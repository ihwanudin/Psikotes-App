# ADR-022: Authority material TLS sintetis checkout

## Status

Proposed. ADR ini hanya mengusulkan contract authority, policy sertifikat, dan
batas acceptance. ADR ini tidak memilih teknologi issuer atau dependency,
tidak menerima implementasi, dan tidak mengotorisasi pembuatan sertifikat,
candidate, browser, service, native Windows, deployment, atau activation.

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

Successful `materialize` tetap disabled sampai issuer technology, artifact
acquisition, preparation ACL, final ACL admission, material consumer, browser
trust, dan native acceptance yang disebut ADR ini diterima.

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
belum diterima oleh ADR Proposed ini. Implementasi saat ini tidak mempunyai
authority tersebut dan tetap unusable. Kontrak berikutnya wajib mensyaratkan
native handle/identity continuity, owner-only protected DACL, no-reparse, serta
ordinary-principal denial yang setara dengan policy ADR-017, tetapi terikat ke
preparation policy dan state karena final config belum ada.

Schema request version pertama sekurang-kurangnya mengikat exact:

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
- issuance timestamp dan maximum lifetime;
- required minimum remaining lifetime yang berasal dari runtime budget plus
  cleanup budget; dan
- requested algorithm profile.

Caller tidak boleh memilih path output, issuer executable, dependency root,
trust bypass, subject content, serial, key bytes, atau entropy source.
Composition menurunkan semua nilai tersebut dari authority yang sudah dipin.

Request digest memakai domain separation dan exact canonical request bytes.
Replay request, challenge reuse, binding drift, generation drift, atau request
yang tidak cocok dengan held lifecycle lease wajib ditolak sebelum material
dibuat.

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
path/hash key ke config. Sebelum acceptance, preparation wajib dipisah menjadi:

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
   public browser config tanpa key path/hash/identity, lalu segel final manifest
   dan hitung final `configBinding`;
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

Final serialized config hanya boleh memuat public certificate evidence dan
chosen public trust configuration. Ia tidak boleh memuat capability identifier,
reference, lookup key, object metadata, atau private binding dalam bentuk apa
pun. Exact capability object/type/implementation/lifecycle identity hanya dipin
di private composition admission/state, tidak caller-addressable atau
retrievable. Key metadata dan handles tetap eksklusif di capability.
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
membuka replacement/identity race. Sebelum runtime acceptance, user harus
memilih salah satu mekanisme consumer berikut:

1. supervisor mempertahankan exact certificate/key handles dengan share mode
   yang menolak delete/rename selama umur proxy; child membuka canonical fixed
   run-local names tanpa menerima key path lewat argv/environment, lalu memberi
   acknowledgment terikat bahwa exact final path, volume/file identity, size,
   dan hash cocok sebelum TLS listen; supervisor memvalidasi ulang melalui held
   handles sebelum dan sesudah acknowledgment; atau
2. explicit Windows handle transfer dengan noninheritability, least privilege,
   exact child/process binding, acknowledgment, cleanup, dan replay contract
   yang diputuskan melalui ADR berikutnya.

Tidak satu pun dipilih oleh ADR ini. Current proxy path-reopen interface tetap
unusable. Unencrypted PKCS#8 hanya dapat dipertimbangkan setelah user menerima
open decision terkait, native ACL efficacy terbukti, dan consumer mechanism
diterima. Encrypted key memerlukan contract password channel terpisah; password
tidak boleh diselundupkan melalui argv, environment, JSON, stdin umum, atau log.

### Policy X.509 minimum

Material hanya untuk host sintetis checkout dan wajib memenuhi exact policy:

- tepat satu self-signed end-entity certificate, tanpa chain tambahan;
- RSA minimal 2048 bit dengan signature SHA-256, sampai algorithm profile lain
  diputuskan melalui ADR;
- `BasicConstraints` critical dan exact `CA=false`;
- SAN berisi tepat dua `dNSName`, `psikotes.oncam.id` dan `oncam.id`, dalam
  canonical order, tanpa wildcard, IP address, URI, atau nama tambahan;
- EKU exact hanya `serverAuth`;
- Key Usage critical dan, untuk RSA profile, exact `digitalSignature` serta
  `keyEncipherment` tanpa bit tambahan;
- subject dan issuer exact minimal, sama karena self-signed, tanpa PII, account,
  username, machine name, atau environment name;
- positive random serial yang memenuhi batas X.509/RFC dan tidak berasal dari
  timestamp, PID, session, atau identifier bisnis;
- `notBefore` UTC tidak lebih lambat dari issuance time dan hanya memakai skew
  mundur terbatas yang ditetapkan policy;
- `notAfter` UTC tidak melebihi issuance time plus 24 jam; dan
- saat handoff, sisa masa berlaku minimal harus mencakup exact runtime budget
  ditambah cleanup budget.

Certificate file wajib memuat tepat satu canonical PEM certificate. Key file
wajib memuat tepat satu unencrypted PKCS#8 PEM private key bila keputusan
tersebut kelak diterima. Extra PEM block, comment, prefix/suffix, chain, legacy
key label, encrypted-key ambiguity, atau trailing noncanonical content ditolak.
Public key certificate wajib exact cocok dengan private key.

Issuer dan consumer wajib parse ulang policy dari bytes yang tersimpan; boolean
`policySatisfied` dari producer tidak menjadi authority.

### Trust browser tetap keputusan terbuka

Trust tidak boleh memakai `ignoreHTTPSErrors`, global
`--ignore-certificate-errors`, disable hostname verification, perubahan system
trust store, atau fallback ke trust host yang tidak dipin.

Authority menerbitkan public SPKI SHA-256 digest dari leaf certificate sebagai
salah satu input kandidat trust. Mekanisme trust browser belum dipilih oleh ADR
ini. Leaf-SPKI pin dapat dipertimbangkan hanya bila browser/CLI yang dipin
membuktikan semantics tepatnya dan composition mengikat exact digest serta dua
synthetic host ke final config.

SPKI pin adalah public metadata, bukan secret. Namun caller tidak boleh memilih
atau menggantinya. SPKI match saja tidak boleh diklaim membuktikan hostname,
validity, EKU, Key Usage, atau seluruh certificate policy; beberapa browser flag
dapat mengubah semantics validasi yang tersisa. Semua sifat tersebut wajib diuji
terpisah setelah mekanisme trust dipilih. Perubahan pin setelah materialization,
context tambahan yang memakai bypass luas, atau browser yang tidak membuktikan
dukungan dan semantics pin wajib menolak runtime.

Jika mekanisme browser yang dipilih tidak dapat memberi trust host-scoped atau
SPKI-scoped dengan bukti yang cukup, keputusan trust harus kembali ke status
open. ADR ini tidak mengotorisasi perubahan trust store mesin.

### Acquisition dan supply-chain issuer

Rekomendasi awal adalah helper issuer milik repository yang memakai dependency
cryptographic dari offline artifact bundle. Teknologi dan versi dependency
tetap open sampai user memilih dan review menerima authority-nya.

Jika `cryptography` dipilih, acceptance acquisition sekurang-kurangnya wajib:

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

OpenSSL CLI bukan default karena executable saja tidak mengikat config file,
provider modules, DLL search, entropy/runtime behavior, dan seluruh distribution
closure. OpenSSL hanya dapat dipilih bila authority lengkap tersebut diputuskan
dan dibuktikan terpisah.

### Public certificate evidence dan private capability

Public certificate evidence adalah satu-satunya bagian yang boleh diserialisasi.
Ia hanya memuat data publik dan immutable yang diperlukan untuk cross-binding.
Exact schema version pertama sekurang-kurangnya memuat:

- request digest dan challenge;
- run/session/preparation/lease bindings;
- issuer policy, artifact manifest digest, dan generation;
- certificate SHA-256 fingerprint dan SPKI SHA-256 digest;
- parsed algorithm, key size, serial policy result, subject/issuer profile,
  SAN, EKU, Key Usage, Basic Constraints, dan validity timestamps;
- certificate file canonical path, filesystem identity, byte size, dan hash;
- minimum-remaining-lifetime check terhadap runtime dan cleanup budget.

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
config mengikat digest public evidence; final `configBinding` baru dihitung
setelah itu dan diverifikasi oleh fresh final ADR-017 admission. Output error
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
dipakai. Consumer yang kelak dipilih hanya boleh memperoleh private material
melalui exact capability/handle contract, sementara path tetap tidak boleh masuk
user-facing diagnostics.

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
- static absence of private-key transport dan broad trust-bypass switches.

Pure tests tidak membuktikan issuer cryptography, CSPRNG, native key protection,
Windows ACL efficacy, file identity durability, browser trust behavior, TLS
hostname/SPKI validation, crash cleanup, reparse/rename resistance, atau actual
candidate execution.

Native/runtime acceptance kelak wajib membuktikan sekurang-kurangnya:

- offline pinned dependency acquisition pada target host;
- real native ACL/access denial dan noninheritability sebelum key generation;
- actual key generation, X.509 parse, entropy behavior, dan file revalidation;
- primary browser context memakai exact trust mechanism yang dipilih dan terikat
  ke final public evidence/config tanpa broad bypass;
- successful TLS handshake, ditambah separate negative proof untuk wrong host,
  wrong trust material, expired, not-yet-valid, extra SAN, dan broad bypass;
  hasil tersebut tidak boleh diasumsikan berasal dari SPKI match saja;
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

Ditolak. `ignoreHTTPSErrors` dan broad certificate-error flags membuat test
tidak membuktikan hostname, chain, validity, atau trust material yang dipilih.

### Memakai OpenSSL dari PATH

Ditolak. PATH lookup dan executable version tidak mengikat config, providers,
DLL, dependency, atau artifact acquisition.

### Mengirim encrypted-key password melalui argv atau environment

Ditolak. Kedua channel mudah diwariskan atau diobservasi dan memperluas secret
surface. Password-bearing design memerlukan ADR dan sealed channel tersendiri.

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
- Trust browser tetap open. Mekanisme yang dipilih harus mengikat final public
  evidence/config dan membuktikan hostname/validity/policy secara terpisah;
  broad bypass menjadi pelanggaran contract.
- Offline dependency closure menambah artifact review dan rotasi, tetapi
  menghilangkan ambient installed-package authority.
- Unencrypted run-local key mungkin tetap diperlukan oleh proxy sekarang,
  tetapi tidak diterima sampai open decision dan native ACL evidence selesai.
- Failure lebih awal akan menahan launch daripada memakai material fallback.
- Tidak ada code, dependency, certificate, candidate, native operation,
  runtime, configuration, deployment, payment, atau gate activation yang
  diterima oleh ADR Proposed ini.
- P15, P16, P17c, dan P18 tetap terbuka. Semua checkout/payment gates tetap
  default OFF dan checklist/progress tidak berubah.

## Open Decisions Requiring User Approval

ADR ini tidak boleh dipindahkan ke Accepted sampai user memilih:

1. **Teknologi issuer dan acquisition authority** — repository-owned helper
   dengan pinned offline `cryptography` wheel closure, atau alternatif dengan
   supply-chain closure yang setara.
2. **Mekanisme trust browser** — exact leaf-SPKI binding yang benar-benar
   didukung toolchain browser, atau authority trust terisolasi lain; broad
   certificate bypass dan system-store mutation tetap tidak boleh dipilih.
3. **Allowance private key** — apakah unencrypted PKCS#8 diizinkan hanya di
   owner-only protected run directory setelah native ACL gate, atau proxy dan
   password channel harus didesain ulang lebih dahulu.
4. **Runtime budget** — maximum run duration, cleanup allowance, clock-skew
   allowance, dan minimum remaining certificate lifetime pada handoff.
5. **Consumer mechanism** — retained supervisor handles dengan exact child-open
   acknowledgment, atau explicit handle transfer yang memiliki ownership,
   identity, cleanup, dan replay contract lengkap.

Keputusan user atas item tersebut hanya menerima design. Pembuatan artifact,
dependency acquisition, native test, candidate execution, atau activation
tetap memerlukan authority dan acceptance terpisah.

## Acceptance Criteria

ADR dapat dipindahkan ke Accepted-for-contract hanya setelah:

- seluruh open decision di atas dipilih secara eksplisit;
- issuer artifact/dependency authority dan trust consumer memiliki exact
  implementable contract tanpa discovery atau fallback;
- ADR-021 preparation authorization, atomic protected empty root, proposed
  handle-relative ADR-016 lease sebagai first child/write, skeleton/marker,
  separately accepted preparation ACL, `preparationBinding`, final config,
  verified ADR-021 final composition admission, dan fresh final ADR-017
  admission memiliki exact ordered composition;
- private-key custody dimulai setelah accepted native preparation-ACL
  prerequisite dan tidak masuk serialized config/evidence;
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
- [Python `SSLContext.load_cert_chain`](https://docs.python.org/3/library/ssl.html#ssl.SSLContext.load_cert_chain)
