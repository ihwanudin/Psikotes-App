# P14c — preflight kontrak proyeksi ringkasan privat

Status: **proposal untuk review, belum implementasi**, 2026-09-04. Ownership
increment ini hanya laporan ini. Baseline lane `be47d4d`; acceptance adapter
P14b2 root `5e11bbb`/`c1963aa` tidak berarti halaman ringkasan P14 sudah tersedia.
Tidak ada projector, DTO, endpoint, perubahan frontend, P15 writer, atau aktivasi.

## Sumber dan batas authority

Dibaca read-only dari root: `SPEC-integrated-checkout.md`, plan/todo/parallel-work
organization-payment, ADR-004/005/012 (termasuk amendment native logout), dan
`resources/js/types/integrated-checkout.ts`. DRAFT frontend adalah kontrak
presentasi, bukan kontrak HTTP yang telah diterima. P14/P15/P16 pada todo masih
unchecked; instruksi P14c koordinator membatasi pekerjaan pada preflight ini.

Authority kode yang diaudit:

- `app/Actions/Integrations/CheckoutSessionLifecycle.php`, DTO principal/session,
  middleware `AuthenticateCheckoutSession`, dan `CheckoutSessionHttpContract`:
  sesi aktif, scope persisted, generasi handoff/recovery, expiry, cookie digest.
- Model `AssessmentParticipant`, `Participant`, `Branch`, `IntegrationClient`,
  `IntegrationSource`, `TestPackage`: identitas sendiri dan registry authoritative.
- `ResolvePayerPolicy`, `AssessmentPriceSnapshot`, model charge/bill/item/entitlement,
  serta `PreviewAssessmentBill`, `ReserveAssessmentBill`, `FinalizeAssessmentBill`:
  keputusan payer, snapshot harga, allocation dan settlement.
- `AssessmentEntitlementGate`, `AssessmentAccessPrerequisites`,
  `ActivateSettledAssessment`, `StartParticipantSessionController`: akses per tes,
  prasyarat, aktivasi sebagai writer, dan engine sesi yang masih 501.
- `App\Registration\ConsentDocument`, `config/consent.php`, model/migration
  consent dan identity evidence/verification: dokumen kini dan bukti peserta.

Tidak boleh memakai `Participant.package_id`, order/entitlement legacy, email,
telepon, parameter browser, Host/Origin, atau `principal.descriptor()` sebagai
pengganti graph attempt. Descriptor memuat lebih banyak identifier daripada yang
boleh diserialisasikan ke ringkasan. Input internal yang direkomendasikan hanya
credential sesi checkout existing; tidak menerima participant/attempt/bill ID
pilihan caller atau Eloquent model yang dianggap sudah terotorisasi.

## Boundary pembacaan yang direkomendasikan

Middleware existing mengakhiri transaksi hydration sebelum memanggil downstream.
Principal sesudahnya merupakan snapshot; tidak cukup untuk projector melakukan
query service berdasarkan ID tersebut tanpa revalidasi. Tambahkan, setelah
review, entrypoint lifecycle khusus bertipe untuk projection dengan credential
existing. Reuse graph validator/`operate` yang sama di dalam transaksi service;
jangan salin parser/session predicate, membuat callback publik generik, atau
memanggil `hydrate` dari ambient transaction/context yang memang ditolak.

Urutan canonical tetap organization → client → source → package/items → attempt
→ participant → handoff history → checkout session. Nilai digest, scope lengkap,
contract checkout-v2, latest consumed handoff, session aktif dan expiry diperiksa
ulang dengan clock database. Tolak ambient RLS context termasuk admin/participant
dan outer transaction. Pemulihan sesi/revoke yang menang sebelum pembacaan harus
membuat sesi lama unavailable; credential lama tidak dapat memilih attempt baru.

Projector murni hanya menerima graph hasil validasi internal, lalu membentuk DTO
allowlist sebelum transaksi berakhir. Pembacaan billing/consent tidak mengambil
lock bill setelah lock attempt/session: jangan membalik urutan writer pembayaran.
Organization mutex melindungi writer kooperatif yang memakai urutan canonical;
reader baru tidak boleh mengklaim perlindungan terhadap writer yang melewatinya.
Tes PG wajib memeriksa interleaving writer yang benar-benar dipakai. Bila reader
butuh lock tambahan, ajukan revisi boundary dahulu, bukan menambahkannya diam-diam.

Hydration existing memperbarui idle/last-seen dan dapat menandai expiry. Karena
itu klaim yang benar adalah **tidak ada mutasi bisnis dari projector**, bukan
seluruh request tanpa write. Tidak ada reserve/finalize/activation, consent write,
invoice lookup/create, audit pembayaran, outbox atau credential baru saat proyeksi.
RLS context wajib pulih pada keberhasilan maupun exception. Respons merupakan
snapshot saat dibaca, bukan izin mutasi/start berikutnya; semua aksi revalidasi.

## Pemetaan lengkap DRAFT ke authority

| Field DRAFT | Authority dan usulan kontrak |
| --- | --- |
| `formKey` | Bukan field persisted atau credential. Usulkan revision presentasi dari own attempt yang terverifikasi dan fingerprint dokumen kini, mencakup hash teks selain version. Implementasi dapat memakai hash deterministik primitive existing; bukan token/auth atau rekonstruksi secret. Jangan memasukkan PII/credential ke input revision. Perubahan versi sama tetapi teks berbeda harus remount. |
| `sourceName` | Registry `IntegrationSource` memiliki `source_system`, tetapi tidak memiliki friendly display-name. Jangan memakai domain, callback URL/configuration atau external ID. Usulkan label statis generik “Integrasi seleksi” dahulu; label per sumber memerlukan mapping yang direview, bukan schema baru di slice ini. |
| `branchName` | `Branch` dari exact `attempt.organization_id`, cocok participant.branch/client.organization. Gunakan nonblank `display_name`, lalu nonblank `name`; tidak fallback organisasi default. Nilai malformed menghasilkan unavailable, bukan cabang tebakan. |
| `packageName` | Charge ada: `AssessmentPriceSnapshot::fromCharge(...).packageName`; charge belum ada: nama dari catalog snapshot tervalidasi `capture`. Relasi selalu `attempt.package_id`, bukan paket legacy peserta. Label sumber harga harus ikut membedakan snapshot vs katalog. |
| `attemptLabel` | Label UI statis “Assessment Anda” cukup untuk increment minimum. Jangan membocorkan external selection/candidate ID atau mengarang urutan attempt/nomor tes. ID attempt internal hanya untuk scope, tidak diperlukan oleh props. |
| `profile[].key` | Allowlist tujuh field pada tabel profil di bawah, urutan tetap; tidak enumerasi atribut model. |
| `profile[].label` | Copy UI tetap, bukan metadata integration/client. |
| `profile[].state` / `displayValue` | Nilai persisted valid → locked; SQL NULL → missing. `displayValue` hanya nilai peserta sendiri dengan formatting teks/date deterministik, tanpa raw HTML. Nonnull invalid tidak dianggap missing/editable. |
| `profile[].required` | Enam field gate wajib; email tidak diwajibkan `AssessmentAccessPrerequisites`. Flag presentasi bukan validator P15. |
| `profile[].input` / `autoComplete` / `options` | Descriptor UI allowlist tetap; enum kontrak existing, tidak opsi dari request/metadata. Optional autocomplete boleh dihilangkan. Tidak menyatakan server mutation contract telah dibuat. |
| `identityMessage` | Pesan generik prasyarat, bukan klaim identity verified dari profil lengkap. Predicate identity existing berada dalam `AssessmentAccessPrerequisites`; belum ada reader granular. Jangan expose outcome matcher/confidence/evidence/admin reviewer. |
| `payment.amountIdr` | Nominal **charge/allocation attempt ini saja**, setelah validasi snapshot dan linkage; tanpa charge boleh katalog berlabel estimasi, atau null bila belum ada pilihan konsultasi sah. DRAFT perlu provenance eksplisit; detail di bawah. |
| `payment.payer` | Funding lifecycle persisted: `COMMERCIAL_SELF_PAY` → self, `INVOICED_TO_ORGANIZATION` → organization, null sah → unselected. Reload `ResolvePayerPolicy` atas graph kini; jangan menerapkan auto-selection resolver ke DTO seolah sudah dipilih. `unselected` bukan nilai database. Free adalah settlement state, bukan payer ketiga. |
| `payment.organizationName` | Hanya payer organization yang sama dengan organization authoritative di atas. Tidak organisasi/member lain. |
| `payment.state` | Turunan charge, own bill item dan parent bill yang dibaca internal; bukan `assessment_status` atau flag browser. Matriks state di bawah. |
| `payment.actionAvailable` | Selalu false pada increment projector internal; belum ada public payment action. Selanjutnya perlu authority action terpisah, bukan sekadar `unpaid`. Payer organization tidak pernah mendapatkan aksi invoice self. |
| `access.state` / `message` | `AssessmentEntitlementGate::assertReady` per test type dari frozen snapshot, dalam service context authoritative; prerequisite failure tetap locked tanpa mengubah paid. DRAFT binary tidak dapat mengungkap partial-ready; jangan mengklaim semua tes siap dari satu entitlement. READY bukan engine session tersedia: controller existing masih `501 SESSION_ENGINE_PENDING`. |
| `consents.psychotest` / `.dass` | Bukti `ConsentRecord` participant yang sama dibandingkan `ConsentDocument::for(type)` kini; bukan payload integrasi atau kelengkapan profil. DASS applicable hanya bila `dass21` ada pada test types authoritative; DASS opsional untuk tes lain. Detail mapping di bawah. |
| consent `document.version/title/text` | `ConsentDocument::toPublicArray`, teks legal configured server, bukan dokumen kiriman client. Hash server tetap diperiksa meskipun DRAFT tidak menampilkannya. |
| consent `accepted.version` / `declined.version` | Version dokumen kini yang benar-benar cocok hash/status bukti; jangan menampilkan version lama sebagai acceptance kini. |
| `consents.legalReviewPending` | Boolean strict `config('consent.legal_review_pending')`, saat audit true. Missing/invalid tidak berubah menjadi false/approved; gagal tertutup. Projector tidak mengesahkan draft legal. |

`screen.loading`, `busy`, callbacks dan feedback adalah state komponen/adaptor,
bukan authority database. `screen.ready` hanya berarti DTO aman tersedia, bukan
entitlement siap. Expired/invalid/foreign/recovery/error harus memakai pesan
generik tanpa mengungkap apakah peserta/organisasi tertentu ada. Confirmation
`missingProfile`, psychotest `{version,accepted:true}` dan DASS `{version,accepted}`
adalah usulan input P15: laporan ini tidak menerima, menyimpan atau mengesahkannya.
Field errors kelak hanya allowlist profile key, tidak exception/SQL mentah.

### Profil: NULL bukan identitas palsu

| Key | Kolom peserta / presentasi / requirement |
| --- | --- |
| `fullName` | `full_name`, teks, wajib nonblank. |
| `birthDate` | `birth_date`, tanggal lokal `YYYY-MM-DD` tanpa time-zone shifting, wajib sebelum hari kini sesuai gate. |
| `gender` | `gender` persisted `male`/`female`; label UI terpisah. Input checkout integrasi memakai mapping enum kontrak, tidak boleh menyalin nilai display sebagai nilai database. |
| `educationLevel` | `education_level`, teks wajib; jangan menciptakan enum baru dari asumsi UI. |
| `intendedField` | `intended_field`, enum existing KAIGO/KENSETSU/NOUGYOU/SEIZOU/GAISHOKU/UMUM. Missing tetap NULL, tidak default UMUM. |
| `email` | `email`, email opsional; NULL tetap missing optional, bukan penghalang gate baru. |
| `phone` | `phone`, teks/tel wajib; bukan integer yang menghilangkan nol/prefix. |

Belum ada provenance/locked flag per field. Kebijakan minimum yang sesuai SPEC:
semua nilai valid yang sudah ada locked, bukan hanya yang terbukti dari sumber.
Whitespace kosong, enum ilegal atau tanggal malformed nonnull menjadi correction
required/unavailable generik, tidak membuka edit nilai existing. DRAFT hanya
locked/missing: jangan memaksa data corrupt masuk salah satunya. Nilai lengkap
dibetulkan upstream/admin berizin, bukan melalui summary GET. Tidak mengisi data
palsu dan tidak menimpa nilai lengkap dengan null saat refresh/replay.

### Harga, pembayaran sendiri, dan state yang belum terwakili

Harga dapat dikonfigurasi melalui katalog server; tidak ada tarif hardcoded.
Currency diterima hanya IDR sesuai `AssessmentPriceSnapshot`, nominal integer
rupiah tanpa float. Frozen charge snapshot menang atas harga katalog yang berubah.
Validasi own charge scope, package, payer, snapshot versi/kolom, consultation flag,
jumlah dan currency; bila ada item, jumlah item harus sama dengan charge sendiri.
Tidak pernah mengambil `AssessmentBill.amount` sebagai `amountIdr` peserta.

Tambahkan provenance presentasi yang direview: `amountSource = charge_snapshot |
catalog_estimate | unavailable`. Sebelum charge ada, jangan mengasumsikan pilihan
konsultasi false dari ketidakhadiran data atau request browser. Untuk increment
minimum gunakan amount null/unavailable bila pilihan belum authoritative; opsi
estimasi harga dasar harus berlabel jelas, bukan nominal final yang dapat dibayar.
Jumlah nol sah sebagai harga tetapi **bukan** bukti free-settled. `null` berarti
nominal belum dapat ditampilkan, tidak sama dengan nol.

DRAFT memakai JavaScript `number`; validasi PHP integer saja tidak cukup untuk
nilai di atas `Number.MAX_SAFE_INTEGER`. Rekomendasi: range transport integer
0..9,007,199,254,740,991, gagal tertutup saat melampaui, tanpa clamp/round/string
conversion diam-diam. Jika seluruh range database harus ditampilkan, ajukan
kontrak decimal-string dan perubahan formatter frontend terpisah.

| State authoritative | Proyeksi minimum yang aman |
| --- | --- |
| Initial funding snapshot ADR005 absent/invalid, versi salah, funding lifecycle tidak sah/current policy denied | Unavailable generik; jangan infer/backfill initial snapshot atau mengubah funding. |
| Payer null sah, belum charge | unselected, amount null, tanpa payment action. |
| Payer selected, belum claimed/settled | self unpaid / organization unbilled; amount snapshot bila charge sah tersedia, selain itu aturan katalog di atas. |
| Bill reserved / issuing | Preparing/pending generik dengan action false; DRAFT perlu keputusan label agar tidak menyatakan provider invoice sudah ada. |
| Bill pending + own item unsettled | pending; tidak mengeluarkan URL, reference, gateway ID atau expiry parent bill. |
| Manual review | Bukan enum status bill mandiri. `review` hanya jika reader canonical membuktikan state review dari workflow manual existing; tanpa reader itu tetap pending generik, jangan menampilkan proof metadata. |
| Bill unknown atau snapshot/linkage/state campuran corrupt | recovery_required/unavailable, tanpa aksi invoice ulang. DRAFT tidak punya varian ini; jangan dipetakan unpaid. |
| Positive charge dan seluruh settlement canonical sah | paid untuk own allocation, meskipun consent/identity belum lengkap dan access masih locked. |
| Zero charge + `free_settled_at` sah + tanpa bill item | free; zero tanpa marker settlement tetap belum settled. Jangan memanggil free writer dari projector. |
| Expired/rejected claimed bill | expired/rejected bila canonical; action false, jangan menghapus claim atau menawarkan tagihan baru otomatis. |

Gate existing mencampur settlement, ready entitlement dan prerequisites melalui
`assertReady`, sedangkan predicate `settled` masih private (juga pada activation).
Karena itu gate gagal **tidak** berarti unpaid. Sebelum proyeksi paid/free, usulkan
ekstraksi reader settlement canonical bersama dari logic existing, diuji terhadap
gate/activation/finalizer. Reader boleh memeriksa count/sum/settled seluruh item
parent bill untuk integritas, tetapi mengembalikan hanya keputusan own settlement;
tidak mengembalikan collection/model/count/total. Jangan memanggil Preview/Reserve
sebagai reader status umum: preview batch bukan DTO peserta, dan paid/claimed
memiliki semantik berbeda. Perubahan shared reader memerlukan persetujuan slice.

### Consent versioned, DASS, identitas, dan akses per tes

Accepted berarti participant/type/version/hash sama dengan dokumen kini,
`status=accepted`, consented_at tidak null/tidak masa depan, withdrawn_at null.
Ganti teks pada version sama tetap membatalkan acceptance untuk dokumen kini.
Psychotest missing/declined/withdrawn/stale tampil required dengan dokumen kini;
tidak auto-accept. DASS missing/stale/withdrawn juga required, tetapi UI harus
menjelaskan pilihan DASS opsional untuk tes lain. DASS declined kini dapat tampil
declined hanya dengan version/hash yang cocok; jangan menyebut withdrawn sebagai
declined. Bukti inconsistent/future timestamp tidak boleh diterima. Schema tidak
punya `declined_at`; jangan mengarang waktu penolakan atau menyatakan legal
evidence lebih kuat daripada yang disimpan. Mapping negatif ini perlu shared
consent reader agar tidak membuat salinan predicate accepted di projector.

DASS not_applicable berasal dari test types frozen charge atau paket authoritative
sebelum charge, bukan dari hasil klinis. Declined DASS tidak boleh menghalangi
entitlement tes non-DASS. Akses harus dibaca per type, tanpa skor/result/DASS
answers. Usulkan daftar keputusan per-test yang hanya memuat type dan locked/ready,
lalu aggregate `locked | partial | ready`; DRAFT binary perlu direvisi sebelum
adaptor frontend. IN_PROGRESS/COMPLETED/finalized bukan bukti bahwa entitlement
yang sudah started/completed masih dapat dimulai. Jangan membuat start token,
session URL, atau tombol sukses selama engine tetap pending.

Gap penting: `consent_records` unik pada participant/type/version; identity
verification unik participant_id, evidence unik participant/type. Tidak ada
attempt FK pada bukti ini. Gate menjalankan pengecekan peserta dalam scope attempt,
tetapi itu tidak membuktikan consent/identitas direkam khusus attempt tersebut.
DTO tidak boleh mengatakan “consent attempt ini diberikan” atau tanggal verifikasi
per attempt. Gunakan “persetujuan versi berlaku tersedia untuk peserta ini”.
Jika P15 memerlukan bukti baru per attempt, itu keputusan kontrak/schema terpisah;
jangan menyisipkan perubahan binding dalam projector.

Identity prerequisites sekarang memberi exception gabungan, bukan status granular.
Untuk increment minimum `identityMessage` tetap generik tanpa flag verified;
status verified granular ditunda sampai reader shared teruji. Predicate existing
memeriksa keputusan manual/matcher yang sah serta dua evidence tidak lebih baru
daripada checked_at. Profil lengkap atau pembayaran lunas tidak menggantikannya.

## Usulan increment implementasi minimum, setelah review

1. DTO whitelist immutable + pure projector dasar + tes DTO/profile/serialization
   (sekitar 3–4 file). Sumber label statis; field payment/access yang belum memiliki
   reader sah tidak dibuat-buat. Tambahan provenance harga dan partial access
   direview bersama frontend; ini belum drop-in replacement DRAFT.
2. Prasyarat reader settlement shared: satu service + gate/activation menggunakan
   service yang sama + tes focused (maksimal sekitar 5 file). Pertahankan semantics,
   tidak writer/session baru. Bila reader consent granular diperlukan, pisahkan
   increment serupa, jangan memperbesar satu commit atau menyalin predicate.
3. Entry lifecycle projection bertipe + DTO/projector lengkap + feature/PG tests
   (target sekitar 5 file, split jika perlu). Reuse authority graph dalam transaksi
   existing, bukan generic `project(principal)` yang dapat dipanggil siapa saja.
   DTO harus siap sebelum HTTP adapter/wiring dipertimbangkan.

Tidak ada requirement HTTP baru di laporan ini. Ketika adapter diizinkan kelak,
privacy boundary ADR012 tetap berlaku pada semua status: no-store/private,
no-referrer, DENY, nosniff, CSP, generic errors. CSRF secret tetap delivery kanal
khusus existing hidden-field/meta halaman aktif; tidak menjadi field summary,
props JSON, formKey, URL, telemetry atau log. P15 mutations, payment action dan
start assessment merupakan boundary terpisah yang selalu revalidasi.

## Matriks TDD wajib untuk implementasi berikutnya

| Kelompok | Bukti negatif/positif yang harus dibuat sebelum code |
| --- | --- |
| Credential/authority | Forged/stale principal object tidak diterima sebagai entry input; missing/malformed cookie, mismatch digest, sesi expired pada batas DB clock, recovery/handoff generation baru, logout/revoke, salah role/ambient transaction → generic unavailable tanpa data. |
| Scope joins | Participant sama di attempt lain, foreign tenant/client/source/package/charge/item, soft-deleted participant, disabled/effective source, paket tidak diizinkan, marker v1, parent payer mismatch → gagal tertutup; tidak fallback legacy/default branch. |
| Policy/history | Policy OFF/locked payer berubah; initial funding key absent vs null, illegal snapshot value, lifecycle selected bertentangan initial selected → no inference/backfill; initial null dan selected kini yang sah tetap dibaca tanpa mutasi. |
| Profile | Setiap nullable field missing, email optional, full profile locked, gender enum mapping, tanggal masa depan, blank nonnull, intendedField invalid, telepon dengan nol, label dengan HTML → format aman/correction-required; tidak default UMUM atau mengosongkan existing. |
| Catalog vs snapshot | Catalog harga/nama/addon berubah sesudah charge tidak mengubah own frozen display; snapshot unknown version/type/amount/currency mismatch ditolak. No charge/no consultation authority → tidak mengarang total; integer overflow, float, string, negatif, >JS safeinteger ditolak. |
| Payment | Bill collective 10 anggota: hanya nominal own charge; parent total/member/count/URL tidak keluar. Reserved/issuing/pending/unknown/expired/rejected, orphan/duplicate/mismatched item, partial sum/settled timestamp masa depan, zero belum settled, free sah diuji terpisah. Paid + profile/consent/identity belum lengkap tetap paid dan locked tanpa invoice lagi. |
| Consent | Version/hash berubah, accepted dengan waktu masa depan/withdrawn, record peserta lain, missing/declined/withdrawn, version sama text baru, invalid config → tidak accepted. DASS absent package → not_applicable; declined DASS tidak mengunci non-DASS; legalReviewPending true tidak hilang. |
| Identity/access | Complete profile tanpa evidence tidak verified; checked/reviewed masa depan atau evidence diganti setelah verification gagal gate. READY label tanpa settlement/entitlement sah tidak cukup; started/completed/finalized, partial-ready test set dan controller engine pending tidak menjadi izin start. |
| No disclosure | Recursive allowlist assertion dan sentinel: other member names/amounts/IDs, bill total/reference/URL/proof, external candidate/selection IDs, token/digest, raw CSRF, evidence path, clinical/DASS data tidak ada dalam DTO/error/log. Own profile adalah PII yang memang hanya di sesi terotorisasi; tidak logging DTO. |
| No business writes | Snapshot semua business tables sebelum/sesudah; tiada charge/bill/activation/outbox/consent/audit pembayaran baru, tiada provider/job. Perubahan lifecycle idle/expiry existing diuji terpisah, jangan diklaim zero-write total. |
| PG race/RLS | Disposable runtime non-owner/NOBYPASSRLS: recovery/revoke vs projection, finalizer vs projection, policy writer vs projection; output sebelum/ sesudah commit konsisten dengan lock canonical, tidak mixed paid state. Rollback/exception memulihkan context. Buktikan tidak ada lock inversion attempt→bill; SQLite bukan bukti concurrency/RLS. |

Gunakan PHPUnit organization-payment SQLite memory untuk semantics dan runner
PostgreSQL disposable untuk isolation/locking ketika implementasi disetujui.
Tambahkan regresi gate/activation/finalizer/lifecycle bila reader atau seam shared
berubah. Bukti unit DTO bukan bukti HTTP/browser/public summary acceptance.

## Handoff dan verifikasi increment ini

Keputusan yang diminta sebelum code: provenance amount/null sebelum pilihan sah;
label sumber generik; partial access dibanding binary DRAFT; konservatisme identity
message; ekstraksi reader settlement/consent dan seam lifecycle bertipe. Tidak ada
usulan schema baru atau perluasan authority bukti consent per attempt terselubung.

Hanya review source dan dokumen; tidak menjalankan PHP/Node/browser/PG atau outbound.
Tes runtime/Pint/PHPStan tidak diklaim baru karena tidak ada code/config berubah.
`git diff --check` diperiksa sebelum commit laporan; commit hanya path laporan ini,
tanpa staged/untracked snapshot baseline. Hash final dikirim ke koordinator.
Scratch browser cleanup yang sebelumnya diblokir tetap tidak disentuh. STOP untuk
review; tidak melanjutkan projector/HTTP/P15 maupun aktivasi produksi.
