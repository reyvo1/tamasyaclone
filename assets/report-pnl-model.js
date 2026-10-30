// Pure P&L model extracted from assets/chunks/report.js so the invariants
// (St, ut, ft, Ya, On, A, Z) can be tested as behavior, not string presence.
// Mirrors the report component's useMemo computations. Kept dependency-light:
// uses window/global TAMASYA_BUSINESS_POLICY for canonical semantics when present.
(function (global) {
  'use strict';
  function policy() { return (global && (global.TAMASYA_BUSINESS_POLICY || (global.window && global.window.TAMASYA_BUSINESS_POLICY))) || null; }
  function semantics(tx) { const p = policy(); return p ? p.transactionSemantics(tx) : null; }
  function recognizedIncomeDelta(tx) { const s = semantics(tx); return s ? Number(s.incomeDelta || 0) : 0; }
  function recognizedExpenseDelta(tx) { const s = semantics(tx); return s ? Number(s.expenseDelta || 0) : 0; }
  function isInternal(tx) { const s = semantics(tx); return !!(s && s.isInternalTransfer); }
  function isOta(tx) { const s = semantics(tx); return !!(s && s.isOtaTransfer); }
  function isOpening(tx) { const s = semantics(tx); return !!(s && s.isOpeningBalance); }
  function liquidLeg(tx) {
    const p = policy();
    if (p && typeof p.liquidLegs === 'function') {
      const legs = p.liquidLegs(tx, tx && (tx.bankAccountId || tx.bank_account_id));
      return (Number(legs.cash) || 0) + (Number(legs.bank) || 0);
    }
    const bank = String((tx && (tx.bankAccountId || tx.bank_account_id)) || '').toLowerCase();
    const tech = ['ota_receivable','inventory_asset','guest_receivable','accounts_payable'];
    if (tech.includes(bank)) return 0;
    return Number(tx && tx.amount) || 0;
  }
  function bucketOf(tx) {
    const s = semantics(tx);
    return s ? String(s.bucket || 'other') : 'other';
  }

  function tamasyaComputePnlModel(transactions) {
    const list = Array.isArray(transactions) ? transactions : [];
    const qa = list;
    const technical = ['ota_receivable','inventory_asset','guest_receivable','accounts_payable'];
    const Tt = qa.filter((t) => !technical.includes(String((t && (t.bankAccountId || t.bank_account_id)) || '').toLowerCase()));
    const Zn = Tt.filter((t) => t && t.type === 'income' && !isOpening(t) && (!isInternal(t) || isOta(t)));
    const xn = Tt.filter((t) => t && t.type === 'expense' && (!isInternal(t) || isOta(t)));

    const Ya = Zn.reduce((s, t) => s + liquidLeg(t), 0);
    const On = xn.reduce((s, t) => s + liquidLeg(t), 0);

    const A = {};
    Zn.forEach((t) => { const c = (t && t.category) || 'Umum'; const l = liquidLeg(t); if (l > 0) A[c] = (A[c] || 0) + l; });
    const Z = {};
    xn.forEach((t) => { const c = (t && t.category) || 'Umum'; const l = liquidLeg(t); if (l > 0) Z[c] = (Z[c] || 0) + l; });

    // Recognized revenue buckets (cash-basis DPP via canonical semantics).
    const parts = { room: 0, pos: 0, extra: 0, other: 0 };
    qa.forEach((t) => {
      const d = recognizedIncomeDelta(t);
      if (Math.abs(d) <= 0.0001) return;
      const sign = d < 0 ? -1 : 1;
      const amt = Math.abs(d);
      const b = bucketOf(t);
      const key = b === 'pos' ? 'pos' : b === 'room' || b === 'extension' ? 'room' : b === 'extra' ? 'extra' : 'other';
      parts[key] += sign * amt;
    });
    const Cn = parts.room, posRevenue = parts.pos, gn = parts.extra, tr = parts.other;
    const St = Cn + posRevenue + gn + tr;

    // Expenses by bucket.
    const posCogs = qa.filter((t) => String((t && t.transactionKind) || '').toLowerCase() === 'pos_cogs')
      .reduce((s, t) => s + (Number(t.amount) || 0), 0);
    const js = qa.filter((t) => {
      const s = semantics(t);
      const ge = String((t && t.categorySystemKey) || (t && t.category_system_key) || '').toLowerCase();
      const ct = String((t && t.transactionKind) || '').toLowerCase();
      return (ge === 'payroll_expense' || ct === 'salary_payment');
    }).reduce((s, t) => s + (Number(t.amount) || 0), 0);
    const K = qa.filter((t) => String((t && t.transactionKind) || '').toLowerCase() === 'maintenance_cost')
      .reduce((s, t) => s + (Number(t.amount) || 0), 0);
    const Ze = qa.filter((t) => {
      const s = semantics(t);
      const ge = String((t && t.categorySystemKey) || (t && t.category_system_key) || '').toLowerCase();
      const ct = String((t && t.transactionKind) || '').toLowerCase();
      if (ge === 'payroll_expense' || ct === 'salary_payment') return false;
      if (ct === 'maintenance_cost') return false;
      if (ct === 'pos_cogs') return false;
      if (isInternal(t) && !isOta(t)) return false;
      return t && t.type === 'expense';
    }).reduce((s, t) => s + (Number(t.amount) || 0), 0);
    const ut = posCogs + js + K + Ze;
    const ft = St - ut;

    return { St, ut, ft, Ya, On, A, Z, Cn, posRevenue, gn, tr, posCogs, js, K, Ze };
  }

  global.tamasyaComputePnlModel = tamasyaComputePnlModel;
  if (typeof module !== 'undefined' && module.exports) module.exports = { tamasyaComputePnlModel };
})(typeof window !== 'undefined' ? window : (typeof global !== 'undefined' ? global : this));