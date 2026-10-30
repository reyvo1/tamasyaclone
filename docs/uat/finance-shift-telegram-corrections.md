# UAT — Input Nominal Telegram, Shift Kas, dan Koreksi Finansial

## Tujuan
Memastikan nominal Telegram tidak berubah arti, setiap transaksi tunai live terhubung ke shift kas sebelum posting, guard saldo kas tidak bisa dilewati, dan edit/koreksi tidak membuat saldo kas shift negatif.

## Given/When/Then

### A. Nominal Telegram
- Given pengguna mengetik `125.000` pada input pengeluaran Telegram, When pesan diproses, Then nominal tersimpan `125000`, bukan `125`.
- Given pengguna mengetik `125.000,50`, When pesan diproses, Then nominal tersimpan `125000.50`.
- Given pengguna mengetik `1,5`, When pesan diproses, Then nominal tersimpan `1.50`, bukan `15`.
- Given pengguna mengetik `-5` atau teks bukan nominal, When pesan diproses, Then transaksi ditolak dan tidak ada baris finansial dibuat.
- Given perintah `/pemasukan_sub`, `/pengeluaran_sub`, atau `/pengeluaran`, When nominal memakai format Indonesia, Then nominal diparse oleh parser uang yang sama dengan alur state Telegram.

### B. Shift dan coverage
- Given pengeluaran tunai live tanpa shift aktif, When diposting, Then ditolak sebelum baris transaksi dibuat.
- Given pengeluaran tunai live dengan shift aktif tetapi kas tidak cukup, When diposting, Then ditolak.
- Given pemasukan tunai live tanpa shift aktif, When diposting, Then ditolak.
- Given transaksi transfer/QRIS/kartu tanpa shift aktif, When diposting, Then transaksi tetap tersimpan.
- Given split payment dengan porsi tunai lebih dari nol, When diposting tanpa shift, Then ditolak.
- Given split payment dengan porsi tunai nol, When diposting tanpa shift, Then tidak ditolak karena shift.
- Given refund booking, refund POS, penerimaan/pengembalian deposito, panjar booking, atau transaksi kas Telegram, When kaki tunai ada, Then shift diresolusi sebelum posting sehingga guard coverage melihat `shiftSessionId`.
- Given transaksi non-tunai pada shift terbuka, When diposting, Then transaksi boleh terhubung ke shift untuk pelaporan tetapi tidak diwajibkan.
- Given transaksi historical/backfill dengan `shiftExempt=1` atau `recordOrigin=historical_import`, When diposting, Then tidak diwajibkan shift.

### D. Edit transaksi
- Given pengeluaran tunai lama Rp100.000, When nominal diubah menjadi Rp150.000 pada shift dengan kas tersedia Rp120.000, Then edit ditolak karena kebutuhan tambahan melebihi kas.
- Given pengeluaran tunai lama Rp100.000, When hanya deskripsi diubah, Then edit tidak ditolak oleh guard coverage.
- Given pemasukan tunai lama Rp100.000, When diubah menjadi transfer, Then edit ditolak bila saldo kas shift menjadi negatif.
- Given transaksi historical, When diedit, Then guard live tidak dijalankan.

### E. Koreksi audit
- Given koreksi audit langsung pada transaksi live, When nominal tunai dinaikkan atau tipe diubah menjadi pengeluaran tunai, Then coverage kas shift diperiksa dengan mengecualikan baris lama.
- Given koreksi audit pada transaksi historical, When disimpan, Then guard live tidak dijalankan.
- Given koreksi audit dengan selisih tunai yang masih tertutup kas shift, When disimpan, Then koreksi berhasil.

### F. Pemasukan dan perubahan tipe
- Given pemasukan tunai diubah menjadi non-tunai, When saldo kas shift menjadi negatif, Then edit ditolak.
- Given transaksi non-tunai diubah menjadi tunai, When tidak ada shift aktif, Then edit ditolak.
- Given transaksi tunai diubah menjadi non-tunai dan saldo kas shift tetap non-negatif, When disimpan, Then edit berhasil.

### G. Metadata pajak transaksi
- Given panjar booking dengan rule pajak down payment, When diposting, Then `taxRuleId` dan `taxRuleName` berasal dari rule yang sama dengan tarif panjar.
- Given pembayaran kamar, When diposting, Then closure posting memakai `taxRuleId`/`taxRuleName` dari snapshot pembayaran, bukan hasil resolve ulang berdasarkan jenis transaksi.
- Given settlement booking yang merupakan agregat komponen, When diposting, Then sumber pajak adalah `booking_component_allocation`; rule tunggal hanya diisi bila seluruh komponen memakai rule yang sama.
- Given alokasi receipt dibentuk ulang, When aturan agregat berubah, Then `taxRuleName` ikut diperbarui atau dikosongkan, tidak boleh tertinggal dari rule lama.
- Given koreksi audit langsung, When snapshot pajak berubah, Then `taxSource`, `taxRuleId`, `taxRuleName`, dan `taxSnapshotStatus` ikut tersimpan; transaksi historical mempertahankan metadata lamanya.
- Given transaksi terkunci, When proyeksi pajak mencoba mengubahnya, Then mutasi ditolak eksplisit, bukan silently 0 rows.

## Bukti yang harus ada
- Test regresi gagal sebelum fix dan lulus setelah fix.
- `php -l` untuk setiap file yang diubah.
- Output test penuh yang menunjukkan jumlah PASS/FAIL.

