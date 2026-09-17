# Proposal pemetaan profil checkout — DRAFT internal

Tanggal: 2026-09-01. Status: **PROPOSED, menunggu review sebelum implementasi**.
Ini rancangan adapter presentasi P16-prep, bukan kontrak HTTP final, ADR baru,
request yang sudah tersedia, atau izin membuka checkout. Tidak ada mapping
executable, perubahan tipe produksi, endpoint, atau writer dalam deliverable ini.
Pasangan fixture lobby e7a0fd3/0e727db masih menunggu integrasi/review koordinator;
proposal ini independen dari pasangan tersebut.

## Landasan dan batas keputusan

Sumber induk dibaca read-only pada HEAD `0b3c739af2ffff4e7e620f4414114633185d2ebf`:

- [ADR-004](../../../docs/decisions/0004-checkout-partial-profile.md): enam kolom
  profil boleh NULL, email sudah nullable, NULL bukan profil sah, tanpa placeholder
  UMUM atau akses dini. intendedField opsional adalah increment kontrak terpisah.
- [SPEC-integrated-checkout](../../../SPEC-integrated-checkout.md), bagian alur,
  penguncian, success criteria: sumber/cabang/paket tepercaya dari server; isi hanya
  kekurangan; koreksi data terkunci lewat sumber/admin; paid bukan bukti akses.
- [todo P14/P15/P16](../todo.md): P14 memiliki sesi privat/principal attempt;
  P15 memvalidasi pengisian kekurangan dan consent; P16 baru menghubungkan presentasi.
- [Request provisioning](../../../app/Http/Requests/ProvisionCheckoutParticipantRequest.php),
  [CheckoutContractAdapter](../../../app/Services/Integrations/CheckoutContractAdapter.php),
  [prasyarat akses](../../../app/Services/ParticipantAuth/AssessmentAccessPrerequisites.php),
  [validasi registrasi publik](../../../app/Http/Requests/StoreParticipantRegistrationRequest.php).
  Adapter integrasi existing memutuskan policy payer; bukan serializer profil.
- [Tipe presentasi](../../../resources/js/types/integrated-checkout.ts),
  [CheckoutProfile](../../../resources/js/components/integrated-checkout/checkout-profile.tsx),
  [CheckoutForm](../../../resources/js/components/integrated-checkout/checkout-form.tsx),
  [IntegratedCheckout](../../../resources/js/components/integrated-checkout/integrated-checkout.tsx),
  dan [audit nullable frontend](frontend.md), khususnya temuan #3.

Saat request induk dibaca, allowlist masih enam key tanpa intendedField; backend
sedang menambah intendedField opsional saja. Proposal tidak menyatakan increment
tersebut sudah terintegrasi, tidak menyunting request, dan tidak menganggap request
provisioning sebagai response summary/kontrak submit P15. Validasi legacy tetap utuh.

## Satu adapter di server, bukan pembuat aturan di browser

Rekomendasi lokasi **yang belum dibuat**:
`app/Services/Checkout/CheckoutSummaryPresenter.php`. Adapter read-only ini dipanggil
oleh P14 setelah otorisasi, memakai keputusan kelengkapan/update milik P15. Jangan
menambah mapper kedua di React atau mengubah CheckoutContractAdapter integrasi
menjadi serializer UI. Tujuannya agar null/missing, options, required, label enum,
serta kepemilikan attempt tidak mempunyai dua sumber aturan.

P14 membangun snapshot minimum dari participant/attempt yang benar di bawah scope
principal dan organisasi server. Session/route/referral/browser tidak boleh memasok
ulang participant ID, cabang, paket, harga atau klaim verified sebagai sumber kebenaran.
Data integrasi eksternal harus telah melalui autentikasi, validasi dan pemetaan
persisted; keberadaan sebuah string di DB sendiri bukan bukti identitas/consent sah.

P15 menetapkan keputusan per field: nilai dikenal dan terkunci, benar-benar kosong
dan boleh dilengkapi, atau tidak aman ditampilkan sebagai data sah/perlu review.
Presenter mengubah keputusan itu menjadi props, tidak mengotorisasi update, menulis
DB, menghitung harga, menerbitkan credential atau mengaktifkan entitlement. Browser
hanya merender props dan mengumpulkan input; modifikasi props di DevTools tidak
mengubah keputusan server. P15 memeriksa ulang nilai persisted dan izin saat submit.

## Interface DRAFT minimum (notasi desain, bukan file tipe baru)

Operasi internal usulan: `present(AuthorizedCheckoutSnapshot) → IntegratedCheckoutProps['screen']`.
Nama input adalah konsep, bukan class/DTO atau respons HTTP yang telah disetujui.

| Bagian input internal | Isi minimum dan pemilik |
| --- | --- |
| Scope | Participant + attempt + organisasi telah diotorisasi P14; bukan ID bebas dari browser. Tidak perlu menyalin seluruh model/atribut ke props. |
| Profil | Ketujuh key selalu hadir; nilai canonical persisted berupa string/tanggal domain atau null. Omission saat provisioning diterjemahkan writer ke data belum diketahui; omission key dalam snapshot internal justru kesalahan kontrak. |
| Keputusan field | Policy server P15 untuk setiap key: locked / missing yang boleh diisi / review. Required enam field non-email; email opsional. Keputusan tidak diambil dari array key yang dikirim peserta. |
| Metadata presentasi | Label yang disetujui, input kind, katalog enum/options dan format tanggal dari kode/katalog server yang sama dengan validasi. Bukan options dari sumber eksternal bebas. |
| Summary lainnya | formKey/revisi, sourceName, branchName, packageName, attemptLabel, identityMessage, payment, access, consents yang sudah diproyeksikan secara privat oleh domain pemiliknya. Bukan seluruh model bill atau hasil klinis. |

Untuk input konsisten, output ready memakai **CheckoutSummary existing** dengan
profile tepat tujuh field unik dalam urutan tabel berikut. Untuk data tidak sah,
katalog tidak diketahui, scope invalid atau kombinasi tak terwakili, jangan
menghasilkan ready yang tampak lengkap: arahkan ke screen error/expired yang aman
sesuai hasil P14. Kode/status HTTP dan bentuk error wire belum ditetapkan di sini.

Union **locked tetap `{ state: 'locked'; displayValue: string }`**. Nilai tampil
harus nonblank; jangan memperlebar menjadi null, menyisipkan `'-'`/`'null'`/placeholder,
atau menghilangkan key agar validasi presentasi terlihat lolos. Nilai null/blank
menjadi missing bila server mengizinkan pengisian; bila izin ditolak, union saat ini
tidak mewakili field kosong read-only. Tahan ready dan minta review, bukan membuka
input yang tampak dapat disimpan atau memalsukan locked.

## Pemetaan seluruh field

Semua baris memakai aturan sama: null, `''`, atau whitespace-only sesuai normalisasi
server → missing, tanpa menghapus key. Nilai nonblank **valid** → locked dengan
displayValue nonblank. Nilai nonblank invalid berbeda dari missing: jangan dikosongkan
diam-diam atau dibuka sebagai koreksi peserta; server meminta perbaikan sumber/admin.
Pemetaan ini tidak menulis hasil trim/format kembali ke data sumber.

| Key / kolom | Bila missing | Bila dikenal dan locked; asal format/options |
| --- | --- | --- |
| fullName / full_name | required=true, input=text, autocomplete=name | Tampilkan nama canonical yang diterima server; pertahankan ejaan, aksara, kapitalisasi dan isi lengkap. Jangan title-case atau memotong karakter. Label DRAFT: Nama lengkap. |
| birthDate / birth_date | required=true, input=date, autocomplete=bday | Server memvalidasi tanggal kalender dan aturan DOB sebelum format. Input P15 yang diusulkan date-only Y-m-d; output label tanggal Indonesia dari formatter server, tanpa konversi UTC/browser yang menggeser hari. Jangan parse null menjadi hari ini/epoch. Label: Tanggal lahir. |
| gender / gender | required=true, input=select | Domain persisted `female`/`male`; provisioning memakai `FEMALE`/`MALE`. P9a/P15 harus memiliki pemetaan eksplisit pada batasnya, bukan case coercion client. Options `{value,label}` dari enum/validator server yang sama; rekomendasi value P15 canonical lowercase, menunggu review kontrak. Label Indonesia mengikuti copy existing Perempuan/Laki-laki, disahkan server. |
| educationLevel / education_level | required=true, input=text | Existing source adalah string, bukan enum pendidikan yang telah disepakati. Pertahankan nilai valid sebagai teks; jangan menebak padanan SMA/SMK atau membuat daftar select baru. Bila kelak ada katalog, review kontraknya dahulu. Label: Pendidikan terakhir. |
| intendedField / intended_field | required=true untuk completion, input=select | Enum existing KAIGO, KENSETSU, NOUGYOU, SEIZOU, GAISHOKU, UMUM. Options/label dari katalog server yang selaras validator; form publik hanya rujukan copy, bukan authority checkout. Tidak infer dari paket/cabang. UMUM hanya boleh locked jika benar-benar dipilih/dikirim sah, bukan default data hilang. Label: Bidang tujuan. |
| email / email | required=false, input=email, autocomplete=email | Email valid ditampilkan tanpa diminta ulang. Null tetap missing opsional. Tidak menebak alamat, tidak memakai email untuk merge identitas lintas organisasi. Label: Email. |
| phone / phone | required=true, input=tel, autocomplete=tel | Pertahankan string valid, termasuk `+`/nol awal. Jangan cast angka, menambah kode negara, menebak negara dari cabang atau mengasumsikan nomor terverifikasi WhatsApp. Label: Nomor telepon. |

Label di tabel adalah usulan copy internal, bukan penggantian naskah server.
Options select wajib nonempty, value unik, dan label nonblank; tanpa katalog yang
disetujui jangan membuat options sementara atau mengunci kode enum tak dikenal.
Encoding HTML dilakukan oleh rendering React biasa, tanpa raw HTML untuk nilai/label.

Aturan validasi P15 harus diputuskan dari sumber server, bukan diimplementasikan
ulang di adapter: checkout request/gate meminta DOB sebelum hari ini, sedangkan
registrasi publik menerima hari ini; pola phone kedua request juga berbeda.
Gunakan aturan checkout yang disahkan P15 dan pertahankan legacy, bukan menyamakan
keduanya dengan perubahan diam-diam. Panjang/bentuk nama/email/pendidikan mengikuti
validator P15 yang disepakati; array/object/tipe scalar lain invalid, bukan stringified.

## Kelengkapan, penguncian, dan submit

Jika tujuh nilai lengkap/valid, profile berisi tujuh locked; tidak ada input identitas
ulang. Consent atau verifikasi identitas yang kurang tetap harus ditangani terpisah.
Jika enam field wajib lengkap tetapi email kosong, tampilkan missing email opsional
tanpa menyatakan persyaratan profil belum terpenuhi atau memaksa pengisian email.

**Gap UI yang perlu review sebelum wiring:** CheckoutProfile saat ini memakai
`missing.length` untuk pesan lengkap; CheckoutForm juga memakai semua missing untuk
needsConfirmation. Usulan minimal nanti: copy membedakan required missing vs hanya
email opsional (misalnya "Data wajib sudah lengkap; email opsional"). Konfirmasi
wajib didorong required missing/consent; pengisian email tetap pilihan terpisah yang
bisa disimpan bila diisi, tanpa memaksa submit kosong. Derivasi copy/dirty state di UI
boleh dari metadata server; itu bukan keputusan izin update. Jangan menyelesaikan
gap ini dengan membuang key email atau memberi locked displayValue palsu. Tidak ada
perubahan komponen atau props baru yang disahkan oleh dokumen ini.

Callback existing `CheckoutConfirmation` adalah intent internal, **bukan body HTTP
P15 final**. Ia hanya membawa missingProfile dan consent eksplisit/versioned.
CheckoutForm saat ini mengirim setiap key missing dengan string input atau `''`:
untuk email opsional kosong, P15 perlu menerima omission atau blank sebagai tidak
ada perubahan (tetap null), bukan menyimpan placeholder dan bukan mengosongkan
email yang sudah terisi. Required blank harus ditolak server. Kesepakatan normalisasi
ini perlu diuji; jangan mengubah tipe callback ke nullable atau memilih transport kini.

P15 menghitung ulang allowlist dari snapshot persisted terkini dalam transaksi
update: reject key tak dikenal/locked, bahkan bila nilai kiriman sama. Bila field
berubah dari missing menjadi locked oleh proses lain, jangan overwrite; kembalikan
hasil stale yang aman dan refresh summary. Skema wire status/revisi belum final.
formKey adalah kunci reset presentasi, bukan secret atau izin. P14/P15 perlu
merevisinya saat attempt/keputusan field berubah agar input lama tidak dikirim ke
summary baru; reset versi consent existing tetap dipertahankan. Replay tidak boleh
mengosongkan data lengkap atau membuat consent/order/akun kedua.

Cabang, organisasi, sumber, paket, konsultasi, payer, harga, paid, verified, state
missing/locked dan required/options bukan bagian writable missingProfile. Menyembunyikan
kontrol saja tidak cukup: kiriman manipulatif ditolak P15, termasuk hidden field atau
request buatan sendiri. Koreksi field locked memakai workflow sumber/admin existing.

## CheckoutSummary dan dependensi sebelum wiring

P14 memasok sumber/cabang/paket/attempt milik principal dengan nama nonblank dari
registry/snapshot server; jangan isi dari query/referrer/cookie. Payment hanya biaya
dan status attempt sendiri; nominal dari domain billing, bukan penjumlahan client,
harga hardcode, total batch, URL invoice cabang atau bukti bayar lembaga.
amountIdr null berarti belum tersedia, **bukan gratis**. paid/free tidak menghasilkan
access ready; access berasal dari gate server dengan profil, identitas, consent dan
settlement terkini. Presenter tidak memanggil aktivasi atau mengirim notifikasi.

**Gap terpisah:** ADR-004 membolehkan payer belum dipilih pada provisioning, tetapi
CheckoutPayment DRAFT hanya memiliki payer self/organization. Jangan default ke self,
organization atau harga nol. P14/P15 harus menyepakati representasi/flow pemilihan
payer terlebih dahulu; sampai itu tersedia, jangan membangun ready summary palsu
untuk kondisi tersebut. Penyelesaian gap bukan bagian mapper profil ini.

Consent tetap dari bukti versioned server, tanpa precheck atau inference dari profil
lengkap. legalReviewPending tetap menahan konfirmasi lewat UI dan handler existing;
server juga harus memvalidasi kebijakan. DASS pilihan terpisah, bukan syarat akses tes
lain, dan tidak dikirim ke pembayar. Profil locked tidak berarti identity verified.

Prasyarat sebelum implementasi/wiring:

1. Review proposal ini, termasuk aturan P15, optional email/copy, nilai enum submit,
   normalisasi blank, dan penanganan stale/revisi formKey.
2. P14: sesi checkout purpose-bound/principal attempt, RLS/IDOR, CSRF, no-store dan
   no-referrer; proyeksi minimum dan kontrak summary/error privat ditinjau.
3. P15: validasi missing-only persisted, koreksi lewat sumber/admin, transaksi/race,
   consent versioned dan re-evaluasi akses setelah syarat lengkap tanpa invoice ulang.
4. Kontrak intendedField opsional dan writer P9a yang mempertahankan nilai lengkap
   ditinjau; absence tetap null sampai peserta memilih sah. Jangan menunggu mapper
   frontend untuk mengisi kekosongan schema/writer dengan asumsi.
5. Metadata label/options server dan state payer belum dipilih diselesaikan oleh
   pemiliknya. Setelah itu baru adapter server, tes kontrak, lalu wiring P16 terpisah.

## Matriks uji yang diusulkan — belum dijalankan

Semua data berikut sintetis. Kolom hasil adalah acceptance masa depan, bukan klaim
implementasi; uji server/persistensi dan browser harus dipisahkan.
Untuk kasus khusus profil, asumsikan sesi P14 terotorisasi dan konteks summary
lainnya valid, termasuk payer yang telah diputuskan. Kasus payer belum dipilih
dan scope invalid diuji tersendiri, bukan dilewati agar matriks profil bisa dirender.

| Kasus | Input/tindakan konkret | Hasil yang wajib dibuktikan |
| --- | --- | --- |
| Semua kosong | Ketujuh nilai null; ulangi dengan empty, spasi/tab/newline | Tujuh key unik missing; enam required, email false; tidak ada locked kosong/placeholder. Tidak lengkap, akses locked. |
| Snapshot cacat | Key phone hilang, fullName ganda, atau field tak dikenal | Tidak menghasilkan ready summary; jangan menyebut profil lengkap akibat key hilang. |
| Campuran | fullName=Nadia Contoh, gender=female, pendidikan=SMA; DOB/bidang/phone/email null | Tiga locked dipertahankan, empat missing; input/payload hanya empat missing, email opsional. |
| Seluruhnya lengkap | Tujuh nilai valid, termasuk email contoh@example.test | Tujuh locked; tidak ada form identitas ulang; missingProfile kosong bila hanya mengonfirmasi consent. Tidak auto-consent/akses. |
| DOB tidak sah | 2001-02-30, hari ini, tanggal masa depan, atau object | Tolak nilai invalid di boundary P15; bila persisted invalid jangan format tanggal palsu/ubah menjadi missing editable. Minta review sumber/admin. |
| DOB sah | 2000-02-29 dalam timezone berbeda di browser | Hari kalender sama; label dari server, input date-only; tidak bergeser ke 28 Februari. |
| Enum tidak sah | gender=UNKNOWN; intendedField=OTHER | Ditolak server, tidak dibuatkan opsi fallback. Alias/casing selain pemetaan resmi tidak diterima diam-diam. |
| Bidang belum ada | Semua valid kecuali intendedField null/omitted saat provisioning | Summary tetap memuat intendedField missing required; tidak otomatis UMUM. Opsi sah dari server; pilih UMUM eksplisit berbeda dari null. |
| Katalog tak siap | Opsi bidang kosong, value duplikat, atau label blank | Jangan render select yang tampak valid tanpa pilihan sah atau locked kode tak dikenal; summary error aman. |
| Hanya email kosong | Enam required lengkap, email null; consent sudah sah | Email missing optional; pesan data wajib lengkap, tidak wajib submit/email untuk akses. Gate tetap menilai syarat lainnya. |
| Email opsional diisi | Email missing diberi contoh@example.test / tidak-sah | Yang valid tersimpan lewat P15 dan menjadi locked; invalid ditolak tanpa menulis field lain. Omission/blank adalah no-op jika tetap kosong. |
| Phone/nama dipertahankan | Phone valid berawalan +/0, nama beraksara non-ASCII | Display string tidak diubah menjadi angka/title-case; tidak diminta ulang. Tidak dianggap WA/identitas terverifikasi. |
| Locked dibajak | Kirim fullName baru ketika server locked; ulangi dengan nilai sama | P15 menolak key locked, tidak overwrite/merge; UI read-only bukan satu-satunya guard. |
| Batas summary dibajak | Tambahkan branchId/organizationId/packageId/payerType/amountIdr/paid/consultation ke body atau missingProfile | Semua perubahan di luar allowlist ditolak; persisted cabang/paket/payer/harga/akses tetap. Tidak ada invoice baru. |
| Metadata UI dibajak | Ubah field.state ke missing, required=false, atau options di DevTools | Request tetap diverifikasi memakai policy persisted; tidak bisa menulis locked/enum liar atau melewati required. |
| Race/stale | Phone missing di summary A, diisi proses lain sebelum submit A | Tidak menimpa phone baru; refresh menghasilkan locked dan formKey baru; input lama tidak diputar ulang. |
| Paid tetapi belum siap | Paid/free, phone atau consent/identity belum sah | Summary tetap locked untuk akses; hanya melengkapi syarat; tidak bayar/invoice ulang. |
| Payer belum dipilih | Policy server masih requiresSelection; funding_mode null | Tidak mengarang CheckoutPayment self/organization/free; safe non-ready sampai flow resmi direview. |
| Privasi/scope | Token attempt lain, role salah, batch berisi peserta lain | P14 menolak scope; response tidak memuat profil lain, total batch, invoice/proof organisasi atau klinis. |
| Pesan vs payload | Profil null sebagian → isi → summary baru; lalu email saja kosong | Pesan required lengkap konsisten, tujuh key tetap ada, payload hanya missing yang diizinkan; data locked tidak diminta ulang. |

Review statis saja dilakukan untuk dokumen ini. Tidak menjalankan browser/build/
full suite/DB, tidak membuat tes atau adapter executable, dan tidak menyatakan
P14/P15/P16 selesai. Langkah selanjutnya hanya setelah review koordinator.
