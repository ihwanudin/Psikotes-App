# ADR-017: Attestation ACL kandidat checkout

## Status

Accepted untuk implementasi kontrak dan pengujian lokal bertahap. Ini bukan
bukti ACL Windows dan tidak mengaktifkan candidate, browser, service, atau deployment.

## Date

2026-09-06

## Context

Mode `0600/0700`, path canonical, hash, dan lifecycle lease tidak membuktikan
owner SID, protected DACL, inheritance, effective access, maupun hak rename/delete
di Windows. Attestation saat build juga dapat kedaluwarsa sebelum lifecycle mulai.

## Decision

Policy authority adalah exact canonical artifact
`tools/testing/tests/Browser/checkout-windows-acl-policy-v1.json`. Artifact wajib
masuk manifest/candidate closure dan SHA-256 literalnya dipin di builder serta
supervisor (`a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb`).
Caller dan attestor tidak menerima authority untuk memilih policy.
Canonical encoding adalah ASCII JSON sorted-key dengan separator `,`/`:`, tanpa
BOM/duplicate/nonfinite, tepat satu LF akhir, dan maksimum 8 KiB.

Policy v1 mewajibkan security descriptor ber-owner exact
`PROCESS_TOKEN_USER`, DACL present/non-NULL, protected, tidak auto-inherited,
revision 2, dan tepat satu ACE pada urutan 0. ACE tersebut adalah
`ACCESS_ALLOWED_ACE_TYPE` numerik 0 untuk trustee `PROCESS_TOKEN_USER`, bukan
inherited, tanpa propagation flag, serta memakai exact gabungan
`OBJECT_INHERIT_ACE|CONTAINER_INHERIT_ACE` numerik 3. Coordinator dan run memakai
`FILE_ALL_ACCESS` `0x001F01FF`/2032127; source memakai
`FILE_GENERIC_READ|FILE_GENERIC_EXECUTE` `0x001200A9`/1179817.

Principal uji ordinary kedua harus merupakan token non-privileged yang exact
membership scope `TOKEN_USER` dan seluruh `TOKEN_GROUPS`-nya tidak memuat SID
`PROCESS_TOKEN_USER`; token user tersebut juga bukan LocalSystem `S-1-5-18`. Enabled
`SeBackupPrivilege`, `SeRestorePrivilege`, atau `SeTakeOwnershipPrivilege`
mengecualikan token dari klaim ordinary. Exact native `AccessCheck` dengan
`MAXIMUM_ALLOWED` `0x02000000`/33554432 harus berhasil sebagai pemanggilan fungsi
(`expectedFunctionSuccess=true`) tetapi mengembalikan keputusan akses false
(`expectedAccessStatus=false`) dan granted mask 0. Penolakan akses bukan kegagalan
pemanggilan native dan tidak dimodelkan sebagai `ERROR_ACCESS_DENIED`. Klaim ini
hanya mengenai akses yang diberikan DACL; SYSTEM dan
akses yang dimediasi privilege berada di luar klaim dan wajib dilaporkan terpisah
pada runtime.

Coordinator menerima satu attestor yang dipin setelah lifecycle lease diperoleh.
Coordinator lalu membangun dan memvalidasi run secara side-effect-free, mengikat
lease, melakukan attestation, dan hanya setelah sukses memasang anchor store.
Attestor menyediakan `attest(request)`, `load(requestDigest)`, dan
`discard(requestDigest)`; object, tipe, bound method implementation, request, dan
hasilnya wajib exact serta diperiksa ulang sebelum delegasi fresh/recovery.

Setiap lifecycle memakai dua challenge acak 256-bit berbeda: boundary `anchor`
sebelum anchor I/O dan boundary `execution` tepat sebelum supervisor berjalan.
Request canonical maksimum 16 KiB mengikat version, boundary, phase, session,
config binding, lease binding dan identity, policy digest authoritative, challenge,
serta target canonical berurutan `coordinator`, `run`, `source`. Evidence maksimum
32 KiB
mengikat digest request/policy/lease serta untuk setiap target: role/path,
volume serial, file ID, owner SID, DACL digest, `reparse=false`, dan
`policySatisfied=true`. Raw SID/DACL tidak dicatat ke log atau browser.

Request/evidence memakai exact types/keys/order; bool tidak diterima sebagai int.
Volume serial dan file ID adalah string desimal unsigned canonical tanpa leading
zero dan dibatasi 128-bit. SID wajib canonical `S-...`; seluruh digest lowercase
SHA-256. Target evidence harus sama role/path-nya, `reparse=false`, dan
`policySatisfied=true`; identity run harus cocok lease, coordinator cocok anchor
directory, serta source harus sama pada kedua boundary.

Cache attestor hanya process-local dan one-shot: `attest` melakukan live scan dan
insert satu digest, `load` atomik mem-pop hasil, load-only/second-load ditolak.
`discard` selalu dicoba pada failure setelah request terbentuk. Evidence tidak
dipersistenkan. Recovery selalu membuat challenge dan attestation baru; evidence lama atau cache
saja tidak menjadi authority. Tanpa attestor, schema exact, atau canonical-byte return/load equality,
lifecycle ditolak sebelum claim, anchor I/O, atau process spawn.

Attestation harus dipin sebagai capability pada supervisor dan diwajibkan pada
instruksi pertama claim/recovery; validasi coordinator saja tidak cukup karena
internal assembly atau `WindowsRun` langsung dapat menjadi bypass.

## Admission Lifecycle

Supervisor menyimpan state privat satu-arah:
`unbound → bound → anchor_ready → anchor_consumed → execution_ready → active → exhausted`.
Anchor publisher hanya dapat dipasang setelah admission anchor dan langsung
mengonsumsinya. `supervise`/`recover` mengaktifkan admission execution sebelum
preflight/anchor/census; claim/recovery dan seluruh Popen boundary mensyaratkan
state aktif. Admission berakhir di `finally` setelah cleanup.

Urutan coordinator: snapshot input → acquire lease → construct/validate run tanpa
anchor I/O → bind lease+attestor → attest/load boundary anchor → attach publisher
(recovery lalu load anchor) → attest/load boundary execution → delegate. Semua
callback menerima deep copy; object/type/bound-method implementation serta nilai
request/evidence dipin dan dicek pre/post. Semua kegagalan mencoba `discard`,
menutup admission, dan melepas lease tanpa menutupi error utama atau
`KeyboardInterrupt`/`SystemExit`.

## Consequences

- Pure/mock tests membuktikan schema, binding, ordering, callable pinning, dan
  mutation isolation saja.
- Runtime Windows tetap wajib membuktikan ACE/DACL effective access, inheritance,
  volume/file ID, reparse/rename races, perubahan ACL setelah attest, serta
  penolakan principal kedua.
- Tiga target evidence hanya membuktikan descriptor direktori coordinator, run,
  dan source. ACL existing descendant source tree belum dibuktikan oleh kontrak
  ini; recursive descendant attestation atau bukti ekuivalen tetap merupakan gate
  runtime terbuka sebelum candidate dapat dipromosikan.
- DACL tidak membedakan dua proses dengan SID sama dan tidak menggantikan lease.
- P17c tetap terbuka sampai bukti runtime dan browser matrix selesai.
