# ADR-017: Attestation ACL kandidat checkout

## Status

Proposed dan diblokir oleh penetapan policy authority serta freshness challenge.
Belum diizinkan untuk implementasi. Ini bukan bukti ACL Windows dan tidak
mengaktifkan candidate, browser, service, atau deployment.

## Date

2026-09-06

## Context

Mode `0600/0700`, path canonical, hash, dan lifecycle lease tidak membuktikan
owner SID, protected DACL, inheritance, effective access, maupun hak rename/delete
di Windows. Attestation saat build juga dapat kedaluwarsa sebelum lifecycle mulai.

## Decision

Coordinator menerima satu attestor yang dipin setelah lifecycle lease diperoleh.
Coordinator lalu membangun dan memvalidasi run secara side-effect-free, mengikat
lease, melakukan attestation, dan hanya setelah sukses memasang anchor store.
Attestor menyediakan `attest(request)` dan
`load(requestDigest)`; object, tipe, bound method implementation, request, dan
hasilnya wajib exact serta diperiksa ulang sebelum delegasi fresh/recovery.

Request mengikat version, phase, session, config binding, lease binding, policy
digest authoritative, challenge acak 256-bit per invocation, dan target canonical
berurutan `coordinator`, `run`, `source`. Evidence
mengikat digest request/policy/lease serta untuk setiap target: role/path,
volume serial, file ID, owner SID, DACL digest, `reparse=false`, dan
`policySatisfied=true`. Raw SID/DACL tidak dicatat ke log atau browser.

Recovery selalu membuat challenge dan attestation baru; evidence lama atau cache
saja tidak menjadi authority. Tanpa attestor, schema exact, atau return/load equality,
lifecycle ditolak sebelum claim, anchor I/O, atau process spawn.

Attestation harus dipin sebagai capability pada supervisor dan diwajibkan pada
instruksi pertama claim/recovery; validasi coordinator saja tidak cukup karena
internal assembly atau `WindowsRun` langsung dapat menjadi bypass.

## Design Review Blockers

- Policy ACL exact beserta canonical serialization/digest harus berada dalam
  artifact reviewed yang immutable dan masuk closure/hash kandidat. Caller atau
  attestor tidak boleh memilih policy authority.
- Challenge per invocation, canonical request/evidence bytes, batas ukuran,
  duplicate-key rejection, serta orphan/cache retention harus dispesifikasikan.
- Evidence identity harus terikat ke identity run pada lease, coordinator anchor,
  dan source yang dipin; bukan hanya path atau boolean `policySatisfied`.
- Semua kegagalan, termasuk callback mutation dan `BaseException`, wajib melepas
  lease tanpa menutupi error utama dan sebelum anchor/claim/process I/O.

## Consequences

- Pure/mock tests membuktikan schema, binding, ordering, callable pinning, dan
  mutation isolation saja.
- Runtime Windows tetap wajib membuktikan ACE/DACL effective access, inheritance,
  volume/file ID, reparse/rename races, perubahan ACL setelah attest, serta
  penolakan principal kedua.
- DACL tidak membedakan dua proses dengan SID sama dan tidak menggantikan lease.
- P17c tetap terbuka sampai bukti runtime dan browser matrix selesai.
