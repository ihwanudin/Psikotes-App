# ADR-024: Authority broker journal dan trusted time checkout

- Status: Accepted-for-contract only
- Tanggal: 2026-09-08

## Konteks

ADR-018 sampai ADR-020 menetapkan provider ordinary Windows, principal service
yang independen, serta pemisahan structural evidence dari native authority.
ADR-021 menetapkan revocation high-water, one-shot ledger, dan trusted time
sebagai prasyarat persiapan kandidat. Primitive yang tersedia saat ini masih
berupa codec supplied-data dan storage observation point-in-time. File yang
terproteksi dan jam lokal tidak membuktikan bahwa state tidak di-rollback saat
crash, reboot, restore disk, atau pergantian host.

Kontrak diperlukan untuk menentukan siapa yang berwenang menyimpan journal,
bagaimana client dan journal/time service saling mengautentikasi, serta bukti apa yang wajib
ada sebelum waktu atau generation boleh dipakai. Kontrak tidak boleh membawa
credential, raw handle, arbitrary path, atau native error ke aplikasi.

## Keputusan

ADR ini menerima desain authority dan failure policy saja. Ia tidak membuat
akun atau service, mengubah user rights/ACL, mengakses TPM, memasang binary,
menjalankan native API, membangun kandidat, atau mengaktifkan gate.

### 1. Principal dan service boundary

Journal dan trusted time memakai **service serta account khusus yang terpisah**
dari ordinary-access broker ADR-019. Service ini adalah
`SERVICE_WIN32_OWN_PROCESS` dengan binary, service SID, local Windows user,
endpoint pipe, authority manifest, manifest generation, codec version, process,
token, dan lifecycle cache sendiri. Ia tidak menjalankan endpoint
`attest/load/discard` ADR-019, tidak memegang ordinary-principal token, dan tidak
boleh berbagi process, account, service SID, pipe, manifest, atau cache dengan
provider ADR-018/019. Composition mengikat kedua manifest dan kedua live service
identity secara terpisah; keberhasilan salah satu tidak mengotorisasi yang lain.
ADR-019 tetap hanya mengatur ordinary-access provider; ADR ini tidak memperluas
method, process, token, account, atau provisioning manifest ADR-019.

Contract v1 menetapkan service name `OncamCheckoutJournalTimeV1`, dedicated
local-account label `OncamCkJournalTime` (exact SID dari manifest tetap identity
authority), endpoint `\\.\pipe\oncam-checkout-journal-time-v1`, manifest schema
`oncam.checkout.journal-time-service-manifest.v1`, dan transport domain
`oncam.checkout.journal-time-transport.v1\0`. Manifest admin-owned mengikat exact
service/account SID, binary path+identity+digest, service SID, endpoint, codec
version, privilege/user-right policy, journal root/policy, DPAPI scope, rollback
witness, trusted-time/revocation authorities, dan manifest generation. Nama atau
label yang sama tanpa exact manifest+live identity tidak memberi authority.

Dedicated journal/time local Windows user:

- non-admin, bukan LocalSystem `S-1-5-18`, LocalService, NetworkService,
  virtual account, managed service account, atau akun yang dipakai service lain;
- hanya dipakai untuk journal/time service checkout ini dan tidak menjadi account selector
  yang dapat dipilih caller;
- memiliki satu grant logon exact `SeServiceLogonRight`;
- tidak memiliki grant `SeInteractiveLogonRight`, `SeRemoteInteractiveLogonRight`,
  `SeNetworkLogonRight`, atau `SeBatchLogonRight`;
- memiliki deny rights exact `SeDenyInteractiveLogonRight`,
  `SeDenyRemoteInteractiveLogonRight`, `SeDenyNetworkLogonRight`, dan
  `SeDenyBatchLogonRight` ketika host/domain policy mendukung kombinasi itu;
  konflik Group Policy atau inability to prove effective rights menolak
  readiness, bukan melonggarkan policy; dan
- memiliki hanya dedicated noninteractive service profile yang diperlukan untuk
  DPAPI master-key user scope; tidak memiliki desktop, share, outbound network
  dependency, SPN, delegation, scheduled task, atau general launcher role.

Raw `TokenPrivileges` service v1 wajib memiliki tepat dua entry sebagai exact
set menurut fixed locally resolved LUID, tanpa entry ketiga. Urutan native bukan
authority; canonical evidence mengurutkan kedua nama dalam urutan berikut:

1. `SeChangeNotifyPrivilege` dengan attributes exact
   `SE_PRIVILEGE_ENABLED_BY_DEFAULT | SE_PRIVILEGE_ENABLED` (`0x00000003`); dan
2. `SeImpersonatePrivilege` dengan attributes exact
   `SE_PRIVILEGE_ENABLED_BY_DEFAULT | SE_PRIVILEGE_ENABLED` (`0x00000003`), hanya
   untuk mutual authentication client named pipe pada interval impersonation
   sempit di bawah.

`SeBackupPrivilege`, `SeRestorePrivilege`, `SeTakeOwnershipPrivilege`,
`SeDebugPrivilege`, `SeTcbPrivilege`, `SeAssignPrimaryTokenPrivilege`, dan
seluruh privilege lain wajib **absent**, bukan sekadar disabled. LUID ketiga,
attribute tambahan termasuk `SE_PRIVILEGE_REMOVED` atau
`SE_PRIVILEGE_USED_FOR_ACCESS`, duplicate LUID, perubahan order, atau drift
menolak start/request.
Required-privilege list, local security policy, group membership, token groups,
restricting SID, AppContainer state, dan privilege attributes dibaca ulang pada
setiap start dan sebelum/sesudah request. Extra atau drift menolak request.

SCM start type adalah demand-only dan default stopped. Service SID journal/time
yang distinct hadir
dengan `SERVICE_SID_TYPE_UNRESTRICTED`; istilah itu tidak memberi filesystem
access tanpa DACL. Service object, binary, directory, recovery configuration,
authority manifest, dan firewall rule hanya writable oleh SYSTEM dan
Administrators. Checkout client tidak memperoleh start, stop, change-config,
delete, write-DAC, atau write-owner rights.

### 2. Authority roles

Authority berikut tidak boleh digabung hanya karena berjalan pada host yang
sama:

| Role | Authority | Batas |
| --- | --- | --- |
| provisioning | Administrator + SCM | Membuat/merotasi/mencabut akun, service, rights, binary, dan manifest; tidak menerbitkan journal state. |
| journal/time service identity | Manifest journal/time admin-owned + live SCM/process/token evidence | Membuktikan exact service instance yang berbeda dari broker ADR-019; PID saja tidak cukup. |
| journal storage | Exact journal/time service instance | Memegang handle journal, melakukan read atau compare-and-append, DPAPI, flush, dan cleanup. |
| rollback witness | TPM-backed monotonic authority **atau** independently signed external witness | Membuktikan monotonic generation; tidak dapat diganti file/checksum/jam lokal. |
| trusted time | Distinct offline trusted-time signing key dalam trust-root/revocation regime ADR-021 | Menerbitkan interval waktu terikat host/run/namespace, bukan mengatur jam host. |
| revocation | Independent 2-of-3 revocation custodians ADR-021 | Menerbitkan snapshot exact namespace/generation; bukan artifact issuer atau broker. |
| consumer | Repository verifier/composition | Memvalidasi exact evidence dan lifecycle binding; tidak memilih principal, path, clock, witness, atau policy. |

Runtime role `broker-journal`, `rollback-witness`, dan `trusted-time` memakai
domain signature serta key/generation terpisah. Penambahan namespace artifact
harus melalui codec/trust-bundle/revocation version yang accepted; literal role
dalam dokumen ini tidak membuat implementation authority secara otomatis.

### 3. IPC local dan mutual authentication

Journal/time service memakai fixed local named-pipe namespace dari manifest
journal/time admin-owned. Endpoint, codec, dan method set ini terpisah dari
ordinary-access provider ADR-019; tidak ada generic operation selector, shared
listener, forwarding, atau fallback lintas endpoint/service.

Pipe dibuat dengan `FILE_FLAG_FIRST_PIPE_INSTANCE`,
`PIPE_REJECT_REMOTE_CLIENTS`, message mode, satu active instance, handle
non-inheritable, finite timeout, tepat satu request frame dan satu response
frame. Request maksimum 20 KiB dan response maksimum 36 KiB. Partial frame,
trailing byte, second frame, oversize, duplicate/in-flight request, disconnect,
atau timeout menolak seluruh operasi.

Security descriptor pipe wajib protected, tanpa inherited/default DACL. Owner
adalah exact service SID. DACL v1 memiliki tepat dua allow principals:

- service SID journal/time: full control atas pipe instance miliknya; dan
- exact checkout-client account SID dari manifest: hanya create/connect,
  read-data, write-data, read-attributes, dan synchronize yang diperlukan.

Tidak ada ACE untuk Everyone, Authenticated Users, Anonymous Logon, Network,
Users, interactive user lain, atau arbitrary group. Remote rejection tetap
wajib walaupun DACL tampak sempit. Native test harus membuktikan effective
rights dan menolak squatting/second-instance.

Client memvalidasi journal/time server sebelum mengirim frame dan sesudah response:

- service name/status dari SCM, server PID dari pipe, serta PID
  `QueryServiceStatusEx` wajib exact-equal;
- client menahan process handle server dan mengikat process creation time,
  session, canonical image path+digest, signer/manifest generation, service
  account SID, service SID, `AuthenticationId`, dan `TokenId`;
- image, manifest, process, dan token snapshot sebelum/sesudah wajib stabil.

Journal/time service memvalidasi client sebelum menyentuh journal:

- client PID dari pipe diikat ke process handle yang ditahan, creation time,
  session, canonical image path+digest, dan accepted candidate manifest;
- `ImpersonateNamedPipeClient` harus sukses; thread token wajib exact expected
  client user/logon SID, token identity, process identity, dan request binding;
- client meminta exact SQOS `SecurityImpersonation`; anonymous,
  identification, dan delegation ditolak;
- `RevertToSelf` wajib selalu dicoba. Setelah sukses,
  `OpenThreadToken` harus membuktikan `ERROR_NO_TOKEN` sebelum service memakai
  service primary token. Kegagalan revert adalah process-fatal setelah
  best-effort cleanup.

SID atau PID tunggal bukan authentication. Semua handle dimiliki service/client
boundary masing-masing, tidak diekspor, non-inheritable, dan ditutup exact sekali.

### 4. Protocol one-shot

Closed journal/time codec v1 mempunyai exact methods `journal.read`,
`journal.compare-and-append`, `time.challenge`, dan `time.validate`. Setiap client
request memakai fresh 256-bit operation challenge, opaque `requestId`, exact
journal/time-service-start identity, manifest digest+generation, run/session,
lifecycle boundary+phase, config/lease binding, authority role/namespace,
operation digest, dan trusted-time/revocation/witness references. Caller tidak
memasok path, SID, account, raw timestamp source, native handle, DPAPI entropy,
TPM selector, access mask, atau error mode.

Request dan response adalah bounded ASCII sorted-key canonical JSON dengan satu
LF, exact version/key/type/order, domain-separated digest, dan tidak menerima
duplicate, extra, nonfinite, bool-as-int, invalid encoding, atau trailing data.
Setiap `(journalTimeServiceStartIdentity, requestId, challenge, operationDigest)` hanya dapat
dikonsumsi satu kali. Refusal tidak dapat diubah menjadi retry otomatis; request
baru memerlukan authority dan challenge baru.

Response hanya `refused`, `observed`, atau `committed`. `refused` memuat fixed
redacted code dan seluruh request binding tanpa native detail. `observed` hanya
untuk read yang tidak mengubah state. `committed` hanya untuk successful
compare-and-append yang telah melewati journal, DPAPI, flush, witness, time, dan
post-validation. `time.validate` hanya menghasilkan bounded interval result;
ia tidak mengubah journal dan tidak membuat artifact menjadi trusted sendiri.

### 5. Protected append/CAS journal

Journal berada pada fixed manifest path di bawah directory admin-owned. Root,
directory, journal, dan lock/CAS object dibuka melalui pinned parent/held handle
tanpa path discovery. DACL protected dan non-inherited memberi:

- journal/time service SID minimum read/append/write-attributes/synchronize rights;
- SYSTEM dan BUILTIN\Administrators full control untuk servicing/recovery; dan
- tidak ada access untuk checkout client, ordinary users, atau broad groups.

Owner, group, ordered ACE, mask, descriptor digest, final path, no-reparse state,
volume/file identity, dan handle inheritance divalidasi sebelum dan sesudah
operasi. Journal handle ditahan dengan sharing yang menolak replacement,
delete, dan write lain selama lifecycle.

Record append-only mengikat exact version, kind, namespace, operation ID,
journal/time-service-start/run/lifecycle binding, current dan proposed generation/state digest,
previous-record digest, revocation-set digest, trusted-time evidence digest,
rollback-witness identity/generation/digest, request digest, record digest, dan
commit marker. Bootstrap hanya `0 -> 1`; setelah itu proposed generation wajib
strictly lebih tinggi sesuai namespace contract. One-shot ledger wajib exact
`current + 1`. Same-generation, rollback, forked previous digest, duplicate
operation/replay ID, cross-namespace, atau ambiguous head ditolak.

Setiap record dienkripsi dan dilindungi memakai Windows DPAPI dalam exact
**current journal/time service-user scope**. `CryptProtectData` dan
`CryptUnprotectData` memakai flags exact `CRYPTPROTECT_UI_FORBIDDEN`; flag
`CRYPTPROTECT_LOCAL_MACHINE`, prompt/UI, audit flag, dan flag lain dilarang.
`pOptionalEntropy` exact `NULL` pada v1 sehingga tidak ada entropy eksternal,
caller-selected entropy, atau entropy-custody fallback. Parameter
`szDataDescr`, `pvReserved`, `pPromptStruct`, dan unprotect `ppszDataDescr`
wajib exact `NULL`. Service profile wajib
dimuat dan dipin ke exact account SID/profile identity sebelum DPAPI; master-key
profile hanya dapat dikelola OS di bawah account tersebut dan tidak boleh
disalin, diekspor, atau dipilih caller.

Credential rotation wajib berupa lifecycle administratif versioned: hentikan
admission dan service, buktikan journal/witness pair current, lakukan rotasi SCM/
LSA tanpa memindahkan account identity, start service generation baru, lalu
buktikan decrypt exact production head secara read-only serta protect/unprotect
dan re-encrypt/CAS hanya pada disposable fixture. Production head tidak ditulis
ulang; append berikutnya tetap melalui CAS+witness normal setelah seluruh gate
lulus. Password reset,
profile/master-key replacement, account migration, restore, atau decrypt failure
tanpa separately accepted recovery authority tetap fail closed. Native evidence
wajib membuktikan exact flags/scope, loaded-profile identity, cross-account dan
machine-copy denial, rotation/crash behavior, buffer cleanup, serta bahwa tidak
ada UI/entropy fallback. DPAPI memberi confidentiality dan integrity pada bytes,
tetapi **bukan** anti-rollback. Ciphertext, checksum, record chain, filesystem
ACL, wall clock, dan service memory tidak boleh dipakai sebagai monotonic
authority.

Compare-and-append memegang single-writer lock, memvalidasi current head, menulis
record baru dengan write-through, memanggil `FlushFileBuffers`, memvalidasi ulang
exact bytes/identity/descriptor, memajukan rollback witness, lalu memublikasikan
head committed hanya jika seluruh binding konsisten. Urutan commit implementation
wajib menyediakan recovery yang tidak pernah menerima journal head tanpa
witness yang cocok; tidak boleh ada success response sebelum file dan witness
berada pada accepted pair.

### 6. Mandatory anti-rollback authority

Setiap accepted head wajib terikat pada salah satu authority berikut yang telah
diterima secara terpisah:

1. TPM-backed monotonic counter/state yang diikat ke machine, journal/time service identity,
   manifest generation, journal namespace, dan record digest; atau
2. external signed witness dari role/key terpisah yang mengikat exact same
   fields dan generation serta diverifikasi melalui trust/revocation ADR-021.

Tidak ada software-only fallback. Missing/unavailable TPM, external witness yang
tidak tersedia, unverifiable signature, generation regression/equality yang
tidak diizinkan, restored disk, cloned state, atau journal/witness mismatch
menolak read, append, recovery, dan admission.

TPM atau external witness technology, provisioning, handle/session lifetime,
NV/index/anti-hammering policy, rotation, and disaster recovery harus memiliki
implementation decision dan native evidence terpisah. Dokumen ini tidak
menganggap TBS atau keberadaan TPM sebagai bukti bahwa counter telah aman.

### 7. Signed trusted time dan revocation

Jam lokal, file mtime, process uptime, dan network time tidak menjadi authority.
Current time pertama kali dibentuk hanya melalui fresh challenge-response dari
separately provisioned trusted-time signer; tidak boleh divalidasi memakai
snapshot waktu lama yang sedang hendak digantikan. `time.challenge` membuat
fresh random 256-bit **signer challenge**, distinct dari operation challenge
client, lalu secara CAS menyimpan pending challenge ke journal+witness dan
mengembalikan canonical signer request yang mengikat challenge, exact source
operation request digest, input-set digest,
journal/time-service start, machine/run/session/namespace, manifest generation,
rollback-witness identity+current generation, serta exact trust dan revocation
snapshot digests/generations. Signer mengembalikan satu signed canonical interval
response yang mengulang seluruh binding tersebut dan menambahkan lower/upper,
issued-at, expires-at, signer key/trust generation, dan replay ID.

Untrusted caller merelay exact signer request ke offline signer, lalu memasukkan
signed response sebagai payload exact `time.validate`; tidak ada shared listener
atau direct network authority. Signature/domain/key/trust/revocation identities
dan rollback-witness generation diverifikasi lebih dahulu tanpa menganggap local
UTC sebagai current. Response wajib tiba dalam monotonic elapsed timeout exact
lima menit sejak signer challenge dibuat, dan interval `upper - lower` wajib
positif serta tidak melebihi lima menit. Pending challenge, response replay ID,
dan exact response digest kemudian dikonsumsi atomically ke protected journal+
witness sebelum interval dapat dipakai. Sesudah signature dan monotonic binding
lulus, interval response menjadi candidate trusted time; barulah
issued/expiry serta exact revocation `issuedAt/nextUpdate` diuji harus mencakup
seluruh candidate interval. Timeout, replay, witness drift, revocation interval
yang tidak mencakupnya, atau response yang tidak exact menolak. Monotonic elapsed
timer hanya membatasi round trip dan tidak menjadi UTC authority.

Untuk memutus dependency melingkar, `time.challenge` memakai record CAS pending
`trusted-time-challenge-v1`, dan konsumsi `time.validate` memakai record CAS
terminal `trusted-time-bootstrap-v1`. Kedua record boleh committed tanpa
trusted-time lama hanya setelah exact request binding, current rollback witness,
dan—untuk terminal record—signature, revocation structure+generation serta
candidate-interval coverage lulus. Keduanya hanya mengikat challenge/replay/
response digest dan tidak dapat memajukan namespace artifact, one-shot ledger,
atau journal mutation lain. Pending state tidak boleh dipulihkan setelah service
restart atau monotonic-timer uncertainty. Setelah terminal record+witness menjadi
accepted pair, candidate interval boleh mengotorisasi operasi asal; seluruh
compare-and-append umum tetap memerlukan interval current tersebut. Failure
sebelum pair committed tidak menerbitkan waktu atau success parsial.

Signer dan private key tetap offline dan tidak memperoleh network/service
authority dari ADR ini. Relay tidak dapat memilih atau mengubah binding, dan
availability operator tidak mengubah refusal menjadi fallback.

Trusted-time role menerbitkan signed canonical interval response dengan exact:

- schema/domain, artifact ID, issuer/key/trust generation, replay ID;
- machine identity, journal/time-service-start identity, run/session, lifecycle boundary,
  config/lease binding, dan input-set digest;
- lower dan upper UTC bounds, issued-at, expires-at, serta rollback-witness
  identity+generation; dan
- exact digest/generation/namespace dari revocation snapshot set yang dipakai.

Interval wajib positif, UTC canonical, bounded, dan dievaluasi setengah-terbuka
`lower <= trustedNow < upper`. Consumer hanya boleh mempersempit interval;
intersection kosong, bound mundur, unavailable evidence, clock/witness
regression, future issue, expiry, replay, revocation, atau perubahan input set
menolak. Local wall clock boleh memperketat refusal tetapi tidak memperlebar
interval atau menaikkan trust.

Setiap authority namespace memakai signed revocation snapshot ADR-021 dari
independent 2-of-3 custodians, dengan generation strictly higher dari protected
high-water saat advance. Same-generation snapshot tidak menjadi freshness proof.
Exact trusted-time response dan revocation bytes/digests dipin selama
journal operation, dan sebelum hasil dipakai. Ketika trusted time atau
revocation tidak dapat dibuktikan current, seluruh operation fail closed.

### 8. Evidence dan privacy

Native acceptance evidence untuk setiap operation wajib mengikat:

- exact canonical request/response digest dan one-shot consumption;
- journal/time manifest/start, service/client mutual-auth snapshots, process/token
  before/after stability, pipe descriptor/effective access, dan handle census;
- journal path/identity/descriptor before/after, prior/new record digest,
  generation/CAS result, DPAPI service-user scope/flags/profile identity,
  write-through/flush result, dan committed head;
- rollback authority kind, identity, generation, pre/post value, request/record
  binding, and recovery decision;
- trusted-time interval evidence, input-set digest, revocation snapshots, fresh
  challenge/request-digest binding, monotonic timeout, atomic replay consumption,
  protected high-water before/after, dan refusal reason category; serta
- crash/reboot step, cleanup outcome, and proof that no partial success was
  exposed.

Serialized public evidence hanya memuat opaque IDs/digests, generation, coarse
UTC interval, role/namespace, fixed status, dan lifecycle binding yang diperlukan.
Username, SID, local path, pipe name, process/token detail, security descriptor,
DPAPI blob/entropy, TPM raw data, private key, credential, request payload,
native error, stack trace, atau host inventory tidak masuk log/browser/user output.
Detail sensitif hanya hidup dalam sealed private composition dan dibuang setelah
operation. Error publik memakai fixed code `broker_journal_unavailable`.

### 9. Crash, reboot, recovery, dan cleanup

Failure injection wajib mencakup setiap boundary: sebelum/selama append, sesudah
flush namun sebelum witness advance, sesudah witness advance namun sebelum head
publication, disconnect, journal/time service termination, power-loss simulation, reboot,
DPAPI failure, witness timeout, snapshot expiry, dan cleanup failure.

Recovery tidak men-truncate, menulis ulang, mengadopsi orphan, menurunkan
generation, atau auto-repair. Ia membuka journal read-only terlebih dahulu,
memvalidasi seluruh chain serta DPAPI, memperoleh fresh signed time/revocation
state, lalu mencocokkan exact highest committed record dengan TPM/external
witness. Partial tail, witness-ahead, journal-ahead, fork, corrupt slot/record,
ambiguous active head, lost high-water, atau uncertain reboot tetap fail closed.
Lost high-water hanya dapat dipulihkan oleh separately accepted TPM/external
recovery authority yang membuktikan exact monotonic state, namespace, trust/
manifest generation, journal head, dan full prior witness chain sesuai ADR-021.
Operator claim, memilih angka yang tampak lebih tinggi, membuat generation baru,
atau menandatangani ulang local observation tidak pernah menjadi recovery
evidence.

Cleanup selalu mencoba revert impersonation, discard one-shot state, release
lock, zero sensitive buffers where supported, dan close setiap real handle exact
sekali. Error cleanup tidak menutupi primary error; `KeyboardInterrupt` dan
`SystemExit` tetap dipreservasi. Service restart mengganti journal/time-service-start identity
dan menginvalidasi in-flight/cache state, tetapi tidak me-reset journal/witness.

### 10. Acceptance boundary

Pure/synthetic tests boleh membuktikan closed schema, canonical bytes, bounds,
domain/role separation, request-response cross-binding, one-shot state machine,
CAS planner, chain linkage, signed-fixture verification, fixed redaction,
dependency mutation refusal, and failure-order model. Hasil itu structural-only.

Native Windows acceptance pada disposable host wajib membuktikan:

- exact distinct journal/time account/service/endpoint/manifest/codec, effective
  user rights, groups, raw two-entry privilege set+attributes, service SID/type,
  SCM/service-object/binary/manifest/firewall policy;
- actual pipe DACL/effective access, local-only behavior, squatting resistance,
  client/server mutual auth, PID reuse defense, image/token stability, and exact
  impersonation/revert semantics;
- actual journal ACL, no-reparse/path/identity continuity, handle sharing,
  DPAPI exact service-user scope/flags/profile/master-key and rotation behavior,
  append/CAS/write-through/flush durability,
  and handle/memory cleanup;
- accepted TPM monotonic or external signed witness implementation, including
  rollback/clone/restore, unavailable authority, rotation, and recovery;
- fresh challenge/request-bound signed trusted-time validation, monotonic timeout,
  atomic replay consumption, revocation coverage, regression, expiry, replay,
  cross-host/run/namespace confusion, and high-water persistence;
- concurrent clients, crash at every boundary, journal/time service restart, reboot/power
  interruption, corrupted/truncated/forked journal, and forensic retention; dan
- exact integration dengan ADR-017/018/019/020/021 lifecycle tanpa cross-service,
  endpoint, account, manifest, codec, cache, authority, provider, browser,
  payment, network, atau gate fallback.

Acceptance contract ini tidak menerima atau mengaktifkan account, service,
named pipe, ACL, DPAPI/TPM, witness, trusted-time issuer, native provider,
candidate, browser, payment, deploy, atau runtime. Semua implementation dan
native evidence memerlukan review terpisah sebelum composition dapat memakai
hasil sebagai admission.

## Alternatif yang ditolak

### Memperluas ordinary-access broker ADR-019

Ditolak. Menambahkan journal/time method, account rights, DPAPI profile, witness,
atau trusted-time cache ke service ADR-019 akan menggabungkan ordinary denial
provider dengan state authority. Service/account/endpoint/manifest/codec v1 pada
ADR ini harus tetap berbeda dan di-cross-bind hanya oleh composition.

### Software journal, checksum, atau DPAPI saja

Ditolak sebagai anti-rollback. Semuanya dapat dipulihkan bersama disk snapshot.
DPAPI tetap dipakai untuk confidentiality/integrity record, bukan monotonicity.

### Local wall clock, mtime, uptime, atau network time

Ditolak sebagai authority. Nilai dapat mundur, unavailable, atau berasal dari
source yang tidak terikat trust/revocation lifecycle.

### LocalSystem atau shared service account

Ditolak karena privilege luas atau identity dipakai lintas service dan tidak
membuktikan dedicated ordinary principal.

### Username/password, raw token/handle, path, atau clock selector dari caller

Ditolak karena memindahkan authority dan material sensitif ke aplikasi serta
membuka confused-deputy surface.

### Named pipe default DACL atau autentikasi PID saja

Ditolak karena default/broad principals dan PID reuse tidak membuktikan exact
service/client process/token identity.

### Auto-repair atau fallback ketika witness/time unavailable

Ditolak. Availability tidak boleh mengubah uncertain state menjadi trusted.

## Konsekuensi

- Journal/time service menjadi security boundary tersendiri yang harus dipatch,
  diprovisikan, diaudit, dan diuji crash/reboot terpisah dari broker ADR-019.
- DPAPI melindungi record tetapi operational recovery membutuhkan identity
  service/account yang sama atau prosedur rotation yang diterima.
- TPM/external witness dan trusted-time signing menambah authority/custody serta
  recovery ceremony; tanpa authority tersebut kandidat tetap unavailable.
- Exact pipe/DACL/token policy mengurangi permukaan tetapi meningkatkan
  dependency pada host policy dan bukti native.
- ADR-018/019/020/021 tetap berlaku. Jika terdapat konflik, kontrak yang lebih
  sempit dan fail-closed di ADR ini berlaku untuk journal/trusted-time boundary;
  perubahan schema/role tetap memerlukan versioning dan review.

## Status implementasi dan larangan

Tidak ada bagian dari ADR ini yang memberi izin untuk membuat akun/service,
mengubah Local Security Policy/ACL/firewall, mengakses TPM, menerbitkan key atau
snapshot, membuka pipe, menginstal binary, menjalankan browser/provider,
mengaktifkan source/gate/payment, atau deploy. P15, P16, P17c, dan P18 tetap
terbuka; checklist serta progres tidak berubah.

## Referensi

- [ADR-018: Provider akses ordinary Windows](0018-windows-ordinary-access-provider.md)
- [ADR-019: Provisioning principal ordinary Windows](0019-windows-ordinary-principal-provisioning.md)
- [ADR-020: Authority validasi evidence ordinary Windows](0020-ordinary-evidence-validation-authority.md)
- [ADR-021: Authority persiapan release kandidat checkout](0021-checkout-release-preparation-authority.md)
- [Microsoft Learn: Service user accounts](https://learn.microsoft.com/en-us/windows/win32/services/service-user-accounts)
- [Microsoft Learn: Account rights constants](https://learn.microsoft.com/en-us/windows/win32/secauthz/account-rights-constants)
- [Microsoft Learn: Service security and access rights](https://learn.microsoft.com/en-us/windows/win32/services/service-security-and-access-rights)
- [Microsoft Learn: SERVICE_REQUIRED_PRIVILEGES_INFO](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/ns-winsvc-service_required_privileges_infow)
- [Microsoft Learn: Privilege constants](https://learn.microsoft.com/en-us/windows/win32/secauthz/privilege-constants)
- [Microsoft Learn: SERVICE_SID_INFO](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/ns-winsvc-service_sid_info)
- [Microsoft Learn: Named pipe security and access rights](https://learn.microsoft.com/en-us/windows/win32/ipc/named-pipe-security-and-access-rights)
- [Microsoft Learn: CreateNamedPipeW](https://learn.microsoft.com/en-us/windows/win32/api/namedpipeapi/nf-namedpipeapi-createnamedpipew)
- [Microsoft Learn: ImpersonateNamedPipeClient](https://learn.microsoft.com/en-us/windows/win32/api/namedpipeapi/nf-namedpipeapi-impersonatenamedpipeclient)
- [Microsoft Learn: GetNamedPipeClientProcessId](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-getnamedpipeclientprocessid)
- [Microsoft Learn: GetNamedPipeServerProcessId](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-getnamedpipeserverprocessid)
- [Microsoft Learn: QueryServiceStatusEx](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/nf-winsvc-queryservicestatusex)
- [Microsoft Learn: CryptProtectData](https://learn.microsoft.com/en-us/windows/win32/api/dpapi/nf-dpapi-cryptprotectdata)
- [Microsoft Learn: CryptUnprotectData](https://learn.microsoft.com/en-us/windows/win32/api/dpapi/nf-dpapi-cryptunprotectdata)
- [Microsoft Learn: FlushFileBuffers](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-flushfilebuffers)
- [Microsoft Learn: About TPM Base Services](https://learn.microsoft.com/en-us/windows/win32/tbs/about-tbs)
- [Microsoft Learn: GetSystemTimePreciseAsFileTime](https://learn.microsoft.com/en-us/windows/win32/api/sysinfoapi/nf-sysinfoapi-getsystemtimepreciseasfiletime)
