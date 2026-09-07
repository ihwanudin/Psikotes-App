# ADR-023: Authority root persiapan dan lease handle-relative checkout

- Status: Accepted-for-contract
- Tanggal: 2026-09-08

## Konteks

ADR-021 menetapkan bahwa satu fresh run root harus lahir dengan security
descriptor restriktif pada operasi create yang sama, lalu lease lifecycle
ADR-016 harus menjadi child dan write pertama. Implementasi ADR-016 saat ini
membuka lease melalui path setelah run directory tersedia. Bentuk itu tidak
dapat menutup interval create-lalu-`SetACL`, path reopen, replacement namespace,
atau reparse race pada bootstrap kandidat.

Pada 2026-09-08 pengguna menyetujui default aman: pembuatan root dan bootstrap
lease dimiliki authority native yang sempit. ADR ini menerima kontrak desain
authority tersebut. Ia tidak menerima implementasi, native execution, runtime,
candidate build, browser/service launch, deployment, atau activation.

Review setelah penerimaan awal menemukan tiga ambiguity yang keputusan ini
hilangkan. Pertama, “owner/admin” tidak boleh dibaca sebagai dua ACE: root
preparation mengikuti exact one-ACE `PROCESS_TOKEN_USER` policy target `run`
ADR-017. Administrator tetap recovery authority di luar threat model, bukan
trustee DACL. Kedua, preparation process bukan broker ordinary ADR-019 atau
participant browser; ia adalah dedicated, restricted-purpose native service.
Ketiga, tidak ada owner handoff: final supervisor/runtime berjalan sebagai satu
child yang disahkan di bawah primary principal dedicated yang sama. Dengan itu
fresh final ADR-017 melihat `PROCESS_TOKEN_USER` yang identik dengan owner/trustee
root, tanpa interval ubah-owner atau ubah-DACL.

Threat model tetap writer ordinary/non-admin. Administrator, kernel compromise,
dan offline disk rollback berada di luar jaminan software ini dan tetap mengikuti
batas ADR-021.

## Keputusan

### 1. Authority dan interface privat

Satu `PreparationRootAuthority` privat menjadi satu-satunya komponen yang boleh
membuat root persiapan dan child bootstrap-nya. Authority terikat pada exact
dedicated service instance saat construction; service/account/manifest tidak
menjadi parameter operasi. Interface konseptual exact adalah:

```text
create_root(
  *,
  parent: PinnedPreparationParentCapability,
  authorization: VerifiedPreparationAuthorizationCapability
) -> HeldPreparationRootCapability

acquire_first_lease(
  *,
  root: HeldPreparationRootCapability,
  lease_request: VerifiedLeaseRequestCapability
) -> HeldPreparationLeaseCapability

create_descendant(
  *,
  lease: HeldPreparationLeaseCapability,
  relative_name: CanonicalRelativeName,
  write_authority: BoundPreparationWriteCapability
) -> HeldDescendantCapability
```

Semua nama, destination, generation, parent identity, owner SID, security policy,
session, dan lease binding berasal dari capability terverifikasi. Caller tidak
boleh memasukkan raw handle, alternate parent/path, security descriptor, SID,
mask, policy, share mode, creation disposition, atau fallback callback.

Capability berikut opaque, nonserializable, noncopyable, nonpickleable, dan
tidak mengekspos handle atau pointer:

- `PinnedPreparationParentCapability` dimiliki native authority dan menahan exact
  validated parent handle, canonical/final path, no-reparse state, serta
  `(volumeSerial,fileId)`;
- `VerifiedPreparationAuthorizationCapability` adalah one-shot authority hasil
  verifikasi ADR-021 dan mengikat exact destination leaf, generation, security
  policy, artifact set, serta revocation state;
- `PinnedPreparationServiceCapability` dibuat internal dari manifest
  admin-owned serta live SCM/process/token capture dan tidak pernah diterima dari
  caller; ia mengikat exact service instance, account SID, service SID, PID/start
  identity, binary identity/digest, primary token, serta token policy;
- `HeldPreparationRootCapability` dimiliki authority sejak native create sampai
  handoff atau terminal cleanup dan menahan retained parent/root handles, root
  identity, serta evidence descriptor internal;
- `VerifiedLeaseRequestCapability` mengikat exact session/generation dan schema
  lease ADR-016 tanpa memberi caller pilihan nama/path;
- `HeldPreparationLeaseCapability` dimiliki authority sampai lifecycle terminal,
  menahan root dan lease handles serta lock, dan menjadi satu-satunya authority
  untuk descendant creation; dan
- `BoundPreparationWriteCapability` berasal dari ordered ADR-021 lifecycle dan
  tidak dapat dipakai pada root, lease, session, atau generation lain.

Capability bersifat linear dan one-shot. Percobaan pertama, termasuk kegagalan,
mengonsumsi transisi terkait. Tidak ada reset, retry, rearm, conversion ke path,
atau peminjaman raw handle. Object identity capability dipin di seluruh operasi;
serialized digest atau supplied object yang tampak sama tidak dapat menggantinya.

### 2. Dedicated preparation dan final-supervisor principal

Preparation authority berjalan sebagai single-instance
`SERVICE_WIN32_OWN_PROCESS` demand-only di bawah dedicated local non-admin service
account. Exact account yang sama dipakai hanya oleh service persiapan dan satu
final supervisor/runtime child untuk run yang sedang dipegang. Account tetap
berbeda dari current coordinator, ordinary principal/broker ADR-018/019,
participant browser identity, LocalSystem, LocalService, NetworkService, virtual
account, dan setiap account service lain. Tidak ada application process lain,
helper, scheduled task, interactive shell, atau concurrent run yang boleh logon
sebagai account tersebut.

Provisioning admin-owned wajib menolak interactive, remote-interactive, network,
batch, scheduled-task, desktop, share, delegation, SPN, dan outbound-network use.
Account hanya memiliki logon grant `SeServiceLogonRight` serta exact deny rights
untuk interactive, remote-interactive, network, dan batch logon; conflicting
local/domain policy atau inability to prove effective rights menolak readiness.
Service memakai unique `SERVICE_SID_TYPE_UNRESTRICTED` SID untuk mengikat live
instance; ini bukan `SERVICE_SID_TYPE_RESTRICTED` token dan service SID bukan
owner/trustee root. Exact root owner/trustee adalah `TokenUser` service account.

Token readiness sebelum authority tersedia dan sebelum/sesudah setiap operasi
wajib membuktikan:

- primary `TokenUser` exact account SID dari admin-owned manifest dan berbeda
  dari seluruh application/ordinary/built-in SID;
- exact service SID hadir dengan SCM attributes
  `SE_GROUP_ENABLED_BY_DEFAULT|SE_GROUP_OWNER (0x0000000A)`, serta process
  image/service/PID/start/token identity stabil;
- account bukan anggota Administrators atau privileged group, tidak restricted,
  bukan AppContainer, dan exact ordered groups/attributes cocok manifest; serta
- raw `TokenPrivileges` hanya memuat `SeChangeNotifyPrivilege` dengan exact
  accepted attributes. `SeImpersonatePrivilege`, `SeBackupPrivilege`,
  `SeRestorePrivilege`, `SeTakeOwnershipPrivilege`, `SeDebugPrivilege`,
  `SeTcbPrivilege`, `SeAssignPrimaryTokenPrivilege`, dan privilege lain wajib
  absent, bukan sekadar disabled.

Parent DACL yang dipin memberi exact account tersebut hanya hak yang dibutuhkan
untuk membuat satu authorized child. Tidak ada credential, token, service
selector, service-control right, atau capability constructor pada aplikasi.
Sebelum root create sampai finalization, census wajib berisi tepat satu process
untuk account tersebut: service authority yang dipin. Setelah finalization,
composition admission, dan fresh pre-launch validation, authority boleh membuat
tepat satu supervisor child dalam suspended state. Child memakai primary token
dengan exact `TokenUser`, group/attribute, privilege, restriction, AppContainer,
authentication, dan token-type policy yang sama; hanya per-process/token identity
yang secara kontrak memang baru boleh berbeda dan wajib diikat ke handoff
evidence. Setelah child tervalidasi dan handoff berhasil, census wajib berisi
tepat service parent dan satu supervisor child dengan exact parent/child PID,
creation-time, image, token, session, run, dan generation relation. Process ketiga,
child tak dikenal, token/profile drift, atau process account tersebut sebelum
authorized launch menolak dan membuat lifecycle terminal.

Supervisor child bukan preparation authority: ia tidak dapat membuat root atau
lease, menerbitkan capability, mengulang finalization, atau meluncurkan sibling.
Participant browser dan ordinary broker tidak berjalan dengan token dedicated
ini. Browser menerima hanya endpoint/public TLS evidence yang telah disahkan;
broker ordinary tetap memiliki principal dan transport terpisah ADR-018/019.

### 3. Atomic protected root

Authority harus memvalidasi ulang retained parent handle dan identity tepat
sebelum create. Root wajib fresh; existing object, collision, ambiguity, atau
replacement menolak. Exact user-mode ABI adalah `NtCreateFile` dari pinned
`ntdll.dll`/`winternl.h`; tidak ada `CreateDirectoryW`, `CreateFileW`, path-based,
atau alternate-API fallback.

Satu pemanggilan `NtCreateFile` sekaligus membuat directory, menerapkan security
descriptor, dan mengembalikan held handle. Parameter normatifnya:

- `DesiredAccess=FILE_ALL_ACCESS (0x001F01FF)`;
- `OBJECT_ATTRIBUTES.RootDirectory` adalah retained parent handle;
- `ObjectName` adalah tepat satu canonical leaf component hasil authorization;
- `OBJECT_ATTRIBUTES.Attributes=OBJ_CASE_INSENSITIVE|OBJ_DONT_REPARSE`
  (`0x00000040|0x00001000`), tanpa `OBJ_INHERIT`, `OBJ_OPENIF`, atau absolute
  object name;
- `OBJECT_ATTRIBUTES.SecurityDescriptor` menunjuk exact self-relative descriptor
  policy di bawah dan `SecurityQualityOfService=NULL`;
- `AllocationSize=NULL`, `FileAttributes=FILE_ATTRIBUTE_NORMAL`;
- `ShareAccess=FILE_SHARE_READ|FILE_SHARE_WRITE (0x00000003)`, tanpa
  `FILE_SHARE_DELETE`;
- `CreateDisposition=FILE_CREATE (0x00000002)`;
- `CreateOptions=FILE_DIRECTORY_FILE|FILE_SYNCHRONOUS_IO_NONALERT`
  (`0x00000001|0x00000020`); dan
- `EaBuffer=NULL`, `EaLength=0`.

Hanya exact `STATUS_SUCCESS`, non-NULL/non-invalid returned handle,
`IO_STATUS_BLOCK.Status=STATUS_SUCCESS`, dan
`IO_STATUS_BLOCK.Information=FILE_CREATED (0x00000002)` diterima. Symbol, module,
ABI widths/layout/signature, constants, supported Windows build/filesystem, dan
input/output buffers dipin serta divalidasi pre/post. Handle ditutup dengan exact
checked `CloseHandle` dan tidak diwariskan. Nilai NTSTATUS/native error hanya
menjadi fixed redacted refusal.

Urutan `CreateDirectory` lalu `SetACL`, inherited-default ACL sementara,
post-create repair, call kedua untuk memperoleh handle, atau library yang membuka
ulang name dilarang. Jika exact ABI/support matrix tersebut tidak dapat dibuktikan,
operation tetap unavailable; kontrak tidak turun ke fallback.

Default security descriptor root adalah self-relative dan canonical:

- owner exact `PROCESS_TOKEN_USER` dari dedicated preparation service primary
  token yang telah dipin;
- DACL present, non-NULL, protected, dan tidak auto-inherited;
- tidak ada inherited ACE;
- tepat satu non-inherited `ACCESS_ALLOWED_ACE` untuk SID owner yang sama, dengan
  inheritance flags object-and-container `3` dan exact directory full-control
  mask `0x001F01FF`; dan
- tidak ada ACE, trustee, deny/allow variant, generic/unmapped bit, atau alternate
  owner lain.

Ini exact semantics target `run` pada canonical ADR-017 policy digest
`a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb`;
ADR ini tidak mengubah policy ADR-017 atau menambah Administrators ACE. Ia
menyempitkan kata “owner-only” ADR-021/022 menjadi bentuk biner/semantik tersebut.
Implementasi preparation memakai schema/domain terpisah
`oncam.checkout.preparation-root-evidence.v1` karena final `configBinding` belum
ada; evidence itu wajib mengikat canonical ADR-017 policy digest, exact live
descriptor bytes/semantics, service/token identity, parent/root identity,
authorization, dan lease-first state. Evidence preparation tidak dapat
menggantikan final ADR-017 request/evidence/admission.

Segera setelah create dan sebelum child/write apa pun, authority menahan root
handle non-inheritable dengan sharing yang menahan rename/delete. Melalui handle
yang sama, authority memvalidasi dua kali dan mewajibkan hasil identik untuk:

- object type directory, no-reparse state, final canonical path, dan exact parent;
- volume/file identity dan absence of identity alias;
- owner, group metadata yang diperlukan, full descriptor/control, DACL, ACE, dan
  policy digest; serta
- effective access current preparation principal dan denial ordinary principal
  sesuai preparation-ACL policy yang diterima terpisah.

Validation atau access evidence yang masih Proposed tidak boleh diganti oleh
supplied booleans, pure fixtures, atau hash saja. Sampai preparation-ACL
capability dan native evidence diterima, operasi ini tidak implementable.

### 4. Lease sebagai child dan write pertama

Setelah root terverifikasi, authority membuktikan root kosong. Satu-satunya
operasi child pertama dan write pertama adalah pembuatan lalu perolehan lock
exclusive nonblocking pada exact file `.checkout-coordinator.lease`.

Lease dibuat/open secara handle-relative di bawah retained root handle. Tidak ada
absolute path, current-working-directory resolution, path reopen, symlink/junction,
reparse traversal, device alias, alternate stream, atau fallback ke implementasi
ADR-016 path-based. File harus regular, non-reparse, non-inheritable, dan identity,
final location, content, generation, serta lock ownership dipin sebelum lifecycle
maju. Stable lease tidak dihapus pada cleanup, sesuai ADR-016.

Schema dan semantic lease ADR-016 tetap berlaku. ADR ini hanya menerima extension
bootstrap handle-relative dan ordering lease-first; ia tidak mengaktifkan
implementasi ADR-016 yang ada sebagai preparation authority.

### 5. Semua descendant tetap handle-relative

Setelah lease dipegang, marker incomplete, skeleton, source, vendor, runtime
configuration, TLS material, manifest, dan output hanya boleh dibuat atau dibuka
melalui retained root/descendant handles. Setiap komponen relative harus canonical,
bounded, dan menolak separator terselubung, `.`/`..`, trailing dot/space,
case-fold collision, reserved device name, alternate data stream, symlink,
junction, mount point, reparse point, dan hard-link/identity alias yang tidak
diotorisasi.

Setiap create/open melakukan pre/post validation terhadap exact parent handle,
target handle, final path, no-reparse state, dan object identity. Identity target
yang berubah atau digunakan ulang pada role berbeda menolak. Setelah root dibuat,
tidak ada operasi yang boleh membuka ulang root atau descendant melalui string
path, mengikuti namespace ambient, atau turun ke API/fallback yang tidak dapat
membuktikan containment handle-relative.

Capability descendant tidak memberi authority baru. Ia hanya hidup selama exact
root dan lease capability tetap held dan hanya dapat digunakan oleh phase,
session, generation, role, serta write authority yang terikat.

### 6. Handoff ke final supervisor tanpa owner change

Tidak ada pergantian owner, DACL, trustee, atau principal pada handoff. Setelah
finalization dan composition admission, service membuat exact supervisor child
suspended dengan token dedicated yang memenuhi profil di atas. Tidak ada root,
lease, descendant, credential, token, atau ambient inheritable handle pada child.
`bInheritHandles=FALSE` dan inherited-handle list kosong wajib dibuktikan.

Handle minimum yang memang diperlukan supervisor diduplikasi secara eksplisit
ke exact suspended target process memakai versioned private handoff capability;
setiap source handle, target-process handle, duplicated handle, access mask,
object type, identity, non-reparse state, dan lifecycle binding divalidasi
pre/post. Duplicated handles tidak memberi create/write authority setelah
finalization kecuali operasi runtime sempit yang dinyatakan exact oleh capability
terpisah. Child tidak menerima raw path sebagai authority dan tidak boleh membuka
ulang root atau descendant melalui path. Kebutuhan library yang hanya menerima
path tetap blocker sampai ada adapter/capability dan native evidence terpisah;
tidak ada fallback path-open.

Untuk secure candidate lifecycle, keputusan ini mempersempit ADR-022 yang masih
menggambarkan retained-parent plus child path-open acknowledgment: child-open
berbasis nama itu tidak cukup dan tidak boleh dipakai. Demikian pula, ownership
lease ADR-016 harus dipertahankan atau dipindahkan melalui exact handle-bound
protocol yang diterima tanpa unlock gap; kesamaan account bukan pengganti bukti
lock/handle ownership. Sampai adapter TLS dan transfer/retention lease tersebut
diterima secara native, supervisor tidak dapat diluncurkan.

Sebelum child dilanjutkan, ia melakukan fresh final ADR-017 anchor terhadap exact
coordinator/run/source objects melalui handle-bound internal composition dan
token current-process miliknya. Karena child `TokenUser` sama dengan owner/trustee
yang tidak pernah berubah, exact one-owner policy ADR-017 dapat lulus. Execution
boundary diulang tepat sebelum supervisor berjalan. Kedua boundary juga mengikat
service-parent/child relation, duplicated-handle identities, dan absence of
inherited handles. Failure menutup duplicated handles, menghentikan suspended
child, dan membuat run terminal; service tidak mencoba launch atau handoff ulang.

### 7. Lifecycle wajib

Urutan berikut tidak dapat dipertukarkan:

1. autentikasi artifact statis dan revalidasi protected revocation/high-water
   state sesuai ADR-021, seluruhnya read-only;
2. validasi dan consume one-shot preparation authorization;
3. validasi dedicated preparation service/token dan retained parent capability;
4. atomic `NtCreateFile` fresh protected root, retain returned handle, lalu
   complete root identity,
   descriptor, no-reparse, dan access validation;
5. buktikan root kosong, lalu create/acquire ADR-016 lease handle-relative sebagai
   child/write pertama;
6. buat marker incomplete dan skeleton melalui lease-bound handle-relative API;
7. lakukan separately accepted preparation-ACL attestation;
8. jalankan ADR-022 TLS materialization hanya setelah preparation ACL diterima;
9. finalisasi source/vendor/config/manifest dan seluruh binding ADR-021;
10. issue dan verify final composition admission;
11. create exact supervisor child suspended di bawah dedicated principal yang
    sama, buktikan process/token graph, lalu duplicate hanya exact runtime handles
    melalui private handoff capability tanpa path reopen atau inheritance;
12. di dalam child, lakukan fresh final ADR-017 anchor dan execution admission
    dengan current `PROCESS_TOKEN_USER` yang sama dengan root owner/trustee; lalu
13. resume supervisor hanya setelah seluruh state final dan handoff tervalidasi.

Root capability berpindah monotonic melalui `fresh-authorized`, `root-held`,
`lease-held`, `preparing`, `finalized-handoff`, atau `terminal`. Tidak ada jalur
dari `terminal`/`finalized-handoff` kembali ke state writable sebelumnya.

### 8. Failure dan cleanup

Authority menolak tanpa fallback pada sekurang-kurangnya kondisi berikut:

- parent/root/child path, type, identity, final path, descriptor, policy, token,
  generation, session, lease, atau capability tidak exact;
- root sudah ada, root tidak kosong sebelum lease, atau operasi apa pun mendahului
  lease;
- preparation account/process/token tidak dedicated/singleton/stabil, berbagi SID
  dengan application/ordinary principal, atau privilege/group policy berbeda;
- final supervisor tidak memakai exact dedicated principal/profile, process graph
  bukan tepat service-parent plus satu authorized child, inherited handle ada,
  duplicated-handle identity/access berbeda, atau child mencoba path reopen;
- `NtCreateFile` ABI/constant/support berbeda, returned handle/status/information
  tidak exact, atau implementasi mencoba alternate API;
- atomic security descriptor tidak dapat diterapkan pada create yang sama;
- handle inheritance, share mode, reparse, link, alias, collision, atau path reopen
  terdeteksi;
- lock tidak langsung diperoleh, lease berubah, atau proses/capability berbeda
  mencoba memakai lease;
- write/attestation/TLS/final binding terjadi di luar urutan; atau
- dependency Proposed atau bukti native yang belum diterima diperlakukan sebagai
  authority aktif.

Cleanup berjalan best-effort dalam urutan kebalikan ownership: terminate/wait
suspended child bila handoff belum committed, close duplicated target handles,
close source/runtime descendant handles, preparation/TLS child handles, lease
lock dan lease handle, root handle, lalu parent handle. Primary exception,
termasuk `KeyboardInterrupt` dan `SystemExit`, selalu menang; cleanup failure hanya
menghasilkan fixed redacted terminal state dan tidak mengizinkan retry atau
launch.

Cleanup tidak boleh melakukan recursive delete melalui reconstructed path. Stable
lease tidak di-unlink. Root incomplete atau material yang tidak dapat dihapus
melalui retained exact handles tetap quarantined/incomplete untuk recovery authority
yang diterima terpisah. ADR ini tidak mengklaim crash, reboot, power-loss,
filesystem durability, atau deletion semantics telah terbukti.

### 9. Privacy dan observability

Raw handle, SID, absolute path, descriptor bytes, ACL, native error, token/process
identity, private-key metadata, artifact content, dan credential tidak boleh masuk
argv, environment, config publik, stdout/stderr, exception, log, atau evidence
pengguna. Log hanya memakai fixed status/refusal code, opaque run/generation
reference yang diizinkan, dan coarse phase. Error ordinary dipetakan ke satu fixed
redacted preparation-root refusal; native message tidak diteruskan.

## Acceptance evidence

Pure/synthetic tests kelak hanya dapat membuktikan:

- exact capability type/ownership, one-shot transitions, lease-first ordering,
  no caller-selected policy/path/handle, dan immutable narrow outputs;
- exact service-parent/supervisor-child state machine, same-principal token-profile
  relation, empty inheritance set, dan one-shot handoff ordering;
- refusal terhadap wrong phase/session/generation, alias, path fallback intent,
  duplicate operation, mutation, retry, serta forged capability;
- reverse cleanup order dan primary `BaseException` precedence; dan
- static absence of path reopen, broad API fallback, secret output, serta gate
  activation.

Pure evidence tidak membuktikan atomic Windows creation, ACL efficacy,
handle-relative containment, lock behavior, identity continuity, race resistance,
crash cleanup, atau durability.

Native acceptance pada disposable Windows host wajib membuktikan sekurang-kurangnya:

- root security descriptor berlaku dari operasi create pertama, tanpa observable
  permissive interval dan tanpa create-then-ACL call;
- exact one-owner-ACE protected DACL, current-principal access, ordinary-principal
  denial, dan double descriptor/token stability;
- retained parent/root handle, final path, identity, noninheritance, share-mode
  rename/delete resistance, dan reparse/link refusal di bawah adversarial races;
- root kosong sebelum lease dan exact lease merupakan child/write pertama;
- pre-launch census tepat service parent plus satu supervisor child, exact
  same-principal token/profile relation, zero inherited handles, bounded explicit
  handle duplication, dan refusal terhadap third process/path reopen;
- cross-process exclusive nonblocking lock, stable no-unlink lease, process-exit
  release, stale/replay refusal, serta exact ADR-016 schema/content;
- every descendant operation truly relative to retained handles, with instrumented
  proof that no root/descendant path reopen or ambient fallback occurs;
- normal, ordinary-exception, `KeyboardInterrupt`, `SystemExit`, crash, partial
  create/write, cleanup failure, dan reverse cleanup behavior; dan
- independent adversarial review tanpa P1/P2.

Native evidence juga wajib membuktikan exact pinned `NtCreateFile` user-mode ABI,
`RootDirectory` relative resolution, atomic descriptor application dan handle
return pada filesystem/build yang disahkan, serta failure tanpa fallback pada
unsupported status/semantics.

Acceptance juga memerlukan versioned exact preparation-root/ACL policy artifact,
native API/ABI dan suspended-child/handle-handoff design, refactor ADR-016 dan
builder/supervisor composition, serta bounded recovery/retirement procedure.
Tidak ada pure test yang dapat menggantikan bukti tersebut.

## Dependency dan urutan implementasi

1. Pertahankan artifact/verifier/revocation/high-water dan preparation authorization
   ADR-021 sebagai prerequisite; structural codecs saja bukan authority.
2. Provision dan terima dedicated preparation service/account manifest serta
   native evidence untuk exact token/group/privilege/singleton policy.
3. Terima exact pinned `NtCreateFile` ABI/support matrix, retained handle model,
   preparation evidence schema, dan ordinary-principal preparation-ACL
   attestation.
4. Refactor ADR-016 menjadi bootstrap lease handle-relative yang hanya menerima
   `HeldPreparationRootCapability`; implementation path-based tetap unusable.
5. Refactor builder/supervisor agar seluruh child I/O menggunakan lease-bound
   capability dan lifecycle di atas.
6. Implementasikan exact suspended supervisor creation, same-principal token
   profile/census, explicit non-inherited handle duplication, dan child-side
   ADR-017 boundary tanpa path reopen.
7. Baru kemudian implementasikan ADR-022 native materialization, final ADR-021
   composition admission, dan fresh ADR-017 admission.
8. Jalankan pure, native disposable-host, crash/race, browser/service, dan full
   candidate acceptance secara terpisah sebelum mempertimbangkan activation.

## Alternatif yang dipertimbangkan

### Create directory lalu memasang ACL

Ditolak. Ada interval ketika object dapat diwarisi atau diakses dengan ACL yang
belum disahkan.

### `CreateDirectoryW` lalu membuka handle

Ditolak walaupun `SECURITY_ATTRIBUTES` dapat memasang descriptor pada create.
API tersebut tidak mengembalikan directory handle, sehingga call berikut untuk
membuka name menyisakan replacement/rename interval. Exact `NtCreateFile`
relative-handle ABI di atas dipilih karena create menghasilkan held handle yang
sama.

### Memakai ADR-016 path-based tanpa perubahan

Ditolak. Root dan lease dapat dibuka ulang melalui namespace yang berubah dan
tidak membuktikan lease sebagai first child/write dari retained root.

### Memakai root yang telah ada atau shared staging directory

Ditolak. Freshness, emptiness, ownership, prior content, dan identity tidak dapat
diikat ke satu preparation authorization one-shot.

### Membuka path ulang untuk library atau proses child yang memerlukannya

Ditolak. Komponen harus menerima capability/handle contract yang disahkan atau
persiapan berhenti; compatibility fallback bukan authority.

### Mengganti owner/DACL dari preparation service ke supervisor lain

Ditolak. Handoff security descriptor membuka interval policy dan memerlukan
versioned evidence baru untuk owner, trustee, handle, token, dan denial. Satu
dedicated principal untuk service authority dan exact supervisor child membuat
final `PROCESS_TOKEN_USER` sama dengan owner/trustee ADR-017 tanpa mutation.

### Menjalankan browser atau broker sebagai dedicated principal

Ditolak. Kesamaan principal hanya mencakup preparation service dan satu final
supervisor/runtime child. Participant browser dan broker ordinary tetap berada di
batas identity/transport terpisah dan tidak menerima handle atau credential root.

### Menghapus dan membuat ulang lease/root saat gagal

Ditolak. Ini merusak stable lease semantics, membuka race, dan dapat menyamarkan
partial state. Failure menghasilkan terminal incomplete state.

### Menganggap pure fake atau structural digest sebagai bukti native

Ditolak. Fixture hanya menguji kontrak dan tidak membuktikan ACL, handle, lock,
filesystem, process, atau race semantics.

## Konsekuensi

- Atomic protected root dan lease-first menghilangkan interval permissive yang
  memang tidak dapat diperbaiki oleh attestation setelah fakta.
- Seluruh candidate I/O harus direfaktor dari path-centric menjadi capability dan
  handle-relative; current monolithic builder serta lease path-based tetap
  unusable untuk jalur yang disahkan.
- Retained handles mempersempit namespace race tetapi menambah lifecycle,
  cleanup, crash-recovery, dan native-test complexity.
- Dedicated preparation-service provisioning/evidence, preparation-ACL
  authority, native API/ABI, same-principal supervisor launch/handle-handoff,
  ordinary-principal evidence,
  recovery authority, ADR-022 implementation, serta final composition masih
  dependency terpisah.
- Status ini tidak menerima atau menjalankan code, native call, candidate,
  browser, service, database, environment/config, network, payment, deployment,
  atau activation.
- P15, P16, P17c, dan P18 tetap terbuka. Seluruh checkout/payment gate tetap
  default OFF dan checklist/progress tidak berubah.

## References

- [ADR-016: Candidate-global lifecycle lease](0016-checkout-candidate-lifecycle-lease.md)
- [ADR-017: Windows checkout ACL attestation](0017-checkout-windows-acl-attestation.md)
- [ADR-018: Ordinary Windows access provider](0018-windows-ordinary-access-provider.md)
- [ADR-021: Release preparation authority](0021-checkout-release-preparation-authority.md)
- [ADR-022: Synthetic TLS material authority](0022-checkout-synthetic-tls-material-authority.md)
- [Microsoft `CreateDirectoryW`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createdirectoryw)
- [Microsoft user-mode `NtCreateFile`](https://learn.microsoft.com/en-us/windows/win32/api/winternl/nf-winternl-ntcreatefile)
- [Microsoft `OBJECT_ATTRIBUTES`](https://learn.microsoft.com/en-us/windows/win32/api/ntdef/ns-ntdef-_object_attributes)
- [Microsoft `SERVICE_SID_INFO`](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/ns-winsvc-service_sid_info)
- [Microsoft `SERVICE_REQUIRED_PRIVILEGES_INFO`](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/ns-winsvc-service_required_privileges_infow)
- [Microsoft `SECURITY_ATTRIBUTES`](https://learn.microsoft.com/en-us/previous-versions/windows/desktop/legacy/aa379560(v=vs.85))
- [Microsoft Security Descriptors](https://learn.microsoft.com/en-us/windows/win32/secauthz/security-descriptors)
- [Microsoft DACLs and ACEs](https://learn.microsoft.com/en-us/windows/win32/secauthz/dacls-and-aces)
- [Microsoft `CreateFileW`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilew)
- [Microsoft `LockFileEx`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-lockfileex)
- [Microsoft `GetFinalPathNameByHandleW`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-getfinalpathnamebyhandlew)
- [Microsoft `GetFileInformationByHandleEx`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-getfileinformationbyhandleex)
- [Microsoft Reparse Points](https://learn.microsoft.com/en-us/windows/win32/fileio/reparse-points)
- [Microsoft Handle Inheritance](https://learn.microsoft.com/en-us/windows/win32/sysinfo/handle-inheritance)
