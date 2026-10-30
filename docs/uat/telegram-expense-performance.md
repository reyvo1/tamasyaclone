# Performa Input Pengeluaran Telegram — Kriteria Penerimaan

## Tujuan
Menerima foto nota tidak boleh menahan webhook Telegram untuk mengunduh berkas. Bukti disimpan sebagai referensi `telegram_file_id:` dan baru diambil saat admin membuka nota.

## Given/When/Then
- Given petugas mengirim foto nota pada state input pengeluaran, When webhook memproses foto, Then tidak ada unduhan berkas sinkron (`getFile`/`file_get_contents`) di jalur tersebut.
- Given foto nota diterima, When konteks disimpan, Then `photoFileId` tersimpan dan balasan menyebut referensi foto disimpan (bukan file tersimpan di server).
- Given nominal dikirim setelah foto, When transaksi dibuat, Then `proofUrl` berisi `telegram_file_id:<file_id>`.
- Given admin membuka bukti transaksi ber-referensi Telegram, When endpoint `transaction-proof` dipanggil, Then foto diambil on-demand dan dikembalikan sebagai data URL.
- Given pengambilan on-demand gagal, Then endpoint tetap sukses tanpa `proofUrl` dan UI menampilkan placeholder, bukan error 500.
- Given pengeluaran tanpa foto, When transaksi disimpan, Then alur tetap berjalan seperti sebelumnya.
- Given foto KTP pada alur lain, Then perilakunya tidak berubah oleh perubahan ini.

## Catatan
- Timeout pengambilan on-demand dibatasi agar tidak menggantung request admin.
- Tidak menyentuh DB produksi.
