# ADR-012: Sesi privat checkout terintegrasi

## Status

Proposed untuk review P14a0. Dokumen ini belum mengizinkan implementasi,
registrasi route, perubahan CSRF/session global, config baru, migration, source
checkout aktif, atau wiring P14/P15/P16.

## Date

2026-09-02

## Context

P13a menerbitkan bearer handoff `och1_` berumur maksimum 600 detik. P13b menukar
bearer itu tepat sekali menjadi `CheckoutSessionScope` internal setelah mengunci
dan memvalidasi ulang organization, integration client/source, package, attempt,
participant, serta history handoff. Hasil P13b belum merupakan Laravel session,
cookie, hak assessment, bukti pembayaran, persetujuan, atau identity verification.

Browser berikutnya perlu menampilkan ringkasan privat untuk satu attempt tanpa
memasukkan bearer ke URL dan tanpa memakai login participant/admin sebagai
bypass. Session harus tetap gagal tertutup ketika graph persisted berubah.
Ringkasan tidak boleh membocorkan anggota batch, total/invoice organisasi,
credential provider, data klinis, atau attempt peserta lain.

Konfigurasi existing memakai server-side Laravel session (`database` sebagai
default development, Redis diwajibkan pada production), serialization JSON,
cookie Secure, HttpOnly, SameSite=Lax, host-only bila `SESSION_DOMAIN` null, dan
path `/`. Session table existing menyimpan opaque session ID, nullable user ID,
IP, user agent, payload, dan last activity. P14 tidak memerlukan perubahan tabel
itu untuk menyimpan principal kecil berisi identifier non-PII.

P16-prep hanya kontrak presentasi React. `IntegratedCheckoutProps` bukan response
HTTP atau authority. Field seperti branch, package, payer, nominal, payment
state, access state, identity message, dan consent harus dibentuk server-side
setelah hydration P14; browser tidak menghitung atau memilih scope.

## Threat model

Asset utama adalah kemampuan session checkout untuk membaca dan kelak mengubah
hanya attempt yang ditetapkan handoff. Ancaman yang perlu ditutup:

- bearer masuk path, query, fragment, redirect `Location`, `Referer`, access log,
  validation echo, exception, audit, telemetry, atau session payload;
- session fixation sebelum exchange atau cookie session dicuri oleh script;
- replay bearer, dua exchange paralel, atau response race menghasilkan dua scope;
- participant/admin/branch login, referral cookie, Host, Origin, atau ID request
  dipakai sebagai authority tambahan;
- session lama tetap hidup setelah organization/client/source/package/attempt/
  participant dinonaktifkan, dipindah, dicabut, atau dihapus;
- IDOR mengganti attempt/participant/organization pada URL, body, query, Inertia
  props, atau callback P16;
- error/cache/browser history membocorkan identitas, credential, bill reference,
  invoice URL, total batch, jumlah anggota, atau data klinis;
- consume database commit tetapi session store/cookie response gagal;
- CSRF pada operasi setelah cookie menjadi credential otomatis browser.

TLS dan hardening host tetap prasyarat transport. Session tidak mengubah bearer
menjadi bukti settlement, consent, identity, entitlement, atau readiness.

## Actor, action, and resource matrix

| Actor/caller | Exchange bearer | Hydrate checkout | Ubah checkout kelak | Resource authority |
| --- | --- | --- | --- | --- |
| Anonymous browser + bearer P13 exact dalam POST body | Allow sekali | Setelah session berhasil tersimpan | Hanya melalui P15 + CSRF | Bearer memilih persisted handoff; seluruh graph direload |
| Anonymous tanpa bearer, malformed, replay, expired, revoked, foreign | Deny generik | Deny generik | Deny | Tidak ada fallback registrasi/default branch |
| Checkout-session principal valid | Tidak perlu bearer lagi | Allow hanya exact attempt/participant | Allow hanya action P15 yang explicit | Principal adalah selector; database tetap authority |
| Participant login/JWT tanpa checkout principal | Deny | Deny | Deny | Login participant bukan scope checkout-v2 |
| BranchAdmin/Staff/Psychologist/SuperAdmin login | Deny | Deny | Deny | Tidak ada role/admin bypass pada route checkout |
| Guest dengan referral/invitation/assessment-start token | Deny | Deny | Deny | Purpose lain tidak dapat ditukar |
| Integration client HMAC tanpa browser bearer | Deny pada P14 | Deny | Deny | Client hanya menerbitkan/recover melalui boundary P13 terpisah |

Authenticated roles boleh kebetulan mempunyai cookie Laravel yang sama, tetapi
guard tersebut diabaikan oleh middleware checkout. Sebaliknya checkout principal
tidak memberi akses panel, participant API, assessment start, atau tenant lain.

## Decision

### 1. Gunakan server-side Laravel session existing

P14 direkomendasikan menyimpan principal checkout sebagai array JSON kecil di
namespace session khusus, misalnya `integrated_checkout.principal`. Nilai yang
boleh disimpan hanya:

- version integer;
- public handoff ULID;
- internal assessment-participant ID dan public assessment-attempt ULID;
- organization, participant, package, integration-client, dan integration-source
  IDs;
- source system;
- consumed-at, established-at, last-seen-at, dan absolute-expiry timestamps.

Raw bearer, token digest, idempotency digest, external candidate/registration ID,
nama, email, telepon, bill/invoice reference, invoice URL, payer credential,
consent text, identity evidence, clinical data, dan payment proof dilarang dalam
session. Fixed purpose `checkout-handoff`, destination
`integrated-checkout-session`, dan contract `checkout-v2` divalidasi dari row
persisted; nilai itu bukan input browser.

Session principal memakai idle timeout 30 menit dan absolute lifetime maksimum
120 menit dari database `consumed_at`, serta tidak boleh melebihi global session
lifetime yang lebih pendek. Angka ini kelak menjadi config integer tervalidasi,
bersama `enabled=false`; P14a0 tidak menambah config. Hydration memakai database
wall clock. Request aktif boleh memperbarui `last_seen_at` di session, tetapi
tidak memperpanjang absolute expiry.

Config future yang diajukan adalah namespace terpisah
`assessment_integration.checkout_session` dengan `enabled=false`,
`idle_minutes=30`, dan `absolute_minutes=120`. Gate hanya menerima boolean exact
serta integer positif dengan `idle <= absolute <= global session lifetime`;
config malformed gagal tertutup. Nilai tidak dibaca dari request atau Host.

Cookie existing tetap opaque, encrypted/signed oleh framework middleware, Secure,
HttpOnly, SameSite=Lax, host-only ketika domain null, dan path `/`. P14 tidak
mengubah setting global. Network scope cookie memang seluruh host, tetapi hanya
middleware route checkout yang membaca namespace principal; route lain tidak
mendapat authority dari keberadaan key itu. Kebutuhan cookie dengan path khusus
memerlukan session stack/cookie terpisah dan harus menjadi ADR lain.

### 2. Typed boundary

Surface implementasi yang diusulkan:

```text
CheckoutSessionExchangeInput
  private #[SensitiveParameter] rawHandoffToken: string

CheckoutSessionPrincipal
  version, handoffPublicId, assessmentParticipantId, assessmentAttemptId,
  organizationId, participantId, packageId, integrationClientId,
  integrationSourceId, sourceSystem, consumedAt, establishedAt,
  lastSeenAt, absoluteExpiresAt

HydratedCheckoutSession
  CheckoutSessionPrincipal + authoritative attempt/participant scope
```

Input bukan array extensible dan tidak menerima organization, participant,
attempt, package, payer, amount, return URL, purpose, destination, Host, Origin,
atau Referer. DTO raw tidak boleh `JsonSerializable`, tidak mempunyai public
property, dan tidak masuk exception/log. Principal/result boleh diserialisasi
hanya melalui allowlist descriptor tanpa PII.

P13b tetap satu-satunya consumer bearer. Adapter P14 tidak menyalin validasi
digest/lifecycle. Hydrator P14 tidak mempercayai DTO sebagai authority; setiap
request memuat dan memeriksa persisted graph serta latest handoff generation.

### 3. Exchange HTTP dan transport token

Route produksi yang diusulkan, tetapi belum didaftarkan:

```text
POST /checkout/session       exchange bearer
GET  /checkout               hydrate/render ringkasan privat
POST /checkout/logout        hapus principal checkout
GET  /checkout/unavailable   halaman error generik tokenless
```

Exchange hanya menerima `application/x-www-form-urlencoded` dengan tepat satu
field `handoffToken`; nilai wajib string format P13, maksimum 69 byte. Multipart,
JSON, query, route parameter, cookie, header Authorization, dan unknown field
ditolak. Token tidak boleh berada pada path/query/fragment. Trusted source
membuat top-level browser POST HTTPS; Origin/Referer bukan authority.

Sukses memanggil P13b, lalu meregenerasi session ID dengan old ID invalidated,
mengganti namespace principal, merotasi CSRF token, dan hanya setelah itu memberi
`303 See Other` ke fixed `/checkout`. Tidak ada return URL browser. Redirect
`Location` tidak memuat token, attempt ID, atau participant ID.

Malformed, unknown, expired, revoked, consumed, wrong-purpose, wrong-destination,
wrong-tenant, dan config-off memakai satu hasil credential generik. Ketika route
kelak terdaftar tetapi gate OFF, hasil adalah generic 404. Ketika gate ON,
credential failure adalah `303` ke fixed `/checkout/unavailable`; halaman itu
tidak membedakan alasan dan tidak mendaftarkan peserta baru. Rate limit tetap 429
generik; unexpected failure tetap framework 500 generik. Seluruh jalur mendapat
privacy headers, termasuk rejection awal, throttle, validation, dan 500.

### 4. Session fixation, multi-tab, dan one-session semantics

Session ID selalu diregenerasi setelah consume sukses dan sebelum principal
ditulis. Existing participant/admin login di cookie yang sama bukan authority;
regenerasi mempertahankan data session lain tetapi mengganti ID. Checkout logout
menghapus hanya namespace checkout, meregenerasi ID, dan merotasi CSRF token agar
tidak diam-diam logout dari guard lain.

Semantik yang dijanjikan oleh opsi ini:

- satu raw bearer hanya dapat menghasilkan satu eksekusi exchange pemenang karena
  P13b atomik;
- tab dengan cookie yang sama berbagi satu checkout principal;
- exchange handoff baru di browser yang sama mengganti principal lama, bukan
  menumpuk daftar attempt;
- request tanpa principal exact tidak dapat memilih attempt dari URL/body;
- tidak ada klaim database-authoritative "satu session aktif global per attempt"
  tanpa durable session record.

Dua POST paralel dari browser tanpa cookie dapat memakai dua session sementara,
tetapi hanya satu lolos consume. Urutan response cookie dapat membuat browser
kehilangan cookie pemenang; ini fail closed dan tidak menciptakan privilege kedua.
Test harus membuktikan at-most-one principal, bukan menjanjikan delivery tepat
sekali ke browser.

### 5. CSRF

Initial `POST /checkout/session` adalah satu-satunya exception CSRF yang diusulkan.
Alasannya: cross-site top-level POST dari trusted source memang diperlukan,
browser belum memiliki CSRF token ONCAM, cookie/login tidak dipakai sebagai
authority, dan bearer 256-bit satu kali adalah credential unguessable. Exception
harus exact route+method, bukan prefix checkout, dan tidak boleh meluas ke API/
route lain. SameSite tidak menggantikan aturan ini.

Setelah session cookie terbentuk, setiap request state-changing termasuk profile,
consent, payer/payment intent, refresh yang memutasi, dan logout wajib melewati
Laravel CSRF normal. Tidak ada GET yang memutasi. Origin/Host/Referer boleh dipakai
untuk telemetry aman atau defense-in-depth, tetapi tidak memberi atau menolak
authority yang seharusnya berasal dari bearer/session+persisted graph.

### 6. Hydration, IDOR, tenant, dan revocation

Setiap request checkout memulai tanpa ambient participant/admin bypass. Middleware
memuat routing hint dari principal, lalu dalam service boundary mengikuti lock/
reload canonical yang konsisten dengan P13 untuk memeriksa:

1. organization active;
2. integration client active/effective dan masih milik organization;
3. integration source checkout-v2 active/effective dan masih milik client;
4. package active, source-allowed, dan snapshot yang diperlukan valid;
5. exact assessment participant masih terikat organization/client/source/package/
   participant dan belum revoked;
6. participant belum soft-deleted;
7. handoff public ID adalah CONSUMED yang exact, purpose/destination/version benar,
   scope sama, dan merupakan latest generation untuk attempt.

Selector attempt/participant dari route, query, body, Inertia props, login user,
referral cookie, atau callback P16 tidak diterima. Bila session idle/absolute
expired atau graph berubah, namespace checkout dihapus, session ID/CSRF token
dirotasi, dan response generik diarahkan ke unavailable. Revocation harus efektif
pada request berikutnya; tidak ada cache principal sebagai authority.

Hydration hanya boleh menghasilkan proyeksi attempt sendiri. Untuk billing
organization, output maksimum adalah amount/status allocation attempt itu dan
nama organisasi yang memang boleh dilihat peserta. Dilarang memuat/serialize
bill parent, merchant/gateway reference, invoice URL, proof, member IDs/names,
jumlah anggota, total batch, atau charge attempt lain. Clinical result, DASS,
identity evidence, credential, dan internal audit juga tidak masuk props/error.

### 7. Privacy dan security headers

Boundary middleware khusus checkout harus membungkus seluruh pipeline, termasuk
gate OFF, CSRF failure, validation, throttle, redirect, 404, dan unexpected 500:

```text
Cache-Control: no-store, private
Pragma: no-cache
Referrer-Policy: no-referrer
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'
```

CSP final halaman mengikuti asset Inertia/Vite nyata dan harus diuji; tidak boleh
melonggarkan `unsafe-inline` hanya untuk harness. Error tidak memuat raw bearer,
digest, PII, SQL, table/model names, credential, scope ID, atau stack trace ketika
`APP_DEBUG=false`. Session/cookie/CSRF values tidak dicatat pada audit bisnis.

Named limiter yang diusulkan: exchange maksimum 10/menit per IP tanpa token/
digest pada cache key; hydrated reads maksimum 60/menit per hash session ID+IP;
state-changing checkout maksimum 10/menit per safe handoff public ID+IP. Nilai
final perlu acceptance load/abuse test. Limiter adalah kontrol DoS, bukan authority.

### 8. Crash window dan recovery

Transaksi P13b commit sebelum framework menyimpan session dan mengirim cookie.
Tidak ada transaksi atomik lintas PostgreSQL, Redis/database session store, dan
browser. Jika consume berhasil tetapi regenerate/write/session middleware atau
response delivery gagal:

- handoff tetap CONSUMED dan tidak pernah dikembalikan ke ISSUED;
- retry raw bearer gagal generik;
- tidak ada fallback login/registration atau rekonstruksi raw token;
- pengguna meminta trusted source menerbitkan recovery handoff baru;
- implementasi tidak boleh mengklaim exactly-once delivery ke browser.

Ada gap existing yang harus diselesaikan sebelum P14 route wiring: P13 issuer
saat ini menolak ISSUE ketika history ada dan menolak REISSUE ketika tidak ada
handoff ISSUED active. Karena handoff yang crash-window sudah CONSUMED, source
belum dapat melakukan recovery. Increment terpisah harus memperluas intent
recovery secara bounded: hanya authenticated exact integration client, latest
generation CONSUMED, generation baru, audit aman, dan hydration session lama
ditolak ketika bukan latest generation. Ia tidak boleh membuktikan bahwa session
write benar-benar gagal, menghidupkan token lama, atau menciptakan billing/access.
Kontrak dan race recovery ini memerlukan review sebelum implementasi P14.

## Alternatives considered

### A. Laravel session existing (recommended)

Keuntungan:

- memakai lifecycle, storage, cookie encryption, CSRF, regeneration, expiry, dan
  test primitives framework yang sudah dipelihara;
- tidak menambah schema/RLS/cleanup worker atau custom credential parser;
- payload principal server-side dan JSON, sementara cookie hanya opaque ID;
- multi-tab mengikuti perilaku browser yang sudah dipahami.

Batas:

- consume DB dan session write/cookie delivery tidak atomik;
- cookie path `/` dikirim ke seluruh host, walau authority checkout tetap route-
  scoped di server;
- tidak ada unique active session per attempt lintas browser;
- session backend Redis tidak ikut PostgreSQL RLS/transaction.

### B. Durable `checkout_sessions` record + cookie selector

Keuntungan:

- record dapat dibuat dalam transaksi PostgreSQL yang sama dengan consume bila
  P13b direfaktor;
- unique active attempt/generation, explicit revoke, absolute expiry, dan audit
  lifecycle dapat database-authoritative;
- cookie selector dapat dipisahkan dari global auth session.

Kerugian:

- perlu migration, digest/selector baru, FORCE RLS, lifecycle/cleanup/index,
  rotation, custom middleware/cookie, deploy plan, dan PostgreSQL concurrency;
- cookie delivery tetap berada di luar transaksi, sehingga record dapat orphan
  dan crash window browser tidak hilang;
- memperluas penyimpanan credential dan attack surface tanpa kebutuhan P14 saat
  ini;
- integrasi CSRF/Inertia/multi-tab menjadi custom dan lebih sulit direview.

Advisory/cache lock tanpa durable record tidak dipilih: ia tidak memberi lifecycle
atau recovery lintas crash dan tidak boleh menjadi authority.

## Consequences

- P14 implementation pertama dapat tetap tanpa migration dan memakai framework
  primitives existing.
- Config/session/CSRF global tidak berubah. Route production dan config flag tetap
  OFF/unregistered sampai review implementation dan browser tests selesai.
- Hydration per request menambah query persisted, tetapi menjaga revocation/IDOR
  fail closed. Optimisasi cache tidak boleh mengurangi authority checks.
- Recovery-after-consume adalah dependency eksplisit baru yang harus direview;
  P14 tidak boleh wired publik sebelum gap itu ditutup.
- P14 tidak mengubah P16 props menjadi HTTP authority dan tidak memulai P15.

## Default-off implementation increments proposed

1. **P13 recovery amendment:** ADR/action/test terpisah untuk generation baru
   setelah latest CONSUMED; no route.
2. **P14a1 internal session adapter:** typed input/principal, session rotation,
   hydration/revocation middleware, privacy boundary, feature tests; routes hanya
   synthetic test registration.
3. **P14a2 HTTP contract:** request/controller dan test-only exact CSRF exception,
   redirect/error/header/rate-limit tests; production route masih absent.
4. **P14b summary projection:** own-attempt DTO untuk P16 props, tanpa mutations.
5. **Public wiring review:** baru menambah default-off config/route setelah browser,
   PostgreSQL, security, and crash-recovery evidence diterima.

Sampai increment kelima, test feature mendaftarkan route sintetis di runtime test
dan memakai controller/request/middleware nyata. Harness memakai config in-memory,
session store disposable, source page loopback sintetis, dan tidak mengedit
`routes/web.php`, `bootstrap/app.php`, `config/session.php`, atau exception CSRF
global. Route produksi tetap tidak ditemukan ketika config/source belum disetujui.

## RED test matrix

| Area | RED cases before GREEN |
| --- | --- |
| HTTP input | POST body exact succeeds; query/path/fragment/header/cookie/multipart/JSON/unknown field rejected; token absent from Location/Referer/body/log |
| Generic outcome | malformed/unknown/expired/revoked/consumed/wrong-purpose/destination/scope identical; OFF 404; 429 and APP_DEBUG=false 500 private/generic |
| Cookie/fixation | preseeded session ID changes; old ID unusable; Secure/HttpOnly/SameSite=Lax/host-only expectations; no bearer/PII in cookie or session payload |
| Redirect | success 303 fixed `/checkout`; failure fixed unavailable; no attacker return URL/open redirect; refresh GET never consumes |
| CSRF | exchange exact route works without prior CSRF; every later mutation/logout rejects missing/mismatch token; unrelated CSRF exemptions unchanged |
| Actor | participant/admin/branch/psychologist/super-admin login alone denied; auth cookie cannot choose scope; checkout principal cannot access their guards |
| IDOR/tenant | attempt/participant/org/package IDs in URL/body ignored/rejected; cross-tenant and stale principal generic; no fallback default branch |
| Hydration | client/source/org/package disabled/effective-window, attempt revoked/status changed, participant deleted, handoff not latest/exact all invalidate session |
| Expiry/logout | idle and absolute boundaries use DB clock; logout removes only checkout namespace and rotates ID/CSRF; expired session cannot mutate |
| Replay/concurrency | same bearer two HTTP requests at most one principal; multi-tab shares one principal; second handoff replaces not appends; response race fail closed |
| Crash | injected regenerate/write/response failure leaves handoff CONSUMED, no principal/right, retry bearer invalid; recovery generation invalidates stale session |
| Privacy | only own profile/amount/allocation state; no batch member/count/total, bill/gateway ref, invoice URL/proof, external identity, credential, clinical/DASS leak |
| Side effects | exchange/hydration changes no charge/bill/item/entitlement/order/outbox/consent/identity/assessment session/invitation |
| Headers | success, redirect, validation, auth reject, CSRF 419, throttle, 404, and 500 all no-store/private/no-referrer/frame denied/nosniff/CSP |

SQLite feature tests may verify HTTP/session/cookie/generic output and rollback
injection. PostgreSQL disposable dua proses wajib membuktikan consume-versus-
exchange/recovery lock ordering, same bearer single winner, latest-generation
hydration, revocation wait, dan runtime non-owner/NOBYPASSRLS. Browser harness
test-only memakai source page sintetis yang POST body, memeriksa history/address
bar/cookie flags/refresh/back/multi-tab/mobile, serta network/console tanpa token
atau PII. Harness tidak mendaftarkan route produksi dan tidak memakai data nyata.

## Rollback

P14a0 hanya dokumen. Implementasi Laravel-session kelak dapat dirollback dengan
menghapus route/gate dan namespace principal; tidak ada schema/data migration.
Session lama menjadi inert karena middleware/route tidak lagi membacanya dan
akan habis menurut lifecycle session existing. Tidak boleh melakukan mass delete
session admin/participant sebagai rollback.
