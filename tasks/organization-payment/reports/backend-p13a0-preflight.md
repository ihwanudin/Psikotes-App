# P13a0 backend preflight — checkout handoff issuance

Tanggal: 2026-09-02
Status: proposal untuk review; belum accepted dan belum ada implementasi.

## Scope yang diaudit

Preflight membaca root terbaru: plan/todo/parallel-work, SPEC organization
billing, ADR-004/005/010, composer Laravel 13/PHP 8.3, integration registry,
payer policy, checkout-v2 request/adapter/provisioner, assessment participant,
participant, billing schema/RLS, lock order P7/P8, assessment-start token,
integration HMAC middleware, serta invitation issue/consume legacy.

Tidak ada source produksi, migration, model, action, test, config, route,
controller, session, provider, notifier, atau database yang diubah/dijalankan.

## Temuan preflight

1. `AuthenticateIntegrationClient` sudah menyediakan HMAC SHA-256, persisted
   client reload, effective window, secret reference, timestamp tolerance, dan
   rate limit. Itu calon caller authentication; role RLS service sendiri tidak
   cukup menjadi authority issuer.
2. `ProvisionCheckoutParticipant` sudah menetapkan lock organization → client →
   source → package → attempt/participant dan checkout-v2 marker server-side.
   P13 harus memakai binding itu, bukan external identity/email/phone.
3. `AssessmentAccessToken` purpose `assessment-start` adalah token terenkripsi
   berbeda. Ia tidak dapat mengotorisasi checkout.
4. Invitation legacy memakai HMAC digest dengan `APP_KEY`, token URL fragment,
   dan lifecycle READY/IN_PROGRESS. Reuse ditolak karena purpose, transport,
   actor, dan hasil consume berbeda.
5. Exact replay dan raw-once adalah trade-off nyata: digest-only tidak dapat
   mengembalikan bearer yang sama setelah response hilang. ADR-011 memilih
   no-op replay tanpa raw token dan explicit atomic reissue dengan key baru.
6. PostgreSQL runtime memakai FORCE RLS/service policy. SQLite hanya cocok untuk
   portable unit harness dan tidak dapat menjadi bukti RLS/lock/concurrency.

## Proposal

ADR-011 menetapkan:

- issuer internal hanya untuk authenticated trusted integration client;
- tenant/resource/source/purpose/destination seluruhnya direload dan dibandingkan
  persisted, dengan fixed purpose `checkout-handoff` dan destination
  `integrated-checkout-session`;
- raw token `och1_` + 64 hex dari `random_bytes(32)`, entropy 256 bit;
- durable SHA-256 digest saja; alternatif HMAC/pepper beserta rotasi/key-id
  dibandingkan dan tidak dipilih;
- surface action tidak menerima purpose/destination; dua nilai itu konstanta
  server yang masuk canonical hash dan row persisted;
- idempotency key wajib opaque `ih1_` + 32 hex, raw tidak durable, dan hanya
  digest SHA-256 terpisah yang disimpan untuk replay;
- TTL 60..600 detik, default 600, feature default-OFF, database clock;
- exact replay no-op tanpa raw token; explicit reissue atomik mencabut active
  lama dan tidak menyentuh billing/access/session;
- lifecycle ISSUED/CONSUMED/REVOKED/EXPIRED yang siap dipakai P13b;
- schema/FK/unique/CHECK/index, FORCE RLS service-only, populated migration
  up/down refusal, audit aman, rollback, retention belum aktif;
- POST-body future consume transport tanpa token di URL/query/fragment/log;
- RED matrix SQLite + PostgreSQL disposable termasuk two-process race.

## Keputusan yang masih memerlukan acceptance

1. Replay exact mengembalikan descriptor explicit `replayed=true`, raw token
   null, dan `reissueRequired=true`; response loss memakai reissue.
2. SHA-256 atas bearer 256-bit dipilih tanpa pepper/key-id.
3. Purpose/destination fixed dan future consume memakai POST body.
4. TTL maksimum/default 600 detik serta feature default OFF.
5. Attempt delete cascade membatalkan bearer dan privacy deletion mengalahkan
   retention; source/client restrict, down refusal bila berisi data, dan retention
   terminal maksimal 730 hari hanya selama parent graph masih ada.
6. Tidak ada admin/participant issuer bypass; source/client/attempt deleted atau
   revoked selalu fail closed.

Tidak ada blocker teknis lain yang membenarkan implementasi sebelum keputusan
di atas direview. P13b consume, P14 session, route/controller, browser, cleanup,
dan activation tetap increment terpisah.

## Patch status kanonik

File plan/todo/parallel root lebih baru daripada snapshot worker, sedangkan
`parallel-work.md` masih untracked sejak baseline worker. Untuk menghindari
commit ulang seluruh overlay, perubahan status P13a0 diterapkan lokal tetapi
tidak dimasukkan commit lane. Koordinator perlu menerapkan delta kecil berikut
pada root canonical:

- plan: tambah bagian `P13a0 — preflight handoff checkout (proposal)` yang
  menunjuk ADR-011 dan menyatakan migration/action/P13b belum diizinkan;
- todo P13a: tambah dua checkbox unchecked untuk acceptance ADR dan larangan
  implementasi/wiring selama status proposal;
- parallel-work: tambah lane P13a0 proposal-only dan kewajiban review sebelum
  increment implementasi.

Hash SHA-256 root saat preflight sebelum delta:

- `plan.md`: `858E08C9D214634F96DDBDE991D73E80B7C80FFB317AF0C9EE1B7F5F757FA1E6`
- `todo.md`: `09684584D96510BBA97F96026C8409BA82D8BAA45A90B39825FE3A9F954F7F72`
- `parallel-work.md`: `CE28493A2026B655BAEAFC536491CE4F742C3752F479D837BE4E7A916A67BA77`

## Verification aktual

- Dokumen saja: tidak menjalankan PHPUnit atau PostgreSQL karena belum ada code,
  schema, RLS, transaksi, atau test change.
- Markdown/link/path review, `git diff --check`, staged-path audit, dan secret
  scan dijalankan sebelum commit.
- Tidak ada `.env`, credential, raw token, data peserta, DB aktif, outbound,
  migration execution, deploy, atau push.

**STOP untuk review ADR-011 sebelum P13a schema/action.**

## Koreksi review root

Koreksi docs-only berikut diterapkan setelah review awal:

- purpose/destination dihapus dari caller input dan hanya berasal dari konstanta;
- raw idempotency key diganti digest durable terpisah dengan format input ketat;
- attempt cascade direkonsiliasi dengan retensi parent-bound;
- replay descriptor dan hasil race dua reissue dibuat eksplisit: hanya generasi
  commit terakhir valid;
- lock naming/order memakai `AssessmentParticipant`, `Participant`, lalu handoff
  terurut, dengan prefix owner sama untuk source/client revocation.

Tidak ada keputusan teknis lain yang diubah dan belum ada izin implementasi.
