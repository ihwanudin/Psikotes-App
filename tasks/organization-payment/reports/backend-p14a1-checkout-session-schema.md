# P14a1 — Durable private checkout-session schema

Tanggal: 2026-09-02
Status: **GREEN lokal; menunggu review koordinator**

## Hasil

Increment ini menambah tabel internal `checkout_sessions` dan model
`CheckoutSession` sesuai ADR-012. Tidak ada action, cookie, CSRF runtime,
controller, middleware, route, config, cleanup worker, atau source aktif.

Record hanya menyimpan ULID korelasi noncredential serta digest selector dan
CSRF SHA-256 lowercase. Kedua digest disembunyikan dari serialisasi model. Scope
session diikat dengan composite FK ke handoff, attempt, client, organization,
participant, package, dan source/contract. Handoff dan attempt menghapus session
secara cascade; client dan source tetap restrict.

Lifecycle database menerima hanya `ACTIVE`, `REVOKED`, atau `EXPIRED`, dengan
pasangan marker/timestamp/reason yang tepat. Unique constraints membatasi satu
session per handoff dan satu session aktif per attempt. Timestamp idle/absolute
hanya memiliki ordering constraint; migration tidak menanam durasi UX proposed
30/120 menit.

PostgreSQL mengaktifkan dan memaksa RLS dengan satu policy service-only untuk
runtime non-owner/NOBYPASSRLS. Index mencakup selector lookup, latest attempt,
dan cleanup expiry tanpa predicate waktu volatil. SQLite menggunakan trigger
portable untuk kontrak identity/digest/time/lifecycle, tetapi tidak diklaim
sebagai bukti RLS PostgreSQL.

Migration `down()` melakukan preflight service-visible dan menolak bila satu
session pun ada sebelum DDL. `up()` memeriksa graph handoff lama sebelum menambah
parent unique/composite FK. Roundtrip kosong memulihkan struktur, sedangkan
history populated dan duplicate/corrupt parent fail closed tanpa perubahan
parsial. Dua test migration historis disesuaikan secara test-only agar child
session diturunkan sebelum parent handoff dalam savepoint transaksi.

## TDD dan verifikasi

- RED awal: **5 tes**, 1 failure dan 4 errors karena table/model/migration belum
  ada. Setelah fixture timestamp diperbaiki, GREEN focused P14a1:
  **5 tes/43 assertions**.
- Regresi SQLite schema session + handoff: **11 tes/86 assertions**, lulus.
- Runner PostgreSQL pertama menemukan dua dependency-order errors dan dua
  assertion fixture errors. Setelah perbaikan test-only, runner disposable
  penuh lulus **330 tes/2.299 assertions**; runtime ialah
  `psikotes_runtime`, non-superuser, `NOBYPASSRLS`, dan seluruh resource
  disposable dibersihkan.
- Pint enam file PHP lulus.
- PHPStan seluruh project dengan environment testing/SQLite memory lulus
  **0 error**.
- PHP syntax dan `git diff --check` lulus.

## File delta

- `database/migrations/2026_09_02_000200_create_checkout_sessions.php`
- `app/Models/CheckoutSession.php`
- `tests/Feature/Database/CheckoutSessionSchemaTest.php`
- `tests/Postgres/CheckoutSessionSchemaTest.php`
- `tests/Postgres/CheckoutHandoffSchemaTest.php` (test-only rollback ordering)
- `tests/Postgres/AssessmentBillingMigrationTest.php` (test-only rollback ordering)
- `tasks/organization-payment/reports/backend-p14a1-checkout-session-schema.md`
- `tasks/organization-payment/reports/backend.md`

## Batas dan risiko deployment

Belum ada raw selector/CSRF generation, exchange, hydrate/revoke, recovery,
cookie, HTTP, config enablement, atau cleanup. Migration menambah parent unique
index pada `checkout_handoffs`; production rollout kelak memerlukan maintenance
window serta lock/statement-timeout plan dan tetap bukan bagian increment ini.
Tidak ada migration pada DB aktif, data nyata, outbound, deploy, atau push.

**STOP sebelum amendment recovery P13, action P14, atau wiring publik.**
