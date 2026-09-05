# Rencana: pembayaran lembaga dan checkout terintegrasi

Tanggal: 2026-08-31
Status: **P1–P13b, core privat P14/P15, dan P17a–P17b selesai lokal. P16-pay-a–i, binding Blade/UI summary-v2, subset consent current, serta privacy audit selesai lokal dan default OFF; DASS-21 wajib pada ingress/snapshot/handoff/session/form. Bukti terbaru: Blade 19+7, Node 4, HTTP/PHP 38/684, PostgreSQL fresh 400/3.950, beserta PHPStan/ESLint/Prettier/Pint/diff-check. Runtime browser/P17c belum dijalankan dan checkbox P15/P16 tetap terbuka. DASS consent tetap terpisah dan RLS privat. Tidak ada deploy, migrasi DB aktif, invoice/notifikasi nyata, provider/outbound, atau cutover sumber/feature flag.**

## Checkpoint P16 consent subset dan privacy audit — 2026-09-06

`918eb93` membuat audit konfirmasi/re-consent service-only melalui policy RLS
restrictive. `ae54cd6` membuat presenter, request, writer, Blade, dan transport
hanya menerima subset requirement current, mendukung histori konfirmasi
bergenerasi untuk withdrawal/rotasi dokumen, serta mengunci endpoint dan
provenance pembayaran secara literal/fail-closed.

Root lulus Blade **19+7**, Node **4/4**, PHP/HTTP **38/684**, PostgreSQL
disposable **2/30**, PHPStan 0 error, Pint, ESLint, Prettier, dan diff-check.
Fitur tetap default OFF dan browser P17c belum dijalankan.

## Checkpoint P16 binding Blade/UI summary-v2 — 2026-09-05

`4110213` memperketat helper browser agar hanya memakai fixture dengan DASS wajib.
`cc69179` mengikat Blade pada payload `checkout-summary-v2` dan merender pilihan
pembayaran dari action/harga server tanpa menjadikan client authority. `87cf221`
menyelaraskan suite HTTP ke script v2, sedangkan `0f46e29` menjaga consent DASS
required pada form checkout.

Root membuktikan Blade **17+5 tes**, transport Node **7**, React SSR **28**, dan
HTTP **34 tes / 942 assertions**. TypeScript, ESLint, Prettier, Pint, serta
diff-check lulus. Binding Blade/UI selesai lokal, tetapi payment tetap default
OFF. Runtime browser/P17c belum dijalankan, sehingga acceptance P15/P16 tidak
ditutup. Tidak ada deploy, provider nyata, database aktif, outbound, migrasi
aktif, atau feature flag yang dinyalakan.

## Checkpoint P16 summary-v2 dan DASS lifecycle — 2026-09-05

Lima commit diterima sebagai satu checkpoint server/frontend lokal. `cac65f1`
menolak handoff issuance, consume, dan recovery yang tidak membawa komposisi
psikotes+DASS-21 canonical; `ce036bb` menyelaraskan fixture checkout bersama.
`04e1499` menetapkan tipe summary-v2 yang strict. `edebc7c` mewajibkan evidence
pembayaran canonical dan metode Xendit persisted pada replay pending, tanpa query
baru. `1547501` memproyeksikan pilihan/action harga IDR dari graph lifecycle dan
snapshot server tanpa ID, batch, reference, atau URL provider.

Root gabungan lulus **207 tes / 2.189 assertions**, handoff **29/385**, dan
frontend **8/8** plus TypeScript, lint, serta format. Ini menyelesaikan kontrak
summary-v2 dan defense-in-depth DASS pada server, bukan P16 secara keseluruhan.
Binding Blade/UI diterima pada checkpoint berikutnya di atas. Tahap yang tersisa
adalah runtime browser/P17c; payment masih literal default OFF. Checkpoint tidak
mencakup deploy, migrasi DB aktif, provider/outbound nyata, atau aktivasi sumber/
feature flag.

## Perubahan lingkup: pembayaran kolektif cabang

Pengguna meminta satu pembayaran untuk banyak peserta cabang, bukan invoice
yang harus dibayar satu per satu. Menu peserta/tagihan/riwayat cabang termasuk
lingkup. Acuan revisi adalah `SPEC-organization-billing.md`; batas lama yang
menunda tagihan gabungan dicabut. Tidak ada perubahan modul/dependensi utama.

Pengguna menyetujui satu cabang per tagihan, pelunasan seluruh total, dan daftar
terkunci melalui jawaban "lanjutkan". Tugas di todo.md kini dipecah menjadi
increment maksimal sekitar lima file. P1–P3 tetap hasil kerja lokal yang sah;
hasil tesnya bukan bukti pembayaran kolektif selesai. Persetujuan teknis terbaru
mengizinkan implementasi bertahap dan pengujian lokal.

| Kelompok lama | Dampak yang wajib masuk revisi tugas |
| --- | --- |
| P4–P5 | Policy ONCAM, opt-in, dan pemilihan pembayar tetap; organization tidak langsung membuat invoice individual |
| P6–P7 | Pisahkan tagihan induk dari biaya/attempt; FK/RLS, reservasi atomik, dan cegah klaim batch/mandiri ganda |
| P8–P9 | Akses per attempt dari alokasi lunas + consent/identitas, bukan total batch saja |
| P10–P11 | Satu reference/invoice induk; rekonsiliasi dan pelunasan seluruh alokasi secara konsisten/idempotent |
| P12 | Seleksi banyak peserta, ringkasan total, detail tagihan, upload bukti, dan riwayat cabang |
| P13–P16 | Handoff/status peserta sendiri tanpa mengekspos anggota atau invoice kolektif |
| P17–P18 | Uji race antarbatches/mandiri, crash/replay, privasi, browser kolektif, dan runbook rekonsiliasi |

Tabel ini memetakan tugas lama ke rincian revisi di todo.md. Sufiks a/b/c
memecah pekerjaan tanpa mengganti arti bukti P1–P3. Tugas billing lama yang
belum selesai digantikan rincian revisi, bukan dicentang selesai.

## Lingkup dan status persetujuan

Pengguna meminta melanjutkan setelah usulan lokasi terpisah. Rencana ini berada
di `tasks/organization-payment/`; `tasks/plan.md` dan `tasks/todo.md` F1 tidak
diubah. Persetujuan lingkup fungsional menjadi dasar perencanaan, bukan bukti
fitur selesai. Plan awal mendasari P1–P3; persetujuan lingkup kolektif terbaru
tidak otomatis menyetujui rincian teknis revisi di bawah. Setelah rincian revisi
diserahkan, pengguna menjawab "lanjhutkan" untuk memulai implementasi.
Rincian migrasi/cutover tetap harus diverifikasi sebelum tahap terkait dijalankan.

Sumber: `CAPABILITY-MAP-organization-payment.md`, `SPEC-funding-policy.md`,
`SPEC-organization-billing.md`, dan `SPEC-integrated-checkout.md`.
Checklist berada di [todo.md](todo.md). Validasi rincian tugas sebelum implementasi
per modul; persetujuan tidak mencakup perubahan sistem eksternal.

Hanya dua pilihan: bayar sendiri atau dibayar lembaga. Harga paket/konsultasi
berasal dari database dalam IDR. Tagihan gabungan satu cabang termasuk lingkup.
Dana talang, cicilan, tempo, refund, skoring dan perubahan sistem seleksi eksternal
tidak termasuk.
Lembaga pembayar tahap awal adalah organisasi sumber yang terautentikasi;
sponsor lintas organisasi memerlukan perluasan spesifikasi.

## Bukti kondisi kode saat penyusunan

- `ProvisionAssessmentParticipant` membuat entitlement ready tanpa order.
- `ProvisionSelectionParticipant` memiliki jalur akses ready legacy.
- Constraint entitlement lama unik pada participant + test_type; gate
  `ParticipantEntitlementGate::assertReady` mencari hanya dua atribut itu.
  Akses lama tidak boleh menjadi bukti lunas attempt baru.
- `Order` belum memiliki pembayar lembaga atau foreign key attempt.
- Status assessment yang diizinkan PostgreSQL tidak memuat WAITING_PAYMENT.
- Undangan assessment sekarang untuk mulai tes, bukan checkout sebelum bayar.
- Banyak file integrasi masih untracked/modified. Ini temuan working tree,
  bukan bukti image Docker publik menjalankan implementasi yang sama.

## Urutan dan dependensi

```text
funding-policy: konfigurasi -> keputusan server -> kontrol admin
  -> organization-billing: isolasi attempt -> reservasi mandiri/kolektif -> pembayaran/alokasi -> panel lembaga
    -> integrated-checkout: handoff terbatas -> ringkasan/consent -> checkout
      -> regresi lintas tenant, browser, dokumentasi dan review
```

Pengguna menyetujui tiga task worktree terpisah dengan task utama sebagai
koordinator pada 2026-08-31. Ini menggantikan larangan task paralel sebelumnya.
Schema, gate akses, dan billing tetap satu pemilik dan berurutan; persiapan UI
peserta dan portal baca-saja dapat berjalan bersamaan tanpa mengaktifkan alur
pembayaran. Batas file, dependensi, dan checkpoint: [parallel-work.md](parallel-work.md).
Setiap checkpoint tetap perlu bukti focused tests, build sesuai dampak, alur
slice, dan tinjauan pengguna. Persiapan UI tidak menutup P12/P16 end-to-end.

## Usulan teknis untuk ditinjau

### 1. funding-policy: pisahkan pembayar dari kanal pembayaran

Tambahkan konfigurasi payer pada Branch dan IntegrationSource secara additive:
allowed_payer_types (self/organization), serta locked_payer_type pada sumber.
Gunakan null untuk konfigurasi existing yang belum dipetakan; null tidak memberi
izin checkout baru. Organisasi baru default self saja; organization OFF.
Sumber baru jalur checkout tetap nonaktif sampai dikonfigurasi admin ONCAM.

Keputusan server adalah irisan organisasi, sumber aktif, dan paket yang diizinkan.
Simpan snapshot keputusan saat order dibuat. Menonaktifkan opsi menolak order
baru; pembayaran sah dan penyelesaian order pending existing tetap dihormati.
Unknown/forged mode ditolak; konfigurasi diaudit. Otorisasi bukan sekadar UI.

Usulkan kontrak checkout versi baru yang opt-in, terpisah dari v1.
COMMERCIAL_SELF_PAY dapat dipetakan eksplisit ke self dan
INVOICED_TO_ORGANIZATION ke organization pada adapter yang disetujui.
SPONSORED/INTERNAL/WAIVED tidak dipetakan otomatis dan tidak membuktikan paid.
Existing v1 tetap tercatat sebagai legacy, tidak diklaim memenuhi aturan baru.
Cutover sumber memerlukan koordinasi; tidak menyisakan fallback v1 bagi sumber
yang sudah dipindahkan. Tidak mengubah arti atau data historis massal.

### 2. organization-billing: tagihan induk dan alokasi attempt

Usulan additive: tabel baru berikut, tanpa menjadikan satu Order legacy sebagai
wadah sepuluh peserta atau mengubah arti handler pembayaran legacy.

| Tabel usulan | Tanggung jawab dan constraint utama |
| --- | --- |
| assessment_charges | Satu biaya per assessment_participant_id (UNIQUE); FK attempt/participant/organization/package konsisten; snapshot komponen IDR integer, payer dan policy; free_settled_at hanya untuk total nol |
| assessment_bills | Induk invoice mandiri/kolektif; organization_id, payer_type, identitas pembayar, total, currency, public reference unik, status, kanal, gateway reference, bukti dan verifier; key idempotensi + hash payload dalam scope principal |
| assessment_bill_items | Keanggotaan dan alokasi: bill_id, charge_id UNIQUE, organization_id, nominal snapshot, settled_at; satu charge tidak pernah berada pada dua bill dalam tahap ini |
| assessment_entitlements | Hak per attempt + test_type UNIQUE, charge_id FK, status dan waktu; tidak fallback ke entitlement legacy |

Gunakan FK komposit/constraint untuk scope parent-child dan konsistensi
participant/organization; tidak cukup metadata atau filter UI. DDL final
ditinjau pada P6 dan dibuktikan dengan direct SQL negatif di PostgreSQL runtime.
RLS bill/item hanya ONCAM berwenang, service, atau cabang pemilik. Peserta
tidak mendapat SELECT ke batch; proyeksi status privat mengembalikan charge/
attempt miliknya saja tanpa identitas anggota lain atau invoice/bukti induk.

Bill self hanya satu charge dan pembayar peserta harus sama; bill organization
memuat charge organisasi sumber yang sama dan tidak punya pembayar peserta.
Untuk tahap awal, reservasi keanggotaan tidak dilepas otomatis saat expired atau
rejected; UNIQUE charge_id tetap mencegah penagihan ulang. Reinvoice memerlukan
prosedur baru yang terpisah, bukan menghapus item lama. Total nol tidak memiliki
bill berbayar; free_settled_at tidak menjadi jalan pintas consent.

Preview tidak mereservasi. Konfirmasi mengambil lock attempt/charge terurut,
memuat ulang policy dan snapshot harga, lalu membuat induk+seluruh item atomik.
Jumlah dan hash daftar canonical, payer, snapshot, dan kanal diperiksa; perubahan
harga/anggota sejak preview menolak konfirmasi untuk tinjau ulang. Unique key
dan hash payload menjaga retry; key sama dengan isi berbeda ditolak. Batch yang
saling tumpang tindih serta self vs batch memperebutkan claim charge yang sama.
Lock policy organisasi/sumber dan pembaruan P4 memakai urutan konsisten agar
OFF tidak berlomba dengan reservasi. Tidak ada request gateway di dalam lock.

Usulan batas operasional awal: maksimal 100 attempt per tagihan dari konfigurasi
server, bukan angka dalam komponen UI. Bukan harga/aturan skoring. Validasi
1..limit dilakukan server; uji 10, limit, limit+1, duplikasi, dan overflow total.
Tidak melakukan silent split menjadi beberapa pembayaran bila limit terlewati.

Bill expired/rejected ditampilkan sebagai terminal dan diarahkan ke petugas;
penggantian bill/cancel/reinvoice belum otomatis. Jangan membuat bill kedua
atau mengganti pembayar/paket saat bill aktif. Penanganan perubahan ini perlu
rancangan tersendiri jika diminta.

Gunakan status assessment PROVISIONED selama prasyarat belum lengkap; status
pembayaran dibaca dari charge/bill, bukan menambah enum assessment secara spontan.
Akses ready membutuhkan pembayaran sah atau total nol, consent yang berlaku,
dan prasyarat identitas. Lunas tidak berarti consent otomatis.
Gate jalur baru wajib context attempt terautentikasi, tidak fallback ke gate
participant + test_type. Token checkout tidak bisa dipakai sebagai token tes.

Invoice dibuat setelah transaksi reservasi bill selesai; gateway fake dahulu.
Reference baru memakai namespace AB_ + ULID (masih sesuai CreateInvoiceRequest),
bukan ID salah satu peserta. Normalisasi/authentication provider dipakai ulang;
dispatcher event memilih handler bill atau legacy secara eksplisit. Reference
AB_ yang tidak ditemukan ditolak, tidak fallback ke order lain. Processor event
tetap mengklaim event idempotent dan memvalidasi reference/nominal/currency.
Pemeriksaan status/reconciliation juga harus mengarah ke handler bill, tidak
hanya webhook. Jangan membuat callback fiktif individual untuk total batch.

Pembuatan invoice memiliki claim worker tunggal; status issuing/unknown tidak
mengizinkan POST invoice kedua. Timeout diidentifikasi dengan reference stabil,
ditelusuri ulang atau ditangani petugas. Kontrak provider yang ada mungkin perlu
operasi lookup read-only eksplisit; perubahan interface/adapter/fake diuji
sebagai satu increment P10a sebelum dipakai. Tidak mengasumsikan timeout berarti gagal.

Finalisasi mengunci bill dan item terurut, mencocokkan total dan seluruh charge,
lalu mencatat paid + semua settled_at + audit + outbox dalam satu transaksi.
Uji injeksi kegagalan setelah item kelima: tidak boleh tersisa 5 dari 10 lunas.
Aktivasi mengevaluasi consent/identitas per attempt; peserta belum memenuhi
syarat tetap lunas dan menunggu syarat, tidak ditagih lagi. Pengiriman pesan
setelah commit melalui outbox, kunci dedup per attempt/jenis event.
Gunakan reference stabil untuk retry/reconciliation dan cegah invoice ganda.
Pembayaran, aktivasi entitlement attempt, dan outbox harus atomik/idempotent.
Webhook Xendit tetap cocok token, nominal, currency, dan reference. Callback
expired terlambat tidak menurunkan paid. Jalur total nol tidak memanggil gateway.

Transfer manual batch diverifikasi SuperAdmin ONCAM pada tahap awal, bukan
BranchAdmin/Staff meskipun memiliki flag legacy can_verify_payments. Izin legacy
tidak diubah diam-diam. Delegasi verifikator ONCAM tambahan memerlukan aturan
eksplisit dan tes terpisah.
Panel lembaga hanya menampilkan ringkasan tagihan sendiri, kanal aktif, invoice/
unggah bukti sesuai izin; tidak memberi akses hasil klinis. Role ONCAM dan
lembaga harus dapat dibedakan secara eksplisit sebelum fitur diaktifkan.

### 3. integrated-checkout: ringkasan tanpa registrasi ulang

Handoff server-to-server memetakan identitas eksternal dalam scope sumber/
organisasi. Tidak menggabungkan orang lintas tenant dari email atau nomor WA.
Token checkout disimpan sebagai hash, berumur pendek, sekali konsumsi, tujuan
terbatas. Konsumsi menghasilkan sesi checkout dengan CSRF dan scope attempt.
No-store/no-referrer, token tidak dicatat di log atau diteruskan ke gateway.
Reissue terkontrol mencabut token lama dan tidak menggandakan order.

Profil lengkap langsung ke ringkasan + persetujuan. Profil kurang hanya meminta
field yang kurang; sumber/cabang/identitas terkunci tidak dapat diganti peserta.
Kontrak baru harus mengakomodasi profil parsial tanpa melonggarkan validasi v1.
DASS consent terpisah, bukan otomatis disetujui dan bukan syarat kelayakan kerja.

Self menuju kanal aktif; organization melihat menunggu pembayaran lembaga;
paid/gratis tidak membuat invoice ulang. Reload melanjutkan sesi/order yang sama.
Referral publik tetap first-touch; undangan peserta invalid tidak fallback
ke registrasi default. Kedua situs seleksi diuji dengan fake client dahulu.

## Verification dan lingkungan

Semua perintah berikut dijalankan dari root pada lingkungan development/test
terisolasi, bukan container publik. Pastikan PHP/dependency tersedia, config cache
test bersih, database test eksplisit, dan outbound gateway/notifier fake.
Jangan menjalankan composer setup atau migrasi reset terhadap database aktif.

```powershell
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
php vendor/bin/pint --parallel --test
php vendor/bin/phpstan analyse --no-progress
npm run lint:check
npm run types:check
npm run build -- --outDir storage/app/private/verification/organization-collective-build
```

Perintah focused tests baru tercantum dalam calon tugas; sebelum tes dibuat,
filter kosong bukan bukti lulus. Catat jumlah tes/assertion serta skip.
PostgreSQL RLS dan concurrency harus dibuktikan pada database khusus test:
role runtime bukan owner/BYPASSRLS, FORCE RLS, lintas tenant dan tanpa context
ditolak. Harness aman disiapkan sebelum migrasi uji; SQLite tidak menggantikannya.
Browser mobile/desktop: lengkap/parsial, self/organization/free, reload, token
expired/replay, consent ditolak, cabang terkunci, dan akses unpaid ditolak.

## Risiko dan mitigasi

| Risiko | Mitigasi / gerbang |
|---|---|
| Hak lama membuka attempt unpaid | Entitlement attempt terpisah, gate tanpa fallback, tes dua attempt |
| v1 memberi ready tanpa order | Versi opt-in, cutover eksplisit dan larangan fallback sumber v2 |
| Lembaga mengesahkan bayar sendiri | Policy ONCAM verifier + tes role/IDOR/RLS |
| Timeout/replay menagih dua kali | Reservasi unik, reference stabil, reconcile sebelum retry |
| Attempt masuk dua batch atau mandiri sekaligus | Claim per attempt, transaksi/constraint unik, uji concurrency PostgreSQL |
| Pembayaran induk hanya tercatat pada sebagian peserta | Alokasi atomik, uji crash/replay dan outbox idempotent |
| Peserta membuka daftar/bukti seluruh batch | Scope alokasi miliknya saja; RLS/IDOR parent-child dan minimalisasi respons |
| Consent terlewati karena sudah lunas | Gate gabungan, tes paid tetapi consent belum lengkap |
| Data aktif terkena test/migrasi | Harness test terpisah, guard target, tanpa deploy otomatis |
| Checklist F1 belum seluruhnya tertutup | Gerbang F1 tetap berdiri sendiri; tidak dicentang dari rencana ini |

## Persetujuan rencana dan batas pelaksanaan

Lingkup dan rencana teknis kolektif sudah disetujui: empat tabel baru terpisah
dari order legacy; satu claim permanen per charge selama belum ada reinvoice;
batas batch konfigurabel (usulan awal 100); SuperAdmin sebagai verifikator batch;
dispatcher/reconciliation khusus bill dan urutan tugas revisi di todo.md.
Persetujuan rencana hanya mengizinkan implementasi dan pengujian lokal,
bukan menjalankan migrasi data aktif, deploy, Xendit Live, invoice atau WA nyata.

## Bukti penyusunan dan pelaksanaan

Pada penyusunan awal hanya dokumentasi yang diubah. Pelaksanaan P1 menambah
harness test lokal; lihat ../../docs/ORGANIZATION_PAYMENT_TESTING.md untuk bukti.
Kode aplikasi publik, database aktif, harga, dan checklist F1 tidak diubah.

Pada giliran penyusunan revisi kolektif, hanya dokumen spesifikasi/rencana/tugas diubah.
Hasil P1–P3 merupakan bukti historis, bukan tes yang dijalankan ulang atau bukti
fitur kolektif selesai. Pemeriksaan revisi mencakup dependensi tugas, batas file,
kelengkapan langkah verifikasi, tautan dokumen, dan whitespace diff.

Setelah rencana disetujui, P4a menambah policy/action pengelolaan beserta tes.
Kontrak dan urutan lock: docs/PAYER_POLICY.md. Bukti P4a:
43 tes terfokus/166 assertions, regresi 461/2.334, PostgreSQL 31/128,
Pint dan PHPStan lulus. Ini backend internal, belum form P4b atau billing kolektif.

P4b kemudian memasang action bersama pada tabel client/sumber dan header edit
sumber, dengan tes Livewire (increment lima file). Harness browser disposable
ditambahkan terpisah, disusul dokumentasi. Bukti terbaru: 12 tes panel/98
assertions; regresi 473/2.432; Pint dan PHPStan lulus. Browser lokal menguji
simpan/batal, NULL, lock, dan error inline. Database aktif tidak diubah.
Ini bukan bukti billing kolektif selesai; P5 berikutnya, lalu checkpoint.

P5 menambah kontrak checkout-v2 default OFF, Form Request profil parsial,
adapter pemetaan eksplisit, dan guard kedua provisioning legacy sebelum replay.
Marker versi v2 dipetakan per organisasi/sumber, termasuk lintas client lembaga
yang sama. Tidak membuat marker pada data aktif atau endpoint checkout publik.
Hasil akhir: 25 tes kontrak/99 assertions, regresi 498/2.531,
PostgreSQL 33/134, Pint dan PHPStan lulus. Rincian batas dan keputusan:
docs/ORGANIZATION_CHECKOUT_CONTRACT.md dan docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
Checkpoint P5 disetujui melalui jawaban "lanjutkan" untuk P6a.

P6a menambah assessment_bills/assessment_charges, model internal, FK komposit,
partial unique idempotensi, CHECK uang/snapshot, dan service-only FORCE RLS awal.
Harga operasional tidak di-hardcode. Kontrak docs/ASSESSMENT_BILLING_SCHEMA.md;
bukti docs/ORGANIZATION_CHECKOUT_VALIDATION.md. Hasil: 10 tes skema/40 assertions,
regresi 508/2.571, PostgreSQL 66/184, Pint/PHPStan lulus. Populated down/up memakai
SQLite; PostgreSQL membuktikan constraint/RLS sebagai runtime, bukan owner.
P6b berikutnya; belum ada writer, invoice, alokasi lunas, atau perubahan DB aktif.

P6b kemudian menambah dua migrasi, dua model, fixture/tes untuk bill-items dan
entitlement per attempt. FK komposit, partial unique self dan CHECK PostgreSQL
menjaga scope serta identitas pembayar; kedua tabel langsung service-only FORCE
RLS. Tes rollback P6a disesuaikan urutan dependensi tanpa mengubah migrasi P6a.
Hasil: 12 tes/65 assertions terfokus, regresi 520/2.636, PostgreSQL 98/327,
Pint/PHPStan lulus. Rincian docs/ORGANIZATION_CHECKOUT_VALIDATION.md bagian P6b.
P6c berikutnya: kebijakan per peran dan populated rollback PostgreSQL. Tidak
ada perubahan DB aktif, harga, invoice, gateway, atau notifikasi nyata.

P6c menambah policy FOR SELECT ke empat tabel: SuperAdmin semua, BranchAdmin
organisasi sendiri, peserta charge/entitlement sendiri tanpa SELECT bill/item.
Write tetap service-only; tanpa context/staff/psychologist tetap tidak membaca.
Tes PostgreSQL mencakup matriks baca/tulis, TRUNCATE privilege, serta populated
down/up policy dan tabel melalui owner disposable dengan guard identitas.
Hasil: PostgreSQL 121/504, regresi 520/2.636, Pint/PHPStan lulus. Tidak ada skip;
sandbox eksternal tidak dijalankan. Rincian docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
Checkpoint P6 disetujui melalui jawaban "lanjutkan" untuk P7a; belum alur billing end-to-end.

P7a menambah kalkulator snapshot version=1 dan preview internal service-only.
Harga berasal dari package DB; snapshot existing dipertahankan, policy tetap
reload. Hash deterministic, free terpisah, satu item invalid meniadakan total
payable; batas batch dari config, overflow ditolak. Source dan marker attempt
checkout-v2 wajib: P9 harus menulis metadata.checkout_contract_version server-side
agar attempt legacy tidak otomatis ditagihkan setelah registry cutover.
Hasil: unit 11/16, feature preview 22/48, regresi 553/2.700, PostgreSQL 127/531,
Pint/PHPStan lulus. Kontrak docs/ASSESSMENT_BILL_PREVIEW.md; verifikasi di
docs/ORGANIZATION_CHECKOUT_VALIDATION.md. P7b wajib lock/reload/compare hash
sebelum menulis reservasi; preview bukan bukti concurrency atau invoice siap.
P7b menambah action reservasi internal yang memetakan payer/organisasi dari
principal persisted (BranchAdmin atau Participant), validasi canonical bersama
preview, lock terurut dan mutex organisasi, pemeriksaan hash/replay, snapshot
charge, bill/items serta audit atomik. Tidak mengaktifkan route/UI atau invoice.
Tes dua proses PostgreSQL membuktikan retry, overlap batch, self vs kolektif,
policy/kanal OFF, perubahan harga, dan rollback. Harga tetap dari database.
Hasil akhir setelah review pembacaan charge batch: 29 tes feature/114 assertions,
regresi 582/2.814, PostgreSQL 135/675, Pint/PHPStan lulus. Kontrak dan batas:
docs/ASSESSMENT_BILL_RESERVATION.md. Tidak ada perubahan DB aktif, deploy, WA,
n8n, Cloudflare, atau transaksi gateway. Tahap berikutnya P8a gate per attempt;
autentikasi HTTP/proyeksi privat, invoice, settlement dan menu cabang tetap
menunggu tahapan integrasi berikutnya.
P8a menambah principal scope attempt, gate read-only service-only, dan prasyarat
consent/identitas yang dapat dipakai ulang. Pembayaran wajib cocok ke charge/
bill-item milik attempt, atau free_settled_at eksplisit; ready legacy bukan
fallback. Hak tes berasal dari snapshot dibeli, bukan katalog/harga terbaru.
Consent psychotest saat ini wajib; DASS terpisah. Identitas match atau manual
accepted dengan bukti/timestamp sah; prasyarat bukan flag dari browser.
Hasil: 44 tes/55 assertions, regresi 626/2.869, PostgreSQL 144/690,
Pint/PHPStan lulus. Bug offset timestamp direproduksi dan diperbaiki.
Kontrak: docs/ASSESSMENT_ACCESS_GATE.md. FormRequest/controller/token verifier
ditunda ke P8b agar tidak memasang start tanpa autentikasi attempt yang benar;
gate belum melindungi endpoint publik dan tidak memulai sesi/mengaktifkan hak.
Tidak ada perubahan DB aktif, login legacy, scoring, UI, invoice, WA atau deploy.

## P13a0 — preflight handoff checkout

ADR-011 diterima pada 2026-09-02 untuk implementasi lokal bertahap P13a setelah
review threat model, actor matrix, schema, lock order, dan test matrix. Issuer
internal hanya menerima integration client persisted, attempt ULID, source
selector, idempotency key opaque, dan intent typed. Purpose `checkout-handoff`
serta destination `integrated-checkout-session` adalah konstanta server, bukan
input caller. Bearer 256-bit dan idempotency key tidak disimpan mentah; keduanya
memakai digest SHA-256 terpisah dengan fungsi dan authority berbeda. Exact replay
tidak dapat mengembalikan raw token dan mengarahkan explicit atomic reissue.

TTL dibatasi 60–600 detik dengan default 600 dan feature default OFF. Lifecycle,
FK/RLS, satu active handoff, rollback, race, populated migration, dan refusal
down saat berisi data wajib dibuktikan pada SQLite serta PostgreSQL disposable.
Penerimaan ADR ini belum mengizinkan route/controller, consume/session,
database aktif, checkout publik, atau P13b/P14.

P13a1 kemudian menambah schema/model `checkout_handoffs` dengan tiga parent
scope unique dan composite FK yang mengikat attempt, integration client,
organization, participant, package, source system, dan contract version dalam
satu graph durable. Digest/lifecycle/TTL/one-active, FORCE RLS service-only,
populated-down refusal, serta urutan rollback descendant→ancestor diuji pada
SQLite dan PostgreSQL disposable. Root final lulus 320 tes/2.655 assertions
PostgreSQL dan cleanup sukses. P13a2 issuer action tetap berikutnya; belum ada
raw token, config, route, consume/session, DB aktif, atau checkout publik.

P13a2 menambah DTO/intent dan issuer internal yang hanya berjalan dalam service
RLS context dengan authenticated integration client persisted. Config handoff
tetap default OFF; purpose/destination server-only, token/idempotency digest-only,
exact replay tidak mengembalikan raw, dan reissue eksplisit atomik. Clock database
dibaca ulang setelah seluruh lock agar effective window dan timestamps tidak
stale; paket tetap aktif, source-allowed, IDR, nominal non-negatif, dan memiliki
item. Root related suite lulus 139 tes/979 assertions, Pint/PHPStan lulus. Race
dua proses dan expiry saat menunggu lock tetap P13a3; route/P13b/P14 belum ada.

## Konsolidasi bukti checkout-v2 dan preparation gate (2026-09-06)

Commit accepted `3f0acc2`, `0ecb5f1`, `70c174b`, `a2aa851`, dan `e8fdad8`
menyelaraskan kontrak strict TypeScript dengan invariant runtime paket campuran:
setiap paket memuat DASS-21 dan minimal satu tes non-DASS, dengan consent DASS
current tetap wajib namun tidak mengubah scoring/hasil psikotes utama. Static
browser harness telah diperbarui untuk kontrak checkout-summary-v2, tetapi ini
hanya preparation dan bukan bukti browser runtime.

Ownership journal kini memiliki chain lokal dan mewajibkan latest anchor yang
disimpan independen saat recovery. Runner disabled belum memublikasikan anchor
tersebut; helper `_ps`, validasi lineage OS nyata, serta browser/service runtime
tetap menjadi gate P17c. Verifier dokumentasi operasi pada `a2aa851` hanya
preparation P18. Bagian ini tidak menutup P15, P16, P17c, P18, atau checkpoint
lintas tahap mana pun.

Preparation final `94e294d` → `d41844a` → `e15f55e` menerima strict input/browser,
pin publisher/callable dan lifecycle supervisor, serta adapter coordinator yang
mengikat provenance dan identitas operasional store. Bukti code-only: input
**79/79**, supervisor **88/88 pure/mock**, coordinator **12/12**, dan anchor store
**14/14**; tidak ada census/process/browser/service/DB nyata.
Anchor lokal mendeteksi korupsi/rollback tetapi bukan autentikasi. Wiring runner,
ACL/single-writer, durability/TOCTOU Windows, lineage/helper/cleanup OS,
manifest/tool hash disposable, dan runtime acceptance tetap tertunda. Runner
masih absent/disabled, payment tetap default OFF dan status P15/P16/P17c/P18
tidak berubah.
