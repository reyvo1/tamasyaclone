# Kebijakan Shift untuk Transaksi Manual — Kriteria Penerimaan

## Tujuan
Shift kas adalah custody laci tunai. Transaksi manual live hanya boleh mewajibkan shift bila benar-benar memindahkan uang tunai. Pengeluaran transfer/QRIS/bank tidak boleh terhalang oleh shift.

## Given/When/Then
- Given pengeluaran manual non-tunai (akun transfer/QRIS/bank dipilih), When disimpan tanpa shift aktif, Then transaksi tersimpan tanpa error shift.
- Given pemasukan manual non-tunai, When disimpan tanpa shift aktif, Then transaksi tersimpan tanpa error shift.
- Given pengeluaran tunai (`bankAccountId` kosong/null/`cash`), When disimpan tanpa shift aktif, Then ditolak dengan pesan yang menyebut transaksi tunai dan saran memakai transfer/QRIS.
- Given pemasukan tunai, When disimpan tanpa shift aktif, Then ditolak dengan pesan yang menyebut transaksi tunai.
- Given split payment dengan porsi tunai lebih dari nol, When disimpan tanpa shift aktif, Then ditolak.
- Given split payment dengan porsi tunai nol, When disimpan tanpa shift aktif, Then tidak diwajibkan shift.
- Given pengeluaran tunai dengan shift aktif tetapi kas tidak cukup, When disimpan, Then ditolak oleh guard coverage kas yang sudah ada.
- Given transaksi historical/backfill, When disimpan, Then tidak diwajibkan shift.
- Given transaksi non-tunai disimpan saat ada shift terbuka, Then transaksi tetap boleh terhubung ke shift untuk pelaporan, tetapi ketiadaan shift bukan alasan penolakan.
- Given pengguna membuka form transaksi, Then teks bantuan menyatakan shift hanya wajib untuk transaksi tunai.

## Catatan
- Mode kas kecil/reimbursement di luar laci belum termasuk perubahan ini.
- Tidak menyentuh DB produksi.
