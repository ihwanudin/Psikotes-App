# Tugas: organization-payment

Status: **P1–P13b, core privat P14/P15, dan P17a–P17b selesai lokal. P16-pay-a–i, binding Blade/UI summary-v2, subset consent current, serta privacy audit selesai lokal dan default OFF; DASS-21 wajib pada ingress/snapshot/handoff/session/form. Bukti terbaru: Blade 19+7, Node 4, PHP/HTTP 38/684, PostgreSQL fresh 400/3.950, beserta PHPStan/ESLint/Prettier/Pint/diff-check. Runtime browser/P17c dan finalisasi P18 masih terbuka; checkbox P15/P16 belum ditutup. Tidak deploy, tidak migrasi DB aktif, dan tidak menyalakan sumber/feature flag, provider, atau outbound nyata.**

## Gerbang revisi kolektif

- [x] Kebutuhan pengguna dicatat: cabang memilih beberapa peserta dan membayar sekali; mandiri tetap ada.
- [x] Draf spesifikasi diperbarui, termasuk menu cabang, alokasi lunas per attempt, dan pencegahan tagihan ganda.
- [x] Tinjauan spesifikasi kolektif: satu cabang, pelunasan seluruh total, daftar terkunci, tanpa reinvoice otomatis; pengguna menjawab "lanjutkan".
- [x] Rencana dan tugas schema/reservasi/invoice/alokasi/portal/regresi dipecah ulang setelah tinjauan spesifikasi.
- [x] Pengguna meninjau dan menyetujui rencana teknis revisi sebelum implementasi P4a melalui jawaban "lanjhutkan".

Checklist P1–P3 berikut mempertahankan bukti historis. P4a–P18 menggantikan daftar
lama dengan rincian kolektif; status unchecked tetap dipertahankan.
Lihat analisis dampak pada plan.md dan acuan SPEC-organization-billing.md.

Rujuk [plan.md](plan.md). Tinjau rincian file/kontrak tiap tugas sebelum coding. Lokasi `<new>` adalah nama migrasi yang belum dialokasikan; path baru lain adalah usulan, bukan klaim file sudah ada. Jika satu tugas melebar melebihi lima file, pecah dahulu. Tes selain yang memiliki bukti masih direncanakan; filter tanpa tes bukan keberhasilan.

P1 selesai dengan bukti di dokumen pengujian. Checklist F1 lama tetap terpisah.

## P1: Harness dan baseline aman

**Deskripsi / acceptance:**

- [x] Runner menolak database aktif dan outbound nyata; baseline existing dicatat tanpa menandai skip sebagai lulus.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml`: 10 tes/22 assertions. Setelah penyelarasan legacy, regresi lokal 353 tes/1.776 assertions lulus tanpa skip (sandbox eksternal tidak dijalankan). `powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1`: 8 tes/52 assertions PostgreSQL lulus. Bukti dan batas: `docs/ORGANIZATION_PAYMENT_TESTING.md`.

**Dependencies:** None. **Scope:** M.

**Files likely touched:** `tests/Feature/Database/OrganizationPaymentTestEnvironmentTest.php`, `tests/OrganizationPaymentTestCase.php`, `phpunit.organization-payment.xml`, `docs/ORGANIZATION_PAYMENT_TESTING.md`.

**Increment terpisah:** penyelarasan `tests/Feature/Auth/RegistrationTest.php`; runner `tools/testing/run-org-postgres.ps1`, `tools/testing/bootstrap-org-postgres.php`, `phpunit.organization-postgres.xml`, dan `tests/Postgres/OrganizationPaymentRlsTest.php`. Tidak mengubah skema/kode aplikasi atau checklist F1.

## P2: Konfigurasi pembayar

**Deskripsi / acceptance:**

- [x] Schema additive dan RLS teruji; existing tidak otomatis memperoleh izin; default organisasi baru self saja.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml`: 13 tes/44 assertions; regresi lokal 356 tes/1.798 assertions; runner PostgreSQL disposable 27 tes/105 assertions. Semua lulus tanpa skip; sandbox eksternal tidak dijalankan. Pint kelima file PHP dan PHPStan (0 error) lulus. Bukti/batas: `docs/ORGANIZATION_PAYMENT_TESTING.md`, bagian P2. Tidak ada migrasi DB aktif atau deploy.

**Increment:** schema/model/test/XML (5 file), lalu tes PostgreSQL dan dokumentasi. Lifecycle up/down berisi data diuji pada SQLite; PostgreSQL membuktikan fresh migration, constraint, dan RLS. Perubahan masih working tree karena bergantung pada registry existing yang sebagian belum tracked; tidak melakukan commit massal.

**Dependencies:** P1. **Scope:** M.

**Files likely touched:** `database/migrations/<new>_add_payer_policy.php`, `app/Models/Branch.php`, `app/Models/IntegrationSource.php`, `tests/Feature/Database/PayerPolicySchemaTest.php`.

## P3: Keputusan kebijakan server

**Deskripsi / acceptance:**

- [x] Irisan organisasi/sumber/paket dihormati; locked payer dan input palsu diuji; policy tidak membuka entitlement.

**Verification:** unit PayerPolicyTest 59 tes/351 assertions; harness P1–P3 75 tes/414 assertions; regresi lokal 418 tes/2.168 assertions; PostgreSQL disposable 28 tes/114 assertions. Semua lulus tanpa skip, sandbox eksternal tidak dijalankan. Kontrak: `docs/PAYER_POLICY.md`; bukti: `docs/ORGANIZATION_PAYMENT_TESTING.md`.

**Increment:** enum/DTO/resolver/unit test, kemudian feature test SQLite, perluasan test PostgreSQL, registrasi XML, dan dokumentasi. Tidak memasang resolver ke route/provisioning v1. Pemanggil order nanti wajib reload registry dalam transaksi/RLS; keputusan bukan bukti paid/consent/akses.

**Dependencies:** P2. **Scope:** M.

**Files likely touched:** `app/Enums/PayerType.php`, `app/Services/Payments/ResolvePayerPolicy.php`, `app/Data/Payments/PayerDecision.php`, `tests/Unit/Payments/PayerPolicyTest.php`.

### Checkpoint setelah P3

- [x] Focused tests tiga tugas terakhir dan regresi terkait lulus tanpa skip gerbang.
- [x] Pint, PHPStan, lint/typecheck frontend, dan build lulus sesuai perintah plan.
- [ ] Slice berjalan end-to-end pada lingkungan test; RLS/concurrency memakai PostgreSQL bila terkait. Catat bukti dan tinjau bersama pengguna sebelum kelompok berikutnya.

**Bukti teknis historis:** registry → resolver → keputusan berjalan di SQLite/PostgreSQL runtime. Browser checkout dan concurrency reservasi belum termasuk slice ini. Build memakai output verifikasi terpisah; peringatan existing fontaine/chunk >500 kB dicatat. Persetujuan rencana kolektif mengizinkan tahap P4a, bukan menandai pengujian checkout end-to-end selesai.

## Tugas revisi kolektif — rencana disetujui

P4–P18 lama diganti rincian berikut; tidak dianggap selesai. Path baru adalah
usulan dan diverifikasi sebelum implementasi. Semua task berscope S/M (2–5 file).
Perubahan pendukung tambahan wajib dipecah dahulu.
Tidak ada subagent; pekerjaan berurutan pada workspace yang sama.

Focused test menggunakan path eksplisit agar tidak bergantung pada registrasi XML.
Tes baru harus ada dan jumlah tes nonzero sebelum dinilai lulus. File PostgreSQL
hanya dijalankan dengan runner disposable. Dokumen verifikasi diperbarui pada
checkpoint; jangan memasukkan tests/Postgres ke suite SQLite.

## P4a: Otorisasi dan audit policy

**Acceptance:**

- [x] Hanya SuperAdmin ONCAM boleh mengubah izin pembayar; input invalid ditolak dan audit mencatat aktor/perubahan dalam transaksi. Urutan lock registry ditetapkan bagi reservasi berikutnya; race dengan reservasi dibuktikan pada P7/P17, bukan diklaim dari P4a.

**Dependencies:** P3. **Scope:** M.

**Files likely touched:** `app/Policies/FundingPolicyPolicy.php`, `app/Actions/Payments/UpdateFundingPolicy.php`, `tests/Feature/Admin/FundingPolicyManagementTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/FundingPolicyManagementTest.php`

**Bukti:** 43 tes/166 assertions terfokus; regresi lokal 461 tes/2.334 assertions;
runner PostgreSQL disposable 31 tes/128 assertions. Semuanya lulus tanpa skip
(sandbox eksternal tidak dijalankan). Pint seluruh proyek dan PHPStan 0 error.
Tes PostgreSQL tambahan berada di `tests/Postgres/FundingPolicyManagementTest.php`.
Kontrak: `docs/PAYER_POLICY.md`; rincian RED/GREEN/batas di
`docs/ORGANIZATION_PAYMENT_TESTING.md`. Belum dipasang ke panel/route; P4b berikutnya.

## P4b: Kontrol policy pada panel ONCAM

**Acceptance:**

- [x] Form organisasi dan sumber menampilkan pilihan self/organization serta lock; menggunakan action P4a, bukan save bebas. BranchAdmin tidak bisa memberi izin sendiri.

**Dependencies:** P4a. **Scope:** M.

**Files touched:** `app/Filament/Actions/FundingPolicyAction.php`, `app/Filament/Resources/IntegrationSources/IntegrationSourceResource.php`, `app/Filament/Resources/IntegrationSources/Pages/EditIntegrationSource.php`, `app/Filament/Resources/IntegrationClients/IntegrationClientResource.php`, `tests/Feature/Admin/FundingPolicyPanelTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/FundingPolicyPanelTest.php`

**Bukti:** 12 tes/98 assertions; regresi lokal 473 tes/2.432 assertions lulus
tanpa skip, sandbox eksternal tidak dijalankan. Pint dan PHPStan 0 error.
Browser menggunakan fixture sintetis SQLite terpisah: simpan lembaga/sumber,
lock, batal, NULL, dan validasi inline. Rincian/batas di
`docs/ORGANIZATION_PAYMENT_TESTING.md`. Increment pendukung terpisah:
`tools/testing/serve-funding-panel.php`, kemudian dokumentasi. Tidak ada
migrasi DB aktif, deploy, invoice atau WA nyata; perubahan masih working tree.

## P5: Kontrak checkout opt-in

**Increment pelaksanaan:** (1) adapter, Form Request, config, tes kontrak,
dan dokumen kontrak; (2) guard provisioning umum/seleksi sebelum replay, serta
tes fallback; (3) verifikasi dan dokumentasi checkpoint. Masing-masing maksimal
lima file. Tidak menambahkan endpoint provisioning checkout sebelum P9.

**Acceptance:**

- [x] Kontrak baru tidak membuat invoice/ready otomatis; mapping legacy hanya eksplisit. Sumber cutover tidak menerima fallback v1; test compatibility mempertahankan sumber legacy yang belum cutover.

**Dependencies:** P4b. **Scope:** M.

**Files likely touched:** `app/Services/Integrations/CheckoutContractAdapter.php`, `app/Http/Requests/ProvisionCheckoutParticipantRequest.php`, `config/assessment_integration.php`, `tests/Feature/Integrations/CheckoutContractCompatibilityTest.php`, `docs/ORGANIZATION_CHECKOUT_CONTRACT.md`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutContractCompatibilityTest.php`

**Bukti:** 25 tes/99 assertions; regresi 498 tes/2.531 assertions; PostgreSQL
33 tes/134 assertions lulus, tanpa skip. Sandbox eksternal tidak dijalankan.
Pint/PHPStan lulus. Increment tambahan: dua action provisioning legacy dan
`tests/Postgres/CheckoutContractTest.php`; rincian di
`docs/ORGANIZATION_CHECKOUT_VALIDATION.md`. Tidak ada route checkout publik,
migrasi/deploy/cutover aktif, invoice atau pesan nyata. Masih working tree.

### Checkpoint setelah P5

- [x] Focused tests dan regresi terkait lulus tanpa skip; bukti baru dicatat di docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
- [x] Pint/PHPStan sesuai dampak lulus; query RLS diuji PostgreSQL terisolasi. Tidak ada perubahan frontend, sehingga browser/lint/typecheck/build tidak diulang. Race/cutover konkuren belum diklaim, batas tercatat.
- [x] Slice diserahkan dan pengguna menjawab "lanjutkan" untuk P6a; tidak ada izin deploy, transaksi, atau notifikasi nyata.

## P6a: Schema tagihan dan biaya

**Increment:** kontrak skema + migrasi/model/tes SQLite (maksimal lima file),
kemudian tes PostgreSQL dan dokumentasi. Proteksi awal kedua tabel service-only;
kebijakan akses panel/tenant lengkap tetap P6c. Tidak ada writer/API billing baru.

**Acceptance:**

- [x] Tambahkan assessment_bills dan assessment_charges dengan snapshot IDR, FK scope, unique attempt/reference/idempotency; orders lama tetap utuh. Up/down populated dan defaults diuji terisolasi.

**Dependencies:** P5. **Scope:** M.

**Files likely touched:** `database/migrations/<new>_create_assessment_billing.php`, `app/Models/AssessmentBill.php`, `app/Models/AssessmentCharge.php`, `tests/Feature/Database/AssessmentBillingSchemaTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Database/AssessmentBillingSchemaTest.php`

**Bukti:** 10 tes/40 assertions terfokus; regresi lokal 508 tes/2.571 assertions;
PostgreSQL disposable 66 tes/184 assertions. Semua lulus tanpa skip; sandbox
eksternal tidak dijalankan. Pint/PHPStan dan git diff --check lulus. Lifecycle
populated down/up diuji SQLite; CHECK, FK scope existing, unique dan FORCE RLS
dibuktikan PostgreSQL runtime. Kontrak: docs/ASSESSMENT_BILLING_SCHEMA.md;
RED/GREEN, diagnosis konteks tes, review dan batas: docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
Tidak ada bill-items, writer, invoice/akses otomatis, atau migrasi DB aktif.

## P6b: Keanggotaan dan hak per attempt

**Increment pelaksanaan:** kontrak + fixture sintetis bersama + tes SQLite;
kemudian dua migrasi/dua model; kemudian tes PostgreSQL dan penyesuaian urutan
rollback tes P6a; terakhir dokumentasi/verifikasi. Tidak mengubah migrasi P6a
yang sudah ada. Service-only RLS awal tetap berlaku hingga kebijakan P6c.

**Acceptance:**

- [x] Tambahkan assessment_bill_items dengan charge_id UNIQUE dan assessment_entitlements. Parent/child/attempt satu scope; bill self satu peserta; FK komposit atau constraint tambahan membuktikan tidak ada kaitan silang.

**Dependencies:** P6a. **Scope:** M.

**Files likely touched:** `database/migrations/<new>_create_assessment_bill_items.php`, `database/migrations/<new>_create_assessment_entitlements.php`, `app/Models/AssessmentBillItem.php`, `app/Models/AssessmentEntitlement.php`, `tests/Feature/Database/AssessmentBillItemsSchemaTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Database/AssessmentBillItemsSchemaTest.php`

**Bukti:** 12 tes/65 assertions terfokus; regresi lokal 520 tes/2.636 assertions;
PostgreSQL disposable 98 tes/327 assertions. Semua lulus tanpa skip, sandbox
eksternal dikecualikan. Pint/PHPStan dan git diff --check lulus. PostgreSQL
menolak scope/payer palsu, klaim duplikat, status/waktu invalid, dan insert
cabang. Lifecycle populated down/up masih SQLite; bukti PostgreSQL rollback
populated dan kebijakan tenant lengkap tetap P6c. Kontrak dan rincian RED/GREEN:
docs/ASSESSMENT_BILLING_SCHEMA.md dan docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
Belum ada reservasi, invoice, pelunasan atau pembukaan akses otomatis.

## P6c: RLS dan integritas PostgreSQL

**Increment:** matriks akses dan tes RLS; migrasi kebijakan baca + penyesuaian
tes historis P6a/P6b (maksimal lima file); tes lifecycle PostgreSQL berisi fixture
lama pada koneksi owner disposable khusus DDL; terakhir regresi/dokumentasi.
Tidak memberi hak tulis langsung ke pengguna atau membuat route baru.

**Acceptance:**

- [x] FORCE RLS ke empat tabel; tanpa context/lintas cabang ditolak, peserta tidak SELECT bill/item batch. Direct SQL runtime menolak duplikasi charge dan hubungan lintas tenant; migrasi berisi fixture lama diuji tanpa DB aktif.

**Dependencies:** P6b. **Scope:** M.

**Files likely touched:** `database/migrations/<new>_secure_assessment_billing.php`, `tests/Postgres/AssessmentBillingRlsTest.php`, `tests/Postgres/AssessmentBillingIntegrityTest.php`.

**Bukti:** migrasi 000500, AssessmentBillingRlsTest dan AssessmentBillingMigrationTest;
tes integritas SQL P6a/P6b dipakai ulang, bukan menduplikasi file IntegrityTest.
PostgreSQL disposable 121 tes/504 assertions dan regresi 520 tes/2.636 assertions
lulus tanpa skip. Pint/PHPStan serta git diff --check lulus. Uji lifecycle owner
dibatasi database bermarker disposable; uji izin tetap runtime non-owner.
Matriks akses dan batas: docs/ASSESSMENT_BILLING_SCHEMA.md;
rincian RED/GREEN/checkpoint: docs/ORGANIZATION_CHECKOUT_VALIDATION.md.

**Verification:** `powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1`

### Checkpoint setelah P6c

- [x] Focused tests dan regresi terkait lulus tanpa skip; bukti baru dicatat di docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
- [x] Pint/PHPStan lulus; RLS/lifecycle PostgreSQL terisolasi. Tidak ada perubahan UI sehingga browser/lint/typecheck/build frontend tidak diulang; race reservasi belum diklaim, tetap P7/P17.
- [x] Slice P6 diserahkan; pengguna menjawab "lanjutkan" untuk P7a. Tidak ada izin deploy, transaksi, atau notifikasi nyata.

## P7a: Snapshot dan preview biaya

**Increment:** kontrak + kalkulator snapshot + unit test; action/config/fixture
preview/feature test (maksimal lima file); tes PostgreSQL; regresi/dokumentasi.
Preview hanya service context dan tidak terhubung route. Attempt wajib marker
metadata checkout_contract_version=checkout-v2; P9 harus menulis marker server
ini. Legacy tidak otomatis memenuhi syarat hanya karena registry telah cutover.

**Acceptance:**

- [x] Harga paket/konsultasi dibaca DB, jumlah integer dengan pemeriksaan overflow; preview memuat hash snapshot dan alasan item tidak layak. Limit batch dari config (usulan 100), free dipisah, preview tidak mereservasi.

**Dependencies:** P6c. **Scope:** M.

**Files likely touched:** `app/Services/Payments/AssessmentPriceSnapshot.php`, `app/Actions/Payments/PreviewAssessmentBill.php`, `config/assessment_billing.php`, `tests/Feature/Payments/AssessmentBillPreviewTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillPreviewTest.php`

**Bukti:** 11 tes kalkulator/16 assertions, 22 tes preview/48 assertions
(termasuk guard soft-delete); regresi 553 tes/2.700 assertions; PostgreSQL
disposable 127 tes/531 assertions. Seluruhnya lulus tanpa skip, sandbox eksternal
dikecualikan. Pint/PHPStan dan git diff --check lulus. Kontrak internal:
docs/ASSESSMENT_BILL_PREVIEW.md; bukti/batas: docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
Tidak ada route/UI baru, reservasi, invoice, migrasi DB aktif, atau harga di kode.

## P7b: Reservasi mandiri dan kolektif

**Increment:** validasi canonical bersama preview/DTO; action internal dan tes
feature (empat file); tes PostgreSQL dua proses (satu file); dokumentasi/review
terpisah. Tidak membuat endpoint/UI atau mengaktifkan checkout-v2.

**Acceptance:**

- [x] Satu action/claim untuk self dan collective; input dipetakan ke principal, lock terurut, policy/harga reload. Seluruh daftar valid baru commit induk+items; key sama payload beda ditolak; race/total berubah tidak menerbitkan invoice parsial.

**Dependencies:** P7a. **Scope:** M.

**Files likely touched:** `app/Actions/Payments/ReserveAssessmentBill.php`, `app/Data/Payments/AssessmentBillSelection.php`, `tests/Feature/Payments/AssessmentBillReservationTest.php`, `tests/Postgres/AssessmentBillReservationTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillReservationTest.php` Jalankan juga runner PostgreSQL disposable.

**Bukti terfokus:** 29 tes/114 assertions lulus. Satu bill untuk 10/100 attempt,
limit+1 ditolak, IDR dari DB, snapshot existing tetap, free terpisah, retry
canonical, role/tenant persisted, policy/harga/kanal berubah, terminal claim,
serta rollback item kelima. PostgreSQL: tujuh race dua proses + satu rollback;
suite 135 tes/675 assertions lulus. Review membatch pembacaan charge agar tidak
SELECT per peserta; verifikasi akhir setelah perbaikan: 582 tes regresi/2.814
assertions dan PostgreSQL 135/675 lulus. Pint/PHPStan dan whitespace diff bersih.
Kontrak: docs/ASSESSMENT_BILL_RESERVATION.md. Integrasi autentikasi HTTP dan
proyeksi privat tetap tugas tahap berikutnya; action hanya menerima principal
server dalam service context, kemudian memuat ulang role/identitasnya.

## P8a: Gate akses khusus attempt

**Increment:** principal attempt + prasyarat + gate internal + fixture/feature
(lima file); tes PostgreSQL terpisah; dokumentasi/checkpoint. FormRequest dan
controller start tidak ditambah sebagai stub tanpa verifier token: pemasangan
ke jalur HTTP berada pada P8b bersama token assessment/checkout yang berbeda.

**Acceptance:**

- [x] Gate memeriksa principal, settlement tepat (paid bill+item atau free eksplisit), consent/identitas dan test_type. Tidak memakai entitlement legacy untuk attempt baru atau mempercayai total batch sebagai hak akses.

**Dependencies:** P7b. **Scope:** M.

**Files likely touched:** `app/Services/ParticipantAuth/AssessmentEntitlementGate.php`, `app/Http/Requests/StartAssessmentSessionRequest.php`, `tests/Feature/Auth/AttemptEntitlementGateTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Auth/AttemptEntitlementGateTest.php`

**Bukti:** 44 tes feature/55 assertions; regresi 626/2.869; PostgreSQL
disposable 144/690, seluruhnya lulus tanpa skip. Bug perbandingan timestamp
ber-offset direproduksi lalu diperbaiki dengan perbandingan waktu Carbon;
PG memakai clock beku pada detik sama. Pint/PHPStan/diff check lulus.
Kontrak/batas: docs/ASSESSMENT_ACCESS_GATE.md. Belum terhubung route/token/start
session; tidak mengubah login legacy, settlement, UI, atau database aktif.

### Checkpoint setelah P8a

- [x] Focused tests dan regresi terkait lulus tanpa skip; bukti baru dicatat di docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
- [x] Pint/PHPStan lulus; PostgreSQL terisolasi termasuk regresi race P7b. Tidak ada perubahan UI sehingga browser/lint/typecheck/build frontend tidak diulang.
- [ ] Tinjau slice dengan pengguna sebelum kelompok berikutnya; tidak ada deploy, transaksi, atau notifikasi nyata.

## P8b: Aktivasi individual setelah syarat lengkap

**Acceptance:**

- [ ] Service aktivasi dipanggil setelah settlement maupun pemenuhan consent; ready/outbox per attempt idempotent. Satu peserta belum consent tidak menghalangi yang lain; token attempt dan token checkout dibedakan di jalur start.

Rincian checkpoint (acceptance gabungan di atas tetap wajib):

- [x] Core aktivasi/outbox atomik per attempt, diuji dan diintegrasikan pada gelombang pertama.
- [x] Token purpose-bound dan adapter read-only start: implementasi ee7ba62/fe96239 diintegrasikan; regresi 728/3.367 dan PostgreSQL 156/921 lulus. Bukan endpoint publik atau engine sesi.
- [x] Panggilan setelah settlement: finalizer P11a memanggil aktivasi per attempt dan membuktikan rollback aktivasi/outbox dalam transaksi induk; PostgreSQL dua proses lulus pada runner root.
- [ ] Panggilan setelah pemenuhan consent/profil: dibuktikan bersama P15; tanpa invoice ulang dan tanpa auto-consent.
- [ ] Wiring publik autentikasi/RLS, producer credential dan delivery intent diverifikasi sebelum cutover; engine sesi tetap dependensi terpisah, tidak boleh diganti respons sukses palsu.

Dependensi dibedakan antara core dan integrasi: pemanggilan dari P11a/P15 tidak
dapat menjadi prasyarat implementasi awal P9 yang mendahului kedua writer itu.
P9a internal dapat dilanjutkan sesudah core P8b diverifikasi; tidak menutup P8b
keseluruhan atau mengizinkan endpoint/cutover. Semua acceptance tetap tercatat.

**Dependencies:** P8a. **Scope:** M.

**Files likely touched:** `app/Actions/Payments/ActivateSettledAssessment.php`, `app/Actions/Notifications/EnqueueAssessmentActivation.php`, `app/Http/Controllers/StartParticipantSessionController.php`, `tests/Feature/Auth/SettledAssessmentActivationTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Auth/SettledAssessmentActivationTest.php`

## P9: Provisioning tanpa akses dini

**Acceptance:**

- [ ] Persist attempt PROVISIONED, profil lengkap/parsial dalam scope autentikasi; tidak membuat invoice untuk organization. Sediakan jalur kontrak baru gated, tetap tertutup sampai checkout end-to-end siap.

**Dependencies:** P8b. **Scope:** M.

### P9a0: prasyarat schema profil parsial (review terpisah)

Keputusan: [ADR-004](../../docs/decisions/0004-checkout-partial-profile.md).
Preflight menemukan NOT NULL tidak sesuai kontrak checkout-v2 existing.

- [x] Migration baru untuk enam kolom profil nullable; nilai existing, FK/index/RLS dan enum tetap utuh. Validasi v1/registrasi publik tidak dilonggarkan. Integrasi bfc0587 dan bukti reports/integration-wave-5.md.
- [x] funding_mode NULL dibatasi ke marker checkout-v2 dengan status PROVISIONED/REVOKED/VOID; metadata hilang/NULL dan status akses ditolak oleh constraint.
- [x] Audit statis reader frontend/portal diserahkan pada 3105383 dan diintegrasikan 793d494; temuan dan batas pada reports/frontend.md. Ini bukan pengujian runtime profil nullable.
- [x] Model/readers diaudit; gate profil parsial tetap fail-closed. Uji rollback aman, compatibility legacy dan PG disposable lulus. Perbaikan label portal tetap prep sebelum wiring publik; tidak menjalankan migration pada database aktif.
- [x] Review schema P9a0 lulus lokal: 751 tes aplikasi/3.491 assertions, PG 196/1.070, Pint/PHPStan. Ini belum action provisioning atau izin cutover.
- [x] Tambahkan profile.intendedField opsional checkout-v2 beserta tes kontrak, tanpa fallback UMUM; review lulus, bukti reports/integration-wave-6.md. Action P9a kini dikerjakan, belum selesai.

### P9a: increment internal provisioning (sebelum endpoint publik)

- [x] Action internal memakai client/sumber/paket persisted dan kontrak checkout-v2 existing; policy/opt-in tetap diperiksa. Cabang tidak berasal dari referral/input bebas, identitas tidak digabung lintas organisasi lewat email/telepon.
- [x] Persist profil yang tersedia dan attempt PROVISIONED dengan marker checkout-v2 server-side secara atomik/idempotent. Tidak membuat ready entitlement, bill/invoice, credential atau notifikasi; metadata browser tidak boleh menjadi bukti paid/verified/consent.
- [x] Replay yang konsisten tidak menggandakan participant/attempt; konflik payload, sumber/tenant/paket tidak sah dan concurrent request ditolak/ditangani deterministik. Kegagalan rollback; tidak menambah placeholder identitas palsu demi memenuhi kolom wajib. Snapshot awal ADR-005, lifecycle replay dan PG race lulus; bukti wave-9.

Dependencies P9a: core P8b yang sudah diverifikasi + policy/reservasi existing
dan P9a0 yang telah direview. P9 keseluruhan dan endpoint masih unchecked. Review kontrak/rute
terpisah sebelum wiring publik; tidak mengubah v1 atau mengaktifkan sumber.

Files: `app/Actions/Integrations/ProvisionCheckoutParticipant.php`,
`tests/Feature/Integrations/CheckoutProvisioningTest.php`, tes PG baru khusus
provisioning bila transaksi/race diperlukan, serta laporan backend. Maksimal
sekitar lima file per increment; schema/request shared tidak diubah tanpa review.
Verification: focused PHPUnit dengan phpunit.organization-payment.xml, Pint,
PHPStan, PG disposable untuk RLS/race. Koordinator mengulang regresi gabungan.

**Files likely touched:** `app/Actions/Integrations/ProvisionCheckoutParticipant.php`, `app/Http/Controllers/CheckoutParticipantProvisioningController.php`, `routes/api.php`, `tests/Feature/Integrations/CheckoutProvisioningTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutProvisioningTest.php`

## P10a: Lookup invoice dengan reference tetap

Prasyarat P9 berikutnya: **P9b adapter HTTP test-only**, controller baru + tes
dengan middleware HMAC existing + dokumentasi kontrak + laporan. Route hanya
didaftarkan tes, shared routes/config/middleware tidak berubah. Respons minimal
dan error generik, no-store, create/replay/denial tanpa efek akses/billing diuji.
P9b internal lulus wave-10 dan P9c boundary route lulus wave-11. No-store/private
mencakup downstream auth/validation/throttle/contract/500; bukan error sebelum
boundary. Public wiring tetap memerlukan review terpisah. P10a internal kini
ditugaskan setelah core P9 diterima, tanpa mengklaim dependensi public/E2E selesai.

**Acceptance:**

- [x] Kontrak lookup/recovery read-only eksplisit, adapter/fake konsisten; unknown bukan izin POST ulang, nol ditolak. Review internal ce9b3a0 dan full988/5569 lulus; bukti wave-13. Tidak consumer/provider call nyata atau P10b issuance.

**Dependencies:** P9. **Scope:** M.

**Files likely touched:** `app/Contracts/PaymentProvider.php`, `app/Services/Payments/XenditProvider.php`, `app/Services/Payments/FakePaymentProvider.php`, `tests/Feature/Payments/AssessmentInvoiceLookupTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Payments/AssessmentInvoiceLookupTest.php`

### Checkpoint setelah P10a

- [ ] Focused tests dan regresi terkait lulus tanpa skip; bukti baru dicatat di docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
- [ ] Pint/PHPStan dan lint/typecheck/build sesuai dampak; UI diperiksa browser, RLS/race memakai PostgreSQL terisolasi.
- [ ] Tinjau slice dengan pengguna sebelum kelompok berikutnya; tidak ada deploy, transaksi, atau notifikasi nyata.

## P10b: Satu invoice untuk satu bill

P10b-a claim-only diterima pada wave-15: guard/lock -> issuing + intent outbox
pending/0 + audit atomik. P10b-b berikutnya mengikuti ADR-007 untuk konsumsi
izin sekali pakai dan provider fake saja; belum dispatcher/route/credential nyata.
Acceptance P10b keseluruhan tetap terbuka sampai issuance/recovery terverifikasi.

**Acceptance:**

- [x] Core internal claim/issuance worker tunggal, request fake setelah commit; gunakan AB_ reference/total induk. Sepuluh peserta menghasilkan satu create maksimum; crash/timeout menyimpan recovery/unknown tanpa invoice ganda. Bukti wave-16; belum dispatcher/credential/public wiring.

**Dependencies:** P10a. **Scope:** M.

**Files likely touched:** `app/Actions/Payments/IssueAssessmentBillInvoice.php`, `app/Jobs/IssueAssessmentBillInvoiceJob.php`, `app/Enums/AssessmentBillStatus.php`, `tests/Feature/Payments/AssessmentBillInvoiceTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillInvoiceTest.php`

## P10c: Rekonsiliasi invoice tertunda

Increment P10c-a lokal mengikuti ADR-008: satu intent unknown/processing,
strict lookup tanpa create dan persistence boundary bersama. Command/discovery
bounded serta scheduler tetap P10c-b terpisah dan nonaktif.

ADR-009 menerima desain P10c-b dua fase. P10c-b1 dibatasi pada metadata lease
additive, model/config dan bukti migrasi; token provisional belum boleh memanggil
provider atau menjadi authority sampai acquisition/validator berikutnya direview.

**Acceptance:**

- [x] P10c-a single-intent memvalidasi canonical state/policy/snapshot, commit sebelum strict GET, menempelkan exact atau mempertahankan unknown tanpa create/rearm/audit spam. Race late-state noncanonical fail-closed; bukti wave-16 final.
- [x] P10c-b1 schema durable lease additive, topic isolation, PostgreSQL CHECK/index, rollback refusal, model casts dan config bounded default-OFF. Root 37/185 serta PG252/2140 lulus; belum ada acquisition/provider/wiring.
- [x] P10c-b2a reservasi provisional outbox-only memakai database clock dan PostgreSQL SKIP LOCKED; token bukan permit, counter/state bisnis tidak berubah. Root invoice182/1323 serta PG256/2195 lulus.
- [x] P10c-b2b validator reuse claim canonical, mengonsumsi provisional dengan UUID permit baru dan generation atomik; invalid cleanup token-fenced. Root invoice197/1524 serta PG258/2247 lulus; belum provider/persist leased.
- [x] P10c-b3 strict GET reference yang sama dengan nominal/currency snapshot, late token/generation/expiry fence, cooldown database, dan race issuance exact. Tidak pernah create ulang; root 32/476 serta PG260/2292 lulus.
- [x] P10c-b4 koordinator internal bounded menggabungkan reserve → validate → execute leased memakai batch/scan/max-lookups konfigurabel, mengisi ulang lookup setelah validation rejected tanpa melewati scan, dan mengembalikan ringkasan tanpa identifier. Root 46/479 serta PG261/2302 lulus; tidak ada command/job/scheduler/route atau aktivasi akses.

**Dependencies:** P10b. **Scope:** M.

**Files likely touched:** `app/Services/Payments/ReconcileAssessmentBill.php`, `app/Console/Commands/ReconcileAssessmentBillsCommand.php`, `routes/console.php`, `tests/Feature/Payments/AssessmentBillReconciliationTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillReconciliationTest.php`

## P11a: Pelunasan induk dan semua alokasi

**Acceptance:**

- [x] Paid bill dan semua settled_at, audit, aktivasi yang memenuhi syarat, serta outbox commit atomik; kegagalan setelah item kelima rollback semuanya. Event replay no-op; nominal parsial/berlebih ditolak, paid tidak turun oleh expired. P11a1 `2f668b9`/`974ded1`, PG262/2320 lulus.

**Dependencies:** P10c. **Scope:** M.

**Files likely touched:** `app/Actions/Payments/FinalizeAssessmentBill.php`, `app/Services/Payments/AssessmentBillEventHandler.php`, `tests/Feature/Payments/AssessmentBillFinalizationTest.php`, `tests/Postgres/AssessmentBillSettlementTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillFinalizationTest.php` Jalankan juga runner PostgreSQL disposable.

### Checkpoint setelah P11a

- [x] Focused tests dan regresi terkait lulus tanpa skip; bukti finalizer dicatat pada laporan backend dan integration wave.
- [x] Pint/PHPStan lulus sesuai dampak; race dua finalizer memakai PostgreSQL disposable terisolasi.
- [ ] Tinjau slice dengan pengguna sebelum kelompok berikutnya; tidak ada deploy, transaksi, atau notifikasi nyata.

## P11b: Routing webhook dan pemeriksaan status

- [x] P11b0 audit kontrak dan P11b1 dispatcher: namespace `AB_` fail-closed tanpa fallback, duplicate merchant reference exact, terminal mapping, dan regresi legacy lulus. Implementasi root `419de35` + guard paid-only `573b278`; P11b2 masih terbuka.

**Acceptance:**

- [x] Setelah autentikasi provider, dispatcher membedakan bill vs legacy dalam event processor idempotent. AB_ tak dikenal ditolak tanpa fallback; checkStatus/reconciliation memakai finalizer yang sama; regresi webhook/order legacy tetap lulus. P11b2 root `f104801`/`c850af3`; default 1195/7472 dan PG263/2332 lulus.

**Dependencies:** P11a. **Scope:** M.

**Files likely touched:** `app/Services/Payments/PaymentEventDispatcher.php`, `app/Services/Payments/PaymentWebhookProcessor.php`, `app/Services/Payments/ReconcileAssessmentBill.php`, `tests/Feature/Payments/AssessmentBillWebhookTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillWebhookTest.php`

## P11c: Verifikasi transfer oleh ONCAM

- [x] P11c0 ADR-010 dan P11c1a schema proof identity additive selesai lokal; metadata all-or-none, format/range/key CHECK, up/down preflight, casts, RLS dan historical rollback diuji. Root `e6a53b3`/`41bffb9`/`c72a304`; belum writer/upload/UI.
- [x] P11c1b core typed review/finalizer selesai lokal pada `d8e21b7`/`ca4e21a`: persisted SuperAdmin aktif saja, approve/reject atomik, exact replay, bounded rejection, dan provider/legacy compatibility. Root default 1224/7629 dan PostgreSQL disposable 289/2471 lulus; belum storage access/upload, policy, route, atau UI.
- [x] P11c2a core private proof storage selesai lokal pada `b9a819e`/`8bf0660`: BranchAdmin pembayar atau exact participant principal self saja, MIME/size/checksum server-side, replacement fingerprint-fenced, expiry/revocation locked recheck, dan cleanup best-effort di luar transaksi. Root default 1261/7830 dan PostgreSQL disposable 292/2508 lulus; belum HTTP access/policy/UI.
- [x] P11c2b policy dan internal private-proof issuer selesai lokal pada `3a605c9`/`0887725`: hanya persisted SuperAdmin aktif, storage di luar lock, second recheck actor+fingerprint, dan audit sukses tanpa URL/key/PII. Root default 1283/7922 dan PostgreSQL disposable 293/2519 lulus; belum controller/route/UI/decision wiring.
- [x] P11c2c HTTP adapter test-only selesai lokal pada `3214126`/`9ee8768`: proof redirect privat, request decision strict, error mapping generik, header no-store/no-referrer, dan route sintetis membuktikan POST+web middleware. Root default 1310/8118 lulus; `routes/web.php` belum berubah dan UI belum dibuat.
- [x] P11c3a reviewer resource default-off selesai lokal pada `e262714`/`354d7b2`: SuperAdmin-only list/detail projection aman, hydration reauthorization, pagination/filter bounded, dan aksi buka bukti melalui issuer privat. Root default 1320/8214 lulus; approve/reject dan discovery non-testing belum aktif.
- [x] P11c3b decision actions default-off selesai lokal pada `877f0a3`/`fb8fd88`: approve/reject hanya memakai fingerprint dari audit proof-access reviewer terbaru, stale/replaced/expired/tampered/wrong-actor context fail closed, lalu delegasi ke finalizer canonical. Root default 1346/8352 lulus; browser acceptance dan aktivasi produksi belum dilakukan.
- [x] P11c3c browser acceptance testing-only selesai lokal pada `d5b7163`/`cbd861a`: aplikasi Laravel/Filament nyata dijalankan loopback dengan SQLite disposable, provider/notifier fake, jaringan eksternal diblokir, desktop/mobile/keyboard serta seluruh role denial lulus. Root focused reviewer 36/234 lulus; discovery/route produksi tetap OFF.

**Acceptance:**

- [x] SuperAdmin saja memverifikasi bukti bill dengan audit dan total tepat; cabang/staff dengan flag legacy tetap ditolak. Memakai finalizer yang sama, tidak jalur paid manual lain. Selesai lokal default-off; aktivasi produksi bukan bagian acceptance implementasi ini.

**Dependencies:** P11b. **Scope:** M.

**Files likely touched:** `app/Policies/AssessmentBillPolicy.php`, `app/Actions/Payments/VerifyAssessmentBillTransfer.php`, `app/Filament/Resources/AssessmentBills/AssessmentBillResource.php`, `app/Filament/Resources/AssessmentBills/Pages/ListAssessmentBills.php`, `tests/Feature/Admin/AssessmentBillVerificationTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/AssessmentBillVerificationTest.php`

## P12a: Daftar dan detail tagihan cabang

- [x] Implementasi lokal default-off selesai pada `9decef5`/`3a17d64` dan hardening terkait: list/detail BranchAdmin tenant-scoped, reauthorization persisted, filter/paginator/riwayat satu sumber, proyeksi aman, PostgreSQL runtime non-owner, serta browser desktop/mobile. Focused root terakhir 14/210; discovery non-testing tetap OFF.

**Acceptance:**

- [x] List/detail hanya cabang sendiri; jumlah peserta/total/status/due/invoice dari server. Paginator/filter paid menjadi riwayat dari data yang sama, tanpa ledger duplikat; peserta tidak masuk panel ini. Selesai lokal default-off; aktivasi produksi terpisah.

**Dependencies:** P11c. **Scope:** M.

**Files likely touched:** `app/Filament/Resources/OrganizationBills/OrganizationBillResource.php`, `app/Filament/Resources/OrganizationBills/Pages/ListOrganizationBills.php`, `app/Filament/Resources/OrganizationBills/Pages/ViewOrganizationBill.php`, `tests/Feature/Admin/OrganizationBillAccessTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/OrganizationBillAccessTest.php`

### Checkpoint setelah P12a

- [ ] Focused tests dan regresi terkait lulus tanpa skip; bukti baru dicatat di docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
- [ ] Pint/PHPStan dan lint/typecheck/build sesuai dampak; UI diperiksa browser, RLS/race memakai PostgreSQL terisolasi.
- [ ] Tinjau slice dengan pengguna sebelum kelompok berikutnya; tidak ada deploy, transaksi, atau notifikasi nyata.

## P12b: Bayar banyak peserta dari menu cabang

- [x] Core lokal default-off selesai pada `e872b4b`–`ec86591`: pilihan tenant-scoped, preview server-authoritative, satu delegasi ReserveAssessmentBill, replay canonical, stale/tampered hash fail closed, reauthorization persisted saat confirm, dan unexpected failure tidak disamarkan. Root focused 19/61, Pint dan PHPStan lulus.
- [x] Browser P12b selesai pada `71fc95a`/`fc8313f` dengan hardening `279450f`/`d5cf4d2`: 10 attempt, keyboard, sembilan geometry check selection/preview/detail pada 320/390/1280, stale price, double-enter canonical, replay/reload, secrecy, console/network bersih, dan direct-detail denial guest/role/tenant/foreign. Root gabungan P12a/P12b 86/1010 lulus; tetap default-off.

**Acceptance:**

- [x] Bulk selection attempt layak → preview → konfirmasi ReserveAssessmentBill; tampilkan alasan disabled dan perubahan harga/item. Invoice sekali, anggota terkunci, reload melanjutkan bill yang sama; keyboard/mobile diuji. Selesai lokal default-off; aktivasi produksi terpisah.

**Dependencies:** P12a. **Scope:** M.

**Files likely touched:** `app/Filament/Resources/AssessmentParticipants/AssessmentParticipantResource.php`, `app/Filament/Actions/CreateCollectiveBillAction.php`, `tests/Feature/Admin/CollectiveBillSelectionTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/CollectiveBillSelectionTest.php`

## P12c: Bukti transfer privat dan riwayat

- [x] Core lokal default-off selesai pada `4b8ff72`/`6a87caa`: upload/replace memakai writer P11c canonical, projection riwayat aman, branch issuer short-lived dengan role/tenant/fingerprint second recheck, config/channel guard ketat, dan audit tanpa URL/key/PII. Browser acceptance dan verifier postcondition ketat diintegrasikan sebagai `da235c7`–`5874c0a` serta `47d12e3`/`a7f70f5`; root focused terakhir 64/490, Pint, PHP lint, dan PHPStan lulus.

**Acceptance:**

- [x] Cabang unggah bukti satu bill; JPEG/PNG/PDF, invalid/oversize, replace/replay, role/tenant/deleted denial, download privat, riwayat, keyboard, dan reflow 320/390/1280 dibuktikan pada browser fixture disposable. Postcondition menerima hanya profil baseline atau P12c yang exact, mengikat satu audit akses ke aktor/tenant/bill tanpa URL/key/checksum/secret, dan membuktikan nol settlement/entitlement/outbox/order. Status unggahan tetap pending, bukan paid; seluruh discovery produksi tetap OFF.

**Dependencies:** P12b. **Scope:** M.

**Files likely touched:** `app/Actions/Payments/StoreAssessmentBillProof.php`, `app/Http/Controllers/Admin/AssessmentBillProofController.php`, `routes/web.php`, `app/Filament/Resources/OrganizationBills/Pages/ViewOrganizationBill.php`, `tests/Feature/Admin/AssessmentBillProofTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/AssessmentBillProofTest.php`

## P13a: Terbitkan token checkout terbatas

### P13a0: preflight dan ADR issue internal

- [x] ADR-011 diterima untuk implementasi lokal bertahap: issuer hanya trusted integration client persisted; purpose/destination berasal dari konstanta server; bearer 256-bit dan idempotency key opaque disimpan sebagai dua digest terpisah; replay tanpa raw token membutuhkan reissue eksplisit; TTL 60–600 detik default OFF; RLS service-only, lock order, lifecycle, rollback, dan migration safety ditetapkan.
- [x] P13a0 hanya dokumentasi/preflight. Belum ada migration/model/action/test atau wiring publik; consume P13b dan session P14 tetap terpisah.

### P13a1: schema dan model handoff

- [x] Migration additive dan model internal selesai lokal: bearer/idempotency hanya digest, lifecycle/TTL/one-active exact, attempt-client-organization-source database-authoritative melalui composite FK, source/client restrict, attempt cascade, dan FORCE RLS service-only. Down populated fail-closed dan empty descendant/ancestor roundtrip dibuktikan.
- [x] Root lulus focused SQLite 6/43, PHP lint, Pint, PHPStan, serta PostgreSQL disposable 320/2.655 sebagai runtime non-owner/NOBYPASSRLS; cleanup sukses. Belum ada issuer action, raw token generation, config, route, consume, atau session.

### P13a2: issuer internal default-off

- [x] Typed issuer internal selesai lokal: service context + authenticated client persisted, purpose/destination konstanta server, raw bearer/idempotency hanya hidup di parameter/result private, exact replay tanpa raw, explicit reissue atomik, post-lock database-clock recheck, package IDR canonical, audit aman, dan rollback old generation terbukti. Config committed default OFF/TTL 600.
- [x] Root lulus related checkout/schema 139/979, PHP lint, Pint, PHPStan, dan diff-check. Belum ada route/controller, consume/session, atau klaim race PostgreSQL; P13a3 tetap wajib sebelum acceptance P13a ditutup.

**Acceptance:**

- [x] Hash token short-lived terikat attempt/tujuan; reissue mencabut lama tanpa duplikasi charge/bill; tidak memuat PII/credential di URL/log. P13a3 root `ce84b1f`–`6f606e2`; local 29/274 dan PostgreSQL disposable 328/2.768 lulus.

**Dependencies:** P12c. **Scope:** M.

**Files likely touched:** `database/migrations/<new>_create_checkout_handoffs.php`, `app/Models/CheckoutHandoff.php`, `app/Actions/Integrations/IssueCheckoutHandoff.php`, `tests/Feature/Integrations/CheckoutHandoffIssueTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutHandoffIssueTest.php`

### Checkpoint setelah P13a

- [x] Focused tests dan regresi terkait lulus tanpa skip; bukti P13a1–P13a3 dicatat pada laporan backend dan koordinasi kanonik.
- [x] Pint/PHPStan sesuai dampak lulus; P13a tidak mengubah UI, sedangkan RLS/race memakai PostgreSQL disposable terisolasi tanpa port publik.
- [ ] Tinjau slice dengan pengguna sebelum kelompok berikutnya; tidak ada deploy, transaksi, atau notifikasi nyata.

## P13b: Konsumsi token satu kali

**Acceptance:**

- [x] Consume atomik dengan expiry/replay checks menghasilkan scope sesi checkout internal saja; dua request paralel tepat satu pemenang dan invalid tidak fallback. Root `605e54c`/`30a57f1`: Integrations 192/1.352 dan PostgreSQL disposable 331/2.811 lulus; belum ada session/cookie/route P14.

**Dependencies:** P13a. **Scope:** M.

**Files likely touched:** `app/Actions/Integrations/ConsumeCheckoutHandoff.php`, `tests/Feature/Integrations/CheckoutHandoffConsumeTest.php`, `tests/Postgres/CheckoutHandoffReplayTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutHandoffConsumeTest.php` Jalankan juga runner PostgreSQL disposable.

## P14: Sesi ringkasan privat

**Acceptance:**

- [ ] Sesi checkout CSRF/no-store/no-referrer, principal attempt; status miliknya saja, tidak bocor anggota/total/invoice batch. Jalur role salah/IDOR ditolak.

**Dependencies:** P13b. **Scope:** M.

**Files likely touched:** `app/Http/Controllers/IntegratedCheckoutController.php`, `app/Http/Requests/ConsumeCheckoutHandoffRequest.php`, `app/Http/Middleware/AuthenticateCheckoutSession.php`, `routes/web.php`, `tests/Feature/Integrations/CheckoutSummaryTest.php`.

**Verification:**
`php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutSessionEstablishmentTest.php tests/Feature/Integrations/CheckoutSessionLifecycleTest.php tests/Feature/Integrations/CheckoutSessionHttpTest.php tests/Feature/Integrations/CheckoutSummaryComposerTest.php tests/Feature/Integrations/CheckoutSummaryHttpTest.php tests/Feature/Integrations/CheckoutSummaryLifecycleTest.php tests/Feature/Integrations/CheckoutPrivacyHeadersTest.php tests/Feature/Integrations/CheckoutProductionWiringTest.php`

**Bukti core lokal:** sesi/cookie/CSRF, proyeksi ringkasan milik attempt, privacy
headers, lifecycle, dan route canonical default-off sudah terintegrasi. Acceptance
tetap unchecked sampai browser dan checkpoint lintas tahap selesai.

## P15: Profil kurang dan consent

**Acceptance:**

- [ ] Hanya field kurang boleh diisi; cabang/payer/paket/nominal locked tidak dapat diganti. Consent versioned dan pilihan DASS tanpa duplikasi; sudah settled dapat aktif setelah syarat lengkap tanpa invoice baru.

**Dependencies:** P14. **Scope:** M.

**Files likely touched:** `app/Http/Requests/ConfirmIntegratedCheckoutRequest.php`, `app/Actions/Registration/ConfirmIntegratedCheckout.php`, `tests/Feature/Registration/IntegratedCheckoutConsentTest.php`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Registration/IntegratedCheckoutConsentTest.php`

**Bukti core lokal:** request/DTO/action writer, HTTP adapter JSON, idempotensi,
rollback, profil missing-only, serta consent psikotes dan DASS-21 wajib telah
terintegrasi. Subset requirement current, withdrawal/re-consent, rotasi dokumen,
histori bergenerasi, actor sesi, interval waktu, rollback, dan privacy audit RLS
telah dibuktikan pada `918eb93`/`ae54cd6`. Route writer tetap default OFF;
acceptance tidak ditutup sebelum browser/checkpoint.

### Checkpoint setelah P15

- [ ] Focused tests dan regresi terkait lulus tanpa skip; bukti baru dicatat di docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
- [ ] Pint/PHPStan dan lint/typecheck/build sesuai dampak; UI diperiksa browser, RLS/race memakai PostgreSQL terisolasi.
- [ ] Tinjau slice dengan pengguna sebelum kelompok berikutnya; tidak ada deploy, transaksi, atau notifikasi nyata.

## P16: Halaman checkout mandiri/kolektif/gratis

**Acceptance:**

- [ ] Profil lengkap tidak registrasi ulang; mandiri memakai reservasi self, organization melihat menunggu cabang/batch/paid sendiri. Free lewat jalur eksplisit; responsive/keyboard/brand ONCAM, no client-authoritative pricing.

**Dependencies:** P15. **Scope:** M.

**Files likely touched:** `resources/js/pages/integrated-checkout.tsx`, `resources/js/types/integrated-checkout.ts`, `app/Http/Controllers/IntegratedCheckoutController.php`, `tests/Feature/Integrations/IntegratedCheckoutPageTest.php`.

**Verification:**
`php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutProductionWiringTest.php tests/Feature/Integrations/CheckoutMandatoryDassHttpPresentationTest.php tests/Feature/Integrations/CheckoutPaymentActionProjectorTest.php tests/Feature/Integrations/CheckoutPaymentHttpTest.php tests/Feature/Integrations/CheckoutPaymentHttpBoundaryTest.php tests/Feature/Integrations/CheckoutZeroPriceSettlementTest.php`
`node --test tests/Frontend/IntegratedCheckout/checkout-summary-v2.test.mjs tests/Frontend/IntegratedCheckout/confirmation-transport.test.mjs tests/Frontend/IntegratedCheckout/payment-transport.test.mjs`
`php tests/Frontend/IntegratedCheckout/summary-render.test.php`
`php tests/Frontend/IntegratedCheckout/summary-mandatory-dass-render.test.php`

**Bukti parsial lokal:** Blade branded/no-JS, form injected fail-closed, transport
JSON same-origin, session-authorized writer self/gratis, proyeksi URL persisted,
boundary HTTP, controller, dan route pembayaran default-off sudah terintegrasi.
DASS-21 wajib pada provisioning, snapshot harga, issuance/consume/recovery
handoff, serta graph session. Kontrak strict TypeScript summary-v2, fixture DASS
bersama, evidence pembayaran canonical, dan proyeksi action server telah diterima
melalui `cac65f1`, `04e1499`, `ce036bb`, `edebc7c`, dan `1547501`. Bukti terbaru:
root **207/2.189**, handoff **29/385**, frontend **8/8** plus TS/lint/format.
Binding Blade/UI summary-v2 telah selesai lokal melalui `4110213`, `cc69179`,
`87cf221`, `0f46e29`, dan hardening `918eb93`/`ae54cd6`. Bukti hardening terbaru:
Blade **19+7**, Node **4**, PHP/HTTP **38/684**, PostgreSQL privacy **2/30**,
serta PHPStan/ESLint/Prettier/Pint/diff-check. Runtime browser/P17c
belum dijalankan; checkbox P15/P16 tetap terbuka dan payment tetap default OFF.
Payer organisasi positif tetap
baca-saja tanpa URL/action, sedangkan organisasi berharga tepat nol hanya memakai
pengecualian no-money yang sudah diterima ADR-014.

## P17a: Regresi alur dan privasi

**Acceptance:**

- [x] Kedua sumber seleksi fake, profil lengkap/parsial, sepuluh peserta mixed package, free+konsultasi, lintas cabang, dua attempt, unpaid/paid tanpa consent. Proyeksi peserta dan panel tidak bocor klinis/batch.

**Dependencies:** P16. **Scope:** M.

**Files likely touched:** `tests/Feature/Integrations/OrganizationCheckoutAcceptanceTest.php`, `tests/Postgres/OrganizationCheckoutRlsTest.php`, `docs/ORGANIZATION_CHECKOUT_VALIDATION.md`.

**Verification:** `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/OrganizationCheckoutAcceptanceTest.php` Jalankan juga runner PostgreSQL disposable.

**Bukti:** implementasi memakai `CheckoutAcceptanceMatrixTest`. Root membuktikan
lima proses terpisah masing-masing 1/277 dan gabungan production wiring serta
collective lifecycle 11/1.744. Fresh PostgreSQL disposable 392/3.799 mencakup
RLS billing, lintas tenant, DASS consent privacy, serta schema/lifecycle terkait.
Provider/notifier fake, SQLite memory, dan feature config-memory; tidak ada browser
atau aktivasi publik.

## P17b: Uji konkurensi dan pemulihan crash

**Acceptance:**

- [x] Dua koneksi/proses runtime nyata dengan barrier: overlapping batch, self vs batch, parallel webhook/manual, crash setelah item kelima dan claim issuance, replay/reorder. Bukan sequential test yang disebut concurrency.

**Dependencies:** P17a. **Scope:** M.

**Files likely touched:** `tests/Postgres/OrganizationBillingConcurrencyTest.php`, `tools/testing/organization-billing-race-worker.php`, `tools/testing/run-org-postgres.ps1`, `docs/ORGANIZATION_CHECKOUT_VALIDATION.md`.

**Verification:** `powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1`

**Bukti:** tes existing membuktikan race dua proses dengan barrier dan observed
PostgreSQL lock untuk reservation, self-vs-batch, claim/issuance, finalizer,
manual review, dan activation. `OrganizationBillingSettlementRecoveryTest`
menambah crash tepat save allocation kelima dari sepuluh untuk webhook serta
manual, seluruh state rollback, lalu retry exact berhasil sekali. Root disposable
394/3.865 lulus; cleanup sukses. Replay/reorder terminal diuji sebagai urutan
event, bukan diklaim concurrency.

### Checkpoint setelah P17b

- [x] Focused concurrency/crash dan regresi PostgreSQL disposable lulus tanpa skip; bukti dicatat di docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
- [x] Pint/PHPStan sesuai dampak lulus; RLS/race memakai PostgreSQL terisolasi. Verifikasi UI/browser bukan bagian checkpoint backend ini.
- [x] Slice backend diterima lokal sebagai dependensi teknis P17c; tidak ada deploy, transaksi, atau notifikasi nyata.

## P17c: Browser menu cabang dan peserta

**Acceptance:**

- [ ] Verifikasi desktop/mobile/keyboard: multi-select 10 → satu invoice fake → semua paid; alasan disabled, stale preview, reload, expired, consent tertunda, IDOR. Gunakan test-only origin dan data sintetis; rekam bukti tanpa token.

**Dependencies:** P17b. **Scope:** S.

**Files likely touched:** `tests/Browser/organization-collective-checkout.spec.ts`, `docs/ORGANIZATION_CHECKOUT_VALIDATION.md`.

**Verification:** Browser skill pada origin test saja; command runner E2E ditentukan setelah tool/package yang sudah tersedia diperiksa. Catat screenshot/alur dan hasil, tidak membuat klaim uji sebelum dijalankan.

Verifikasi UI desktop/mobile/keyboard hanya berada pada P17c dan masih terbuka;
hasil backend P17b tidak menggantikannya.

**Bukti runtime parsial 2026-09-07:** harness cabang pada loopback test-only kini
menghubungkan satu graph yang sama dari multi-select 10, reservation, satu
invoice `FakePaymentProvider`, settlement 10 item, replay idempoten, reload
status paid, dan proyeksi consent 10/10 maupun 9/10. Run juga mencakup alasan
disabled, stale preview, expired/reload, IDOR, keyboard native, serta geometry
320/390/1280 tanpa console/network failure. Regresi profil P12b tetap lulus.
Acceptance tetap unchecked: ini browser cabang berbasis HTTP loopback dan belum
membuktikan UI peserta pada secure test-only origin/candidate ADR-021/022.

## P18: Runbook dan serah-terima

**Acceptance:**

- [ ] Dokumentasi operasi menjelaskan unknown/expired/rejected tanpa auto-release/reinvoice, konsistensi allocation, rollback aplikasi yang mempertahankan histori, dan cutover opt-in tanpa fallback. Tidak deploy atau mengirim pesan nyata.

**Dependencies:** P17c. **Scope:** M.

**Files likely touched:** `docs/ORGANIZATION_CHECKOUT_OPERATIONS.md`, `docs/ORGANIZATION_CHECKOUT_VALIDATION.md`, `tasks/organization-payment/todo.md`.

**Verification:** `git diff --check`; verifikasi tautan dan cocokkan runbook dengan bukti P17 (bukan tes kosong).

### Checkpoint setelah P18

- [ ] Focused tests dan regresi terkait lulus tanpa skip; bukti baru dicatat di docs/ORGANIZATION_CHECKOUT_VALIDATION.md.
- [ ] Pint/PHPStan dan lint/typecheck/build sesuai dampak; UI diperiksa browser, RLS/race memakai PostgreSQL terisolasi.
- [ ] Tinjau slice dengan pengguna sebelum kelompok berikutnya; tidak ada deploy, transaksi, atau notifikasi nyata.

### Catatan bukti accepted 2026-09-06 — status checklist tidak berubah

Commit `3f0acc2` menerima strict TypeScript mixed-package DASS-21 + minimal satu
non-DASS; `0ecb5f1` menerima refresh static harness checkout-summary-v2;
`70c174b` menerima hardening ownership journal; `a2aa851` menerima verifier
struktur runbook; dan `e8fdad8` menandai klaim P8 DASS historis sebagai
disupersede perilaku checkout-v2 saat ini. Tipe dan fixture sintetis sekarang
selaras dengan invariant runtime DASS current wajib, tanpa mengubah scoring atau
hasil tes utama.

Harness tersebut belum dijalankan sebagai browser runtime. Recovery journal
memerlukan latest anchor independen yang belum dipublikasikan runner disabled;
helper `_ps` dan pembuktian ownership/cleanup OS nyata juga tetap gate. Verifier
P18 hanya preparation, bukan bukti runbook atau serah-terima lengkap. Karena itu
P15, P16, P17c, P18, serta seluruh checkpoint terkait tetap unchecked.

Preparation ownership kini dikonsolidasikan pada `94e294d`, `d41844a`,
`e15f55e`, `12ce0bb`, `231d64d`, dan `8c73391`: strict input/browser **79/79**,
supervisor **89/89 pure/mock**, adapter coordinator **12/12**, anchor store
**14/14**, candidate builder **17/17**, serta verifier arsitektur/runbook P18
**7/7 tes, 190 assertions**. Semua bukti code-only; census host nyata dan builder
terhadap source nyata tidak dijalankan. Local anchor bukan bukti autentisitas;
ACL/single-writer, durability/TOCTOU Windows, lineage/helper/cleanup OS, X.509
semantik, inventory vendor/delivery candidate, standalone recovery, serta
browser/service runtime masih gate. Runner masih absent/disabled, payment tetap
default OFF dan tidak ada checkbox acceptance yang berubah.

### Bukti delivery JavaScript accepted — status checklist tidak berubah

`5b03674` menerima delivery test-only dua JavaScript melalui route literal yang
fail closed; `3229f82` menerima review closure yang sama persis pada builder dan
supervisor. Bukti yang boleh dipakai: asset-pure lane **210 assertions**, root
contract **15 assertions**, self-test **64 checks**, builder **18/18**, dan
supervisor **90/90 pure/mock**. Full junction OS tidak dijalankan, sedangkan
asset-pure root yang timeout tidak dihitung sebagai bukti.

Percobaan Composer parity tidak diterima dan tetap uncommitted/rejected karena
parity metadata runtime Composer belum lengkap. Semantik penuh X.509, runtime
Composer, candidate nyata, browser/service/OS, dan seluruh gate sebelumnya tetap
terbuka; payment default OFF dan checkbox P15/P16/P17c/P18 tidak ditutup.

### Bukti cert/key builder accepted — checklist tetap terbuka

Commit `7025c83` menerima pemeriksaan lokal parseability dan kecocokan cert/key,
penolakan noninteraktif seluruh key terenkripsi, serta revalidasi identity+hash
sebelum/sesudah load. Root lulus builder **20/20**, parity AST **2/2**, dan scan
header private-key fixture bersih.

Belum ada bukti SAN/validity/EKU/CA/chain/trust/key strength, browser, atau ACL.
Tidak ada candidate build, deploy, maupun runtime aktif; checkbox P17c/P18 tidak
ditutup dan gate tetap default OFF.

### Bukti envelope Playwright accepted — checklist tetap terbuka

`d954b3c` menerima framing marker own-line dan JSON utuh yang strict, termasuk
penolakan duplicate/nonfinite/trailing. Schema smoke/full, counter, history,
check canonical, serta return source driver kini exact-bound. Root lulus
supervisor **93/93**, parity AST **2/2**, dan check Node; klaim script hanya untuk
checkout default-OFF.

Tidak ada browser, service/aplikasi, DB, env aktif, network, atau candidate;
hanya proses tes Python/Node lokal. P17c/P18 tidak dicentang dan semua gate
tetap default OFF.

### Bukti browser-config strict accepted — checklist tetap terbuka

`a391612` menerima schema browser-config exact dari candidate builder, termasuk
offline/service-worker block, isolated/headless, executable dan launch args
berurutan exact, serta timeout integer exact. JSON bounded menolak UTF-8 invalid,
duplicate, nonfinite, trailing, tipe salah, dan key tambahan. Hash, parse, serta
recheck menggunakan descriptor yang sama; validasi dilakukan pada preflight dan
segera sebelum browser launch intent. Root lulus supervisor **96/96** dan parity
AST **2/2**.

Penggantian direktori run/source dan TOCTOU akhir pembukaan path oleh CLI
eksternal belum ditutup. ACL, single-writer, serta lifecycle identity tetap gate
P17c. Tidak ada candidate/browser/service/DB/env/network/deploy atau aktivasi;
checkbox P17c/P18 tidak berubah dan seluruh gate tetap default OFF.

### Bukti binding konfigurasi kandidat accepted — checklist tetap terbuka

- [x] Commit `23ed855` mewajibkan schema exact builder/supervisor, path canonical,
  nama file runtime tetap, dan binding directory/manifest/session/tool/hash/review aset.
- [x] Recovery menolak config sebelum anchor I/O; coordinator memetakan nested
  refusal ke `coordinator_config`; parity AST tidak mengeksekusi source supervisor.
- [x] Root lulus coordinator+builder **34/34**, supervisor aman **98/98**, AST
  **5 file**, dan diff-check.
- [ ] ACL/single-writer, directory replacement, lifecycle identity OS, final
  external path-open TOCTOU, serta runtime browser P17c tetap belum dibuktikan.

Tidak ada candidate/browser/service/DB/env/network/deploy atau aktivasi;
checkbox P17c/P18 tetap terbuka dan seluruh gate/payment tetap default OFF.

### Bukti kontrak/policy ACL Windows accepted — runtime gate tetap terbuka

- `e47406a` menerima parser policy canonical dan codec attestation exact dengan
  binding challenge, policy, lease, path, dan identity.
- `8461e03` mem-pin digest policy final
  `a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb`
  pada closure candidate, CONFIG_KEYS/configBinding, dan preflight descriptor
  sebelum identity probe atau proses.
- Evidence root/agent: policy+codec **13/13**, builder **26/26**, supervisor aman
  **102/102** (real-listener dikecualikan), `py_compile`, AST, dan diff-check.
- Tetap belum terbukti: ACL recursive seluruh descendant source tree, effective
  access Windows nyata, cross-process/crash, reparse/rename, durability,
  candidate/browser P17c, dan operasi P18.

Tidak ada checkbox acceptance yang diubah. Tidak ada runtime/browser/service/DB/
network/deploy/aktivasi; P17c/P18 tetap terbuka dan gate/payment default OFF.

### Bukti ACL anchor admission accepted — runtime gate tetap terbuka

- `20cf713` menambah jalur admission supervisor untuk evidence anchor-only dan
  mem-pin codec, lease, serta attestor secara exact.
- Konsumsi one-shot memakai sentinel `None`; discard dan seluruh `BaseException`
  gagal tertutup dengan cleanup tetap dijalankan.
- Root lulus supervisor aman **116/116**, `py_compile`, dan diff-check;
  cross-review **PASS** dan real-listener tidak dijalankan.
- Publisher, execution/enforcement attestor, wiring coordinator, descendant
  source-tree ACL, serta Windows runtime/crash/reparse/rename masih terbuka.

Tidak ada checkbox acceptance yang diubah. Browser P17c dan operasi P18 tetap
terbuka; tidak ada runtime atau aktivasi dan seluruh gate/payment default OFF.

### Bukti ACL execution admission accepted — runtime gate tetap terbuka

- `221ae47` mengikat publisher ke transisi exact `anchor_consumed`, mem-pin
  codec/lease/attestor exact, dan memakai challenge kedua/source pair baru untuk
  execution admission.
- Session, config binding, dan lease divalidasi dari current context; token
  opaque aktif hanya selama admission, exhausted setelah konsumsi, dan selalu
  dibersihkan.
- Root lulus supervisor aman **127/127**, `py_compile`, diff-check, dan review
  **PASS**; real-listener tidak dijalankan.
- Wiring `supervise`/`recover`, preflight, claim, `_command`, launch,
  coordinator, real attestor, serta enforcement/runtime masih terbuka.

Tidak ada checkbox acceptance yang diubah. Browser P17c dan operasi P18 tetap
terbuka; tidak ada aktivasi dan seluruh gate/payment default OFF.

### Bukti ACL execution gates accepted — runtime gate tetap terbuka

- `15a5509` mewajibkan admission pada wrapper dan direct boundary dengan recheck
  tepat sebelum setiap `Popen`.
- Recovery mengikat session+anchor exact dari gated load one-shot; coordinator
  merangkai urutan canonical dan memetakan refusal ke error fixed tanpa menutupi
  `BaseException` utama.
- Root lulus supervisor aman **133/133** (real-listener dikecualikan),
  coordinator+lease **38/38**, AST **4 file**, `py_compile`, diff-check, dan
  adversarial review **PASS**.
- Real attestor Windows, recursive descendant source-tree ACL/effective access,
  cross-process/crash, reparse/rename/durability, dan browser runtime masih
  terbuka.

Tidak ada checkbox acceptance yang diubah. P17c/P18 tetap terbuka; tidak ada
runtime/deploy dan seluruh gate/payment default OFF.

### Bukti source identity/façade accepted — checklist tetap terbuka

- [x] `30c78ee` mem-pin source root/ancestor selama inventory, open/hash/copy,
  dan sebelum config final; swap/reparse gagal dengan candidate incomplete.
- [x] Cleanup semua descriptor mempertahankan error atau interruption utama.
- [x] `bfda8c4` mewajibkan coordinator assembly/binding sebelum delegasi
  fresh/recovery; root lulus **42/42 tes**, AST **4 file**, dan diff-check.
- [ ] Candidate-global lifecycle lease, ACL Windows, identity lintas-crash,
  final namespace/path-open TOCTOU, dan runtime browser masih belum dibuktikan.

Tidak ada candidate/lifecycle nyata/browser/service/DB/env/network/deploy atau
aktivasi; checkbox P17c/P18 tetap terbuka dan gate/payment tetap default OFF.

### Bukti lifecycle lease accepted — runtime gate tetap terbuka

- [x] `fe45bb2` menambah lease run-local stabil/no-unlink, lock nonblocking,
  descriptor non-inheritable, identity/content pin, dan cleanup exception-safe.
- [x] `cec171c` memperoleh lease sebelum assembly/anchor, menutup race snapshot
  config, serta mewajibkan supervisor admission dan publisher pre/post check.
- [x] Dua coordinator directory untuk run yang sama berkontensi; bare claim dan
  recovery ditolak. Root lulus **58/58 + 101/101**, AST **8**, dan diff-check.
- [ ] Cross-process/crash lock Windows, whole-run rename/reparse, NTFS durability,
  ACL/effective access, dedicated process, dan browser acceptance belum dibuktikan.

Tidak ada runtime/browser/service/DB/env/network/deploy atau aktivasi;
checkbox P17c/P18 tetap terbuka dan seluruh gate/payment tetap default OFF.

### Bukti pure ACL source-tree summary accepted — runtime gate tetap terbuka

- `6256dc8` memvalidasi manifest nonempty dengan exact file+implied-directory
  closure, path ASCII Windows-safe yang bounded, identity record unik, serta
  bentuk owner SID dan digest DACL exact.
- Manifest+records canonical memakai ordering deterministik, digest
  domain-separated dengan known vector, dan parity summary exact antar-boundary.
- Bukti accepted **8/8 tes**, `py_compile`, diff-check, dan adversarial review
  **PASS**.
- Primitive belum terintegrasi ke codec/policy/attestor/builder; native tree
  completeness, empty directory di luar manifest, identity root yang tidak
  masuk summary, ACL enforcement/effective access, dan Windows runtime tetap
  terbuka.

Tidak ada checkbox acceptance yang diubah. P17c/P18 tetap terbuka; tidak ada
runtime/deploy/aktivasi dan seluruh gate/payment tetap default OFF.

### Bukti lazy Win32 ACL ABI boundary accepted — runtime gate tetap terbuka

- `2b715e1` mendefinisikan import lazy tanpa usable attestor/native call;
  non-Windows menolak sebelum `WinDLL`.
- Oracle independen mem-pin **28 signature exact**, ownership DLL,
  `use_last_error`, width/layout, dan resolver sempit dengan revalidasi
  identity/signature/bundle yang fail closed terhadap mutasi.
- Error tetap fixed/redacted dan `KeyboardInterrupt`/`SystemExit` dipertahankan;
  bukti accepted **7/7 tes**, `py_compile`, diff-check, dan cross-review **PASS**.
- Real attestor, scanner, cache, composition wiring, dan native efficacy belum
  tersedia atau terbukti.

Tidak ada checkbox acceptance yang diubah. P17c/P18 tetap terbuka; tidak ada
native/runtime/deploy/aktivasi dan seluruh gate/payment tetap default OFF.

### Bukti private Windows ACL directory handle accepted — runtime tetap terbuka

- `038cbdd` membuka directory handle dengan access/share/flags exact, menjaganya
  non-inheritable, dan menolak non-directory atau reparse point.
- Final path canonical serta identity harus stabil pada dua pembacaan;
  `FILE_ID_128` adalah 16 byte opaque yang diserialisasi menjadi desimal
  canonical dengan interpretasi big-endian.
- Handle ditutup tepat sekali tanpa menutupi exception atau
  `KeyboardInterrupt`/`SystemExit` utama. Bukti root accepted **16/16 tes**,
  `py_compile`, diff-check, dan final cross-review **PASS**.
- Pengujian hanya memakai fake ABI. Belum ada real attestor/scanner/cache,
  composition/candidate closure, wiring codec/policy/source-tree,
  owner/DACL/token/`AccessCheck`, recursive completeness, empty-dir/root binding,
  native ACL efficacy, race/reparse/rename/TOCTOU, crash, atau browser runtime.

Tidak ada checkbox acceptance yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada native/runtime/deploy/aktivasi dan seluruh gate/payment default OFF.

### Bukti private Windows ACL descriptor snapshot accepted — runtime tetap terbuka

- `49d6b87` memperluas ABI menjadi **29 signature**, termasuk `IsValidAcl`, dan
  mengambil dua descriptor snapshot melalui live directory handle yang sama.
- Descriptor wajib self-relative/bounded/revision 1; pointer owner, group, dan
  DACL harus interior. DACL wajib present non-NULL, protected, tidak
  auto-inherited, dan revision 2.
- Seluruh rentang `AclSize` harus contained sebelum traversal dan digest memakai
  exact `AclBytesInUse`; hanya pointer allocation base di-`LocalFree` tepat sekali.
- Bukti root accepted **24/24 tes**, `py_compile`, diff-check, dan final
  adversarial review **PASS**. Tes tetap pure fake ABI.
- SID string/process-token owner match, parsing ACE exact, policy/effective
  `AccessCheck`, source-tree/composition/cache/attestor, native runtime, dan race
  protection belum tersedia atau terbukti.

Tidak ada checkbox acceptance yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada runtime/deploy/aktivasi dan seluruh gate/payment default OFF.

### Bukti private Windows ACL single-ACE semantics accepted — runtime tetap terbuka

- `97d7b33` mengambil ACE melalui live `GetAce`, menyalinnya, dan mem-parsing
  copied bytes secara strict; ABI tetap **29 signature** karena API telah ada.
- Tepat satu allow ACE type 0/flags 3 wajib mengonsumsi seluruh
  `AclBytesInUse`, dengan pointer, alignment, dan full containment exact.
- SID revision/count/full-span diperiksa sebelum `IsValidSid`; authority
  big-endian dan subauthority little-endian diserialisasi canonical. Trustee
  wajib exact owner dan dua semantic snapshot immutable wajib identik.
- Bukti root accepted **27/27 tes**, `py_compile`, diff-check, dan adversarial
  review **PASS**; seluruh tes masih pure fake ABI.
- Mask role/policy, process-token owner/privilege, effective `AccessCheck`,
  source-tree/cache/composition/attestor, native runtime, dan race belum tersedia.

Tidak ada checkbox acceptance yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada runtime/deploy/aktivasi dan seluruh gate/payment default OFF.

### Bukti process-token owner binding accepted — runtime tetap terbuka

- `9301369` menjalankan urutan exact token-before → descriptor-1 → descriptor-2
  → token-after dan mewajibkan token SID stabil serta kedua owner exact match.
- `GetCurrentProcess` pseudo-handle tidak ditutup; real
  `OpenProcessToken(TOKEN_QUERY)` handle non-inheritable dan ditutup exact sekali.
- Probe `TokenUser` bounded harus menghasilkan error 122 lalu fill exact; SID
  tidak overlap header dan full-contained sebelum native validation. Output
  `processTokenSid` immutable.
- Bukti root accepted **30/30 tes**, `py_compile`, diff-check, dan adversarial
  review **PASS**; seluruh pengujian masih pure fake ABI.
- Groups/privileges/LocalSystem, role mask, impersonation, effective
  `AccessCheck`, source-tree/cache/composition/attestor, native runtime, dan races
  masih terbuka.

Tidak ada checkbox acceptance yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada runtime/deploy/aktivasi dan seluruh gate/payment default OFF.

### Bukti current-process TokenGroups accepted — runtime tetap terbuka

- `301a823` membaca `TokenGroups` dengan probe/fill exact dan batas **256
  KiB/4096 group**.
- Layout `ANYSIZE_ARRAY`, tabel, serta full span diperiksa; SID harus tidak
  overlap header/tabel atau sesama SID sebelum `IsValidSid` lalu `GetLengthSid`.
- Semua group+attributes disimpan berurutan tanpa filtering; duplikat ditolak,
  bukan dideduplikasi; profil
  immutable sebelum/sesudah descriptor snapshots harus identik pada token handle
  yang sama, dengan cleanup exact.
- Bukti root accepted **35/35 unittest**, `py_compile`, diff-check, dan final
  adversarial review **PASS** tanpa P1/P2; seluruh tes tetap pure fake.
- Ordinary second principal/provenance, privilege checks, ordinary LocalSystem
  exclusion, impersonation, role/policy mask, effective `AccessCheck`,
  source-tree/cache/composition/attestor, native Windows/runtime/races tetap
  terbuka.

Tidak ada status/checklist yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada runtime/deploy/aktivasi dan seluruh gate/payment default OFF.

### Bukti current-process TokenPrivileges accepted — runtime tetap terbuka

- `ea9fe9c` membaca `TokenPrivileges` dengan probe/fill exact dan batas **256
  KiB/4096 privilege**.
- Inline `ANYSIZE_ARRAY` wajib mengonsumsi buffer exact tanpa trailing; semua
  entry ordered/immutable sebagai `(LowPart uint32, HighPart int32, Attributes
  uint32)` dan duplicate LUID ditolak.
- Profil privilege sebelum/sesudah descriptor snapshots harus stabil pada token
  handle yang sama dengan cleanup exact.
- Bukti root accepted **39/39 unittest**, `py_compile`, diff-check, dan final
  adversarial review **PASS** tanpa P1/P2; ini pure fake current-process only.
- `LookupPrivilegeValue`/name mapping, enabled dangerous privilege policy,
  ordinary principal/provenance, LocalSystem, token type/restriction/
  impersonation, effective `AccessCheck`, source-tree/cache/composition/attestor,
  dan native Windows/runtime tetap terbuka.

Tidak ada status/checklist yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada aktivasi/deploy dan seluruh gate/payment default OFF.

### Bukti sensitive-privilege observation accepted — runtime tetap terbuka

- `4f015f3` mengobservasi urutan fixed `SeBackupPrivilege`,
  `SeRestorePrivilege`, dan `SeTakeOwnershipPrivilege` pada current process.
- `LookupPrivilegeValueW` memakai system `None`; signed/unsigned LUID wajib exact
  dan duplicate mapping ditolak.
- Hasil immutable mengikat name/LUID/present/enabled; enabled hanya
  `Attributes & 0x2`, dengan kasus absent/disabled/enabled terpisah. Profile
  sebelum/sesudah wajib stabil dan cleanup tetap exact.
- Bukti root accepted **43/43 unittest**, `py_compile`, diff-check, dan final
  adversarial review **PASS** tanpa P1/P2; seluruh tes masih pure fake.
- Ini bukan rejection policy atau ordinary evidence. Second-token provenance,
  LocalSystem, token type/restriction/impersonation, effective `AccessCheck`,
  source-tree/cache/composition/attestor, serta native Windows/runtime terbuka.

Tidak ada status/checklist yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada aktivasi/deploy dan seluruh gate/payment default OFF.

### Bukti fixed token profile accepted — runtime tetap terbuka

- `95450a6` mengobservasi `TokenType` sebagai `TOKEN_TYPE` yang wajib exact
  `TokenPrimary`, serta `TokenIsAppContainer` sebagai nilai `DWORD` raw dengan
  klasifikasi nonzero.
- Profil current process sebelum/sesudah descriptor snapshots wajib stabil pada
  token handle yang sama dan cleanup tetap exact.
- Bukti root accepted **47/47 unittest**, `py_compile`, diff-check, dan final
  adversarial review **PASS** tanpa P1/P2; seluruh tes masih pure fake.
- Ini bukan ordinary-principal evidence, rejection policy, atau effective
  `AccessCheck`. Second-token provenance, LocalSystem, token restriction/
  impersonation, source-tree/cache/composition/usable attestor, serta native
  Windows/runtime tetap terbuka.

Tidak ada status/checklist yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada aktivasi/deploy dan seluruh gate/payment default OFF.

### Bukti restricting SID profile accepted — runtime tetap terbuka

- `69a25c2` menetapkan `TokenRestrictedSids` sebagai authority untuk snapshot
  restricting SID current process.
- Encoding kosong hanya sah sebagai count nol dengan panjang exact **4 byte**;
  bentuk lain gagal tertutup. Parser nonempty berbatas **256 KiB/4096 SID**,
  attributes wajib nol, dan duplicate SID pada span berbeda dipertahankan.
- Bukti root accepted **51/51 unittest**, `py_compile`, diff-check, dan final
  adversarial review **PASS** tanpa P1/P2; seluruh tes masih pure fake.
- Ini bukan `IsTokenRestricted`, general-unrestricted evidence, ordinary
  evidence/policy, atau effective `AccessCheck`. Second-token provenance,
  LocalSystem, token restriction/impersonation lainnya, source-tree/cache/
  composition/usable attestor, dan native Windows/runtime tetap terbuka.

Tidak ada status/checklist yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada aktivasi/deploy dan seluruh gate/payment default OFF.

### Bukti restricting SID corroboration accepted — runtime tetap terbuka

- `a5e3c98` memakai `IsTokenRestricted` hanya sebagai corroboration atas
  authority `TokenRestrictedSids` class 11.
- `FALSE` hanya sah setelah `SetLastError(0)` dan immediate
  `GetLastError()==0`; nonzero berarti `true`, dan hasil wajib parity dengan
  snapshot class 11.
- Bukti root accepted **53/53 unittest**, `py_compile`, diff-check, dan final
  adversarial review **PASS** tanpa P1/P2; seluruh tes masih pure fake.
- Ini bukan general-unrestricted evidence, ordinary evidence/policy, atau
  effective `AccessCheck`. Second-token provenance, LocalSystem, token
  restriction/impersonation lainnya, source-tree/cache/composition/usable
  attestor, dan native Windows/runtime tetap terbuka.

Tidak ada status/checklist yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada aktivasi/deploy dan seluruh gate/payment default OFF.

### Bukti LocalSystem identity rejection accepted — runtime tetap terbuka

- `bbcd5b7` menolak current-process `TokenUser` LocalSystem exact `S-1-5-18`
  sebelum descriptor snapshots.
- Pemeriksaan identity-only: group SID dan restricting SID tidak dipakai sebagai
  identitas `TokenUser`.
- Bukti root accepted **55/55 unittest**, `py_compile`, diff-check, dan final
  adversarial review **PASS** tanpa P1/P2; seluruh tes masih pure fake.
- Ini hanya mengecualikan LocalSystem sebagai current-process identity; bukan
  ordinary second-principal/provenance evidence, policy, atau effective
  `AccessCheck`. Token restriction/impersonation lainnya, source-tree/cache/
  composition/usable attestor, dan native Windows/runtime tetap terbuka.

Tidak ada status/checklist yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada aktivasi/deploy dan seluruh gate/payment default OFF.

### Kontrak ordinary access provider accepted — implementasi tetap terbuka

- ADR-018 menerima contract/pure-fake testing bagi provider internal pemilik
  independent externally provisioned ordinary token, seluruh raw handle,
  derivation impersonation, live target/descriptor, native `AccessCheck`, dan
  one-shot `attest/load/discard`.
- Caller tidak menerima atau memasukkan credentials, username/account/environment
  selector, raw handle, SID/descriptor pointer, atau process handle.
- Request/evidence baru pada boundary anchor/execution untuk fresh/recovery
  mengikat exact outer ADR-017 request/evidence, policy/challenge/current
  identity, tiga target ordered+descriptor dengan `MAXIMUM_ALLOWED`, profile
  original+derived, dan function-success/access-false/granted-zero. Provider
  membuka/revalidasi target, memparse kedua live descriptor capture independen,
  dan menghasilkan descriptor `policySatisfied`; mismatch teramati ditolak.
- Path dan identity `(volumeSerial,fileId)` ketiga role wajib pairwise distinct;
  ADR-018 codec menolak alias segera, sementara remediation outer codec existing
  tetap terbuka.
- Descriptor `policySatisfied` hanya menutup policy descriptor; ordinary denial
  tetap admission gate terpisah.
- Policy menolak same user, LocalSystem, owner SID pada setiap group, fixed
  sensitive privilege enabled, AppContainer raw/nonzero, class-11 nonempty atau
  `IsTokenRestricted` true, serta profile drift. `TokenOrigin` observational;
  distinct `AuthenticationId` hanya corroboration.
- Acquisition authority dan provider/cache/`attest` belum ada; hanya pure codec
  preparation boleh dimulai. Native `AccessCheck`/runtime tetap terbuka.

Tidak ada status/checklist/progress yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada aktivasi/deploy dan seluruh gate/payment default OFF.

### Bukti pure ADR-018 request codec accepted — provisioning tetap terbuka

- Commit `f011551` menerima pure codec yang memvalidasi exact canonical outer
  ADR-017 request/evidence bytes plus private structural current-token identity
  fixture dan menurunkan request ADR-018 sendiri.
- Codec mengikat exact `aclRequestDigest`, `aclEvidenceDigest`, serta
  domain-separated `aclDescriptorEvidenceDigest`; path dan identity
  `(volumeSerial,fileId)` coordinator/run/source wajib pairwise distinct.
- Evidence root: **11/11 tests**, `py_compile`, dan independent review **PASS**
  tanpa P1/P2.
- Provider/cache/attest, native implementation, composition, provenance,
  runtime, dan provisioning authority tetap belum ada/terbuka.

Tidak ada status/checklist/progress yang diubah. P15/P16/P17c/P18 tetap terbuka;
tidak ada runtime/config/env/deploy/activation dan seluruh active gate/payment
tetap default OFF.

### Bukti outer target anti-alias accepted — runtime tetap terbuka

- Commit `a72727e` membuat outer ADR-017 codec menolak pairwise path aliases
  menurut accepted casefold rule dan duplicate evidence identities
  `(volumeSerial,fileId)` pada coordinator/run/source.
- Evidence root accepted: **24/24 tests**, `py_compile`, dan independent review
  **PASS** tanpa P1/P2.
- Full 299-test discovery saat concurrent edits bukan acceptance evidence: satu
  transient ordinary fixture error telah diperbaiki dan satu unrelated
  supervisor real-listener failure berasal dari environment. Full suite tidak
  diklaim green.
- Provider/native/runtime dan provisioning authority tetap belum ada/terbuka;
  tidak ada config/env/deploy/activation.

Tidak ada status/checklist/progress/gate yang diubah. P15/P16/P17c/P18, active
gates, dan payment tetap terbuka/default OFF.

### Bukti pure ACL source-tree summary v2 accepted — integration tetap terbuka

- Commit `5edb335` mewajibkan exact `sourceRootIdentity` dan mengikatnya ke
  canonical digest v2.
- Descendant root-identity reuse ditolak; deterministic order dipertahankan dan
  boundary drift wajib fail closed.
- Evidence root accepted: **11/11 tests**, `py_compile`, dan independent review
  **PASS** tanpa P1/P2.
- Builder suite timeout setelah lima dot bukan acceptance evidence; full suite
  tidak diklaim green.
- Consumer integration, native traversal, live identity/provenance, serta
  runtime tetap belum ada; tidak ada config/env/deploy/activation.

Tidak ada status/checklist/progress/gate yang berubah. P15/P16/P17c/P18, active
gates, dan payment tetap terbuka/default OFF.

### Bukti pure ACL role-mask policy accepted — composition tetap terbuka

- Commit `a62f8bd` menerima pure role-mask primitive atas fully revalidated
  process-token-bound snapshots.
- Exact mask coordinator/run `2032127` dan source `1179817` diturunkan serta
  parity-checked terhadap canonical policy artifact digest
  `a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb`.
- Hasil hanya immutable private match; tidak memuat `policySatisfied`, evidence,
  atau handle.
- Evidence root: **60/60 tests**, `py_compile`, dan independent review **PASS**
  tanpa P1/P2 setelah forged-snapshot P1 dan artifact-parity P2 diperbaiki.
- Role masih internal/test input. Future composition wajib mengikat canonical
  target order/path/identity serta double descriptor/token stability.
- Usable attestor, native/runtime implementation, dan provider tetap belum ada;
  tidak ada config/env/deploy/activation.

Tidak ada status/checklist/progress/gate yang berubah. P15/P16/P17c/P18, active
gates, dan payment tetap terbuka/default OFF.

### Bukti pure ordered ACL target-policy bundle accepted — binding tetap terbuka

- Commit `ab442d0` menerima exact tuple tiga snapshot pada internal pinned
  coordinator/run/source order.
- Setiap snapshot wajib lolos full role-policy validation; seluruh field
  `processToken*` exact-equal dan authority tuples dipin.
- Output hanya deeply immutable limited result.
- Evidence root: **65/65 tests**, `py_compile`, dan independent review **PASS**
  tanpa P1/P2 setelah root P2 rebind hardening.
- Path/filesystem identity binding, capture/double stability,
  `policySatisfied`/evidence, source-tree completeness, usable attestor,
  native/runtime implementation, serta provider tetap belum ada.

Tidak ada status/checklist/progress/gate yang berubah. P15/P16/P17c/P18, active
gates, dan payment tetap terbuka/default OFF tanpa config/env/deploy/activation.

### Bukti candidate manifest closure ACL accepted — runtime tetap terbuka

- Commit `3460588` menetapkan enam path berikut sebagai exact required
  sekaligus allowed:
  - `tools/testing/tests/Browser/checkout-acl-source-tree.py`
  - `tools/testing/tests/Browser/test_checkout_acl_source_tree.py`
  - `tools/testing/tests/Browser/checkout-windows-acl-attestor.py`
  - `tools/testing/tests/Browser/test_checkout_windows_acl_attestor.py`
  - `tools/testing/tests/Browser/checkout-ordinary-access-request.py`
  - `tools/testing/tests/Browser/test_checkout_ordinary_access_request.py`
- Evidence root: focused **1/1 test**, `py_compile`, dan independent review
  **PASS** tanpa P1/P2.
- Bukti hanya packaging integrity; tidak ada candidate build, klaim full builder
  suite, maupun bukti runtime/provider/native Windows.

Tidak ada status/checklist/progress/gate yang berubah. P15/P16/P17c/P18,
active gates, dan payment tetap terbuka/default OFF tanpa perubahan
config/env/deploy/activation.

### Bukti pure ACL source binding accepted — authority tetap terbuka

- Commit `4bfd005` memvalidasi exact canonical anchor/execution outer pairs
  melalui accepted codec dan `validate_boundary_pair`.
- Source-root identity hanya diturunkan dari evidence `target[2]`; source-tree
  v2 summaries dihitung ulang dari manifest/records dan wajib exact-equal.
- Sibling authority dipin dan narrow result immutable.
- Evidence root: **31/31 tests**, `py_compile`, dan independent review **PASS**
  tanpa P1/P2 setelah tuple/frozenset identity P2 diperbaiki.
- Bukti hanya supplied-data consistency; belum membuktikan authority
  `policySatisfied`, live traversal/completeness, handle/path provenance, DACL
  efficacy, double capture, provider, native/runtime, atau usable attestor.

Tidak ada status/checklist/progress/gate yang berubah. P15/P16/P17c/P18,
active gates, payment, config/env/deploy, dan activation tetap default OFF.

### Bukti candidate packaging ACL source binding accepted — runtime tetap terbuka

- Commit `1f8b22f` menjadikan
  `tools/testing/tests/Browser/checkout-acl-source-binding.py` dan
  `tools/testing/tests/Browser/test_checkout_acl_source_binding.py` exact
  required sekaligus allowed dalam candidate manifest closure.
- Evidence root: focused **1/1 test**, `py_compile`, dan independent review
  **PASS** tanpa P1/P2.
- Bukti hanya packaging integrity; tidak ada candidate build atau runtime
  evidence.

Tidak ada status/checklist/progress/gate yang berubah. P15/P16/P17c/P18,
active gates, payment, config/env/deploy, dan activation tetap default OFF.

### Bukti ACL source-binding dependency pinning accepted — trust boundary tetap terbuka

- Commit `538c144` mem-pin direct sibling dan stdlib dependency callables
  berdasarkan module, callable identity/type, serta Python metadata pre/post
  setiap panggilan dan sebelum hasil diterima.
- Evidence root: **32/32 tests**, `py_compile`, dan independent review **PASS**
  tanpa P1/P2.
- CPython builtins, deeper stdlib internals, dan dependency mutation yang
  sepenuhnya dipulihkan dalam callback tetap trusted/residual.
- Bukti hanya integrity hardening, bukan functional/native/provider/runtime
  authority.

Tidak ada status/checklist/progress/gate yang berubah. P15/P16/P17c/P18,
active gates, payment, config/env/deploy, dan activation tetap default OFF.

### Design provisioning ordinary Windows principal accepted — runtime tetap terbuka

- Commit `16c320f` menerima ADR-019: administrator/SCM menjadi provisioning
  authority bagi dedicated non-admin local Windows user dalam own-process broker
  service yang menjadi provider ADR-018.
- Token/credentials tidak diekspor; strict local IPC dan mutual SID/token plus
  SCM/process identity authentication wajib berlaku.
- Independent review **PASS** tanpa P1/P2; sumber ADR berasal dari dokumentasi
  resmi Microsoft.
- Acceptance hanya design/provisioning authority. Install/provider/native
  runtime tetap dilarang dan belum dibuktikan.

Tidak ada status/checklist/progress/gate yang berubah dan tidak ada account/
service/config/env/deploy/activation. P15/P16/P17c/P18, active gates, dan
payment tetap terbuka/default OFF.

### Pure authority manifest dan broker transport accepted — evidence tetap fail closed

- Commit `6469561`: pure canonical authority-manifest codec ADR-019,
  **14/14 tests**; cap pipe exact 20/36 KiB, owner pipe akun broker, LocalSystem
  ditolak, dan direct SAM membership terpisah dari bound runtime token-group
  policy.
- Commit `459e988`: pure canonical broker-transport codec, **11/11 tests**;
  hanya request, refusal, discard, dan load-sentinel.
- Bukti gabungan **25/25**, `py_compile`, `diff-check`, serta final adversarial
  review **PASS** tanpa P1/P2.
- Successful `attest`/`load` evidence tetap fail closed sampai canonical
  ADR-018 evidence validator/provider diterima.

Tidak ada bukti instalasi/read freshness manifest, efektivitas ACL, account/
service/firewall, native IPC/cache/provider/runtime, candidate/browser, deploy,
atau activation. Tidak ada status/checklist/progress/gate yang berubah;
P15/P16/P17c/P18, active gates, dan payment tetap terbuka/default OFF.

### Broker-start identity dan structural evidence accepted — native authority tetap terbuka

- Commit `b66ebba`: pure canonical supplied-data broker-start identity,
  **8/8 tests**; live process/token composition binding belum dibuktikan.
- Commit `bb369c6`: design boundary ADR-020 memisahkan structural validation
  dari native authority.
- Commit `9785eb5`: pure bytes-only structural evidence codec, **12/12 tests**;
  hasil explicit `structuralOnly` bukan policy/provenance/admission proof.
- Suite codec lokal gabungan **56/56**, `py_compile`, `diff-check`, dan review
  **PASS**.

Successful `attest`/`load` tetap disabled sampai native authority, provider,
cache, dan runtime diterima. Tidak ada instalasi, service, environment, network,
deployment, atau activation. Tidak ada progress/checklist/status yang berubah;
P15/P16/P17c/P18, active gates, dan payment tetap terbuka/default OFF.

### I20–I22 execution preparation accepted — runtime authority tetap terbuka

- `ac59705`: exact `cryptography 50.0.1` Windows wheel archive lock dan
  inspector, 10/10 focused; wheel tidak di-install/import dan closure eksternal
  `cffi>=2.0.0` serta host runtime belum tertutup.
- `57a1174`: sealed repository Ed25519 verifier, 16/16 focused; ambient package
  hanya adapter tes non-authoritative dan hasil tidak memberi trust/freshness/
  revocation/replay-consumption/admission.
- `9ece3e9`: native Windows journal storage primitive, 10/10 focused; hasil
  hanya `nativeStorageOnly`, bukan I18/ACL/DPAPI/TPM/reboot/rollback authority.
- `635d31d`: exact tujuh file baru required/allowed dalam manifest, focused
  1/1. Gabungan root **122/122**, `py_compile`, `diff-check`, dan independent
  review **PASS** setelah P1/P2 ditutup.

Tidak ada checklist/progress/gate yang berubah. P15/P16/P17c/P18, payment,
candidate/browser, config/env, service, deploy, dan activation tetap terbuka/
default OFF; progres formal tetap **173/220 = 78.6%**.
