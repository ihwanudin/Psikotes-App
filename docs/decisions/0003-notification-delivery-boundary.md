# ADR-003: Batas pengiriman notifikasi aktivasi

## Status

Accepted

## Date

2026-08-25

## Context

Transisi pembayaran ke `paid` harus mengaktifkan akses tes walaupun penyedia WhatsApp sedang gagal. Callback pembayaran dan pekerjaan queue dapat dikirim ulang, berhenti setelah provider menerima request, atau mati sebelum status lokal diperbarui. Pesan membawa nomor tes dan nomor telepon sehingga payload, audit, dan log harus dibatasi.

WAHA menyediakan operasi `POST /api/sendText`, tetapi kontrak yang ditinjau tidak menjamin idempotensi pada timeout dengan outcome tidak diketahui. n8n dapat menjadi batas workflow yang melakukan deduplikasi sebelum memanggil WAHA dan memungkinkan template pesan diubah tanpa mengikat domain pembayaran ke API WAHA.

## Decision

- Transisi pertama order ke `paid` menulis satu event `participant.activation` ke outbox dalam transaksi order yang sama. Unique `deduplication_key` mencegah replay webhook atau keputusan admin membuat pesan kedua.
- Queue hanya membawa ULID `message_id`. Worker memuat order, peserta, dan entitlement terbaru di bawah service RLS setelah mengklaim row dengan lock; data kredensial tidak disalin ke payload outbox atau job.
- Adapter produksi adalah webhook n8n HTTPS ber-Bearer token. Request membawa `Idempotency-Key` dan `idempotency_key` yang sama. Workflow n8n wajib menolak request tanpa token dan menyimpan key tersebut secara atomik sebelum memanggil WAHA.
- Laravel tidak melakukan retry HTTP langsung. Timeout yang outcome-nya tidak diketahui ditangani sebagai kegagalan job dan dapat dikirim ulang dengan key sama; n8n bertanggung jawab mengembalikan hasil deduplikasi tanpa mengirim WhatsApp kedua.
- Maksimal lima claim dilakukan dengan backoff 30, 120, 600, lalu 1.800 detik. Scheduler setiap menit memulihkan pesan `pending`, `failed`, atau `processing` yang macet lebih dari 10 menit. Pesan kedaluwarsa dan pesan yang sudah mencapai lima claim tidak didispatch lagi.
- Status `paid` dan entitlement `ready` tidak pernah di-rollback oleh kegagalan provider. Setiap hasil pengiriman dicatat di audit lima tahun dan structured log tanpa nomor telepon, nomor tes, nama, tanggal lahir, nominal, atau isi respons provider.
- Endpoint status peserta selalu menurunkan participant dari JWT atau sesi registrasi server. Respons menggunakan `Cache-Control: no-store, private` dan tidak mengekspos participant ID, gateway reference, proof object key, atau invoice URL.

## Alternatives Considered

### Memanggil WAHA langsung dari Laravel

Lebih sedikit komponen, tetapi timeout setelah WAHA menerima pesan tidak dapat dibedakan dari request yang belum diterima. Tanpa kontrak idempotensi provider, retry dapat mengirim kredensial ganda. Ditolak untuk F1.

### Mengirim notifikasi di transaksi pembayaran

Memberi feedback segera, tetapi network call akan memperpanjang lock dan kegagalan provider dapat membatalkan aktivasi pembayaran. Ditolak karena pembayaran dan delivery mempunyai failure domain berbeda.

### Menyimpan seluruh pesan di outbox

Memudahkan worker tanpa query tambahan, tetapi menduplikasi PII dan kredensial ke tabel operasional serta job backend. Ditolak; outbox hanya menyimpan versi schema dan aggregate reference.

## Consequences

- Redis cache harus dipakai bersama oleh seluruh node agar lock `ShouldBeUnique` efektif. Worker wajib mendengarkan `notifications,default`; `retry_after` Redis harus lebih panjang daripada timeout worker.
- n8n adalah komponen wajib untuk delivery produksi. Tanpa URL HTTPS dan token, adapter gagal tertutup dengan `n8n_not_configured`; aktivasi peserta tetap berlaku dan operator melihat outbox gagal.
- Workflow n8n/WAHA belum dapat diuji dari repository sampai endpoint development tersedia. Fake adapter dan HTTP-fake contract menjadi gate lokal.
- Setelah lima claim, operator harus memperbaiki provider/configuration lalu mengatur pesan secara eksplisit untuk retry; sistem tidak membuat loop tanpa batas.
