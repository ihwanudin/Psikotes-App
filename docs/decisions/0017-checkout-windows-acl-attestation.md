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
Ketiga method adalah instance method sempit dua argumen termasuk `self`, tanpa
default, keyword-only, closure, varargs, generator, atau coroutine; identity code
implementation dipin dan diperiksa sebelum serta sesudah callback.

Setiap lifecycle memakai dua challenge acak 256-bit berbeda: boundary `anchor`
sebelum anchor I/O dan boundary `execution` tepat sebelum supervisor berjalan.
Request canonical maksimum 16 KiB mengikat version, boundary, phase, session,
config binding, lease binding dan identity, policy digest authoritative, challenge,
serta target canonical berurutan `coordinator`, `run`, `source`. Evidence maksimum
32 KiB
mengikat digest request/policy/lease serta untuk setiap target: role/path,
volume serial, file ID, owner SID, DACL digest, `reparse=false`, dan
`policySatisfied=true`. Raw SID/DACL tidak dicatat ke log atau browser.

Path ketiga target wajib pairwise distinct dan pasangan identity
`(volumeSerial,fileId)` juga wajib pairwise distinct; object filesystem yang sama
tidak boleh memenuhi lebih dari satu role. Kontrak outer codec wajib menolak
alias tersebut. Enforcement pada outer codec existing masih remediation terbuka
dan belum boleh dianggap terbukti oleh dokumentasi ini.

Request/evidence memakai exact types/keys/order; bool tidak diterima sebagai int.
Volume serial dan file ID adalah string desimal unsigned canonical tanpa leading
zero dan dibatasi 128-bit. SID wajib canonical `S-...`; seluruh digest lowercase
SHA-256. Target evidence harus sama role/path-nya, `reparse=false`, dan
`policySatisfied=true`; identity run harus cocok lease, coordinator cocok anchor
directory, serta source harus sama pada kedua boundary.

Cache attestor hanya process-local dan one-shot: `attest` melakukan live scan dan
insert satu digest, lalu `load` pertama atomik mem-pop hasil. `load` tanpa hasil
atau pemanggilan kedua wajib mengembalikan sentinel `None`; supervisor memanggil
ulang sekali dan menolak nilai lain sebagai evidence replayable.
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

## Implementation Checkpoint — `15a5509`

Supervisor wrapper dan boundary direct kini mewajibkan admission aktif, termasuk
recheck tepat sebelum setiap `Popen`. Recovery mengikat session dan anchor exact
ke hasil load one-shot, lalu memvalidasinya kembali sebelum ownership I/O.
Coordinator merangkai lease, dua boundary attestation, publisher dan gated anchor
load dalam urutan canonical; refusal supervisor dipetakan ke vocabulary
coordinator tanpa menutupi `BaseException` utama.

Bukti root: supervisor aman **133/133 tes** dengan real-listener dikecualikan,
coordinator+lease **38/38**, AST **4 file**, `py_compile`, diff-check, dan review
adversarial **PASS**. Ini hanya bukti pure/mock dan statis. Real attestor Windows,
recursive descendant source-tree ACL/effective access, cross-process/crash,
reparse/rename, durability, serta browser runtime tetap terbuka. P17c/P18 tidak
ditutup; tidak ada runtime/deploy dan seluruh gate/payment tetap default OFF.

## Implementation Checkpoint — `6256dc8`

Kontrak pure source-tree summary sekarang menerima manifest nonempty beserta
tepat seluruh file dan implied directory-nya. Path relatif dibatasi pada ASCII
yang aman untuk semantik Windows; jumlah record, kedalaman, panjang path, dan
ukuran canonical payload dibatasi. Record mengikat identity unik, bentuk owner
SID canonical, dan digest DACL. Digest summary memakai preimage canonical
manifest+records yang domain-separated, berurutan deterministik, dan dijaga oleh
known vector; summary pada boundary anchor dan execution harus sama exact.

Bukti lokal accepted: **8/8 tes**, `py_compile`, diff-check, dan review
adversarial **PASS**. Primitive ini belum diintegrasikan ke codec, policy,
attestor, atau candidate builder. Ia belum membuktikan kelengkapan native source
tree, empty directory yang tidak diimplikasikan manifest, identity root yang
dikecualikan dari summary, enforcement ACL/effective access, atau perilaku
Windows runtime. P17c/P18 tetap terbuka, tidak ada runtime/deploy/aktivasi, dan
seluruh gate/payment tetap default OFF.

## Implementation Checkpoint — `2b715e1`

Boundary ABI `ctypes` Win32 kini didefinisikan secara lazy untuk persiapan
attestor. Import hanya membentuk tipe, konstanta, dan tabel; belum ada class
attestor dan tidak ada native call. Platform non-Windows menolak sebelum
`WinDLL`. Loader mengikat **28 signature exact** memakai oracle pengujian
independen, nama DLL canonical, `use_last_error`, width/layout struktur, serta
resolver sempit yang memvalidasi ulang identity, signature, dan bundle agar
mutasi gagal tertutup. Error memakai vocabulary fixed tanpa detail native;
`KeyboardInterrupt` dan `SystemExit` tetap dipertahankan.

Signature boundary merujuk dokumentasi Microsoft untuk
[CreateFileW](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilew),
[GetSecurityInfo](https://learn.microsoft.com/en-us/windows/win32/api/aclapi/nf-aclapi-getsecurityinfo),
dan [AccessCheck](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-accesscheck).
Bukti accepted: **7/7 tes**, `py_compile`, diff-check, dan cross-review **PASS**.
Ini belum merupakan real attestor, scanner, cache, atau composition wiring dan
tidak membuktikan efektivitas native/ACL Windows. P17c/P18 tetap terbuka, tidak
ada native/runtime/deploy/aktivasi, dan seluruh gate/payment tetap default OFF.

## Implementation Checkpoint — `038cbdd`

Private directory-handle primitive kini membuka handle dengan akses/share/flag
exact, memastikan handle non-inheritable, dan memverifikasi target adalah
directory serta bukan reparse point. Final path canonical dan identity dibaca
dua kali dan wajib identik. `FILE_ID_128` diperlakukan sebagai 16 byte opaque,
bukan integer native; serialisasi contract mengubah byte sequence tersebut ke
string desimal canonical dengan interpretasi big-endian. Handle ditutup tepat
sekali pada semua jalur, sementara kegagalan close tidak menutupi exception atau
`KeyboardInterrupt`/`SystemExit` utama.

Bukti root accepted: **16/16 tes**, `py_compile`, diff-check, dan final
cross-review **PASS**. Tes memakai fake ABI; tidak ada native call atau filesystem
Windows nyata. Primitive ini belum menjadi public attestor/scanner/cache, belum
terintegrasi dengan codec, policy, source-tree summary, coordinator, atau
candidate closure, dan belum memanggil `GetSecurityInfo`, memvalidasi owner/DACL
atau token, maupun menjalankan `AccessCheck`. Kelengkapan descendant, empty dirs,
root binding, native ACL efficacy, race/reparse/rename/TOCTOU, crash, dan browser
runtime tetap terbuka. P15/P16/P17c/P18 tidak berubah; seluruh gate/payment tetap
default OFF dan tidak ada deploy/aktivasi.

## Implementation Checkpoint — `49d6b87`

Boundary ABI kini mem-pin **29 signature**, termasuk `IsValidAcl`. Primitive
private mengambil dua snapshot security descriptor melalui live handle yang
sama. Setiap snapshot wajib self-relative dan bounded; pointer owner, group, dan
DACL harus berada di dalam allocation descriptor. DACL wajib present, non-NULL,
protected, tidak auto-inherited, memakai security descriptor revision 1 dan ACL
revision 2. Seluruh rentang `AclSize` harus contained sebelum traversal native
apa pun dan digest dihitung dari tepat `AclBytesInUse`. Hanya pointer
base hasil alokasi yang diberikan ke `LocalFree`, tepat sekali pada setiap jalur.

Bukti root accepted: **24/24 tes**, `py_compile`, diff-check, dan final review
adversarial **PASS**. Semua bukti masih memakai fake ABI. Primitive belum
mengubah SID ke string atau mencocokkan owner dengan process token, belum
mem-parsing ACE exact atau membuktikan policy/effective `AccessCheck`, dan belum
terhubung ke source-tree summary, composition, cache, atau usable attestor.
Native runtime, recursive completeness, race/reparse/rename/TOCTOU, crash, dan
browser tetap terbuka. P15/P16/P17c/P18 tidak berubah; tidak ada
runtime/deploy/aktivasi dan seluruh gate/payment tetap default OFF.

## Implementation Checkpoint — `97d7b33`

Primitive semantik DACL kini mengambil ACE melalui live `GetAce`, menyalinnya,
kemudian mem-parsing copy secara strict. DACL harus berisi tepat satu allow ACE
type 0 dengan flags exact 3. Pointer, alignment, dan seluruh span ACE wajib berada
di dalam `AclBytesInUse`; satu ACE tersebut harus mengonsumsi seluruh area ACE
tanpa trailing byte. SID trustee diperiksa revision, subauthority count, dan
full-span sebelum `IsValidSid`; identifier authority dibaca big-endian dan setiap
subauthority little-endian lalu diserialisasi canonical. Trustee harus exact sama
dengan owner. Dua snapshot semantic immutable wajib identik. ABI tetap **29
signature** karena `GetAce` sudah berada dalam boundary awal.

Bukti root accepted: **27/27 tes**, `py_compile`, diff-check, dan review
adversarial **PASS**. Bukti masih memakai fake ABI. Mask berbasis role/policy,
process-token owner dan privilege, effective `AccessCheck`, source-tree, cache,
composition/usable attestor, serta native runtime dan race belum tersedia atau
terbukti. P15/P16/P17c/P18 tetap terbuka; tidak ada runtime/deploy/aktivasi dan
seluruh gate/payment tetap default OFF.

## Implementation Checkpoint — `9301369`

Primitive owner binding kini memakai urutan exact token SID sebelum → descriptor
snapshot pertama → descriptor snapshot kedua → token SID sesudah. Pseudo-handle
`GetCurrentProcess` tidak ditutup; real handle dari `OpenProcessToken` memakai
`TOKEN_QUERY`, dibuat non-inheritable, dan ditutup tepat sekali. Pembacaan
`TokenUser` memakai probe bounded yang harus gagal dengan error 122 lalu fill
exact. Pointer SID wajib tidak overlap dengan header dan seluruh span-nya harus
contained sebelum pemeriksaan native. SID token harus stabil, kedua owner
descriptor harus exact sama dengannya, dan output `processTokenSid` immutable.

Bukti root accepted: **30/30 tes**, `py_compile`, diff-check, dan review
adversarial **PASS**. Bukti tetap pure fake ABI. Token groups, privileges,
penolakan LocalSystem, role mask, impersonation, effective `AccessCheck`,
source-tree, cache, composition/usable attestor, serta native runtime/races belum
tersedia atau terbukti. P15/P16/P17c/P18 tetap terbuka; tidak ada
runtime/deploy/aktivasi dan seluruh gate/payment tetap default OFF.

## Implementation Checkpoint — `301a823`

Private current-process token profile kini membaca `TokenGroups` dengan probe/
fill exact, batas **256 KiB** dan maksimum **4096 group**. Layout
`ANYSIZE_ARRAY`, tabel, serta seluruh span diperiksa; tiap SID harus berada di
luar header/tabel dan tidak overlap dengan SID lain sebelum `IsValidSid` lalu
`GetLengthSid`. Semua group dan attributes dipertahankan dalam urutan native tanpa
filtering; duplikat ditolak, bukan dideduplikasi. Profil immutable dibaca sebelum dan sesudah descriptor snapshots melalui
token handle yang sama, wajib identik, dan cleanup handle tetap exact.

Bukti root accepted: **35/35 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Bukti masih pure fake ABI dan hanya untuk
current process. Ordinary second principal beserta provenance-nya, privilege
checks, pengecualian LocalSystem untuk principal ordinary, impersonation, role/
policy mask, effective `AccessCheck`, source-tree, cache, composition/usable
attestor, serta native Windows/runtime/races belum tersedia atau terbukti.
P15/P16/P17c/P18 tetap terbuka; tidak ada runtime/deploy/aktivasi dan seluruh
gate/payment tetap default OFF.

## Implementation Checkpoint — `ea9fe9c`

Private current-process token profile kini juga membaca `TokenPrivileges`
melalui probe/fill exact dengan batas **256 KiB/4096 privilege**. Inline
`ANYSIZE_ARRAY` wajib mengonsumsi buffer secara exact tanpa trailing byte.
Seluruh entry dipertahankan berurutan dan immutable sebagai tuple
`(LowPart uint32, HighPart int32, Attributes uint32)`; duplicate LUID ditolak.
Profil privilege sebelum dan sesudah descriptor snapshots harus stabil pada
token handle yang sama, dengan cleanup handle tetap exact.

Bukti root accepted: **39/39 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Ini hanya observasi current-process
berbasis pure fake ABI. Belum ada `LookupPrivilegeValue`/name mapping atau policy
untuk enabled dangerous privileges; ordinary principal/provenance, LocalSystem,
token type/restriction/impersonation, effective `AccessCheck`, source-tree,
cache/composition/usable attestor, dan native Windows/runtime belum dibuktikan.
P15/P16/P17c/P18 tetap terbuka; tidak ada aktivasi/deploy dan seluruh
gate/payment tetap default OFF.

## Implementation Checkpoint — `4f015f3`

Current-process profile kini mengobservasi tiga privilege sensitif dalam urutan
fixed: `SeBackupPrivilege`, `SeRestorePrivilege`, dan
`SeTakeOwnershipPrivilege`. Resolusi memakai `LookupPrivilegeValueW` lokal dengan
system name `None`; LUID signed/unsigned dinormalisasi exact dan duplicate
mapping ditolak. Hasil immutable mengikat name, LUID, `present`, serta `enabled`;
enabled hanya berarti `Attributes & 0x2`. Kasus absent, present-disabled, dan
present-enabled dibedakan, lalu profile sebelum/sesudah wajib stabil dengan
cleanup token handle tetap exact.

Bukti root accepted: **43/43 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Ini hanya observasi current process,
bukan rejection policy atau bukti ordinary principal. Provenance second ordinary
token, LocalSystem exclusion, token type/restriction/impersonation, effective
`AccessCheck`, source-tree, cache, composition/usable attestor, serta native
Windows/runtime tetap terbuka. P15/P16/P17c/P18 tidak berubah; tidak ada
aktivasi/deploy dan seluruh gate/payment tetap default OFF.

## Implementation Checkpoint — `95450a6`

Private current-process token profile kini juga mengobservasi `TokenType`
sebagai `TOKEN_TYPE` yang wajib exact `TokenPrimary`, serta
`TokenIsAppContainer` sebagai nilai `DWORD` raw dengan klasifikasi nonzero.
Profil sebelum/sesudah descriptor snapshots wajib stabil pada token handle yang
sama dan cleanup handle tetap exact.

Bukti root accepted: **47/47 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Ini hanya observasi current-process
berbasis pure fake ABI, bukan ordinary-principal evidence, rejection policy,
atau effective `AccessCheck`. Ordinary second-token provenance, LocalSystem,
token restriction/impersonation, source-tree/cache/composition/usable attestor,
dan native Windows/runtime tetap terbuka. P15/P16/P17c/P18 tetap terbuka;
tidak ada aktivasi/deploy dan seluruh gate/payment tetap default OFF.

## Implementation Checkpoint — `69a25c2`

`TokenRestrictedSids` menjadi authority untuk snapshot restricting SID current
process. Encoding kosong hanya sah sebagai count nol dengan panjang exact **4
byte**; bentuk kosong lain gagal tertutup. Bentuk nonempty memakai parser
berbatas **256 KiB/4096 SID**, mewajibkan attributes nol, dan mempertahankan
duplicate SID yang berada pada span berbeda tanpa deduplikasi.

Bukti root accepted: **51/51 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Ini hanya observasi current-process
berbasis pure fake ABI; bukan bukti `IsTokenRestricted`, general unrestricted,
ordinary principal/policy, atau effective `AccessCheck`. Ordinary second-token
provenance, LocalSystem, token restriction/impersonation lainnya, source-tree/
cache/composition/usable attestor, dan native Windows/runtime tetap terbuka.
P15/P16/P17c/P18 tetap terbuka; tidak ada aktivasi/deploy dan seluruh
gate/payment tetap default OFF.

## Implementation Checkpoint — `a5e3c98`

`IsTokenRestricted` kini menjadi corroboration saja terhadap authority
`TokenRestrictedSids` (class 11). Hasil `FALSE` hanya sah setelah
`SetLastError(0)` dan pembacaan immediate `GetLastError()` yang tetap nol;
hasil nonzero berarti `true`. Nilai corroboration wajib parity dengan ada atau
tidaknya restricting SID pada snapshot class 11.

Bukti root accepted: **53/53 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Ini tetap observasi current-process
berbasis pure fake ABI; bukan bukti general unrestricted, ordinary principal/
policy, atau effective `AccessCheck`. Ordinary second-token provenance,
LocalSystem, token restriction/impersonation lainnya, source-tree/cache/
composition/usable attestor, dan native Windows/runtime tetap terbuka.
P15/P16/P17c/P18 tetap terbuka; tidak ada aktivasi/deploy dan seluruh
gate/payment tetap default OFF.

## Implementation Checkpoint — `bbcd5b7`

Current-process `TokenUser` kini menolak identitas LocalSystem exact
`S-1-5-18` sebelum descriptor snapshots. Pemeriksaan ini identity-only; group
SID atau restricting SID tidak diperlakukan sebagai `TokenUser`.

Bukti root accepted: **55/55 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Bukti tetap pure fake dan hanya
mengecualikan LocalSystem sebagai identitas current process; ini tidak
membuktikan ordinary second principal beserta provenance-nya, policy, atau
effective `AccessCheck`. Token restriction/impersonation lainnya, source-tree/
cache/composition/usable attestor, dan native Windows/runtime tetap terbuka.
P15/P16/P17c/P18 tetap terbuka; tidak ada aktivasi/deploy dan seluruh
gate/payment tetap default OFF.

## Decision Refinement — ordinary access provider

Kontrak principal ordinary kedua dan native `AccessCheck` dipisahkan ke
[ADR-018](0018-windows-ordinary-access-provider.md). Provider internal tersebut,
bukan launcher/coordinator atau caller, menjadi pemilik independent externally
provisioned token, derivation impersonation token, raw handle, dan evidence
one-shot. ADR-018 accepted hanya untuk contract/pure-fake testing; native
provider wajib memparse kedua live descriptor capture secara independen dan
menghasilkan descriptor `policySatisfied`, bukan menyalinnya dari request.
Outer ADR-017 `policySatisfied` hanya membuktikan descriptor policy; ordinary
principal denial adalah admission gate terpisah dari ADR-018. Provisioning,
integration, dan runtime evidence tetap terbuka.
