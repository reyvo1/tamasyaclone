# Konsolidasi Modul 9 — Konversi String-based → Behavior-based (repo clone)

## Latar
String-based assertions cukup untuk menjaga wiring bundle minified, tetapi tidak memverifikasi perilaku. Modul 9 mengubah cek bisnis-logika utama ke perilaku nyata dengan data, sambil membiarkan cek infrastruktur/struktural (SW cache, build ID, HTML, routing wiring) tetap string-based karena tidak bermakna dikonversi.

## Pendekatan
1. Identifikasi cek string yang merepresentasikan aturan bisnis (P&L invariants, cash summary, tax snapshot).
2. Ekstrak/logika akses dijalankan lewat `vm.SourceTextModule` / `Function` dengan dependensi policy (`TAMASYA_BUSINESS_POLICY`) disuplai secara eksplisit.
3. Ganti cek string dengan asersi nilai (behavior) atas fixture data.

## Artefak (di repo clone `testamasya-modul9`)
- `assets/report-pnl-model.js` — fungsi murni `tamasyaComputePnlModel(tx[], opts)` mengembalikan `{St,ut,ft,Ya,On,A,Z,Cn,posRevenue,gn,tr,posCogs,js,K,Ze}`. Membaca `TAMASYA_BUSINESS_POLICY` untuk semantic kanonik; fallback sendiri untuk cash leg.
- `tests/pnl-model-behavior.mjs` — 5 skenario (mixed/empty/income-only/expense-only/multi-kategori) → all PASS.
  - Invarian: `St = Cn+posRevenue+gn+tr`; `ut = posCogs+js+K+Ze`; `ft = St-ut`; `sum(A) <= Ya`; `sum(Z) <= On`.
- `tests/ui-financial-logic-regression.mjs` — 3 cek string P&L diganti rujukan perilaku → 14 PASS.
- Test PHP baru: `tests/booking-finance-posting-regression.php` (10), `tests/pos-minibar-insert-regression.php` (6), `tests/maintenance-posting-regression.php` (7).

## Audit kategori `includes`/`str_contains` (109 file)
- **Bisnis-logika (sudah perilaku):** finance/tax parity, cash summary, P&L, owner read-only, growth KPI, universal-catalog, booking/POS/maintenance posting.
- **Kait logika (sengaja string):** asersi keberadaan wiring di bundle minifield (mis. `tamasyaCap(...)` route, `money(rate.rate,rate.plan?.currency||'IDR')`, React hooks mount). Dapat dikonversi hanya dengan browser end-to-end; perilakunya sudah dilindungi behavior test + Playwright (`tests/uat_rc1/browser/`).
- **Infrastruktur (sengaja string):** SW cache, build ID, HTML/htaccess/nginx, config path.

## Catatan penting
- Repo utama `testamasya-main` **tidak disentuh**; semua kerja di clone ini sampai ada perintah pindah.
- `FILE_CHECKSUMS.sha256` diregenerasi setelah tiap perubahan aset.
