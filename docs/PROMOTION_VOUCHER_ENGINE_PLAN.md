# Rencana Implementasi: Promotion & Voucher Engine Dinamis

## Tujuan
Menambahkan engine promo/voucher yang dinamis, aman, terintegrasi, dan konsisten untuk hotel dengan mode online/offline, dua node, serta multi-property. Engine harus mendukung kode promo yang dapat ditukar, event promo khusus, dan diskon yang dikendalikan pengaturan, tanpa merusak canonical finance, sync, atau isolation tenant.

## Prinsip Keselamatan
1. Tidak mengubah source-of-truth transaksi, jurnal, pajak, atau stok secara langsung dari UI promo.
2. Semua mutasi promo memakai operation/idempotency key.
3. Semua perubahan schema bersifat expand-only + migration versioned; tidak ada reset/seed/destructive migration.
4. Tenant isolation wajib: property/database, company, permission, role.
5. Online/offline dua node: tabel promo harus masuk allowlist sync, idempotent, dan tahan conflict.
6. Feature flag wajib untuk rollout bertahap; default OFF sampai UAT lulus.
7. Rollback/compensating operation wajib sebelum implementasi.
8. Test + UAT + checksum wajib sebelum release.

## Fase

### Fase 0 — Audit & Kontrak (belum coding fitur)
- Audit modul yang sudah ada: voucher loyalty, discount booking/POS, public promotions, CRM campaigns, node sync, permission.
- Definisikan kontrak API, schema, sync allowlist, role matrix, dan invariant.
- Keluaran: dokumen ini + checklist test.

### Fase 1 — Fondasi Data & Backend (default OFF)
- Tabel baru `promotion_codes` (expand-only migration).
- Fungsi: create, update, deactivate, list, validate, redeem/use, audit.
- Validasi: kode unik, tipe fixed/percent, nilai, min spend, max discount, valid_from/until, usage limit, status.
- Idempotency: operation_id; redeem tidak boleh menduplikasi penggunaan.
- Permission: admin/manager/finance; owner read-only.
- Audit trail: writeRequiredEnterpriseAudit atau audit mutation.
- Feature flag: `TAMASYA_PROMOTION_CODES_ENABLED`.

### Fase 2 — Integrasi POS (kontrol ketat)
- Input kode promo pada POS minibar.
- Backend validasi kode + hitung diskon canonical.
- Diskon tetap mengikuti alur `tamasyaPosDiscountPlan` / `tamasyaPosAllocateDiscount`.
- Guard: diskon < subtotal, reason otomatis dari kode, authorized by role.
- Idempotency sale tetap terjaga.
- Test: POS discount + promo code.

### Fase 3 — Integrasi Booking
- Input kode promo pada pembuatan/edit booking.
- Validasi kode dan terapkan ke `discountAmount` canonical.
- Pastikan tax/PBJT recalculation mengikuti aturan yang ada.
- Guard snapshot/locked transaction.
- Test: booking finance + promo code.

### Fase 4 — Website & Campaign Dinamis
- Promosi website dapat menghasilkan kode promo.
- Event promo khusus: periode, segment, property.
- CRM campaign dapat mereferensikan kode promo (tetap fail-closed live send sampai diuji).

### Fase 5 — Sync, Multi-property, Offline
- Tambah `promotion_codes` ke node sync allowlist.
- Tambah kolom allowlist ke security cluster smartlock.
- Verifikasi conflict resolution, idempotency, dan convergence.
- UAT dua node.

### Fase 6 — Frontend & UX
- UI admin untuk CRUD promo code.
- Input kode promo di POS dan booking.
- Status, penggunaan, expired, audit view.
- Owner read-only.

### Fase 7 — Test, UAT, Release
- Unit/regression PHP.
- Node/behavior test jika ada logika JS.
- UAT skenario promo/voucher.
- Checksum, staging, release ready.

## Sinkronisasi & Multi-property
- Satu database per property; sync antar node untuk property yang sama.
- Kode promo bersifat property-scoped secara default; opsi global hanya jika diset admin.
- Tabel sync harus idempotent dan memiliki operation_id.
- Conflict resolution mengikuti receipt/sequence yang sudah ada.
- Tidak boleh ada write ganda saat failover.

## Rollback
- Feature flag OFF.
- Tabel baru boleh dibiarkan (expand-only) tetapi tidak dipakai.
- Kode promo yang sudah dipakai tetap tercatat; compensating operation untuk penggunaan salah adalah void/reversal sesuai domain, bukan delete.
- Tidak mengubah transaksi historis.

## Invariant yang Harus Dijaga
- Jurnal tetap seimbang.
- Diskon tidak melebihi subtotal.
- Tax snapshot tetap konsisten.
- Stok tidak berubah akibat promo.
- Promo tidak bisa dipakai melebihi limit.
- Audit trail lengkap.

## File Awal yang Kemungkinan Disentuh (Belum Final)
- `database_setup.sql` (tabel baru)
- migration expand-only
- `api/modules/...` (modul baru)
- `api/routes/...` (route baru)
- `api.php` (loader)
- `node_sync_support.php`, `api/support/090_security_cluster_smartlock.php`
- `assets/...` UI
- `tests/...` regression

## Non-goal Awal
- Integrasi provider eksternal.
- Campaign live send (tetap disabled sampai SMTP diuji).
- Event banquet/MICE (modul terpisah).

## Keputusan yang Dibutuhkan Sebelum Coding
1. Mulai dari fondasi saja atau langsung POS?
2. Kode promo boleh dipakai lintas property atau per property?
3. Event promo khusus apakah perlu segment/consent CRM?
4. Apakah diskon promo boleh stack dengan diskon manual?

Setelah keputusan ini, saya mulai Fase 1 tanpa menyentuh flow transaksi sampai test lulus.
EOF
echo "written"