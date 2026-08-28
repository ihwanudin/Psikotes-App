# API_CONTRACT.md (v4.1 — Laravel)

Base: `https://psikotes.oncam.id`. Rute peserta (Inertia+React) dan API internal dilayani Laravel yang sama. Auth header untuk panggilan API: `Authorization: Bearer <jwt>` (peserta = JWT custom TTL 12 jam via middleware kustom). Panel admin/staf/psikolog (Filament) memakai sesi Laravel standar, bukan token API terpisah. Semua input divalidasi lewat Form Request Laravel (server-side, setara peran zod di stack lama); error format seragam `{error:{code,message}}`; rate-limit via Laravel throttle middleware.

## Integrasi Seleksi App

- `POST /api/integrations/v1/selection/participants` memprovisikan satu peserta beasiswa dan entitlement tes tanpa order komersial.
- Header wajib: `X-Client-Id`, `X-Timestamp` (Unix seconds), `X-Signature`, dan `Idempotency-Key` dengan pola `psychotest-participant:v1:<candidate-id>`.
- Signature adalah hex HMAC-SHA256 atas `timestamp + "\n" + sha256(raw_json_body)`. Secret integrasi berdiri sendiri dan request hanya diterima dalam toleransi waktu yang dikonfigurasi (default 300 detik).
- Payload: `{externalCandidateId,selectionRoundId,registrationId,fullName,birthDate,gender,educationLevel,email,phone}`. Respons sukses: `{data:{participantId}}` (`201` baru, `200` replay identik).
- Kunci/candidate yang dipakai ulang dengan data berbeda menghasilkan `409 IDEMPOTENCY_CONFLICT`; request tanpa autentikasi valid tidak boleh menyimpan data pribadi.
- Cabang, bidang tujuan default, dan daftar tes harus dikonfigurasi eksplisit. Integrasi tidak melakukan fallback ke cabang/paket komersial.

## Publik
- `GET  /r/:ref_code` — resolusi referral: set cookie first-touch 30 hari + catat `referral_visits`, redirect ke halaman daftar. ref tak dikenal → cabang default (pusat).
- `POST /registrations` — body form + `ref` (dari cookie/query; opsional) + `package_id` + `payment_method_code`. Paket wajib aktif, memiliki jenis tes, berharga positif dalam IDR; metode pembayaran wajib aktif. Server mengunci dan memvalidasi ulang keduanya saat transaksi, lalu membuat order `pending` dan entitlement `locked`, sehingga status ON→OFF yang bersamaan tidak dapat menghasilkan order baru. Kode metode yang tidak aktif/tidak dikenal menghasilkan validasi `payment_method_code: "Metode pembayaran tidak tersedia."`. Server menetapkan `referral_branch_id` (first-touch menang; kosong→default). Untuk `xendit`, invoice dibuat setelah transaksi registrasi commit; order menyimpan ID invoice, hosted-checkout URL, dan expiry lalu browser diarahkan ke checkout. Claim lokal menahan retry bersamaan; hasil provider yang tetap tidak diketahui tidak membatalkan registrasi dan entitlement tetap locked.
- `POST /registration/identity-evidence` — multipart `identity_document` + `initial_selfie`, hanya dari sesi registrasi yang terikat peserta dan aktif dua jam. JPG/PNG/WebP maksimal 5 MB, dimensi 480–8.000 px; MIME dibaca dari isi file. Berhasil → redirect ke `/registration/received`; matcher hanya mengisi penanda tinjauan.
- `POST /registration/manual-payment-proof` — multipart `payment_proof`, hanya dari sesi registrasi aktif dan selalu ditautkan server ke order transfer manual `pending` milik peserta sesi; request tidak menerima order/participant ID. Isi dan ekstensi harus JPG/JPEG/PNG/PDF, maksimal 5.000 KB. File disimpan privat dengan key acak; unggahan ulang mengganti bukti selama order masih `pending`.
- `POST /api/auth/participant/login` `{test_number, birth_date}` → `{jwt}` (rate-limit 5/menit/IP + lockout progresif per nomor tes)
- `GET  /orders/:id/status`
- `POST /webhooks/xendit` — verifikasi konstan-waktu header `x-callback-token` sebelum normalisasi payload. `id` harus cocok dengan `orders.gateway_ref`, `external_id` dengan ULID publik order, serta amount/currency dengan snapshot order. `PAID`/`SETTLED` dinormalisasi ke `paid`; `EXPIRED` mempertahankan entitlement locked. Event logis menggunakan hash ID invoice + status ternormalisasi, sehingga retry PAID/SETTLED tepat sekali tetapi cek PENDING tidak menghalangi PAID berikutnya. Claim event unik serta perubahan order+entitlement terjadi dalam satu transaksi service-RLS. Forged/payload invalid dibalas error generik tanpa detail; duplicate sah dan reordered terminal yang sudah aman dibalas 200.
- `POST /webhooks/:gateway` — gateway lain via adapter (signature sesuai gateway; idempotent by event_id)

Fallback status: scheduler menjalankan `php artisan payments:reconcile-xendit --limit=100` tiap lima menit, `withoutOverlapping()` dan `onOneServer()`. Hanya order Xendit `pending` yang memiliki `gateway_ref` yang diperiksa.

## Peserta (JWT)
- `GET  /api/me` · `GET /api/me/entitlements`
- `POST /api/sessions/:test_type/start` → `{session_id, ends_at, config, seed?}`. **403 bila entitlement ≠ ready (belum bayar).** 409 bila sudah ada sesi aktif/one-attempt terkunci. Batas implementasi Task 12: entitlement `ready` mendapat 501 `SESSION_ENGINE_PENDING` tanpa perubahan state sampai engine sesi menyediakan durasi/config/seed dan one-attempt lock secara atomik.
- `GET  /sessions/:id` → state + sisa waktu (resume)
- `POST /sessions/:id/answers` — batch upsert `{items:[{item_no,value}]}` (IST/PAPI/RMIB; auto-save)
- `POST /sessions/:id/events` — batch Kraepelin `{col,row,answer,client_ts_ms}[]` (insert-ignore per seq)
- `POST /sessions/:id/subtest/next` (IST)
- `POST /sessions/:id/proctor` — foto multipart | log event
- `POST /sessions/:id/submit` → `{status:'scored'}`
- `GET  /reports/me/url` → `{url, expires_in:900}`

## Admin (sesi Laravel/Filament; scope RLS via middleware)
- Peserta: `GET /admin/participants?status&branch` · `GET /admin/participants/:id` (detail + timeline + foto) · `POST /admin/participants/:id/void-session` · `POST /admin/orders/:id/activate-manual`
- Transfer manual: halaman Filament `GET /admin/orders` hanya tersedia bagi admin berkemampuan `verify_payments` dan hanya menampilkan cabangnya (super admin global). Aksi Livewire Setujui/Tolak memvalidasi ulang policy, mengunci order, dan mengikat keputusan ke object key bukti yang ditinjau. Bukti yang berubah memaksa admin membuka versi terbaru. Replay keputusan sama adalah no-op; keputusan berlawanan ditolak; penolakan wajib menyimpan alasan maksimal 500 karakter.
- `GET /admin/manual-payment-proofs/:order_public_id/open` — menerbitkan redirect ke URL sementara privat 15 menit setelah policy dan scope cabang lulus. Penerbitan diaudit tanpa URL/object key dan dibatasi 30 permintaan/menit/admin.
- Bukti identitas: `POST /admin/identity-evidence/:public_id/temporary-url` → `{url, expires_at}` setelah policy peserta/RLS; URL berlaku 15 menit dan penerbitannya dicatat di `audit_logs` tanpa menyimpan URL/object key di konteks audit.
- Laporan: `GET /admin/reports/:participant/url` · `POST /admin/reports/:participant/regenerate` · `GET /admin/reports/:participant/integration` (draf) · `PUT /admin/reports/:participant/integration` (psikolog simpan teks tersunting; tak tertimpa saat regenerate) · `POST /admin/reports/:participant/finalize` (draft→reviewed→final, kunci norm_version)
- Fee: `GET /admin/fees/summary?branch` · `POST /admin/withdrawals` (cabang) · `POST /admin/withdrawals/:id/decide` · `POST /admin/withdrawals/:id/mark-paid` (pusat, +bukti)
- Master: CRUD packages, branches, admins, psychologists; `POST /admin/ge-dictionary` (tambah kamus GE dr unknown); `GET /admin/audit-logs`
- Ekspor: `GET /admin/export/participants.csv?branch&period`

Idempoten ingest: `answers` upsert (session,item); `kraepelin_events` unique (session,seq). Webhook & submit aman diulang.
