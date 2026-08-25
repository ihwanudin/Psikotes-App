# API_CONTRACT.md (v4.0 — Laravel)

Base: `https://psikotes.oncam.id`. Rute peserta (Inertia+React) dan API internal dilayani Laravel yang sama. Auth header untuk panggilan API: `Authorization: Bearer <jwt>` (peserta = JWT custom TTL 12 jam via middleware kustom). Panel admin/staf/psikolog (Filament) memakai sesi Laravel standar, bukan token API terpisah. Semua input divalidasi lewat Form Request Laravel (server-side, setara peran zod di stack lama); error format seragam `{error:{code,message}}`; rate-limit via Laravel throttle middleware.

## Publik
- `GET  /r/:ref_code` — resolusi referral: set cookie first-touch 30 hari + catat `referral_visits`, redirect ke halaman daftar. ref tak dikenal → cabang default (pusat).
- `POST /registrations` — body form + `ref` (dari cookie/query; opsional) + `package_id` + `payment_method_code`. Paket wajib aktif, memiliki jenis tes, berharga positif dalam IDR; metode pembayaran wajib aktif. Server mengunci dan memvalidasi ulang keduanya saat transaksi, lalu membuat order `pending` dan entitlement `locked`, sehingga status ON→OFF yang bersamaan tidak dapat menghasilkan order baru. Kode metode yang tidak aktif/tidak dikenal menghasilkan validasi `payment_method_code: "Metode pembayaran tidak tersedia."`. Server menetapkan `referral_branch_id` (first-touch menang; kosong→default). Task 15 melengkapi order Xendit dengan invoice; Task 16 menangani unggahan bukti transfer manual.
- `POST /registration/identity-evidence` — multipart `identity_document` + `initial_selfie`, hanya dari sesi registrasi yang terikat peserta dan aktif dua jam. JPG/PNG/WebP maksimal 5 MB, dimensi 480–8.000 px; MIME dibaca dari isi file. Berhasil → redirect ke `/registration/received`; matcher hanya mengisi penanda tinjauan.
- `POST /api/auth/participant/login` `{test_number, birth_date}` → `{jwt}` (rate-limit 5/menit/IP + lockout progresif per nomor tes)
- `GET  /orders/:id/status`
- `POST /webhooks/xendit` — verifikasi header `x-callback-token` = token dashboard; baca `external_id`(=order_id) & `status`; PAID/SETTLED → order paid + entitlement ready + bekukan komisi + notif WA. **Balas 200 ≤30 dtk** (Xendit retry 24 jam bila gagal). Idempotent by `id` invoice (unik). Status lain (EXPIRED) → order expired, entitlement tetap locked.
- `POST /webhooks/:gateway` — gateway lain via adapter (signature sesuai gateway; idempotent by event_id)

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
- Peserta: `GET /admin/participants?status&branch` · `GET /admin/participants/:id` (detail + timeline + foto) · `POST /admin/participants/:id/void-session` · `POST /admin/orders/:id/verify` (paid|reject) · `POST /admin/orders/:id/activate-manual`
- Bukti identitas: `POST /admin/identity-evidence/:public_id/temporary-url` → `{url, expires_at}` setelah policy peserta/RLS; URL berlaku 15 menit dan penerbitannya dicatat di `audit_logs` tanpa menyimpan URL/object key di konteks audit.
- Laporan: `GET /admin/reports/:participant/url` · `POST /admin/reports/:participant/regenerate` · `GET /admin/reports/:participant/integration` (draf) · `PUT /admin/reports/:participant/integration` (psikolog simpan teks tersunting; tak tertimpa saat regenerate) · `POST /admin/reports/:participant/finalize` (draft→reviewed→final, kunci norm_version)
- Fee: `GET /admin/fees/summary?branch` · `POST /admin/withdrawals` (cabang) · `POST /admin/withdrawals/:id/decide` · `POST /admin/withdrawals/:id/mark-paid` (pusat, +bukti)
- Master: CRUD packages, branches, admins, psychologists; `POST /admin/ge-dictionary` (tambah kamus GE dr unknown); `GET /admin/audit-logs`
- Ekspor: `GET /admin/export/participants.csv?branch&period`

Idempoten ingest: `answers` upsert (session,item); `kraepelin_events` unique (session,seq). Webhook & submit aman diulang.
