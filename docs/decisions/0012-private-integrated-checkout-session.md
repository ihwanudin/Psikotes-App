# ADR-012: Sesi privat checkout terintegrasi

## Status

Accepted untuk implementasi lokal bertahap setelah koreksi alur cookie
lintas-site. Acceptance mengizinkan schema/model durable dan pengujian
disposable lebih dahulu; belum mengizinkan config, route, middleware, perubahan
CSRF/session global, source aktif, migration database aktif, atau wiring publik
P14/P15/P16.

## Date

2026-09-02

## Context

P13b menukar bearer handoff `och1_` tepat sekali menjadi
`CheckoutSessionScope` internal setelah memvalidasi graph persisted. Hasil itu
belum merupakan cookie/session, hak assessment, bukti pembayaran, consent, atau
identity verification.

Dua source browser nyata, `seleksi.beasiswajepang.id` dan
`seleksi.serbaindo.com`, berbeda site dengan `oncam.id`. Mereka harus membawa
bearer melalui top-level HTTPS POST body karena token dilarang pada URL. Cookie
global ONCAM memakai `SameSite=Lax`; browser tidak mengirim cookie itu pada
top-level cross-site POST. Jika exchange memakai global Laravel session, request
akan memperoleh session ID baru lalu response dapat menulis cookie global dengan
nama sama. Cookie baru dapat mengganti pointer session participant/admin yang
sudah ada walau exchange tidak pernah melihat session lama. Karena itu klaim
bahwa regeneration dapat mempertahankan data session lain pada alur ini salah.

P14 juga harus membatasi principal ke satu attempt/participant, memeriksa
revocation setiap request, dan tidak membocorkan anggota, jumlah, total, invoice,
proof organisasi, credential, atau data klinis. P16-prep tetap kontrak presentasi,
bukan response HTTP atau authority.

## Threat model

Ancaman utama:

- bearer masuk path, query, fragment, `Location`, `Referer`, log, exception,
  audit, telemetry, cookie, atau durable session payload;
- cross-site exchange mengganti cookie auth ONCAM existing atau memberi auth
  participant/admin sebagai bypass;
- session fixation melalui selector checkout yang disediakan attacker;
- replay/two-process exchange menghasilkan dua principal aktif;
- IDOR mengganti tenant, attempt, participant, package, payer, atau amount;
- principal lama bertahan setelah graph/handoff direvoke atau generation baru;
- cookie otomatis dipakai untuk CSRF pada mutation checkout;
- cache/error/history membocorkan PII, billing batch, invoice, atau clinical data;
- consume/session commit berhasil tetapi browser tidak menerima cookie;
- mutation config session global pada worker long-lived/Octane bocor ke request
  concurrent.

## Actor, action, and resource matrix

| Actor/caller | Exchange bearer | Hydrate | Mutation kelak | Authority |
| --- | --- | --- | --- | --- |
| Anonymous browser + bearer exact dalam POST body | Allow sekali | Setelah cookie checkout tersimpan | Hanya P15 + CSRF checkout | Bearer memilih handoff; database tetap authority |
| Anonymous tanpa bearer, malformed/replay/expired/revoked/foreign | Deny generik | Deny | Deny | Tidak ada fallback registrasi/default branch |
| Checkout-session principal exact | Tidak perlu bearer | Own attempt/participant saja | Scope sama saja | Selector adalah credential; graph direload |
| Participant login/JWT tanpa checkout cookie | Deny | Deny | Deny | Login bukan authority checkout-v2 |
| BranchAdmin/Staff/Psychologist/SuperAdmin | Deny | Deny | Deny | Tidak ada role/admin bypass |
| Guest/referral/invitation/assessment-start token | Deny | Deny | Deny | Purpose lain tidak dapat ditukar |
| Integration client HMAC tanpa browser bearer | Deny pada P14 | Deny | Deny | Client hanya issue/recovery lewat P13 |

Global auth cookie boleh hadir di browser, tetapi tidak dikirim pada cross-site
POST Lax dan tidak dibaca/ditulis oleh exchange. Pada request same-site berikutnya
cookie auth mungkin ikut bersama cookie checkout; middleware checkout tetap
mengabaikan seluruh guard auth sebagai authority.

## Decision

### 1. Durable checkout-session record dan cookie khusus

P14 direkomendasikan memakai record `checkout_sessions` service-only dan selector
opaque terpisah. Cookie:

```text
name:     __Secure-oncam_checkout_session
value:    ocs1_ + 64 lowercase hex dari random_bytes(32)
Domain:   omitted (host-only)
Path:     /checkout
Secure:   true
HttpOnly: true
SameSite: Lax
```

Nama berbeda mencegah overwrite cookie Laravel auth. Path `/checkout` mencakup
exchange, page, mutation, dan logout, tetapi tidak dikirim ke admin, participant
API, dashboard, atau route lain. Domain tidak boleh menerima input/source domain.
SameSite tidak diubah menjadi `None`; selector tidak dikirim pada initial
cross-site POST, kemudian ditetapkan oleh response ONCAM dan dikirim pada navigasi
same-site berikutnya. Browser behavior ini wajib dibuktikan, bukan diasumsikan.

Database hanya menyimpan SHA-256 digest selector. Raw selector hanya hidup saat
Set-Cookie/browser request, ditandai sensitive pada input internal, tidak dapat
diserialisasi, dan tidak masuk model/log/audit/exception/metric. Format memakai
primitive `random_bytes` dan `hash` yang sama dengan P13; tidak ada crypto DIY,
APP_KEY coupling, atau dependency baru.

Record proposed minimum:

| Field | Invariant |
| --- | --- |
| `public_id` | ULID safe correlation, bukan credential |
| `selector_digest` | unique SHA-256 lowercase hex, hidden |
| `checkout_handoff_id` | unique, exact CONSUMED handoff |
| attempt/client/org/participant/package/source IDs | composite persisted scope |
| `csrf_digest` | SHA-256 secret CSRF, hidden |
| `status`, `active_marker` | ACTIVE atau terminal REVOKED/EXPIRED; satu active per attempt |
| `established_at`, `last_seen_at` | database clock |
| `idle_expires_at`, `absolute_expires_at` | exact bounded expiry |
| `revoked_at`, `expired_at`, `revocation_reason` | state-paired terminal fields |

Migration additive harus menambah composite FK ke handoff/attempt graph, exact
lifecycle CHECK, unique active marker, discovery/expiry index, FORCE RLS service-
only, populated down preflight, dan rollback tanpa menghapus history diam-diam.
Schema dan migration memerlukan ADR implementation review terpisah; tidak dibuat
pada P14a0.

Proposed defaults, belum merupakan product requirement final:

```text
enabled=false
idle_minutes=30
absolute_minutes=120
terminal_retention_days=30
```

Acceptance harus memvalidasi angka berdasarkan UX/security/load sebelum wiring.
Config harus typed, bounded, default OFF, dan tidak membaca Host/request. Tidak
boleh ada `config()->set('session.*')` pada request, middleware, Octane worker,
atau singleton mutable.

### 2. Kenapa bukan global atau named Laravel session kedua

Global Laravel session ditolak untuk exchange ini karena cookie Lax existing
absen pada cross-site POST dan Set-Cookie bernama sama dapat mengganti pointer
auth. Regeneration tidak dapat memigrasi session yang tidak dikirim browser.

Named Laravel session kedua hanya layak bila framework menyediakan isolated
store, cookie, CSRF, dan lifecycle tanpa mengubah config repository global. Stack
existing tidak mempunyai boundary itu. Membuat middleware yang sementara
mengganti `session.cookie`, `session.path`, driver, atau store adalah request-
unsafe pada Octane/concurrent worker dan ditolak. Membuat SessionManager/store
custom pada akhirnya memiliki lifecycle dan schema sendiri; durable record
explicit lebih mudah diaudit dan tidak berpura-pura memakai global session.

Flow dua langkah yang terlebih dahulu membuat global ONCAM session juga ditolak:
ia tetap dapat menimpa cookie auth atau membutuhkan token transport baru.
SameSite=None global tidak diperlukan dan dilarang.

### 3. Typed boundary

Surface proposed:

```text
CheckoutSessionExchangeInput
  private #[SensitiveParameter] rawHandoffToken: string

EstablishedCheckoutSession
  private #[SensitiveParameter] rawSelector: string
  public safe descriptor: sessionPublicId, handoffPublicId,
    assessmentParticipantId, assessmentAttemptId, organizationId,
    participantId, packageId, integrationClientId, integrationSourceId,
    sourceSystem, establishedAt, idleExpiresAt, absoluteExpiresAt

CheckoutSessionPrincipal
  safe descriptor di atas + lookup generation; tanpa raw selector/digest/CSRF

HydratedCheckoutSession
  principal + authoritative exact attempt/participant scope
```

Input bukan array extensible dan tidak menerima tenant/attempt/participant/
package/payer/amount/return URL/purpose/destination/Host/Origin/Referer. P13b
tetap satu-satunya canonical bearer validator; adapter tidak menyalin predicate.

### 4. Atomicity, fixation, dan crash window

Target implementation membuat transition handoff CONSUMED dan durable session
ACTIVE dalam satu PostgreSQL service transaction dengan lock order canonical:

```text
organization -> client -> source -> package/items -> attempt -> participant
-> handoff history -> checkout session rows
```

Ini memerlukan refactor bounded P13b agar canonical consume dapat dipakai oleh
establisher dalam transaksi yang sama, tanpa membuka callback arbitrary atau
menyalin validation. Refactor/schema harus direview sebelum code.

Incoming `__Secure-oncam_checkout_session` selalu diabaikan pada exchange sebagai
authority/fixation input. Setelah bearer valid, server menghasilkan selector dan
CSRF secret baru. Unique digest, unique handoff, dan one-active-attempt menjadi
backstop. Response hanya menetapkan selector yang dibuat server; attacker tidak
dapat memilih session ID.

Database commit tetap tidak atomik dengan HTTP Set-Cookie/browser delivery. Bila
commit berhasil tetapi response/session cookie gagal:

- handoff tetap CONSUMED dan session record dapat menjadi orphan ACTIVE;
- bearer replay tetap invalid dan token lama tidak dihidupkan;
- tidak ada billing/access/order/outbox side effect;
- trusted source memerlukan recovery generation;
- recovery wajib revoke old durable session dalam lock order yang sama;
- implementasi tidak mengklaim exactly-once delivery lintas DB/browser.

Gap P13 existing tetap blocker: issuer tidak dapat reissue setelah latest
handoff CONSUMED. Increment recovery terpisah harus mengizinkan generation baru
hanya bagi authenticated exact client, revoke session old secara atomik, menulis
audit aman, dan membuat latest-generation hydration menolak selector lama.

### 5. HTTP exchange dan global-session isolation

Route proposed, belum didaftarkan:

```text
POST /checkout/session       bearer exchange
GET  /checkout               hydrate/render
POST /checkout/logout        revoke session checkout
GET  /checkout/unavailable   error tokenless generik
```

Initial exchange berada pada middleware group minimal yang **tidak menjalankan
global `StartSession` dan tidak menulis global session cookie**. Ia menerima hanya
`application/x-www-form-urlencoded`, tepat field `handoffToken`, format/panjang
P13. Query/path/fragment/header/cookie/JSON/multipart/unknown field ditolak.
Origin/Referer bukan authority.

Sukses commit session durable lalu memberi Set-Cookie khusus dan `303` fixed
`/checkout`. Invalid/malformed/replay/expired/revoked/wrong-scope memberi `303`
fixed `/checkout/unavailable` tanpa Set-Cookie checkout baru. Gate OFF memberi
generic 404. Throttle 429 dan framework 500 tetap generik. Tidak ada response
yang menulis cookie global, return URL, token, attempt ID, atau participant ID.

Test HTTP harus menanam cookie auth global sintetis, melakukan cross-site POST
tanpa cookie itu pada request sesuai behavior Lax, lalu membuktikan response tidak
memuat Set-Cookie global dan cookie auth browser tetap byte-identik. Login existing
tidak boleh mengubah outcome exchange atau memberi bypass.

### 6. CSRF khusus checkout

Initial exchange tidak memakai cookie authority dan merupakan satu exact route/
method CSRF exception. Ia sengaja menerima cross-site form POST dengan bearer
unguessable. Exception tidak boleh berupa prefix dan tidak mengubah global CSRF
untuk route lain.

Setiap durable session mempunyai CSRF secret acak berbeda. Database menyimpan
digest; raw secret hanya diproyeksikan ke HTML/form/meta same-origin setelah
hydration dan tidak masuk log/cookie/URL. Semua POST setelah exchange, termasuk
profile, consent, payer/payment intent, refresh yang mutating, dan logout,
membutuhkan pasangan:

1. dedicated selector cookie yang lookup exact active session; dan
2. CSRF body/header secret yang `hash_equals` digest persisted.

CSRF secret dirotasi ketika recovery/session baru. GET tidak pernah mutasi.
Missing/mismatch mengembalikan generic 419 dengan privacy headers. Ini terpisah
dari global Laravel CSRF/session dan tidak memerlukan mutasi config runtime.

### 7. Hydration, expiry, logout, dan multi-tab

Setiap request menggunakan selector digest sebagai bounded routing hint, lalu
service boundary reload/lock organization, client, source, package, attempt,
participant, handoff latest, dan session row. Ia memverifikasi exact scope,
checkout-v2/purpose/destination, CONSUMED latest generation, active/effective
graph, participant belum deleted, attempt belum revoked, DB clock sebelum idle/
absolute expiry, dan selector digest dengan `hash_equals`.

Principal adalah selector, bukan authority untuk ID request. Route/body/query/
Inertia props tidak menerima attempt/participant/org/package selector. Session
expired/revoked menjadi terminal atomik, cookie dibersihkan memakai exact name/
path/domain attributes, dan response generik. Tidak ada fallback auth/referral.

Logout adalah same-origin POST ber-CSRF: lock record, ACTIVE menjadi REVOKED,
clear dedicated cookie, dan tidak membaca/menghapus/regenerate cookie/session
participant/admin global.

Multi-tab pada host yang sama berbagi dedicated cookie dan satu principal.
Exchange baru menimpa hanya dedicated cookie; old record direvoke bila lock scope
memungkinkan, atau menjadi orphan sampai expiry jika cross-site request tidak
mengirim old Lax cookie. Unique active per attempt mencegah dua active session
untuk attempt sama. Sistem tidak menjanjikan satu session global untuk semua
attempt/browser tanpa identifier browser durable yang justru menambah tracking.

Cleanup active expiry dapat terjadi saat hydration. Cleanup terminal background
kelak harus service-only, bounded, SKIP LOCKED, default-off, dan tidak didaftarkan
pada P14. Migration down menolak row nonempty; rollback aplikasi membuat cookie/
record inert dan expiry/cleanup plan harus disetujui sebelum deploy.

### 8. Privacy projection dan headers

Hydration hanya dapat menghasilkan attempt sendiri. P16 props boleh memuat own
profile fields, branch/package label, payer decision, own amount/allocation state,
dan consent requirement dari server. Dilarang memuat bill parent, batch member/
count/total, merchant/gateway reference, invoice URL/proof, charge lain, external
identity, credential, clinical result, DASS data, atau audit internal.

Middleware privacy paling luar membungkus gate, validation, auth/CSRF rejection,
throttle, redirects, 404, dan unexpected 500:

```text
Cache-Control: no-store, private
Pragma: no-cache
Referrer-Policy: no-referrer
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'
```

CSP final mengikuti asset Inertia/Vite nyata tanpa `unsafe-inline` workaround.
APP_DEBUG=false errors tidak memuat token/selector/digest/CSRF/PII/SQL/model/scope.

Proposed limiter, masih perlu acceptance: exchange 10/menit per IP tanpa token
cache key; hydrated reads 60/menit per session public ID+IP; mutation 10/menit per
session public ID+IP. Limiter bukan authority dan tidak menyimpan raw credential.

## Alternatives considered

| Opsi | Cross-site/global cookie | Atomicity | Cleanup/one-session | Risiko |
| --- | --- | --- | --- | --- |
| Global Laravel session existing | **Tidak aman:** dapat overwrite cookie auth Lax yang absent | Consume dan session store berbeda | Global TTL; tidak unique attempt | Logout/auth loss; rejected |
| Dedicated Laravel session via config mutation | Nama/path bisa beda, tetapi global config request-unsafe | Store terpisah dari P13 DB | Perlu custom lifecycle | Octane/concurrency leak; rejected |
| Durable record + dedicated cookie | Tidak menyentuh cookie auth; host-only/path scoped | Consume+record dapat satu PG transaction; cookie delivery tetap terpisah | Explicit expiry/revoke/unique/RLS | Migration/custom CSRF; **selected** |
| Two-step/global first-party bootstrap | Tetap menulis cookie global atau perlu token transport baru | Tidak memperbaiki browser delivery | Bergantung global session | Lebih kompleks; rejected |

## Default-off implementation sequence

1. ADR/migration contract `checkout_sessions` + PostgreSQL RLS/rollback tests.
2. P13 recovery amendment dan refactor canonical consume-with-session transaction.
3. Internal establish/hydrate/revoke actions + typed DTO; no HTTP.
4. Test-only HTTP controller/request/privacy/CSRF middleware; routes synthetic,
   global session middleware absent.
5. Own-attempt summary projection untuk P16 props; no mutation P15.
6. Browser/PG security review, cleanup/deploy plan, lalu public default-off wiring
   pada checkpoint terpisah.

Sebelum langkah keenam, test mendaftarkan route sintetis dengan component nyata,
config in-memory, PostgreSQL/SQLite disposable, dan source loopback sintetis.
Tidak mengedit `routes/web.php`, `bootstrap/app.php`, `config/session.php`, atau
global CSRF/session config.

## RED test matrix

| Area | RED cases before GREEN |
| --- | --- |
| Cross-site cookie | authenticated global ONCAM cookie exists; POST from both real source-site origins sends no Lax auth cookie; response never Set-Cookie global; browser auth cookie/access remains unchanged |
| Auth isolation | participant/admin/branch/psychologist/super-admin login does not authorize or alter checkout; checkout cookie does not authorize their routes |
| Transport | body-only exact form succeeds; URL/query/fragment/header/cookie/JSON/multipart/unknown fields rejected; no token in Location/Referer/log |
| Selector/fixation | incoming dedicated selector ignored on exchange; server generates fresh entropy; only digest durable; chosen/fixed selector cannot attach session |
| Generic response | malformed/unknown/expired/revoked/consumed/wrong scope same redirect; OFF 404, throttle 429, debug-false 500 private |
| Cookie attributes | exact name; host-only; Path=/checkout; Secure/HttpOnly/SameSite=Lax; clear uses identical scope; no global cookie mutation |
| CSRF | exchange exact exception works cross-site; every later POST/logout rejects missing/mismatch; secret bound to session, rotated on recovery; unrelated CSRF unchanged |
| IDOR/tenant | request IDs rejected; foreign/stale scope generic; latest handoff/session/tenant graph reloaded every hydration |
| Expiry/logout | proposed idle/absolute boundaries DB-clock; logout terminalizes record and clears only dedicated cookie; repeated logout generic/no audit spam |
| Replay/concurrency | same bearer two HTTP/processes one record/cookie outcome; unique handoff/active attempt; revoke/recovery races linearizable |
| Crash | DB/session rollback atomic; failure after commit before Set-Cookie leaves orphan active + consumed handoff; replay invalid; recovery revokes orphan; no exactly-once claim |
| Multi-tab | same cookie shares one principal; exchange new attempt overwrites only dedicated cookie; old orphan/revoke behavior explicit |
| Privacy | own amount/allocation only; no batch member/count/total, bill/gateway ref, invoice URL/proof, external identity, credential, clinical/DASS leak |
| Side effects | establish/hydrate changes no charge/bill/item/entitlement/order/outbox/consent/identity/assessment session/invitation |
| Headers | success, redirects, validation, auth, CSRF 419, throttle, 404, 500 all no-store/private/no-referrer/frame deny/nosniff/CSP |
| Octane safety | parallel synthetic requests prove no global config/session cookie mutation or cross-request state bleed |

SQLite feature tests hanya membuktikan portable HTTP/cookie/DTO semantics.
PostgreSQL disposable dua proses wajib untuk composite scope, FORCE RLS, consume+
record atomicity, unique active attempt, recovery/revoke lock order, rollback, dan
runtime non-owner/NOBYPASSRLS. Browser harness wajib memakai source page pada dua
site berbeda atau controlled hostnames, memeriksa cookies via browser protocol,
URL/history/back/refresh/multi-tab, console, dan network tanpa credential/PII.

## Rollback

P14a0 hanya dokumen. Implementasi durable kelak memerlukan route gate OFF lebih
dahulu, expiry/revoke active sessions, clear dedicated cookie, bounded cleanup,
dan populated migration down preflight. Rollback tidak menyentuh global Laravel
session/admin/participant cookies dan tidak menghapus history diam-diam.
