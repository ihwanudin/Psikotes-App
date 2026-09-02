# P13 — Explicit checkout-handoff recovery

Tanggal: 2026-09-02
Status: **GREEN lokal; menunggu review koordinator**

## Kontrak yang diimplementasikan

`CheckoutHandoffIntent::Recovery` adalah intent typed ketiga pada issuer internal
existing. Tidak ada boolean atau free-string recovery, dan caller tetap hanya
memberikan authenticated persisted client, attempt ULID, source system, serta
idempotency key private. Recovery merupakan tindakan autentik eksplisit yang
disruptif; kode tidak mencoba membuktikan bahwa pengiriman cookie sebelumnya
gagal.

Issuer memakai ulang seluruh authoritative validation dan lock order existing:
organization, client, source, package beserta semua items, attempt, participant,
handoff history, lalu checkout-session history. Recovery baru hanya menerima
latest handoff `CONSUMED` yang mempunyai tepat satu session `ACTIVE`, belum due,
dan terikat exact ke handoff/attempt/client/organization/participant/package/
source/contract yang sama.

Dalam transaksi service yang sama, session ACTIVE menjadi `REVOKED` dengan
reason `RECOVERY_REISSUED`, handoff CONSUMED lama tidak diubah, generation baru
dibuat `ISSUED`, dan satu audit `checkout_handoff.recovery_reissued` ditulis.
Raw bearer baru hanya dikembalikan dari eksekusi pertama dan tetap tidak masuk
model, descriptor, audit, exception, atau IPC test.

Same key/hash menjadi replay credentialless tanpa transisi/audit tambahan. Key
sama dengan intent atau attempt scope lain pada client yang sama menjadi
idempotency conflict. Recovery distinct berikutnya tidak bertindak sebagai
REISSUE implisit. Missing, terminal, due, future-timestamp, foreign, atau corrupt
session serta latest handoff non-CONSUMED gagal tertutup. Authority client,
source, attempt, package, participant, dan effective window tetap direload.

## TDD dan bukti

- RED awal: **5 tes/0 passed**, seluruhnya error karena enum intent Recovery
  belum ada.
- Focused final recovery + issuance + consume: **25 tes/329 assertions**, lulus.
  Cakupan meliputi exact success/replay, scope+intent conflict, session invalid,
  authority revoked, audit rollback, raw secrecy, dan nol side effect pada
  billing/access/order/outbox/consent/identity/global session.
- PostgreSQL disposable final: **333 tes/2.349 assertions**, lulus dan cleanup
  sukses. Tiga tes proses baru berjalan sebagai `psikotes_runtime` non-superuser
  `NOBYPASSRLS`, mengamati `wait_event_type=Lock`, dan membuktikan distinct
  recovery hanya satu pemenang, same-key hanya satu credential, serta revocation
  yang menang menolak generation baru.
- Pint empat file kode/tes lulus.
- PHPStan seluruh project pada testing/SQLite memory lulus **0 error**.
- PHP syntax dan `git diff --check` lulus.

## File delta

- `app/Enums/CheckoutHandoffIntent.php`
- `app/Actions/Integrations/IssueCheckoutHandoff.php`
- `tests/Feature/Integrations/CheckoutHandoffRecoveryTest.php`
- `tests/Postgres/CheckoutHandoffRecoveryConcurrencyTest.php`
- `tasks/organization-payment/reports/backend-p13-checkout-handoff-recovery.md`
- `tasks/organization-payment/reports/backend.md`

## Batas

Tidak ada route/controller/config enablement, HTTP, cookie, CSRF, cleanup,
migration baru, P14 establish/hydrate/revoke action, billing/access/order/outbox,
DB aktif, data nyata, outbound, deploy, atau push. Session revocation race pada
tes PG adalah mutasi sintetis canonical karena action logout/revoke P14 belum
diizinkan pada increment ini.

**STOP sebelum P14 establish atau wiring publik.**
