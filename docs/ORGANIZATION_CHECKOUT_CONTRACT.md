# Kontrak checkout opt-in (P5)

## Status dan batas

Kontrak internal `checkout-v2` disiapkan untuk provisioning P9. Belum tersedia
endpoint checkout publik. Validasi/adaptasi tidak membuat participant, attempt,
invoice, entitlement, consent, atau outbox. Harga bukan input integrasi.

## Input

Gunakan field camelCase seperti kontrak assessment v1:

- Wajib: `contractVersion` persis `checkout-v2`, `sourceSystem`,
  `organizationCode`, `externalCandidateId`, `assessmentPackageCode`, `profile`.
- ID opsional: `externalProcessId`, `externalRegistrationId`, `assessmentRoundId`.
- `profile` menerima hanya fullName, birthDate, gender, educationLevel, email,
  phone. Object kosong/field null diperbolehkan sebagai profil parsial; nilai
  yang diberikan tetap wajib valid. Ini bukan bukti identitas atau persetujuan.
- `payerType` opsional/null atau tepat `self`/`organization`. Tanpa pilihan,
  resolver P3 dapat memilih satu pilihan efektif atau meminta pilihan peserta.
- `fundingMode` hanya untuk adapter legacy eksplisit: COMMERCIAL_SELF_PAY → self,
  INVOICED_TO_ORGANIZATION → organization. Flag mapping server harus true dan
  field payerType tidak boleh sekaligus dikirim (termasuk null). SPONSORED,
  INTERNAL, WAIVED tidak pernah dipetakan. Label legacy bukan bukti paid/gratis.
- `metadata` hanya menerima cohortCode. Field tambahan pada root/profile/
  metadata ditolak, termasuk paid, amount, branchId dan consent.
- Idempotency-Key wajib valid sebelum provisioning P9; request helper memeriksa
  karakter/ukuran, tetapi P5 tidak mengklaim kunci atau membuat respons replay.

Validasi memakai Form Request dan allow-list array seperti
[dokumentasi Laravel](https://laravel.com/docs/13.x/validation#validating-arrays).

## Gerbang dan keluaran

`assessment_integration.checkout.enabled` default false. Payer mapping legacy
memerlukan `assessment_integration.checkout.allow_legacy_funding_mapping` true
terpisah. Keduanya konfigurasi server; tidak dibaca dari payload.

`CheckoutContractAdapter::resolve(client, source, package, input)` menerima input
tervalidasi serta model registry yang dipetakan server. Ia memeriksa versi,
pemetaan organisasi/sumber/paket, lalu memakai ResolvePayerPolicy. Keluaran
PayerDecision bukan izin tes atau lunas. Pemanggil persistence berikutnya wajib
reload registry dalam transaksi/RLS dan memenuhi gate identitas/consent/harga.

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
RLS sebagai tidak ada penanda. Kedua action provisioning menyiapkan context ini.
Data historis/akses tes existing tidak diubah; guard ini khusus provisioning,
bukan pencabutan hak lama. Cutover konkuren tidak dilakukan saat writer aktif:
jeda ingress dan drain request/worker sebelum perubahan registry; uji race
reservasi merupakan tahap berikutnya, bukan klaim P5.

Threat model: cegah caller memilih organisasi lain, menyuntik paid/harga,
menggunakan label sponsored sebagai akses gratis, atau downgrade/replay lewat
jalur ready lama. Autentikasi HMAC existing tidak diubah. Tidak menambah secret,
callback URL, data sensitif baru, atau izin admin.

## Pemanggil P9 dan checkpoint

P9 wajib menghubungkan request → reload registry → adapter → persist PROVISIONED
dalam transaksi, dengan idempotensi atomik dan gate publik terpisah. Flag P5
bukan sakelar untuk membuka endpoint yang belum dibuat. Pemindahan sumber aktif
memerlukan persetujuan terpisah dan runbook setelah alur end-to-end terbukti.
Lihat [bukti checkpoint](ORGANIZATION_CHECKOUT_VALIDATION.md).
