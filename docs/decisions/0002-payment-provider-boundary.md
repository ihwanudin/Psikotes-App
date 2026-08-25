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
- Event diterapkan berdasarkan `gateway_ref` unik milik order, bukan pasangan order ID dan reference dari request. Reference yang tidak dikenal gagal tertutup.
- `FakePaymentProvider` menjadi implementasi deterministik untuk contract test dan tidak didaftarkan sebagai provider produksi. Xendit akan menjadi adapter tersendiri.
- Kanal pembayaran kanonis dibuat default nonaktif. Hanya super admin yang dapat mengubah aktivasi melalui action ber-row-lock; setiap perubahan state dicatat di `audit_logs` dalam transaksi service-RLS yang sama. Replay state identik adalah no-op tanpa audit duplikat.
- Registrasi hanya mengekspos kanal aktif. Paket dan kanal dikunci serta divalidasi ulang sebelum participant, order `pending`, dan entitlement `locked` dibuat atomik. Order menyimpan `payment_method_id`, nominal, dan mata uang sebagai snapshot transaksi; menonaktifkan kanal hanya mencegah order baru dan tidak menyembunyikan order historis.
- Task 13 menjamin replay aman pada state machine. Persistensi `event_id` unik, autentikasi callback Xendit, dan idempotensi delivery lintas proses adalah tanggung jawab Task 15.

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
- Task 15 wajib menyimpan event terautentikasi dengan constraint unik sebelum mengandalkan contract ini untuk webhook produksi.
- Admin harus mengaktifkan setidaknya satu kanal sebelum registrasi pembayaran dapat dilanjutkan; kondisi semua-OFF gagal tertutup dan ditampilkan jelas pada form.
