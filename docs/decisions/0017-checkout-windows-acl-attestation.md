# ADR-017: Attestation ACL kandidat checkout

## Status

Accepted untuk kontrak dan pengujian lokal default tertutup. Ini bukan bukti ACL
Windows dan tidak mengaktifkan candidate, browser, service, atau deployment.

## Date

2026-09-06

## Context

Mode `0600/0700`, path canonical, hash, dan lifecycle lease tidak membuktikan
owner SID, protected DACL, inheritance, effective access, maupun hak rename/delete
di Windows. Attestation saat build juga dapat kedaluwarsa sebelum lifecycle mulai.

## Decision

Coordinator menerima satu attestor yang dipin setelah lifecycle lease diperoleh
dan sebelum assembly/anchor I/O. Attestor menyediakan `attest(request)` dan
`load(requestDigest)`; object, tipe, bound method implementation, request, dan
hasilnya wajib exact serta diperiksa ulang sebelum delegasi fresh/recovery.

Request mengikat version, phase, session, config binding, lease binding, policy
digest, dan target canonical berurutan `coordinator`, `run`, `source`. Evidence
mengikat digest request/policy/lease serta untuk setiap target: role/path,
volume serial, file ID, owner SID, DACL digest, `reparse=false`, dan
`policySatisfied=true`. Raw SID/DACL tidak dicatat ke log atau browser.

Recovery selalu melakukan attestation baru; evidence lama atau cache saja tidak
menjadi authority. Tanpa attestor, schema exact, atau return/load equality,
lifecycle ditolak sebelum claim, anchor I/O, atau process spawn.

## Consequences

- Pure/mock tests membuktikan schema, binding, ordering, callable pinning, dan
  mutation isolation saja.
- Runtime Windows tetap wajib membuktikan ACE/DACL effective access, inheritance,
  volume/file ID, reparse/rename races, perubahan ACL setelah attest, serta
  penolakan principal kedua.
- DACL tidak membedakan dua proses dengan SID sama dan tidak menggantikan lease.
- P17c tetap terbuka sampai bukti runtime dan browser matrix selesai.
