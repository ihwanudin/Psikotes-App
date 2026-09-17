# P14c — kontrak komposisi summary privat setelah reader diterima

Status: **proposal untuk review, dokumentasi saja**, 2026-09-04. Baseline worker
`216634e`. Tidak ada DTO/composer/reader/writer baru pada increment ini.

Keputusan yang direkomendasikan: summary lengkap harus berasal dari satu operasi
lifecycle ber-credential dan satu transaksi service, bukan gabungan hasil
`readProfile()` dan `readPayment()` yang sudah commit terpisah. Sebelum implementasi
komposisi lengkap, selesaikan gap sinkronisasi identity writer dan kontrak satu
waktu/dokumen evaluasi. Jangan mengklaim semua prasyarat konsisten hanya karena
query ditempatkan dalam satu transaksi.

## Sumber dan status acceptance

Dibaca read-only dari root: parallel-work, bagian integrated-checkout plan,
P14–P17 todo, ADR-012 beserta amendment cookie/CSRF/logout, serta
`resources/js/types/integrated-checkout.ts` yang masih **DRAFT**. Laporan reviewed
`backend-p14c-summary-contract.md` identik antara root dan worker, SHA256
`30481dab375b080d493022d90828eea043c8f387969ddbb3b4a1b80fedcce65c`.
Proposal ini memperjelas komposisi minimum, bukan mengganti dokumen kanonik.

Reader yang sudah diterima:

- `CheckoutProfileMapper` dan `CheckoutProfile`: tujuh fakta profil, NULL missing,
  nilai nonnull invalid ditolak; tidak ada input form/P15.
- `CheckoutPaymentFactsReader` dan `CheckoutPaymentFacts`: nominal own frozen
  charge, provenance eksplisit, status pembayaran tanpa purchasing authority.
- `AssessmentSettlementReader`: paid/free evidence canonical, bukan consent atau
  access; gate dan activation memakainya juga.
- `AcceptedConsentReader`: satu boolean accepted untuk participant/type dan
  dokumen configured kini; bukan reader declined/withdrawn/reason.
- `AssessmentEntitlementGate::assertReady`: gate read-only per test type,
  menggabungkan attempt/charge/entitlement/settlement/prerequisites. Tidak membuka
  mesin sesi; controller start tetap `501 SESSION_ENGINE_PENDING`.

Payment worker `53fc8da/cf0c1eb/216634e` telah diintegrasikan root
`6e84aef/b203e77/e766176`. Koordinator melaporkan pengulangan 158 tes/613 assertions
dan tujuh file byte-identik. PG 350/2597 adalah bukti worker sebelumnya yang
direview, bukan run baru root atau bukti bahwa composition sudah selesai.

## Kontrak minimum yang diusulkan

Nama internal: `CheckoutSummary`, immutable dan allowlisted. Entrypoint usulan:
`CheckoutSessionLifecycle::readSummary(CheckoutSessionMutationCredentials)`.
Tambahkan operasi enum internal `Summary` setelah review; tidak menerima ID,
principal, array opsi operasi, callback, tanggal, dokumen, atau harga dari caller.

Bentuk serialisasi yang diusulkan di bawah adalah keseluruhan success payload.
Field tidak boleh bertambah dari serialisasi model. TypeScript ini dokumentasi,
bukan perubahan types frontend atau kontrak HTTP yang sudah live.

```typescript
type ProfileKey = 'fullName' | 'birthDate' | 'gender' | 'educationLevel'
    | 'intendedField' | 'email' | 'phone';
type ProfileFact = { key: ProfileKey; label: string; required: boolean } & (
    | { state: 'locked'; displayValue: string }
    | { state: 'missing' }
);
type Document = { version: string; title: string; text: string };
type Consent =
    | { state: 'accepted'; version: string }
    | { state: 'required'; document: Document };
type TestType = 'ist' | 'papi' | 'rmib' | 'kraepelin' | 'dass21';
type Payment = {
    amountIdr: number | null;
    amountSource: 'charge_snapshot' | 'unavailable';
    consultationRequested: boolean | null;
    actionAvailable: false;
} & (
    | { payer: 'unselected'; state: 'unselected' }
    | { payer: 'self'; state: 'unpaid' | 'preparing' | 'pending'
        | 'recovery_required' | 'paid' | 'free' | 'expired' | 'rejected' }
    | { payer: 'organization'; organizationName: string;
        state: 'unbilled' | 'preparing' | 'pending' | 'recovery_required'
        | 'paid' | 'free' | 'expired' | 'rejected' }
);
type Summary = {
    contractVersion: 'checkout-summary-v1';
    formKey: string;
    sourceName: string;
    branchName: string;
    packageName: string;
    packageSource: 'charge_snapshot' | 'catalog';
    attemptLabel: string;
    profile: ReadonlyArray<ProfileFact>;
    identityMessage: string;
    payment: Payment;
    access: {
        state: 'locked' | 'partial' | 'ready';
        tests: ReadonlyArray<{ testType: TestType; state: 'locked' | 'ready' }>;
        startAvailable: false;
        message: string;
    };
    consents: {
        psychotest: Consent;
        dass: Consent | { state: 'not_applicable' };
        legalReviewPending: boolean;
    };
};
```

Invariants tambahan: profile tepat tujuh baris dengan urutan DTO accepted; tests
unik, tidak kosong, hanya jenis own package/snapshot. Urutan daftar display tidak
menentukan urutan pelaksanaan instrumen. Amount integer IDR dalam batas JS safe,
0..9,007,199,254,740,991. `charge_snapshot` berarti amount dan consultation tersedia;
`unavailable` berarti keduanya null. Payer unselected tidak mempunyai charge.
Paid memerlukan nominal positif dan settlement shared; free nominal nol dengan
marker settlement shared, bukan sekadar harga nol. Semua action tetap false.
`organizationName` hanya hadir untuk payer organization; berasal dari cabang own.
Tidak ada `review` sampai ada reader workflow manual yang membuktikannya.

## Pemetaan DRAFT dan provenance label

| Field | Sumber exact dan keputusan minimum |
| --- | --- |
| `contractVersion` | Literal versi presentasi baru; bukan checkout token purpose atau database contract version. DRAFT belum memilikinya. |
| `formKey` | Revision presentasi opaque, bukan credential. Usulkan `cs1_` + SHA256 JSON berurutan tetap: versi summary, own attempt public ID, latest consumed handoff public ID, daftar missing profile keys, testTypes canonical, flag legal, serta fingerprint dokumen applicable (type/version/text hash/title hash). Tidak memakai raw token, digest credential, nama/kontak, invoice atau clock idle. Perubahan dokumen dengan version sama dan teks berbeda harus mengubah key. |
| `sourceName` | Literal “Integrasi seleksi”. Source registry belum menyediakan friendly label; jangan tampilkan URL, external selection ID, credential reference, atau menebak brand/domain. |
| `branchName` | Own validated Branch: nonblank `display_name`, lalu nonblank `name`; keduanya invalid menghasilkan unavailable. Tidak fallback ke default tenant. |
| `packageName` / `packageSource` | Charge ada: nama dari snapshot yang sudah lolos `AssessmentPriceSnapshot::fromCharge`, source `charge_snapshot`. Tanpa charge: nonblank nama package authoritative, source `catalog`. Membaca label katalog tidak menciptakan harga final; jangan `capture(package, false)` untuk menebak konsultasi. |
| `attemptLabel` | Literal “Assessment Anda”, tanpa nomor/urutan tes palsu atau ID eksternal. |
| `profile` | Output accepted mapper, bukan enumerasi Participant. Label enum memakai DTO accepted; nonnull valid locked, SQL NULL missing, email optional. |
| `identityMessage` | Literal “Kelengkapan profil tidak menggantikan verifikasi identitas.” Tidak ada flag verified, outcome/confidence, foto/key evidence atau reviewer. |
| `payment` | Accepted own-payment DTO ditambah organizationName yang diambil dari label cabang yang sama. State `preparing` dan `recovery_required` tidak boleh di-coerce menjadi unpaid/pending palsu. |
| `access` | Satu panggilan gate kanonik per type own snapshot/package, dalam transaksi yang sama. Aggregate semua ready => ready, sebagian => partial, nol => locked. Ready adalah eligibility gate, bukan engine tersedia. |
| `consents` | Current document + accepted reader yang memakai dokumen/waktu evaluasi sama. DASS not_applicable hanya jika jenis dass21 tidak ada pada own testTypes. |

Pesan access adalah copy tetap: locked “Akses tes belum siap.”; partial “Sebagian
akses tes belum siap.”; ready “Prasyarat akses tes terpenuhi; mesin sesi belum
tersedia.” `startAvailable=false` pada ketiganya; jangan menghasilkan token/start
URL atau memanggil controller/activation saat membuat summary.

DRAFT tidak dapat langsung menerima kontrak ini: missing-profile DRAFT mewajibkan
descriptor `input/options`, payment belum mengenal preparing/recovery/provenance,
access hanya binary, dan packageSource tidak ada. Summary minimum ini read-only:
tidak mengarang descriptor mutasi P15, confirmation payload, onConfirm atau
onPayment. Perubahan frontend/adapter kelak memerlukan review tersendiri; jangan
membuat object yang hanya lolos cast TypeScript tetapi menyesatkan UI. `screen`,
`busy`, callback dan feedback milik adapter/UI, bukan field success DTO backend.

## Absent, invalid, dan prasyarat belum terpenuhi

| Keadaan | Hasil yang diizinkan |
| --- | --- |
| Credential salah, expired, logout/recovery lama, foreign graph, participant deleted, scope revoked | Invalid lifecycle generik, tanpa summary parsial. Expiry/revoke lifecycle boleh commit terminalisasi sesuai kontrak existing. |
| Profil SQL NULL | Missing field; jangan membuat placeholder identitas atau default UMUM. Bukan error konfigurasi. |
| Profil nonnull blank/tanggal/enum/tipe invalid | Mapper error -> summary unavailable generik; jangan mengubahnya menjadi editable missing. |
| Charge belum ada | Amount/consultation null, source unavailable; payer/state dari lifecycle persisted. Bukan gratis atau final catalog price. |
| Snapshot/initial funding/linkage/settlement corrupt atau future | Error payment reader -> summary unavailable; tidak di-coerce menjadi unpaid. Bill unknown canonical tetap recovery_required/action false. |
| Paid/free tetapi consent, profil, identity atau ready entitlement belum memenuhi gate | Payment tetap paid/free; gate locked pada tes terkait. Tidak invoice ulang atau aktivasi dari read. |
| Gate melempar `EntitlementLocked` | Locked generik. Exception ini tidak membedakan absent/corrupt/unmet; jangan menciptakan reason granular dengan menyalin predicate. Exception programming/DB/config bukan locked. |
| Current purchasing policy OFF | Tidak menurunkan historical settlement/access. Resolver baru hanya diperlukan untuk mutasi pembelian berikutnya. Revocation authority sesi tetap diperiksa seperti sebelumnya. |
| Catalog berubah setelah charge | Frozen amount, consultation, package label dan testTypes menang untuk fakta pembelian; authorization current source/package tetap mengikuti lifecycle existing. |

Sukses summary harus all-or-nothing: jika satu komponen gagal karena data/config
invalid, jangan kirim profile/payment yang sempat dibentuk. Error adapter kelak
generik tanpa PII, payload, SQL, model atau partial DTO. Unexpected failures tetap
masuk reporting framework; jangan disamarkan sebagai credential invalid.

## Consent dan dokumen: boolean accepted bukan histori keputusan

Gunakan `AcceptedConsentReader`, tanpa query status accepted kedua di composer.
True berarti bukti participant/type cocok dengan version+hash teks configured,
status accepted, consented_at tersedia/tidak future dan withdrawn_at null. Bukti
itu participant-bound, **bukan consent per attempt**. Label accepted hanya “versi
berlaku tersedia untuk peserta ini”; tidak ada tanggal/ID consent baru yang diklaim.

False diproyeksikan `required` bersama dokumen kini. Ini berarti acceptance kini
belum terbukti, bukan bukti peserta tidak pernah memilih atau telah menolak.
Missing, versi/hash lama, withdrawn, declined dan future tidak disebut accepted.
Minimum ini tidak mengeluarkan state `declined`: belum ada reader canonical yang
membedakannya dengan tepat. DASS `required` menawarkan pilihan opsional, bukan
kewajiban menyetujui agar tes utama layak. Jangan precheck atau auto-consent.
Jika produk ingin mempertahankan tampilan “ditolak”, minta slice reader keputusan
consent terpisah; jangan mengulang predicate accepted/withdrawn di composer.

`ConsentDocument::toPublicArray` adalah sumber version/title/text. DASS yang tidak
applicable tidak memerlukan dokumen DASS untuk payload. `legalReviewPending` wajib
boolean strict; missing/string/invalid harus unavailable, bukan bool cast yang
diam-diam meloloskan konfigurasi. Nilai true tetap terlihat dan tidak mengesahkan
draft legal. Flag ini tidak diam-diam ditambahkan ke gate existing sebagai syarat
access baru.

Perubahan title saja mengubah revision presentasi, tetapi tidak membatalkan
accepted bila version dan hash teks tetap cocok; ini perilaku reader yang sudah
diterima. Perubahan teks dengan version sama membatalkan bukti hash lama.

Gap compatibility nyata: ConsentDocument saat ini menerima string kosong, dan
tes accepted-reader mempertahankan perilaku itu. Rekomendasi **baru untuk summary**:
version/title/text applicable yang blank menghasilkan unavailable, karena dokumen
kosong bukan tampilan persetujuan yang bermakna. Ini memerlukan approval kontrak
presentasi sebelum implementasi; jangan mengubah reader/gate global atau mengklaim
aturan nonblank sudah existing. Teks legal ditampilkan sebagai text, tidak HTML
mentah, dan tidak disalin/ditulis ulang oleh composer.

## Satu transaksi lifecycle dan gap audit yang harus ditutup

Urutan target: tolak ambient role/transaction -> digest credential -> satu service
transaction -> reload/lock graph lifecycle existing -> validate latest generation
dan expiry -> ambil satu frame waktu/dokumen -> buat profile, product/payment,
consent dan access -> satu idle touch existing -> immutable DTO -> commit/restore.
Urutan relatif touch vs construction boleh mengikuti existing operate asalkan
error construction membatalkannya. ReadProfile/readPayment publik tidak dipanggil
dari readSummary: keduanya entrypoint pemilik transaksi, bukan helper composition.

Lock existing tetap organization -> client -> source -> package/items -> attempt
-> participant -> handoff history -> session. Tidak mengambil bill/item/charge
lock setelah attempt/session. Billing reader dan gate sudah tidak mengambil lock;
organization mutex mengikat writer reservation/finalizer/activation/issuance yang
kooperatif, dengan bukti PG payment slice sebelumnya. Jangan mengganti isolation
atau menambah lock global pada increment summary.

| Gap yang ditemukan | Dampak dan rekomendasi |
| --- | --- |
| Identity writer tidak mengikuti mutex pembacaan | `StoreIdentityEvidence.php:42` hanya SELECT Participant; baris evidence disimpan pada 48–63 lalu verification `updateOrCreate` pada 66. Tidak ada explicit organization/participant lock. Root dan worker source cocok. Existing-row replacement tidak boleh diasumsikan terblokir oleh parent lock/FK. Pada READ COMMITTED, beberapa SELECT gate dapat melihat commit berbeda. Ini static risk, **belum reproduksi race baru**. |
| Menambah evidence lock ke composer bukan perbaikan otomatis | Activation mengambil verification sebelum evidence; identity writer menulis evidence sebelum verification. Lock anak tambahan dapat membentuk siklus. Jangan menyalin pola ini ke summary atau menganggap org mutex melindungi writer yang tidak menggunakannya. |
| Clock dan dokumen belum berupa satu frame immutable | Lifecycle memakai clock database; profile memakai kalender itu, tetapi consent/settlement/prerequisites memakai `now()/today()` berulang. Accepted reader me-load dokumen sendiri; gate mengulang panggilannya. Batas waktu/dokumen bisa dinilai berbeda dalam satu composition. Jangan global `Date::setTestNow`/config mutation pada request. |
| Package label/testTypes masih tersembunyi di payment reader | Payment DTO sengaja tidak membawa model/snapshot. Untuk composer, usulkan typed internal product facts dari lookup/snapshot validation yang sama: own package label, source, canonical testTypes. Jangan copy parser harga atau mengarang consultation=false untuk katalog. Data tambahan ini tidak masuk payment output. |
| Identity reason dan decline reason tidak diekstrak | Minimum memakai identityMessage statis dan accepted/required saja. Tidak perlu granular reader untuk minimum ini. Gate tetap satu authority readiness; jangan menulis predicate identitas kedua. |

PostgreSQL READ COMMITTED memang memberi snapshot per statement sehingga dua
SELECT dalam satu transaksi bisa membaca commit berbeda; mengganti ke REPEATABLE
READ memerlukan penanganan serialization/retry dan bukan pengganti audit writer.
[Dokumentasi PostgreSQL 17](https://www.postgresql.org/docs/17/transaction-iso.html).
Lock hanya memblokir operasi yang berkonflik pada objek terkait; urutan konsisten
penting untuk menghindari deadlock.
[Explicit locking](https://www.postgresql.org/docs/17/explicit-locking.html).

Rekomendasi lock setelah approval terpisah: identity mutation mengambil participant
FOR UPDATE sebelum membaca/menulis evidence dan verification; bila ikut mengambil
organization, wajib organization dahulu. Jangan mengambil organization setelah
participant. Gate/lifecycle/activation yang sudah mengunci participant kemudian
dapat memakai pembacaan child biasa dalam mutex yang sama. Audit juga semua
manual-review/withdrawal writer sebelum menyatakan kontrak lengkap; pencarian
source kini menemukan consent create pada registrasi, tetapi belum writer P15
atau withdrawal/decline lifecycle baru. Registration membuat participant dan
consent baru dalam transaksi, bukan kontrak untuk mutasi consent existing.

Saat ini matcher yang dibind adalah ManualReviewIdentityMatcher lokal. Memperpanjang
participant lock selama handle existing tetap harus diuji; jangan memperkenalkan
provider matcher/network di transaksi sebagai efek samping perbaikan mutex.

Rekomendasi frame setelah review: satu object internal immutable berisi `asOf`
database (plus kalender server) dan ConsentDocument applicable yang sudah dimuat.
Tambahkan seam bertipe pada reader/gate untuk mengevaluasi frame yang sama;
existing public methods tetap delegasi dengan perilaku default lama. Ekstrak
predicate accepted sekali ke method document/asOf-bound, lalu pakai ulang dari
isAccepted existing dan gate. Settlement/prerequisites/gate perlu clock seam
kompatibel untuk composition; jangan fork predicate atau memaksa bool hasil
payment menjadi readiness. Jika belum disetujui/teruji, composition lengkap
tetap ditahan, bukan mengembalikan ready atau locked placeholder.

## Increment berikut yang direkomendasikan

**Pertama: prasyarat mutex identity, bukan langsung summary composer.** Minta
ownership eksplisit sebelum perubahan shared `StoreIdentityEvidence`. Mulai
reproduksi dua proses PostgreSQL: existing evidence diganti oleh action asli saat
canonical participant lock ditahan, plus gate/projection read yang terinterleaving.
Kemudian minimal parent-lock fix, tests feature regression storage/rollback,
tests PG commit/rollback/single-writer, dan laporan; target <=4 file, tidak P15,
matcher nyata, schema, routes atau isolation global. Jika reproduksi membantah
gap, catat bukti tersebut dan evaluasi kembali, jangan memaksakan fix asumtif.

Sesudah review mutex: increment document/asOf-bound accepted-reader compatibility
(reader, DTO frame bila diperlukan, tes, laporan <=4 file). Lanjutkan clock seam
gate/prerequisites/settlement dalam commit atomic terpisah <=5 file dan regression
existing. Product facts dapat diekstrak dalam slice payment-reader <=4 file.

Terakhir barulah Summary DTO/composer + operasi lifecycle khusus + focused tests;
pecah dengan PG tests/report jika melewati ~5 file per commit. Tidak ada frontend
atau HTTP sampai kontrak baru direview. Ini urutan usulan, bukan izin menjalankan
beberapa increment tanpa checkpoint.

## Matriks pengujian sebelum composition boleh diterima

| Kelompok | RED/GREEN yang wajib dibuktikan |
| --- | --- |
| Authority | Entrypoint hanya sensitive credential pair; malformed/foreign/stale/recovery/logout/deleted/scope revoked, semua ambient roles dan outer transaction ditolak. Tidak memakai stale principal atau public callback. |
| Atomic composition | Satu lifecycle transaction/context; bukan readProfile lalu readPayment. Exception komponen terakhir mengembalikan idle touch; expiry terminal tetap commit sekali; context selalu pulih; tidak ada partial DTO. |
| Profile + labels | Semua missing/locked accepted; invalid nonnull unavailable; own branch/source/package only; catalog label tanpa charge tetap amount null; charge snapshot label/types tetap saat katalog berubah. |
| Payment | Collective sepuluh nominal own saja, semua state accepted/provenance, paid+missing consent/identity tetap paid, zero tanpa marker bukan free, corruption/future/overflow gagal tertutup, policy OFF tidak menurunkan historical paid/access. |
| Consent | Exact doc/version/hash; same-version text/title change dan formKey; missing/old/declined/withdrawn/future bukan accepted; DASS absent type not_applicable; optional refusal tidak mengunci non-DASS; blank/invalid config sesuai keputusan review; legal true tidak hilang. |
| Access | Panggil gate per type tanpa menyalin predicate; all/some/none readiness; PROVISIONED/IN_PROGRESS/COMPLETED/finalized/started/completed entitlements sesuai gate; ready tetap startAvailable false dan engine 501. Gate exception generic bukan granular diagnosis. |
| Clock/config frame | Forced clock advance, date boundary, exact/future consent and settlement/evidence times, same-version text mutation: semua komponen memakai frame yang sama, tanpa global clock/config mutation atau cache lintas request. Existing caller compatibility tetap lulus. |
| Identity mutex PG | Existing-row evidence replacement vs held participant lock, writer-first/read-first, rollback, two writers; observed lock waits; verification/evidence tidak tercampur; lock order tidak deadlock. Matcher/storage synthetic saja. |
| Summary PG | Recovery/revoke/finalizer/policy writer vs one summary; participant evidence update vs summary setelah fix; commit/rollback observations konsisten, tidak ada attempt->bill lock; runtime non-owner/NOBYPASSRLS dan cleanup. |
| Privacy/no writes | Recursive exact keys; no raw token/digest/CSRF/IDs/parent amount/count/URL/proof/member/clinical/evidence fields; no payload log; business tables/audit/outbox unchanged kecuali lifecycle writes yang disebut eksplisit. |
| Compatibility | Accepted profile/payment/consent/settlement/gate/activation/start/registration regression; DRAFT tidak diubah atau dipaksa melalui type assertion. HTTP/browser/P15 acceptance tetap terpisah. |

## Verifikasi proposal dan batas

Increment ini hanya source audit, pembandingan kontrak dan dokumen. Tidak ada
PHPUnit/Pint/PHPStan/PG/browser runtime baru yang diklaim. Dokumen PostgreSQL
menjelaskan risiko, bukan bukti bahwa race aplikasi sudah direproduksi.
`git diff --check` dan cached-path check dijalankan sebelum commit report tunggal.
Baseline untracked overlays, blocked scratch, root canonical docs dan aplikasi
tidak diubah; tidak ada active DB/.env/data nyata/outbound/deploy/push/task/agent.
STOP untuk review keputusan dan scope prasyarat, bukan melanjutkan implementasi.
