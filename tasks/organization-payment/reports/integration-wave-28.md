# Integration wave 28 — P12c proof cabang core

Tanggal: 2026-09-02

Rangkaian awal worker `2bf56b6`/`7558915` ditahan karena enum alasan penolakan
salah format, copy halaman bertentangan dengan fitur unggah, dan URL issuer
cabang belum memiliki guard konfigurasi/channel selengkap issuer reviewer.

Fix `889ed26`/`80f1cf2` memperbaiki label melalui enum canonical, menjelaskan
unggah bukti tidak berarti lunas, memvalidasi disk `payment-proofs` dan TTL
1–60 menit, menolak URL kosong serta bill bercampur gateway/invoice, dan mengunci
PaymentMethod saat second recheck. Storage failure dipetakan generik; kegagalan
DB/audit tetap merambat dan transaksi audit rollback.

Seluruh commit diintegrasikan sebagai `4b8ff72`, `4f051af`, `6a87caa`, dan
`8fbf3cb`. Root storage canonical + P12c lulus **50 tes / 278 assertions**;
P12a terisolasi lulus **14/212**. Pint dan PHPStan lulus. Run gabungan yang
menjalankan P12a setelah storage suite gagal karena strategi reset skema SQLite
berbeda; hasil itu tidak dipakai sebagai bukti dan kedua kelompok lulus sendiri.

Core P12c diterima lokal default-off. Tidak ada schema/config toggle, route atau
discovery produksi, Xendit/notifier, finalizer/reviewer, command/job/scheduler,
DB aktif, migrasi, deploy, atau push. Browser acceptance P12c masih diperlukan.
