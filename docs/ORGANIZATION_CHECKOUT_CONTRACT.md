# Kontrak checkout opt-in (P5/P9)

## Status dan batas

Kontrak internal `checkout-v2` memiliki action P9a yang menyimpan participant dan
attempt PROVISIONED secara atomik/idempotent. Request dan adapter policy sendiri
tetap tidak melakukan persistence. Adapter HTTP P9b disiapkan tanpa registrasi
route produksi: endpoint checkout publik belum tersedia dan P9 belum live.
Tidak membuat invoice, entitlement, consent, credential, token atau outbox.
Harga bukan input integrasi.

## Input

Gunakan field camelCase seperti kontrak assessment v1:

- Wajib: `contractVersion` persis `checkout-v2`, `sourceSystem`,
  `organizationCode`, `externalCandidateId`, `assessmentPackageCode`, `profile`.
- ID opsional: `externalProcessId`, `externalRegistrationId`, `assessmentRoundId`.
- `profile` menerima hanya fullName, birthDate, gender, educationLevel, email,
  phone, intendedField. Object kosong/field null diperbolehkan sebagai profil
  parsial; nilai yang diberikan tetap wajib valid. intendedField opsional/nullable
  dengan nilai KAIGO/KENSETSU/NOUGYOU/SEIZOU/GAISHOKU/UMUM, tanpa default UMUM.
  Missing/null tetap belum diketahui. Ini bukan bukti identitas atau persetujuan;
  kontrak v1 tidak berubah. Lihat [ADR-004](decisions/0004-checkout-partial-profile.md).
- `payerType` opsional/null atau tepat `self`/`organization`. Tanpa pilihan,
  resolver P3 dapat memilih satu pilihan efektif atau meminta pilihan peserta.
- `fundingMode` hanya untuk adapter legacy eksplisit: COMMERCIAL_SELF_PAY → self,
  INVOICED_TO_ORGANIZATION → organization. Flag mapping server harus true dan
  field payerType tidak boleh sekaligus dikirim (termasuk null). SPONSORED,
  INTERNAL, WAIVED tidak pernah dipetakan. Label legacy bukan bukti paid/gratis.
- `metadata` hanya menerima cohortCode. Field tambahan pada root/profile/
  metadata ditolak, termasuk paid, amount, branchId dan consent.
- Idempotency-Key wajib valid sebelum provisioning P9; request helper memeriksa
  karakter/ukuran, action P9a memeriksa konflik dan replay secara transaksional.

Validasi memakai Form Request dan allow-list array seperti
[dokumentasi Laravel](https://laravel.com/docs/13.x/validation#validating-arrays).

## Gerbang dan keluaran

`assessment_integration.checkout.enabled` default false. Payer mapping legacy
memerlukan `assessment_integration.checkout.allow_legacy_funding_mapping` true
terpisah. Keduanya konfigurasi server; tidak dibaca dari payload.

`CheckoutContractAdapter::resolve(client, source, package, input)` menerima input
tervalidasi serta model registry yang dipetakan server. Ia memeriksa versi,
pemetaan organisasi/sumber/paket, lalu memakai ResolvePayerPolicy. Keluaran
PayerDecision bukan izin tes atau lunas. Action P9a me-reload registry dalam
transaksi/service RLS sebelum create maupun replay; akses tetap memerlukan gate
identitas/consent/settlement terpisah.

## Persistence dan keputusan funding awal (P9a)

Identitas dipetakan lewat organisasi/source/external candidate tepat, tidak
digabung lintas organisasi lewat email/telepon. Profil parsial sah disimpan tanpa
placeholder, attempt baru selalu PROVISIONED. Replay tidak mengosongkan profil
yang telah dilengkapi dan tidak mengubah status/funding lifecycle.

Sesuai [ADR-005](decisions/0005-checkout-initial-funding-snapshot.md), create menulis
metadata server checkout_contract_version=checkout-v2 dan
checkout_initial_funding_mode. Key snapshot awal wajib hadir, dengan nilai tepat
null/COMMERCIAL_SELF_PAY/INVOICED_TO_ORGANIZATION dari resolver. Payload tidak
boleh memasok keduanya; metadata input tetap hanya cohortCode. Snapshot tidak
diubah replay/lifecycle; ini kontrak aplikasi, bukan constraint immutable DB baru.

Replay mencocokkan scope/hash dan keputusan resolver terkini dengan snapshot
awal, lalu memeriksa funding lifecycle secara terpisah terhadap PayerDecision.
Initial null boleh dipilih kemudian; initial selected tidak boleh berubah/null.
Snapshot hilang/invalid atau versi salah gagal tertutup tanpa infer/backfill.
Policy tidak sah, keputusan awal berubah, revoked/void tetap ditolak. Nilai ini
bukan bukti paid, persetujuan, identitas atau entitlement.

## Adapter HTTP belum dipasang (P9b)

CheckoutParticipantProvisioningController memakai ProvisionCheckoutParticipantRequest
dan action P9a; route hanya didaftarkan oleh tes sintetis. Wiring mendatang wajib
memasang middleware `integration.client` existing yang memverifikasi HMAC atas
timestamp dan raw body. Controller bukan pengganti signature verifier. Context
service dibentuk setelah autentikasi; context peserta/admin yang sudah aktif
tidak dinaikkan haknya. Context dan transaksi dipulihkan pada sukses/error.

Response sukses hanya `data.participantId` (string), `assessmentAttemptId`, dan
`assessmentStatus`: HTTP 201 untuk create, 200 untuk replay. Tidak mengirim profil,
metadata, funding snapshot, credential, token atau checkoutURL. Status adalah
proyeksi existing, bukan izin mulai tes. Respons controller (sukses maupun error
kontrak) memakai Cache-Control: no-store, private.

Conflict menghasilkan HTTP 409/IDEMPOTENCY_CONFLICT; key missing/invalid
422/IDEMPOTENCY_KEY_REQUIRED; exception kontrak memakai code/status existing.
Pesan controller generik dan tidak menyalin exception/payload. Autentikasi gagal
dan validasi FormRequest terjadi sebelum controller, tetap memakai middleware/
handler JSON existing (termasuk VALIDATION_FAILED pada path api/*). Header error
awal dan error tak terduga tetap milik pipeline existing; P9b tidak menambah
no-store global atau mengubah shared auth. Error tak terduga memakai renderer
framework dengan debug dimatikan, bukan contract-error mapping baru.

Kesalahan memakai IntegrationContractViolation: CHECKOUT_NOT_ENABLED (503),
CHECKOUT_CONTRACT_REQUIRED/INTEGRATION_CONTEXT_INVALID (403),
LEGACY_FUNDING_MAPPING_DISABLED (403), AMBIGUOUS_PAYER_INPUT dan
LEGACY_FUNDING_NOT_SUPPORTED (422), atau alasan penolakan resolver P3 (403).
Caller HTTP memberikan pesan generik tanpa payload/PII. Endpoint legacy umum
mengembalikan CHECKOUT_CONTRACT_REQUIRED (403); dedicated selection tetap
memakai bentuk error INTEGRATION_UNAVAILABLE (503) existing. Tidak ada fallback
otomatis ketika caller menerima error tersebut.

## Cutover tanpa fallback

Baris IntegrationSource `contract_version = checkout-v2` menjadi penanda cutover
eksplisit untuk pasangan organisasi + source_system, di semua client lembaga.
Jangan membuat baris ini di database aktif sampai alur baru siap dan perpindahan
disetujui. Baris v1 boleh dipertahankan sebagai histori, tetapi tidak dipakai lagi
untuk provisioning/replay sumber itu. Status DRAFT/SUSPENDED/RETIRED atau flag
checkout OFF tidak membuka fallback; matikan layanan baru bukan kembali ke ready
legacy. Menghapus/mengganti versi penanda juga bukan prosedur rollback yang aman.

Jalur seleksi dedicated memiliki sumber tetap SELEKSI_BEASISWA_JEPANG dan pemetaan
branch_ref server. Guard memakai pasangan itu, bukan domain/Referer kiriman.
Cabang/sumber lain yang belum dipindahkan tetap memakai perilaku v1 existing.
Guard hanya boleh dijalankan dalam service RLS context. Tanpa context tersebut,
ia melempar LogicException sebelum query, bukan menganggap hasil yang disembunyikan
RLS sebagai tidak ada penanda. Kedua action provisioning legacy menyiapkan context ini.
Data historis/akses tes existing tidak diubah; guard ini khusus provisioning,
bukan pencabutan hak lama. Cutover konkuren tidak dilakukan saat writer aktif:
jeda ingress dan drain request/worker sebelum perubahan registry. Bukti race
reservasi/provisioning internal tidak menjadi izin cutover pada sumber aktif.

Threat model: cegah caller memilih organisasi lain, menyuntik paid/harga,
menggunakan label sponsored sebagai akses gratis, atau downgrade/replay lewat
jalur ready lama. Autentikasi HMAC existing tidak diubah. Tidak menambah secret,
callback URL, data sensitif baru, atau izin admin.

## Pemanggil P9 dan checkpoint

P9a internal dan adapter HTTP P9b belum berarti route publik terdaftar atau
checkout end-to-end tersedia. Tes P9b memakai HMAC dan database sintetis; bukan
izin cutover. Flag P5 bukan sakelar untuk membuka endpoint yang belum dipasang.
Pemindahan sumber aktif memerlukan persetujuan terpisah dan runbook setelah alur
end-to-end terbukti. No-store untuk seluruh pipeline error sebelum controller
perlu ditinjau pada tahap wiring; jangan mengklaim header controller mencakupnya.
Lihat [bukti checkpoint](ORGANIZATION_CHECKOUT_VALIDATION.md).
