# ADR-018: Provider akses ordinary Windows

## Status

Accepted untuk kontrak internal dan pengujian pure-fake saja. Native provider,
provisioning principal, integrasi attestor, efektivitas Windows, candidate,
browser, service, payment, dan deployment belum diterima atau diaktifkan.

## Date

2026-09-06

## Context

ADR-017 mewajibkan bukti bahwa principal ordinary kedua tidak menerima akses ke
target checkout. Menduplikasi current-process token tidak membuktikan principal
kedua karena security context tetap sama. Attestor juga tidak boleh menerima
kredensial atau pilihan akun dari launcher/coordinator: hal itu akan memperluas
input publik dan memindahkan authority keamanan kepada caller.

`AccessCheck` memerlukan impersonation token dan membedakan keberhasilan fungsi
dari keputusan akses. Kepemilikan token ordinary, derivation token, pembukaan
target, descriptor live, dan pemanggilan native karena itu harus berada dalam
satu provider internal yang sempit.

## Decision

### Authority dan boundary

Provider adalah capability internal bagi Windows ACL attestor, bukan input
launcher/coordinator. Provider memiliki secara eksklusif:

- independent ordinary token yang diprovisikan di luar lifecycle checkout;
- seluruh raw token dan target handles beserta cleanup-nya;
- derivation independent primary token menjadi impersonation token;
- pembukaan target, capture descriptor live, dan native `AccessCheck`.

Provider tidak pernah menerima, mengembalikan, atau mencatat credentials,
password, username, domain, account selector, environment choice, raw `HANDLE`,
SID pointer, security-descriptor bytes/pointer, atau process handle. Provenance
kind adalah closed allowlist dengan satu nilai
`externally_provisioned_windows_principal`; `duplicate_current_process` selalu
ditolak.

Canonical SID dan token profile pada evidence hanya boleh melintasi boundary
internal attestor; nilainya tidak dipersistenkan, dicatat ke log, atau dikirim ke
browser.

Policy authority tetap exact artifact ADR-017
`tools/testing/tests/Browser/checkout-windows-acl-policy-v1.json` dengan pinned
SHA-256 `a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb`,
termasuk bagian `ordinarySecondPrincipal`. Tidak ada provider policy atau digest
alternatif yang dapat dipilih caller.

### Interface one-shot

Interface berikut bersifat normative setelah authority provisioning diterima;
belum boleh ada provider, cache, atau implementation `attest` yang usable.
Interface dipin pada object dan implementasi bound method exact:

- `attest(request)` melakukan seluruh observasi live, mengembalikan canonical
  evidence, dan menyimpan exact evidence yang sama;
- `load(requestDigest)` atomik mem-pop evidence satu kali;
- `discard(requestDigest)` membuang evidence yang belum dikonsumsi.

Aturan signature, object/type/method pinning, deep-copy isolation, canonical
mutation checks, fixed redacted refusal vocabulary, dan preservasi error utama
serta `KeyboardInterrupt`/`SystemExit` mengikuti ADR-017. Cache hanya process
local, maksimal satu entry, tidak persisten, dan tidak memiliki fallback atau
replay. Semua failure setelah request terbentuk mencoba `discard`; seluruh
failure yang terlihat consumer memakai kode tetap `acl_attestation` tanpa data
token, account, SID, path, atau native error detail.

### Request canonical dan lifecycle binding

Setiap boundary `anchor` dan `execution`, baik phase `fresh` maupun `recovery`,
wajib membuat request baru dengan challenge acak 256-bit yang belum dipakai.
Request ASCII sorted-key canonical JSON maksimum 16 KiB, exact types/keys,
tanpa duplicate/nonfinite/extra field. Top-level memiliki tepat:

- `version=1`, `boundary` exact `anchor` atau `execution`, `phase` exact `fresh`
  atau `recovery`, serta exact `session`, `configBinding`, dan `leaseBinding`
  dari outer request ADR-017;
- exact `policyDigest` ADR-017, `aclRequestDigest`, `aclEvidenceDigest`, dan
  fresh `challenge`;
- `currentTokenIdentity` (`userSid`, `tokenId`, `authenticationId`,
  `modifiedId`);
- `aclDescriptorEvidenceDigest`; dan
- `targets` berisi tepat tiga entry ordered `coordinator`, `run`, `source`.

Ketiga target path wajib pairwise distinct dan ketiga pasangan
`(volumeSerial,fileId)` wajib pairwise distinct. Object yang sama tidak dapat
memenuhi beberapa role; request codec ADR-018 menolak alias ini segera sebelum
request digest atau provider boundary terbentuk.

Setiap target memiliki tepat `role`, `path`, `volumeSerial`, `fileId`,
`ownerSid`, `daclDigest`, `reparse=false`, serta `desiredAccess=MAXIMUM_ALLOWED`
(`0x02000000`/33554432). Nilai target merupakan identity/summary exact dari
pre-provider descriptor evidence ADR-017, bukan pilihan baru caller; digest
berikutnya mengikat seluruh evidence itu.
Seluruh target `ownerSid` wajib sama dengan `currentTokenIdentity.userSid`.
`aclDescriptorEvidenceDigest` adalah lowercase SHA-256 atas byte prefix ASCII
`checkout-acl-descriptor-evidence-v1` diikuti satu NUL dan canonical JSON bytes
dari tiga ordered pre-provider descriptor evidence. Setiap item digest memiliki
exact keys `daclDigest`, `fileId`, `ownerSid`, `path`, `policySatisfied`,
`reparse`, `role`, dan `volumeSerial`; `desiredAccess` tidak ikut karena bukan
descriptor evidence. Digest ini tidak memakai final provider evidence sehingga
tidak circular. `aclRequestDigest` mengikat exact request ACL ADR-017 pada
boundary yang sama. `requestDigest` adalah lowercase SHA-256 atas canonical
provider request bytes dan tidak menjadi field request.

`aclRequestDigest` adalah digest exact canonical outer ADR-017 request bytes dan
wajib sama dengan `requestDigest` pada outer evidence.
`aclEvidenceDigest` adalah digest exact canonical outer ADR-017 evidence bytes,
bukan digest semantic subset. Internal attestor memvalidasi codec outer request
dan evidence, lalu menurunkan ketiga digest serta seluruh targets; caller tidak
dapat memasukkan atau mengganti target/digest. Provider baru dipanggil setelah
canonical outer descriptor evidence bytes tersedia, dan bytes exact tersebut
wajib tetap identik melewati callback.

`currentTokenIdentity` berasal hanya dari immutable private current-process
token snapshot milik attestor yang sama, bukan dari caller atau outer JSON.
Provider mengobservasinya kembali secara live sebelum/sesudah checks.

### Evidence canonical

Evidence ASCII sorted-key canonical JSON maksimum 32 KiB mengulang exact `version`,
`boundary`, `phase`, `session`, `configBinding`, `leaseBinding`, `policyDigest`,
`aclRequestDigest`, `aclEvidenceDigest`, `aclDescriptorEvidenceDigest`,
`challenge`,
`currentTokenIdentity`, dan `requestDigest`. Field lainnya tepat:

- `provenanceKind=externally_provisioned_windows_principal`;
- `originalToken` dan `derivedToken`; serta
- tiga `targets` ordered yang sama.

Kedua token profile memiliki exact keys `authenticationId`, `groups`,
`impersonationLevel`, `isAppContainer`, `isAppContainerRaw`, `modifiedId`,
`origin`, `privileges`, `restrictingSids`, `tokenId`, `tokenType`, dan `userSid`.
`isAppContainerRaw` wajib uint32 dan `isAppContainer` wajib bool exact
`isAppContainerRaw != 0`. Original wajib
`tokenType=TokenPrimary` dan `impersonationLevel=null`. Derived wajib
`tokenType=TokenImpersonation` dan `impersonationLevel=SecurityImpersonation`
numerik 2.

SID memakai string canonical ADR-017. Setiap LUID (`tokenId`,
`authenticationId`, `modifiedId`, dan `origin`) adalah exact two-element array
`[LowPart uint32, HighPart int32]`. Group entry memiliki exact `sid` dan
`attributes`; privilege entry memiliki exact `luid` dan `attributes`;
restricting-SID entry memiliki exact `sid` dan `attributes=0`. Duplicate entry
tidak dideduplikasi dan seluruh urutan native dipertahankan.

Original dan derived wajib memiliki exact same `userSid`, `authenticationId`,
`origin`, ordered groups, ordered privileges, ordered restricting SIDs, serta
exact `isAppContainerRaw` dan `isAppContainer`. `tokenId` keduanya wajib
berbeda. `modifiedId` bersifat
observational: tidak wajib sama antar-token, tetapi setiap tokenId/modifiedId dan
seluruh profile masing-masing wajib stabil sebelum dan sesudah tiga checks.
Source buffer groups, privileges, dan restricting SIDs masing-masing dibatasi
256 KiB dan 4096 entry.

Setiap target evidence memiliki exact identity/request fields ditambah
`descriptorDigest`, `functionSuccess`, `accessStatus`, `grantedAccess`, dan
provider-derived `policySatisfied`. Nilainya wajib
`desiredAccess=MAXIMUM_ALLOWED`, `policySatisfied=true`,
`functionSuccess=true`, `accessStatus=false`, dan `grantedAccess=0`.
`policySatisfied` tidak pernah disalin dari request atau pre-provider evidence.
SID/LUID/integer/digest/path, bool-versus-int, bounds, canonical-byte equality,
serta ordering mengikuti strict codec ADR-017.

`descriptorDigest` adalah lowercase SHA-256 atas exact validated self-relative
security-descriptor bytes yang pointer-nya diberikan ke `AccessCheck`; capture
sebelum dan sesudah check wajib byte-identical.

### Descriptor authority dan lifetime

Provider sendiri membuka setiap request target memakai handle non-inheritable
dan no-reparse. Provider memegang handle tersebut selama check, lalu:

1. memvalidasi final path serta volume/file identity terhadap request;
2. menangkap dan memparse descriptor dari live handle;
3. memverifikasi owner/DACL digest terhadap request serta
   `aclDescriptorEvidenceDigest`;
4. memanggil `AccessCheck` pada descriptor live yang baru ditangkap;
5. menangkap dan memparse ulang descriptor secara independen, lalu memvalidasi
   ulang final path, identity, owner, DACL, serta full self-relative descriptor
   bytes/digest sebelum handle ditutup.

Kedua capture wajib secara independen memenuhi seluruh authoritative descriptor
policy ADR-017: valid security descriptor revision 1; owner dan group SID
pointer/span contained serta valid; control exact `SE_SELF_RELATIVE=1`,
`SE_DACL_PRESENT=1`, `SE_DACL_PROTECTED=1`, dan
`SE_DACL_AUTO_INHERITED=0`; owner/group/DACL defaulted flags false; DACL
non-NULL; ACL revision 2; full `AclSize` contained; DACL digest exact
`AclBytesInUse`; dan tepat satu aligned, fully-contained ordered ACE yang
mengonsumsi seluruh `AclBytesInUse`. ACE wajib
`ACCESS_ALLOWED_ACE_TYPE` numerik 0, flags exact 3
`OBJECT_INHERIT_ACE|CONTAINER_INHERIT_ACE`, tidak inherited, tanpa propagation
flag, trustee exact current-process owner SID, serta mask exact sesuai role
ADR-017. Hanya hasil parse live provider ini yang menghasilkan
`policySatisfied=true`.

Provider juga membuka current-process token sendiri, memverifikasi stable token
identity request secara live sebelum dan sesudah checks, dan tidak menerima
current token handle dari caller. Seluruh tiga target handle, current-process
token handle, original token handle, dan derived token handle tetap dimiliki
provider sepanjang operasi yang relevan. Semua real token handle wajib
non-inheritable, memiliki hanya hak yang diperlukan, dan ditutup exact sekali
dalam cleanup.
Setiap mismatch yang teramati pada path/identity/descriptor/token capture,
reparse, atau pinned handle menolak seluruh evidence; hasil parsial tidak masuk
cache. Mutasi transient yang berubah lalu kembali identik di antara dua capture
tidak diklaim terdeteksi dan tetap merupakan residual runtime. Exception cleanup
tidak boleh menutupi error utama atau `KeyboardInterrupt`/`SystemExit`.

Provider memakai fixed filesystem `GENERIC_MAPPING` internal yang sama untuk
ketiga checks; mapping bukan field request dan tidak dapat diganti caller.
`PrivilegeSet` hasil native dibatasi dan divalidasi internal, tanpa mengubah
requirement bahwa access status false serta granted mask nol.

### Ordinary-principal policy

Evidence diterima hanya bila seluruh invariant berikut terpenuhi:

- original `TokenUser` berbeda dari current-process `TokenUser` dan bukan
  LocalSystem `S-1-5-18`;
- current-process owner SID tidak muncul sebagai original token user maupun pada
  setiap original `TokenGroups` entry tanpa memandang attributes;
- `SeBackupPrivilege`, `SeRestorePrivilege`, dan `SeTakeOwnershipPrivilege`
  di-resolve memakai fixed local `LookupPrivilegeValueW(system=None)` LUID dan
  tidak enabled;
- original dan derived memiliki class-11 `TokenRestrictedSids` kosong serta
  `IsTokenRestricted=FALSE` yang dibuktikan dengan `SetLastError(0)` dan
  immediate `GetLastError()==0`;
- original dan derived memiliki `isAppContainerRaw=0` dan
  `isAppContainer=false`;
- original/derived relation dan before/after profile stability exact;
- `AuthenticationId` berbeda dari current process menjadi corroboration, bukan
  satu-satunya bukti provenance; dan
- exact native `AccessCheck` terhadap ketiga descriptor live sukses sebagai
  fungsi tetapi menolak akses dengan granted mask nol.

`TokenOrigin` bersifat observational dan tidak membuktikan provenance sendiri.
`DuplicateTokenEx` atas current-process token tidak membuktikan principal
berbeda. Fungsi itu hanya boleh dipakai provider untuk mengubah independent
primary token yang telah diprovisikan menjadi derived impersonation token dengan
security context yang sama.

### Provisioning belum dipilih

ADR ini tidak memilih cara acquisition atau provisioning independent ordinary
principal. Tidak ada provider konkret/usable atau composition sampai keputusan
authority provisioning terpisah diterima. Tanpa authority tersebut, provider
dan seluruh lifecycle yang bergantung padanya wajib fail closed sebelum native
token acquisition atau target access. Selector `DISTINCT_NON_PRIVILEGED_TEST_TOKEN`
pada policy hanya membatasi hasil yang wajib dibuktikan dan bukan mekanisme
acquisition.

Sebelum ADR provisioning diterima, hanya pure request-codec preparation yang
boleh dimulai. Codec tersebut wajib menerima exact canonical outer ADR-017
request/evidence bytes, memvalidasinya, dan menurunkan lifecycle bindings,
targets, `aclRequestDigest`, `aclEvidenceDigest`, serta
`aclDescriptorEvidenceDigest` sendiri; tidak boleh menerima nilai derivasi itu
sebagai input bebas. Satu-satunya input tambahan adalah immutable private
current-token identity dari attestor yang sama, yang juga tidak tersedia bagi
caller.

## Alternatives Considered

### Duplicate current-process token

Ditolak karena hanya mengganti token handle/type, bukan membuktikan principal
ordinary yang independen.

### Credentials, username, environment, atau raw-handle API

Ditolak karena membocorkan material sensitif, membuat caller memilih authority,
dan memperbesar permukaan lifecycle checkout.

### Menjadikan provider sebagai launcher umum

Ditolak. Kebutuhan launcher atau process creation di bawah principal lain harus
diputuskan melalui ADR terpisah dan bukan bagian dari attestation ini.

## Consequences

- Sebelum ADR provisioning, pure-fake implementation hanya boleh mencakup codec
  yang menurunkan request dari exact outer bytes. One-shot provider, pinning,
  cleanup, dan native behavior tetap sekadar contract.
- Native provider, provisioning authority, serta integration belum ada dan tetap
  menjadi gate wajib; tidak ada credentials atau principal nyata di repository.
- Runtime Windows masih harus membuktikan independent provenance, native token
  derivation, handle lifetime, descriptor binding, `AccessCheck`, race/crash,
  dan effective denial untuk semua target.
- P15/P16/P17c/P18 tetap terbuka. Seluruh gate dan payment tetap default OFF.

## References

- [AccessCheck](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-accesscheck)
- [DuplicateTokenEx](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-duplicatetokenex)
- [GetTokenInformation](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-gettokeninformation)
- [TOKEN_ORIGIN](https://learn.microsoft.com/en-us/windows/win32/api/winnt/ns-winnt-token_origin)
- [TOKEN_STATISTICS](https://learn.microsoft.com/en-us/windows/win32/api/winnt/ns-winnt-token_statistics)
- [Requesting access rights (`MAXIMUM_ALLOWED`)](https://learn.microsoft.com/en-us/windows/win32/secauthz/requesting-access-rights-to-an-object)
