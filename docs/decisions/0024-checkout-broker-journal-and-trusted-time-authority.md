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
bagaimana client dan broker saling mengautentikasi, serta bukti apa yang wajib
ada sebelum waktu atau generation boleh dipakai. Kontrak tidak boleh membawa
credential, raw handle, arbitrary path, atau native error ke aplikasi.

## Keputusan

ADR ini menerima desain authority dan failure policy saja. Ia tidak membuat
akun atau service, mengubah user rights/ACL, mengakses TPM, memasang binary,
menjalankan native API, membangun kandidat, atau mengaktifkan gate.

### 1. Principal dan service boundary

Provider berjalan sebagai satu Windows service khusus
`SERVICE_WIN32_OWN_PROCESS` di bawah dedicated local Windows user yang:

- non-admin, bukan LocalSystem `S-1-5-18`, LocalService, NetworkService,
  virtual account, managed service account, atau akun yang dipakai service lain;
- hanya dipakai untuk broker checkout ini dan tidak menjadi account selector
  yang dapat dipilih caller;
- memiliki satu grant logon exact `SeServiceLogonRight`;
- tidak memiliki grant `SeInteractiveLogonRight`, `SeRemoteInteractiveLogonRight`,
  `SeNetworkLogonRight`, atau `SeBatchLogonRight`;
- memiliki deny rights exact `SeDenyInteractiveLogonRight`,
  `SeDenyRemoteInteractiveLogonRight`, `SeDenyNetworkLogonRight`, dan
  `SeDenyBatchLogonRight` ketika host/domain policy mendukung kombinasi itu;
  konflik Group Policy atau inability to prove effective rights menolak
  readiness, bukan melonggarkan policy; dan
- tidak memiliki profile, desktop, share, outbound network dependency, SPN,
  delegation, scheduled task, atau general launcher role.

Token privilege allowlist service v1 hanya:

1. `SeChangeNotifyPrivilege`, yang dapat dipertahankan SCM untuk compatibility;
2. `SeImpersonatePrivilege`, hanya untuk autentikasi client named pipe dan
   pembukaan target pada interval impersonation yang ditetapkan ADR-019.

`SeBackupPrivilege`, `SeRestorePrivilege`, `SeTakeOwnershipPrivilege`,
`SeDebugPrivilege`, `SeTcbPrivilege`, `SeAssignPrimaryTokenPrivilege`, dan
privilege lain di luar allowlist wajib absent atau disabled sesuai policy exact.
Required-privilege list, local security policy, group membership, token groups,
restricting SID, AppContainer state, dan privilege attributes dibaca ulang pada
setiap start dan sebelum/sesudah request. Extra atau drift menolak request.

SCM start type adalah demand-only dan default stopped. Service SID exact hadir
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
| broker identity | Manifest admin-owned + live SCM/process/token evidence | Membuktikan exact service instance; PID saja tidak cukup. |
| journal storage | Exact broker service instance | Memegang handle journal, melakukan read atau compare-and-append, DPAPI, flush, dan cleanup. |
| rollback witness | TPM-backed monotonic authority **atau** independently signed external witness | Membuktikan monotonic generation; tidak dapat diganti file/checksum/jam lokal. |
| trusted time | Distinct offline trusted-time signing key dalam trust-root/revocation regime ADR-021 | Menerbitkan interval waktu terikat host/run/namespace, bukan mengatur jam host. |
| revocation | Independent 2-of-3 revocation custodians ADR-021 | Menerbitkan snapshot exact namespace/generation; bukan artifact issuer atau broker. |
| consumer | Repository verifier/composition | Memvalidasi exact evidence dan lifecycle binding; tidak memilih principal, path, clock, witness, atau policy. |

Runtime role `broker-journal`, `rollback-witness`, dan `trusted-time` memakai
domain signature serta key/generation terpisah. Penambahan namespace artifact
harus melalui codec/trust-bundle/revocation version yang accepted; literal role
dalam dokumen ini tidak membuat implementation authority secara otomatis.

### 3. IPC local dan mutual authentication

Broker memakai fixed local named-pipe namespace dari manifest admin-owned.
Journal dan ordinary-access provider memakai endpoint serta closed method set
terpisah; tidak ada generic operation selector atau fallback lintas endpoint.

Pipe dibuat dengan `FILE_FLAG_FIRST_PIPE_INSTANCE`,
`PIPE_REJECT_REMOTE_CLIENTS`, message mode, satu active instance, handle
non-inheritable, finite timeout, tepat satu request frame dan satu response
frame. Request maksimum 20 KiB dan response maksimum 36 KiB. Partial frame,
trailing byte, second frame, oversize, duplicate/in-flight request, disconnect,
atau timeout menolak seluruh operasi.

Security descriptor pipe wajib protected, tanpa inherited/default DACL. Owner
adalah exact service SID. DACL v1 memiliki tepat dua allow principals:

- service SID broker: full control atas pipe instance miliknya; dan
- exact checkout-client account SID dari manifest: hanya create/connect,
  read-data, write-data, read-attributes, dan synchronize yang diperlukan.

Tidak ada ACE untuk Everyone, Authenticated Users, Anonymous Logon, Network,
Users, interactive user lain, atau arbitrary group. Remote rejection tetap
wajib walaupun DACL tampak sempit. Native test harus membuktikan effective
rights dan menolak squatting/second-instance.

Client memvalidasi server sebelum mengirim frame dan sesudah response:

- service name/status dari SCM, server PID dari pipe, serta PID
  `QueryServiceStatusEx` wajib exact-equal;
- client menahan process handle server dan mengikat process creation time,
  session, canonical image path+digest, signer/manifest generation, service
  account SID, service SID, `AuthenticationId`, dan `TokenId`;
- image, manifest, process, dan token snapshot sebelum/sesudah wajib stabil.

Broker memvalidasi client sebelum menyentuh journal:

- client PID dari pipe diikat ke process handle yang ditahan, creation time,
  session, canonical image path+digest, dan accepted candidate manifest;
- `ImpersonateNamedPipeClient` harus sukses; thread token wajib exact expected
  client user/logon SID, token identity, process identity, dan request binding;
- client meminta exact SQOS `SecurityImpersonation`; anonymous,
  identification, dan delegation ditolak;
- `RevertToSelf` wajib selalu dicoba. Setelah sukses,
  `OpenThreadToken` harus membuktikan `ERROR_NO_TOKEN` sebelum broker memakai
  service primary token. Kegagalan revert adalah process-fatal setelah
  best-effort cleanup.

SID atau PID tunggal bukan authentication. Semua handle dimiliki broker/client
boundary masing-masing, tidak diekspor, non-inheritable, dan ditutup exact sekali.

### 4. Protocol one-shot

Closed methods v1 adalah `journal.read`, `journal.compare-and-append`, dan
`time.validate`. Setiap request memakai fresh 256-bit challenge, opaque
`requestId`, exact broker-start identity, manifest digest+generation, run/session,
lifecycle boundary+phase, config/lease binding, authority role/namespace,
operation digest, dan trusted-time/revocation/witness references. Caller tidak
memasok path, SID, account, raw timestamp source, native handle, DPAPI entropy,
TPM selector, access mask, atau error mode.

Request dan response adalah bounded ASCII sorted-key canonical JSON dengan satu
LF, exact version/key/type/order, domain-separated digest, dan tidak menerima
duplicate, extra, nonfinite, bool-as-int, invalid encoding, atau trailing data.
Setiap `(brokerStartIdentity, requestId, challenge, operationDigest)` hanya dapat
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

- broker service SID minimum read/append/write-attributes/synchronize rights;
- SYSTEM dan BUILTIN\Administrators full control untuk servicing/recovery; dan
- tidak ada access untuk checkout client, ordinary users, atau broad groups.

Owner, group, ordered ACE, mask, descriptor digest, final path, no-reparse state,
volume/file identity, dan handle inheritance divalidasi sebelum dan sesudah
operasi. Journal handle ditahan dengan sharing yang menolak replacement,
delete, dan write lain selama lifecycle.

Record append-only mengikat exact version, kind, namespace, operation ID,
broker-start/run/lifecycle binding, current dan proposed generation/state digest,
previous-record digest, revocation-set digest, trusted-time evidence digest,
rollback-witness identity/generation/digest, request digest, record digest, dan
commit marker. Bootstrap hanya `0 -> 1`; setelah itu proposed generation wajib
strictly lebih tinggi sesuai namespace contract. One-shot ledger wajib exact
`current + 1`. Same-generation, rollback, forked previous digest, duplicate
operation/replay ID, cross-namespace, atau ambiguous head ditolak.

Setiap record dienkripsi dan dilindungi memakai Windows DPAPI dalam exact
service/account scope. Optional entropy berasal dari sealed composition dan
tidak masuk request/log. DPAPI memberi confidentiality dan integrity pada bytes,
tetapi **bukan** anti-rollback. Ciphertext, checksum, record chain, filesystem
ACL, wall clock, dan broker memory tidak boleh dipakai sebagai monotonic
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

1. TPM-backed monotonic counter/state yang diikat ke machine, broker identity,
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
Trusted-time role menerbitkan signed canonical interval snapshot dengan exact:

- schema/domain, artifact ID, issuer/key/trust generation, replay ID;
- machine identity, broker-start identity, run/session, lifecycle boundary,
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
Exact trusted-time dan revocation bytes/digests dipin sebelum request, selama
journal operation, dan sebelum hasil dipakai. Ketika trusted time atau
revocation tidak dapat dibuktikan current, seluruh operation fail closed.

### 8. Evidence dan privacy

Native acceptance evidence untuk setiap operation wajib mengikat:

- exact canonical request/response digest dan one-shot consumption;
- broker manifest/start, service/client mutual-auth snapshots, process/token
  before/after stability, pipe descriptor/effective access, dan handle census;
- journal path/identity/descriptor before/after, prior/new record digest,
  generation/CAS result, DPAPI scope, write-through/flush result, dan committed
  head;
- rollback authority kind, identity, generation, pre/post value, request/record
  binding, and recovery decision;
- trusted-time interval evidence, input-set digest, revocation snapshots,
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
publication, disconnect, broker termination, power-loss simulation, reboot,
DPAPI failure, witness timeout, snapshot expiry, dan cleanup failure.

Recovery tidak men-truncate, menulis ulang, mengadopsi orphan, menurunkan
generation, atau auto-repair. Ia membuka journal read-only terlebih dahulu,
memvalidasi seluruh chain serta DPAPI, memperoleh fresh signed time/revocation
state, lalu mencocokkan exact highest committed record dengan TPM/external
witness. Partial tail, witness-ahead, journal-ahead, fork, corrupt slot/record,
ambiguous active head, lost high-water, atau uncertain reboot tetap fail closed
dan memerlukan separately authorized recovery evidence dengan generation yang
lebih tinggi dari seluruh candidate yang pernah diamati.

Cleanup selalu mencoba revert impersonation, discard one-shot state, release
lock, zero sensitive buffers where supported, dan close setiap real handle exact
sekali. Error cleanup tidak menutupi primary error; `KeyboardInterrupt` dan
`SystemExit` tetap dipreservasi. Service restart mengganti broker-start identity
dan menginvalidasi in-flight/cache state, tetapi tidak me-reset journal/witness.

### 10. Acceptance boundary

Pure/synthetic tests boleh membuktikan closed schema, canonical bytes, bounds,
domain/role separation, request-response cross-binding, one-shot state machine,
CAS planner, chain linkage, signed-fixture verification, fixed redaction,
dependency mutation refusal, and failure-order model. Hasil itu structural-only.

Native Windows acceptance pada disposable host wajib membuktikan:

- exact account, effective user rights, groups, privileges, service SID/type,
  SCM/service-object/binary/manifest/firewall policy;
- actual pipe DACL/effective access, local-only behavior, squatting resistance,
  client/server mutual auth, PID reuse defense, image/token stability, and exact
  impersonation/revert semantics;
- actual journal ACL, no-reparse/path/identity continuity, handle sharing,
  DPAPI service/account isolation, append/CAS/write-through/flush durability,
  and handle/memory cleanup;
- accepted TPM monotonic or external signed witness implementation, including
  rollback/clone/restore, unavailable authority, rotation, and recovery;
- signed trusted-time/revocation validation, regression, expiry, replay,
  cross-host/run/namespace confusion, and high-water persistence;
- concurrent clients, crash at every boundary, broker restart, reboot/power
  interruption, corrupted/truncated/forked journal, and forensic retention; dan
- exact integration dengan ADR-017/018/019/020/021 lifecycle tanpa provider,
  browser, payment, network, atau gate fallback.

Acceptance contract ini tidak menerima atau mengaktifkan account, service,
named pipe, ACL, DPAPI/TPM, witness, trusted-time issuer, native provider,
candidate, browser, payment, deploy, atau runtime. Semua implementation dan
native evidence memerlukan review terpisah sebelum composition dapat memakai
hasil sebagai admission.

## Alternatif yang ditolak

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

- Broker menjadi security boundary yang harus dipatch, diprovisikan, diaudit,
  dan diuji crash/reboot secara terpisah.
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
- [Microsoft Learn: SERVICE_SID_INFO](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/ns-winsvc-service_sid_info)
- [Microsoft Learn: Named pipe security and access rights](https://learn.microsoft.com/en-us/windows/win32/ipc/named-pipe-security-and-access-rights)
- [Microsoft Learn: CreateNamedPipeW](https://learn.microsoft.com/en-us/windows/win32/api/namedpipeapi/nf-namedpipeapi-createnamedpipew)
- [Microsoft Learn: ImpersonateNamedPipeClient](https://learn.microsoft.com/en-us/windows/win32/api/namedpipeapi/nf-namedpipeapi-impersonatenamedpipeclient)
- [Microsoft Learn: GetNamedPipeClientProcessId](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-getnamedpipeclientprocessid)
- [Microsoft Learn: GetNamedPipeServerProcessId](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-getnamedpipeserverprocessid)
- [Microsoft Learn: QueryServiceStatusEx](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/nf-winsvc-queryservicestatusex)
- [Microsoft Learn: CryptProtectData](https://learn.microsoft.com/en-us/windows/win32/api/dpapi/nf-dpapi-cryptprotectdata)
- [Microsoft Learn: FlushFileBuffers](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-flushfilebuffers)
- [Microsoft Learn: About TPM Base Services](https://learn.microsoft.com/en-us/windows/win32/tbs/about-tbs)
- [Microsoft Learn: GetSystemTimePreciseAsFileTime](https://learn.microsoft.com/en-us/windows/win32/api/sysinfoapi/nf-sysinfoapi-getsystemtimepreciseasfiletime)
