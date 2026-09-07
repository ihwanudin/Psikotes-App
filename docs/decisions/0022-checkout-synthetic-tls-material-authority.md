# ADR-022: Authority material TLS sintetis checkout

## Status

Accepted-for-contract. ADR ini menerima pilihan desain authority, issuer,
policy sertifikat, transport exception browser, custody key, budget, dan
consumer handoff yang dijelaskan di bawah. Status ini tidak menerima
implementasi atau bukti runtime dan tidak mengotorisasi acquisition dependency,
pembuatan sertifikat, candidate, browser, service, native Windows, deployment,
atau activation.

## Date

2026-09-07

## Context

Candidate builder saat ini menerima byte sertifikat dan private key dari caller
beserta SHA-256 masing-masing. Pemeriksaan lokal hanya membuktikan bahwa
sertifikat dapat diparse oleh `SSLContext.load_cert_chain` dan public key cocok
dengan private key. Pemeriksaan tersebut belum membuktikan:

- siapa yang menerbitkan atau menghasilkan material;
- provenance tool, dependency, dan artifact issuer;
- SAN, masa berlaku, EKU, Key Usage, Basic Constraints, atau signature policy;
- kekuatan key dan bentuk PEM tunggal;
- cara browser mempercayai sertifikat tanpa bypass luas;
- bahwa private key tidak pernah melewati input caller, argument, environment,
  output, cache umum, atau log; dan
- one-shot handoff, replay refusal, serta cleanup pada failure dan crash.

Material historis dibuat secara lokal menggunakan instalasi `cryptography`,
tetapi helper, wheel, RECORD, native extension, dan dependency transitive itu
belum menjadi authority yang dipin. Versi yang kebetulan terpasang bukan bukti
acquisition atau supply-chain closure.

Candidate browser juga belum memiliki contract trust sempit untuk context
utama. Sertifikat self-signed tidak boleh dianggap dipercaya hanya karena
pernah ada context lain atau laporan lama yang memakai
`ignoreHTTPSErrors=true`. Bypass sertifikat umum akan menghilangkan bukti yang
seharusnya diberikan oleh test TLS.

ADR-016 menetapkan lifecycle lease candidate dan ADR-017 menetapkan boundary
attestation ACL Windows. Material private key tidak boleh dibuat ke filesystem
run-local sebelum prasyarat owner-only ACL dan identity continuity tersebut
diterima secara native.

## Decision

### Authority internal milik composition

Material TLS sintetis wajib berasal dari satu authority internal yang dibuat,
dipin, dan dipanggil oleh composition checkout. Authority bukan public input
builder, bukan operation selector umum, dan tidak menerima byte sertifikat atau
private key dari caller.

Contract sempit yang diusulkan adalah satu sealed, single-use
`TlsMaterialCapability` milik lifecycle. Capability menerima tepat satu
`materialize(request)`, menahan seluruh state privat dan handle yang dihasilkan,
serta menyerahkan hanya certificate evidence publik kepada composition yang
sama. Capability tidak menyediakan evidence store yang dapat diambil ulang atau
repeatable retrieval method. Ia menyediakan `discard()` eksplisit sebelum
handoff dan terminal cleanup yang wajib dipanggil lifecycle pada setiap jalur
keluar. Capability tidak memiliki identifier publik, tidak caller-addressable,
dan tidak dapat diperoleh kembali dari config, evidence, admission serialized,
atau lookup registry.

Nama method, object identity, implementation, callable metadata, constants,
dependency authority, dan exact composition binding wajib dipin. Tidak ada
fallback, discovery dari environment, permissive retry, reseal, cloning, atau
operation baru. Capability hanya berlaku untuk satu run, session, lease, dan
candidate generation; reference atau hasilnya tidak dapat dipakai ulang.

Successful `materialize` tetap disabled sampai exact issuer artifact
acquisition, preparation ACL, material consumer, browser transport exception,
serta contract dan implementasi final composition admission ADR-021 dan final
ADR-017 tersedia serta diterima. Prasyarat ini berarti mekanisme final-admission
siap dipakai, bukan bahwa final admission untuk run tersebut sudah sukses
sebelum materialization. Per-run final composition admission dan fresh final
ADR-017 tetap terjadi sesudah materialization dan finalisasi config sesuai
urutan di bawah. Native acceptance yang relevan juga tetap wajib sebelum
runtime.

### Request dan lifecycle binding

Request canonical wajib berukuran terbatas, ASCII sorted-key JSON, tanpa
duplicate, nonfinite number, extra field, trailing byte, atau bool-as-int.
Final `configBinding` belum tersedia sebelum certificate dan browser trust
configuration selesai. Karena itu request tidak boleh menerima atau menebak
final `configBinding`. Composition lebih dahulu menurunkan exact
`preparationBinding` dari:

- exact one-shot **preparation authorization** ADR-021, bukan final composition
  admission;
- exact run canonical path dan filesystem identity;
- held lifecycle lease ADR-016;
- exact preparation-ACL request/evidence untuk run dan output parent;
- exact runtime-configuration-policy artifact digest dan generation;
- exact preparation-policy digest;
- digest dan generation dari independently authenticated monotonic revocation
  snapshots yang berlaku bagi seluruh artifact/authority preparation; serta
- fresh preparation challenge dan authority generation.

Preparation ACL adalah capability/extension terpisah yang masih **Proposed**. Ia
bukan capability yang sudah diterima ADR-017, bukan final ADR-017 admission, dan
tidak diterima sebagai implementasi oleh ADR ini. Implementasi saat ini tidak
mempunyai authority tersebut dan tetap unusable. Kontrak berikutnya wajib mensyaratkan
native handle/identity continuity, owner-only protected DACL, no-reparse, serta
ordinary-principal denial yang setara dengan policy ADR-017, tetapi terikat ke
preparation policy dan state karena final config belum ada.

ADR ini belum menetapkan nama key, nesting, tipe, byte limit, dan exact closed
key set untuk request v1. Daftar berikut adalah semantic binding yang seluruhnya
wajib dibawa, bukan schema yang dapat langsung diimplementasikan:

- `version` dan fresh random `challenge` per invocation;
- `run`, `session`, `preparationBinding`, dan `leaseBinding`;
- canonical run-directory path dan filesystem identity;
- exact ADR-021 preparation-authorization digest/generation;
- exact runtime-configuration-policy artifact digest/generation;
- exact preparation-policy, preparation-ACL evidence, dan independently
  authenticated revocation-snapshot digests/generations;
- certificate-policy artifact bytes dan digest;
- issuer artifact manifest bytes, digest, dan generation;
- exact ordered DNS names;
- issuance timestamp, exact `notBefore=issuance-5m`, exact
  `notAfter<=issuance+23h55m`, dan canonical validity interval tidak lebih dari
  24 jam;
- exact maximum run 15 menit, cleanup allowance 2 menit, dan required minimum
  remaining lifetime 30 menit pada handoff; dan
- exact RSA-3072/SHA-256 algorithm profile.

Caller tidak boleh memilih path output, issuer executable, dependency root,
trust bypass, subject content, serial, key bytes, atau entropy source.
Composition menurunkan semua nilai tersebut dari authority yang sudah dipin.

Request digest memakai domain separation dan exact canonical request bytes.
Replay request, challenge reuse, binding drift, generation drift, atau request
yang tidak cocok dengan held lifecycle lease wajib ditolak sebelum material
dibuat.

Sebelum implementasi, ADR/codec Accepted berikutnya wajib menetapkan satu exact
request-v1 closed schema: nama dan urutan key, tipe/nesting, bounds, canonical
ASCII serialization, domain separator, dan digest. Extra/missing/duplicate key,
alternate spelling/representation, trailing byte, nonfinite number, dan
bool-as-int wajib ditolak. Sampai codec tersebut diterima, `materialize` tetap
non-implementable dan disabled; implementer tidak boleh menafsirkan daftar
semantic di atas sebagai schema extensible.

Setelah materialization, composition memvalidasi certificate evidence, menyalin
source/vendor yang telah diautentikasi, memilih dan memfinalisasi browser trust
serta runtime configuration, dan menyegel final source/config/manifest. Final
`configBinding` baru dihitung dari state akhir tersebut, termasuk public
certificate/SPKI binding. Composition kemudian wajib menerbitkan dan
memverifikasi exact **final composition admission ADR-021** yang mengikat seluruh
artifact, revocation snapshots, run identity, held lease, preparation evidence,
public TLS certificate-evidence digest, final manifest, dan final
`configBinding`. Secara terpisah, private composition state mengikat exact
`TlsMaterialCapability` object, type, implementation, dan lifecycle identity
kepada admission tersebut tanpa menyerialisasikan salah satu binding privat.

Hanya setelah final composition admission lulus, coordinator boleh meminta
fresh final ADR-017 admission untuk exact `configBinding` dan exact ordered
targets `coordinator`, `run`, `source`. Keduanya wajib lulus sebelum claim,
anchor I/O, atau process spawn. Preparation authorization maupun preparation-ACL
evidence tidak dapat direplay atau dinaikkan menjadi salah satu admission final.

### Urutan composition yang dapat diimplementasikan

Current candidate builder tidak dapat langsung dipakai untuk urutan ini karena
ia mewajibkan cert/key sebagai caller-supplied runtime files dan memasukkan
path/hash key ke config. Sebelum implementation/native acceptance, preparation
wajib dipisah menjadi:

1. validasi artifact source/vendor/tools/asset-review, runtime-configuration
   policy, preparation policy, dan revocation snapshots tanpa mengonsumsi
   preparation authorization atau menulis destination;
2. revalidasi seluruh artifact/revocation authority, lalu validasi dan consume
   exact one-shot preparation authorization ADR-021;
3. di bawah exact pinned parent, buat fresh run root secara atomik dengan
   protected owner-only DACL, lalu pin dan tahan exact root handle/path/
   filesystem identity. Root wajib masih kosong—belum ada child, marker,
   skeleton, source, vendor, config, certificate, atau key;
4. melalui root handle yang dipin, buat dan acquire proposed handle-relative
   ADR-016 lease sebagai **first child dan first write** di dalam root; exact
   lease handle/identity ditahan sampai lifecycle terminal;
5. setelah lease berhasil dipegang, buat incomplete marker dan skeleton minimum
   saja; source/vendor/config/TLS tetap belum boleh ditulis;
6. jalankan proposed preparation-ACL capability/extension terhadap exact parent,
   root, lease, marker, skeleton, dan held identities, lalu turunkan
   `preparationBinding`;
7. buat satu sealed `TlsMaterialCapability` dan materialize cert/key langsung
   ke protected run root;
8. validasi public certificate evidence, copy/finalisasi authenticated source
   dan vendor, pilih trust mechanism yang sudah diterima, finalisasi runtime dan
   public browser config yang hanya mengikat evidence digest dan chosen public
   SPKI/transport configuration—tanpa certificate path/identity/size atau key
   path/hash/identity—lalu segel final manifest dan hitung final
   `configBinding`;
9. terbitkan dan verifikasi exact ADR-021 final composition admission untuk
   seluruh public final state, lalu bind exact capability object/type/
   implementation/lifecycle identity hanya di private composition state;
10. dapatkan fresh final ADR-017 admission untuk exact final config dan targets;
   dan
11. baru izinkan anchor/claim serta consumer/proxy launch yang sudah diterima.

Atomic protected run-root creation pada langkah 3 adalah satu-satunya narrow
pre-lease filesystem exception: operasi itu hanya menciptakan dan menahan root
kosong yang diperlukan sebagai lokasi lease. Ia bukan assembly dan tidak boleh
membuat marker, skeleton, source, config, TLS, atau child lain. ADR-016 saat ini
belum mendefinisikan handle-relative lease setelah empty-root creation; karena
itu extension tersebut masih Proposed dan current lease implementation tidak
dapat dipakai untuk flow ini sampai diterima terpisah.

Full public certificate evidence adalah separate internal serialized object;
ia bukan bagian dari final config. Final serialized config hanya boleh memuat
digest exact canonical public certificate-evidence object dan chosen public
SPKI/transport-exception configuration. Certificate path, filesystem identity,
byte size, full evidence fields, capability identifier/reference/lookup key,
object metadata, atau private binding tidak boleh masuk final config. Exact
capability object/type/implementation/lifecycle identity hanya dipin di private
composition admission/state, tidak caller-addressable atau retrievable. Key
metadata dan handles tetap eksklusif di capability.
Root/skeleton preparation, capability, final composition admission, atau final
ADR-017 admission yang gagal masuk terminal cleanup tanpa fallback ke current
builder path.

### Prasyarat owner-only ACL dan penulisan key

Authority hanya boleh berjalan setelah composition membuktikan exact run root,
skeleton, marker, dan output parent melalui separately accepted native
preparation-ACL capability/extension yang dijelaskan di atas. Prasyarat mencakup
atomic protected-DACL creation, owner-only protected DACL, non-reparse
directory, pinned parent/root/first-child path dan filesystem identity, exact
ADR-021 preparation authorization, independently authenticated current
revocation snapshots, runtime-configuration/preparation-policy bindings, serta
held lifecycle lease yang sama.

Private key dibuat langsung ke file run-local melalui exclusive-create handle
yang non-inheritable. Authority wajib:

- menolak symlink, junction, reparse point, hard-link alias, replacement, dan
  pre-existing target;
- memvalidasi final path dan filesystem identity sebelum dan sesudah write;
- menahan exact handle sampai flush, validation, evidence publication, dan
  ownership transfer selesai;
- memakai permission/DACL paling sempit yang diizinkan policy ADR-017;
- tidak membuat copy sementara di luar protected run directory; dan
- membersihkan partial file melalui held identity/handle, bukan lookup path
  yang dapat berubah.

Proxy saat ini menerima path melalui argv lalu membuka ulang certificate/key.
Mekanisme itu tidak dapat diterima karena key path melewati argv dan path reopen
membuka replacement/identity race. Contract consumer yang dipilih adalah:

1. certificate dan key memakai fixed canonical run-local names yang diturunkan
   child hanya dari pinned run layout/current working directory; path, hash,
   identity, atau bytes key tidak masuk argv, environment, serialized config,
   output, atau log;
2. supervisor membuka dan mempertahankan exact certificate/key handles read-only
   dengan sharing yang hanya mengizinkan read, tidak write atau delete/rename,
   dari sebelum child launch sampai proxy benar-benar terminal;
3. private key adalah encrypted PKCS#8 PEM. Authority membuat random one-shot
   passphrase dan menyimpannya hanya dalam private `TlsMaterialCapability`;
4. supervisor membuat anonymous pipe khusus password dan pipe acknowledgment.
   Hanya exact endpoint yang diperlukan child yang dibuat inheritable sesaat
   dan dimasukkan ke `STARTUPINFOEX` handle list dengan `close_fds=true`.
   `STARTF_USESTDHANDLES` memasang password-read endpoint sebagai dedicated child
   `hStdInput` dan acknowledgment-write endpoint sebagai dedicated child
   `hStdOutput`; nilai handle tidak masuk argv/environment. Endpoint lain tidak
   diwariskan, tidak ada process launch paralel selama inheritance window,
   password dikirim sebagai one-shot bounded frame, dan kedua sisi menutup
   endpoint sesuai ownership;
5. child yang exact PID dan process-start identity-nya telah dipin membuka fixed
   names dengan no-reparse policy, memvalidasi final path, volume/file identity,
   size, hash, canonical PEM, serta certificate/key match sebelum
   `SSLContext.load_cert_chain`; password callback memakai exact one-shot bytes
   dari pipe dan tidak melakukan prompt atau fallback;
6. child memvalidasi ulang identity/hash/key-match setelah load, lalu mengirim
   bounded private acknowledgment yang terikat ke challenge, PID/start identity,
   certificate fingerprint, SPKI, dan exact privately-held file identities.
   Acknowledgment tidak dipublikasikan atau dicatat; dan
7. supervisor memvalidasi ulang kedua retained handles sebelum dan sesudah
   acknowledgment. Drift, timeout, pipe replay/extra byte, child mismatch,
   exception, interruption, atau cleanup failure menolak sebelum TLS listen dan
   tidak pernah jatuh kembali ke argv/path lama.

`SSLContext.load_cert_chain` menerima nama file, bukan Win32 file handle, sehingga
retained-parent-handle + exact child-open acknowledgment dipilih daripada raw
handle transfer. Current proxy path-reopen/argv interface tetap unusable sampai
contract ini diimplementasikan dan lulus native acceptance.

### Policy X.509 minimum

Material hanya untuk host sintetis checkout dan wajib memenuhi exact policy:

- tepat satu self-signed, one-run end-entity certificate dan fresh private key
  yang tidak pernah dipakai pada run lain, tanpa chain tambahan;
- exact RSA 3072 bit dengan signature SHA-256;
- `BasicConstraints` critical dan exact `CA=false`;
- SAN berisi tepat dua `dNSName`, `psikotes.oncam.id` dan `oncam.id`, dalam
  canonical order, tanpa wildcard, IP address, URI, atau nama tambahan;
- hanya SAN tersebut yang menjadi hostname authority; Common Name tidak menjadi
  authority dan tidak boleh menambah nama;
- EKU exact hanya `serverAuth`;
- Key Usage critical dan, untuk RSA profile, exact `digitalSignature` serta
  `keyEncipherment` tanpa bit tambahan;
- subject dan issuer exact minimal, sama karena self-signed, tanpa PII, account,
  username, machine name, atau environment name;
- positive random serial yang memenuhi batas X.509/RFC dan tidak berasal dari
  timestamp, PID, session, atau identifier bisnis;
- `notBefore` UTC exact issuance time dikurangi 5 menit;
- `notAfter` UTC tidak melebihi issuance time plus 23 jam 55 menit, sehingga
  interval canonical dari `notBefore=issuance-5m` sampai `notAfter` tidak lebih
  dari 24 jam;
- maximum run duration exact 15 menit dan cleanup allowance exact 2 menit; dan
- saat handoff, sisa masa berlaku wajib sekurang-kurangnya 30 menit.

Certificate file wajib memuat tepat satu canonical PEM certificate. Key file
wajib memuat tepat satu encrypted PKCS#8 PEM private key menggunakan exact
serialization policy dari pinned issuer version. Extra PEM block, comment,
prefix/suffix, chain, legacy key label, unencrypted key, unexpected encryption
profile, atau trailing noncanonical content ditolak. Public key certificate
wajib exact cocok dengan private key.

Issuer dan consumer wajib parse ulang policy dari bytes yang tersimpan; boolean
`policySatisfied` dari producer tidak menjadi authority.

### Transport exception browser yang dipilih

Playwright tidak menyediakan documented per-context server custom-CA trust;
`clientCertificates` adalah TLS client authentication dan
`ignoreHTTPSErrors` adalah bypass umum. Browser contract karena itu memakai satu
disposable Chromium process dengan run-local user-data-dir dan tepat satu launch
argument `--ignore-certificate-errors-spki-list=<base64 SHA-256 leaf SPKI>`.
`ignoreHTTPSErrors` wajib exact `false`; global `--ignore-certificate-errors`,
disable hostname verification, tambahan SPKI, trust-store mutation, profile
reuse, atau fallback ke ambient host trust ditolak.

Ini disebut **transport exception**, bukan browser trust atau certificate
validation. Implementasi Chromium yang didokumentasikan mengembalikan success
ketika SPKI mana pun dalam chain cocok, sehingga Chromium sendiri tidak boleh
diklaim memvalidasi wrong-host, expiry, EKU, Key Usage, chain, atau seluruh
certificate policy pada run ini. Sebelum browser launch, composition wajib
melakukan independent full validation terhadap exact stored leaf certificate,
exact two-host SAN, validity, algorithm/extensions, certificate/key match, dan
exact certificate/SPKI/evidence/config digests. Exact public SPKI dan certificate
digest wajib terikat ke final config, final ADR-021 admission, dan fresh final
ADR-017 admission.

SPKI adalah public metadata tetapi caller tidak boleh memilih atau menggantinya.
Perubahan certificate/SPKI setelah materialization, argumen flag kedua, missing
run-local user-data-dir, context `ignoreHTTPSErrors=true`, atau browser/tool
identity yang tidak tepat wajib menolak sebelum launch. Negative runtime proof
untuk wrong host dan expired certificate harus menguji validator independen;
successful Chromium navigation di bawah SPKI exception bukan bukti kedua sifat
tersebut. ADR ini tidak mengotorisasi perubahan trust store mesin.

### Acquisition dan supply-chain issuer

Issuer yang dipilih adalah repository-owned Python helper yang memakai exact
pinned `cryptography` offline wheel closure. Exact wheel filenames, versions,
hashes, dan generation bukan caller input; semuanya berasal dari accepted
artifact authority ADR-021. Acceptance acquisition sekurang-kurangnya wajib:

- exact offline wheel filenames dan SHA-256 yang direview;
- exact wheel RECORD, package file inventory, native extension, dependent DLL,
  license, dan platform/ABI binding;
- exact Python interpreter path, identity, dan hash yang sudah menjadi bagian
  candidate authority;
- isolated import root dan isolated interpreter mode tanpa user/system
  site-packages, current-directory import, registry, environment, atau network;
- exact issuer source dan tests di required/allowed candidate manifest; dan
- pre/post revalidation seluruh artifact dan dependency identity.

Tidak boleh ada `pip install`, package resolution, download, online revocation,
PATH lookup, implicit DLL search, atau fallback ke package host. Installed
package version string tidak cukup.

OpenSSL CLI bukan authority karena executable saja tidak mengikat config file,
provider modules, DLL search, entropy/runtime behavior, dan seluruh distribution
closure. Tidak ada fallback dari pinned helper ke OpenSSL, installed
`cryptography`, PowerShell PKI cmdlet, atau ambient host tool.

### Public certificate evidence dan private capability

Public certificate evidence adalah separate internal serialized object dan
satu-satunya material object yang boleh diserialisasi. Ia tidak masuk final
config; hanya exact canonical evidence digest dan chosen public SPKI/transport
configuration yang masuk config. Evidence hanya memuat data publik dan
immutable yang diperlukan untuk cross-binding.

ADR ini belum menetapkan nama key, nesting, tipe, byte limit, dan exact closed
key set evidence v1. Daftar berikut adalah semantic evidence yang seluruhnya
wajib tersedia, bukan schema yang dapat langsung diimplementasikan:

- request digest dan challenge;
- run/session/preparation/lease bindings;
- issuer policy, artifact manifest digest, dan generation;
- certificate SHA-256 fingerprint dan SPKI SHA-256 digest;
- parsed algorithm, key size, serial policy result, subject/issuer profile,
  SAN, EKU, Key Usage, Basic Constraints, dan validity timestamps;
- fixed-layout certificate role, filesystem identity, byte size, dan hash,
  tanpa menyerialisasikan certificate path;
- minimum-remaining-lifetime check terhadap runtime dan cleanup budget.

Sebelum implementasi, ADR/codec Accepted berikutnya wajib menetapkan satu exact
evidence-v1 closed schema beserta canonical ASCII bytes dan domain-separated
digest. Extra/missing/duplicate key, alternate representation, trailing byte,
nonfinite number, dan bool-as-int wajib ditolak. Sampai codec itu diterima,
successful evidence publication dan `materialize` tetap non-implementable dan
disabled.

Key path, key hash, key filesystem identity, key size, private key bytes,
password, entropy, dan raw handles tidak boleh muncul dalam serialized evidence.
Data tersebut hanya hidup di dalam exact sealed `TlsMaterialCapability`, bersama
retained certificate/key handles dan private consumer state. Capability bukan
serializable, tidak menyediakan conversion/dump/repr yang membocorkan state,
dan hanya dapat dipakai oleh exact lifecycle/supervisor object yang dipin.
SID, account, host detail, absolute dependency paths, dan native error detail
juga tidak pernah menjadi evidence serialized.

Evidence wajib dihasilkan dari independent parse terhadap exact stored bytes
dan exact held certificate identity. Private capability secara terpisah
memvalidasi exact held key identity dan certificate/key match. Final browser
config mengikat hanya digest exact canonical public evidence dan chosen public
SPKI/transport configuration; final `configBinding` baru dihitung setelah itu
dan diverifikasi oleh fresh final ADR-017 admission. Output error
menggunakan fixed redacted codes tanpa memasukkan request, path, certificate
content, key data, atau native message.

### One-shot handoff, failure, dan cleanup

State capability wajib monotonic dan fail closed:

1. `fresh` menerima satu fresh bound request;
2. `materializing` memegang lease, ACL authority, issuer authority, dan output
   handles;
3. `materialized` menyerahkan satu immutable public certificate evidence kepada
   exact composition sambil tetap menahan private key state dan handles;
4. `attached` setelah exact supervisor consumer, final config, dan fresh final
   ADR-017 admission tervalidasi; atau
5. `discarded`/`terminal` setelah explicit discard atau lifecycle cleanup.

Tidak ada repeated evidence lookup atau transisi dari `discarded`/`terminal`
kembali ke `materialized`. Materialize kedua, consumer berbeda, attach kedua,
discard lintas request, partial success, stale generation, atau callback
mutation wajib menolak. Menyerahkan public evidence tidak melepas custody
private capability.

Pada exception, `KeyboardInterrupt`, atau `SystemExit`, authority wajib mencoba
cleanup semua held resources tanpa mengganti primary exception. Cleanup failure
dicatat hanya sebagai fixed redacted state internal dan menahan lifecycle dari
claim atau launch. Evidence parsial tidak dipublikasikan.

Certificate dan key harus dihapus atau diinvalidasi pada explicit discard dan
terminal cleanup melalui exact retained handles/identities. Supervisor wajib
menahan handle yang diperlukan sampai proxy benar-benar terminal. Bukti crash
recovery, locked-handle semantics, filesystem durability, deletion pada Windows,
dan child-consumer acknowledgment tetap runtime gate; pure fake state machine
tidak membuktikannya.

### Batas privacy

Tidak ada secret pada argv, environment, stdout, stderr, JSON, audit log,
exception text, command history, atau browser config. Private key tidak pernah
dikembalikan oleh authority. Current proxy path-reopen interface tidak boleh
dipakai. Implementasi consumer yang dipilih hanya boleh memperoleh private
material melalui exact capability/pipe contract, sementara path tetap tidak
boleh masuk user-facing diagnostics.

Public certificate, certificate fingerprint, dan SPKI digest boleh dipakai oleh
internal verifier. Logging tetap menggunakan fixed status/refusal code dan
tidak mencatat subject, serial, path, key hash, atau lifecycle identifier.

### Acceptance pure dan native

Pure tests dapat membuktikan:

- exact canonical request/evidence codecs dan size/type/order bounds;
- immutable authority/result dan dependency/callable mutation refusal;
- single-use capability transitions, replay refusal, explicit discard, dan
  terminal cleanup;
- primary `BaseException` precedence dan fixed error vocabulary;
- artifact-manifest closure serta no-discovery policy;
- X.509 policy evaluation terhadap synthetic parsed fixtures; dan
- static absence of private-key transport, `ignoreHTTPSErrors`, dan broad
  trust-bypass switches; exact single-SPKI transport exception diuji sebagai
  satu-satunya pengecualian.

Pure tests tidak membuktikan actual pinned `cryptography` execution, CSPRNG,
native key protection, Windows ACL efficacy, file identity durability, browser
trust behavior, TLS
hostname/SPKI validation, crash cleanup, reparse/rename resistance, atau actual
candidate execution.

Native/runtime acceptance kelak wajib membuktikan sekurang-kurangnya:

- offline pinned dependency acquisition pada target host;
- real native ACL/access denial dan noninheritability sebelum key generation;
- actual key generation, X.509 parse, exact `notBefore=issuance-5m`, exact
  `notAfter<=issuance+23h55m`, canonical interval maksimal 24 jam, entropy
  behavior, dan file revalidation;
- disposable browser memakai exact run-local user-data-dir, tepat satu
  `--ignore-certificate-errors-spki-list` value yang cocok dengan final public
  evidence/config, dan `ignoreHTTPSErrors=false`, tanpa broad bypass;
- process evidence membuktikan exact effective browser args/profile dan wrong
  SPKI ditolak; successful TLS handshake di bawah SPKI exception hanya
  membuktikan transport dengan key yang dipin;
- independent validator memberi separate negative proof untuk wrong host,
  expired, not-yet-valid, extra SAN/EKU/Key Usage, wrong trust material, dan
  broad bypass; hasil tersebut tidak boleh diasumsikan berasal dari Chromium
  SPKI match;
- password-pipe inheritance hanya mencakup exact handles, frame one-shot,
  PID/start identity dan acknowledgment terikat, serta retained file handles
  menolak write/delete/rename sepanjang proxy lifetime;
- cleanup normal, failure, interruption, crash, stale process, dan replay; dan
- independent adversarial review tanpa blocker P1/P2.

Tidak ada pure evidence yang dapat menutup native/runtime acceptance.

## Alternatives Considered

### Mempertahankan cert/key sebagai caller-supplied bytes

Ditolak. Hash hanya membuktikan bytes tidak berubah setelah dipilih caller; hash
tidak membuktikan issuer, policy, private-key custody, atau one-shot provenance.

### Menghasilkan sertifikat otomatis di candidate builder

Ditolak. Builder akan sekaligus menjadi config parser, issuer, key custodian,
dan trust authority. Batas tersebut terlalu luas dan memungkinkan material
dibuat sebelum protected ACL/lifecycle authority tersedia.

### Memakai sertifikat tetap yang disimpan di repository

Ditolak. Private key repository bukan secret yang terjaga, dapat dipakai ulang,
dan tidak memiliki one-shot lifecycle. Rotasi hash tidak memperbaiki custody.

### Memakai system trust store atau CA host

Ditolak untuk default. Mutasi trust store berdampak di luar candidate dan sulit
dibatasi serta dipulihkan. Per-run CA juga membutuhkan keputusan provisioning,
installation, rollback, dan native trust-store authority terpisah.

### Memakai bypass certificate validation browser

`ignoreHTTPSErrors` dan global `--ignore-certificate-errors` ditolak karena
menghapus pembatas material. Exact one-SPKI Chromium exception diterima hanya
sebagai transport exception di disposable process; ia tidak menjadi bukti
hostname, chain, validity, EKU, Key Usage, atau certificate policy.

### Memakai OpenSSL dari PATH

Ditolak. PATH lookup dan executable version tidak mengikat config, providers,
DLL, dependency, atau artifact acquisition.

### Mengirim encrypted-key password melalui argv atau environment

Ditolak. Kedua channel mudah diwariskan atau diobservasi dan memperluas secret
surface. Contract memilih bounded one-shot anonymous pipe dengan exact inherited
handle list; stdin umum, stdout, JSON, config, dan log tetap dilarang.

## Consequences

- Builder tidak lagi menjadi intended authority untuk memilih byte cert/key;
  migrasi production belum dilakukan dan existing path tetap gate terbuka.
- Candidate composition membutuhkan authenticated preparation inputs, consumed
  preparation authorization ADR-021, atomic protected empty root, proposed
  handle-relative ADR-016 lease sebagai first child/write, skeleton/marker,
  proposed preparation ACL, materialization, source/vendor/config finalization,
  verified final composition admission ADR-021, lalu fresh final ADR-017
  admission sebelum proxy/browser launch. Current monolithic builder dan lease
  path tetap unusable.
- Browser memakai exact one-SPKI transport exception pada disposable run-local
  profile. Independent full certificate validation sebelum launch tetap wajib;
  Chromium tidak diklaim membuktikan hostname/validity/policy di bawah flag itu.
- Offline dependency closure menambah artifact review dan rotasi, tetapi
  menghilangkan ambient installed-package authority.
- Encrypted PKCS#8 dan one-shot password pipe mempersempit exposure, tetapi
  retained-handle, pipe inheritance, callback, memory cleanup, dan Windows ACL
  efficacy tetap membutuhkan implementasi serta native evidence.
- Failure lebih awal akan menahan launch daripada memakai material fallback.
- Tidak ada code, dependency, certificate, candidate, native operation,
  runtime, configuration, deployment, payment, atau gate activation yang
  diterima oleh status Accepted-for-contract ini.
- P15, P16, P17c, dan P18 tetap terbuka. Semua checkout/payment gates tetap
  default OFF dan checklist/progress tidak berubah.

## Keputusan implementasi dan runtime yang tetap terbuka

Pilihan kontrak di atas sudah diterima. Hal berikut belum diterima dan tidak
boleh diinferensikan dari status ADR:

1. exact wheel filenames, versions, SHA-256, RECORD/native-extension/DLL
   inventory, license, dan generation pada acquisition evidence;
2. implementasi preparation authorization/ACL, handle-relative lease,
   `TlsMaterialCapability`, issuer helper, consumer proxy, password pipe,
   acknowledgment, dan final composition wiring;
3. native Windows evidence untuk ACL, retained sharing semantics, no-reparse,
   file/process identity, handle inheritance, crash cleanup, dan secret-memory
   lifetime;
4. actual pinned Chromium/Playwright evidence bahwa exact SPKI flag serta
   run-local user-data-dir efektif dan tidak ada argumen/context tambahan; dan
5. explicit authority untuk melakukan dependency acquisition, certificate
   generation, native tests, candidate/browser execution, atau activation.

## Acceptance Criteria

Contract ini diterima dengan syarat implementasi berikut tetap menjadi gate:

- issuer artifact/dependency authority dan transport-exception consumer
  memiliki exact implementable contract tanpa discovery atau fallback;
- ADR-021 preparation authorization, atomic protected empty root, proposed
  handle-relative ADR-016 lease sebagai first child/write, skeleton/marker,
  separately accepted preparation ACL, `preparationBinding`, final config,
  verified ADR-021 final composition admission, dan fresh final ADR-017
  admission memiliki exact ordered composition;
- private-key custody dimulai setelah accepted native preparation-ACL
  prerequisite dan tidak masuk serialized config/evidence;
- separate Accepted request/evidence codec menetapkan exact closed v1 key sets,
  canonical bounds/serialization, domain-separated digests, dan fail-closed
  rejection sebelum `materialize` dapat diimplementasikan;
- consumer mengganti current proxy argv/path-reopen interface serta mempertahankan
  exact handle/path/identity continuity sampai proxy terminal;
- request/evidence, one-shot lifecycle, cleanup, privacy, dan redacted failure
  rules disetujui;
- pure and native acceptance boundaries tetap terpisah; dan
- independent adversarial review tidak menemukan blocker P1/P2.

Accepted-for-contract tetap tidak mengotorisasi certificate generation,
dependency installation, native Windows calls, candidate/browser execution,
config/environment changes, deployment, payment, atau gate activation.

## References

- [ADR-016: Lease lifecycle global candidate checkout](0016-checkout-candidate-lifecycle-lease.md)
- [ADR-017: Attestation ACL Windows checkout](0017-checkout-windows-acl-attestation.md)
- [ADR-021: Authority persiapan release kandidat checkout](0021-checkout-release-preparation-authority.md)
- [ADR-020: Authority validasi evidence ordinary Windows](0020-ordinary-evidence-validation-authority.md)
- [RFC 5280: Internet X.509 Public Key Infrastructure Certificate and CRL Profile](https://www.rfc-editor.org/rfc/rfc5280)
- [RFC 6125: Service Identity in TLS](https://www.rfc-editor.org/rfc/rfc6125)
- [`cryptography` X.509 tutorial](https://cryptography.io/en/45.0.6/x509/tutorial/)
- [`cryptography` X.509 reference](https://cryptography.io/en/latest/x509/reference/)
- [`cryptography` key serialization](https://cryptography.io/en/latest/hazmat/primitives/asymmetric/serialization/)
- [Python `SSLContext.load_cert_chain`](https://docs.python.org/3/library/ssl.html#ssl.SSLContext.load_cert_chain)
- [Python Windows `STARTUPINFO` handle list](https://docs.python.org/3/library/subprocess.html#subprocess.STARTUPINFO)
- [Playwright `Browser.newContext` options](https://playwright.dev/docs/api/class-browser#browser-new-context)
- [Playwright persistent browser context](https://playwright.dev/docs/api/class-browsertype#browser-type-launch-persistent-context)
- [Chromium SPKI ignore-errors verifier](https://chromium.googlesource.com/chromium/src/+/HEAD/services/network/ignore_errors_cert_verifier.cc)
- [Chromium SPKI switch definition](https://chromium.googlesource.com/chromium/src/+/HEAD/services/network/public/cpp/network_switches.cc)
- [Microsoft `CreateFileW`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilew)
- [Microsoft process handle inheritance](https://learn.microsoft.com/en-us/windows/win32/procthread/inheritance)
- [Microsoft `PROC_THREAD_ATTRIBUTE_HANDLE_LIST`](https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/nf-processthreadsapi-updateprocthreadattribute)
