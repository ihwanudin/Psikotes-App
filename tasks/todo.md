# F1 Task Checklist

Checklist F1 ini tetap utuh. Checklist kanonik F2-F9 dan status T-01..T-28 per
lapisan domain, persistence, PostgreSQL/RLS, HTTP, UI, dan browser E2E berada di
[`tasks/f2-f9-acceptance.md`](f2-f9-acceptance.md). Jangan menjumlahkan rasio
checklist yang berbeda sebagai persentase proyek; gunakan exit gate fase.

## Task 1: Establish repository baseline

**Description:** Inisialisasi Git, buat branch `f1/foundation`, lindungi secrets/build artifacts, dan dokumentasikan toolchain yang dipin.

**Acceptance criteria:**

- [x] Repository Git dan branch F1 aktif tanpa memasukkan data peserta atau secrets.
- [x] `.gitignore` mencakup `.env*` rahasia, key, vendor, node_modules, dan build output.
- [x] PHP, Composer Laragon, Node/package manager, PostgreSQL client, dan Docker dicatat statusnya.

**Verification:**

- [x] `git status` hanya menampilkan file yang memang diharapkan.
- [x] Secret/PII scan baseline tidak menemukan material sensitif yang akan di-commit.

**Dependencies:** None

**Files likely touched:** `.gitignore`, `README.md`

**Estimated scope:** Small

## Task 2: Bootstrap Laravel shell

**Description:** Inisialisasi Laravel dengan Inertia React dan test harness tanpa mengubah artefak F0.

**Acceptance criteria:**

- [x] Laravel boot dengan PHP strict conventions dan environment example tanpa secret.
- [x] Inertia React terpasang dan halaman smoke test dirender.
- [x] Lockfile PHP/frontend tunggal serta dependency-script policy tercatat.

**Verification:**

- [x] Focused PHP smoke test lulus (39/39; 136 assertions).
- [x] Frontend build, lint, dan typecheck lulus; Composer/npm audit tidak menemukan advisory.

**Dependencies:** Task 1

**Files likely touched:** `composer.json`, `package.json`, `bootstrap/app.php`, `resources/js/`, `tests/`

**Estimated scope:** Medium

## Task 3: Define development containers

**Description:** Buat topologi dev untuk app, queue, scheduler, Redis, dan PostgreSQL dengan jaringan database privat.

**Acceptance criteria:**

- [x] Compose memakai healthcheck dan volume bernama; Postgres/Redis tidak dipublikasikan ke host.
- [x] App, queue, dan scheduler memakai image/config aplikasi yang sama.
- [x] `.env.example` hanya berisi placeholder.

**Verification:**

- [x] `docker compose config` valid.
- [x] Health endpoint membuktikan DB dan Redis dapat dijangkau setelah stack hidup.

**Dependencies:** Task 2

**Files likely touched:** `compose.yaml`, `docker/`, `.env.example`

**Estimated scope:** Medium

## Task 4: Seed F0 configuration

**Description:** Buat Laravel seeder yang memuat enam JSON F0 sebagai data instrumen versioned dan read-only.

**Acceptance criteria:**

- [x] Seeder idempotent dan menyimpan versi sumber/engine.
- [x] Seluruh angka dibaca dari JSON; tidak ada norma/ambang yang ditanam di PHP.
- [x] Count dan checksum hasil seed cocok dengan artefak F0.

**Verification:**

- [x] Seeder integration test lulus dua kali berturut-turut.
- [x] Gate Python F0 tetap 11/11 lulus.

**Dependencies:** Task 2

**Files likely touched:** `database/migrations/`, `database/seeders/InstrumentSeeder.php`, `tests/Feature/Seeders/`

**Estimated scope:** Medium

## Task 5: Create core tenant schema

**Description:** Tambahkan branches, admins, participants, referral visits, payment methods, orders, entitlements, consent records, audit/outbox, serta schema DASS terpisah.

**Acceptance criteria:**

- [ ] Foreign keys, enum/check constraints, indeks, dan unique/partial unique constraints sesuai SPEC.
- [x] `payment_methods` menyimpan kode stabil dan `is_active`; semua metode default nonaktif.
- [x] Consent A/B versioned dan dicatat terpisah; riwayat consent B yang ditarik/ditolak tetap fail-closed tanpa mengubah hasil psikotes utama.
- [x] Role migration dan runtime database terpisah.

**Verification:**

- [ ] Migration up/down lulus pada PostgreSQL.
- [x] Schema assertion tests memeriksa constraint dan indeks kritis.

**Dependencies:** Task 3

**Files likely touched:** `database/migrations/`, `database/schema/`, `tests/Feature/Database/`

**Estimated scope:** Medium

## Task 6: Enforce PostgreSQL RLS

**Description:** Aktifkan dan paksa RLS untuk data tenant, peserta, keuangan, audit, serta DASS dengan least privilege.

**Acceptance criteria:**

- [ ] Runtime role bukan table owner dan tidak memiliki BYPASSRLS.
- [ ] Policy super admin, branch admin, peserta, service, dan psikolog sesuai matriks akses.
- [ ] Request tanpa konteks gagal tertutup.

**Verification:**

- [ ] Tes SQL negatif lintas cabang dan lintas peserta lulus.
- [ ] Tes membuktikan DASS tidak terbaca admin cabang/pusat non-psikolog.

**Dependencies:** Task 5

**Files likely touched:** `database/migrations/`, `tests/Feature/Rls/`

**Estimated scope:** Medium

## Task 7: Inject RLS context

**Description:** Buat middleware dan job middleware yang memasang role/branch/participant context secara transaction-local.

**Acceptance criteria:**

- [ ] Semua route bertenant dan job bertenant memakai context middleware.
- [x] Konteks dibersihkan otomatis setelah transaksi, termasuk saat exception.
- [x] Route baru yang menyatakan akses tenant tanpa middleware context gagal melalui architecture test.

**Verification:**

- [ ] Dua request/job berurutan dari tenant berbeda tidak saling melihat data.
- [x] Route coverage test mendeteksi controller bertenant tanpa middleware.

**Dependencies:** Task 6

**Files likely touched:** `app/Http/Middleware/`, `app/Jobs/Middleware/`, `bootstrap/app.php`, `tests/Feature/Rls/`

**Estimated scope:** Medium

## Task 8: Configure Filament roles

**Description:** Pasang Filament auth untuk super admin, branch admin/staf, dan psikolog dengan session cookie aman.

**Acceptance criteria:**

- [x] Role dan kemampuan diverifikasi server-side, bukan hanya menyembunyikan menu.
- [x] Cookie httpOnly/Secure/SameSite dan session expiry dikonfigurasi.
- [x] Admin cabang otomatis mendapat konteks RLS cabangnya.

**Verification:**

- [x] Auth/authorization feature tests lulus untuk setiap role.
- [x] IDOR lintas cabang ditolak walau ID resource diketahui.

**Dependencies:** Task 7

**Files likely touched:** `app/Models/Admin.php`, `app/Filament/`, `config/session.php`, `tests/Feature/Admin/`

**Estimated scope:** Medium

## Task 9: Implement first-touch referral

**Description:** Implementasikan `/r/{ref_code}`, cookie 30 hari, fallback cabang default, dan audit referral visit.

**Acceptance criteria:**

- [x] Referral pertama tetap menang setelah link cabang lain dibuka.
- [x] Kode tidak dikenal jatuh ke cabang default tanpa membuka data cabang.
- [x] IP/UA diperlakukan sebagai PII dengan retensi dan logging minimum.

**Verification:**

- [ ] Feature tests mencakup first-touch, unknown code, expired cookie, dan concurrent registration. Tiga skenario pertama hijau; concurrent registration menunggu Task 10.

**Dependencies:** Task 7

**Files likely touched:** `routes/web.php`, `app/Http/Controllers/ReferralController.php`, `app/Services/Referral/`, `tests/Feature/Referral/`

**Estimated scope:** Medium

## Task 10: Build registration and consent

**Description:** Buat form Inertia dan Form Request untuk nama, jenis kelamin, tanggal lahir, pendidikan, cabang/referral, bidang tujuan, kontak, paket, consent A, serta consent B.

**Acceptance criteria:**

- [x] Consent A serta Consent B dicatat terpisah; versi teks dan timestamp disimpan.
- [x] Bidang kerja dan atribusi cabang ditentukan server-side.
- [x] Validasi panjang/format dan rate limit registrasi aktif.
- [x] `package_id` terhubung ke katalog per jenis tes dengan harga IDR dan sakelar aktivasi default OFF; backend memvalidasi ulang paket saat transaksi.
- [x] Panel `super_admin` dapat mengisi harga dan mengatur paket ON/OFF; role lain serta URL langsung ditolak, dan paket tidak dapat diaktifkan tanpa harga terkonfigurasi (Rp0 sah untuk layanan gratis).
- [x] Harga IDR disimpan di katalog database: IST/PAPI/RMIB/Kraepelin masing-masing Rp99.000 dan menyertakan DASS-21 otomatis, paket semua tes Rp200.000, paket DASS-21 mandiri gratis, dan konsultasi psikolog opsional Rp50.000.

**Verification:**

- [x] Feature tests mencakup input valid, invalid, duplikat, consent B yang tidak diterima, dan mass-assignment abuse.
- [x] Browser smoke test mobile registration lulus (390×844; kedua consent diterima; POST 302 → konfirmasi 200; console bersih).
- [x] Feature tests panel paket mencakup batas role, akses URL langsung, update harga/aktivasi, harga gratis, dan validasi harga kosong.
- [x] Browser smoke katalog lulus: enam paket dan add-on berasal dari database, DASS menampilkan Gratis, konsultasi mengubah total menjadi Rp50.000, dan console bersih.

**Dependencies:** Tasks 8, 9

**Files likely touched:** `resources/js/Pages/Registration/`, `app/Http/Requests/`, `app/Actions/Registration/`, `tests/Feature/Registration/`

**Estimated scope:** Medium

## Task 11: Store identity evidence privately

**Description:** Tambahkan upload foto identitas dan selfie awal, metadata verifikasi, serta alur tinjauan manual melalui interface matcher.

**Acceptance criteria:**

- [x] MIME, magic bytes, ukuran, dan dimensi gambar divalidasi; object key tidak memakai nama peserta.
- [x] Bucket private dan akses memakai signed URL yang diaudit.
- [x] Hasil matcher hanya penanda; kegagalan tidak otomatis memutuskan kelayakan.

**Verification:**

- [x] Upload tests menolak spoofed MIME, oversized, malformed, dan unauthorized access.
- [x] Fake matcher contract test lulus tanpa data biometrik nyata.

**Dependencies:** Task 10

**Files likely touched:** `app/Contracts/IdentityMatcher.php`, `app/Services/Identity/`, `app/Http/Requests/`, `tests/Feature/Identity/`

**Estimated scope:** Medium

## Task 12: Issue participant credentials

**Description:** Buat sequence nomor tes bulanan, login nomor tes + tanggal lahir, JWT 12 jam, dan entitlement gate dasar.

**Acceptance criteria:**

- [x] Nomor tes unik, aman dari race, dan reset scheduler teruji.
- [x] Login dibatasi 5/menit/IP dengan lockout progresif per nomor tes.
- [x] Endpoint peserta hanya mengakses participant claim sendiri; entitlement locked tidak dapat memulai sesi.

**Verification:**

- [x] Unit/feature tests mencakup collision, expiry JWT, brute force, claim tampering, dan 403 locked.

**Dependencies:** Tasks 7, 10

**Files likely touched:** `app/Services/TestNumber/`, `app/Services/ParticipantAuth/`, `routes/api.php`, `tests/Feature/Auth/`

**Estimated scope:** Medium

## Task 13: Define payment provider contract

**Description:** Buat interface payment-neutral, state machine order, dan fake provider untuk test deterministik.

**Acceptance criteria:**

- [x] Interface mendukung create invoice, status check, webhook normalization, dan expiry.
- [x] Transition invalid ditolak; entitlement tetap locked sampai event sah.
- [x] Tidak ada secret atau detail Xendit di domain service.

**Verification:**

- [x] Contract tests lulus terhadap fake provider.
- [x] State-machine property tests membuktikan event berulang aman.

**Dependencies:** Tasks 5, 12

**Files likely touched:** `app/Contracts/PaymentProvider.php`, `app/Services/Payments/`, `tests/Unit/Payments/`

**Estimated scope:** Medium

## Task 14: Control payment method activation

**Description:** Tambahkan toggle admin untuk mengaktifkan atau menonaktifkan Xendit dan transfer manual tanpa menghapus konfigurasi/order historis.

**Acceptance criteria:**

- [x] Hanya super admin dapat mengubah `is_active`; perubahan masuk audit log.
- [x] Endpoint/form registrasi hanya menampilkan metode aktif dan menolak kode nonaktif dengan error stabil.
- [x] Menonaktifkan kanal tidak membatalkan atau menyembunyikan order yang sudah dibuat.

**Verification:**

- [x] Feature tests mencakup default-off, on/off, unauthorized toggle, forced disabled code, dan order historis.
- [x] Filament toggle smoke test lulus.

**Dependencies:** Tasks 8, 13

**Files likely touched:** `app/Models/PaymentMethod.php`, `app/Filament/Resources/PaymentMethodResource.php`, `app/Services/Payments/`, `tests/Feature/Payments/`

**Estimated scope:** Medium

## Task 15: Integrate Xendit Invoice

**Description:** Implementasikan invoice, cek-status fallback, callback-token verification, dan webhook idempotent.

**Acceptance criteria:**

- [x] PAID/SETTLED terverifikasi mengubah order dan entitlement tepat sekali dalam satu transaksi.
- [x] Token salah dan status tidak dikenal ditolak tanpa bocor detail; EXPIRED mempertahankan locked.
- [x] Event ID/gateway reference unik mencegah replay; hanya transisi paid pertama menghasilkan sinyal unlock yang dapat dipakai ledger komisi tanpa duplikasi.

**Verification:**

- [x] HTTP tests mencakup forged, duplicate, reordered, timeout, dan retry callback.
- [ ] Sandbox contract test tersedia dan fail-closed ke key development, tetapi diskip karena credential belum tersedia pada environment ini.

**Dependencies:** Task 14

**Files likely touched:** `app/Services/Payments/XenditProvider.php`, `app/Http/Controllers/XenditWebhookController.php`, `config/services.php`, `tests/Feature/Payments/`

**Estimated scope:** Medium

## Task 16: Verify manual transfers

**Description:** Tambahkan bukti transfer private dan aksi Filament approve/reject dengan audit trail.

**Acceptance criteria:**

- [x] Bukti dibatasi jpg/png/pdf maksimal 5 MB dan disimpan private.
- [x] Hanya role berwenang dalam scope cabang yang dapat memverifikasi.
- [x] Approve berulang tidak menggandakan entitlement/sinyal finansial; reject menyimpan alasan. Ledger komisi belum ada pada schema F1, sehingga transisi pertama yang teraudit menjadi input idempoten untuk implementasi ledger berikutnya.

**Verification:**

- [x] Authorization, upload, idempotency, penggantian bukti saat ditinjau, dan cross-branch tests lulus.
- [x] Filament action smoke test lulus.

**Dependencies:** Tasks 8, 14

**Files likely touched:** `app/Filament/Resources/OrderResource.php`, `app/Actions/Payments/`, `tests/Feature/Admin/`

**Estimated scope:** Medium

## Task 17: Deliver notifications and order status

**Description:** Buat outbox queue untuk notifikasi kredensial/aktivasi dan halaman status pembayaran peserta.

**Acceptance criteria:**

- [x] Notifikasi diantrekan setelah commit dan aman diulang.
- [x] Kegagalan WAHA/n8n tidak membatalkan status paid; retry dan audit tersedia.
- [x] Halaman status tidak membocorkan order peserta lain.

**Verification:**

- [x] Queue retry/idempotency tests dan status-page authorization tests lulus.
- [x] Adapter fake lulus; integrasi WAHA/n8n dijalankan pada stack lokal dan deduplikasi terverifikasi.

**Dependencies:** Tasks 15, 16

**Files likely touched:** `app/Jobs/`, `app/Contracts/Notifier.php`, `resources/js/Pages/Orders/`, `tests/Feature/Notifications/`

**Estimated scope:** Medium

## Task 18: Prove the F1 gate

**Description:** Jalankan alur lengkap manual dan Xendit serta security regression suite pada PostgreSQL nyata.

**Acceptance criteria:**

- [x] Manual: aktifkan metode -> daftar -> upload bukti -> admin verifikasi -> notifikasi -> login berhasil.
- [ ] Xendit: aktifkan metode -> invoice -> webhook terverifikasi -> entitlement ready -> notifikasi -> login berhasil.
- [x] Metode yang dimatikan hilang dari pilihan dan order baru ditolak tanpa mengganggu order historis.
- [x] RLS, riwayat consent B nonaktif, webhook replay, upload abuse, dan auth brute force tetap hijau.

**Verification:**

- [ ] Seluruh PHP/frontend/integration/browser tests lulus tanpa skip gerbang.
- [ ] Dependency audit dan secret/PII scan tidak memiliki temuan kritis yang belum dimitigasi.

**Dependencies:** Tasks 1-17

**Files likely touched:** `tests/Feature/F1/`, `tests/Browser/`, `F1_VALIDATION.md`

**Estimated scope:** Medium

## Task 19: Document F1 operations

**Description:** Perbarui setup, threat model, env contract, scheduler/queue, serta runbook verifikasi pembayaran.

**Acceptance criteria:**

- [ ] Developer baru dapat menjalankan dev stack dan test tanpa memperoleh secret nyata.
- [ ] Middleware RLS wajib, alur incident webhook, serta retry notifikasi terdokumentasi.
- [ ] Semua keterbatasan/provider yang belum dipilih dicatat terbuka.

**Verification:**

- [ ] Perintah dokumentasi diuji dari checkout bersih atau dicatat bila Docker belum tersedia.
- [ ] F1 validation report memetakan bukti ke setiap gerbang.

**Dependencies:** Task 18

**Files likely touched:** `README.md`, `SECURITY.md`, `DEPLOYMENT.md`, `F1_VALIDATION.md`

**Estimated scope:** Medium
