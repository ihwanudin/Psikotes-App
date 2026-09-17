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
handoff history, lalu checkout-session history. Recovery hanya menerima latest
handoff `CONSUMED` yang mempunyai tepat satu session exact, terikat ke handoff/
attempt/client/organization/participant/package/source/contract yang sama.
`CheckoutSessionRecoveryState` membatasi empat restart state: ACTIVE belum due,
ACTIVE yang sudah idle/absolute due, terminal EXPIRED valid, dan terminal REVOKED
dengan reason LOGOUT.

Dalam transaksi service yang sama, ACTIVE belum due menjadi `REVOKED` dengan
reason `RECOVERY_REISSUED`; ACTIVE due diterminalkan menjadi `EXPIRED` memakai
database wall clock; terminal EXPIRED/LOGOUT tidak ditulis ulang. Handoff
CONSUMED lama tidak diubah, generation baru dibuat `ISSUED`, dan satu audit
`checkout_handoff.recovery_reissued` mencatat prior session state allowlist tanpa
identifier session atau credential.
Raw bearer baru hanya dikembalikan dari eksekusi pertama dan tetap tidak masuk
model, descriptor, audit, exception, atau IPC test.

Same key/hash menjadi replay credentialless tanpa transisi/audit tambahan. Key
sama dengan intent atau attempt scope lain pada client yang sama menjadi
idempotency conflict. Recovery distinct berikutnya tidak bertindak sebagai
REISSUE implisit. Missing, SCOPE_REVOKED, future-timestamp, foreign, corrupt,
atau terminal RECOVERY_REISSUED/REPLACED tanpa generation lanjut serta latest
handoff non-CONSUMED gagal tertutup. Terminal history lama hanya diterima bila
transition timestamp dan generation berikutnya konsisten. Authority client,
source, attempt, package, participant, dan effective window tetap direload.

## TDD dan bukti

- RED awal: **5 tes/0 passed**, seluruhnya error karena enum intent Recovery
  belum ada.
- RED review-fix: **6 tes**, 4 passed, 1 failure, dan 1 error; audit belum
  mencatat prior state dan ACTIVE due masih ditolak.
- Focused final recovery + issuance + consume: **26 tes/348 assertions**, lulus.
  Cakupan meliputi exact success/replay, scope+intent conflict, session invalid,
  ACTIVE due, natural EXPIRED, LOGOUT, SCOPE_REVOKED, authority revoked, audit
  rollback, raw secrecy, dan nol side effect pada billing/access/order/outbox/
  consent/identity/global session.
- PostgreSQL disposable final: **339 tes/2.456 assertions**, lulus dan cleanup
  sukses. Sembilan case proses recovery berjalan sebagai `psikotes_runtime`
  non-superuser `NOBYPASSRLS`, mengamati `wait_event_type=Lock`, dan membuktikan
  distinct serta same-key recovery pada ACTIVE/EXPIRED/LOGOUT dan linearization
  melawan expiry/logout/scope revocation.
- Run PG review-fix menemukan flake presisi: `timestampTz` membulatkan ke detik,
  sedangkan wall clock microsecond dapat membuat LOGOUT sah tampak future.
  Recovery sekarang memakai `clock_timestamp()::timestamptz(0)` agar authority
  cocok dengan presisi storage. Rerun penuh final lulus.
- Pint lima file kode/tes lulus.
- PHPStan seluruh project pada testing/SQLite memory lulus **0 error**.
- PHP syntax dan `git diff --check` lulus.

## File delta

- `app/Enums/CheckoutHandoffIntent.php`
- `app/Enums/CheckoutSessionRecoveryState.php`
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
