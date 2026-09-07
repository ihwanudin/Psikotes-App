# ADR-021: Authority persiapan release kandidat checkout

- Status: Proposed
- Tanggal: 2026-09-07

## Konteks

Jalur kandidat checkout memerlukan sumber yang telah direview, dependency Composer, aset checkout, tool/runtime host, konfigurasi runtime, material TLS, dan admission ACL yang semuanya terikat ke objek kandidat yang sama. Kontrak saat ini belum menyediakan authority eksternal untuk membuktikan rangkaian tersebut:

- `source_revision` hanya divalidasi sebagai 40 karakter heksadesimal dan belum mengikat manifest ke commit/tree Git yang telah direview;
- `vendor/` diabaikan Git, sehingga checkout sumber saja tidak membuktikan isi dependency yang dipakai;
- baseline belum memiliki tag atau tanda tangan release yang dapat dijadikan trust root;
- `asset_delivery_review` dapat diterbitkan oleh caller yang sama dan baru dibandingkan dengan manifest, sehingga belum membuktikan review independen;
- inventory tool yang ada belum membuktikan closure dependency runtime transitif pada host; dan
- `build_candidate()` saat ini bersifat monolitik: sumber, vendor, konfigurasi, sertifikat/kunci, dan manifest final diproses dalam satu alur. Bentuk ini tidak dapat memenuhi urutan ACL-persiapan lalu TLS lalu admission final.

Karena itu candidate builder yang ada belum merupakan jalur persiapan yang disahkan. ADR ini hanya menetapkan batas authority dan dependency graph. ADR ini tidak memilih teknologi signer, trust root, provisioning, atau mekanisme runtime.

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

Sebelum final composition admission tersedia, composition-admission authority boleh menerbitkan **preparation authorization** dengan domain/schema terpisah yang hanya mengizinkan pembuatan satu skeleton fresh dan penerapan ACL persiapan. Artifact sempit ini mengikat artifact statis pra-TLS dan constraint destination/generation, tetapi tidak menyatakan komposisi final dan tidak berpura-pura mengikat TLS atau evidence yang belum ada. Preparation authorization tidak dapat dipakai sebagai final composition admission atau ADR-017 admission.

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

Setiap role menerbitkan artifact kanonis yang terpisah dan terautentikasi. Setiap schema harus versioned dan domain-separated, memiliki exact key/type, bounded canonical encoding, identifier role/issuer, artifact digest, generation, issued/expiry time, dan replay identifier. Duplicate key, non-finite number, field tambahan, encoding nonkanonis, atau autentikasi yang tidak tepat harus ditolak.

Teknologi tanda tangan, key format, trust-root distribution, serta matrix pemisahan role/co-sign belum dipilih dan memerlukan keputusan pengguna. Invariant minimum yang tidak dapat dinegosiasikan adalah release/source authority tidak boleh menjadi satu-satunya reviewer/penanda tangan asset-review artifact. Apakah role lain harus terpisah atau boleh co-sign juga tetap keputusan terbuka.

#### Release/source artifact

Artifact ini adalah root authority untuk kode sumber dan hanya memuat `sourceFiles`; `vendor/` dikecualikan. Ia mengikat exact reviewed commit/tree/source revision dan path serta digest setiap source file, termasuk exact digest `composer.lock`. Ia tidak boleh memuat digest asset review, vendor artifact, tool/runtime descriptor, runtime policy, TLS, preparation ACL, atau composition admission.

Fresh export harus dibuat dari exact source artifact tanpa mengambil file dari working tree yang kotor. Dirty tree, untracked file yang masuk inventory, submodule/LFS state yang tidak dibuktikan, path collision, symlink/reparse yang tidak diizinkan, atau perbedaan byte harus menolak persiapan.

#### Vendor artifact

Artifact vendor mengikat exact release/source artifact digest dan exact `composer.lock` digest yang tercatat di source artifact. Ia memuat inventory package dan file `vendor/`, digest byte, metadata runtime yang diperlukan, serta provenance build yang diautentikasi vendor-build authority. Artifact ini tidak boleh mengikat artifact downstream.

Source dan vendor dari release berbeda tidak boleh digabung. Reproducible-build policy dan issuer vendor-build masih merupakan keputusan terbuka.

#### Asset-review artifact

Artifact review aset diterbitkan secara independen dan mengikat exact release/source artifact digest serta exact path dan digest enam file berikut:

1. `public/css/checkout-summary-v1.css`;
2. `public/brand/oncam-logo-full-color.png`;
3. `public/js/checkout-confirmation-v1.js`;
4. `public/js/checkout-payment-v1.js`;
5. `resources/views/checkout/summary.blade.php`; dan
6. `tools/testing/tests/Browser/serve-checkout-session.php`.

Review ini tidak mengubah source manifest dan tidak menjadi input bagi release/source artifact. Source/release issuer tidak boleh melakukan self-review aset sebagai satu-satunya reviewer. Identitas reviewer, authority CI, dan kebutuhan co-sign adalah keputusan pengguna.

#### Tool/runtime closure descriptor

Descriptor ini diautentikasi secara independen dan host-specific. Ia mengikat exact canonical path, object identity, digest, dan version untuk PHP, Python, Node.js, PowerShell, Playwright CLI, serta browser yang disahkan.

Descriptor juga harus menutup seluruh dependency runtime transitif yang dapat memengaruhi eksekusi: DLL/shared library, provider module, configuration file, package root, loader/search policy, dan resource runtime lain yang diperlukan. Jika closure transitif tidak dapat dihitung dan dibuktikan secara bounded, host harus ditolak; daftar executable top-level saja tidak cukup.

#### Runtime-configuration-policy artifact

Artifact ini diautentikasi secara independen dan mengikat exact policy untuk byte/semantik `runtime.ini`, template dan schema browser config, launch arguments, port/origin, offline/network restrictions, budget, dan nama output. Artifact tidak boleh berisi secret atau memberi caller kewenangan memilih path/tool/policy alternatif.

#### TLS authority

Pembuatan, penyimpanan, binding, dan validasi material TLS didelegasikan ke ADR-022. ADR-022 masih **Proposed** dan menjadi dependency eksplisit ADR ini. Langkah TLS tidak boleh dianggap tersedia atau diterima sebelum keputusan dan kontrak ADR-022 disetujui.

Artifact dan final serialized config hanya mengikat digest public certificate
evidence yang ditetapkan ADR-022. Path, hash, identity, size, atau metadata lain
dari private key tidak boleh masuk artifact, manifest, config, admission,
evidence publik, log, maupun output. Custody private key tetap berada pada exact
opaque, nonserializable `TlsMaterialCapability`; composition hanya mengikat
object identity capability tersebut secara privat dan tidak mengubahnya menjadi
identifier yang dapat dipakai untuk mengambil key.

#### Composition admission

Hanya composition admission yang melakukan cross-binding exact digest release/source, vendor, asset review, tool/runtime closure, runtime configuration policy, preparation ACL evidence, public TLS certificate evidence, canonical destination/run identity, generation, expiry/revocation state, dan lease ADR-016. Binding privat yang hidup bersama admission juga memegang exact object identity `TlsMaterialCapability`; capability dan metadata private key tidak diserialisasi. Artifact upstream tidak boleh menunjuk kembali ke composition admission.

Final composition admission baru dapat diterbitkan setelah TLS, preparation ACL evidence, source, config, dan manifest final tersedia. Ia bersifat one-shot dan hanya berlaku untuk exact set artifact, fresh destination, dan generation yang tercantum. Perubahan byte, path, identity, policy, atau artifact setelah admission menghabiskan/refuse admission; admission tidak boleh digunakan ulang untuk retry atau run lain.

### 3. Revocation, expiry, dan replay

Setiap role authority memiliki offline monotonic revocation snapshot yang ditandatangani atau diautentikasi oleh independent revocation trust role/threshold yang tidak dapat digantikan issuer artifact tersebut. Snapshot memiliki namespace exact yang mencakup authority role, issuer identity, issuer-key generation, dan revocation-trust generation. Ia mengikat schema, snapshot digest, monotonic generation, `issuedAt`, `nextUpdate`, cutoff/not-before yang berlaku, serta daftar artifact ID/digest atau key generation yang dicabut. Consumer harus menyimpan highest accepted generation pada protected monotonic state dan menolak rollback, snapshot hilang/stale/future/conflicting, artifact expired, replay identifier yang sudah dipakai, dan artifact yang dicabut.

Exact snapshot digest, generation, `issuedAt`, dan `nextUpdate` per namespace wajib terikat ke preparation authorization dan final composition admission. Preparation memakai satu set snapshot yang byte, path/object identity, trust result, dan protected monotonic state-nya dipin. Set yang sama wajib direvalidasi sebelum preparation authorization dikonsumsi dan sekali lagi sebelum final composition admission/final boundary; perubahan, replacement, expiry, atau perbedaan set menolak run. Cache atau referensi remote lokal bukan revocation authority. Teknologi threshold, penyimpanan monotonic, clock authority, retention, dan recovery snapshot masih keputusan terbuka.

### 4. Lifecycle persiapan bertahap

Lifecycle yang disahkan harus mengikuti urutan berikut tanpa pertukaran langkah:

1. autentikasi seluruh artifact statis per-role secara read-only, tanpa membuat destination atau mengonsumsi authority;
2. revalidasi independent revocation snapshots terhadap exact protected monotonic state dan pin exact snapshot set yang akan dipakai sepanjang run;
3. validasi lalu consume preparation authorization one-shot yang hanya mengizinkan satu fresh run root dengan exact parent, security descriptor, artifact set, dan revocation snapshot set;
4. melalui already validated and pinned parent authority, atomically create fresh run root **bersama** exact owner-only protected security descriptor pada operasi create yang sama; create-directory lalu `SetACL` dilarang karena meninggalkan interval permissive. Segera tahan handle root dan pin canonical path, final path, no-reparse state, volume/file identity, owner, serta DACL sebelum child dibuat;
5. sebagai child dan write pertama di dalam held run root, create lalu acquire candidate-global lifecycle lease ADR-016 secara handle-relative dan fail-closed;
6. baru setelah lease dipegang, buat marker incomplete dan skeleton minimum di bawah held root tanpa source, vendor, runtime config, browser config, atau TLS material final;
7. jalankan proposed preparation-ACL attestation untuk exact parent/root/skeleton/output identities dan turunkan preparation evidence;
8. setelah preparation ACL berhasil, jalankan proposed TLS authority ADR-022 dan ikat hanya public certificate evidence digest serta exact private `TlsMaterialCapability` object identity;
9. copy dan finalisasi source, vendor, runtime/browser configuration, manifest, tool/runtime binding, dan public certificate evidence; bentuk exact final supervisor config dan `configBinding` tanpa path/hash/identity/size/metadata private key sambil marker incomplete tetap ada;
10. terbitkan dan verifikasi final composition admission yang mengikat seluruh exact upstream digest, pinned revocation snapshot set, preparation evidence, public TLS evidence digest, private capability object identity, run identity, lease, dan final `configBinding`;
11. lakukan admission ADR-017 yang baru dan penuh terhadap final `configBinding`, target, serta source identity pada boundary anchor dan execution tepat sebelum eksekusi; preparation authorization dan preparation-ACL evidence tidak boleh digunakan kembali sebagai admission final; dan
12. hanya setelah seluruh validasi final berhasil, publikasikan state final/handoff sesuai prosedur yang diterima. Kegagalan mempertahankan state incomplete, menghabiskan authority one-shot, melepaskan resource secara fail-closed, dan tidak melakukan retry otomatis.

Preparation-ACL attestation adalah capability **Proposed** yang terpisah. Ia boleh memakai ulang exact policy semantics ADR-017 untuk owner, protected DACL, no-reparse, identity continuity, dan ordinary-principal denial, tetapi ia bukan accepted ADR-017 boundary, tidak memakai final `configBinding`, dan tidak dapat menghasilkan atau menggantikan final ADR-017 admission.

ADR-016 saat ini membuat/membuka lease melalui path setelah run directory sudah ada. Langkah 5 memerlukan extension ADR-016 yang masih Proposed: lease creation/acquisition handle-relative di bawah held root, dengan root/lease handle dan identity dipin tanpa path reopen. Current ADR-016 implementation dan `build_candidate()` monolitik harus direfaktor agar dapat menjalankan lifecycle tersebut. Sampai extension, refactor, native review, dan acceptance terpisah selesai, keduanya tetap tidak dapat digunakan sebagai jalur persiapan yang disahkan.

### 5. Failure policy

Persiapan menolak secara tetap dan tanpa fallback ketika salah satu kondisi berikut terjadi:

- artifact/authentication/schema/issuer tidak tepat, hilang, expired, revoked, replayed, atau generation mundur;
- dependency graph atau cross-binding tidak cocok;
- source dan vendor berasal dari release yang berbeda;
- source/release issuer mencoba menjadi satu-satunya asset reviewer;
- closure tool/runtime transitif tidak lengkap;
- revocation snapshot tidak berasal dari independent trust role/threshold,
  namespace tidak cocok, generation rollback, atau protected snapshot pre/post
  berbeda;
- destination tidak fresh/canonical atau identity berubah;
- run root dibuat sebelum authority dikonsumsi, ACL dipasang setelah create,
  atau lease bukan child/write pertama di bawah held root;
- lifecycle dijalankan di luar urutan;
- preparation ACL evidence diperlakukan sebagai final ADR-017 admission;
- metadata/path/hash private key masuk artifact/config/evidence atau private
  capability diganti/diserialisasi;
- config/source/manifest/TLS berubah setelah binding final; atau
- ADR-022 atau authority lain yang masih Proposed dianggap aktif tanpa keputusan penerimaan.

Tidak ada fallback ke argv, environment, working tree, network fetch, cached remote reference, atau self-issued review.

### 6. Privacy dan secret handling

Artifact authority tidak boleh memuat private key, password, credential, token, environment value, atau source bytes. Runtime configuration policy hanya berisi policy, bukan nilai secret. Private key TLS hanya boleh berada di capability/storage yang ditetapkan ADR-022 dan tidak boleh diserialisasi ke artifact upstream, admission, log, atau output pengguna.

Path, SID/account, host identity, tool inventory, dan digest internal diperlakukan sebagai data operasional sensitif: simpan sekecil mungkin, batasi retention, dan jangan tampilkan pada error/user-facing output. Log hanya boleh memuat role, opaque artifact ID/digest, generation, coarse timestamp, dan fixed status/error code. Log tidak boleh memuat source/manifest content, path lokal, username/SID, process/token identity, certificate/private-key material, native error detail, environment, atau credential.

Revocation snapshot juga menggunakan opaque identifiers; distribusinya offline dan tidak membocorkan inventory host atau identitas operator yang tidak diperlukan.

## Keputusan terbuka yang memerlukan pengguna

- teknologi signer dan trust root untuk setiap role;
- matrix pemisahan role, reviewer/CI authority, dan persyaratan co-sign;
- vendor-build issuer serta standar reproducibility;
- tool/runtime-closure issuer dan definisi closure transitif per host;
- runtime-configuration-policy issuer;
- independent revocation trust role/threshold per namespace serta storage monotonic, clock authority, retention, dan recovery snapshot;
- seluruh keputusan TLS yang didelegasikan ke ADR-022, termasuk issuer, browser trust, custody private key, dan runtime budget; dan
- prosedur operator untuk fresh export, cutover, retirement, serta rollback candidate incomplete.

Tidak ada implementasi production, provisioning, candidate build, browser/service launch, deployment, activation, atau checklist closure yang diotorisasi sebelum keputusan tersebut diterima.

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

## Konsekuensi

Positif:

- dependency authority satu arah dan dapat diaudit;
- source, vendor, review aset, tool/runtime, config, TLS, dan admission tidak dapat saling menerbitkan secara sirkular;
- run root lahir atomically dengan owner-only protected security descriptor dan secret TLS baru ditulis setelah preparation-ACL evidence;
- final ADR-017 admission mengikat state kandidat yang benar-benar selesai; dan
- revocation/replay ditangani per role melalui independently authenticated protected monotonic snapshots.

Negatif:

- diperlukan beberapa issuer dan artifact terautentikasi;
- tool/runtime closure serta monotonic revocation memerlukan desain host-specific;
- ADR-016 memerlukan proposed handle-relative extension dan builder monolitik harus direfaktor sebelum dapat digunakan;
- keputusan signer/trust-root/role separation/vendor/TLS masih memblokir implementasi; dan
- tidak ada kandidat runtime yang dapat disahkan hanya dengan primitive struktural saat ini.

## Penerimaan bertahap

ADR ini belum menutup blocker mana pun. Implementasi baru hanya dapat maju melalui review terpisah berikut:

1. pengguna memilih signer/trust roots dan role/co-sign matrix;
2. codec kanonis dan verifier autentikasi per-role diuji secara pure/synthetic;
3. issuer source, vendor, asset review, tool/runtime closure, dan runtime config policy diterima secara independen;
4. independent revocation trust/threshold dan offline protected monotonic revocation/replay store diterima;
5. ADR-022 diterima dan implementasi TLS authority divalidasi;
6. ADR-016 diperluas secara handle-relative dan builder direfaktor ke atomic-secured-root/lease-first/skeleton/preparation-ACL/TLS/finalization/final-admission lifecycle;
7. native host evidence membuktikan ACL, tool/runtime closure, custody TLS, dan lifecycle pada Windows; dan
8. baru setelah seluruh gate lain tetap lulus, candidate runtime dapat dipertimbangkan untuk handoff. Aktivasi/deployment tetap keputusan terpisah.

## Referensi

- [ADR-016: Candidate-global lifecycle lease](0016-checkout-candidate-lifecycle-lease.md) — implementasi saat ini belum menyediakan proposed handle-relative bootstrap extension.
- [ADR-017: Windows checkout ACL attestation](0017-checkout-windows-acl-attestation.md)
- [ADR-020: Structural ordinary-access evidence boundary](0020-ordinary-evidence-validation-authority.md)
- [ADR-022: Synthetic TLS material authority](0022-checkout-synthetic-tls-material-authority.md) — **Proposed dependency**; belum diterima dan tidak memberi authority runtime.
- [Microsoft Learn: `CreateDirectoryW`](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createdirectoryw) — `lpSecurityAttributes` menetapkan security descriptor saat directory dibuat.
- [Microsoft Learn: `SECURITY_ATTRIBUTES`](https://learn.microsoft.com/en-us/previous-versions/windows/desktop/legacy/aa379560(v=vs.85))
