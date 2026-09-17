# ADR-025: Authority runtime Python terisolasi dan TLS checkout

- Status: Accepted-for-contract (design only)
- Tanggal: 2026-09-08

## Konteks

ADR-021 mensyaratkan verifier artifact Ed25519 yang berjalan pada Python dan
dependency kriptografi yang diperoleh secara offline, dipin, dan ditutup sampai
dependency native. ADR-022 menetapkan authority material TLS sintetis per-run,
ADR-023 menetapkan atomic protected root dan handle-relative lease sebagai
prasyarat normatif, dan ADR-024 menetapkan distinct journal/trusted-time service
serta rollback witness. Keempatnya sengaja belum memilih satu kontrak executable
yang menghubungkan bootstrap verifier, akuisisi archive, instalasi terisolasi,
loader Windows, custody private key, consumer proxy, dan browser.

Repository sekarang memiliki structural wheel locks dan host-closure observer
untuk `cryptography`, `cffi`, dan `pycparser`. Bukti tersebut memvalidasi data
yang diberikan caller dan fake adapter; bukti itu tidak mengautentikasi archive,
tidak menginstal atau mengimpor package, tidak membuktikan resolusi DLL/API-set
Windows, dan tidak memberi runtime authority. Package Python atau OpenSSL yang
kebetulan tersedia melalui `PATH`, registry, user site, system site, current
working directory, atau environment tetap hostile.

ADR ini menerima default kontrak yang sempit untuk menutup dua dependency
tersebut. Status ini hanya menerima desain dan interface. Ia tidak mengizinkan
download, install, extraction, certificate/key generation, native Windows call,
process atau browser launch, candidate build, perubahan config/environment,
deployment, payment, atau aktivasi gate.

## Keputusan

### 1. Baseline runtime offline yang exact

Satu-satunya baseline yang dapat diajukan untuk verifier dan issuer TLS adalah:

| Komponen | Artifact exact | SHA-256 archive |
|---|---|---|
| CPython | `python-3.14.0-embed-amd64.zip`; CPython `3.14.0`, ABI `cp314`, arsitektur `amd64` | wajib berasal dari acquisition artifact yang ditandatangani; belum diterima |
| `cryptography` | `cryptography-50.0.1-cp311-abi3-win_amd64.whl` | `aed8db4f6d71c51efb89530e12d9464e7bf2923d46c3205dc794a2a93f8c0648` |
| `cffi` | `cffi-2.1.1-cp314-cp314-win_amd64.whl` | `3222ba5d678f80a030e6afbcc33dc1ae5cb45facabb61cee2c7016b8432fde48` |
| `pycparser` | `pycparser-3.0-py3-none-any.whl` | `b727414169a36b7d524c1c3e31839a521725078d7b2ff038656844266160a992` |

CPython archive belum menjadi accepted input sampai independent acquisition
authority menerbitkan exact byte size, lowercase SHA-256, distribution inventory,
issuer/key/trust generation, provenance, dan revocation binding melalui artifact
ADR-021. Tidak ada substitute patch release, architecture, wheel tag, sdist,
source build, repacked archive, atau equal-version filename.

Keempat archive harus diperoleh di luar candidate melalui offline acquisition
yang telah diautentikasi. Setiap archive, inventory ZIP/RECORD, metadata, license,
native extension, resource, dan extracted byte dipin ke signed provenance.
Extraction tidak boleh menjalankan `pip`, installer, `setup.py`, `.pth`, startup
hook, script, atau binary dari archive. Network, index resolution, dependency
solver, fallback download, dan package cache ambient wajib disabled.

Runtime root adalah satu fresh, owner-protected, non-reparse directory di luar
candidate source dan di bawah accepted preparation root. `python314._pth` wajib
menyebut hanya exact relative package roots yang dipin, tidak memuat `import
site`, dan tidak menerima path absolut atau parent traversal. `PYTHONHOME`,
`PYTHONPATH`, user site, system site, registry lookup, current-directory import,
`PATH` lookup, executable association, dan ambient DLL search tidak menjadi
input. Environment yang memengaruhi Python/loader harus ditolak, bukan
disanitasi lalu dilanjutkan.

### 2. Bootstrap verifier yang tidak sirkular

Archive target tidak boleh menjalankan verifier yang mengautentikasi archive itu
sendiri. Default adalah external verified-capability handoff dari satu
`BootstrapRuntimeAuthority` yang telah diprovision dan diautentikasi sebelum run,
di luar candidate dan di luar empat target archives. Tidak ada local fallback
yang mengeksekusi target `python.exe`, module, wheel, extracted byte, atau helper
untuk membuat capability bootstrap.

Interface konseptualnya exact:

```text
handoff(
  bootstrapManifestBytes,
  bootstrapAcquisitionEvidenceBytes,
  bootstrapObservationBytes,
  twoOperatorCeremonyCapability
) -> BootstrapVerifierRuntimeCapability

verify_artifact(
  BootstrapVerifierRuntimeCapability,
  artifactBytes,
  envelopeBytes,
  trustBundleBytes,
  revocationSnapshotBytes,
  trustedTimeCapability
) -> VerifiedArtifactCapability

promote_target(
  BootstrapVerifierRuntimeCapability,
  VerifiedArtifactCapability[],
  IsolatedRuntimeCapability,
  targetNativeObservationBytes,
  challenge
) -> RuntimeVerifierCapability

discard(BootstrapVerifierRuntimeCapability) -> terminal
```

`trustedTimeCapability` bukan timestamp atau callback generik. Ia hanya boleh
berasal dari successful one-shot `time.challenge` -> `time.validate` ADR-024
melalui exact journal/time service `OncamCheckoutJournalTimeV1`, dedicated
local-account label `OncamCkJournalTime` dan manifest-bound account/service SID,
endpoint `\\.\pipe\oncam-checkout-journal-time-v1`, admin-owned manifest schema
`oncam.checkout.journal-time-service-manifest.v1` beserta generation, transport
domain `oncam.checkout.journal-time-transport.v1\0`, rollback witness,
trusted-time signer, dan revocation bindings yang dipin. Service/account/
endpoint/manifest/codec/cache/witness tersebut wajib berbeda dari preparation
service ADR-023 dan ordinary broker ADR-019. Local clock, process uptime, file
time, caller callback, atau in-process replacement tidak dapat menghasilkan
capability ini.

Bootstrap manifest mengikat exact external provider/broker process identity,
source/binary digest, already-installed runtime-root identity, interpreter,
verifier source, public trust keys, every loaded module, loader policy, system
DLL/API-set resolution, acquisition issuer/generation, dan expiry/revocation.
Bootstrap root, process, files, keys, dan object identities wajib pairwise
distinct dari target isolated runtime yang diverifikasi. Capability hanya dapat
memverifikasi; ia tidak dapat extract/install, menandatangani, membuat key/cert,
memilih artifact, atau menerbitkan admission.

Authority bootstrap berasal dari two-operator/two-channel fingerprint ceremony
ADR-021 atas exact bootstrap manifest/verifier bundle, kemudian native observer
yang independen membuktikan process dan loader closure yang sama sebelum handoff.
Hasil ceremony structural yang supplied sendiri tidak cukup. Exact provisioning,
operator authentication, protected transport, native observer, trusted time,
revocation, dan provider process identity masih acceptance gates; sampai semuanya
diterima, `handoff` harus disabled.

Capability mengikat exact method/object/type/implementation, manifest/evidence
bytes and digests, provider PID/start identity, loader closure, generation,
challenge, dan one-shot state. Semua binding direvalidasi sebelum dan sesudah
setiap verify. Mutation, replacement, equal-looking capability, request/evidence
drift, replay, provider restart, loader drift, expiry/revocation, partial result,
atau unavailable authority mengonsumsi attempt dan menolak. Cleanup mencoba
seluruh resource tanpa menutupi primary `KeyboardInterrupt`/`SystemExit`.

Hanya `VerifiedArtifactCapability` dari bootstrap boundary ini yang dapat
mengizinkan preparation authorization dan target runtime acquisition. Setelah
target runtime berhasil dibangun serta native closure-nya dibandingkan dengan
artifact yang sudah diverifikasi, bootstrap authority memanggil
`promote_target` sekali untuk exact artifact-capability set, target runtime,
native observation, dan fresh challenge. Output `RuntimeVerifierCapability`
mengikat bootstrap evidence digest, seluruh target artifact digest/generation,
target process/start and loaded-module identity, loader closure, serta challenge.
Baru output itu dapat menggantikan bootstrap verifier untuk operasi berikutnya.
Promotion dan bootstrap discard bersifat one-way; target tidak pernah menjadi
authority bagi acquisition atau promotion dirinya sendiri.

### 3. Acquisition dan loader authority

`IsolatedRuntimeAuthority` adalah capability sealed dan single-use. Composition
memberikan hanya canonical acquisition request yang sudah diverifikasi; caller
tidak dapat memilih root, archive, version, hash, loader policy, DLL, search
directory, atau fallback. Interface konseptualnya exact:

```text
prepare(acquisitionRequestBytes, protectedRootCapability)
    -> IsolatedRuntimeCapability
observe(IsolatedRuntimeCapability, challenge)
    -> SignedRuntimeAcquisitionEvidence
discard(IsolatedRuntimeCapability) -> terminal
```

Capability mengikat secara privat exact request digest, protected-root/lease
object identity, runtime-root handle and identity, archive identities/digests,
extracted inventory digest, loader-policy digest, process-image set, generation,
dan lifecycle challenge. Capability tidak serializable, tidak menyediakan raw
handle/path getter, dan tidak dapat direseal atau digunakan ulang.

Sebelum dan sesudah setiap archive read, extraction, hash, PE inspection, dan
process boundary, implementation wajib membuka melalui exact retained parent
handle, menolak reparse/path escape/alternate data stream/device atau Windows
alias, lalu membandingkan canonical final path, volume/file identity, size, dan
SHA-256 dari descriptor yang sama. Seluruh root/ancestor/inventory dipindai ulang
sesudah read terakhir dan tepat sebelum evidence/result. Missing, extra,
casefold collision, duplicate object identity, hardlink, transient replacement,
atau cleanup uncertainty menolak.

Loader closure harus mencakup exact `python.exe`, `python314.dll`, `python3.dll`,
`VCRUNTIME140.dll`, `ucrtbase.dll`, semua `.pyd`, bundled OpenSSL di `_rust.pyd`,
dan seluruh normal-import PE edge. Setiap image dipin dengan canonical path,
object identity, machine `AMD64`, size, SHA-256, normal imports, dan empty delay
imports. Delay imports, runtime `LoadLibrary` target yang tidak terdapat dalam
authenticated closure, basename non-ASCII/invalid, duplicate import setelah
casefold, atau import yang hanya dapat ditemukan lewat `PATH`/CWD menolak.

System DLL dan API-set tidak boleh dipercaya berdasarkan nama. Native producer
kelak wajib merekam exact KnownDLL/system image identity+digest dan exact API-set
contract-to-host resolution untuk host/generation yang sama, kemudian
memverifikasi loaded module path/identity sesudah process start. Tidak ada
fallback ke search order default. Dynamic loading dan actual loaded-module
closure adalah runtime gate; structural PE inventory tidak membuktikannya.

### 4. Evidence runtime yang boleh diserialisasi

Public runtime evidence adalah canonical artifact role
`tool-runtime-closure`, dibungkus dan diverifikasi menurut ADR-021. Karena codec
v1 yang sudah diterima hanya mencatat supplied structural inventory, evidence
native ini memakai payload **v2**; v1 tidak dapat dipromosikan menjadi v2 atau
runtime authority. Payload v2
memiliki exact top-level fields berikut dan tidak boleh memiliki field lain:

```text
version, role, issuerId, artifactId, generation, issuedAt, expiresAt, replayId,
hostIdentityDigest, acquisitionRequestDigest, cpythonArchiveDigest,
cryptographyArchiveDigest, cffiArchiveDigest, pycparserArchiveDigest,
archiveInventoryDigest, extractedInventoryDigest, loaderPolicyDigest,
systemDllClosureDigest, apiSetClosureDigest, loadedModuleObservationDigest
```

`version` exact `2`, `role` exact `tool-runtime-closure`, generation positive
int63, waktu canonical UTC dan lifetime maksimum tujuh hari. Seluruh `*Digest`
adalah lowercase SHA-256 atas canonical, domain-separated bytes; identifier
adalah bounded lowercase ASCII opaque ID. `loadedModuleObservationDigest` hanya
boleh diterbitkan oleh native observer setelah child start dan tidak boleh
diganti digest inventory yang direncanakan. Artifact ditandatangani detached
Ed25519 oleh tool/runtime-closure authority, direvokasi dan diperiksa one-shot
seperti ADR-021. Artifact tidak memuat absolute path, SID, username, machine
name, environment, raw handle, key, passphrase, atau archive bytes.

Signed evidence tidak mengubah fakta menjadi benar dengan sendirinya. Issuer,
acquisition mechanism, host identity producer, native loader observer, protected
journal, clock, trust roots, revocation, dan verifier composition semuanya harus
diterima dan dipin secara terpisah sebelum evidence menjadi admission input.

### 5. Profile TLS dan origin ADR-022

Algoritme tidak boleh disamakan lintas boundary:

- artifact/evidence signature tetap detached **Ed25519** sesuai ADR-021;
- per-run TLS leaf key tetap exact **RSA 3072 bit**, dan self-signature tetap
  **SHA-256**, sesuai ADR-022; dan
- ECDSA atau Ed25519 certificate/key bukan fallback dan memerlukan ADR baru.

Baseline v1 tetap sama dengan ADR-022 dan runtime-configuration-policy yang sudah
diterima: certificate mempunyai exact ordered SAN `dNSName:psikotes.oncam.id`,
lalu `dNSName:oncam.id`, tanpa IP address, `localhost`, wildcard, URI, email,
atau nama lain. Browser dan proxy memakai public origin exact
`https://psikotes.oncam.id` pada port `443`, dengan exact host-resolver policy
yang memetakan kedua nama tersebut ke `127.0.0.1` dan menolak semua nama lain.
Common Name tidak menjadi hostname authority.

Loopback SAN `localhost`/`127.0.0.1` bukan alias yang diterima. Migrasi ke SAN
tersebut kelak memerlukan version baru yang mengganti certificate-policy,
runtime-configuration-policy, browser launch policy, public-origin binding,
negative tests, artifact/evidence digest, dan seluruh consumer secara atomik.
Tidak ada silent dual-stack, alternate origin, atau fallback antarversi.

Semua policy ADR-022 lain tetap berlaku: satu fresh self-signed end-entity leaf
per run; `BasicConstraints` critical `CA=false`; EKU exact `serverAuth`; Key
Usage critical exact `digitalSignature` dan `keyEncipherment`; minimal equal
subject/issuer tanpa PII; positive random X.509 serial; `notBefore` exact lima
menit sebelum issuance; `notAfter` tidak melebihi issuance plus 23 jam 55 menit;
canonical interval maksimum 24 jam; maximum run 15 menit; cleanup allowance dua
menit; dan minimum remaining lifetime 30 menit saat handoff.

### 6. Custody, consumer, dan transport secret

`TlsMaterialAuthority` menerima exact pinned `IsolatedRuntimeCapability`, final
preparation binding, protected-root capability, run/lease identities, generation,
trusted time, policy digest, dan fresh challenge. Ia menghasilkan satu key/cert
langsung di accepted protected root setelah preparation ACL berhasil. Tidak ada
caller-supplied output path, key bytes, entropy, subject, SAN, algorithm, issuer,
atau consumer.

Private key disimpan sebagai encrypted PKCS#8 PEM. Authority menahan exact
non-inheritable file handle dan path/object identity; composition hanya menerima
opaque single-use `TlsMaterialCapability`. Capability, key path/hash/identity/
size, password, handle value, dan private acknowledgment tidak pernah
diserialisasi.

Key file tidak dibuka ulang berdasarkan path oleh proxy. Supervisor membuat
duplicate read-only key/certificate handles untuk exact child serta dua anonymous
pipes: one-shot passphrase pipe dan acknowledgment pipe. Hanya exact key/cert
handles, passphrase-read endpoint, dan acknowledgment-write endpoint masuk
`PROC_THREAD_ATTRIBUTE_HANDLE_LIST`; handle inheritance umum dilarang. Tidak ada
secret atau handle pada argv, environment, config, JSON, stdout/stderr, log, atau
command history.

Consumer membaca key/certificate dari inherited handles atau bounded pipe-backed
copies yang berasal dari handles tersebut, memuatnya sekali, menutup child
handles, lalu mengirim bounded acknowledgment. Acknowledgment mengikat request
digest, fresh challenge, child PID/start identity, certificate fingerprint,
SPKI digest, dan privately held source object identities. Parent memvalidasi
acknowledgment dan retained identities sebelum dan sesudah load. Timeout, extra
byte, replay, wrong child, drift, path reopen, callback substitution, partial
load, atau missing close menolak sebelum listen.

Karena Python `SSLContext.load_cert_chain` menerima filenames, implementation
tidak boleh mengklaim kontrak ini terpenuhi dengan memanggil API itu pada path
key yang dapat dibuka ulang. Exact handle-to-consumer adapter dan bukti bahwa
library tidak melakukan reopen tetap native acceptance gate.

### 7. Browser trust yang sempit

Browser hanya satu disposable Chromium process dengan exact run-local
user-data-dir dan tepat satu
`--ignore-certificate-errors-spki-list=<base64-SHA256-leaf-SPKI>`. SPKI berasal
dari final signed public TLS evidence dan terikat ke runtime policy, final config,
composition admission, serta process observation. `ignoreHTTPSErrors` wajib
exact `false`.

Global `--ignore-certificate-errors`, tambahan SPKI, trust-store mutation,
profile reuse, launch/context arg tambahan, proxy fallback, atau ambient browser
trust menolak. SPKI flag adalah transport exception saja: ia tidak membuktikan
hostname, validity, EKU, Key Usage, CA/chain, private-key custody, atau policy.
Independent validator wajib membuktikan policy tersebut sebelum launch dan
negative runtime tests wajib membuktikan wrong SPKI/host/time/policy ditolak.

### 8. Public TLS evidence dan pemisahan role

`TlsMaterialCapability` tetap privat. Satu-satunya output serializable adalah
canonical public artifact role `tls-material` dengan exact v1 fields:

```text
version, role, issuerId, artifactId, generation, issuedAt, expiresAt, replayId,
requestDigest, preparationBindingDigest, runtimeEvidenceDigest,
runtimeConfigurationPolicyDigest, runIdentityDigest, leaseIdentityDigest,
certificateSha256, spkiSha256, serialHex, publicKeyAlgorithm,
signatureAlgorithm, subjectDigest, sanPolicy, notBefore, notAfter,
certificatePolicyDigest, evidenceDigest
```

`version` exact `1`, `role` exact `tls-material`, algorithms exact
`rsa-3072`/`sha256WithRSAEncryption`, `sanPolicy` exact
`psikotes-two-dns-v1`, digests lowercase SHA-256, serial positive
canonical lowercase hex, timestamps canonical UTC, dan identifiers bounded
lowercase ASCII. `evidenceDigest` memakai domain
`oncam.checkout.tls-material-evidence.v1\0` plus canonical bytes seluruh field
sebelumnya. Private-key metadata, raw certificate/key, path, SID, account, host
detail, entropy, password, handle, atau acknowledgment bytes dilarang.

Role `tls-material` belum terdapat pada allowlist envelope structural I1 saat
ini. ADR ini menerima role tersebut hanya untuk future envelope v2 yang
menambahkan exact role dan domain `oncam.checkout.tls-material.v1\0`; envelope
I1 v1 wajib tetap menolaknya. Sampai codec v2, verifier, trust/revocation, dan
consumer composition diterima bersama, public TLS evidence tidak dapat
ditandatangani atau dipakai oleh jalur kandidat.

Certificate issuer/materializer dan evidence signer/custodian adalah authority
yang berbeda. Materializer tidak memegang evidence signing key. Ia menyerahkan
hanya canonical public evidence digest ke independently provisioned TLS evidence
signer; signer mengembalikan detached Ed25519 envelope ADR-021 untuk exact
artifact. Runtime acquisition issuer, TLS materializer, TLS evidence signer,
composition issuer, dan private-key cleanup custodian tidak boleh merupakan
object/key identity yang sama. Exact operator assignment, key provisioning, dan
custody evidence tetap acceptance gate; pemisahan nama role saja bukan bukti.

Pre-launch public TLS artifact tidak memuat atau mengikat consumer acknowledgment:
artifact harus tersedia sebelum final composition admission, sedangkan
acknowledgment baru dapat lahir setelah admission mengizinkan child start.

### 9. Post-load execution observation

Proxy dimulai sebagai **running bootstrap child** dalam mode exact
`tls-bootstrap-v1` setelah final composition dan fresh ADR-017 execution
admission. Mode ini hanya boleh memvalidasi inherited handles/frame, memuat
certificate/key sekali, mengirim acknowledgment, lalu menunggu release frame.
Sebelum release tervalidasi, process dilarang membuat socket atau memperoleh
socket handle; `socket`, `bind`, `listen`, accept loop, HTTP handler, dan browser
launch semuanya belum boleh dijangkau. “Non-listening” saja tidak cukup karena
socket yang sudah dibuat atau bound tetap memperluas authority sebelum journal
gate.

Setelah exact child memuat key, private acknowledgment divalidasi dan dicatat
sebagai satu protected-journal CAS transition `proxy-key-load-v1` sebelum socket
create/bind/listen atau browser launch. CAS hanya boleh dilakukan melalui exact
`journal.compare-and-append` ADR-024 pada service/account/endpoint/manifest/
codec/rollback-witness generation yang sama dengan `trustedTimeCapability` run
ini. Generic local file, existing test storage primitive, coordinator journal,
ordinary broker cache, atau alternate witness bukan substitute. Record exact
memiliki field berikut dan tidak boleh memiliki field lain:

```text
version, kind, generation, observedAt, previousJournalDigest,
runIdentityDigest, leaseIdentityDigest, sessionBindingDigest,
compositionAdmissionDigest, aclExecutionAdmissionDigest,
tlsMaterialEvidenceDigest, proxyProcessIdentityDigest, challengeDigest,
certificateSha256, spkiSha256, consumerAcknowledgmentDigest, recordDigest
```

`version` exact `1`, `kind` exact `proxy-key-load`, generation mengikuti exact
protected compare-and-swap predecessor, dan `observedAt` berasal dari exact
current ADR-024 `trustedTimeCapability`; seluruh digest lowercase SHA-256.
Request/response juga mengikat journal/time service-start identity, authority
manifest digest/generation, endpoint/codec domain, rollback-witness identity/
generation/digest, trusted-time evidence digest, request challenge, run/session,
lease/config, dan current revocation set sesuai ADR-024. `recordDigest` memakai
domain `oncam.checkout.proxy-key-load.v1\0` plus canonical bytes seluruh field
sebelumnya. Record tidak memuat private-key path/hash/identity/size, password,
handle, raw acknowledgment, SID, atau certificate bytes. Exact private
`TlsMaterialCapability` dan source-handle identities tetap dibandingkan in-memory
sebelum dan sesudah journal commit.

Setelah commit, supervisor merevalidasi current composition, lease, journal,
runtime/TLS capabilities, process identity, retained handles, dan fresh ACL
execution context. Baru kemudian satu one-shot release frame yang mengikat exact
acknowledgment digest, committed record digest/generation, journal/time
service-start identity, rollback-witness generation, proxy PID/start identity,
dan fresh release challenge dikirim melalui retained release pipe. Child
memvalidasi frame exact, menutup bootstrap inputs, lalu—dan hanya lalu—membuat
socket, bind, dan listen. Browser launch menyusul setelah listener identity
terbukti. Missing, failed, ambiguous, replayed, stale, atau unpersisted CAS;
release sebelum CAS; socket create/bind/listen sebelum release; atau ADR-024
service/witness drift membuat child diterminalkan. Observation ini bukan
pengganti signed pre-launch TLS artifact, final composition admission, ADR-017,
atau ADR-024 authority.

### 10. Lifecycle exact

Urutan lifecycle tidak dapat dipertukarkan:

1. autentikasi external bootstrap verifier/runtime melalui exact ceremony,
   provider observation, dan capability handoff; target archives belum dieksekusi;
2. gunakan bootstrap capability untuk memverifikasi trust/revocation/high-water,
   target runtime acquisition, archive hashes, dan static preparation artifacts;
3. consume preparation authorization one-shot ADR-021;
4. melalui required ADR-023 authority, atomic create protected run root, tahan
   handle/identity, lalu create/acquire handle-relative ADR-016 lease sebagai
   first child/write;
5. buat incomplete marker/skeleton dan lulus separately accepted preparation-ACL;
6. prepare target isolated runtime dari exact verified offline archives, buktikan
   extracted/loader/system-DLL/API-set/loaded-module closure, lalu lakukan one-way
   replacement bootstrap capability;
7. buat `TlsMaterialCapability`, generate material per-run, validasi X.509 dua
   kali dari held bytes/handles, lalu terbitkan signed pre-launch public evidence;
8. finalisasi source/vendor/runtime config/browser config/manifest dan exact
   `configBinding` tanpa private metadata;
9. verifikasi final composition admission yang mengikat runtime evidence, TLS
   public evidence, exact private capability identity, run/lease, dan config;
10. lulus fresh final ADR-017 anchor/execution admission;
11. start exact running `tls-bootstrap-v1` child tanpa socket melalui inherited-
    handle/pipe contract, validasi private acknowledgment, commit
    `proxy-key-load-v1` melalui exact ADR-024 authority, revalidasi semua
    authority, lalu kirim one-shot release yang baru mengizinkan socket create/
    bind/listen dan launch browser dengan one-SPKI exception;
12. sepanjang run revalidasi retained capabilities/identities dan budget; dan
13. pada success, refusal, interruption, atau crash, terminalkan child lalu
    discard/close/zeroize seluruh capability dan held resource dalam reverse
    ownership order.

Evidence atau capability dari run/generation/challenge lain, langkah yang
dilewati, retry materialization, attach kedua, stale evidence, atau cleanup
uncertainty wajib menolak tanpa fallback atau auto-repair.

### 11. Failure, cleanup, dan privacy

Seluruh ordinary failure menjadi fixed redacted refusal per boundary. Error
tidak memuat path, archive name yang berasal dari input, certificate detail,
identity, handle, dependency error, atau secret. `KeyboardInterrupt` dan
`SystemExit` exact object tetap primary sementara setiap held handle, pipe,
capability, child process, dan temporary byte buffer tetap dicoba ditutup.
Cleanup failure tidak menutupi primary exception dan membuat lifecycle terminal.

Passphrase/private-key buffer ditimpa best effort segera setelah consumer ack;
certificate/key files dihapus atau diinvalidate melalui retained identities
setelah child terminal. Python dan immutable/runtime-library copies dapat
membatasi zeroization, dan power-loss/crash dapat meninggalkan media state.
Karena itu zeroization adalah best effort, bukan security proof. Protected ACL,
sharing mode, deletion semantics, crash recovery, storage remanence, dan reboot
rollback memerlukan native evidence terpisah.

## Acceptance Criteria

Contract dianggap implemented hanya jika seluruh hal berikut diterima bersama:

- separately authenticated external bootstrap verifier/runtime capability,
  two-operator/two-channel ceremony, protected handoff, exact independent loader
  closure, and proof that target archives are not executed before verification;
- signed acquisition/provenance untuk exact empat archives, termasuk CPython
  archive SHA-256 yang belum tersedia, beserta issuer/trust/revocation/replay;
- bounded extraction membuktikan exact ZIP/RECORD/resources/licenses/native
  inventory dan byte-to-record binding tanpa menjalankan archive content;
- native Windows observation membuktikan root/ancestor/descriptor identity,
  no-reparse, extracted inventory stability, loader search policy, exact system
  DLL/API-set resolution, loaded module set, dan tidak ada dynamic-load fallback;
- real isolated CPython starts dengan exact flags/path set dan membuktikan tidak
  ada environment, registry, site, CWD, `PATH`, network, atau ambient import;
- atomic protected root dan handle-relative lease capability ADR-023,
  preparation ACL, serta exact distinct journal/time service, protected journal,
  rollback witness, trusted-time, dan revocation prerequisites ADR-024 lulus
  sebelum runtime/TLS materialization;
- real key generation dan independent X.509 validation membuktikan exact
  RSA/SHA-256, ordered two-domain SAN, extensions, time bounds, key match, dan
  fresh-run uniqueness;
- native handle inheritance membuktikan exact handles, noninheritability,
  one-shot passphrase, child identity, acknowledgment binding, no path reopen,
  retained identity stability, timeout, dan reverse cleanup;
- proxy tidak membuat socket, bind, atau listen sebelum exact acknowledgment,
  protected `proxy-key-load-v1` compare-and-swap melalui distinct ADR-024
  service/witness, full context revalidation, dan one-shot listener release
  berhasil;
- disposable Chromium membuktikan exact single-SPKI arg, run-local profile,
  `ignoreHTTPSErrors=false`, effective process args, successful expected
  `https://psikotes.oncam.id` transport, exact resolver rules, dan negative
  wrong-SPKI/host/time/policy cases;
- public runtime/TLS evidence mempunyai accepted canonical codecs, detached
  Ed25519 verification, distinct role authorities/custodians, current trusted
  time, revocation/high-water, replay consumption, dan final composition binding;
  dan
- independent adversarial review serta native runtime suite tidak menemukan
  blocker P1/P2.

Pure/synthetic tests hanya boleh membuktikan schema, deterministic digest,
state-machine, mutation refusal, cleanup ordering, dan behavior fake adapter.
Host-closure observation saat ini tidak memenuhi acceptance di atas.

## Konsekuensi

- Default dependency dan TLS profile tidak lagi boleh dipilih caller atau host
  ambient; failure menahan candidate sebelum launch.
- ADR ini menutup pilihan versi/archive dan interface desain, tetapi menambah
  pekerjaan bootstrap verifier handoff, acquisition, native loader observation,
  protected lifecycle, evidence signing, handle consumer, post-load journal, dan
  browser runtime yang wajib dituntaskan.
- Baseline SAN/origin ADR-022 dan runtime-configuration-policy v1 tetap berlaku;
  ADR ini tidak membuat alternate localhost/IP origin.
- Tidak ada existing candidate yang menjadi reusable atau accepted karena ADR
  ini. P15, P16, P17c, P18, checklist/progress, dan seluruh payment/checkout gates
  tetap terbuka/default OFF.

## Alternatif yang ditolak

### Ambient Python, package, OpenSSL, `PATH`, atau default DLL search

Ditolak karena version string tidak membuktikan archive provenance, extracted
bytes, loader graph, atau loaded process. Sanitasi environment sesudah discovery
tidak memperbaiki authority awal.

### Online `pip install` atau dependency resolution saat run

Ditolak karena network/cache/index menjadi mutable authority dan resolution
tidak lagi identik dengan reviewed archive set.

### Ed25519/ECDSA sebagai TLS leaf tanpa keputusan baru

Ditolak pada baseline ini. Ed25519 sudah dipakai untuk artifact signatures,
sedangkan accepted TLS profile tetap RSA-3072/SHA-256. Menyamakan kedua boundary
akan menciptakan compatibility dan policy claim yang belum diuji.

### Private key melalui argv, environment, serialized config, atau path reopen

Ditolak karena memperluas observability/replay dan memutus identity continuity.
Inherited exact handle/anonymous-pipe handshake tetap wajib dibuktikan native.

### `ignoreHTTPSErrors` atau global certificate bypass

Ditolak. Exact one-SPKI Chromium exception adalah batas maksimum dan hanya
transport exception, bukan certificate-policy authority.

## Dependencies dan keputusan lanjutan

ADR ini bergantung secara normatif pada ADR-016, ADR-017, ADR-021, ADR-022,
ADR-023, dan ADR-024. `PinnedPreparationParentCapability`,
`HeldPreparationRootCapability`, dan `HeldPreparationLeaseCapability` ADR-023
wajib tersedia dan tetap exact sebelum runtime root, extracted file, TLS key,
atau certificate dibuat. Implementasi juga bergantung pada accepted external
bootstrap runtime authority, offline acquisition/signing, exact CPython archive
digest, preparation ACL, protected journal/trusted clock/revocation, TLS evidence
signer, native system-DLL/API-set producer, inherited-handle consumer,
post-load journal/release boundary, dan final composition wiring.

Setiap trusted-time use dan `proxy-key-load-v1` CAS wajib memakai exact ADR-024
service/account/endpoint/manifest/codec/witness capability yang dipin bersama
run. ADR-024 yang belum terimplementasi membuat kedua boundary unavailable; ADR
ini tidak mengizinkan local clock, generic journal/provider, atau synthetic
fixture sebagai fallback.

Material user/operations decisions yang belum tersedia adalah operator/key
assignment dan custody, offline archive transfer/storage, accepted CPython
archive digest/source, machine/OS image policy and update cadence, evidence
signer service boundary, bootstrap provider/protected handoff, post-load journal
producer, crash/remanence response, serta runtime test budget.
Tidak ada dependency tersebut boleh dipenuhi secara implisit oleh developer
machine atau fake adapter.

## References

- [ADR-016: Lease lifecycle global candidate checkout](0016-checkout-candidate-lifecycle-lease.md)
- [ADR-017: Attestation ACL Windows checkout](0017-checkout-windows-acl-attestation.md)
- [ADR-021: Authority persiapan release kandidat checkout](0021-checkout-release-preparation-authority.md)
- [ADR-022: Synthetic TLS material authority](0022-checkout-synthetic-tls-material-authority.md)
- [ADR-023: Authority root persiapan dan lease handle-relative checkout](0023-checkout-preparation-root-and-lease-authority.md)
- [ADR-024: Authority broker journal dan trusted time checkout](0024-checkout-broker-journal-and-trusted-time-authority.md)
- [Python embeddable package](https://docs.python.org/3/using/windows.html#the-embeddable-package)
- [Python isolated mode](https://docs.python.org/3/using/cmdline.html#cmdoption-I)
- [Python path configuration files](https://docs.python.org/3/using/windows.html#finding-modules)
- [Python Packaging: Recording installed projects (`RECORD`)](https://packaging.python.org/en/latest/specifications/recording-installed-packages/)
- [Microsoft DLL search order](https://learn.microsoft.com/en-us/windows/win32/dlls/dynamic-link-library-search-order)
- [Microsoft `SetDefaultDllDirectories`](https://learn.microsoft.com/en-us/windows/win32/api/libloaderapi/nf-libloaderapi-setdefaultdlldirectories)
- [Microsoft process handle inheritance](https://learn.microsoft.com/en-us/windows/win32/procthread/inheritance)
- [Microsoft `PROC_THREAD_ATTRIBUTE_HANDLE_LIST`](https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/nf-processthreadsapi-updateprocthreadattribute)
- [`cryptography` Ed25519 API](https://cryptography.io/en/latest/hazmat/primitives/asymmetric/ed25519/)
- [`cryptography` X.509 tutorial](https://cryptography.io/en/latest/x509/tutorial/)
- [`cryptography` key serialization](https://cryptography.io/en/latest/hazmat/primitives/asymmetric/serialization/)
- [Python `SSLContext.load_cert_chain`](https://docs.python.org/3/library/ssl.html#ssl.SSLContext.load_cert_chain)
- [Playwright `Browser.newContext`](https://playwright.dev/docs/api/class-browser#browser-new-context)
- [Chromium SPKI ignore-errors verifier](https://chromium.googlesource.com/chromium/src/+/HEAD/services/network/ignore_errors_cert_verifier.cc)
- [RFC 5280: X.509 certificate profile](https://www.rfc-editor.org/rfc/rfc5280)
- [RFC 6125: Service identity in TLS](https://www.rfc-editor.org/rfc/rfc6125)
