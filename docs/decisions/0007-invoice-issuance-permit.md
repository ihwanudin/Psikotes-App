# ADR-007: Izin penerbitan invoice sekali pakai

## Status

Accepted for local P10b-b implementation and review only. No dispatcher,
public route, provider credential, active source, or production acceptance.

## Date

2026-09-01

## Context

P10b-a menyimpan satu intent invoice `pending/attempts=0` secara atomik dengan
status bill `issuing`. Retry job atau crash tidak boleh menghasilkan POST kedua.
P10a menyediakan lookup exact yang gagal tertutup; P10c rekonsiliasi belum ada.

## Decision

- Handler internal menerima `message_id`, mengunci ulang organisasi, bill, item,
  snapshot, metode, dan intent. Hanya intent canonical `pending/0` yang dapat
  dikonsumsi atomik menjadi `processing/1`; transaksi konsumsi wajib commit
  sebelum pemanggilan provider. Counter tidak pernah kembali ke nol.
- Setelah izin dikonsumsi, lakukan paling banyak satu `createInvoice` memakai
  reference AB_, nominal, currency, description, dan requested expiry dari intent.
  Tidak membangun ulang nilai dari request/browser atau katalog terkini.
- Baik create mengembalikan nilai maupun melempar, lakukan lookup P10a terhadap
  reference/nominal/currency yang sama. Hanya satu hasil exact yang boleh ditempel
  sebagai `pending` invoice. Empty, ambiguous, mismatch, timeout, atau error
  berakhir `unknown`/intent gagal dengan attempts tetap satu; tidak create ulang.
- Attach hasil atau transisi unknown mengunci ulang dan menolak response terlambat
  yang mencoba menimpa bill terminal/paid atau intent berbeda. Invoice belum
  settlement, consent, identitas, entitlement, maupun izin akses.
- Job/handler boleh dibuat untuk dipanggil langsung oleh tes, tetapi belum boleh
  didaftarkan ke dispatcher/command/scheduler/route. Seluruh HTTP memakai fake dan
  `preventStrayRequests`; tidak ada credential/provider nyata.
- Policy, opt-in, revoke, scope, Xendit aktif, dan snapshot canonical diperiksa
  sebelum konsumsi. Crash setelah commit izin tetapi sebelum POST sengaja menuju
  recovery P10c, bukan rearm. Outer transaction pemanggil yang belum commit harus
  ditolak agar network tidak terjadi di dalam transaksi fisik.

## Consequences

P10b-b membuktikan izin create sekali pakai dan penyimpanan hasil, bukan delivery
queue atau exactly-once provider. Crash window dapat meninggalkan invoice belum
diketahui; operator/reconciliation P10c tetap wajib sebelum fitur dapat diaktifkan.
Uji harus mencakup dua proses PostgreSQL, crash barriers, replay, late response,
policy/channel OFF, corrupt intent, dan bukti satu create maksimum tanpa outbound.
