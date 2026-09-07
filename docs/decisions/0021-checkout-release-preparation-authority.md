# ADR-021: Authority persiapan release kandidat checkout

- Status: Accepted-for-contract
- Tanggal: 2026-09-07

## Konteks

Jalur kandidat checkout memerlukan sumber yang telah direview, dependency Composer, aset checkout, tool/runtime host, konfigurasi runtime, material TLS, dan admission ACL yang semuanya terikat ke objek kandidat yang sama. Kontrak saat ini belum menyediakan authority eksternal untuk membuktikan rangkaian tersebut:

- `source_revision` hanya divalidasi sebagai 40 karakter heksadesimal dan belum mengikat manifest ke commit/tree Git yang telah direview;
- `vendor/` diabaikan Git, sehingga checkout sumber saja tidak membuktikan isi dependency yang dipakai;
- baseline belum memiliki tag atau tanda tangan release yang dapat dijadikan trust root;
- `asset_delivery_review` dapat diterbitkan oleh caller yang sama dan baru dibandingkan dengan manifest, sehingga belum membuktikan review independen;
- inventory tool yang ada belum membuktikan closure dependency runtime transitif pada host; dan
- `build_candidate()` saat ini bersifat monolitik: sumber, vendor, konfigurasi, sertifikat/kunci, dan manifest final diproses dalam satu alur. Bentuk ini tidak dapat memenuhi urutan ACL-persiapan lalu TLS lalu admission final.

Karena itu candidate builder yang ada belum merupakan jalur persiapan yang disahkan. ADR ini menerima hanya kontrak authority, signature, trust-root bootstrap, revocation, dan dependency graph. Status ini tidak menerima implementation, dependency acquisition, provisioning, native evidence, candidate build, atau mekanisme runtime.

## Keputusan

### 1. Dependency graph satu arah

Artifact authority membentuk directed acyclic graph berikut:

```text
release/source artifact
  |-- vendor artifact
  |-- asset-review artifact
  `-- composition admission

tool/runtime closure descriptor ---------|
runtime configuration policy ------------|--> composition admission
preparation ACL evidence ----------------|
ADR-022 public TLS evidence digest -------|
run identity + ADR-016 lease ------------|
```

Artifact upstream tidak boleh memuat digest artifact downstream. Hanya composition admission yang mengikat seluruh digest upstream ke satu run. Dengan demikian source issuer tidak dapat menerbitkan atau mengganti hasil review, vendor, tool, konfigurasi, TLS, atau admission secara sirkular.

Sebelum final composition admission tersedia, composition-admission authority menerbitkan **preparation authorization** dengan domain/schema terpisah yang hanya mengizinkan pembuatan satu skeleton fresh dan penerapan ACL persiapan, dengan lifetime maksimum 10 menit. Artifact sempit ini mengikat artifact statis pra-TLS dan constraint destination/generation, tetapi tidak menyatakan komposisi final dan tidak berpura-pura mengikat TLS atau evidence yang belum ada. Preparation authorization tidak dapat dipakai sebagai final composition admission atau ADR-017 admission.

### 2. Authority dan artifact kanonis

Set authority minimum adalah:

1. release/source authority;
2. vendor-build authority;
3. asset-review authority;
4. tool/runtime-closure authority;
5. runtime-configuration-policy authority;
6. proposed preparation-ACL authority yang terpisah, serta final ACL admission
   menurut ADR-017;
7. TLS authority yang didelegasikan ke ADR-022; dan
8. composition-admission authority; serta
9. independent revocation trust authority atau threshold set untuk setiap
   namespace authority yang direvokasi.

Setiap role menerbitkan artifact kanonis yang terpisah dan terautentikasi. Setiap schema harus versioned dan domain-separated, memiliki exact key/type, bounded canonical ASCII sorted-key JSON encoding dengan satu LF, identifier role/issuer, artifact digest, generation, `issuedAt`, `expiresAt`, dan replay identifier. Duplicate key, non-finite number, field tambahan, encoding nonkanonis, atau autentikasi yang tidak tepat harus ditolak.

Baseline signature adalah detached Ed25519. Untuk setiap role, exact signed message adalah byte prefix ASCII `oncam.checkout.<role>.v1`, satu NUL, lalu exact canonical artifact bytes. Detached signature envelope juga canonical dan exact; sekurang-kurangnya mengikat literal algorithm `ed25519`, role, issuer ID, key ID, key generation, trust generation, artifact SHA-256 digest, dan signature 64 byte yang dikodekan sebagai tepat 128 karakter heksadesimal lowercase. Public key wajib exact 32 byte dan direpresentasikan sebagai tepat 64 karakter heksadesimal lowercase di trust bundle. Repository-owned verifier memverifikasi canonical bytes, digest, role/domain, key generation, threshold, revocation, dan signature sebelum artifact dapat dipakai.

Verifier memakai exact isolated Python runtime dan pinned offline `cryptography` wheel closure. Wheel, interpreter, verifier source, RECORD/package inventory, native extension, bundled OpenSSL/DLL, loader policy, serta seluruh transitive runtime files wajib memiliki exact authenticated path/identity/hash. Exact accepted wheel version dan hash ditetapkan oleh acquisition acceptance evidence tersendiri; package ambient yang kebetulan terpasang tidak pernah menjadi authority dan tidak boleh menjadi fallback.

Setiap authority role memiliki key berbeda. Asset review wajib 2-of-2 dari dua reviewer keys independen dan release/source key tidak boleh menjadi salah satu signer. Role artifact lain memakai exact role-specific key yang tercatat trust bundle; penggabungan role tidak diizinkan tanpa ADR baru. Trust-root bundle dan setiap rotasinya memakai 2-of-3 offline root custodians. Rotasi wajib lolos threshold 2-of-3 old roots **dan** 2-of-3 new roots serta menaikkan `trustGeneration`. Revocation snapshot memakai independent 2-of-3 revocation custodians yang tidak menjadi issuer artifact yang direvokasi.

Private signing keys hanya berada pada offline signer custody masing-masing role dan tidak pernah berada pada candidate atau verifier host. Tidak ada private key, passphrase, signer callback, atau signer selection pada builder/config/request. Bootstrap default adalah verifikasi fingerprint bundle/verifier offline oleh dua operator melalui dua salinan/channel yang independen. Enterprise Authenticode/catalog PKI hanya dapat menggantikan ceremony itu setelah authority, certificate chain, publisher, root, revocation, dan installer contract-nya diterima melalui keputusan terpisah.

Durasi maksimum dihitung dari `issuedAt` sampai `expiresAt`: release/source,
vendor, asset review, tool/runtime closure, dan runtime-configuration-policy
artifact maksimum 7 hari; trust-root bundle maksimum 366 hari agar satu periode
tahunan tetap kanonis melintasi tahun kabisat; preparation authorization maksimum
10 menit; final composition admission maksimum 5 menit. Durasi nol/negatif,
overflow, timestamp non-UTC/nonkanonis, expiry yang melampaui batas, atau waktu
yang tidak dapat dipercaya wajib menolak. Seluruh artifact, termasuk trust-root
bundle, hanya dapat dievaluasi pada interval setengah-terbuka
`issuedAt <= trustedNow < expiresAt`. TTL adalah batas atas, bukan izin untuk
memakai artifact setelah revocation atau binding drift.

#### Release/source artifact

Artifact ini adalah root authority untuk kode sumber dan hanya memuat `sourceFiles`; `vendor/` dikecualikan. Ia mengikat exact reviewed commit/tree/source revision dan path serta digest setiap source file, termasuk exact digest `composer.lock`. Ia tidak boleh memuat digest asset review, vendor artifact, tool/runtime descriptor, runtime policy, TLS, preparation ACL, atau composition admission.

Fresh export harus dibuat dari exact source artifact tanpa mengambil file dari working tree yang kotor. Dirty tree, untracked file yang masuk inventory, submodule/LFS state yang tidak dibuktikan, path collision, symlink/reparse yang tidak diizinkan, atau perbedaan byte harus menolak persiapan.

#### Vendor artifact

Artifact vendor mengikat exact release/source artifact digest dan exact `composer.lock` digest yang tercatat di source artifact. Ia memuat inventory package dan file `vendor/`, digest byte, metadata runtime yang diperlukan, serta provenance build yang diautentikasi vendor-build authority. Artifact ini tidak boleh mengikat artifact downstream.

Source dan vendor dari release berbeda tidak boleh digabung. Exact reproducible-build policy, key-to-operator assignment, dan bukti issuer vendor-build merupakan acceptance evidence operasional yang belum tersedia; hal itu tidak mengubah baseline role/key separation yang diterima ADR ini.

#### Asset-review artifact

Artifact review aset diterbitkan secara independen dan mengikat exact release/source artifact digest serta exact path dan digest enam file berikut:

1. `public/css/checkout-summary-v1.css`;
2. `public/brand/oncam-logo-full-color.png`;
3. `public/js/checkout-confirmation-v1.js`;
4. `public/js/checkout-payment-v1.js`;
5. `resources/views/checkout/summary.blade.php`; dan
6. `tools/testing/tests/Browser/serve-checkout-session.php`.

Review ini tidak mengubah source manifest dan tidak menjadi input bagi release/source artifact. Source/release issuer tidak boleh menjadi salah satu dari dua asset-review signers. Identitas operator/reviewer dan bukti custody kedua asset-review keys merupakan acceptance evidence operasional yang belum tersedia; threshold 2-of-2 dan pemisahan dari release/source key sudah tetap.

#### Tool/runtime closure descriptor

Descriptor ini diautentikasi secara independen dan host-specific. Ia mengikat exact canonical path, object identity, digest, dan version untuk PHP, Python, Node.js, PowerShell, Playwright CLI, serta browser yang disahkan.

Descriptor juga harus menutup seluruh dependency runtime transitif yang dapat memengaruhi eksekusi: DLL/shared library, provider module, configuration file, package root, loader/search policy, dan resource runtime lain yang diperlukan. Jika closure transitif tidak dapat dihitung dan dibuktikan secara bounded, host harus ditolak; daftar executable top-level saja tidak cukup.

#### Runtime-configuration-policy artifact

Artifact ini diautentikasi secara independen dan mengikat exact policy untuk byte/semantik `runtime.ini`, template dan schema browser config, launch arguments, port/origin, offline/network restrictions, budget, dan nama output. Artifact tidak boleh berisi secret atau memberi caller kewenangan memilih path/tool/policy alternatif.

#### TLS authority

Pembuatan, penyimpanan, binding, dan validasi material TLS didelegasikan ke ADR-022. ADR-022 berstatus **Accepted-for-contract** dan menjadi dependency eksplisit ADR ini, tetapi status tersebut tidak menyediakan implementation, material, native evidence, atau authority runtime. Langkah TLS tidak boleh dianggap tersedia sebelum seluruh acceptance evidence dan implementasi ADR-022 diterima secara terpisah.

Artifact dan final serialized config hanya mengikat exact digest public certificate
evidence yang ditetapkan ADR-022. Path, hash, identity, size, atau metadata lain
dari private key tidak boleh masuk artifact, manifest, config, admission,
evidence publik, log, maupun output. Custody private key tetap berada pada exact
opaque, nonserializable `TlsMaterialCapability`; composition hanya mengikat
object identity capability tersebut secara privat dan tidak mengubahnya menjadi
identifier yang dapat dipakai untuk mengambil key.

#### Composition admission

Hanya composition admission yang melakukan cross-binding exact digest release/source, vendor, asset review, tool/runtime closure, runtime configuration policy, preparation ACL evidence, public TLS certificate evidence, canonical destination/run identity, generation, expiry/revocation state, dan lease ADR-016. Binding privat yang hidup bersama admission juga memegang exact object identity `TlsMaterialCapability`; capability dan metadata private key tidak diserialisasi. Artifact upstream tidak boleh menunjuk kembali ke composition admission.

Final composition admission baru dapat diterbitkan setelah TLS, preparation ACL evidence, source, config, dan manifest final tersedia. Ia bersifat one-shot, memiliki lifetime maksimum 5 menit, dan hanya berlaku untuk exact set artifact, fresh destination, dan generation yang tercantum. Perubahan byte, path, identity, policy, atau artifact setelah admission menghabiskan/refuse admission; admission tidak boleh digunakan ulang untuk retry atau run lain.

### 3. Revocation, expiry, dan replay

Setiap role authority memiliki offline monotonic revocation snapshot yang ditandatangani 2-of-3 oleh independent revocation custodians yang tidak dapat digantikan issuer artifact tersebut. Snapshot memiliki namespace exact yang mencakup authority role, issuer identity, issuer-key generation, dan revocation-trust generation. Ia mengikat schema, snapshot digest, monotonic generation, `issuedAt`, `nextUpdate`, cutoff/not-before yang berlaku, serta daftar artifact ID/digest atau key generation yang dicabut. `nextUpdate - issuedAt` wajib positif dan maksimum 24 jam. Consumer harus menyimpan highest accepted generation pada protected monotonic state dan menolak rollback, snapshot hilang/stale/future/conflicting, artifact expired, replay identifier yang sudah dipakai, dan artifact yang dicabut.

Exact snapshot digest, generation, `issuedAt`, dan `nextUpdate` per namespace wajib terikat ke preparation authorization dan final composition admission. Preparation memakai satu set snapshot yang byte, path/object identity, trust result, dan protected monotonic state-nya dipin. Set yang sama wajib direvalidasi sebelum preparation authorization dikonsumsi dan sekali lagi sebelum final composition admission/final boundary; perubahan, replacement, expiry, atau perbedaan set menolak run. Cache atau referensi remote lokal bukan revocation authority.

Threat model baseline untuk local high-water state adalah writer ordinary/non-admin; administrator, kernel compromise, atau offline disk rollback tidak dianggap dapat dicegah oleh software file state ini. State disimpan sebagai protected bounded two-slot atau append journal dengan exact generation, snapshot digest, previous-record digest, checksum/authentication, dan commit marker. Update menulis slot/record baru, flushes content dan metadata, memvalidasi ulang exact handle/identity/content, lalu baru memajukan active generation; corruption, partial write, ambiguous active record, atau missing state menolak tanpa auto-repair. Value journal tidak boleh berasal dari caller.

Generation adalah freshness authority utama. Local wall clock hanya dapat memperketat refusal dan tidak dapat menaikkan trust. Setelah reboot, clock rollback/uncertainty, journal uncertainty, atau ketika authenticated `nextUpdate` tidak dapat dibuktikan masih berlaku, verifier wajib fail closed. Recovery hanya boleh menerima 2-of-3 signed revocation snapshot untuk exact current authority namespace dan revocation-trust generation dengan generation yang **strictly lebih tinggi** daripada setiap revocation-generation candidate yang pernah teramati lokal dan last accepted generation yang masih dapat dibuktikan. Same-generation snapshot selalu ditolak, termasuk ketika digest-nya sama; replay tidak dapat dipakai untuk “mengonfirmasi” freshness.

Jika trustworthy last generation/high-water tidak bertahan atau upper bound seluruh generation yang pernah teramati tidak dapat dibuktikan, prosedur offline biasa tidak dapat memulihkan freshness. Verifier tetap fail closed sampai separately accepted external/TPM recovery authority membuktikan monotonic state dan menerbitkan recovery binding untuk exact namespace/trust generation. ADR ini tidak memilih atau menganggap authority tersebut tersedia, dan tidak mengubah local clock menjadi trust source.

### 4. Lifecycle persiapan bertahap

Lifecycle yang disahkan harus mengikuti urutan berikut tanpa pertukaran langkah:

1. autentikasi seluruh artifact statis per-role secara read-only, tanpa membuat destination atau mengonsumsi authority;
2. revalidasi independent revocation snapshots terhadap exact protected monotonic state dan pin exact snapshot set yang akan dipakai sepanjang run;
3. validasi lalu consume preparation authorization one-shot yang hanya mengizinkan satu fresh run root dengan exact parent, security descriptor, artifact set, dan revocation snapshot set;
4. melalui already validated and pinned parent authority, atomically create fresh run root **bersama** exact owner-only protected security descriptor pada operasi create yang sama; create-directory lalu `SetACL` dilarang karena meninggalkan interval permissive. Segera tahan handle root dan pin canonical path, final path, no-reparse state, volume/file identity, owner, serta DACL sebelum child dibuat;
5. sebagai child dan write pertama di dalam held run root, create lalu acquire candidate-global lifecycle lease ADR-016 secara handle-relative dan fail-closed;
6. baru setelah lease dipegang, buat marker incomplete dan skeleton minimum di bawah held root tanpa source, vendor, runtime config, browser config, atau TLS material final;
7. jalankan proposed preparation-ACL attestation untuk exact parent/root/skeleton/output identities dan turunkan preparation evidence;
8. setelah preparation ACL berhasil, jalankan TLS authority sesuai contract ADR-022 yang implementasi dan native evidence-nya telah diterima secara terpisah, lalu ikat hanya public certificate evidence digest serta exact private `TlsMaterialCapability` object identity;
9. copy dan finalisasi source, vendor, runtime/browser configuration, manifest, tool/runtime binding, dan public certificate evidence; bentuk exact final supervisor config dan `configBinding` tanpa path/hash/identity/size/metadata private key sambil marker incomplete tetap ada;
10. terbitkan dan verifikasi final composition admission yang mengikat seluruh exact upstream digest, pinned revocation snapshot set, preparation evidence, public TLS evidence digest, private capability object identity, run identity, lease, dan final `configBinding`;
11. lakukan admission ADR-017 yang baru dan penuh terhadap final `configBinding`, target, serta source identity pada boundary anchor dan execution tepat sebelum eksekusi; preparation authorization dan preparation-ACL evidence tidak boleh digunakan kembali sebagai admission final; dan
12. hanya setelah seluruh validasi final berhasil, publikasikan state final/handoff sesuai prosedur yang diterima. Kegagalan mempertahankan state incomplete, menghabiskan authority one-shot, melepaskan resource secara fail-closed, dan tidak melakukan retry otomatis.

Preparation-ACL attestation adalah capability **Proposed** yang terpisah. Ia boleh memakai ulang exact policy semantics ADR-017 untuk owner, protected DACL, no-reparse, identity continuity, dan ordinary-principal denial, tetapi ia bukan accepted ADR-017 boundary, tidak memakai final `configBinding`, dan tidak dapat menghasilkan atau menggantikan final ADR-017 admission.

ADR-016 saat ini membuat/membuka lease melalui path setelah run directory sudah ada. Langkah 5 memerlukan extension ADR-016 yang masih Proposed: lease creation/acquisition handle-relative di bawah held root, dengan root/lease handle dan identity dipin tanpa path reopen. Current ADR-016 implementation dan `build_candidate()` monolitik harus direfaktor agar dapat menjalankan lifecycle tersebut. Sampai extension, refactor, native review, dan acceptance terpisah selesai, keduanya tetap tidak dapat digunakan sebagai jalur persiapan yang disahkan.

### 5. Failure policy

Persiapan menolak secara tetap dan tanpa fallback ketika salah satu kondisi berikut terjadi:

- artifact/authentication/schema/issuer tidak tepat, hilang, expired, revoked, replayed, generation mundur, atau revocation snapshot memakai generation yang sama;
- domain signature, algorithm Ed25519, artifact digest, role/key/trust generation, atau threshold signer tidak tepat;
- static non-trust-root artifact melampaui lifetime maksimum 7 hari,
  trust-root bundle melampaui 366 hari, revocation `nextUpdate` melampaui 24
  jam, preparation authorization melampaui 10 menit, atau final composition
  admission melampaui 5 menit;
- trust bundle belum melalui bootstrap fingerprint dua-operator/dua-channel, rotasi tidak memenuhi 2-of-3 old dan 2-of-3 new, atau revocation snapshot tidak memenuhi independent 2-of-3;
- verifier/runtime/wheel closure tidak memiliki exact acquisition acceptance evidence atau mencoba memakai package ambient/fallback;
- dependency graph atau cross-binding tidak cocok;
- source dan vendor berasal dari release yang berbeda;
- source/release issuer mencoba menjadi satu-satunya asset reviewer;
- closure tool/runtime transitif tidak lengkap;
- revocation snapshot tidak berasal dari independent trust role/threshold,
  namespace tidak cocok, generation rollback, atau protected snapshot pre/post
  berbeda;
- reboot, clock, atau journal uncertainty belum dipulihkan dengan signed revocation snapshot yang memenuhi strict-higher-generation/current-namespace/current-trust-generation rule, atau trustworthy high-water hilang tanpa separately accepted external/TPM recovery authority;
- destination tidak fresh/canonical atau identity berubah;
- run root dibuat sebelum authority dikonsumsi, ACL dipasang setelah create,
  atau lease bukan child/write pertama di bawah held root;
- lifecycle dijalankan di luar urutan;
- preparation ACL evidence diperlakukan sebagai final ADR-017 admission;
- metadata/path/hash private key masuk artifact/config/evidence atau private
  capability diganti/diserialisasi;
- config/source/manifest/TLS berubah setelah binding final; atau
- dependency atau authority yang masih Proposed dianggap aktif tanpa keputusan penerimaan, atau contract Accepted-for-contract dianggap sebagai implementation/runtime authority.

Tidak ada fallback ke argv, environment, working tree, network fetch, cached remote reference, atau self-issued review.

### 6. Privacy dan secret handling

Artifact authority tidak boleh memuat private key, password, credential, token, environment value, atau source bytes. Runtime configuration policy hanya berisi policy, bukan nilai secret. Private key TLS hanya boleh berada di capability/storage yang ditetapkan ADR-022 dan tidak boleh diserialisasi ke artifact upstream, admission, log, atau output pengguna.

Path, SID/account, host identity, tool inventory, dan digest internal diperlakukan sebagai data operasional sensitif: simpan sekecil mungkin, batasi retention, dan jangan tampilkan pada error/user-facing output. Log hanya boleh memuat role, opaque artifact ID/digest, generation, coarse timestamp, dan fixed status/error code. Log tidak boleh memuat source/manifest content, path lokal, username/SID, process/token identity, certificate/private-key material, native error detail, environment, atau credential.

Revocation snapshot juga menggunakan opaque identifiers; distribusinya offline dan tidak membocorkan inventory host atau identitas operator yang tidak diperlukan.

## Contract yang diterima dan gate yang tetap terbuka

ADR ini menetapkan sebagai keputusan normatif: detached Ed25519 dengan domain
separation; key berbeda per authority role; asset review 2-of-2 tanpa
release/source key; trust-root rotation 2-of-3 old dan 2-of-3 new; independent
revocation 2-of-3; tidak adanya private signing key pada candidate/verifier;
bootstrap fingerprint dua-operator/dua-channel; threat model ordinary-user;
protected high-water journal; generation sebagai freshness authority utama;
serta lifetime maksimum static artifact 7 hari, trust-root bundle 366 hari,
revocation snapshot 24 jam, preparation authorization 10 menit, dan final
composition admission 5 menit yang ditetapkan di atas. Pilihan tersebut bukan
lagi open decision.

Gate acceptance yang masih terbuka adalah bukti konkret, bukan kewenangan untuk mengganti baseline tersebut:

- exact Python/verifier/`cryptography` wheel version, filename, hash, RECORD, native-library, dan transitive closure yang didapat secara offline dan diautentikasi;
- exact trust bundle, public-key fingerprints, key generations, key-to-operator assignments, offline private-key custody, serta hasil ceremony bootstrap/rotation/revocation;
- exact reproducible vendor-build policy dan evidence, tool/runtime closure per host, runtime-configuration-policy issuer evidence, serta artifact yang benar-benar diterbitkan role-role tersebut;
- native Windows evidence untuk ACL journal, atomic/flush semantics, identity continuity, reboot/clock behavior, corruption/recovery, retention, dan rollback dalam threat model yang dinyatakan;
- proposed handle-relative ADR-016 extension, preparation-ACL capability, refactor builder, dan seluruh dependency TLS ADR-022;
- prosedur operator untuk fresh export, cutover, retirement, serta rollback candidate incomplete; dan
- semua native/provider/browser/service/environment/network/deployment/activation acceptance yang terpisah.

Enterprise Authenticode/catalog PKI, TPM, atau external witness hanya dapat mengganti atau memperluas bagian baseline melalui ADR baru yang diterima. Tidak ada implementation, installation, dependency acquisition, provisioning, production candidate build, browser/service launch, deployment, activation, atau checklist closure yang diotorisasi oleh status contract ini.

## Alternatif yang dipertimbangkan

### Mempercayai working tree dan `source_revision` dari caller

Ditolak. Bentuk 40-heksadesimal tidak membuktikan hubungan dengan exact file bytes atau reviewed commit.

### Memasukkan vendor dan asset review ke source artifact

Ditolak. Ini menciptakan authority yang terlalu luas dan dapat membentuk dependency sirkular/self-review. Vendor dan review aset harus menjadi artifact downstream yang independen.

### Mengandalkan hash tool top-level

Ditolak. Loader, DLL, provider, config, dan package root transitif dapat mengubah perilaku runtime tanpa mengubah executable top-level.

### Menggunakan satu builder monolitik dengan validasi di akhir

Ditolak. Material TLS dapat ditulis sebelum destination memperoleh dan membuktikan ACL persiapan, sementara final config belum tersedia untuk admission ADR-017.

### Mengambil revocation state dari network pada saat build

Ditolak sebagai authority. Selain menambah dependency outbound, remote/cache tidak membuktikan monotonic offline state dan membuka fallback ketika network gagal.

### Menggunakan package `cryptography` ambient atau command signer generik

Ditolak. Package/CLI ambient, PATH, loader state, dan transitive native library tidak memiliki exact acquisition evidence. Verifikasi hanya boleh memakai repository-owned verifier bersama exact pinned offline wheel/runtime closure yang diterima.

### Menggunakan satu signing key atau membolehkan release self-review aset

Ditolak. Key per role harus berbeda dan asset review harus 2-of-2 dari reviewer keys yang tidak mencakup release/source key.

### Menganggap software journal mencegah administrator atau offline rollback

Ditolak. Protected journal membatasi ordinary-user writer dan mendeteksi state invalid dalam threat model tersebut; ia bukan TPM atau external witness. Reboot/clock/journal uncertainty tetap fail closed sampai snapshot dengan generation yang strictly lebih tinggi dan exact current namespace/trust generation diterima; jika trustworthy high-water hilang, separately accepted external/TPM recovery authority diperlukan.

## Konsekuensi

Positif:

- dependency authority satu arah dan dapat diaudit;
- source, vendor, review aset, tool/runtime, config, TLS, dan admission tidak dapat saling menerbitkan secara sirkular;
- signature, role-key separation, threshold review/rotation/revocation, bootstrap, dan lifetime memiliki baseline deterministik;
- run root lahir atomically dengan owner-only protected security descriptor dan secret TLS baru ditulis setelah preparation-ACL evidence;
- final ADR-017 admission mengikat state kandidat yang benar-benar selesai; dan
- revocation/replay ditangani per role melalui independently authenticated protected monotonic snapshots.

Negatif:

- diperlukan beberapa issuer dan artifact terautentikasi;
- exact verifier/wheel closure, key custody, issuer assignments, dan ceremony evidence belum diakuisisi atau divalidasi;
- tool/runtime closure, protected monotonic journal, trusted-time handling, dan reboot behavior memerlukan native host evidence;
- ADR-016 memerlukan proposed handle-relative extension dan builder monolitik harus direfaktor sebelum dapat digunakan;
- preparation-ACL capability masih Proposed, sedangkan ADR-022 baru Accepted-for-contract; implementation dan runtime evidence keduanya tetap dependency terpisah; dan
- tidak ada kandidat runtime yang dapat disahkan hanya dengan primitive struktural saat ini.

## Penerimaan bertahap

Status Accepted-for-contract menutup pilihan desain baseline di ADR ini, tetapi tidak menutup blocker P17c/P18, checklist, runtime, atau gate deployment mana pun. Implementasi hanya dapat maju melalui review terpisah berikut:

1. implementasikan codec artifact/envelope/trust-bundle/revocation dan repository-owned verifier dengan pure/synthetic tests terhadap exact domain, threshold, expiry, dan fail-closed behavior;
2. akuisisi dan autentikasi exact supported Python serta offline `cryptography` wheel closure; catat version/filename/hash/RECORD/native/transitive inventory sebagai acquisition acceptance evidence;
3. provision distinct offline role keys dan custodians, lalu buktikan bootstrap dua-operator, asset-review 2-of-2, root rotation 2-of-3 old+new, serta independent revocation 2-of-3 tanpa menaruh private key pada candidate/verifier;
4. terima native protected high-water journal dan revocation/clock/reboot/corruption/rollback evidence dalam ordinary-user threat model;
5. terima secara independen artifact/evidence release-source, vendor build, asset review, tool/runtime closure, dan runtime configuration policy;
6. implementasikan dan validasi contract ADR-022, terima proposed preparation-ACL capability dan handle-relative ADR-016 extension, serta refactor builder ke atomic-secured-root/lease-first/skeleton/preparation-ACL/TLS/finalization/final-admission lifecycle;
7. buktikan ACL, tool/runtime closure, TLS custody, identity continuity, dan lifecycle pada native Windows host; dan
8. baru setelah seluruh gate lain tetap lulus, candidate runtime dapat dipertimbangkan untuk handoff. Installation, service/browser launch, activation, dan deployment tetap keputusan terpisah.

## Referensi

- [ADR-016: Candidate-global lifecycle lease](0016-checkout-candidate-lifecycle-lease.md) — implementasi saat ini belum menyediakan proposed handle-relative bootstrap extension.
- [ADR-017: Windows checkout ACL attestation](0017-checkout-windows-acl-attestation.md)
- [ADR-020: Structural ordinary-access evidence boundary](0020-ordinary-evidence-validation-authority.md)
- [ADR-022: Synthetic TLS material authority](0022-checkout-synthetic-tls-material-authority.md) — **Accepted-for-contract**; implementation, native evidence, dan authority runtime belum diterima.
- [RFC 8032: Edwards-Curve Digital Signature Algorithm (EdDSA)](https://www.rfc-editor.org/rfc/rfc8032.html)
- [NIST FIPS 186-5: Digital Signature Standard](https://csrc.nist.gov/pubs/fips/186-5/final)
- [`cryptography`: Ed25519 signing and verification](https://cryptography.io/en/latest/hazmat/primitives/asymmetric/ed25519/)
- [`cryptography`: Installation and Windows wheels](https://cryptography.io/en/46.0.1/installation/)
- [`cryptography`: Security policy](https://cryptography.io/en/46.0.4/security/)
- [Python Packaging: Recording installed projects (`RECORD`)](https://packaging.python.org/en/latest/specifications/recording-installed-packages/)
- [pip: Repeatable installs](https://pip.pypa.io/en/stable/topics/repeatable-installs/)
- [Microsoft Learn: Digital signatures](https://learn.microsoft.com/en-us/windows-hardware/drivers/install/digital-signatures)
- [Microsoft Learn: Catalog files](https://learn.microsoft.com/en-us/windows-hardware/drivers/install/catalog-files)
- [Microsoft Learn: `CryptProtectData`](https://learn.microsoft.com/en-us/windows/win32/api/dpapi/nf-dpapi-cryptprotectdata)
- [Microsoft Learn: `FlushFileBuffers`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-flushfilebuffers)
- [Microsoft Learn: `GetSystemTimePreciseAsFileTime`](https://learn.microsoft.com/en-us/windows/win32/api/sysinfoapi/nf-sysinfoapi-getsystemtimepreciseasfiletime)
- [Microsoft Learn: `QueryUnbiasedInterruptTimePrecise`](https://learn.microsoft.com/en-us/windows/win32/api/realtimeapiset/nf-realtimeapiset-queryunbiasedinterrupttimeprecise)
- [Microsoft Learn: About the TPM Base Services](https://learn.microsoft.com/en-us/windows/win32/tbs/about-tbs)
- [Microsoft Learn: `CreateDirectoryW`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createdirectoryw) — `lpSecurityAttributes` menetapkan security descriptor saat directory dibuat.
- [Microsoft Learn: `SECURITY_ATTRIBUTES`](https://learn.microsoft.com/en-us/previous-versions/windows/desktop/legacy/aa379560(v=vs.85))
