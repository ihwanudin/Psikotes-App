# P14a0 backend — preflight sesi checkout privat

Tanggal: 2026-09-02

## Hasil audit

P13b sudah menghasilkan `CheckoutSessionScope` internal secara atomik, tetapi
belum ada session/cookie/HTTP. Session Laravel existing sudah server-side,
serialization JSON, cookie Secure/HttpOnly/SameSite=Lax, dan backend production
diwajibkan Redis. P16-prep hanya typed presentation props; ia tidak boleh menjadi
authority atau menghitung payer/nominal/access di browser.

Review menemukan global Laravel session tidak aman untuk exchange lintas-site.
Cookie ONCAM `SameSite=Lax` tidak dikirim oleh top-level POST dari
`seleksi.beasiswajepang.id` atau `seleksi.serbaindo.com`; Set-Cookie global baru
dapat mengganti pointer session auth existing yang tidak pernah terlihat request.
Regeneration tidak dapat mempertahankan session yang tidak dikirim browser.

ADR-012 revisi memilih durable `checkout_sessions` record dengan cookie khusus
`__Secure-oncam_checkout_session`, host-only, Path `/checkout`, Secure, HttpOnly, dan
SameSite=Lax. Exchange tidak menjalankan global `StartSession`, tidak membaca/
menulis cookie auth, dan tidak memutasi config session pada request/Octane.
Bearer tetap hanya exact HTTPS POST form body; sukses memberi dedicated cookie
dan redirect 303 tokenless. Session checkout menyimpan selector digest, scope
persisted, CSRF digest, lifecycle dan expiry tanpa PII.

Setiap hydration reload persisted graph dan latest handoff generation. Login
participant/admin/branch/guest tidak memberi bypass; principal hanya selector
exact attempt/participant. Summary dibatasi attempt sendiri dan dilarang membawa
anggota/count/total batch, bill/gateway reference, invoice URL/proof, external
identity, credential, consent evidence, atau data klinis.

## Alternatif dan keputusan revisi

- **Global Laravel session — ditolak:** cross-site POST Lax tidak membawa old
  cookie; response dapat overwrite auth cookie. Temporary global config mutation
  untuk cookie/store kedua juga ditolak karena request-unsafe pada Octane.
- **Durable checkout-session row + dedicated cookie — direkomendasikan:** tidak
  menyentuh auth cookie, dapat membuat consume+record atomik dan memberi exact
  RLS/revoke/expiry/one-active semantics. Konsekuensinya migration, custom CSRF,
  cleanup dan deploy plan harus direview pada increment berikutnya.

Multi-tab dengan dedicated cookie sama berbagi satu principal. Cookie baru hanya
menimpa cookie checkout, bukan auth. Commit database tetap tidak atomik dengan
browser delivery; response hilang meninggalkan orphan session yang harus direvoke
oleh recovery atau expire, tanpa menghidupkan bearer.

## Gap sebelum implementasi publik

Crash setelah transaksi consume+record commit tetapi sebelum browser menerima
dedicated cookie wajib fail closed: handoff tetap CONSUMED, record dapat orphan
ACTIVE, raw replay invalid, dan trusted source harus recovery. Namun issuer P13
existing tidak menerima ISSUE/REISSUE setelah latest handoff CONSUMED. ADR-012
karena itu menetapkan dependency review terpisah untuk recovery generation
bounded yang juga merevoke orphan. Token lama tidak boleh dihidupkan dan session
lama harus gagal hydration ketika bukan latest generation. Route P14 tidak boleh
diwiring publik sebelum gap ini diselesaikan.

## Rencana verifikasi

RED matrix ADR mencakup body-only transport, generic status/redirect, fixation,
cookie khusus, exact CSRF exchange, CSRF durable untuk seluruh mutation, actor/
role/tenant denial, IDOR, stale/revoked/expired principal, replay/concurrency/
crash, privacy, serta side-effect nol. Wajib ada browser/HTTP case dengan auth
cookie ONCAM existing: cross-site POST tidak mengirim cookie Lax itu dan response
tidak boleh overwrite, clear, atau memberi bypass. SQLite dipakai untuk portable
HTTP/cookie semantics; PostgreSQL disposable untuk schema/RLS/lock/recovery;
browser test-only untuk dua source-site, URL/history, cookie protocol, back/
refresh/multi-tab, console, dan network leak.

Tidak ada tes runtime pada P14a0 karena increment hanya dokumen. Verifikasi aktual:
seluruh file root terkait dibaca read-only; `git diff --check` dijalankan pada
delta dokumen. Tidak ada config, schema, route, controller, middleware, session
global, source, `.env`, data aktif, provider/notifier, deploy, push, P15, atau
P16 yang diubah. **STOP untuk review ADR-012 sebelum implementasi.**
