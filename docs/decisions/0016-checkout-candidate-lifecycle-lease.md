# ADR-016: Lease lifecycle kandidat checkout

## Status

Accepted untuk implementasi dan pengujian lokal bertahap. Keputusan ini tidak
menyalakan candidate runner, browser, service, database, payment, atau deployment.

## Date

2026-09-06

## Context

Anchor store saat ini mengunci hanya satu transaksi publish dan nama lock-nya
bergantung pada session/config. Dua lifecycle untuk direktori kandidat yang sama
masih dapat berjalan bersamaan. File sentinel `O_EXCL` yang dihapus saat selesai
tidak aman: crash meninggalkan stale lock permanen, sedangkan penghapusan atau
recreate dapat membuka dua inode dan split-brain.

Validasi publisher saja juga terlambat. `claim()` dapat menulis claim sebelum
publish pertama, recovery dapat membaca anchor sebelum façade lifecycle mulai,
dan pemanggilan `WindowsRun` langsung dapat melewati coordinator.

## Decision

Gunakan satu file provenance stabil bernama `.checkout-coordinator.lease` di
root kandidat canonical dan jangan pernah menghapusnya. Lokasi run-local yang
deterministik memastikan dua `coordinator_directory` berbeda tetap berebut lease
yang sama. File dibuat `O_EXCL` hanya saat pertama kali dan selanjutnya
dibuka ulang dengan schema/content serta identity path/descriptor/parent exact.
Kepemilikan lifecycle berasal dari kernel/advisory lock nonblocking pada handle
non-inheritable, bukan dari keberadaan file. OS melepas lock saat proses mati;
tidak ada stale-lock deletion atau auto-break.

Coordinator harus memperoleh lease sebelum assembly atau anchor I/O, lalu
memegangnya sampai `supervise`/`recover` selesai atau melempar. Cleanup selalu
mencoba unlock/close dan tidak boleh menutupi error utama maupun
`KeyboardInterrupt`/`SystemExit`.

Supervisor menerima capability lease sekali sebelum claim/recovery, mem-pin
object, tipe, callable implementation, binding, dan handle identity. Instruksi
pertama `claim()` dan `recover_ownership()` wajib memvalidasi capability; setiap
publisher publish/load memvalidasinya kembali sebelum dan sesudah anchor I/O.
Bare claim/recovery tanpa admission lease ditolak. Key lease hanya path kandidat,
bukan session atau config binding.

## Alternatives Considered

### Sentinel yang dihapus saat release

Ditolak karena crash meninggalkan stale sentinel, sedangkan unlink/recreate dapat
mengizinkan pemegang inode lama dan baru berjalan bersamaan.

### Lock hanya pada façade atau publisher

Ditolak karena direct `claim()` dan recovery sebelum publisher tetap menjadi
bypass. Admission harus berada pada supervisor boundary juga.

### Menganggap permission 0600/0700 sebagai ACL Windows

Ditolak. Mode POSIX bukan bukti owner SID, protected DACL, effective access,
inheritance, rename/delete rights, atau penolakan principal lain di Windows.

## Consequences

- Pure/mock tests dapat membuktikan key global, ordering, capability pinning,
  same-process contention, no-unlink, dan exception-safe release.
- Real Windows tests tetap wajib untuk kernel-lock/crash semantics, file identity,
  junction/reparse/rename behavior, durability, dan ACL/effective access.
- Satu proses coordinator harus single-use per lifecycle pada acceptance runtime;
  lease tidak membuktikan bahwa claim owner yang masih hidup sudah aman direcover.
- P17c tetap terbuka sampai seluruh bukti runtime dan browser matrix selesai.
