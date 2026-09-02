# P14a0 backend — preflight sesi checkout privat

Tanggal: 2026-09-02

## Hasil audit

P13b sudah menghasilkan `CheckoutSessionScope` internal secara atomik, tetapi
belum ada session/cookie/HTTP. Session Laravel existing sudah server-side,
serialization JSON, cookie Secure/HttpOnly/SameSite=Lax, dan backend production
diwajibkan Redis. P16-prep hanya typed presentation props; ia tidak boleh menjadi
authority atau menghitung payer/nominal/access di browser.

ADR-012 mengusulkan Laravel session existing sebagai opsi P14 paling sederhana.
Bearer hanya diterima sekali lewat top-level HTTPS POST form body, tidak pernah
URL/header/cookie. Setelah P13b commit, adapter meregenerasi session ID, menyimpan
principal allowlist tanpa PII, merotasi CSRF token, lalu redirect 303 ke path
tokenless fixed. Initial exchange adalah satu-satunya exact CSRF exception;
seluruh mutation berikutnya tetap memakai CSRF Laravel. Semua pipeline response
harus no-store/private/no-referrer dan generic pada credential failure.

Setiap hydration reload persisted graph dan latest handoff generation. Login
participant/admin/branch/guest tidak memberi bypass; principal hanya selector
exact attempt/participant. Summary dibatasi attempt sendiri dan dilarang membawa
anggota/count/total batch, bill/gateway reference, invoice URL/proof, external
identity, credential, consent evidence, atau data klinis.

## Alternatif dan keputusan

- **Laravel session existing — direkomendasikan:** tanpa schema/RLS/credential
  baru, memakai cookie/CSRF/regeneration/expiry framework. Batasnya tidak ada
  unique active session global per attempt, cookie path existing `/`, dan DB
  consume tidak atomik dengan Redis/session write atau browser delivery.
- **Durable checkout-session row — ditolak untuk increment awal:** dapat memberi
  unique/revoke database-authoritative, tetapi memerlukan migration, RLS,
  selector/digest/cookie custom, cleanup dan deploy; cookie delivery crash tetap
  tidak dapat dibuat atomik.

Multi-tab dengan cookie sama berbagi satu principal. Exchange kedua mengganti,
bukan menambah, principal. Dua request bearer sama tetap at-most-one winner dari
P13b; response/cookie race boleh membuat browser kehilangan session tetapi tidak
membuat privilege kedua.

## Gap sebelum implementasi publik

Crash setelah P13b commit tetapi sebelum session berhasil ditulis wajib fail
closed: handoff tetap CONSUMED, raw replay invalid, dan trusted source harus
reissue. Namun issuer P13 existing tidak menerima ISSUE/REISSUE setelah latest
handoff CONSUMED. ADR-012 karena itu menetapkan dependency review terpisah untuk
recovery generation bounded. Token lama tidak boleh dihidupkan dan session lama
harus gagal hydration ketika bukan latest generation. Route P14 tidak boleh
diwiring publik sebelum gap ini diselesaikan.

## Rencana verifikasi

RED matrix ADR mencakup body-only transport, generic status/redirect, fixation,
cookie flags, exact CSRF exception, all later CSRF, actor/role/tenant denial,
IDOR, stale/revoked/expired principal, replay/concurrency/crash, privacy, serta
side-effect nol. SQLite dipakai untuk HTTP/session semantics; PostgreSQL
disposable dua proses untuk lock/recovery/revocation dan runtime non-owner;
browser test-only untuk POST source, URL/history, cookie, back/refresh/multi-tab,
mobile, console, dan network leak.

Tidak ada tes runtime pada P14a0 karena increment hanya dokumen. Verifikasi aktual:
seluruh file root terkait dibaca read-only; `git diff --check` dijalankan pada
delta dokumen. Tidak ada config, schema, route, controller, middleware, session
global, source, `.env`, data aktif, provider/notifier, deploy, push, P15, atau
P16 yang diubah. **STOP untuk review ADR-012 sebelum implementasi.**
