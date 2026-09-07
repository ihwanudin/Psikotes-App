# Rencana: pembayaran lembaga dan checkout terintegrasi

Tanggal: 2026-08-31
Status: **P1–P13b, core privat P14/P15, dan P17a–P17b selesai lokal. P16-pay-a–i, binding Blade/UI summary-v2, subset consent current, serta privacy audit selesai lokal dan default OFF; DASS-21 wajib pada ingress/snapshot/handoff/session/form. Bukti terbaru: Blade 19+7, Node 4, HTTP/PHP 38/684, PostgreSQL fresh 400/3.950, beserta PHPStan/ESLint/Prettier/Pint/diff-check. Runtime browser/P17c belum dijalankan dan checkbox P15/P16 tetap terbuka. DASS consent tetap terpisah dan RLS privat. Tidak ada deploy, migrasi DB aktif, invoice/notifikasi nyata, provider/outbound, atau cutover sumber/feature flag.**

## Checkpoint baseline root `149a5da` — 2026-09-07

Commit awal structural privilege authority `7a39213` diterima sebagai rangkaian
setelah koreksi sealing `28b25fb` dan lifecycle/vault `702118a`. Hasilnya tetap
pure supplied-data consistency dengan marker `structuralOnly`, bukan native
provenance, policy satisfaction, atau authorization. `149a5da` hanya
memasukkan exact module+test itu ke candidate required/allowed manifest dan
mempertahankan omission/extra/casefold fail-closed; tidak ada candidate build,
transport enablement, provider/cache, ataupun runtime proof.

Pada jalur metadata, `9317b88` memperbaiki semantik ikon dekoratif landing,
`ec379f0` menetapkan fallback title publik, `892c621` menetapkan default
metadata Compose, dan `9bc1e93` menyelaraskan default sumber Laravel menjadi
`ONCAM Psikotes`/`id` sambil mempertahankan override environment serta
`APP_URL` environment-backed.

Re-audit HEAD lulus authority **15/15**, focused candidate closure **1/1**,
Node metadata/a11y **6/6**, dan konfigurasi PHP **2 tes / 8 assertions**.
Acceptance ini tetap code/test-only: tidak membuktikan candidate/browser,
container/DB, native Windows, provider/outbound, network, atau deploy.
Persentase dan checklist tidak berubah; P15/P16/P17c/P18 serta seluruh gate,
payment, deploy, dan activation tetap terbuka/default OFF.

Checkpoint backend P17b selesai lokal dengan bukti race dua proses, rollback
crash, replay, serta PostgreSQL disposable **394 tes / 3.865 assertions**.
Verifikasi UI desktop/mobile/keyboard bukan bagian checkpoint backend tersebut;
seluruhnya tetap acceptance P17c yang belum dijalankan. P17c/P18 tidak ditutup
dan status default OFF maupun larangan aktivasi/deploy tidak berubah.

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

Preparation final `94e294d` → `d41844a` → `e15f55e` → `12ce0bb` → `231d64d` →
`8c73391` menerima strict input/browser, pin publisher/callable dan lifecycle
supervisor, adapter coordinator, candidate builder immutable, serta pengikatan
runbook pada state dan gate exact. Bukti code-only: input **79/79**, supervisor
**89/89 pure/mock**, coordinator **12/12**, anchor store **14/14**, builder
**17/17**, dan arsitektur/runbook **7/7 tes, 190 assertions**; tidak ada census,
process, browser, service, atau DB nyata.
Anchor lokal mendeteksi korupsi/rollback tetapi bukan autentikasi. Builder belum
dijalankan terhadap source nyata. Wiring runner, ACL/single-writer,
durability/TOCTOU Windows, lineage/helper/cleanup OS, validasi semantik X.509,
inventory vendor/delivery candidate, dan runtime acceptance tetap tertunda.
Runner masih absent/disabled, payment tetap default OFF dan status
P15/P16/P17c/P18 tidak berubah.

### Checkpoint delivery JavaScript test-only

Commit accepted `5b03674` dan `3229f82` menambahkan delivery literal dua aset
JavaScript dan memasukkannya ke review closure builder/supervisor tanpa aktivasi
runner. Bukti statis/pure yang diterima adalah asset-pure lane **210 assertions**,
root contract **15 assertions**, self-test **64 checks**, builder **18/18**, dan
supervisor **90/90 pure/mock**. Full junction OS belum dijalankan dan timeout
asset-pure root bukan evidence.

Percobaan Composer parity tidak diintegrasikan. Parity runtime Composer dan
semantik penuh X.509 tetap harus ditutup bersama gate candidate nyata,
browser/service/OS. Payment tetap default OFF; P15/P16/P17c/P18 serta rencana
runtime tidak berubah.

### Checkpoint validasi pasangan TLS lokal

`7025c83` diterima sebagai preparation builder: parse sertifikat dan kecocokan
cert/key diperiksa lokal, key terenkripsi ditolak tanpa interaksi, dan identity
serta hash divalidasi sebelum/sesudah load. Evidence root adalah builder
**20/20**, parity AST **2/2**, dan scan header private-key fixture bersih.

Acceptance ini bukan validasi SAN, validity, EKU, CA/chain/trust, key strength,
browser, atau ACL. Candidate belum dibangun dan tidak ada deploy/runtime aktif;
P17c/P18 serta semua gate default-OFF tidak berubah.

### Checkpoint strict result envelope Playwright

Commit `d954b3c` mengikat output driver pada satu marker own-line, JSON penuh
tanpa duplicate/nonfinite/trailing, serta schema ordered smoke/full, counter,
history, check canonical, dan source return exact. Bukti root: supervisor
**93/93**, parity AST **2/2**, dan check Node lulus. Wording executable-script
secara eksplisit hanya berlaku pada state checkout default-OFF.

Ini evidence statis/pure: tidak ada browser, service/aplikasi, DB, env aktif,
network, atau candidate; hanya proses tes Python/Node lokal. P17c/P18 dan semua
gate default-OFF tetap tidak berubah.

### Checkpoint kontrak browser-config strict

Commit `a391612` membatasi browser-config pada schema semantic exact keluaran
builder: root/key nested exact, offline/service-worker block, isolated/headless,
executable dan launch args berurutan exact, serta timeout integer exact. JSON
bounded menolak UTF-8 invalid, duplicate, nonfinite, trailing, tipe salah, dan
key tambahan. Hash, parse, dan recheck memakai descriptor yang sama; pemeriksaan
terjadi saat preflight dan segera sebelum browser launch intent. Bukti root:
supervisor **96/96** dan parity AST **2/2**.

Increment ini bukan bukti terhadap penggantian direktori run/source maupun
TOCTOU akhir ketika CLI eksternal membuka ulang path. ACL, single-writer, dan
lifecycle identity tetap blocker P17c. Tidak ada candidate, browser, service,
DB, env aktif, network, deploy, atau aktivasi. P17c/P18 tetap terbuka dan semua
gate/payment tetap default OFF.

### Checkpoint binding konfigurasi kandidat exact

Commit `23ed855` mengikat schema config builder ke supervisor/coordinator dan
jurnal recovery. Key, path canonical, hash, directory, nama file runtime,
manifest, session, dan review aset wajib exact; review aset tidak lagi dapat
berubah tanpa mengubah `configBinding`. Recovery memvalidasi sebelum anchor I/O,
coordinator meredaksi kegagalan nested, dan parity schema diverifikasi via AST
allowlist tanpa mengeksekusi source supervisor. Bukti root: coordinator+builder
**34/34**, supervisor aman **98/98**, AST **5 file**, dan diff-check lulus.

Ini tetap preparation code-only. ACL/single-writer, replacement direktori,
lifecycle identity OS, dan final external path-open TOCTOU belum ditutup. Tidak
ada candidate/browser/service/DB/env/network/deploy atau aktivasi; P17c/P18
tetap terbuka dan seluruh gate/payment tetap default OFF.

### Checkpoint source identity dan façade lifecycle

Commit `30c78ee` menahan identity source root dan seluruh ancestor selama fase
build, termasuk inventory, descriptor open, hash, copy, dan batas publikasi
config. Commit `bfda8c4` menambahkan façade import-only yang mengharuskan
coordinator assembly/binding sebelum fresh supervise atau recovery. Cleanup
descriptor tidak menutupi `KeyboardInterrupt`/`SystemExit`. Bukti root gabungan:
**42/42 tes**, AST **4 file**, dan diff-check lulus.

Checkpoint ini tidak menutup candidate-global lifecycle lease, Windows ACL,
identity lintas-crash, final rename/swap namespace race, atau path yang dibuka
ulang oleh proses eksternal. Tidak ada runtime/deploy/aktivasi; P17c/P18 tetap
terbuka dan seluruh gate/payment tetap default OFF.

### Checkpoint lease lifecycle kandidat

ADR-016 diimplementasikan melalui `fe45bb2` dan `cec171c`: provenance lease
run-local deterministik, stabil/no-unlink, descriptor non-inheritable, dan lock
kernel/advisory nonblocking. Coordinator snapshot tunggal memperoleh lease sebelum
assembly/anchor I/O; supervisor memerlukan capability terpin pada claim/recovery;
publisher memvalidasi pre/post; cleanup selalu melepas handle tanpa menutupi error.
Root lulus **58/58 + 101/101 tes aman**, AST **8 file**, dan diff-check.

Runtime Windows masih harus membuktikan cross-process/crash lock, rename/reparse,
ACL/effective access, durability, serta dedicated single-use process. Tidak ada
browser/service/DB/env/network/deploy/aktivasi; P17c/P18 dan gate/payment tetap OFF.

### Checkpoint kontrak dan policy authority ACL Windows

`e47406a` menerima policy canonical dan codec request/evidence attestation;
`8461e03` memasukkan policy ke closure/hash kandidat serta config binding dan
preflight supervisor. Digest authority final adalah
`a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb`.
Schema, vocabulary, order, path/identity/lease binding, pembacaan descriptor yang
sama, dan error redacted kini exact. Policy invalid berhenti sebelum identity
probe atau proses.

Bukti root/agent: policy+codec **13/13**, builder **26/26**, supervisor aman
**102/102** dengan real-listener dikecualikan, serta `py_compile`, AST, dan
diff-check lulus. Ini preparation statis/pure saja. Recursive ACL descendant
source tree, effective access, crash/cross-process, reparse/rename dan durability
Windows, browser P17c, serta operasi P18 masih harus dibuktikan. Tidak ada
aktivasi atau runtime; P17c/P18 tetap terbuka dan seluruh gate/payment default OFF.

### Checkpoint admission anchor-only attestation ACL

Commit `20cf713` menambah jalur evidence attestation ACL anchor-only pada
supervisor. Codec, lease, dan attestor dipin exact; konsumsi one-shot memakai
sentinel `None`, sedangkan discard dan `BaseException` tetap fail-closed dengan
cleanup dipertahankan. Bukti root: supervisor aman **116/116**, `py_compile`,
diff-check, dan cross-review **PASS**; real-listener tidak dijalankan.

Publisher, execution/enforcement attestor, coordinator wiring, descendant
source-tree ACL, serta bukti Windows runtime/crash/reparse/rename belum tersedia.
Browser P17c dan operasi P18 tetap terbuka, tanpa aktivasi; seluruh gate/payment
tetap default OFF.

### Checkpoint admission execution ACL

Commit `221ae47` mengikat publisher ke transisi exact `anchor_consumed`, lalu
memerlukan challenge kedua/source pair baru. Admission memvalidasi session,
config binding, dan lease current-context dengan codec, lease, dan attestor dipin
exact; token opaque aktif hanya selama admission, menjadi exhausted setelah
dipakai, dan selalu dibersihkan.

Bukti root: supervisor aman **127/127**, `py_compile`, diff-check, dan review
**PASS**; real-listener tidak dijalankan. Wiring operasional `supervise`/
`recover`, preflight, claim, `_command`, launch, coordinator, real attestor,
serta enforcement/runtime Windows tetap terbuka. P17c/P18 belum diterima,
tanpa aktivasi, dan semua gate/payment tetap default OFF.

### Checkpoint enforcement admission ACL code-only

`15a5509` menambahkan wrapper dan direct gates dengan recheck admission segera
sebelum setiap `Popen`, exact recovery session+anchor yang berasal dari load
one-shot, serta coordinator wiring canonical dan fixed error mapping. Error utama
dan `BaseException` dipertahankan sementara cleanup admission/lease tetap dicoba.

Bukti root: supervisor aman **133/133** tanpa real-listener, coordinator+lease
**38/38**, AST **4 file**, `py_compile`, diff-check, dan adversarial review
**PASS**. Real Windows attestor, recursive descendant source-tree ACL/effective
access, cross-process/crash, reparse/rename/durability, dan browser runtime masih
terbuka. P17c/P18 belum diterima; tidak ada runtime/deploy dan seluruh
gate/payment tetap default OFF.

### Checkpoint pure source-tree summary ACL

Commit `6256dc8` mendefinisikan kontrak pure untuk manifest nonempty dengan
closure exact file dan implied directory. Path ASCII Windows-safe serta seluruh
batas ukuran diperiksa; identity record unik dan bentuk owner SID/DACL digest
exact. Canonical manifest+records diikat oleh digest domain-separated, known
vector, ordering deterministik, dan parity exact antar-boundary. Bukti accepted:
**8/8 tes**, `py_compile`, diff-check, dan review adversarial **PASS**.

Kontrak belum di-wire ke codec, policy, attestor, atau builder. Native tree
completeness, empty directory yang tidak tersirat manifest, identity root yang
dikecualikan, ACL enforcement/effective access, dan Windows runtime tetap gate.
Tidak ada runtime/deploy/aktivasi; P17c/P18 tetap terbuka dan seluruh
gate/payment default OFF.

### Checkpoint lazy Win32 ACL ABI boundary

Commit `2b715e1` menyediakan boundary `ctypes` lazy tanpa usable attestor atau
native call saat import; non-Windows berhenti sebelum `WinDLL`. Sebanyak **28
signature exact** dijaga oracle independen bersama ownership DLL,
`use_last_error`, width/layout, dan resolver sempit yang memvalidasi ulang
identity/signature/bundle serta fail closed terhadap mutasi. Error redacted
tetap fixed dan `KeyboardInterrupt`/`SystemExit` tidak ditutupi.

Bukti accepted: **7/7 tes**, `py_compile`, diff-check, dan cross-review **PASS**.
Real attestor/scanner/cache/composition serta native efficacy belum ada. Tidak
ada native/runtime/deploy/aktivasi; P17c/P18 tetap terbuka dan seluruh
gate/payment default OFF.

### Checkpoint private Windows ACL directory handle

Commit `038cbdd` menambah primitive private dengan handle access/share/flags
exact, noninheritance, verifikasi directory/non-reparse, dan double-read final
path canonical serta identity. `FILE_ID_128` dipertahankan sebagai 16 byte opaque
dan diserialisasi ke desimal canonical memakai interpretasi big-endian. Handle
selalu ditutup tepat sekali tanpa menutupi exception atau
`KeyboardInterrupt`/`SystemExit` utama. Bukti root accepted: **16/16 tes**,
`py_compile`, diff-check, dan final cross-review **PASS**.

Bukti hanya fake ABI, bukan native runtime. Belum ada usable attestor, scanner,
cache, composition/candidate closure, wiring codec/policy/source-tree, validasi
owner/DACL/token/`AccessCheck`, recursive completeness, empty-dir/root binding,
ACL efficacy, race/reparse/rename/TOCTOU, crash, atau browser runtime. Tidak ada
runtime/deploy/aktivasi; P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment
default OFF.

### Checkpoint private Windows ACL single-ACE semantics

Commit `97d7b33` memakai live `GetAce` lalu parser strict pada copied bytes.
Kontrak menerima tepat satu allow ACE type 0 dengan flags 3, mewajibkan
pointer/alignment/full containment, dan memastikan ACE mengonsumsi seluruh
`AclBytesInUse`. SID revision, count, dan full-span divalidasi sebelum
`IsValidSid`; authority big-endian dan subauthority little-endian diserialisasi
canonical. Trustee wajib exact owner dan dua immutable semantic snapshot harus
identik. ABI tetap **29 signature**. Bukti root: **27/27 tes**,
`py_compile`, diff-check, dan adversarial review **PASS**.

Bukti tetap pure fake ABI. Mask role/policy, process-token owner/privilege,
effective `AccessCheck`, source-tree/cache/composition/attestor, native runtime,
serta race belum dibuktikan. Tidak ada runtime/deploy/aktivasi; P15/P16/P17c/P18
tetap terbuka dan seluruh gate/payment default OFF.

### Checkpoint private Windows ACL descriptor snapshot

Commit `49d6b87` menambah `IsValidAcl` sehingga ABI berisi **29 signature** dan
mendefinisikan dua descriptor snapshot private melalui live handle yang sama.
Descriptor harus self-relative, bounded, revision 1, dengan pointer owner/group/
DACL interior. DACL harus present non-NULL, protected, tidak auto-inherited,
revision 2, dan seluruh rentang `AclSize` contained sebelum traversal; digest
mengikat exact `AclBytesInUse`. Cleanup hanya memanggil `LocalFree` pada allocation
base, tepat sekali. Bukti root accepted: **24/24 tes**, `py_compile`, diff-check,
dan final adversarial review **PASS**.

Ini masih pure fake ABI. SID string/process-token owner matching, exact ACE,
policy/effective `AccessCheck`, source-tree/composition/cache/usable attestor,
native runtime, serta race/reparse/rename/TOCTOU/crash belum dibuktikan. Tidak ada
runtime/deploy/aktivasi; P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment
default OFF.

### Checkpoint process-token owner binding

Commit `9301369` membaca token SID sebelum dua descriptor snapshot lalu membaca
token SID kembali. `GetCurrentProcess` pseudo-handle tidak ditutup; real handle
`OpenProcessToken` memakai `TOKEN_QUERY`, non-inheritable, dan ditutup exact
sekali. Bounded `TokenUser` probe harus menghasilkan error 122 lalu fill exact;
SID tidak boleh overlap header dan wajib full-contained sebelum native check.
Token harus stabil, kedua owner exact match, dan `processTokenSid` immutable.
Bukti root accepted: **30/30 tes**, `py_compile`, diff-check, dan adversarial
review **PASS**.

Ini tetap pure fake ABI. Groups, privileges, LocalSystem, role mask,
impersonation, effective `AccessCheck`, source-tree/cache/composition/attestor,
native runtime, dan races belum dibuktikan. Tidak ada runtime/deploy/aktivasi;
P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment default OFF.

### Checkpoint current-process TokenGroups

Commit `301a823` menambah profile `TokenGroups` private dengan probe/fill exact,
batas **256 KiB/4096 group**, serta validasi `ANYSIZE_ARRAY`, tabel, full span,
dan SID non-overlap terhadap header/tabel dan sesama SID sebelum `IsValidSid` →
`GetLengthSid`. Semua group+attributes dipertahankan dalam urutan native tanpa
filtering; duplikat ditolak, bukan dideduplikasi. Profil immutable sebelum/sesudah descriptor snapshots wajib
identik pada token handle yang sama, dengan cleanup exact. Bukti root accepted:
**35/35 unittest**, `py_compile`, diff-check, dan adversarial review **PASS**
tanpa P1/P2.

Ini tetap pure fake current-process only. Ordinary second principal/provenance,
privileges, LocalSystem exclusion untuk ordinary, impersonation, role/policy
mask, effective `AccessCheck`, source-tree/cache/composition/usable attestor,
native Windows/runtime/races belum dibuktikan. Tidak ada runtime/deploy/aktivasi;
P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment default OFF.

### Checkpoint current-process TokenPrivileges

Commit `ea9fe9c` membaca `TokenPrivileges` dengan probe/fill exact dan batas
**256 KiB/4096 privilege**. Inline `ANYSIZE_ARRAY` wajib mengonsumsi seluruh
buffer tanpa trailing. Setiap entry dipertahankan ordered/immutable sebagai
`(LowPart uint32, HighPart int32, Attributes uint32)` dan duplicate LUID
ditolak. Profil sebelum/sesudah descriptor snapshots harus stabil pada token
handle yang sama dengan cleanup exact. Bukti root accepted: **39/39 unittest**,
`py_compile`, diff-check, dan adversarial review **PASS** tanpa P1/P2.

Bukti hanya pure fake current-process observation. `LookupPrivilegeValue`/name
mapping, dangerous-enabled policy, ordinary principal/provenance, LocalSystem,
token type/restriction/impersonation, effective `AccessCheck`, source-tree/cache/
composition/attestor, dan native Windows/runtime belum dibuktikan. Tidak ada
aktivasi/deploy; P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment OFF.

### Checkpoint sensitive-privilege observation

Commit `4f015f3` mengamati tiga privilege fixed dalam urutan exact:
`SeBackupPrivilege`, `SeRestorePrivilege`, dan `SeTakeOwnershipPrivilege`.
Lookup lokal memakai `LookupPrivilegeValueW(system=None)`; LUID signed/unsigned
dinormalisasi exact dan duplicate mapping ditolak. Observation immutable memuat
name/LUID/present/enabled, dengan enabled hanya `Attributes & 0x2`, dan membedakan
absent/disabled/enabled. Profile sebelum/sesudah harus stabil dengan cleanup
exact. Bukti root accepted: **43/43 unittest**, `py_compile`, diff-check, dan
adversarial review **PASS** tanpa P1/P2.

Ini current-process pure fake observation saja, bukan rejection policy atau
ordinary evidence. Second ordinary-token provenance, LocalSystem, token type/
restriction/impersonation, effective `AccessCheck`, source-tree/cache/
composition/attestor, native Windows/runtime masih terbuka. Tidak ada
aktivasi/deploy; P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment OFF.

### Checkpoint fixed token profile

Commit `95450a6` mengobservasi `TokenType` sebagai `TOKEN_TYPE` yang wajib exact
`TokenPrimary` dan `TokenIsAppContainer` sebagai nilai `DWORD` raw dengan
klasifikasi nonzero. Profil current process sebelum/sesudah descriptor snapshots
harus stabil pada token handle yang sama dengan cleanup exact. Bukti root
accepted: **47/47 unittest**, `py_compile`, diff-check, dan adversarial review
**PASS** tanpa P1/P2.

Ini hanya pure fake current-process observation, bukan ordinary evidence,
rejection policy, atau effective `AccessCheck`. Second-token provenance,
LocalSystem, token restriction/impersonation, source-tree/cache/composition/
usable attestor, serta native Windows/runtime masih terbuka. Tidak ada
aktivasi/deploy; P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment OFF.

### Checkpoint restricting SID profile

Commit `69a25c2` menjadikan `TokenRestrictedSids` authority bagi snapshot
restricting SID current process. Encoding kosong hanya sah sebagai count nol
dengan panjang exact **4 byte** dan bentuk lain gagal tertutup. Parser nonempty
berbatas **256 KiB/4096 SID**, mewajibkan attributes nol, serta mempertahankan
duplicate SID pada span berbeda tanpa deduplikasi. Bukti root accepted:
**51/51 unittest**, `py_compile`, diff-check, dan adversarial review **PASS**
tanpa P1/P2.

Ini hanya pure fake current-process observation; bukan `IsTokenRestricted`,
general-unrestricted evidence, ordinary evidence/policy, atau effective
`AccessCheck`. Second-token provenance, LocalSystem, token restriction/
impersonation lainnya, source-tree/cache/composition/usable attestor, dan native
Windows/runtime masih terbuka. Tidak ada aktivasi/deploy; P15/P16/P17c/P18 tetap
terbuka dan seluruh gate/payment OFF.

### Checkpoint restricting SID corroboration

Commit `a5e3c98` memakai `IsTokenRestricted` hanya sebagai corroboration untuk
authority `TokenRestrictedSids` class 11. `FALSE` hanya sah setelah
`SetLastError(0)` dan immediate `GetLastError()==0`; nonzero berarti `true`, dan
hasil wajib parity dengan snapshot class 11. Bukti root accepted:
**53/53 unittest**, `py_compile`, diff-check, dan adversarial review **PASS**
tanpa P1/P2.

Ini tetap pure fake current-process observation; bukan general-unrestricted
evidence, ordinary evidence/policy, atau effective `AccessCheck`. Second-token
provenance, LocalSystem, token restriction/impersonation lainnya, source-tree/
cache/composition/usable attestor, dan native Windows/runtime masih terbuka.
Tidak ada aktivasi/deploy; P15/P16/P17c/P18 tetap terbuka dan seluruh
gate/payment OFF.

### Checkpoint LocalSystem identity rejection

Commit `bbcd5b7` menolak current-process `TokenUser` LocalSystem exact
`S-1-5-18` sebelum descriptor snapshots. Pemeriksaan ini identity-only; group
SID dan restricting SID bukan identitas `TokenUser`. Bukti root accepted:
**55/55 unittest**, `py_compile`, diff-check, dan adversarial review **PASS**
tanpa P1/P2.

Bukti tetap pure fake dan hanya mengecualikan LocalSystem sebagai
current-process identity; bukan ordinary second-principal/provenance evidence,
policy, atau effective `AccessCheck`. Token restriction/impersonation lainnya,
source-tree/cache/composition/usable attestor, dan native Windows/runtime masih
terbuka. Tidak ada aktivasi/deploy; P15/P16/P17c/P18 tetap terbuka dan seluruh
gate/payment OFF.

### Checkpoint kontrak ordinary access provider

ADR-018 menerima contract/pure-fake testing bagi provider internal yang memiliki
independent externally provisioned ordinary token, raw handles, exact
primary-to-impersonation derivation, native `AccessCheck`, dan one-shot
`attest/load/discard`. Tidak ada credentials, account/environment selection,
raw handle/SID pointer, atau process handle pada input/output caller.

Kontrak mewajibkan request baru pada anchor/execution untuk fresh/recovery yang
mengikat exact outer ADR-017 request/evidence, policy digest, fresh challenge,
current token identity, tiga target ordered+descriptor dengan `MAXIMUM_ALLOWED`,
provenance/profile token, serta exact function-success/access-false/granted-zero.
Path dan identity `(volumeSerial,fileId)` ketiga role wajib pairwise distinct;
ADR-018 codec menolak alias segera dan remediation outer codec tetap terbuka.
Same user, LocalSystem, owner SID dalam setiap group, fixed sensitive privilege
enabled, AppContainer raw/nonzero, class-11/`IsTokenRestricted`, atau profile
drift wajib ditolak; `TokenOrigin` hanya
observational dan distinct `AuthenticationId` hanya corroboration. Kedua live
descriptor capture diparse independen dan descriptor `policySatisfied` hanya
berasal dari provider, bukan request; ordinary denial adalah gate terpisah.
Acquisition authority dan provider/cache/
`attest` belum ada; hanya pure codec preparation boleh dimulai. Native runtime
tetap terbuka. Tidak ada perubahan
acceptance/progress; P15/P16/P17c/P18 terbuka dan seluruh gate/payment OFF.

### Checkpoint pure ADR-018 request codec

Commit `f011551` menerima pure codec yang menurunkan request dari exact
canonical outer ADR-017 request/evidence bytes dan private structural
current-token identity fixture. Codec mengikat `aclRequestDigest`,
`aclEvidenceDigest`, domain-separated `aclDescriptorEvidenceDigest`, serta
pairwise-distinct path dan `(volumeSerial,fileId)` tiga target.

Bukti root: **11/11 tests**, `py_compile`, dan independent review **PASS** tanpa
P1/P2. Belum ada provider/cache/attest, native implementation, composition,
provenance, runtime, atau provisioning authority. Tidak ada perubahan status,
acceptance, maupun progress; P15/P16/P17c/P18 dan active gate/payment tetap
terbuka/default OFF tanpa runtime/config/env/deploy/activation.

### Checkpoint outer target anti-alias

Commit `a72727e` menutup remediation codec untuk alias target: outer ADR-017
codec menolak pairwise path aliases menggunakan accepted casefold rule dan
duplicate evidence identities `(volumeSerial,fileId)`. Bukti root accepted:
**24/24 tests**, `py_compile`, dan independent review **PASS** tanpa P1/P2.

Percobaan full 299-test discovery selama concurrent edits bukan acceptance
evidence. Transient ordinary fixture error sudah diperbaiki; unrelated
supervisor real-listener failure berasal dari environment, sehingga full suite
tidak dinyatakan green. Tidak ada perubahan plan/checklist/progress/gate:
provider/native/runtime, provisioning authority, P15/P16/P17c/P18, active
gates, dan payment tetap terbuka/default OFF tanpa config/env/deploy/activation.

### Checkpoint pure ACL source-tree summary v2

Commit `5edb335` membuat exact `sourceRootIdentity` mandatory dan terikat ke
canonical digest v2. Descendant root-identity reuse ditolak, deterministic order
dipertahankan, dan boundary drift ditolak.

Bukti root accepted: **11/11 tests**, `py_compile`, serta independent review
**PASS** tanpa P1/P2. Builder suite timeout setelah lima dot bukan evidence dan
full suite tidak dinyatakan green. Consumer integration, native traversal, live
identity/provenance, dan runtime masih terbuka. Tidak ada perubahan plan,
checklist, progress, atau gate: P15/P16/P17c/P18 serta active gate/payment tetap
terbuka/default OFF tanpa config/env/deploy/activation.

### Checkpoint pure ACL role-mask policy

Commit `a62f8bd` menerima pure role-mask primitive atas fully revalidated
process-token-bound snapshots. Exact mask coordinator/run `2032127` dan source
`1179817` diturunkan serta parity-checked terhadap canonical policy artifact
digest `a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb`.
Output hanya immutable private match tanpa `policySatisfied`, evidence, atau
handle.

Bukti root: **60/60 tests**, `py_compile`, dan independent review **PASS** tanpa
P1/P2 setelah forged-snapshot P1 dan artifact-parity P2 diperbaiki. Role tetap
internal/test input; future composition wajib mengikat canonical target
order/path/identity serta double descriptor/token stability. Belum ada usable
attestor, native/runtime implementation, atau provider. Plan/checklist/progress,
P15/P16/P17c/P18, active gates, dan payment tidak berubah serta tetap
terbuka/default OFF tanpa config/env/deploy/activation.

### Checkpoint pure ordered ACL target-policy bundle

Commit `ab442d0` menerima exact tuple tiga snapshot dalam internal pinned order
coordinator/run/source. Setiap snapshot menjalani full role-policy validation;
semua field `processToken*` exact-equal, authority tuples dipin, dan limited
result deeply immutable.

Bukti root: **65/65 tests**, `py_compile`, dan independent review **PASS** tanpa
P1/P2 setelah P2 rebind hardening. Belum ada binding path/filesystem identity,
capture/double stability, `policySatisfied`/evidence, source-tree completeness,
usable attestor, native/runtime implementation, atau provider. Plan/checklist/
progress, P15/P16/P17c/P18, active gates, dan payment tidak berubah serta tetap
terbuka/default OFF tanpa config/env/deploy/activation.

### Checkpoint candidate manifest closure ACL

Commit `3460588` menjadikan enam path berikut exact required sekaligus allowed
dalam candidate manifest closure:

- `tools/testing/tests/Browser/checkout-acl-source-tree.py`
- `tools/testing/tests/Browser/test_checkout_acl_source_tree.py`
- `tools/testing/tests/Browser/checkout-windows-acl-attestor.py`
- `tools/testing/tests/Browser/test_checkout_windows_acl_attestor.py`
- `tools/testing/tests/Browser/checkout-ordinary-access-request.py`
- `tools/testing/tests/Browser/test_checkout_ordinary_access_request.py`

Bukti root: focused **1/1 test**, `py_compile`, dan independent review **PASS**
tanpa P1/P2. Bukti ini terbatas pada packaging integrity; tidak ada candidate
build, klaim full builder suite, ataupun bukti runtime/provider/native Windows.
Plan/checklist/progress, P15/P16/P17c/P18, active gates, payment,
config/env/deploy, dan activation tidak berubah serta tetap terbuka/default OFF.

### Checkpoint pure ACL source binding

Commit `4bfd005` memvalidasi exact canonical anchor/execution outer pairs dengan
accepted codec serta `validate_boundary_pair`. Source-root identity hanya
diturunkan dari evidence `target[2]`; source-tree v2 summaries dihitung ulang
dari manifest/records dan wajib exact-equal. Sibling authority dipin dan narrow
result immutable.

Bukti root: **31/31 tests**, `py_compile`, dan independent review **PASS** tanpa
P1/P2 setelah tuple/frozenset identity P2 diperbaiki. Ini hanya supplied-data
consistency, bukan authority `policySatisfied`, live traversal/completeness,
handle/path provenance, DACL efficacy, double capture, provider, native/runtime,
atau usable attestor. Plan/checklist/progress, P15/P16/P17c/P18, active gates,
payment, config/env/deploy, dan activation tidak berubah/default OFF.

### Checkpoint candidate packaging ACL source binding

Commit `1f8b22f` menetapkan
`tools/testing/tests/Browser/checkout-acl-source-binding.py` dan
`tools/testing/tests/Browser/test_checkout_acl_source_binding.py` sebagai exact
required sekaligus allowed dalam candidate manifest closure. Bukti root:
focused **1/1 test**, `py_compile`, dan independent review **PASS** tanpa P1/P2.
Ini hanya packaging integrity; tidak ada candidate build atau bukti runtime.
Plan/checklist/progress, P15/P16/P17c/P18, gates/payment, config/env/deploy, dan
activation tidak berubah/default OFF.

### Checkpoint ACL source-binding dependency pinning

Commit `538c144` mem-pin direct sibling dan stdlib dependency callables melalui
module, callable identity/type, serta Python metadata yang diperiksa pre/post
setiap call dan kembali sebelum result. Bukti root: **32/32 tests**,
`py_compile`, dan independent review **PASS** tanpa P1/P2.

Trust/residual masih mencakup CPython builtins, deeper stdlib internals, dan
dependency mutation yang dipulihkan sepenuhnya di dalam callback. Increment ini
hanya integrity hardening, bukan functional/native/provider/runtime authority.
Plan/checklist/progress, P15/P16/P17c/P18, gates/payment, config/env/deploy, dan
activation tidak berubah/default OFF.

### Checkpoint design provisioning ordinary Windows principal

Commit `16c320f` menerima ADR-019: administrator/SCM menjadi provisioning
authority untuk dedicated non-admin local Windows user dalam own-process broker
service yang bertindak sebagai provider ADR-018. Tidak ada token/credentials
export; strict local IPC dan mutual SID/token plus SCM/process identity
authentication diwajibkan.

Independent review **PASS** tanpa P1/P2 dengan sumber resmi Microsoft. Ini hanya
keputusan desain; install/provider/native/runtime tetap dilarang dan belum
diterima. Plan/checklist/progress, P15/P16/P17c/P18, gates, dan payment tidak
berubah/default OFF; tidak ada account/service/config/env/deploy/activation.

### Checkpoint pure authority manifest dan broker transport

Commit `6469561` menerima pure canonical authority-manifest codec ADR-019
(**14/14 tests**) dengan cap pipe exact 20/36 KiB, owner pipe akun broker,
LocalSystem ditolak, serta direct SAM membership terpisah dari bound runtime
token-group policy. Commit `459e988` menerima pure canonical broker-transport
codec (**11/11 tests**) hanya untuk request, refusal, discard, dan load-sentinel.

Bukti gabungan **25/25**, `py_compile`, `diff-check`, dan final adversarial review
**PASS** tanpa P1/P2. Successful `attest`/`load` evidence tetap fail closed
sampai canonical ADR-018 evidence validator/provider diterima. Tidak ada bukti
instalasi/read freshness manifest, efektivitas ACL, account/service/firewall,
native IPC/cache/provider/runtime, candidate/browser, deploy, atau activation.
Plan/checklist/progress, P15/P16/P17c/P18, gates/payment tidak berubah, tetap
terbuka/default OFF.

### Checkpoint broker-start identity dan structural evidence

Commit `b66ebba` menerima pure canonical supplied-data broker-start identity
(**8/8 tests**) tanpa live process/token composition binding. Commit `bb369c6`
menerima design boundary ADR-020, lalu `9785eb5` menerima bytes-only structural
evidence codec (**12/12 tests**) dengan hasil explicit `structuralOnly`, bukan
native policy/provenance/admission proof.

Suite codec lokal gabungan **56/56** lulus dengan `py_compile`, `diff-check`,
dan review **PASS**. Successful `attest`/`load` tetap disabled sampai native
authority/provider/cache/runtime diterima. Tidak ada instalasi, service,
environment, network, deployment, atau activation. Plan/progress/checklist,
P15/P16/P17c/P18, gates, dan payment tetap terbuka/tidak berubah/default OFF.

### Checkpoint statis confirmation P17c — accepted `81c6cc6`, `42201b0`, `b7a0e95`, `06864b3`

Empat commit ini menyiapkan fixture dan driver confirmation sintetis, menutup
integrity closure beserta parity route, memvalidasi envelope dan retensi audit
`checkout.confirmed`, serta menambahkan regresi retensi leap-day. Bukti terbaru:
self-test **75**, contract **31**, asset-pure **284**, integrity **52 cases / 161
assertions**, confirmation transport **4/4**, dan supervisor exact envelope
**1/1**.

Checkpoint ini hanya bukti statis/pure. Tidak ada browser, native, provider,
database, ataupun runtime yang dijalankan. P15/P16/P17c/P18 tetap terbuka;
checklist/progress tidak berubah, dan seluruh gate/fitur terkait tetap default
OFF.

### Checkpoint packaging manifest/start dan AST scanner — accepted `4ec606a`, `0aca8ff`

Commit `4ec606a` menutup P1 closure dengan memasukkan module dan test manifest/
start ke packaging yang dipin. Commit `0aca8ff` menutup P2 dengan AST scanner
fail-closed. Bukti mencakup RED/GREEN, focused **3/3**, `py_compile`,
`diff-check`, dan independent review **PASS**.

Checkpoint ini packaging-only: candidate tidak dibangun dan prior
host-process confirmation tetap blocker. Tidak ada browser, native, provider,
database, runtime, environment, network, atau deployment. Checklist/progress
serta P15/P16/P17c/P18 tidak berubah, tetap terbuka/default OFF.

### Checkpoint bounded resource recheck — 2026-09-07

Probe literal-path awal terkontaminasi oleh command census sendiri dan dibuang
sebagai non-evidence. Probe split-literal yang dikoreksi berhasil; dua snapshot
berturut-turut mencatat process count **0**, sementara all-family listeners
**443=0** dan **8126=0**. Exact candidate masih ada dengan `integrity-invalid`.

Hanya blocker resource point-in-time yang tertutup. Historical cleanup/lineage
tetap tidak dapat dibuktikan; candidate invalid tidak boleh digunakan kembali
atau di-rearm. Native provider, sealed input authority, dan browser P17c tetap
blocker. Tidak ada candidate/browser/server/database/native/provider/env/network/
deploy atau kill. Checklist/progress dan P15/P16/P17c/P18 tidak berubah/default
OFF.

### Checkpoint authority persiapan release dan TLS sintetis — Proposed `e550227`

Commit `e550227` merekam ADR-021 dan ADR-022 sebagai desain **Proposed** untuk
authority persiapan release kandidat serta material TLS sintetis. Independent
review **PASS** tanpa P1/P2. Checkpoint ini design-only: tidak menerima
implementasi atau runtime, tidak membuat certificate/key maupun candidate, dan
tidak mengizinkan deployment atau activation.

Keputusan pengguna masih diperlukan untuk signer/trust roots, pemisahan role,
revocation authority beserta storage dan clock, serta TLS issuer, browser trust,
private-key custody, consumer mechanism, dan runtime budget. P15/P16/P17c/P18
tetap terbuka, seluruh gate tetap default OFF, dan checklist/progress tidak
berubah pada **173/220 = 78.6%**.

### Checkpoint I1 structural preparation artifact envelope — accepted `f3f8135`

`f3f8135` menerima codec envelope artefak persiapan yang hanya memvalidasi
struktur. Hasilnya berupa `MappingProxyType` immutable dengan exact sembilan key;
pinning callable ekspor `decode`/`canonical_envelope` tetap menjadi tanggung
jawab composition boundary. Bukti focused **17/17**, `py_compile`, dan final
independent review **PASS** tanpa P1/P2.

Acceptance ini tidak membuktikan signature, trust, quorum, TTL, revocation,
replay, admission, ataupun runtime authority. P15/P16/P17c/P18 tetap terbuka,
seluruh gate tetap default OFF, dan checklist/progress tidak berubah pada
**173/220 = 78.6%**.

### Checkpoint I6 runtime-configuration policy artifact — accepted `9ff3404`

`9ff3404` menerima codec structural-only untuk runtime-configuration-policy
artifact yang exact dan lengkap: identity/generation, klasifikasi environment,
constraint variabel presence/type/format, digest beserta schema/version
`runtime.ini` dan browser-config template, launch arguments, public HTTPS
origin/port, offline/network policy, execution/recovery budget, output names,
serta interval UTC positif maksimum tujuh hari. Bukti focused **17/17**,
`py_compile`, `diff-check`, dan final independent review **PASS** setelah dua P1
ditutup.

Artifact tidak memuat values, secrets, credentials, atau path/hash/metadata
private key. Codec ini juga bukan authority signature, trust, current freshness,
revocation, replay, admission, maupun runtime. P15/P16/P17c/P18 tetap terbuka,
gate/payment tetap default OFF, dan checklist/progress formal tidak berubah pada
**173/220 = 78.6%**.

### Checkpoint I2 release/source dan I3 revocation snapshot — accepted `0416cd1`, `8741ef0`

Commit `0416cd1` menerima codec struktural release/source I2 setelah dua P2
path Windows diperbaiki; focused **13/13**, `py_compile`, `diff-check`, dan
final review **PASS**. Commit `8741ef0` menerima codec struktural revocation
snapshot I3 setelah P2 cutoff diperbaiki menjadi exact
`notBefore <= issuedAt < nextUpdate`; focused **10/10**, `py_compile`,
`diff-check`, dan final review **PASS**.

Keduanya hanya memvalidasi supplied canonical data. I2 tidak membuktikan Git
atau filesystem; I3 tidak membuktikan signature, trust, quorum, live freshness,
protected high-water, rollback/replay acceptance, admission, maupun authority
runtime. P15/P16/P17c/P18 tetap terbuka, seluruh gate/payment tetap default
OFF, dan formal checklist/progress tetap **173/220 = 78.6%**.

### Checkpoint I5 asset review dan I7 tool/runtime closure — accepted `96d5076`, `3fe523f`

Commit `96d5076` menerima codec struktural asset-review I5 yang mengikat exact
enam path/digest ADR-021 serta release/source artifact digest; focused **11/11**
dan independent review **PASS**. Commit `3fe523f` menerima codec struktural
tool/runtime-closure I7 untuk exact enam tool, resource-category inventory,
host digest, path/object identity/digest/version, serta bounded
`closureComplete`; focused **10/10** dan independent review **PASS**. Keduanya
lulus `py_compile` dan `diff-check`.

Checkpoint ini hanya supplied-data structure. Tidak ada file/host inspection,
bukti closure transitif aktual, reviewer/signature/trust, current freshness,
revocation, replay acceptance, admission, ataupun runtime authority.
P15/P16/P17c/P18 tetap terbuka, gate/payment tetap default OFF, dan formal
checklist/progress tidak berubah pada **173/220 = 78.6%**.

### Checkpoint I4 structural trust-root bundle — accepted `1c56cca`

Commit `1c56cca` menerima codec struktural trust-root bundle untuk exact
key-role/custodian inventory dan referensi rotation old/new threshold sets.
P2 envelope-digest uniqueness di dalam maupun lintas rotation set telah
ditutup. Bukti focused **15/15**, `py_compile`, `diff-check`, dan independent
review **PASS**.

Codec tidak memverifikasi crypto, quorum, bootstrap, current freshness,
revocation, replay, admission, atau runtime dan tidak memuat private key.
P15/P16/P17c/P18 tetap terbuka, gate/payment tetap default OFF, dan formal
checklist/progress tidak berubah pada **173/220 = 78.6%**.

### Checkpoint I8 vendor-build dan I9 preparation authorization — accepted `b0ae28e`, `8784215`

`b0ae28e` menerima pure structural vendor-build artifact I8 dengan binding
release/source dan `composer.lock`, bounded package/file inventory, runtime
metadata, serta supplied build provenance. Bukti focused **11/11**,
`py_compile`, `diff-check`, dan independent review **PASS** tanpa P1/P2.

`8784215` menerima pure structural preparation-authorization artifact I9 untuk
exact lima artifact statis pra-TLS, revocation-snapshot references, serta
constraint destination/ACL intent dengan lifetime positif maksimum 10 menit.
P2 path telah ditutup melalui batas komponen 255 byte, depth 64, dan penolakan
alias perangkat Win32. Bukti focused **14/14**, `py_compile`, `diff-check`, dan
independent review **PASS**.

Keduanya hanya memvalidasi supplied canonical data. Tidak ada authority untuk
Composer/filesystem/reproducibility, signature/trust, current freshness,
protected high-water, replay consumption, pembuatan destination, penerapan
ACL, admission, atau runtime. P15/P16/P17c/P18 tetap terbuka, seluruh
gate/payment tetap default OFF, dan formal checklist/progress tidak berubah
pada **173/220 = 78.6%**.

### Checkpoint I10 final composition-admission artifact — accepted `57215af`

`57215af` menerima pure structural composition-admission artifact I10 dengan
exact cross-binding release/source, vendor, asset review, tool/runtime closure,
runtime-configuration policy, preparation authorization, preparation ACL
evidence, public TLS certificate evidence, final manifest, dan
`finalConfigBinding`. Artifact juga mengikat canonical destination/run
identities, distinct `requestedGeneration`, lease ADR-016 identity/generation,
serta exact ordered sebelas namespace revocation termasuk `preparation-acl`
dan `tls-material`. Tiga P1 dan satu gap P2 review telah ditutup. Bukti focused
**12/12**, regresi I1 **17/17**, `py_compile`, `diff-check`, dan independent
review **PASS**.

Codec ini structural-only. Tidak ada signature/trust verification, current
freshness, protected high-water, replay consumption, TLS capability
validation, actual admission, atau runtime authority. P15/P16/P17c/P18 tetap
terbuka, gate/payment tetap default OFF, dan formal checklist/progress tidak
berubah pada **173/220 = 78.6%**.

### Checkpoint trusted-time dan transition planners I11–I13 — accepted `eec87c4`, `fc584c5`, `756ac00`, `3a15fd5`

`eec87c4` menetapkan lifetime maksimum trust-root bundle **366 hari**.
`fc584c5` menerima structural trusted-time interval policy I11 untuk exact
artifact/revocation role sets, half-open interval, no-regression input, dan
domain-separated exact input-set binding; focused **13/13** dan independent
review **PASS**. `756ac00` menerima structural revocation high-water transition
planner I12; focused **18/18** dan independent review **PASS**. `3a15fd5`
menerima structural one-shot ledger transition planner I13 untuk preparation
authorization dan composition admission; focused **12/12** dan independent
review **PASS**. Seluruh lane lulus `py_compile` dan `diff-check`.

Semua hasil ini supplied/proposed-only. Tidak ada real-clock atau trusted-time
evidence authentication, protected storage/DPAPI/TPM/atomicity, actual
high-water atau rollback protection, replay consumption, signature/trust,
freshness, admission, maupun runtime authority. P15/P16/P17c/P18 tetap terbuka,
gate/payment tetap default OFF, dan formal checklist/progress tidak berubah
pada **173/220 = 78.6%**.

### Checkpoint I14 acquisition evidence dan I15 verifier request — accepted `11926e8`, `71e49ef`

`11926e8` menerima pure structural cryptography-acquisition evidence I14
setelah binding CPython interpreter tag/version, `abi3`, `win_amd64`, dan
arsitektur `amd64` serta batas versi pra-konversi diperbaiki. Bukti focused
**14/14**, `py_compile`, `diff-check`, dan independent review **PASS** tanpa
P1/P2. Cryptography ambient **46.0.6** tetap bukan authority atau acceptance
evidence.

`71e49ef` menerima pure structural verifier request I15 setelah binding
generation/digest dan hardening dependency diperbaiki. Bukti focused **17/17**,
`py_compile`, `diff-check`, dan independent review **PASS**.

Keduanya hanya memvalidasi supplied canonical data. Tidak ada actual
acquisition, import, install, atau filesystem verification; tidak ada eksekusi
crypto, verifikasi signature/trust, current freshness, protected high-water
persistence, replay consumption, admission, maupun runtime authority.
P15/P16/P17c/P18 tetap terbuka, seluruh gate/payment tetap default OFF, dan
formal checklist/progress tidak berubah pada **173/220 = 78.6%**.

### Checkpoint I16 protected-journal request — accepted `545c6de`

`545c6de` menerima pure structural protected-journal request codec I16 untuk
exact compare-and-swap binding dua jenis jurnal. Setelah dua P1 ditutup,
generation one-shot ledger wajib exact `current + 1`, sedangkan revocation
high-water wajib bootstrap `0 -> 1` lalu strictly lebih besar dari current.
Collision policy juga menurunkan internal exact digest tiga sensitive run-child
path untuk `runtime.ini`, `supervisor-config.json`, dan `tls/server.key`, lalu
mewajibkan set tersebut pada request. Bukti focused **12/12**, `py_compile`,
`diff-check`, dan independent review **PASS**.

Request ini structural-only. Tidak ada journal I/O, ACL/DPAPI/TPM, persistence,
atomicity, actual rollback protection, replay consumption, admission, runtime,
atau private-key metadata. P15/P16/P17c/P18 tetap terbuka, gate/payment tetap
default OFF, dan formal checklist/progress tidak berubah pada
**173/220 = 78.6%**.

### Checkpoint I19, I16 pairing fix, I18, dan I17 — accepted `590da62`, `31c61d8`, `84b0e40`, `d39e935`

`590da62` menerima structural trust-bootstrap evidence I19 untuk exact dua
operator, dua channel, issuer/custodian sets yang disjoint, serta distinct
source-copy ID dan provenance digest; focused **12/12** dan independent review
**PASS**. `31c61d8` menambahkan exact positive `providerGeneration` ke request
protected-journal I16 dan seluruh binding/result digest; focused **12/12** dan
independent review **PASS**. `84b0e40` menerima structural protected-journal
evidence I18 dengan exact pairing seluruh request I16, termasuk jalur refused
dan `providerGeneration`; focused **15/15** plus I16 **12/12** dan independent
review **PASS**. `d39e935` menerima structural verifier evidence I17 dengan
exact ordinary 1-of-1, asset-review 2-of-2 beserta release-key exclusion,
revocation 2-of-3 beserta issuer separation, trust bootstrap 2-of-3, serta
rotation 2-of-3 old dan 2-of-3 new; focused **15/15** plus I15 **17/17** dan
independent review **PASS**. Seluruh lane lulus `py_compile` dan `diff-check`.

Semua output tetap `evidenceStructuralOnly`: tidak ada autentikasi operator,
copy provenance, provider/native behavior, crypto/signature, trust/bootstrap,
persistence/atomicity/rollback, replay consumption, admission, atau runtime
authority. P15/P16/P17c/P18 tetap terbuka, gate/payment tetap default OFF, dan
formal checklist/progress tidak berubah pada **173/220 = 78.6%**.
