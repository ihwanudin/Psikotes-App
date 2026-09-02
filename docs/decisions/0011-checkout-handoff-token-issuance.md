# ADR-011: Penerbitan token handoff checkout terbatas

## Status

Accepted untuk implementasi lokal bertahap P13a schema/model/action setelah
review hardening root. Keputusan ini belum menjadi kontrak endpoint dan tidak
mengizinkan consume/session, route/controller, aktivasi checkout publik,
migration database aktif, atau P13b/P14.

## Date

2026-09-02

## Context

Checkout-v2 sudah mempunyai provisioning service-to-service yang memetakan
`IntegrationClient`, `IntegrationSource`, organisasi, participant, paket, dan
attempt secara persisted. Handoff berikutnya harus membawa browser peserta ke
checkout milik attempt itu tanpa menjadikan external candidate ID, email,
telepon, tenant ID, atau claim browser sebagai authority.

Token assessment-start P8b mempunyai purpose lain dan tidak boleh dipakai untuk
checkout. Invitation legacy juga tidak menjadi dasar P13: ia memakai lifecycle
assessment READY/IN_PROGRESS, digest HMAC dengan `APP_KEY`, dan URL fragment.
P13 membutuhkan token opaque untuk attempt PROVISIONED, raw token sekali saja,
tidak berada di URL, serta consume terpisah pada P13b.

Increment P13a hanya menerbitkan handoff internal. Ia tidak membuat session,
charge, bill, entitlement, order, outbox, consent, identity verification, route,
controller, browser page, atau hak assessment.

## Threat model

Yang dilindungi adalah kemampuan bearer jangka pendek untuk memulai pertukaran
session checkout bagi satu attempt. Ancaman utama:

- pencurian token dari URL, access log, exception, audit, database, atau telemetry;
- trusted client A menerbitkan token untuk tenant/source/attempt client B;
- participant, BranchAdmin, Staff, Psychologist, SuperAdmin, atau guest memanggil
  issuer internal tanpa autentikasi integration client;
- purpose assessment-start atau destination lain ditukar dengan checkout;
- source/client dinonaktifkan, attempt dicabut/dihapus, atau binding persisted
  berubah setelah request dibentuk;
- retry/reissue paralel menghasilkan dua token aktif atau side effect billing;
- database dump dipakai untuk menebak bearer token aktif;
- token expired/revoked/consumed dipakai ulang atau token lama menang setelah
  reissue.

Compromise penuh pada proses aplikasi saat token raw berada di memory berada di
luar kemampuan database constraint, tetapi token tetap tidak boleh dilog. TLS,
host hardening, dan secret integration client tetap kontrol transport terpisah.

## Actor, action, and resource matrix

| Actor/caller | Issue P13a | Reissue P13a | Consume P13b | Resource authority |
| --- | --- | --- | --- | --- |
| Trusted integration client checkout-v2, HMAC valid, persisted active | Allow internal | Allow internal | Tidak melalui issuer; browser membawa token pada P13b | Client/source/organization dan attempt harus direload server-side dan cocok |
| Participant terautentikasi | Deny | Deny | Kelak hanya bearer exchange, bukan participant login sebagai authority | Tidak boleh memilih tenant/attempt untuk issuer |
| BranchAdmin/Staff | Deny | Deny | Deny | Kepemilikan cabang tidak memberi hak menerbitkan token |
| Psychologist/SuperAdmin | Deny pada boundary ini | Deny | Deny | Tidak ada admin bypass; operasi dukungan memerlukan ADR/action lain |
| Guest/browser tanpa bearer | Deny | Deny | Kelak generic invalid | Tidak ada fallback registrasi/default branch |
| Internal service tanpa authenticated client object exact | Deny | Deny | Deny | Role RLS service saja tidak cukup menjadi caller authority |

`AuthenticateIntegrationClient` existing tetap calon autentikasi adapter masa
depan. Action P13a juga harus memeriksa object client authenticated yang
persisted, bukan sekadar header ID atau ambient service RLS context. Tidak ada
route produksi pada P13a0/P13a internal.

## Decision

### 1. Authority dan binding

Issuer tidak menerima purpose atau destination dari caller. Surface typed
internal hanya menerima authenticated persisted integration client object,
assessment attempt public ULID, source system selector, opaque idempotency key,
dan enum intent `ISSUE` atau `REISSUE`. Ia bukan array extensible dan tidak
mempunyai parameter untuk organization, participant, package, external identity,
payer, nominal, purpose, atau destination. Nilai caller hanya selector; dalam
transaksi service issuer reload dan lock:

1. organization;
2. integration client;
3. integration source;
4. package dan package items bila validasi policy membutuhkannya;
5. `AssessmentParticipant` sebagai attempt;
6. `Participant` pemilik attempt;
7. seluruh `CheckoutHandoff` untuk attempt+purpose+destination dalam urutan
   `issue_number`, lalu `id`.

Urutan organization → client → source → package → `AssessmentParticipant` →
`Participant` → handoff mengikuti `ProvisionCheckoutParticipant`,
`UpdateFundingPolicy`, dan lock policy parent pada `ReserveAssessmentBill`.
Action administratif yang menonaktifkan/menghapus source atau client harus
memakai prefix owner yang sama: organization → client → source. Dengan begitu
issuer tidak membalik lock saat berlomba dengan revocation. Semua query memakai
persisted IDs dan scope yang sama.

Attempt wajib checkout-v2, milik client/source/organization exact, status
`PROVISIONED`, belum revoked, participant belum soft-deleted, serta client/source/
organization/package masih active dan effective. Source yang suspended, retired,
deleted, atau berpindah client menolak issue/reissue. Token tidak membuktikan
payer, settlement, consent, identity, entitlement, atau readiness.

Purpose canonical adalah `checkout-handoff`; destination canonical adalah
`integrated-checkout-session`. Action memasukkan kedua konstanta server itu ke
canonical request hash dan row persisted tanpa membaca nilai caller. Keduanya
bukan Host, Origin, Referer, return URL, atau pilihan browser. Prefix token bukan
bukti purpose; P13b harus mencocokkan row persisted purpose/destination/source
lagi.

### 2. Format token dan digest durable

Raw token memakai format `och1_` diikuti 64 karakter hex lowercase dari
`random_bytes(32)`. Entropy payload tepat 256 bit. Prefix hanya version/format
identifier. Generator memakai CSPRNG PHP yang dipelihara; tidak ada cipher,
PRNG, atau encoding kriptografi buatan sendiri.

Database hanya menyimpan `hash('sha256', raw_token)` sebagai 64 hex lowercase.
Raw token diberi atribut sensitive pada DTO/parameter yang relevan, hanya
dikembalikan sekali setelah transaksi commit, dan tidak disimpan di model,
cache, session, audit, exception, log, URL, query, fragment, metric, trace, atau
outbox. Perbandingan digest pada consume memakai `hash_equals` setelah lookup
bounded/unik.

#### Alternatif digest A — SHA-256 atas token acak 256-bit (dipilih)

Dump database tidak memungkinkan brute force realistis atas ruang 256-bit.
Tidak ada secret digest yang harus dirotasi, tidak ada ketergantungan pada
`APP_KEY`, dan restore database tetap dapat memvalidasi token yang belum expired.
Kekuatan berasal dari entropy bearer token, bukan kerahasiaan algoritma hash.

#### Alternatif digest B — HMAC-SHA-256 dengan pepper versioned

HMAC memberi defense tambahan bila entropy token ternyata lemah, tetapi
membutuhkan secret khusus, `digest_key_id`, daftar current/previous key, dan
prosedur rotasi. Mengganti `APP_KEY` langsung akan memutus token aktif; menyimpan
pepper bersama database menghilangkan manfaatnya. Untuk TTL maksimum 600 detik
dan entropy 256-bit, kompleksitas serta risiko rotasi tidak memberi manfaat
material. Jika kebijakan kemudian mewajibkan pepper, migration additive harus
menambah `digest_key_id`; key lama dipertahankan setidaknya TTL+clock skew dan
tidak boleh memakai `APP_KEY` secara implisit.

Hash password adaptif ditolak karena token berentropy tinggi, lookup harus exact,
dan biaya adaptif tidak menambah perlindungan yang relevan.

### 3. TTL dan transport

Config proposed:

```php
'checkout_handoff' => [
    'enabled' => false,
    'ttl_seconds' => 600,
],
```

Action fail closed kecuali `enabled === true` dan TTL integer 60..600. Default
tetap OFF; P13a implementation review tidak memberi izin menyalakannya. Database
clock menetapkan `issued_at` dan `expires_at`, dengan PostgreSQL CHECK bahwa
expiry lebih besar dari issue time dan tidak lebih dari 10 menit.

Adapter masa depan hanya boleh mengembalikan raw token dalam response body HTTPS
authenticated dengan `Cache-Control: no-store, private`. Untuk browser
acceptance, trusted selection site meneruskan token melalui POST body ke consume
P13b; token tidak berada pada path, query, fragment, redirect Location, atau
Referer. Consume sukses kelak membuat session cookie scoped dan redirect tokenless.
Origin/Host tidak menjadi authority. Detail route, CSRF/session, throttling, dan
response generik tetap P13b/P14 dan belum didaftarkan.

### 4. Idempotency, replay, dan reissue

Setiap issue request membawa opaque idempotency key dalam format tepat
`ih1_` + 32 hex lowercase (`/^ih1_[0-9a-f]{32}$/D`, panjang 36). Boundary
menolak string kosong, oversize, email, nomor telepon, nama, whitespace, separator
lain, dan nilai non-string sebelum transaksi. Format ini meminta nonce 128-bit
dan tidak menyediakan tempat bebas untuk PII atau secret.

Raw idempotency key hanya hidup di parameter sensitive selama request. Database
menyimpan `issue_idempotency_key_digest = hash('sha256', raw_key)`; raw key tidak
masuk model, audit, log, serialization, exception, request hash, atau response.
Digest ini hanya kunci replay dan berbeda dari `token_digest`; mengetahui digest
idempotency tidak memberi bearer authority. Canonical request hash terpisah
mencakup client ID persisted, attempt ID, source ID, fixed purpose, fixed
destination, dan intent. Unique `(integration_client_id,
issue_idempotency_key_digest)` melindungi retry concurrent.

- Key baru dan payload sah: buat satu row ISSUED, return raw token sekali.
- Key sama dan request hash exact: no-op, tidak mencabut/membuat row, tidak
  menulis audit kedua, dan mengembalikan descriptor explicit
  `{replayed: true, rawToken: null, reissueRequired: true, handoffPublicId,
  issueNumber}`. Raw token tidak dapat direkonstruksi dari digest dan caller
  tidak boleh menganggap descriptor replay memuat credential.
- Key sama dengan payload/binding berbeda: conflict tanpa mutasi.
- Kehilangan response setelah commit: caller memakai reissue eksplisit dengan
  idempotency key baru. Reissue mengunci attempt dan token aktif, menutup token
  lama, lalu membuat token baru dalam transaksi yang sama.
- Dua reissue berbeda terserialisasi pada organization/attempt. Setiap action
  hanya membentuk response setelah transaksinya commit dan tidak mengembalikan
  token yang sudah direvoke oleh transaksi itu sendiri. Jika reissue kedua commit
  sesudah reissue pertama, token pertama segera tidak valid; hanya token dari
  commit terakhir yang active. Kontrak tidak menjanjikan kedua caller memperoleh
  token usable, karena reissue baru memang mencabut generasi sebelumnya. Consume
  token kalah harus gagal generik dan caller dapat meminta reissue baru. Unique
  active marker menjadi backstop.

Reissue token aktif mengubah token lama menjadi REVOKED dengan reason
`REISSUED`. Bila token lama sudah lewat expiry tetapi belum diamati, ia menjadi
EXPIRED. Reissue tidak membuat atau mengubah participant, attempt, charge, bill,
bill item, entitlement, order, outbox, consent, identity, payment, atau session.

### 5. State machine P13a/P13b

```text
                consume exact, unexpired (P13b)
ISSUED ----------------------------------------------> CONSUMED
   |                                                       terminal
   | reissue before expiry
   +-------------------------> REVOKED (REISSUED)          terminal
   |
   | observed expiry / reissue after expiry
   +-------------------------> EXPIRED                     terminal
   |
   | attempt/source/client revocation observed (P13b/future cleanup)
   +-------------------------> REVOKED (bounded reason)    terminal
```

Only ISSUED has `active_marker=true`. Terminal rows use NULL. CONSUMED has only
`consumed_at`; REVOKED has only `revoked_at` and reason; EXPIRED has only
`expired_at`. Timestamps use database UTC instants. Terminal rows cannot return
to ISSUED. Duplicate consume and stale raw tokens return one generic invalid/
expired outcome without revealing whether digest, tenant, source, or attempt
failed. P13a does not implement any consume transition.

### 6. Proposed schema

New table `checkout_handoffs`:

| Column | Proposed type / invariant |
| --- | --- |
| `id` | bigint primary key |
| `public_id` | ULID unique; safe correlation ID, never bearer |
| `assessment_participant_id`, `organization_id`, `participant_id`, `package_id` | bigint; composite FK to existing `assessment_attempt_billing_scope_unique`, cascade when attempt is deleted |
| `integration_client_id` | bigint FK integration_clients, restrict delete |
| `integration_source_id` | bigint FK integration_sources, restrict delete |
| `source_system` | varchar(100), persisted binding validated against locked source/attempt |
| `contract_version` | varchar(24), CHECK exact `checkout-v2` |
| `purpose` | varchar(40), CHECK exact `checkout-handoff` |
| `destination` | varchar(64), CHECK exact `integrated-checkout-session` |
| `token_digest` | char(64) unique, lowercase hex, hidden on model |
| `active_marker` | nullable boolean; true only for ISSUED |
| `status` | varchar(16): ISSUED, CONSUMED, REVOKED, EXPIRED |
| `issue_number` | unsigned integer, >=1 |
| `issue_idempotency_key_digest` | char(64), SHA-256 lowercase hex; raw key tidak disimpan |
| `request_hash` | char(64), lowercase hex |
| `revocation_reason` | nullable varchar(32), bounded enum |
| `issued_at`, `expires_at` | timestampTz non-null, database time, TTL CHECK |
| `consumed_at`, `revoked_at`, `expired_at` | nullable timestampTz with exact state pairing |
| timestamps | timestampTz; `updated_at` is not authority for expiry |

Constraints and indexes:

- composite FK attempt scope preserves attempt/organization/participant/package;
- FK source/client are restrict-on-delete; attempt cascade invalidates handoff
  atomically dan mencegah orphan bearer state. Penghapusan/privacy erasure parent
  sengaja mengalahkan retensi terminal handoff;
- unique token digest;
- unique `(integration_client_id, issue_idempotency_key_digest)`;
- unique `(assessment_participant_id, purpose, destination, issue_number)`;
- unique `(assessment_participant_id, purpose, destination, active_marker)` so
  nullable terminal rows coexist but only one active row exists on PostgreSQL
  and SQLite;
- CHECK exact lifecycle/timestamp pairing, digest/hash format, active marker,
  positive issue number, nonblank key, issued/expiry ordering, and fixed contract;
- index `(organization_id, status, expires_at, id)` for bounded future cleanup;
- index `(integration_source_id, status, expires_at)` for source revocation audit.

The migration is additive and does not edit historical migrations. Up on a
populated database creates an empty table and policies; it does not backfill
invitations or attempts. Down performs a preflight count and refuses before any
DDL if handoff rows exist. It never silently drops token history or partially
drops indexes/policies. Applying to an active database remains a separate
authorized operation.

### 7. RLS and transaction boundary

PostgreSQL enables and forces RLS. The only table policy is ALL for
`psikotes_runtime` when `app_private.app_role() = 'service'`. No participant,
branch, staff, psychologist, super-admin, or guest SELECT policy exists. Issuer
rejects ambient non-service context and owns one short transaction; future HTTP
middleware authentication alone does not bypass action validation.

Tests must use the runtime non-owner/NOBYPASSRLS role. Owner/superuser results do
not prove isolation. SQLite verifies portable columns, FK/unique behavior, casts,
and application lifecycle only; it is not evidence for CHECK, FORCE RLS, lock,
or concurrency behavior.

### 8. Audit, failure, rollback, and retention

Successful first issue writes one `checkout_handoff.issued` audit; reissue writes
one `checkout_handoff.reissued` audit in the same transaction. Exact replay does
not write another audit. Safe context is version, public handoff ID, issue number,
purpose, destination, source system, issued/expires timestamps, and whether an
older row was revoked. Subject is the assessment attempt internal ID and branch
scope. Raw token, digest, idempotency key, request hash, external identity, name,
email, phone, bill/invoice, IP, credential reference, and secret are absent.

Any validation/audit/insert failure rolls back old-token revocation and new row.
An injected failure after old token update must leave the old token active. Raw
token generated for a rolled-back transaction is discarded and never returned.

Terminal rows are proposed for retention up to 730 days for security/audit
correlation hanya selama parent `AssessmentParticipant` dan graph scope tetap
ada. Cascade pada penghapusan attempt adalah revocation hard boundary: semua
handoff ikut hilang agar bearer tidak orphan, dan privacy deletion dapat
mengalahkan target retensi 730 hari. Audit aman yang tidak menyimpan bearer tetap
mengikuti retensi audit terpisah. Tidak ada purge command, scheduler, route, atau
retention worker yang diizinkan di sini. Future cleanup harus bounded,
default-off, service-only, skip active rows, preserve audit retention, dan memakai
expiry index.

## RED test matrix for P13a implementation

| Area | RED cases required before GREEN |
| --- | --- |
| Actor | trusted HMAC client allowed internally; participant, BranchAdmin, Staff, Psychologist, SuperAdmin, guest, service-without-client denied |
| Scope | cross-tenant attempt, foreign client/source, mismatched source system, deleted participant/attempt/source, disabled/effective-window client/source/org/package denied |
| Binding | signature/reflection contract membuktikan action tidak mempunyai parameter purpose/destination; adapter menolak unknown fields; fixed constants masuk hash/row; assessment-start token shape, checkout-v1/metadata missing, Host/Origin manipulation denied |
| Token | format/entropy contract, digest only persisted, raw returned once, no raw/digest in audit/log/exception/URL/model serialization |
| Idempotency | hanya `ih1_` + 32 lowercase hex diterima; empty/oversize/email/phone/name/whitespace/unknown format ditolak; raw key tidak durable/audit/log/serialization/error; digest idempotency tidak dapat dipakai sebagai bearer |
| TTL | config OFF, non-integer/out-of-range, DB clock, boundary 60/600 seconds, expired token lifecycle |
| Replay | exact key/hash menghasilkan descriptor replayed=true/rawToken=null/reissueRequired=true tanpa mutasi; same key/different input conflict; new explicit reissue revokes old dan emits one raw token |
| State | revoked/void/terminal attempt, consumed/revoked/expired token, missing/corrupt active row, invalid timestamps/status pairing fail closed |
| Side effects | participant/attempt unchanged; zero charge/bill/item/entitlement/order/outbox/session/consent/identity changes |
| Rollback | failure after revocation and before insert/audit restores old active token; failure after insert rolls all back |
| Concurrency | two exact issues create one row; two distinct reissues leave one active dan hanya token commit terakhir dapat consume; loser token generic invalid; issue racing source/client/attempt revoke mengikuti owner lock order tanpa deadlock |
| RLS | runtime no-context and all non-service roles denied; service can operate only through validated action; cross-tenant direct SQL denied |
| Migration | populated up preserves existing data; PG negative CHECK/FK/unique/index/policy definitions; down/up empty roundtrip; down with rows refuses before mutation |

PostgreSQL disposable two-process tests require explicit barriers around attempt
lock and unique insert. Sequential SQLite tests must not be described as race or
RLS proof.

## Migration verification plan

1. SQLite memory with `phpunit.organization-payment.xml`: prior migrations,
   populated integration/attempt graph, new table up, model casts/hidden fields,
   unique/FK semantics, empty down/up, and refusal down with rows.
2. PostgreSQL disposable runner: inspect constraints/index definitions, FORCE RLS,
   runtime role NOBYPASSRLS, direct SQL negative cases, database timestamps, and
   two-process issue/reissue/revoke races.
3. Run focused issue tests, integration contract regression, Pint, PHPStan, and
   `git diff --check`. No active database migration or outbound request.

## Alternatives considered

### Reuse assessment invitation

Rejected. Its actor, lifecycle, purpose, URL transport, `APP_KEY` HMAC, and
participant JWT outcome differ. Reuse would permit purpose confusion and could
make READY assessment invitation a checkout authority.

### Stateless encrypted/signed token

Rejected for P13. Atomic reissue, one active token, consumption, per-token
revocation, replay, and concurrency require durable state. Key rotation alone is
not individual revocation, and embedded claims risk stale tenant/source policy.

### Store encrypted raw token to support replay

Rejected. It violates durable digest-only storage and expands impact of database
plus application-key compromise. Explicit reissue is the recovery path.

### Put token in query or URL fragment

Rejected. Query leaks through logs/history/referrers; fragment still persists in
browser history/client telemetry and violates the accepted no-URL contract.

## Compatibility and boundaries

Checkout-v1, provisioning P9, billing P7–P12, assessment invitations, participant
JWT, assessment-start token, legacy registration, payer decisions, provider,
notifier, and gate remain unchanged. P13a issue does not consume a token or
create a checkout session. P13b owns consume; P14 owns private session/summary;
P15 owns profile/consent; P16 owns browser page. Public route/controller/browser,
cleanup, and operational activation require separate review.

## Review questions and blockers

The implementation must not start until reviewers explicitly accept:

1. exact replay returns no raw token and response loss requires explicit reissue;
2. SHA-256 of a 256-bit random bearer token, without pepper/key-id, is the durable
   digest choice;
3. fixed purpose/destination strings and POST-body transport for future consume;
4. TTL range 60..600 seconds, default 600, feature default OFF;
5. attempt-delete cascade sebagai revocation/privacy boundary, source/client
   restrict, terminal retention maksimal 730 hari hanya selama parent ada, dan
   down-migration refusal ketika rows ada;
6. no admin/participant issuer authority and no fallback when source/client or
   attempt is revoked/deleted.

Until those decisions are accepted, P13a remains blocked at proposal status.

## Consequences

The design provides atomic single-active issuance without storing bearer tokens
or coupling handoff to billing. It adds a durable table and PostgreSQL-specific
security constraints that require separate migration review and disposable PG
evidence. Response-loss recovery is an explicit reissue rather than transparent
replay. No endpoint or user-visible behavior exists from accepting this ADR alone.
