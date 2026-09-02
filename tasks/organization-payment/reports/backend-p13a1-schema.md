# P13a1 backend — checkout handoff schema/model

Tanggal: 2026-09-02

## Hasil

P13a1 menambah schema additive `checkout_handoffs` dan model internal sesuai
ADR-011 accepted. Tidak ada issuer action, DTO, generator token, config, route,
controller, consume, session, billing write, entitlement, outbox, atau activation.

Schema menyimpan public ULID, composite attempt scope, client/source binding,
purpose/destination fixed, bearer digest, idempotency digest terpisah, request
hash, issue number, lifecycle timestamps, dan nullable active marker. Raw bearer
dan raw idempotency key tidak mempunyai kolom. PostgreSQL menegakkan format,
TTL maksimum 600 detik, lifecycle exact, bounded revocation reason, one-active,
unique issue/idempotency/token, FK cascade/restrict, serta service-only FORCE RLS.

Down migration menolak sebelum DDL bila satu row handoff masih ada. Empty
down/up mempertahankan graph existing. Attempt deletion menghapus handoff sebagai
hard revocation/privacy boundary; source dan client deletion tetap restricted.
Model menyembunyikan bearer digest, idempotency digest, dan request hash serta
memakai immutable timestamp casts.

## TDD dan verifikasi aktual

- RED: **5 tes, 6 assertions**, 1 failure karena tabel belum ada dan 4 error
  `App\\Models\\CheckoutHandoff` belum ada.
- Focused/regresi migration SQLite final: **52/52 tes, 294 assertions**. Ini
  mencakup P13a1 plus billing schema, partial profile, invoice lease, dan manual
  proof identity. Focused P13a1 sendiri: **5/5 tes, 32 assertions**.
- PostgreSQL disposable final: **298/298 tes, 2.032 assertions**. Runtime adalah
  `psikotes_runtime`, non-superuser, NOBYPASSRLS; no-context dan semua non-service
  role tidak membaca/menulis, service role diizinkan. Constraint/FK/index/policy,
  negative direct SQL, cascade/restrict, terminal coexistence, one-active,
  populated refusal, dan DDL roundtrip diuji pada PostgreSQL 17.6.
- Runner memakai internal network tanpa published port dan menghapus runner,
  database container, serta network; output final menyatakan cleanup sukses.
- PHP syntax lima file, Pint scoped, dan PHPStan scoped testing/SQLite memory
  lulus; PHPStan final **0 error**. `git diff --check` dan staged-path audit lulus.

Runner PostgreSQL pertama menemukan lima hal dan dipakai sebagai bukti koreksi:
CHECK nullable reason semula dapat lolos SQL UNKNOWN, fixture lintas test belum
rollback, policy roles dibaca sebagai PostgreSQL array string, denial test memakai
empty insert tanpa SQL, dan rollback ancestor billing perlu menurunkan dependent
handoff terlebih dahulu. Semua diperbaiki tanpa melemahkan FK atau mengubah
migration historis. Runner kedua menyisakan satu formula digest fixture lama;
runner ketiga dan final seluruhnya GREEN.

## Delta dan batas

File core P13a1:

- `database/migrations/2026_09_02_000100_create_checkout_handoffs.php`
- `app/Models/CheckoutHandoff.php`
- `tests/Feature/Database/CheckoutHandoffSchemaTest.php`
- `tests/Postgres/CheckoutHandoffSchemaTest.php`

Satu file keenam test-only disetujui koordinator dan dipisahkan commit:
`tests/Postgres/AssessmentBillingMigrationTest.php`. Perubahan hanya memperbaiki
urutan rollback harness descendant → ancestor → ancestor up → descendant up dan
membuktikan struktur/FK/graph pulih. Ia bukan perubahan production schema.

SQLite hanya menjadi bukti portability, model, FK/unique, dan migration harness;
tidak diklaim sebagai bukti CHECK, RLS, atau concurrency. Tidak ada `.env`, data
nyata, migration DB aktif, provider/notifier/outbound, deploy, atau push.

**STOP untuk review P13a1 sebelum IssueCheckoutHandoff/P13a2/P13b/P14.**
