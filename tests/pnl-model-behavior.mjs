import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const read = p => fs.readFileSync(new URL('../' + p, import.meta.url), 'utf8');
const window = { TAMASYA_RUNTIME_CONFIG: { propertyTimezone: 'Asia/Makassar' } };
vm.runInNewContext(read('assets/canonical-business-policy.js'), { window, Intl, Date });
globalThis.TAMASYA_BUSINESS_POLICY = window.TAMASYA_BUSINESS_POLICY;
vm.runInNewContext(read('assets/report-pnl-model.js'), { window: globalThis, global: globalThis, module: { exports: {} }, exports: {} });
const model = globalThis.tamasyaComputePnlModel;

const receipt = (id, amount, extra = {}) => ({
  id, type: 'income', date: '2026-01-28', amount,
  baseAmount: amount / 1.1, taxAmount: amount - amount / 1.1,
  taxSnapshotStatus: 'confirmed', transactionKind: 'booking_payment',
  categorySystemKey: 'room_rental', category: 'Kamar', ...extra
});
const expense = (id, amount, extra = {}) => ({
  id, type: 'expense', date: '2026-01-28', amount, category: 'Beban',
  transactionKind: 'manual', taxSnapshotStatus: 'not_applicable', ...extra
});

function check(rows, label) {
  const res = model(rows);
  assert.equal(res.St, res.Cn + res.posRevenue + res.gn + res.tr, label + ': St=sum buckets');
  assert.equal(res.ut, res.posCogs + res.js + res.K + res.Ze, label + ': ut=sum expense');
  assert.equal(res.ft, res.St - res.ut, label + ': ft=St-ut');
  const sumA = Object.values(res.A).reduce((a, b) => a + b, 0);
  const sumZ = Object.values(res.Z).reduce((a, b) => a + b, 0);
  assert.ok(sumA <= res.Ya + 0.01, label + ': sum(A)<=Ya');
  assert.ok(sumZ <= res.On + 0.01, label + ': sum(Z)<=On');
  return res;
}

check([receipt('r1', 110000), receipt('r2', 220000), expense('e1', 50000)], 'mixed');
check([], 'empty');
check([receipt('r1', 110000)], 'income only');
check([expense('e1', 50000)], 'expense only');
check([receipt('r1', 110000, { category: 'Kamar' }), receipt('r2', 220000, { category: 'Layanan' })], 'multi category');

console.log('PASS behavior P&L invariants (5 scenarios)');