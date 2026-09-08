# F1/P17c — proposal contract namespace trust/revocation `tls-material`

Tanggal: 2026-09-08. Input gap: commit
`b0bc7746d5168a876fcb292e436797c5d0887344`. Status: **proposal untuk review
koordinator; bukan contract freeze, implementation, trust ceremony, atau runtime
authority**.

Proposal ini hanya merinci perubahan contract yang hilang antara codec
`tls-material` ADR-025 dan trust-root/revocation ADR-021. Codec yang ada tetap
`structuralOnly`; dokumen ini tidak mengizinkan signing, verification runtime,
browser/native execution, provisioning, network, atau activation.

## 1. Prinsip versi dan kompatibilitas

Menambahkan `tls-material` ke allowlist v1 akan mengubah makna byte/schema lama
tanpa version bump. Karena itu proposal ini memakai:

- full `TrustRootBundleV2`, bukan sidecar atau edit semantik v1;
- `TlsMaterialRevocationSnapshotV2` khusus namespace `tls-material`;
- envelope TLS yang sudah ada tetap payload/envelope version `2`, role exact
  `tls-material`, dan signing domain ADR-025 exact
  `oncam.checkout.tls-material.v1\0`; dan
- decoder v1 trust bundle, revocation snapshot, serta artifact envelope tetap
  menolak `tls-material`.

Tidak boleh ada promotion hasil decode v1 menjadi v2, merge field v1+v2,
fallback ke v1, atau pemilihan versi oleh caller. Satu verification attempt
memakai satu exact bundle v2, satu exact snapshot v2, dan satu exact TLS
payload/envelope set.

## 2. Encoding dan primitive bersama

Semua object di bawah memakai JSON object exact, key tertutup, ASCII,
`sort_keys=true`, separator `,`/`:`, tanpa whitespace, lalu tepat satu LF.
Duplicate key, byte non-ASCII/BOM, trailing byte/LF kedua, non-finite number,
subclass scalar, bool-as-int, missing/extra field, atau noncanonical encoding
ditolak.

- identifier: lowercase ASCII, regex `[a-z0-9](?:[a-z0-9._-]{0,63})`;
- digest/public key: tepat 64 lowercase hexadecimal;
- detached Ed25519 signature: tepat 128 lowercase hexadecimal;
- generation: exact integer `1 <= n < 2^63`;
- timestamp: exact UTC seconds `YYYY-MM-DDTHH:MM:SSZ`;
- bundle maksimum 64 KiB; snapshot maksimum 256 KiB; signature set maksimum
  12 KiB; dan
- total seluruh tiga revocation list maksimum 4096 entry, masing-masing sorted
  ascending dan unique.

Digest byte biasa berarti `SHA-256(exactCanonicalBytes)`. Digest domain berarti
`SHA-256(domain || exactCanonicalBytes)`. Kedua jenis tidak boleh dipertukarkan.

## 3. `TrustRootBundleV2`

Domain digest bundle exact:
`oncam.checkout.trust-root-bundle.v2\0`.

Top-level fields exact:

```text
version,role,issuerId,artifactId,trustGeneration,
revocationTrustGeneration,issuedAt,expiresAt,replayId,
artifactIssuerKeys,offlineRootCustodians,revocationCustodians,rotation
```

Nilai fixed: `version=2`, `role=trust-root-bundle`. Lifetime positif, maksimum
366 hari, dan hanya current bila interval trusted-time ADR-024 seluruhnya berada
dalam `[issuedAt, expiresAt)`.

`artifactIssuerKeys` adalah ordered list tepat sembilan record. Setiap record
memiliki fields exact:

```text
role,issuerId,keyId,keyGeneration,publicKey
```

Sequence role exact:

```text
asset-review
asset-review
composition-admission
preparation-authorization
release-source
runtime-configuration-policy
tls-material
tool-runtime-closure
vendor-build
```

Dua asset reviewer tetap ordered by `issuerId`; `tls-material` tepat satu key.
TLS materializer, TLS evidence signer (`issuerId` record ini), composition
issuer, runtime-acquisition issuer, dan cleanup custodian tetap distinct
authority/object identity. Hanya key record role `tls-material` boleh
memverifikasi envelope TLS; key role lain tidak dapat dipromosikan.

`offlineRootCustodians` dan `revocationCustodians` masing-masing tepat tiga
ordered record dengan fields exact:

```text
custodianId,keyId,keyGeneration,publicKey
```

Seluruh `keyId`, `publicKey`, dan principal identifier pada ketiga collection
wajib globally unique. Revocation custodians wajib distinct dari seluruh
artifact issuer, termasuk TLS evidence signer. `revocationTrustGeneration`
adalah positive int63 dan mengikat exact revocation-custodian set; pada proposal
v2 ini setiap perubahan bundle/key set menaikkan `trustGeneration` dan
`revocationTrustGeneration` secara strictly monotonic. Nilainya tidak harus
sama, tetapi tidak boleh mundur.

`rotation` tetap `null` hanya untuk bootstrap v2 yang disahkan ceremony. Untuk
non-bootstrap, fields exact:

```text
priorBundleDigest,priorTrustGeneration,newTrustGeneration,
priorRevocationTrustGeneration,newRevocationTrustGeneration,
oldRootSignatureEnvelopes,newRootSignatureEnvelopes
```

`priorBundleDigest` menunjuk exact canonical prior bundle bytes. Kedua generation
new harus sama dengan top-level dan strictly greater dari prior masing-masing.
Setiap signature-reference list tepat dua entry ordered/unique dengan fields
exact `keyId,envelopeDigest`; seluruh empat envelope digest distinct. Rotation
diterima hanya setelah actual detached Ed25519 verification mencapai 2-of-3 old
roots **dan** 2-of-3 new roots atas exact bundle v2 bytes dengan domain
`oncam.checkout.trust-root-bundle.v2\0`. Structural references tidak membuktikan
threshold.

## 4. `TlsMaterialRevocationSnapshotV2`

Snapshot fields exact:

```text
version,role,artifactId,replayId,authorityRole,issuerId,
issuerKeyGeneration,trustBundleDigest,trustGeneration,
revocationTrustGeneration,generation,issuedAt,nextUpdate,notBefore,
revokedArtifactIds,revokedArtifactDigests,revokedKeyGenerations
```

Nilai fixed: `version=2`, `role=revocation-snapshot`, dan
`authorityRole=tls-material`. `issuerId` serta `issuerKeyGeneration` harus
exact-equal record TLS signer dalam bundle v2. `trustBundleDigest` adalah digest
domain bundle v2, bukan raw-byte digest; kedua trust generation harus exact-equal
bundle.

Snapshot raw-byte digest adalah `SHA-256(snapshotCanonicalBytes)`. Domain signed
message exact:

```text
oncam.checkout.revocation-snapshot.tls-material.v2\0
|| snapshotCanonicalBytes
```

`notBefore <= issuedAt < nextUpdate`; `nextUpdate-issuedAt` positif dan maksimum
24 jam. Candidate trusted-time interval ADR-024 harus seluruhnya tercakup:
`issuedAt <= intervalLower < intervalUpper <= nextUpdate`. Local wall clock
hanya boleh memperketat refusal.

`TlsMaterialRevocationSignatureSetV2` memiliki fields exact:

```text
version,role,authorityRole,snapshotDigest,
revocationTrustGeneration,signatures
```

Nilai fixed: `version=2`, `role=revocation-snapshot`,
`authorityRole=tls-material`. `signatures` tepat dua ordered unique record:

```text
algorithm,custodianId,keyId,keyGeneration,signature
```

`algorithm=ed25519`; kedua signer harus merupakan dua custodian berbeda dari
exact three-entry `revocationCustodians` bundle v2, dengan key/generation exact.
Signature set hanya wrapper; setiap signature memverifikasi signed message yang
sama di atas. Snapshot atau signature set dari authority role lain ditolak.

## 5. Verification authority dan cross-binding

Current `checkout-tls-material-verification-request` tetap request structural;
`trustedTimeChallengeDigest` sendiri bukan freshness proof. Authority yang kelak
diterima harus menerima exact request bytes bersama exact authenticated bundle
v2 bytes/root proof, snapshot v2 bytes/signature set, committed one-shot
`time.validate` capability ADR-024, protected high-water/replay journal
capability, dan private `TlsMaterialCapability` yang sudah dipin composition.
Caller tidak boleh memasok boolean hasil atau selector key/namespace/path.

Urutan fail-closed exact:

1. canonical-decode evidence, package, payload v2, envelope v2, dan request;
2. authenticate/current-check bundle v2 melalui bootstrap/rotation roots;
3. pilih hanya exact `tls-material` key by envelope
   `(issuerId,keyId,keyGeneration,trustGeneration)`;
4. verifikasi actual Ed25519 atas
   `oncam.checkout.tls-material.v1\0 || payloadCanonicalBytes`;
5. authenticate snapshot v2 2-of-3 dan exact namespace terhadap key pada langkah
   3 serta exact bundle/trust generations;
6. validate committed trusted-time capability: source challenge digest harus
   exact-equal request, input/run/session/lease/namespace tidak drift, dan
   interval mencakup bundle, artifact, serta snapshot validity requirements;
7. bandingkan snapshot generation dengan protected high-water untuk tuple exact
   `(tls-material,issuerId,issuerKeyGeneration,revocationTrustGeneration)`;
8. tolak bila TLS `artifactId`, raw payload digest, TLS evidence/artifact digest,
   atau issuer key generation terdapat pada revocation lists;
9. tolak replay/drift request ID, artifact replay ID, snapshot replay ID,
   envelope/payload/evidence/package digest, run, lease, generation, trust, atau
   trusted-time binding;
10. ikat certificate/SPKI melalui rantai exact: signed payload -> exact
    `tlsArtifactDigest`/`tlsEvidenceDigest` -> evidence -> satu `spkiSha256` ->
    browser config exact one-SPKI argument, disposable profile, dan
    `ignoreHTTPSErrors=false`; dan
11. setelah seluruh check lulus, lakukan satu compare-and-append ADR-024 yang
    atomically memajukan high-water dan mengonsumsi request/artifact/snapshot
    replay IDs. Crash/ambiguous commit menolak; tidak ada retry/adopt.

Saat sebuah verification attempt pertama kali mengadopsi snapshot, generation
wajib strictly greater dari protected high-water namespace. Exact same bytes,
generation, digest, dan object identity boleh dibaca ulang hanya sebagai
revalidation atas snapshot yang sudah dipin dalam attempt/run yang sama; itu
tidak memajukan high-water, tidak menjadi freshness baru, dan tidak dapat
dipakai setelah terminal/replay commit. Same-generation untuk attempt baru atau
bytes/digest berbeda selalu ditolak. Setelah reboot/journal uncertainty,
recovery mengikuti ADR-021/024: strict-higher signed snapshot atau separately
accepted TPM/external recovery authority; software/local-clock fallback
dilarang.

Successful verification tidak menghasilkan serialized
`verified/authenticated/trusted/current/policySatisfied` flags. Hasilnya harus
opaque, immutable, single-use `VerifiedTlsMaterialCapability` yang secara privat
mengikat exact request, bundle, snapshot, trusted-time commit, high-water/replay
commit, payload/envelope/evidence/package/browser-config digests, run/lease/
generation, dan exact private `TlsMaterialCapability` object identity.

Trust bundle dan snapshot **tidak** memuat SPKI atau private capability. SPKI
di-cross-bind melalui signed public artifact chain di langkah 10. Private key,
key path/hash/identity/size, passphrase, handle, capability ID/lookup key, atau
derived serializable capability digest dilarang; exact object identity hanya
dibandingkan dalam sealed composition dan dibuang pada terminal cleanup.

## 6. Refusal minimum

Refuse dengan fixed redacted code bila ada schema/domain/version/role mismatch;
unknown/duplicate key; threshold kurang; signature salah; trust or revocation
generation drift; bundle/snapshot/time stale, future, expired, replayed, revoked,
or ambiguous; high-water missing/rollback/equal; digest/run/lease/SPKI/config
drift; private capability replacement; extra SPKI/broad TLS bypass/profile reuse;
secret/path/private-key field; mutation pre/post; dependency replacement; atau
journal commit uncertainty. Tidak ada fallback ke ambient trust, cached snapshot,
network, local clock, v1, alternate role, or caller assertion.

`KeyboardInterrupt`/`SystemExit` tetap primary identity; cleanup mencoba discard
seluruh one-shot state tanpa mengubah uncertain commit menjadi reusable state.

## 7. Migration/cutover

1. V1 decoder/tests tetap byte-for-byte dan terus menolak `tls-material`.
2. V2 bundle diterbitkan sebagai full replacement dengan generation di atas
   accepted v1 high-water dan 2-of-3 old+new root rotation proof; tidak ada
   synthetic conversion dari decoded v1.
3. TLS verification diaktifkan hanya ketika exact v2 bundle, v2 snapshot,
   detached threshold signatures, trusted-time, protected journal, dan private
   capability path telah diterima bersama.
4. Legacy roles dapat tetap diverifikasi dengan v1 selama cutover, tetapi satu
   verification attempt tidak boleh mencampur bundle/snapshot versions atau
   trust generations. Setelah v2 menjadi active trust head, downgrade ke v1
   ditolak oleh protected high-water.
5. Removal/rotation TLS signer atau revocation custodian memerlukan v2 bundle
   rotation dan snapshot namespace generation baru; cached bytes tidak berlaku.

## 8. Pure test matrix yang diusulkan

- known canonical vectors untuk bundle v2, snapshot v2, signature set, domains,
  raw/domain digests, dan immutable structural outputs;
- closed-schema, exact scalar types, identifier/digest/signature bounds,
  duplicate/noncanonical/oversize/ordering rejection;
- exact nine-key sequence, one TLS signer, global key/principal separation,
  three revocation custodians, 2-of-3 references/signatures;
- v1 tetap menolak TLS; v1->v2 promotion, cross-version mix, role/domain/key
  confusion, downgrade, dan unknown key ditolak;
- trust rotation old+new threshold, strictly increasing generations, prior
  bundle binding, and mutation/drift negatives;
- snapshot namespace, bundle/key/generation equality, 24-hour bound, trusted
  interval coverage, sorted/unique/joint list bound, and every revocation mode;
- actual Ed25519 known-good/known-bad vectors only after pinned verifier runtime
  exists; structural fake signature must never produce accepted output;
- high-water same/lower/strict-higher, replay IDs, atomic commit ambiguity,
  reboot/lost-state refusal, and cross-run/lease/session confusion;
- signed payload-to-evidence-to-one-SPKI/browser-config chain; wrong/extra SPKI,
  broad bypass, profile reuse, and capability substitution rejection;
- forbidden secret/path/key/handle/capability-identifier fields, fixed redaction,
  callable/dependency rebinding, BaseException precedence, and cleanup ordering.

Pure tests cannot prove custodian identity, key custody, signature runtime
closure, current time, durable high-water, TPM/witness, native handles, TLS,
browser, or cleanup efficacy.

## 9. Exact decisions/evidence still unanswered

The schema above is a proposal; coordinator/ADR acceptance still must decide or
provide:

1. exact initial v2 `artifactId`, `issuerId`, `trustGeneration`,
   `revocationTrustGeneration`, issuance window, and whether deployment is a
   rotated v1->v2 head or a separately bootstrapped v2 ceremony;
2. exact TLS signer and three revocation/root custodian IDs, public keys, key
   generations, operator assignments, custody evidence, and proof all required
   identities are distinct;
3. exact root rotation envelope codec/domain and the accepted journal record
   schema that atomically combines TLS snapshot high-water plus three replay
   consumptions;
4. whether legacy-role verification may coexist temporarily after active v2
   head, and the exact retirement generation/date—never caller-selected;
5. accepted isolated Ed25519 verifier runtime/acquisition evidence, including the
   still-missing CPython archive digest and native loader closure;
6. accepted ADR-024 journal/time service, rollback witness, native durability,
   crash/reboot recovery, and retention policy;
7. concrete published bundle/snapshot/envelopes and independent adversarial
   review without P1/P2; and
8. final API/capability codec for verifier output and private composition
   binding. A serializable private-capability identifier remains forbidden.

Until those decisions and evidence are accepted, this proposal does not change
the current result: trust/revocation inputs remain opaque structural bindings,
the candidate/browser must not launch, and P17c/P18 remain open/default OFF.
