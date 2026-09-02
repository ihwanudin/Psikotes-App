# P13a3 backend — PostgreSQL checkout handoff concurrency evidence

Tanggal: 2026-09-02

## Hasil

P13a3 menambah satu test PostgreSQL authoritative untuk process overlap,
idempotency, REISSUE serialization, post-lock authority, dan revocation ordering.
Setiap child memakai koneksi independen sebagai `psikotes_runtime`, non-superuser
dan NOBYPASSRLS. Socket pair hanya membawa PID, raw-presence boolean, public ID,
issue number, replay flags, dan SHA-256 digest; raw bearer tidak pernah ditulis ke
stdout, log, file, atau payload antarproses.

Seluruh race memakai barrier parent, timeout PostgreSQL/PHP bounded, dan observasi
`pg_stat_activity.wait_event_type = 'Lock'`. Sembilan lock wait teramati:
dua same-ISSUE, dua distinct-REISSUE, satu effective-window waiter, tiga
revoke-first target, dan satu issue-first/revoker waiter.

## Bug production yang ditemukan

Runner awal membuktikan `CURRENT_TIMESTAMP` PostgreSQL tetap sama sepanjang
transaksi. Karena action membaca clock kedua di transaksi yang sama, waiter yang
melewati `effective_until` masih dapat memakai transaction-start timestamp.
Production fix terbatas mengganti primitive PostgreSQL menjadi
`clock_timestamp()`; SQLite tetap memakai `CURRENT_TIMESTAMP`. Dengan demikian
observasi final benar-benar wall-clock database setelah lock wait. Initial child
socket juga diperkeras agar parent assertion/timeout selalu membuat child
disconnect dan exit, bukan melanjutkan proses PHPUnit.

## Bukti concurrency

- Dua ISSUE exact menghasilkan satu row, satu audit, issue #1, satu result raw,
  dan satu credentialless replay dengan public ID yang sama.
- Dua REISSUE berbeda menghasilkan issue #2 dan #3 secara contiguous. Issue #1
  dan #2 terminal REVOKED/REISSUED, hanya #3 active, dan active token digest cocok
  hanya dengan digest result issue #3. Total audit tepat tiga.
- Waiter yang benar-benar blocked sampai client/source `effective_until` lewat
  ditolak `HANDOFF_NOT_ALLOWED` tanpa row, audit, atau raw result.
- Client disable, source suspension, dan attempt revoke yang commit lebih dahulu
  membuat blocked issuer ditolak. Cabang sebaliknya juga diuji: issuer commit
  penuh, revoker yang menunggu kemudian menonaktifkan client dan terminalizes
  row menjadi REVOKED/CLIENT_REVOKED; final state tidak mempunyai active handoff
  untuk authority invalid.
- Same-client cross-attempt idempotency ditolak conflict; foreign client tidak
  dapat menerbitkan untuk attempt asli. Database clock, one-active, safe audit
  allowlist, dan unrelated billing/access/order/outbox/identity/session counts
  tetap benar.

## Verifikasi aktual

- Focused PostgreSQL disposable: **8 tes, 113 assertions**.
- Full PostgreSQL disposable final: **307 tes, 2.167 assertions**.
- Related local checkout/schema regression: **139 tes, 979 assertions**.
- Pint scoped lulus; PHPStan scoped lulus **0 error**; `git diff --check` lulus.
- Setiap runner memakai internal network tanpa published port dan melaporkan
  cleanup container serta network sukses. Runner focused sementara dihapus dan
  runner kanonik dipulihkan byte-identik.

## Batas

Tidak ada perubahan config default-OFF, migration, route/controller, consume,
session, public source/gate, provider/notifier/outbound, `.env`, data nyata,
deploy, push, P13b, atau P14. P13a3 hanya mengubah production action karena test
RED menemukan primitive clock PostgreSQL yang memang stale. **STOP untuk review
sebelum integrasi/P13b/P14.**
