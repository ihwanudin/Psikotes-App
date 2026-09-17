# ADR-002: Batas provider dan transisi pembayaran

## Status

Accepted

## Date

2026-08-25

## Context

Pembayaran F1 harus mendukung invoice Xendit dan transfer manual tanpa memasukkan bentuk payload, status, atau secret gateway ke domain order. Callback dapat dipalsukan, dikirim ulang, atau tiba tidak berurutan. Entitlement tidak boleh terbuka sebelum pembayaran sah diterapkan secara atomik.

## Decision

- Domain bergantung pada `PaymentProvider`, dengan operasi membuat invoice, mengecek status, menormalisasi webhook, dan mengakhiri invoice. Input/output memakai DTO provider-neutral; detail autentikasi dan payload mentah berhenti di adapter.
- Mata uang dibatasi ke IDR dan nominal memakai integer rupiah positif. Invoice hanya menerima URL HTTPS dan waktu kedaluwarsa yang masih akan datang.
- Status provider dinormalisasi menjadi `pending`, `paid`, `expired`, atau `cancelled`. State machine order hanya mengizinkan transisi dari `pending` menuju salah satu status terminal. Replay status yang sama adalah no-op; transisi keluar dari status terminal ditolak.
- Hanya transisi pertama `pending` ke `paid` yang dapat membuka entitlement. Perubahan order dan entitlement dijalankan dalam satu transaksi service-RLS setelah order dikunci dengan `lockForUpdate()`.
- Event diterapkan setelah ID invoice (`gateway_ref`), reference order (`external_id`), nominal, dan currency cocok dengan snapshot order. Reference atau nilai uang yang tidak cocok gagal tertutup.
- `FakePaymentProvider` menjadi implementasi deterministik untuk contract test. Binding produksi memakai `XenditProvider`, yang mengakses Invoice API melalui Laravel HTTP client dengan Basic Auth, timeout, validasi respons, dan status fallback.
- Kanal pembayaran kanonis dibuat default nonaktif. Hanya super admin yang dapat mengubah aktivasi melalui action ber-row-lock; setiap perubahan state dicatat di `audit_logs` dalam transaksi service-RLS yang sama. Replay state identik adalah no-op tanpa audit duplikat.
- Registrasi hanya mengekspos kanal aktif. Paket dan kanal dikunci serta divalidasi ulang sebelum participant, order `pending`, dan entitlement `locked` dibuat atomik. Order menyimpan `payment_method_id`, nominal, dan mata uang sebagai snapshot transaksi; menonaktifkan kanal hanya mencegah order baru dan tidak menyembunyikan order historis.
- `payment_webhook_events` mengklaim `(provider,event_id)` secara atomik. Event ID Xendit adalah hash ID invoice + status ternormalisasi: PAID/SETTLED menjadi satu event paid, sementara PENDING/EXPIRED tetap terpisah. Intent hash mencegah satu event ID dipakai ulang untuk payload logis berbeda. Claim event dan transisi finansial commit dalam satu transaksi service-RLS.
- Transfer manual memakai state machine yang sama tanpa menyamar sebagai webhook provider. Bukti disimpan privat di luar database; admin dengan kemampuan verifikasi dan scope cabang yang tepat menerapkan `pending→paid|rejected` melalui row lock. Keputusan membawa object key bukti yang ditinjau, sehingga penggantian bukti di antara buka dan klik gagal tertutup. Replay status yang sama tetap no-op tanpa audit atau sinyal finansial kedua.
- Invoice API yang dipakai kickoff berstatus legacy pada dokumentasi Xendit. Migrasi ke Payment Session ditunda sebagai keputusan adapter tersendiri agar kontrak F1 tidak berubah diam-diam.

## Alternatives Considered

### Memakai DTO atau SDK Xendit di service order

Lebih cepat untuk integrasi pertama, tetapi mengikat domain ke nama status, payload, dan siklus SDK tertentu. Ditolak agar provider dapat diganti dan transfer manual tetap menjadi kanal sejajar.

### Membuka entitlement di controller webhook

Mengurangi satu lapisan service, tetapi mudah memisahkan update order dan entitlement atau melewati row lock. Ditolak karena invariant pembayaran harus berada pada satu transaction boundary yang dapat diuji.

### Menganggap callback terminal terakhir sebagai kebenaran

Akan membiarkan callback kedaluwarsa yang terlambat menurunkan order paid, atau callback paid menghidupkan kembali order expired. Ditolak; status terminal bersifat final dan konflik harus diselidiki, bukan ditimpa.

### Menghapus atau memfilter relasi order saat kanal dinonaktifkan

Akan merusak rekonsiliasi, audit, dan status pembayaran peserta yang sudah memiliki order. Ditolak; aktivasi adalah eligibility untuk order baru, bukan lifecycle data historis.

## Consequences

- Adapter provider wajib mengautentikasi dan menormalisasi input tidak tepercaya sebelum membentuk `PaymentEvent`.
- Integrasi Xendit tidak boleh menambahkan field gateway ke state machine atau DTO domain; kebutuhan provider-spesifik tetap di adapter/configuration boundary.
- Scheduler status fallback wajib berjalan. Credential sandbox tidak tersedia di repository; contract test eksternal hanya menerima key development dan skip bila key tidak terpasang.
- Admin harus mengaktifkan setidaknya satu kanal sebelum registrasi pembayaran dapat dilanjutkan; kondisi semua-OFF gagal tertutup dan ditampilkan jelas pada form.
- Ledger komisi belum menjadi bagian schema F1 yang telah dimigrasikan. Konsumen ledger mendatang harus memakai transisi pertama yang teraudit sebagai sinyal idempoten, bukan menghitung ulang dari klik atau upload bukti.
