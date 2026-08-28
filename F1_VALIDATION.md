# F1 Validation Report

Tanggal validasi: 28 Agustus 2026
Status gerbang: **parsial — alur Transfer Manual lulus; Xendit menunggu kredensial sandbox**

## Ringkasan

| Gerbang | Status | Bukti |
| --- | --- | --- |
| Transfer Manual aktif dan tampil saat registrasi | Lulus | `manual_transfer=true` dan `xendit=false` pada PostgreSQL lokal; `PaymentMethodSelectionTest` |
| Daftar → unggah bukti → verifikasi admin | Lulus | `ManualActivationFlowTest` pada SQLite dan PostgreSQL 17 |
| Approval idempoten, entitlement siap, outbox tunggal | Lulus | 27 assertion alur F1; `ManualTransferVerificationTest` |
| Notifikasi aktivasi | Lulus tanpa trafik eksternal | `FakeNotifier` menerima tepat satu pesan dan outbox menjadi `processed` |
| Login peserta dan akses tes | Lulus | JWT berhasil diterbitkan; endpoint sesi mengembalikan `SESSION_ENGINE_PENDING`, yang membuktikan entitlement sudah aktif |
| Metode OFF ditolak untuk order baru tanpa merusak histori | Lulus | `PaymentMethodActivationTest` dan `PaymentMethodSelectionTest` |
| Regresi keamanan terkait | Lulus | 62 tes terfokus, 435 assertion: RLS middleware/context, consent DASS ditolak, upload abuse, replay approval/webhook, login throttle, dan otorisasi status |
| Xendit sandbox end-to-end | Belum dijalankan | Kredensial sandbox belum tersedia; metode tetap OFF |

## Eksekusi

- SQLite acceptance: `php artisan test tests/Feature/F1/ManualActivationFlowTest.php` — 1 lulus, 27 assertion.
- PostgreSQL acceptance: database terpisah `psikotes_f1_test`, PostgreSQL 17 — 1 lulus, 27 assertion.
- Suite keamanan, registrasi, dan pembayaran terfokus — 62 lulus, 435 assertion.
- Suite penuh — 296 tes: 293 lulus, 3 sandbox skip, 1.455 assertion. Pint, PHPStan, ESLint, Prettier, TypeScript, dan Vite build lulus.
- Audit dependency — Composer dan npm melaporkan 0 kerentanan; scan pola secret produksi pada file Git terlacak bersih.
- Bukti pembayaran memakai PDF sintetis dan storage fake. Notifikasi memakai `FakeNotifier`; tidak ada data peserta atau pesan WhatsApp nyata yang dikirim.

## Catatan runner PostgreSQL

- Migrasi test wajib memakai akun owner; akun runtime sengaja tidak memiliki hak membuat schema.
- Sebelum `migrate:fresh` berulang, schema `dass` pada database test harus dihapus dan dibuat ulang secara eksplisit. Laravel membersihkan schema default, tetapi tidak otomatis membersihkan tabel pada schema PostgreSQL terisolasi tersebut.
- Reset hanya boleh ditujukan ke database bernama dengan akhiran `_test`; jangan menjalankannya pada database aplikasi.

## Sisa gerbang

Task 18 belum dapat dinyatakan selesai sampai alur Xendit sandbox dijalankan dari invoice, callback terautentikasi, entitlement, notifikasi fake, hingga login peserta. Xendit tetap nonaktif sementara itu.
