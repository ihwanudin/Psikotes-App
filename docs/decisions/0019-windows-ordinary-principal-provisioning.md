# ADR-019: Provisioning principal ordinary Windows untuk attestation checkout

## Status

Accepted untuk keputusan desain dan authority provisioning saja. Akun, service,
broker, IPC, provider native, candidate, browser, payment, dan deployment belum
dibuat, diuji secara native, diterima, atau diaktifkan.

## Date

2026-09-06

## Context

ADR-017 mewajibkan bukti bahwa principal ordinary kedua ditolak oleh DACL tiga
target checkout. ADR-018 menetapkan contract provider dan evidence, tetapi belum
memilih siapa yang memprovisikan principal itu atau bagaimana token independen
diperoleh tanpa credential, account selector, atau raw handle dari caller.

Menduplikasi current-process token tetap bukan bukti principal kedua. Sebaliknya,
memberikan password akun uji kepada aplikasi, repository, environment, atau IPC
akan memindahkan authority keamanan kepada proses checkout. Akun built-in yang
dipakai banyak service juga tidak memberi identitas lokal khusus untuk bukti ini.

Microsoft mendokumentasikan bahwa SCM menjalankan service dalam security context
akun yang dikonfigurasi dan membuat access token bagi proses service. Credential
akun service disimpan oleh SCM pada bagian aman LSA. Karena itu lifecycle akun
dan service dapat diletakkan di boundary administratif Windows, di luar aplikasi.

## Decision

ADR ini melengkapi ADR-018. Ia menggantikan hanya pernyataan bahwa mekanisme
provisioning belum dipilih; seluruh schema, policy, lifecycle, dan larangan
ADR-017/ADR-018 tetap berlaku.

### Authority provisioning

Satu administrator host memprovisikan satu local Windows user khusus, non-admin,
dan satu Windows service khusus sebagai `SERVICE_WIN32_OWN_PROCESS`. Service
berjalan hanya di bawah local user tersebut dan menjadi provider ADR-018.
Checkout process hanya menjadi client internal provider.

Provisioning wajib out-of-band melalui prosedur administratif terpisah. Aplikasi,
launcher, coordinator, browser, request JSON, database, environment, repository,
dan log tidak pernah menerima atau memilih username, password, domain, account
selector, atau material rotation/recovery. SID bukan credential: internal provider
client boleh membaca account/service SID hanya dari manifest admin-owned sebagai
pinned authority, tetapi caller tidak boleh memasok/memilihnya dan nilainya tidak
boleh dicatat atau dikirim ke browser. Credential hanya boleh masuk pada boundary
instalasi/rotasi administratif dan dikelola SCM/LSA; kegagalan logon atau password
expiry harus menghentikan service dan membuat admission fail closed.

Local user dan service bersifat satu host dan satu purpose. Keduanya tidak boleh
dipakai untuk aplikasi lain, interactive session, scheduled task, network client,
atau general-purpose launcher.

### Authority manifest host

Installer menghasilkan satu manifest canonical machine-local yang admin-owned,
non-writable bagi akun checkout dan akun broker. Manifest bukan secret dan tidak
memuat credential, tetapi merupakan authority host yang exact untuk:

- machine identity dan OS compatibility;
- account SID, service SID, static memberships, serta exact group/privilege
  allowlist policy;
- service name, `SERVICE_WIN32_OWN_PROCESS`, start policy, binary canonical path,
  binary digest, dan service-object DACL;
- required-privilege list, service-SID mode, dan user-right assignments;
- fixed local pipe name/namespace, pipe direction/mode/limits, dan exact pipe
  security descriptor;
- exact Windows Firewall outbound block rule untuk binary/service broker;
- fixed ADR-017 policy digest, candidate/coordinator binary digests, serta
  canonical allowed root relations; dan
- manifest version, generation, creation time, rotation state, dan digest.

Manifest digest menjadi bagian private configuration bytes yang menghasilkan
existing `configBinding`; ia tidak menambah field schema ADR-017/ADR-018. Caller
tidak boleh memasukkan path manifest, memilih generation, atau mengganti field.
Provider dan client masing-masing membaca manifest dari lokasi pinned,
memvalidasi canonical bytes serta digest sebelum IPC, lalu memvalidasinya kembali
sesudah response.
Missing, duplicate, stale, revoked, writable, noncanonical, atau drifted manifest
menolak admission sebelum target atau token access.

### Account dan service policy

Local user wajib:

- berbeda dari current-process user, bukan `S-1-5-18`, bukan akun built-in, dan
  bukan anggota Administrators atau group berprivilege lain;
- hanya memperoleh `SeServiceLogonRight` sebagai logon grant untuk purpose ini;
- memperoleh deny network, interactive, remote-interactive, dan batch logon yang
  exact sesuai policy host, tanpa conflicting identity/group grant;
- tidak menjadikan user profile/home, share, SPN, delegation, network, atau
  interactive desktop sebagai dependency/authority; dan
- memenuhi seluruh token policy ADR-018 pada setiap service start dan request.

Service wajib non-interactive, own-process, single instance, tidak menerima
argument/environment dinamis dari checkout, dan tidak memiliki network listener.
Binary, directory, service configuration, recovery action, dan service-object
DACL hanya dapat diubah administrator. Checkout user tidak boleh memiliki
`SERVICE_CHANGE_CONFIG`, delete, write-DAC, write-owner, start, atau stop rights.
Karena Windows Firewall mengizinkan outbound traffic secara default, installer
wajib memasang exact per-program/service outbound block rule dan readiness wajib
menolak bila rule tidak ada atau drift.

Service memakai `SERVICE_SID_TYPE_UNRESTRICTED` agar service SID exact hadir pada
token dan dapat dipakai pada DACL IPC. Istilah Windows ini tidak memberi hak
filesystem tambahan dengan sendirinya. `SERVICE_SID_TYPE_RESTRICTED` ditolak
karena menambahkan restricting SIDs, sedangkan ADR-018 mewajibkan
`TokenRestrictedSids` kosong dan `IsTokenRestricted=FALSE`.

Host policy wajib memberi/mendukung `SeImpersonatePrivilege` secara terpisah;
`SERVICE_REQUIRED_PRIVILEGES_INFO` hanya menyaring privilege token dan tidak
memberikannya. Required-privilege list membatasi token pada
`SeImpersonatePrivilege`; `SeChangeNotifyPrivilege` tetap hadir karena SCM
mempertahankannya untuk compatibility. `SeImpersonatePrivilege` diperlukan hanya
untuk autentikasi dan target-open singkat melalui
`ImpersonateNamedPipeClient`; tidak boleh dipakai untuk operasi lain.
`SeBackupPrivilege`, `SeRestorePrivilege`,
`SeTakeOwnershipPrivilege`, `SeDebugPrivilege`, `SeTcbPrivilege`, dan
`SeAssignPrimaryTokenPrivilege` wajib absent atau disabled sesuai exact token
policy. Setiap privilege/group tambahan atau perubahan attributes menolak start
readiness dan setiap request.

### Broker/provider dan IPC

Broker adalah exact provider ADR-018, bukan token dispenser. Ia tidak pernah
mengekspor token, credential, raw handle, process handle, SID pointer, descriptor
bytes, atau native error.

IPC memiliki exact fixed transport envelope version 1 yang terpisah dari schema
evidence ADR-018. Request envelope memiliki tepat `transportVersion=1`, `method`,
`manifestDigest`, `brokerStartIdentity`, `requestDigest`, dan `payload`. `method`
hanya literal `attest`, `load`, atau `discard`: `attest.payload` hanya exact
canonical request ADR-018; `load.payload` dan `discard.payload` hanya exact
request digest yang sama. Ini bukan operation selector extensible dan tidak ada
method/payload lain. Pada `attest`, broker memvalidasi canonical payload lalu
menghitung ulang lowercase SHA-256 `requestDigest` menurut ADR-018 dan mewajibkan
exact-equal dengan field envelope. Cache hanya dikunci oleh digest hasil hitung
ulang itu. Pada `load`/`discard`, field `requestDigest` dan payload wajib saling
exact-equal dan menunjuk key hasil `attest`; caller tidak dapat memilih key yang
tidak terikat payload. Response envelope mengulang exact transport/method/
manifest/start/request binding, memiliki fixed success/refusal status, dan
memuat hanya exact evidence ADR-018 untuk successful `attest`/`load`; `discard`
tidak memuat evidence. Envelope tidak menambah field pada request/evidence
ADR-018.

Envelope memakai strict ASCII sorted-key canonical JSON ADR-018 tanpa duplicate,
nonfinite, extra field, atau trailing data. Request frame maksimum 20 KiB dan
response frame maksimum 36 KiB. `attest.payload`/successful evidence payload
adalah nested canonical object yang setelah encoding ulang wajib byte-identical
dengan canonical request/evidence ADR-018; digest selalu dihitung dari bytes
canonical nested object tersebut. Refusal memakai vocabulary tetap tanpa native
detail dan tidak pernah membawa partial payload.

Broker sendiri memiliki cache ADR-018. `attest` melakukan observasi, mengembalikan
evidence dan menyimpan exact evidence; `load` pertama atomik mem-pop evidence
yang sama; load kedua mengembalikan sentinel fixed; `discard` idempotent membuang
entry. Tiap method memakai satu connection baru dengan tepat satu bounded request
frame dan satu bounded response frame; frame tambahan pada arah mana pun ditolak.
Cache global maksimal satu entry menolak request lain hingga load/discard.

Broker membuat local named pipe dengan:

- explicit security descriptor; default descriptor dilarang karena Microsoft
  mendokumentasikan default read access bagi Everyone dan anonymous;
- exact service SID dan expected checkout account SID, tanpa broad
  Everyone/Authenticated Users grant;
- rights individual minimum, bukan broad generic rights;
- `FILE_FLAG_FIRST_PIPE_INSTANCE`, `PIPE_REJECT_REMOTE_CLIENTS`, bounded
  message-mode frames, satu request per connection, finite timeout, satu active
  instance, dan non-inheritable handles; serta
- refusal atas second frame, trailing bytes, partial/oversize frame, duplicate
  connection, remote client, atau in-flight collision.

Static manifest tidak menyimpan logon SID, `AuthenticationId`, `TokenId`,
`ModifiedId`, PID, atau creation time karena nilai itu berubah pada setiap
service/client logon dan start. Pada setiap start broker menurunkan
`brokerStartIdentity` sebagai lowercase SHA-256 atas prefix ASCII
`checkout-ordinary-broker-start-v1`, satu NUL, lalu canonical JSON dengan exact
keys `manifestDigest`, `servicePid`, `processCreationTime`, `accountSid`,
`serviceSid`, `authenticationId`, dan `tokenId`. PID adalah uint32; creation time
adalah decimal uint64 string; SID/LUID/digest serta canonical JSON mengikuti
strict encoding ADR-018. Client menghitung digest yang sama dari live pinned
service process/token evidence. Pipe DACL dibuat ulang dari static account/
service SIDs; per-request logon SID dan token statistics dibuktikan live serta
diikat pada transport state.

Sebelum client mengirim request, client wajib membuktikan server secara live:
pipe server PID harus sama dengan PID service berstatus `SERVICE_RUNNING` dari
`QueryServiceStatusEx`; process handle, creation identity, session, executable
canonical path/digest, process token user/service SID, dan manifest generation
wajib exact. Client membuka dan menahan server process handle sebelum mempercayai
PID hingga response selesai, lalu mengulang seluruh pemeriksaan. PID saja hanya
corroboration dan tidak pernah cukup. Kegagalan membuka atau memvalidasi bukti
ini menolak request.

Setelah membaca exact request frame, broker wajib memanggil
`ImpersonateNamedPipeClient` dan memeriksa return value sebelum tindakan apa pun.
Broker memverifikasi thread token terhadap exact current-token identity request,
expected client SID/logon SID, client PID/process identity, executable digest,
session, serta manifest. Client wajib meminta exact SQOS
`SecurityImpersonation`; broker menolak Anonymous, Identification, atau
Delegation. Broker membuka dan menahan client process handle sebelum mempercayai
PID, mempertahankannya sampai response selesai, dan mengulang creation/token
identity setelah operasi. Broker lalu, masih di bawah impersonation client,
membuka tiga target exact dengan rights minimum untuk descriptor/identity checks
dan menahan handle non-inheritable tersebut.

Broker wajib memanggil `RevertToSelf` dalam cleanup yang tidak dapat dilewati.
Kegagalan impersonation menolak seluruh request sebelum client action. Kegagalan
revert mewajibkan best-effort close tanpa tindakan provider berikutnya lalu
process-fatal termination; thread tidak boleh kembali menerima request. Sesudah
revert sukses, `OpenThreadToken` wajib membuktikan `ERROR_NO_TOKEN` sebelum broker
membuka primary token prosesnya sendiri. Broker kemudian membuktikan exact
service/account provenance dan hanya `DuplicateTokenEx` token independen itu
menjadi `SecurityImpersonation` untuk `AccessCheck` ADR-018. Target handle yang
dibuka di bawah client impersonation tidak berarti akun broker memperoleh akses
target; keputusan ordinary tetap berasal dari `AccessCheck` memakai token broker.

Descriptor capture, final path/file identity, double capture, token stability,
ordinary policy, exact denial tuple, cache, dan cleanup mengikuti ADR-018.
Confused-deputy defense juga mewajibkan ketiga target berada pada exact manifest
root relation dan cocok dengan pinned candidate/coordinator identity.
Tidak ada arbitrary path, account, access mask, generic mapping, policy, provider
mode, atau operation selector.

### Replay, crash, dan privacy

ADR-018 evidence tetap terikat hanya pada exact schema ADR-018: full request
digest, fresh 256-bit challenge, boundary, phase, session, lease/config binding,
dan policy digest. Transport envelope terpisah mengikat exact evidence bytes pada
manifest generation dan broker start identity. Broker menolak challenge/digest
duplicate atau in-flight. Cache `attest/load/discard` berada hanya di broker
process, maksimal satu entry, one-shot, bounded, dan tidak persisten. Restart
menghapus cache dan mengganti start identity sehingga request/load lama invalid;
tidak ada recovery dari evidence lama.

Setiap auth, IPC, impersonation, revert, token, handle, descriptor, native check,
cache, write, timeout, disconnect, service stop/restart, atau post-check drift
menolak seluruh evidence dengan fixed redacted `acl_attestation`. Partial evidence
tidak dikirim/disimpan. Semua handles ditutup exact sekali, error utama serta
`KeyboardInterrupt`/`SystemExit` dipreservasi, dan cleanup error tidak mengganti
error utama.

Log hanya boleh memuat event code, boundary/phase generik, dan digest redacted
yang telah disetujui. Username, SID, path, request/evidence bytes, token profile,
native error, pipe name dinamis, password, dan service credential dilarang.

### Lifecycle administratif

Install, update, rotation, revocation, dan uninstall memakai journal admin yang
memvalidasi precondition lalu mencatat setiap langkah. Urutan install:

1. validasi host disposable serta tidak ada lifecycle checkout aktif;
2. buat local user khusus dan random credential di boundary admin;
3. tetapkan deny rights serta exact `SeServiceLogonRight`;
4. pasang binary/directory admin-owned dan verifikasi digest;
5. buat own-process service, required privileges, service SID, service DACL,
   recovery policy fail-closed, serta start policy disabled/demand-only;
6. pasang exact outbound firewall block untuk binary/service;
7. tulis authority manifest admin-owned; dan
8. start hanya dalam test window yang secara eksplisit diotorisasi.

Rotation menghentikan admission baru, menunggu request aktif selesai, menghentikan
service, merotasi credential melalui boundary SCM/admin, menaikkan manifest
generation, lalu mengulang seluruh native readiness. Revocation pertama-tama
mematikan gate, menghentikan admission dan service, menandai generation revoked,
lalu menonaktifkan account.

Pada setiap partial-install/update/rotation failure, rollback pertama-tama wajib
secara fail-closed mematikan gate/admission, terminate lalu disable service,
disable account, dan mencabut `SeServiceLogonRight` serta entitlement
`SeImpersonatePrivilege`. Baru setelah neutralization itu rollback berjalan
terbalik dan idempotent. Service/account tidak langsung dihapus selama forensic
window. Setelah tidak ada lifecycle/recovery aktif: hapus service, cabut sisa
rights, hapus manifest/binary sesuai retention, lalu hapus account. Partial
rollback tetap fail closed dan dicatat tanpa credential.

### Acceptance boundary

Pure/synthetic tests boleh membuktikan canonical manifest/protocol, fixed
operation/policy/paths, framing bounds, replay/in-flight/restart state machine,
mutual-auth ordering, impersonation/revert failure ordering, token-profile
relation, cleanup precedence, redaction, dan rollback failure injection. Bukti
tersebut tidak membuktikan Windows security behavior.

Acceptance native wajib dilakukan pada Windows disposable sebelum integrasi:

- exact account/group/rights/privilege/token inventory dan SCM/service DACL;
- service binary/directory/manifest ACL, SID mode, start/recovery configuration;
- kernel pipe DACL, local-only/remote rejection, first-instance/squatting tests;
- real mutual authentication, impersonation/revert, service/client PID restart
  dan reuse races, process/token stability, serta handle leak/inheritance census;
- real target open under client impersonation, broker primary-to-derived token
  provenance, descriptor double capture, dan native `AccessCheck` denial pada
  ketiga target;
- duplicate/replay/concurrency, disconnect, crash, reboot, password expiry,
  rotation, revocation, update, uninstall, dan partial rollback;
- outbound firewall enforcement dan socket/listener/connection census; serta
- candidate dan browser matrix P17c dalam isolated test window.

Hanya setelah seluruh bukti native direview boleh provider diintegrasikan di
balik gate yang tetap default OFF. Aktivasi/canary/deploy memerlukan keputusan
operasional terpisah. Tidak ada fallback ke current token, built-in/virtual
account, scheduled task, missing service, stale generation, atau bypass browser.

## Alternatives Considered

### Duplicate current-process token

Ditolak karena security context tetap sama dan bukan principal kedua.

### Username/password, environment, account selector, atau raw-handle handoff

Ditolak karena caller memperoleh atau memilih authority, credential/handle dapat
bocor, dan lifecycle cleanup tidak lagi dimiliki satu provider.

### LocalSystem, LocalService, atau NetworkService

Ditolak. LocalSystem secara eksplisit dilarang ADR-018 dan memiliki privilege
luas. LocalService/NetworkService adalah identity built-in yang digunakan lintas
service; NetworkService juga memakai credential komputer pada akses jaringan.

### Virtual account, sMSA, atau gMSA

Ditolak untuk policy v1. Virtual account memakai credential komputer untuk
network dan managed accounts menambah dependency domain. Semuanya juga mengubah
token/group/provisioning semantics yang belum dibuktikan. Opsi masa depan wajib
melalui ADR/policy version baru dan bukti native terpisah.

### Scheduled task, helper interaktif, atau general launcher

Ditolak karena menambah batch/interactive logon, scheduling, duplicate-instance,
credential, dan arbitrary-process surface yang tidak diperlukan provider.

### Service hanya mengekspor token ke attestor

Ditolak. Raw token/handle tidak boleh meninggalkan provider. Broker sendiri harus
melakukan descriptor lifecycle dan `AccessCheck` exact ADR-018.

## Consequences

- Provisioning authority kini dipilih secara normatif, tetapi belum ada service,
  manifest, provider, IPC, atau runtime evidence.
- Broker menambah local privileged-IPC attack surface dan kewajiban patching,
  rotation, monitoring, serta rollback.
- `SeImpersonatePrivilege` diterima hanya sebagai kebutuhan sempit target-open;
  implementasi dan runtime harus membuktikan tidak ada operation lain.
- P15/P16/P17c/P18, checklist, dan progress tetap terbuka. Seluruh gate/payment
  tetap default OFF; tidak ada config/env/deploy/activation.
- Pembuatan akun/service atau perubahan policy Windows memerlukan authority
  operasional eksplisit setelah implementation dan synthetic review selesai.

## References

- [Service User Accounts](https://learn.microsoft.com/en-us/windows/win32/services/service-user-accounts)
- [Service Record List](https://learn.microsoft.com/en-us/windows/win32/services/service-record-list)
- [CreateServiceW](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/nf-winsvc-createservicew)
- [Service Security and Access Rights](https://learn.microsoft.com/en-us/windows/win32/services/service-security-and-access-rights)
- [SERVICE_SID_INFO](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/ns-winsvc-service_sid_info)
- [SERVICE_REQUIRED_PRIVILEGES_INFO](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/ns-winsvc-service_required_privileges_infow)
- [Named Pipe Security and Access Rights](https://learn.microsoft.com/en-us/windows/win32/ipc/named-pipe-security-and-access-rights)
- [CreateNamedPipeW](https://learn.microsoft.com/en-us/windows/win32/api/namedpipeapi/nf-namedpipeapi-createnamedpipew)
- [ImpersonateNamedPipeClient](https://learn.microsoft.com/en-us/windows/win32/api/namedpipeapi/nf-namedpipeapi-impersonatenamedpipeclient)
- [Impersonating a Named Pipe Client](https://learn.microsoft.com/en-us/windows/win32/ipc/impersonating-a-named-pipe-client)
- [GetNamedPipeClientProcessId](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-getnamedpipeclientprocessid)
- [GetNamedPipeServerProcessId](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-getnamedpipeserverprocessid)
- [QueryServiceStatusEx](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/nf-winsvc-queryservicestatusex)
- [OpenProcessToken](https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/nf-processthreadsapi-openprocesstoken)
- [DuplicateTokenEx](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-duplicatetokenex)
- [AccessCheck](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-accesscheck)
- [Account Rights Constants](https://learn.microsoft.com/en-us/windows/win32/secauthz/account-rights-constants)
- [Interactive Services](https://learn.microsoft.com/en-us/windows/win32/services/interactive-services)
- [Configure Windows Firewall rules](https://learn.microsoft.com/en-us/windows/security/operating-system-security/network-security/windows-firewall/configure)

## Implementation Checkpoint — `6469561`, `459e988`

Pure authority-manifest codec pada `6469561` memvalidasi authority canonical
ADR ini, termasuk cap pipe exact 20/36 KiB, owner pipe akun broker, penolakan
LocalSystem, serta direct SAM membership yang terpisah dari runtime token-group
policy terikat. Pure broker-transport codec pada `459e988` memvalidasi hanya
request canonical dan response refusal, discard, atau load-sentinel.

Bukti accepted: masing-masing **14/14** dan **11/11 tests**; gabungan **25/25**,
`py_compile`, `diff-check`, serta final adversarial review **PASS** tanpa P1/P2.
Successful `attest`/`load` evidence tetap fail closed sampai canonical ADR-018
evidence validator/provider diterima. Checkpoint ini tidak membuktikan instalasi
atau read freshness manifest, efektivitas ACL, account/service/firewall, native
IPC/cache/provider/runtime, candidate/browser, deploy, atau activation.
P15/P16/P17c/P18, checklist/progress, gates/payment tetap terbuka/tidak berubah/
default OFF.

## Implementation Checkpoint — `b66ebba`, `bb369c6`, `9785eb5`

Commit `b66ebba` menerima canonical per-start broker identity codec (**8/8
tests**) atas supplied data; live service-process/token binding belum dibuktikan.
Commit `bb369c6` menerima pemisahan authority ADR-020, dan `9785eb5` menerima
bytes-only structural evidence codec (**12/12 tests**) yang tetap explicit
`structuralOnly`, tanpa native policy/provenance/admission claim.

Suite codec lokal gabungan **56/56**, `py_compile`, `diff-check`, dan review
**PASS**. Successful `attest`/`load` tetap disabled menunggu native authority,
provider, cache, dan runtime. Tidak ada instalasi, service, environment, network,
deployment, atau activation. P15/P16/P17c/P18, progress/checklist, gates, dan
payment tetap terbuka/tidak berubah/default OFF.
