# Konsolidasi TAMASYA — Rencana Lengkap (tanpa fitur baru)

Tujuan: rapikan kode existing agar mudah dipelihara, tanpa ubah perilaku/angka.

## Prinsip
- Tidak menambah fitur baru.
- Satu modul per rilis; CI hijau sebelum lanjut.
- Tidak menyentuh DB produksi.
- Tes perilaku (bukan string matching) untuk setiap modul.

## Modul & Tugas

### 1. Report/P&L (assets/chunks/report.js) — PRIORITAS 1
- [x] Paritas client/server recognizedRevenue + bookingId bucket.
- [x] Rincian per kategori (cash) + label "(basis KAS)".
- [x] Catatan awam cash vs DPP.
- [x] Unify St/Ya/A/Z menjadi satu sumber kebenaran.
- [x] Hapus variabel mati (vs, Wa, ar, ns, Ms).
- [x] Tes reconciliasi: St=sum(buckets), ut=sum(expense), ft=St-ut.
- [x] Tes per-kategori: sum(A) <= Ya, sum(Z) <= On.

### 2. Finance canonical semantics (019 + canonical-business-policy.js)
- [x] Paritas recognizedRevenue + bookingId.
- [x] Unify tax snapshot resolution (server canonical).
- [x] Tes perilaku client vs server (fixture).

### 3. Booking/Checkout (030)
- [x] Posting via canonical authority (verified).
- [x] Hapus fallback baseAmount fabrication (refund share + refund tx).
- [x] Tes posting deposit/refund.

### 4. POS minibar (085)
- [x] Fix fallback baseAmount (null instead of amount).
- [x] Tes POS insert transaction.

### 5. Staff/RBAC (050 + 017)
- [x] Audit akses control.
- [x] Tes perilaku.

### 6. Inventory/Maintenance
- [x] Audit.

### 7. Telegram input flows (080)
- [x] Expense input (verified, no critical gaps).
- [x] Income/expense dynamic (verified).
- [x] Audit consistency.

### 8. Sync/Node cluster (040)
- [x] Server-resolved tax snapshot (verified).
- [x] Audit failover.

### 9. Tests
- [x] Konversi string-based tests ke behavior-based (P&L invariants; sisanya struktural/wiring tetap string).
- [x] Tambah tes reconciliasi keuangan.

## Status
Modul 1-9 selesai (termasuk konversi string→behavior P&L di repo clone). Siap frontend rewrite.
