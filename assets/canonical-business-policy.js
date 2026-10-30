(function (global) {
  'use strict';

  const lower = (v) => String(v ?? '').trim().toLowerCase();
  const amount = (v) => Math.max(0, Number(v) || 0);
  const kindOf = (tx) => lower(tx?.transactionKind ?? tx?.transaction_kind ?? 'manual');
  const categoryKeyOf = (tx) => lower(tx?.categorySystemKey ?? tx?.category_system_key ?? '');
  const sourceEntityOf = (tx) => lower(tx?.sourceEntity ?? tx?.source_entity ?? '');

  // FIX17: display labels are property-owned master data and never determine
  // accounting behavior in the browser fallback. Only transaction kind and
  // immutable semantic identity may affect the result.
  function isPbjtSettlement(tx) {
    if (lower(tx?.type) !== 'expense') return false;
    const kind = kindOf(tx);
    return ['pbjt_payment', 'pbjt_settlement'].includes(kind) || categoryKeyOf(tx) === 'pbjt_settlement';
  }

  function isIncomeTaxSettlement(tx) {
    if (lower(tx?.type) !== 'expense' || isPbjtSettlement(tx)) return false;
    return ['pph_payment', 'income_tax_payment', 'corporate_income_tax_payment'].includes(kindOf(tx));
  }

  function recognizedRevenue(tx) {
    const gross = amount(tx?.amount);
    if (gross <= 0) return 0;
    const baseRaw = tx?.baseAmount ?? tx?.base_amount;
    const taxRaw = tx?.taxAmount ?? tx?.tax_amount;
    const base = Number(baseRaw);
    const tax = Number(taxRaw);
    // Parity with server tamasyaRecognizedRevenueAmount(): explicit valid split
    // takes precedence over taxSnapshotStatus (so unresolved receipts with a
    // known split still expose their DPP for journal/reconciliation).
    if (Number.isFinite(base) && Number.isFinite(tax) && base >= 0 && tax >= 0 && Math.abs((base + tax) - gross) <= 0.01) {
      return Math.max(0, Math.round((gross - Math.min(gross, tax)) * 100) / 100);
    }
    const status = lower(tx?.taxSnapshotStatus ?? tx?.tax_snapshot_status ?? 'unresolved');
    if (status === 'unresolved') return 0;
    // Missing tax evidence is fail-closed. Only rows explicitly marked not_applicable
    // may use gross as base without a tax split.
    if (status === 'not_applicable') return gross;
    return 0;
  }

  function transactionSemantics(tx) {
    const serverSemantic = tx?.financialSemantic;
    if (serverSemantic && serverSemantic.policyVersion === 'FINAL12-root-policy-3'
      && typeof serverSemantic.bucket === 'string'
      && Number.isFinite(Number(serverSemantic.incomeDelta))
      && Number.isFinite(Number(serverSemantic.expenseDelta))) {
      return { ...serverSemantic,
        recognizedRevenue: Number(serverSemantic.recognizedRevenue || 0),
        incomeDelta: Number(serverSemantic.incomeDelta || 0),
        expenseDelta: Number(serverSemantic.expenseDelta || 0) };
    }

    const type = lower(tx?.type);
    const kind = kindOf(tx);
    const categorySystemKey = categoryKeyOf(tx);
    const sourceEntity = sourceEntityOf(tx);
    const bookingSource = lower(tx?.bookingSource ?? tx?.booking_source);
    const bank = lower(tx?.bankAccountId ?? tx?.bank_account_id);
    const updatedSource = lower(tx?.updatedSource ?? tx?.updated_source);
    const bookingAction = lower(tx?.bookingAction ?? tx?.booking_action ?? tx?.action);
    const isIncome = type === 'income';
    const isExpense = type === 'expense';
    const isInternalTransfer = ['internal_transfer', 'ota_transfer'].includes(kind) || sourceEntity === 'ota_disbursement';
    const isOtaTransfer = kind === 'ota_transfer' || sourceEntity === 'ota_disbursement';
    const isDepositReceipt = kind === 'security_deposit_received';
    const isDepositRefund = kind === 'security_deposit_refund';
    const isDepositForfeit = kind === 'security_deposit_forfeit';
    const isSecurityDeposit = ['security_deposit_received', 'security_deposit_refund', 'security_deposit_forfeit'].includes(kind);
    const isOpeningBalance = ['opening_balance_cash', 'opening_balance_bank'].includes(kind);
    const isExpenseReversal = isIncome && ['salary_reversal', 'pos_cogs_reversal'].includes(kind);
    const isRevenueRefund = isExpense && !isInternalTransfer && (
      ['refund', 'pos_refund'].includes(kind) || (kind === 'booking_charge' && updatedSource === 'audit-correction')
    );
    const isApPayment = kind === 'supplier_ap_payment';
    const isNonPnlSupplierAccrual = ['supplier_inventory_accrual', 'supplier_asset_accrual'].includes(kind);
    const isTechnicalNonLiquid = ['ota_receivable', 'inventory_asset', 'guest_receivable', 'accounts_payable'].includes(bank);
    const taxStatus = lower(tx?.taxSnapshotStatus ?? tx?.tax_snapshot_status ?? 'unresolved');
    const isTaxUnresolvedReceipt = isIncome && taxStatus === 'unresolved';
    const revenue = recognizedRevenue(tx);
    // A receipt moves cash even while its revenue/tax allocation stays in suspense.
    const isLiquidExternalIncome = isIncome && !isOpeningBalance && !isDepositForfeit
      && !isTechnicalNonLiquid && !isInternalTransfer;
    const isLiquidExternalExpense = isExpense && !isTechnicalNonLiquid && !isInternalTransfer;

    let bucket;
    if (isSecurityDeposit) bucket = 'security_deposit';
    else if (isInternalTransfer) bucket = 'internal_transfer';
    else if (categorySystemKey === 'pos_revenue' || categorySystemKey === 'pos_refund'
      || categorySystemKey === 'pos_cogs' || categorySystemKey === 'pos_cogs_reversal'
      || ['pos_sale', 'pos_refund', 'pos_room_charge', 'pos_cogs', 'pos_cogs_reversal'].includes(kind)
      || bookingSource === 'pos') bucket = 'pos';
    else if (bookingAction === 'extension' || kind === 'extension') bucket = 'extension';
    else if (categorySystemKey === 'extra_service' || bookingAction === 'extra'
      || ['extra', 'service'].includes(kind) || kind.startsWith('extra_') || kind.startsWith('service_')) bucket = 'extra';
    else if (categorySystemKey === 'room_rental'
      || ['booking_payment', 'down_payment', 'settlement', 'refund', 'booking_charge'].includes(kind)
      || sourceEntity === 'booking'
      || String(tx?.bookingId ?? tx?.booking_id ?? '').trim() !== '') bucket = 'room';
    else bucket = kind || 'manual';

    let incomeDelta = 0;
    if (!isInternalTransfer && !isOpeningBalance && !isTaxUnresolvedReceipt && !isDepositReceipt && !isDepositRefund && !isExpenseReversal) {
      if (isIncome) incomeDelta = revenue;
      else if (isRevenueRefund) incomeDelta = -revenue;
    }
    let expenseDelta = 0;
    if (!isInternalTransfer && !isSecurityDeposit && !isRevenueRefund && !isPbjtSettlement(tx) && !isIncomeTaxSettlement(tx) && !isApPayment && !isNonPnlSupplierAccrual) {
      if (isExpense) expenseDelta = amount(tx?.amount);
      else if (isExpenseReversal) expenseDelta = -amount(tx?.amount);
    }

    return {
      type, kind, categorySystemKey, sourceEntity, bookingSource, bank, updatedSource, bookingAction,
      isIncome, isExpense, isInternalTransfer, isOtaTransfer, isDepositReceipt, isDepositRefund,
      isDepositForfeit, isSecurityDeposit, isOpeningBalance, isExpenseReversal, isRevenueRefund,
      isPbjtSettlement: isPbjtSettlement(tx), isIncomeTaxSettlement: isIncomeTaxSettlement(tx),
      isApPayment, isNonPnlSupplierAccrual, isTechnicalNonLiquid, isTaxUnresolvedReceipt,
      isLiquidExternalIncome, isLiquidExternalExpense,
      recognizedRevenue: revenue, bucket, incomeDelta, expenseDelta
    };
  }

  // Compatibility signature retained for bundled UI. `action` is now the
  // semantic/bucket value supplied by the caller; category/subcategory/description
  // are deliberately ignored so property labels cannot alter P&L classification.
  function revenueBucket(action = '', _category = '', _subcategory = '', _description = '') {
    const a = lower(action);
    if (['pos', 'pos_sale', 'pos_refund', 'pos_room_charge', 'pos_revenue'].includes(a)) return 'pos';
    if (['room', 'standard', 'extension', 'room_rental', 'booking_payment', 'down_payment', 'settlement', 'booking_charge'].includes(a)) return 'room';
    if (['extra', 'service', 'extra_service'].includes(a)) return 'extra';
    return 'other';
  }

  function isTaxableRevenueLike(tx, _roomCategory = '') {
    const sem = transactionSemantics(tx);
    if (sem.isInternalTransfer || sem.isSecurityDeposit) return false;
    if (['pos_cogs', 'pos_cogs_reversal', 'salary_payment', 'salary_reversal', 'maintenance_cost',
      'supplier_invoice_accrual', 'supplier_inventory_accrual', 'supplier_asset_accrual', 'supplier_ap_payment'].includes(sem.kind)) return false;
    // A valid saved PBJT snapshot must remain visible after catalog rebinding.
    // No rate is inferred for receipts whose tax evidence is still unresolved.
    const status = lower(tx?.taxSnapshotStatus ?? tx?.tax_snapshot_status);
    const baseRaw = tx?.baseAmount ?? tx?.base_amount;
    const taxRaw = tx?.taxAmount ?? tx?.tax_amount;
    if (['confirmed', 'manual_override'].includes(status) && (sem.isIncome || sem.isRevenueRefund)
      && baseRaw != null && taxRaw != null && Number.isFinite(Number(baseRaw)) && Number.isFinite(Number(taxRaw))
      && Number(baseRaw) >= 0 && Number(taxRaw) > 0
      && Math.abs(Number(baseRaw) + Number(taxRaw) - amount(tx?.amount)) <= 1) return true;
    if (['pos_sale', 'pos_refund'].includes(sem.kind) || sem.categorySystemKey === 'pos_revenue') return true;
    if (['room', 'extension', 'extra'].includes(sem.bucket)) return true;
    return ['room_rental', 'extra_service'].includes(sem.categorySystemKey);
  }

  function liquidLegs(tx, resolvedBankId) {
    const gross = amount(tx?.amount);
    const cash = amount(tx?.splitCashAmount);
    const bank = amount(tx?.splitTransferAmount);
    const splitId = String(tx?.splitTransferBankAccountId ?? '').trim();
    const split = [true, 1, '1', 'true'].includes(tx?.isSplitPayment) || lower(tx?.paymentMethod) === 'split';
    const nonLiquid = ['ota_receivable', 'inventory_asset', 'guest_receivable', 'accounts_payable'];
    if (split && cash > 0 && bank > 0 && splitId && Math.abs(cash + bank - gross) <= 0.01) {
      return {cash, bank: nonLiquid.includes(lower(splitId)) ? 0 : bank, bankAccountId: splitId};
    }
    const bankId = String(resolvedBankId ?? tx?.bankAccountId ?? '').trim();
    if (nonLiquid.includes(lower(bankId))) return {cash: 0, bank: 0, bankAccountId: bankId};
    return bankId && lower(bankId) !== 'cash'
      ? {cash: 0, bank: gross, bankAccountId: bankId}
      : {cash: gross, bank: 0, bankAccountId: ''};
  }

  function propertyDate(now = new Date()) {
    const zone = global.TAMASYA_RUNTIME_CONFIG?.propertyTimezone
      || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(now);
    const part = (key) => parts.find((item) => item.type === key).value;
    return `${part('year')}-${part('month')}-${part('day')}`;
  }

  function savedTaxAmount(tx) {
    const status = lower(tx?.taxSnapshotStatus ?? tx?.tax_snapshot_status ?? 'unresolved');
    if (status === 'unresolved') return null;
    const base = tx?.baseAmount ?? tx?.base_amount;
    const tax = tx?.taxAmount ?? tx?.tax_amount;
    if (base != null && tax != null && Number.isFinite(Number(base)) && Number.isFinite(Number(tax))
      && Number(base) >= 0 && Number(tax) >= 0 && Math.abs(Number(base) + Number(tax) - amount(tx?.amount)) <= 1) return Number(tax);
    return status === 'not_applicable' ? 0 : null;
  }

  function financialDisplaySummary(transactions, { initialCash = 0, resolveBank = (tx) => tx.bankAccountId } = {}) {
    const opening = Number(initialCash) || 0;
    const summary = { receipts: 0, payments: 0, cashReceipts: 0, cashPayments: 0,
      cashDelta: 0, bankDelta: 0, bankBalances: {}, income: 0, expenses: 0, unresolvedReceipts: 0 };
    for (const tx of Array.isArray(transactions) ? transactions : []) {
      const sem = transactionSemantics(tx);
      const legs = sem.isDepositForfeit ? { cash: 0, bank: 0 } : liquidLegs(tx, resolveBank(tx));
      const sign = lower(tx.type) === 'income' ? 1 : lower(tx.type) === 'expense' ? -1 : 0;
      summary.cashDelta += sign * legs.cash;
      summary.bankDelta += sign * legs.bank;
      if (legs.bank > 0) summary.bankBalances[legs.bankAccountId] = (summary.bankBalances[legs.bankAccountId] || 0) + sign * legs.bank;
      if (sem.isLiquidExternalIncome) {
        summary.receipts += legs.cash + legs.bank;
        summary.cashReceipts += legs.cash;
        if (sem.isTaxUnresolvedReceipt) summary.unresolvedReceipts += legs.cash + legs.bank;
      }
      if (sem.isLiquidExternalExpense) {
        summary.payments += legs.cash + legs.bank;
        summary.cashPayments += legs.cash;
      }
      summary.income += sem.incomeDelta;
      summary.expenses += sem.expenseDelta;
    }
    const money = (value) => Math.round(value * 100) / 100;
    for (const key of Object.keys(summary)) if (typeof summary[key] === 'number') summary[key] = money(summary[key]);
    for (const key of Object.keys(summary.bankBalances)) summary.bankBalances[key] = money(summary.bankBalances[key]);
    return { ...summary, initialCash: opening, cashBalance: money(opening + summary.cashDelta),
      bankBalance: summary.bankDelta, combinedBalance: money(opening + summary.cashDelta + summary.bankDelta),
      netMovement: money(summary.cashDelta + summary.bankDelta), operatingProfit: money(summary.income - summary.expenses) };
  }

  function annualRevenueTarget(transactions, config = {}, now = new Date()) {
    const today = propertyDate(now), year = today.slice(0, 4), from = `${year}-01-01`;
    const rows = (Array.isArray(transactions) ? transactions : []).filter((tx) => tx.date >= from && tx.date <= today);
    const actual = Math.round(rows.reduce((total, tx) => total + transactionSemantics(tx).incomeDelta, 0) * 100) / 100;
    const unresolved = rows.filter((tx) => transactionSemantics(tx).isTaxUnresolvedReceipt).length;
    const target = amount(config.targetInvestment);
    return { year, from, to: today, target, actual, unresolved,
      percent: target > 0 ? Math.round(actual / target * 1000) / 10 : null };
  }

  // Management estimate only: 60 months, 10% residual. No tax/journal posting.
  // Missing acquisition dates keep acquisition value visible and flagged unknown.
  function estimatedInventoryValue(item, asOf = propertyDate()) {
    const cost = amount(item?.price), acquired = String(item?.purchase_date || '').slice(0, 10);
    const parse = (date) => {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return null;
      const parts = date.split('-').map(Number), test = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
      return test.toISOString().slice(0, 10) === date ? parts : null;
    };
    const start = parse(acquired), end = parse(asOf);
    if (!start || !end) return { bookValue: cost, depreciatedAmount: 0, ageMonths: null, missingDate: true };
    if (acquired > asOf) return { bookValue: 0, depreciatedAmount: 0, ageMonths: 0, future: true, missingDate: false };
    const months = Math.max(0, (end[0] - start[0]) * 12 + end[1] - start[1]);
    const depreciation = Math.min(cost * .9, cost * .9 / 60 * months);
    return { bookValue: Math.round((cost - depreciation) * 100) / 100,
      depreciatedAmount: Math.round(depreciation * 100) / 100, ageMonths: months, missingDate: false };
  }

  function monthlyShiftVariance(rows, now = new Date()) {
    const month = propertyDate(now).slice(0, 7);
    return (Array.isArray(rows) ? rows : []).filter((row) => row.status === 'closed'
      && String(row.shift_date || '').slice(0, 7) === month).reduce((total, row) => total + (Number(row.variance) || 0), 0);
  }

  global.TAMASYA_BUSINESS_POLICY = Object.freeze({
    version: 'FINAL12-root-policy-3',
    recognizedRevenue,
    isPbjtSettlement,
    isIncomeTaxSettlement,
    transactionSemantics,
    revenueBucket,
    isTaxableRevenueLike,
    liquidLegs,
    propertyDate,
    savedTaxAmount,
    financialDisplaySummary,
    annualRevenueTarget,
    estimatedInventoryValue,
    monthlyShiftVariance
  });
})(window);
