# ADR-020: Authority validasi evidence ordinary Windows

## Status

Accepted untuk design/validation boundary saja. Keputusan ini tidak menerima
codec implementation, authority native, provider composition, Windows runtime,
candidate, browser, service, payment, deployment, atau activation.

## Date

2026-09-06

## Context

ADR-018 menetapkan evidence canonical dari provider ordinary Windows. Evidence
tersebut memuat token profiles dan hasil penolakan akses, tetapi byte JSON tidak
dapat membuktikan sendiri bahwa provider benar-benar:

- memanggil `LookupPrivilegeValueW(NULL, ...)` pada mesin yang sedang berjalan;
- mengobservasi `IsTokenRestricted=FALSE` dengan last-error nol; atau
- menangkap profile token secara independen sebelum dan sesudah seluruh check.

Menambahkan `providerAttestation` yang berisi name-to-LUID map, raw result, atau
hash before/after ke evidence tidak menciptakan authority baru. Provider yang
menghasilkan evidence dapat mengisi nilai dan hash tersebut dari data yang sama,
tanpa observasi native independen. Hal itu memberi kesan bukti yang lebih kuat
daripada yang benar-benar dapat divalidasi oleh pure codec.

Di sisi lain, codec tetap diperlukan untuk menolak bytes noncanonical, field
tambahan, relasi token yang salah, dan hasil target yang tidak sesuai kontrak.
Karena itu authority struktur dan authority observasi live harus dipisahkan.

## Decision

### Schema evidence v1 tidak berubah

Exact ADR-018 evidence v1 tetap menjadi satu-satunya schema serialized. Tidak
ditambahkan `providerAttestation`, serialized name-to-LUID map, native error,
raw handle/pointer, username/account selector, atau hash before/after.

Evidence tetap maksimum 32 KiB, ASCII sorted-key canonical JSON, exact keys dan
types, serta mengulang lifecycle/request bindings yang ditetapkan ADR-018.
Perubahan schema berikutnya wajib memakai ADR dan versioning baru; tidak ada
fallback atau permissive parsing.

### Layer 1: pure canonical structural codec

Layer pertama adalah pure codec yang hanya menerima exact canonical request
ADR-018 beserta exact canonical evidence v1. Codec wajib:

- memverifikasi `requestDigest` terhadap exact request bytes;
- memverifikasi exact equality untuk `version`, `boundary`, `phase`, `session`,
  `configBinding`, `leaseBinding`, `policyDigest`, `aclRequestDigest`,
  `aclEvidenceDigest`, `aclDescriptorEvidenceDigest`, `challenge`, dan
  `currentTokenIdentity`;
- memverifikasi `provenanceKind` literal
  `externally_provisioned_windows_principal` tanpa menganggap literal itu bukti
  provenance native;
- memverifikasi original token `TokenPrimary`/null dan derived token
  `TokenImpersonation`/`SecurityImpersonation(2)`;
- memverifikasi exact token-profile keys/types/bounds, relation original-derived,
  token ID berbeda, empty restricting-SID list, dan app-container raw nol/bool
  false;
- menolak LocalSystem, token user yang sama dengan current process,
  authentication ID yang sama, serta current owner SID pada setiap group tanpa
  memandang attributes;
- memverifikasi tiga targets ordered `coordinator`, `run`, `source` exact sama
  dengan request, dengan pairwise-distinct path dan filesystem identities;
- memverifikasi `desiredAccess=MAXIMUM_ALLOWED`, `policySatisfied=true`,
  `functionSuccess=true`, `accessStatus=false`, dan `grantedAccess=0`; dan
- menolak duplicate keys, nonfinite numbers, bool-as-int, extra/missing fields,
  trailing bytes, invalid UTF/ASCII, over-size, dan noncanonical serialization.

Hasil codec harus immutable dan sempit. Dependency, callable, constants, dan
authority mutation harus fail closed dengan fixed redacted refusal. Error detail
tidak boleh memuat SID, path, token, account, request bytes, atau native detail.
`KeyboardInterrupt` dan `SystemExit` harus dipertahankan sebagai error utama.

Kelulusan layer ini hanya berarti evidence konsisten secara canonical dan
struktural. Layer ini tidak boleh menghasilkan klaim full admission, native
provenance, `AccessCheck` efficacy, privilege safety, restriction state, atau
before/after stability.

### Layer 2: sealed live validation authority

Full admission hanya dapat diberikan oleh composition internal yang memiliki
sealed `PrivilegeLuidAuthority` dan native provider ADR-018/019. Object,
implementation, method, constants, binary/manifest identity, dan dependency
authority dipin sebelum request. Caller, request, transport, dan serialized
evidence tidak dapat memasok atau mengganti resolver maupun LUID map.

#### Tiga identity token yang tidak boleh dicampur

Dalam arsitektur broker ADR-019, istilah `currentTokenIdentity` dan current
profile berarti token checkout client yang telah diautentikasi, bukan token
current process milik broker service. Ketentuan ini menyempurnakan dan
menyupersesi hanya wording ADR-018 yang ambigu tentang “current process”; schema
evidence v1 dan keputusan lainnya tidak berubah.

Composition wajib memilih satu capture authority utuh berikut dan tidak boleh
menggabungkan field dari dua token:

- token impersonated named-pipe thread yang berhasil diautentikasi; atau
- primary token checkout client yang dibuka melalui client process handle yang
  sudah divalidasi dan dipin.

Jika identity berasal dari impersonated thread token, exact handle token itu
ditahan melewati `RevertToSelf` sampai response selesai. Jika identity berasal
dari client process token, exact client process handle dan token handle ditahan
selama interval yang sama. Dalam kedua pilihan, broker juga memverifikasi
relation thread token terhadap pinned client PID, process creation identity,
executable/session authority, user/logon SID, dan authentication context. PID
saja tidak cukup. `currentTokenIdentity` (`userSid`, `tokenId`,
`authenticationId`, `modifiedId`) diambil seluruhnya dari capture authority
yang dipilih dan wajib exact sama dengan request.

`originalToken` selalu berarti primary token broker service. Token itu baru
boleh dibuka setelah `RevertToSelf` sukses dan `OpenThreadToken` membuktikan
`ERROR_NO_TOKEN`; thread yang masih impersonating tidak boleh melanjutkan.
`derivedToken` hanya boleh berasal dari `DuplicateTokenEx(originalToken)` dengan
type `TokenImpersonation` dan level exact `SecurityImpersonation(2)`. Token
client tidak pernah menjadi original atau derived ordinary token.

Urutan capture wajib feasible dan exact: capture client identity/profile awal
serta tahan client authority; buka target handles saat authenticated client
impersonation masih aktif; lakukan `RevertToSelf` dan pembuktian no-thread-token;
buka/capture broker primary dan derived token awal; jalankan tiga descriptor/
`AccessCheck`; capture ulang broker primary dan derived; capture ulang identity/
profile client melalui handle yang sejak awal ditahan; kemudian validasi seluruh
before/after equality sebelum handle ditutup. Client process identity dan token
provenance juga diulang sebelum response. Drift atau kehilangan salah satu
handle menolak seluruh admission.

Untuk setiap request dan broker start, `PrivilegeLuidAuthority` wajib resolve
secara independen tepat tiga nama berikut, dalam urutan tetap, memakai
`LookupPrivilegeValueW(lpSystemName=NULL)`:

1. `SeBackupPrivilege`;
2. `SeRestorePrivilege`; dan
3. `SeTakeOwnershipPrivilege`.

Resolution dilakukan sebelum dan sesudah seluruh target checks. Kedua ordered
snapshot wajib exact equal, ketiga LUID wajib valid dan pairwise distinct, dan
snapshot dibuat immutable. Setiap matching entry dalam original dan derived
token privilege profiles diperiksa; jika bit enabled `Attributes & 0x2` aktif
pada salah satu duplicate/native entry, admission ditolak.

Snapshot tidak diserialisasi ke evidence. Ia diikat secara private dan exact ke:

- `machineIdentityDigest` yang exact-equal dengan `machine.identityDigest` dari
  decoded canonical authority manifest;
- exact authority-manifest canonical bytes, digest, dan generation;
- `brokerStartIdentity`;
- exact ADR-018 `requestDigest`; serta
- `boundary`, `phase`, `session`, `configBinding`, `leaseBinding`,
  `policyDigest`, `aclRequestDigest`, `aclEvidenceDigest`,
  `aclDescriptorEvidenceDigest`, dan `challenge`.

Binding tersebut bukan sekumpulan scalar yang berdiri sendiri. Composition
wajib decode ulang exact canonical authority manifest dan exact canonical broker
start document memakai codec yang dipin. Digest start dihitung ulang dari bytes
canonical dengan domain `checkout-ordinary-broker-start-v1`, lalu wajib
exact-equal dengan `brokerStartIdentity` pada transport/admission. Input digest
adalah exact prefix ASCII tersebut, satu NUL, lalu exact canonical start-document
bytes; encoding alternatif ditolak.

Decoded start wajib memiliki `manifestDigest` yang exact-equal dengan digest
manifest yang sama. `accountSid` dan `serviceSid` start wajib exact-equal dengan
account/service pada manifest yang sama; generation yang dipakai lifecycle wajib
exact generation manifest tersebut. `servicePid` dan `processCreationTime`
wajib exact-equal dengan live pinned broker process snapshot. `authenticationId`
dan `tokenId` start wajib exact-equal dengan live pinned broker primary-token
snapshot serta exact `authenticationId`/`tokenId` pada `originalToken` evidence.
Process handle dan primary-token handle itu ditahan sepanjang request,
kemudian seluruh PID/creation/authentication/token observations diulang sebelum
response. Missing, stale, revoked, cross-generation, cross-account, cross-start,
PID reuse, atau pre/post drift menolak admission.

`machineIdentityDigest`, manifest digest/generation, start identity, dan semua
live process/token observations berasal dari composition-owned authorities;
caller atau evidence tidak dapat memasok, memilih, maupun menggantinya.

Tidak ada cache lintas request/start/machine. Snapshot hanya hidup selama satu
admission, tidak dapat direplay, tidak dicatat, dan dibuang pada success,
refusal, exception, `KeyboardInterrupt`, atau `SystemExit`.

Native provider juga wajib, secara internal dan tanpa field evidence baru:

- mengobservasi original dan derived class-11 restricting SID list empty;
- memanggil `SetLastError(0)`, lalu `IsTokenRestricted`, dan ketika return
  `FALSE` segera membaca `GetLastError()==0` untuk kedua token;
- menangkap full current/original/derived token profiles sebelum dan sesudah
  seluruh tiga target checks; dan
- mensyaratkan exact stability profile masing-masing, kecuali relation
  original-derived yang memang diizinkan ADR-018.

Before/after captures merupakan object private milik provider. Serialized hashes
tidak menggantikannya. Provider, attestor, dan composition wajib mempertahankan
handle ownership, cleanup, mutation checks, dan primary-error precedence sesuai
ADR-017 sampai admission selesai.

### Urutan admission

Urutan fail-closed yang diusulkan:

1. validasi exact request ADR-018;
2. acquire dan pin provider, manifest/start, machine, token, handle, dan
   lifecycle authorities;
3. authenticate/capture checkout client authority, buka target handles di bawah
   impersonation, lalu sukses `RevertToSelf` dan buktikan `ERROR_NO_TOKEN`;
4. capture broker original/derived profiles dan resolve privilege authority
   pertama;
5. lakukan descriptor revalidation dan native `AccessCheck` untuk tepat tiga
   targets;
6. resolve privilege authority kedua dan capture ulang client, original, serta
   derived profiles dan live manifest/start/process/token bindings;
7. validasi stable snapshots, restriction corroboration, dangerous privilege
   exclusion, dan exact denial results;
8. bentuk evidence v1 canonical;
9. jalankan pure structural codec terhadap exact request/evidence bytes; dan
10. baru publish one-shot evidence ke transport/cache yang dipin.

Failure pada langkah mana pun menolak admission dan tidak memublikasikan hasil
parsial. Refusal tetap memakai vocabulary redacted yang sudah ditetapkan.

### Transport dan activation gate

Successful `attest` dan evidence-bearing `load` pada broker transport tetap
disabled. Keduanya baru dapat dipertimbangkan setelah seluruh hal berikut
diterima bersama:

- pure evidence v1 structural codec dan adversarial tests;
- sealed `PrivilegeLuidAuthority` composition;
- native provider ADR-018/019 dan exact one-shot cache wiring;
- native Windows tests untuk resolver, token restriction/stability, target
  descriptor continuity, `AccessCheck`, cleanup, crash, race, dan replay; serta
- independent review tanpa blocker P1/P2.

Pure-fake tests tidak dapat menggantikan native acceptance. Tidak ada gate yang
boleh diaktifkan hanya karena layer structural lulus.

## Alternatives Considered

### Serialized `providerAttestation`

Ditolak. Provider dapat melakukan self-attestation atas name-to-LUID map dan
native results tanpa authority independen. Field tambahan juga memperbesar
surface privacy dan versioning tanpa menambah bukti yang dapat diverifikasi pure
codec.

### Serialized before/after profile hashes

Ditolak. Dua hash yang dibuat dari snapshot yang sama akan tampak stabil. Live
independent captures dan handle continuity harus dibuktikan di provider, bukan
diwakili oleh redundant hashes.

### Hard-code LUID privilege sensitif

Ditolak. Kontrak mewajibkan local `LookupPrivilegeValueW(NULL)` dan tidak boleh
menganggap numeric LUID lintas mesin sebagai authority.

### Mengizinkan caller mengirim resolver atau LUID map

Ditolak. Hal itu memindahkan policy authority kepada input yang tidak dipercaya
dan memungkinkan caller memilih mapping yang melewatkan privilege aktif.

### Menganggap pure codec sebagai full admission

Ditolak. Canonical consistency tidak membuktikan provenance, native calls,
handle continuity, atau efektivitas denial Windows.

## Consequences

- ADR-018 evidence v1 tetap stabil dan transport tidak memerlukan schema
  migration pada tahap structural codec.
- Pure codec dapat dibangun dan diuji tanpa native Windows, tetapi hasilnya harus
  diberi label structural-only.
- Native provider/composition memegang beban pembuktian resolver authority,
  restriction state, profile stability, provenance, dan effective denial.
- ADR ini menyempurnakan ADR-018 dengan menetapkan current identity sebagai
  authenticated checkout client dalam broker architecture; original/derived
  tetap eksklusif broker service token chain.
- Private machine/manifest/start/request bindings menambah state per-admission,
  tetapi mencegah reuse snapshot lintas lifecycle.
- Tidak ada concrete authority, provider, cache, account/service, native test,
  runtime, config, environment, deployment, atau activation yang diterima oleh
  ADR proposed ini.
- P15, P16, P17c, dan P18 tetap terbuka. Seluruh checkout/payment gates tetap
  default OFF.

## Acceptance Criteria

ADR ini hanya dapat dipindahkan ke Accepted setelah review menyetujui bahwa:

- evidence v1 exact schema tidak berubah;
- pure codec tidak mengklaim full admission;
- resolver/mapping tidak berasal dari caller atau evidence;
- exact three-name pre/post authority dan binding lifecycle bersifat normative;
- native restriction/stability checks tetap provider-internal;
- successful transport tetap disabled sampai structural, composition, provider,
  cache, dan native Windows evidence lengkap; dan
- privacy, redaction, cleanup, replay, and `BaseException` boundaries tetap
  fail closed.

Acceptance ADR ini tetap tidak mengotorisasi implementasi native, provisioning,
account/service creation, candidate execution, browser runtime, payment,
deployment, atau gate activation.

## References

- [ADR-017: Attestation ACL Windows checkout](0017-checkout-windows-acl-attestation.md)
- [ADR-018: Provider akses ordinary Windows](0018-windows-ordinary-access-provider.md)
- [ADR-019: Provisioning principal ordinary Windows](0019-windows-ordinary-principal-provisioning.md)
- [LookupPrivilegeValueW](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-lookupprivilegevaluew)
- [IsTokenRestricted](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-istokenrestricted)
- [GetTokenInformation](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-gettokeninformation)
- [AccessCheck](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-accesscheck)

## Implementation Checkpoint — `b66ebba`, `bb369c6`, `9785eb5`

Design boundary ADR ini diterima pada `bb369c6`. Commit `b66ebba` menerima pure
canonical broker-start identity codec (**8/8 tests**) untuk supplied data saja;
live composition binding tetap terbuka. Commit `9785eb5` menerima pure
bytes-only evidence codec (**12/12 tests**) yang memvalidasi struktur tanpa
menaikkan hasil `structuralOnly` menjadi native policy/provenance/admission.

Suite codec lokal gabungan **56/56** lulus dengan `py_compile`, `diff-check`,
dan review **PASS**. Successful `attest`/`load` tetap disabled sampai native
authority/provider/cache/runtime diterima. Tidak ada instalasi, service,
environment, network, deployment, atau activation. P15/P16/P17c/P18,
progress/checklist, gates, dan payment tetap terbuka/tidak berubah/default OFF.
