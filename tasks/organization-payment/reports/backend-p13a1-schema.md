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

## Review hardening — composite durable scope dan RLS-safe down

Review root menemukan client/source binding yang sebelumnya masih dapat silang.
Migration sekarang menambah dua parent unique scope bernama dan mengikat child
melalui composite FK: attempt mencakup client/organization/participant/package,
sedangkan source mencakup client/source-system/contract-version. FK attempt tetap
cascade; source/client tetap restrict secara langsung atau transitif. Tes SQLite
dan PostgreSQL menolak client attempt asing, source milik client lain,
source-system/contract-version mismatch, serta organization/participant/package
silang; graph valid tetap dapat ditulis.

Preflight `down()` PostgreSQL sekarang membuka transaksi singkat, menyimpan raw
GUC app context, menetapkan service context lokal hanya untuk pemeriksaan
visibility, memverifikasi role efektif, lalu selalu memulihkan nilai sebelumnya.
Populated table dengan app context kosong menolak rollback sebelum child maupun
parent uniques berubah. RLS SELECT proof kini menulis satu row valid sebagai
service terlebih dahulu, lalu membuktikan semua non-service role melihat nol dan
ditolak menulis, sedangkan service membaca tepat row tersebut.

Operational note: dua parent unique indexes dapat memindai serta mengunci tabel
parent saat deployment. Tidak ada migration aktif yang dijalankan; deployment
kelak memerlukan maintenance window, duplicate preflight, serta lock/statement
timeout plan. Down menghapus child lebih dahulu, kemudian parent uniques.

Bukti review fix aktual:

- focused P13a1 SQLite: **6 tes, 41 assertions**;
- related migration regression SQLite: **53 tes, 303 assertions**;
- full PostgreSQL 17.6 disposable: **299 tes, 2.050 assertions**, cleanup sukses;
- PHP syntax, Pint scoped, PHPStan scoped **0 error**, dan `git diff --check`
  lulus.

`tests/Postgres/AssessmentBillingMigrationTest.php` hanya mendapat patch helper
enam baris untuk memasukkan dua parent scope constraints ke snapshot roundtrip;
root harus resolve add/add file baseline itu secara manual. **Tetap STOP sebelum
IssueCheckoutHandoff/config/route/P13a2/P13b/P14.**

## Review hardening kedua — attempt source dan client organization

Attempt scope sekarang juga mencakup `source_system`, sehingga source valid dari
client yang sama tetapi berbeda sistem tidak dapat dipasang pada handoff attempt
lain. Parent unique ketiga mengikat IntegrationClient `(id, organization_id)` dan
child memakai composite restrict FK. Dengan demikian nilai client pada attempt
historis yang tidak cocok dengan organisasi tidak dapat menjadi handoff valid.

Tes SQLite dan PostgreSQL mengisolasi kedua gap: source B milik client sama lolos
source FK tetapi ditolak attempt FK; graph attempt yang menyimpan client dari
organisasi lain lolos attempt/source tuple tetapi ditolak client scope FK. Graph
source A dan client/organization yang konsisten tetap masuk. Snapshot migration
shared sekarang mencakup ketiga parent constraints dan fixture roundtrip memakai
`source_system` authoritative dari attempt.

Focused final: **6 tes, 43 assertions**; related SQLite: **53 tes, 305
assertions**; full PostgreSQL disposable: **299 tes, 2.054 assertions** dengan
cleanup sukses. Pint dan PHPStan scoped lulus tanpa error, serta diff-check
bersih. Operational note kini mencatat tiga parent unique indexes. **STOP sebelum
P13a2.**
