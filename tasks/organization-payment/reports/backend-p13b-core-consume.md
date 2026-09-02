# P13b backend — atomic checkout handoff consumption

Tanggal: 2026-09-02

## Hasil

P13b menambah boundary internal untuk menukar satu bearer `och1_` menjadi scope
checkout-session yang typed dan aman. Input menyimpan raw bearer sebagai property
private dan menandai constructor/action parameter dengan `SensitiveParameter`.
Lookup hanya memakai SHA-256 digest dan dibatasi dua row; exception publik tunggal
selalu `CHECKOUT_HANDOFF_INVALID`, tanpa raw bearer, digest, atau alasan cabang.

Action menolak ambient RLS context dan outer transaction, lalu memiliki satu
service transaction. Di dalamnya action memuat routing hint, mengunci graph sesuai
urutan issuer (organization, client, source, package/item, attempt, participant,
history handoff), dan memvalidasi ulang checkout-v2, purpose, destination, scope,
authority persisted, lifecycle, serta effective window memakai wall clock database
setelah lock. Handoff valid berubah atomik `ISSUED -> CONSUMED`; satu audit dengan
allowlist identifier aman ditulis di transaksi yang sama. Expiry yang teramati
berubah atomik menjadi `EXPIRED`, tetapi tetap mengembalikan hasil generik invalid
dan tidak menulis audit consume.

Scope hasil hanya membawa identifier persisted yang dibutuhkan boundary sesi
checkout berikutnya dan timestamp konsumsi. Scope ini tidak membuat Laravel
session/cookie, tidak memberi hak assessment, dan tidak mengubah billing, order,
entitlement, consent, identity, invitation, activation, atau outbox.

## TDD dan concurrency

RED pertama: **3 tes gagal/3 errors** karena input DTO, action, exception, dan
scope hasil belum ada. GREEN feature akhir: **7 tes/64 assertions** dalam file
consume; bersama regresi issuer menjadi **20 tes/220 assertions**. Tes mencakup
format/bounded generic failure, serialisasi input/scope aman, exact replay,
config default-off/invalid, expiry, revocation organization/client/source/package/
attempt/participant, ambient transaction, audit rollback, serta tidak adanya
side effect pada billing/access/order/outbox/identity/session.

PostgreSQL disposable final: **310 tes/2.210 assertions**, runtime
`psikotes_runtime` non-superuser/NOBYPASSRLS. Tiga test dua-proses baru membuktikan:

- bearer sama menghasilkan tepat satu consume dan satu generic loser;
- consume melawan REISSUE linearizable: old token consumed tanpa active bearer,
  atau old token revoked dan hanya generation baru active;
- consumer yang menunggu revocation client commit ditolak tanpa consume/audit.

Setiap worker benar-benar diamati pada `pg_stat_activity.wait_event_type=Lock`.
IPC hanya memuat PID dan boolean outcome; raw bearer tidak pernah dikirim lewat
socket, stdout, log, file, audit, exception, atau descriptor. Runner memakai
network internal tanpa published port dan membersihkan container/network.

## Verifikasi dan batas

- Regresi `tests/Feature/Integrations`: **191/192 tes lulus, 1.338 assertions**.
  Satu kegagalan existing `SelectionLaunchTest` adalah batas worktree karena
  `public/build/manifest.json` tidak tersedia. Tidak dibuat fake manifest dan
  tidak ada harness yang dilonggarkan.
- Pint scoped lulus; PHPStan full lulus **0 error**; `git diff --check` lulus.
- Tidak ada perubahan config, schema/migration, route/controller/request,
  Laravel session, public source/gate, provider/notifier, credential, `.env`,
  data aktif, outbound, deploy, push, P14, atau fase setelahnya.

File lane: `ConsumeCheckoutHandoff`, `InvalidCheckoutHandoff`,
`CheckoutHandoffConsumeInput`, `CheckoutSessionScope`, feature consume test, dan
PostgreSQL replay/concurrency test. **STOP untuk review P13b sebelum P14.**
