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

Threat model tetap writer ordinary/non-admin. Administrator, kernel compromise,
dan offline disk rollback berada di luar jaminan software ini dan tetap mengikuti
batas ADR-021.

## Keputusan

### 1. Authority dan interface privat

Satu `PreparationRootAuthority` privat menjadi satu-satunya komponen yang boleh
membuat root persiapan dan child bootstrap-nya. Interface konseptual exact adalah:

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

### 2. Atomic protected root

Authority harus memvalidasi ulang retained parent handle dan identity tepat
sebelum create. Root wajib fresh; existing object, collision, ambiguity, atau
replacement menolak. Directory dibuat oleh satu native create operation yang
sekaligus menerima exact security descriptor. Urutan `CreateDirectory` lalu
`SetACL`, inherited-default ACL sementara, atau post-create repair dilarang.

Default security descriptor root adalah self-relative dan canonical:

- owner exact SID current authorized preparation process yang telah dipin;
- DACL present, non-NULL, protected, dan tidak auto-inherited;
- tidak ada inherited ACE;
- tepat dua non-inherited `ACCESS_ALLOWED_ACE` berurutan untuk owner dan
  `BUILTIN\\Administrators` (`S-1-5-32-544`), dengan inheritance flags
  object-and-container `3` dan exact directory full-control mask `0x001F01FF`;
  dan
- tidak ada ACE, trustee, deny/allow variant, generic/unmapped bit, atau alternate
  owner lain.

Exact mask bytes dan canonical descriptor bytes bukan caller input. Implementasi
kelak harus mengikat kontrak tersebut ke satu versioned policy artifact dan
menolak bila policy artifact, generated descriptor, atau live descriptor berbeda
byte/semantik.

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

### 3. Lease sebagai child dan write pertama

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

### 4. Semua descendant tetap handle-relative

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

### 5. Lifecycle wajib

Urutan berikut tidak dapat dipertukarkan:

1. autentikasi artifact statis dan revalidasi protected revocation/high-water
   state sesuai ADR-021, seluruhnya read-only;
2. validasi dan consume one-shot preparation authorization;
3. validasi retained parent capability;
4. atomic create fresh protected root, retain handle, lalu complete root identity,
   descriptor, no-reparse, dan access validation;
5. buktikan root kosong, lalu create/acquire ADR-016 lease handle-relative sebagai
   child/write pertama;
6. buat marker incomplete dan skeleton melalui lease-bound handle-relative API;
7. lakukan separately accepted preparation-ACL attestation;
8. jalankan ADR-022 TLS materialization hanya setelah preparation ACL diterima;
9. finalisasi source/vendor/config/manifest dan seluruh binding ADR-021;
10. issue dan verify final composition admission;
11. lakukan fresh final ADR-017 anchor dan execution admission; lalu
12. handoff hanya setelah seluruh state final tervalidasi.

Root capability berpindah monotonic melalui `fresh-authorized`, `root-held`,
`lease-held`, `preparing`, `finalized-handoff`, atau `terminal`. Tidak ada jalur
dari `terminal`/`finalized-handoff` kembali ke state writable sebelumnya.

### 6. Failure dan cleanup

Authority menolak tanpa fallback pada sekurang-kurangnya kondisi berikut:

- parent/root/child path, type, identity, final path, descriptor, policy, token,
  generation, session, lease, atau capability tidak exact;
- root sudah ada, root tidak kosong sebelum lease, atau operasi apa pun mendahului
  lease;
- atomic security descriptor tidak dapat diterapkan pada create yang sama;
- handle inheritance, share mode, reparse, link, alias, collision, atau path reopen
  terdeteksi;
- lock tidak langsung diperoleh, lease berubah, atau proses/capability berbeda
  mencoba memakai lease;
- write/attestation/TLS/final binding terjadi di luar urutan; atau
- dependency Proposed atau bukti native yang belum diterima diperlakukan sebagai
  authority aktif.

Cleanup berjalan best-effort dalam urutan kebalikan ownership: descendant/private
material, preparation/TLS child handles, lease lock dan lease handle, root handle,
lalu parent handle. Primary exception, termasuk `KeyboardInterrupt` dan
`SystemExit`, selalu menang; cleanup failure hanya menghasilkan fixed redacted
terminal state dan tidak mengizinkan retry atau launch.

Cleanup tidak boleh melakukan recursive delete melalui reconstructed path. Stable
lease tidak di-unlink. Root incomplete atau material yang tidak dapat dihapus
melalui retained exact handles tetap quarantined/incomplete untuk recovery authority
yang diterima terpisah. ADR ini tidak mengklaim crash, reboot, power-loss,
filesystem durability, atau deletion semantics telah terbukti.

### 7. Privacy dan observability

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
- exact owner/admin protected DACL, current-principal access, ordinary-principal
  denial, dan double descriptor/token stability;
- retained parent/root handle, final path, identity, noninheritance, share-mode
  rename/delete resistance, dan reparse/link refusal di bawah adversarial races;
- root kosong sebelum lease dan exact lease merupakan child/write pertama;
- cross-process exclusive nonblocking lock, stable no-unlink lease, process-exit
  release, stale/replay refusal, serta exact ADR-016 schema/content;
- every descendant operation truly relative to retained handles, with instrumented
  proof that no root/descendant path reopen or ambient fallback occurs;
- normal, ordinary-exception, `KeyboardInterrupt`, `SystemExit`, crash, partial
  create/write, cleanup failure, dan reverse cleanup behavior; dan
- independent adversarial review tanpa P1/P2.

Acceptance juga memerlukan versioned exact preparation-root/ACL policy artifact,
native API/ABI design, refactor ADR-016 dan builder/supervisor composition, serta
bounded recovery/retirement procedure. Tidak ada pure test yang dapat menggantikan
bukti tersebut.

## Dependency dan urutan implementasi

1. Pertahankan artifact/verifier/revocation/high-water dan preparation authorization
   ADR-021 sebagai prerequisite; structural codecs saja bukan authority.
2. Terima exact native parent/root creation API, security policy artifact, retained
   handle model, dan ordinary-principal preparation-ACL attestation.
3. Refactor ADR-016 menjadi bootstrap lease handle-relative yang hanya menerima
   `HeldPreparationRootCapability`; implementation path-based tetap unusable.
4. Refactor builder/supervisor agar seluruh child I/O menggunakan lease-bound
   capability dan lifecycle di atas.
5. Baru kemudian implementasikan ADR-022 native materialization, final ADR-021
   composition admission, dan fresh ADR-017 admission.
6. Jalankan pure, native disposable-host, crash/race, browser/service, dan full
   candidate acceptance secara terpisah sebelum mempertimbangkan activation.

## Alternatif yang dipertimbangkan

### Create directory lalu memasang ACL

Ditolak. Ada interval ketika object dapat diwarisi atau diakses dengan ACL yang
belum disahkan.

### Memakai ADR-016 path-based tanpa perubahan

Ditolak. Root dan lease dapat dibuka ulang melalui namespace yang berubah dan
tidak membuktikan lease sebagai first child/write dari retained root.

### Memakai root yang telah ada atau shared staging directory

Ditolak. Freshness, emptiness, ownership, prior content, dan identity tidak dapat
diikat ke satu preparation authorization one-shot.

### Membuka path ulang untuk library atau proses child yang memerlukannya

Ditolak. Komponen harus menerima capability/handle contract yang disahkan atau
persiapan berhenti; compatibility fallback bukan authority.

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
- Preparation-ACL authority, native API/ABI, ordinary-principal evidence,
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
- [Microsoft `SECURITY_ATTRIBUTES`](https://learn.microsoft.com/en-us/previous-versions/windows/desktop/legacy/aa379560(v=vs.85))
- [Microsoft Security Descriptors](https://learn.microsoft.com/en-us/windows/win32/secauthz/security-descriptors)
- [Microsoft DACLs and ACEs](https://learn.microsoft.com/en-us/windows/win32/secauthz/dacls-and-aces)
- [Microsoft `CreateFileW`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilew)
- [Microsoft `LockFileEx`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-lockfileex)
- [Microsoft `GetFinalPathNameByHandleW`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-getfinalpathnamebyhandlew)
- [Microsoft `GetFileInformationByHandleEx`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-getfileinformationbyhandleex)
- [Microsoft Reparse Points](https://learn.microsoft.com/en-us/windows/win32/fileio/reparse-points)
- [Microsoft Handle Inheritance](https://learn.microsoft.com/en-us/windows/win32/sysinfo/handle-inheritance)
