# E2E-1 — Inventaris Browser E2E dan Proposal Harness

Tanggal: 2026-09-19. Baseline: `origin/main` `650eea5` (Merge PR #13).
Sifat: **investigasi + proposal saja**. Tidak ada test suite, kode aplikasi,
`package.json`/`composer.json`/lockfile, `AGENTS.md`,
`tasks/parallel-work.md`, atau `tasks/f2-f9-acceptance.md` yang diubah.
Satu-satunya probe yang dijalankan adalah probe HTTP read-only terhadap
SQLite `:memory:` dengan data sintetis (lihat §6 dan
`tasks/evidence/e2e/e2e-1-probe/`).

> Catatan pelaksana: spec dispatch ditulis untuk Codex (branch
> `codex/e2e-1-inventory`). Increment ini dikerjakan oleh helper Lead di
> branch `lead/e2e-1-inventory` (worktree terpisah), tanpa push.

---

## 0. Ringkasan temuan terpenting (baca ini dulu)

1. **Halaman produksi F5 `ReportSigning` TIDAK bisa dibuka lewat HTTP/browser
   sungguhan di `main`.** Route Filament-nya `GET admin/report-signing` tanpa
   parameter `{case}` (`route:list`), sedangkan
   `ReportSigning::mount(string $case, ...)` (`app/Filament/Pages/ReportSigning.php:142`)
   mewajibkan `$case`. `ReportSigning::getUrl(['case' => ...])` menghasilkan
   `?case=...` (query string), yang tidak diteruskan Livewire ke `mount()`.
   Probe: psychologist → **HTTP 500**
   (`TypeError: ReportSigning::mount(): Argument #1 ($case) must be of type string, ReportSigningService given`),
   baik dengan `?case=` maupun lewat link navigasi (tanpa case).
   super_admin/branch_admin/staff juga **500**, bukan 404 tersembunyi,
   karena TypeError terjadi sebelum `abort_unless(self::canAccess(), 404)`.
   Hanya guest yang benar (302 → `/admin/login`).
   Semua test F5 yang ada memakai `Livewire::test(ReportSigning::class, ['case' => ...])`
   (`tests/Feature/Filament/ReportSigningPageTest.php`), yang menyuntik parameter
   langsung dan karena itu tidak mendeteksi ini. Konsekuensi: **skenario
   pertama yang disarankan Lead (alur F3→F5 di `ReportSigning`) TERBLOKIR di
   `main`** sampai page itu diperbaiki (milik lane DeepSeek:
   `app/Filament/Pages/ReportSigning*.php`).
2. Tidak ada titik masuk UI dari mana psikolog memilih kasus untuk
   ditandatangani: tak ada link ke halaman signing dari resource mana pun
   (`grep report-signing|ReportSigning::getUrl` di `app/`, `resources/views` = nihil).
   Link navigasi (`shouldRegisterNavigation()` = true untuk psikolog) mengarah ke
   URL tanpa case → 500.
3. Akses di `main` = **psychologist saja** (`app/Models/Admin.php:65`,
   `AdminAbility::ReviewReports => role === Psychologist`; ditegaskan oleh
   `test_non_psychologist_roles_cannot_access_page`, super_admin → 404).
   Kandidat skenario Lead menyebut "psychologist + super_admin diizinkan" —
   itu kontrak dari branch `deepseek/f5-resign-and-rls` yang **belum merge**.
   Lihat §7 (ambiguitas).
4. Halaman F5 **tidak mengonsumsi teks narasi F4** (termasuk
   `narrative_cluster_edits`): `narrativeClusters` diinisialisasi string kosong
   (`ReportSigning.php:113-118`) dan hanya `narrative_version_id` yang ditautkan;
   `ReportSigningService`/`ReportSigning*.php` tidak membaca
   `bilingual_narrative_versions.cluster_*` maupun `narrative_cluster_edits`.
   Ini bertentangan dengan klaim di `tasks/f2-f9-acceptance.md` baris F4
   ("teks INTEGRATION ... sekarang benar-benar dikonsumsi F5 production UI"). Lihat §7.
5. Masalah lingkungan baru (di luar daftar yang sudah diketahui): pada
   worktree baru **tanpa `.env`**, `composer install` gagal di
   `package:discover` dengan
   `RuntimeException: Konfigurasi production belum lengkap: APP_KEY, ...`
   (`app/Providers/AppServiceProvider.php:63`), karena `APP_ENV` default ke
   `production`. Ini berbeda dari masalah `storage/framework` yang diperbaiki
   PR #11. Harness wajib membuat `.env` sintetis (`APP_ENV=testing`) **sebelum**
   `composer install`.

---

## 1. Inventaris permukaan UI produksi di `main` (`650eea5`)

### 1a. Inertia (React, `resources/js/pages/**`, layout `resources/views/app.blade.php` → butuh Vite manifest)

| Route | Page | Aktor | Catatan E2E |
|---|---|---|---|
| `GET /` | `welcome` | publik | sudah ada `tests/Frontend/welcome-a11y.test.mjs` (non-browser) |
| `GET /register`, `POST /registrations` | `registration/create` (+ `package-selector`, `payment-method-selector`, `identity-file-field`) | peserta publik | consent DASS di `create.tsx:560-590`; smoke manual pernah lulus (`tasks/todo.md:213-215`) |
| `GET /registration/received` | `registration/received` | peserta | teks instruksi kamera saja (`received.tsx:149`), bukan kode kamera |
| `GET /registration/order-status` | `registration/order-status` | peserta | `no_store` |
| `POST /registration/manual-payment-proof` | `manual-payment-proof-form` | peserta | upload sintetis |
| `GET /participant/lobby` | `participant/lobby` | peserta (token di `sessionStorage`) | memanggil `/api/me`, `/api/me/entitlements` (`lobby.tsx:60-61`); **tidak** memanggil start sesi |
| `GET /dashboard`, `settings/*`, `auth/*` | starter kit | user web | bukan permukaan bisnis psikotes |

Blade non-Inertia yang relevan: `resources/views/assessment-invitation.blade.php`
(`/assessment/invitations/{publicId}` → consume → set token → redirect ke lobby),
`resources/views/selection-launch.blade.php` (`/selection/launch`), dan
checkout terintegrasi `/checkout*` (tanpa middleware `web`, sudah punya harness
preview di `tests/Frontend/IntegratedCheckout/`).

### 1b. Filament admin (`/admin`, `app/Providers/Filament/AdminPanelProvider.php`; tidak memakai Vite — cukup asset Filament ter-publish)

| Route | Kelas | Akses (kode) | Status browser |
|---|---|---|---|
| `admin/login` | Filament Login | guest | OK |
| `admin` | Dashboard + `F7OperationalOverview` widget | semua admin (`Widgets/F7OperationalOverview.php:29`) | bisa |
| `admin/report-signing` | `Pages/ReportSigning` | psychologist saja | **500 untuk semua role terautentikasi** (temuan §0.1) |
| `admin/psychologist-review-fixture` | `Pages/PsychologistReviewFixture` | psychologist + `APP_ENV=testing` saja (`PsychologistReviewFixture.php:118-128`) | bisa (pernah dijalankan 2026-09-14), tapi fixture, bukan bukti produksi |
| `admin/assessment-participants` (+ `export.csv`, `CreateCollectiveBill`) | `AssessmentParticipantResource` | policy `ParticipantPolicy` | bisa |
| `admin/assessment-bill-reviews[/{record}]` | `AssessmentBillReviewResource` | `AssessmentBillPolicy` | bisa |
| `admin/organization-bills[/{record}]` | `OrganizationBillResource` | — | bisa |
| `admin/orders` | `OrderResource` | `OrderPolicy` | bisa |
| `admin/payment-methods[/{record}/edit]` | `PaymentMethodResource` | super_admin | bisa |
| `admin/test-packages[/{record}/edit]` | `TestPackageResource` | super_admin | bisa |
| `admin/integration-clients|sources` (+create/edit) | Integration* | super_admin | bisa |
| `admin/commission-entries` | `CommissionEntryResource` | admin terautentikasi (`:39`) + RLS | bisa |
| `admin/withdrawal-requests` | `WithdrawalRequestResource` | admin terautentikasi (`:44`) + RLS | bisa |

Endpoint JSON admin (bukan UI, tapi dipakai E2E sebagai oracle/seed-check):
`admin/assessment-cases/{case}/{eligibility-decisions,bilingual-narratives,narrative-cluster-edits,review-input,signing}`
(`routes/web.php`).

### 1c. Yang tidak ada di `main`

- UI sesi tes / soal (IST/PAPI/RMIB/Kraepelin/DASS): tidak ada page; `POST /api/sessions/{testType}/start` selalu 501 `SESSION_ENGINE_PENDING` (`app/Http/Controllers/StartParticipantSessionController.php:50-56`).
- Kode kamera/proctoring di frontend: tidak ada `getUserMedia`/`visibilitychange` di `resources/js`. Domain murni saja (`app/Domain/Proctoring/**`); widget F7 menyatakan persistence belum ada (`F7OperationalOverview.php:66`).
- UI HPP/PDF (F6) dan tombol publish: tidak ada. `SIGNED → PUBLISHED` hanya ada di state machine (`app/Domain/Review/ReportReviewStateMachine.php:30`).
- Re-sign/REVISED: tidak ada (halaman read-only setelah SIGNED, `ReportSigning.php:279-287`).
- Tidak ada Playwright/Dusk di `package.json`/`composer.json`; tidak ada `tests/Browser/`.

---

## 2. Status per baris acceptance (kolom Browser E2E `open`/`partial`)

Legenda: **BISA** = bisa di-E2E sekarang di `main`; **SEBAGIAN** = sebagian
assertion bisa; **TERBLOKIR** = penyebab konkret disebut.

| Baris | Status E2E di `main` | Dasar (file) / penyebab |
|---|---|---|
| **F1 exit gate** — manual path | **BISA** | register → upload bukti → admin verifikasi (`OrderResource`, `ManualPaymentProofAccessController`) → login peserta → lobby. Notifikasi harus fake/log driver (jangan WAHA/n8n live). |
| F1 — Xendit path | **TERBLOKIR** | invoice memanggil Xendit sungguhan (`config/services.php:39-40`, `XenditProvider`); tidak ada fake gateway yang bisa dipakai server yang di-`serve`. Butuh kredensial sandbox (gate manusia) atau adapter fake env-testing (keputusan Lead). Webhook saja bisa disimulasikan dengan callback token sintetis. |
| F1 — "seluruh browser tests tanpa skip" | **SEBAGIAN** | bergantung harness E2E-2; tiga skrip lama (`tests/Frontend/*/browser*.mjs`) tidak dijalankan CI (`.github/workflows/tests.yml` hanya `composer ci:check`). |
| T-12 G1, T-13 G2, T-14 G3, T-17 G8, T-18, T-19 | **TERBLOKIR** | konsumsi UI satu-satunya adalah `ReportSigning` → 500 (§0.1). Setelah diperbaiki: bisa diverifikasi tampilan level sistem/final, validitas, label, blocker V3. |
| T-15 G5/tanda tangan | **TERBLOKIR** (sama) | alur tanda tangan hanya lewat `ReportSigning::submit()`. Setelah perbaikan: bisa (snapshot di `report_signing_snapshots`, read-only setelah SIGNED). Re-sign/REVISED terblokir `deepseek/f5-resign-and-rls` belum merge. |
| T-16 G7 | **TERBLOKIR** | page (500) + sumber G7 masih `CANONICAL` tunggal dari klien (`ReportSigning.php:209-213`), `TODO(G7-data-gap)` belum ditutup (lane DeepSeek berikutnya). |
| T-20, T-21 narasi | **TERBLOKIR** | page (500) **dan** page tidak menampilkan teks narasi F4 (§0.4). HTTP projection ada (`BilingualNarrativeController`), bukan UI. |
| T-22 DIPERTIMBANGKAN bersyarat | **TERBLOKIR** (page 500) | setelah perbaikan: blocker `ACCOMPANIMENT_CONDITIONS_REQUIRED` + fokus (`ReportSigning.php:319-335`) bisa diuji di browser. |
| T-23 matriks akses | **SEBAGIAN / BISA** untuk admin panel | guest → login, visibilitas resource per role (super_admin/branch_admin/staff/psychologist), concealed 404 untuk resource terlarang, RLS cabang di commission/withdrawal. **Untuk `ReportSigning`: bisa dijalankan tapi akan MERAH** (500 alih-alih 404/200). Permukaan peserta (lobby via invitation) bisa. |
| T-24 DASS mandiri & otomatis dalam paket utama | **BISA** | katalog/registration (`package-selector.tsx`, consent DASS `create.tsx:560`), checkout summary (`tests/Frontend/IntegratedCheckout/summary-mandatory-dass-render.test.php` sebagai oracle), lobby menampilkan `dass21` di entitlements (`lobby.tsx:30`). Jangan menambahkan narasi yang menekankan aturan ini (`tasks/parallel-work.md:116`). |
| T-25 kamera ditolak | **TERBLOKIR** | tidak ada kode kamera di frontend; UI sesi tidak ada (`SESSION_ENGINE_PENDING`). |
| T-26 stream kamera terputus | **TERBLOKIR** | sama + persistence proctoring F7 belum dimulai. |
| T-27 visibility duration | **TERBLOKIR** | tidak ada listener visibility; `proctor_logs` belum ada. |
| T-28 face-match | **TERBLOKIR** | provider belum dipilih (gate manusia); keputusan otomatis tetap dilarang. |
| T-01..T-11 (di luar daftar dispatch, dicatat saja) | **TERBLOKIR** | `SESSION_ENGINE_PENDING` (F2/ADR-0030), tak ada UI soal. |
| **F3 exit gate** (alur F3→F5 di browser) | **TERBLOKIR** | §0.1 + §0.2. |
| **F4 exit gate** | **TERBLOKIR** | §0.1 + §0.4; F6 PDF masih di `glm/f6-hpp-report-draft` (belum merge). |
| **F5 exit gate** (browser) | **TERBLOKIR** | §0.1; re-sign/REVISED + RLS widening di `deepseek/f5-resign-and-rls` belum merge. |
| **F7 exit gate** — dashboard/ledger/withdrawal | **BISA** | `admin` dashboard widget, `CommissionEntryResource`, `WithdrawalRequestResource` (RLS cabang; varian PostgreSQL untuk bukti RLS). |
| F7 — proctoring timeline | **TERBLOKIR** | persistence `proctor_photos`/`proctor_logs` + UI belum dimulai (ledger `AGENTS.md`). |

---

## 3. Proposal harness — pilihan: **(b) `@playwright/test` sebagai devDependency yang di-pin**

Keputusan ada di Lead (pemilik `package.json`/lockfile). Helper ini **tidak**
mengubah file tersebut.

### Alasan memilih (b)

- **Reproducibility.** Pola (a) memakai `npx --yes --package @playwright/cli`
  tanpa versi: setiap run bisa mengunduh versi berbeda (drift + risiko supply
  chain), dan tidak ada lockfile yang merekam browser build. Dengan (b), versi
  runner + Chromium terkunci di lockfile.
- **Struktur test.** (b) memberi `projects` per viewport (320/390/768/1280),
  fixture login per role, `expect` dengan auto-wait, retry, trace/screenshot on
  failure, reporter JUnit/HTML untuk CI. Skrip (a) yang ada menulis ulang
  `assert`, menangani error console sendiri, dan menanam path absolut mesin
  lokal (mis. `evidenceDir` di `tasks/evidence/browser-qa-2026-09-14-f5-review/run-browser.mjs:9`).
- **CI.** (a) belum pernah dijalankan di CI; (b) punya jalur standar
  (`npx playwright install --with-deps chromium` + `webServer`).
- Biaya: satu devDependency (`@playwright/test`, pin eksak mis. `1.xx.y`) +
  entri lockfile. Tidak ada perubahan runtime aplikasi.

Transisi: skrip lama `tests/Frontend/{ParticipantLobby,PsychologistReview}/browser.test.mjs`
dan `IntegratedCheckout/browser-interactions.mjs` dibiarkan; setelah (b)
disetujui, dipindahkan bertahap (bukan di E2E-2). Bila Lead menunda (b),
E2E-2 bisa berjalan dengan (a) **dengan versi dipin di perintah**
(`npx --package @playwright/cli@<versi> ...`) sebagai jembatan.

Catatan package manager (ambiguitas kecil): repo punya `package-lock.json`
**dan** `pnpm-workspace.yaml`; CI memakai `npm install`
(`composer.json` script `setup`), `CLAUDE.md` menyebut `pnpm test:scoring`.
Lead perlu memilih lockfile mana yang menjadi otoritas sebelum menambah dependency.

### Layout folder yang diusulkan

```text
tests/E2E/
  playwright.config.ts        # projects: chromium-320/390/768/1280; webServer: php artisan serve
  global-setup.ts             # buat DB disposable + migrate + seed skenario (lihat bawah)
  support/
    env.ts                    # origin, port, path DB per-run
    login.ts                  # fixture login Filament per role (akun sintetis)
    guards.ts                 # blokir request eksternal, kumpulkan console/pageerror,
                              # predicate border (lihat §4), cek no-horizontal-scroll
  specs/
    t23-admin-access.spec.ts
    f5-report-signing.spec.ts
    t24-dass-package.spec.ts
    f1-manual-payment.spec.ts
    f7-ledger.spec.ts
database/seeders/E2E/         # (usulan, milik owner seed — Lead) seeder skenario,
  E2eReportSigningSeeder.php  # guard: abort bila !app()->environment('testing')
```

Output run (trace, screenshot, junit) → `storage/e2e/` (gitignored); bukti
yang diterima Lead disalin ke `tasks/evidence/e2e/<increment>/` dengan
`checksums.sha256` seperti pola 2026-09-14.

### Seed data sintetis

- **Sumber bentuk data:** ulangi pola `ReportSigningPageTest::createCase()` +
  `seedBaseline()`/`seedV3Baseline()` (`tests/Feature/Filament/ReportSigningPageTest.php:37-255`):
  Branch/TestPackage/Participant/AssessmentCase via model, lalu
  `eligibility_decision_versions` dibangun dari `EligibilityDecisionSnapshot::create()`
  dengan `database/seeders/data/reporting.json` (dibaca, **tidak diubah**), dan
  `bilingual_narrative_versions` sintetis. Admin per role dengan email
  `@example.test` dan password sintetis.
- **Mekanisme:** seeder khusus E2E (usulan di atas) dipanggil
  `php artisan db:seed --class=...` terhadap DB disposable. Alternatif tanpa
  menyentuh `database/**`: `php artisan tinker --execute` dari
  `global-setup` — kurang terbaca, tidak disarankan.
- **SQLite disposable (default, Windows & CI):** file per run
  `storage/e2e/e2e-<runid>.sqlite`, `DB_DATABASE` absolut, `migrate:fresh`
  hanya ke file itu. Cukup untuk UI/alur, **tidak** membuktikan RLS.
- **PostgreSQL disposable (varian RLS):** container sekali pakai seperti pola
  `phpunit.organization-postgres.xml` / `tests/Postgres/**`
  (PostgreSQL 17.x, role `psikotes_runtime` + koneksi `pgsql_migration`),
  dijalankan terpisah (`--project=pgsql`) untuk T-23 lintas cabang dan
  F7 ledger. Tidak pernah diarahkan ke DB nyata.
- **Isolasi eksternal:** `MAIL_MAILER=array|log`, `QUEUE_CONNECTION=sync`,
  notifier fake, `XENDIT_*` kosong, route browser memblokir semua origin
  selain `127.0.0.1:<port>` (pola `run-browser.mjs`; `ui-avatars.com`
  di-fulfill lokal).

### Menjalankan lokal (Windows, PowerShell)

```text
# 0. worktree baru dari origin/main (pasca PR #11)
Copy-Item .env.example .env
#    set APP_ENV=testing, APP_KEY=base64:AAAA...(sintetis), DB_CONNECTION=sqlite,
#    SESSION_DRIVER=database|file, CACHE_STORE=array, QUEUE_CONNECTION=sync, MAIL_MAILER=array
php D:/laragon/bin/composer/composer.phar install --no-interaction   # post-autoload menjalankan filament:upgrade (publish asset Filament)
npm ci
npm run build                        # menghasilkan public/build/manifest.json (butuh vendor untuk wayfinder:generate)
npx playwright install chromium      # opsi (b)
npx playwright test -c tests/E2E     # global-setup: DB disposable + migrate + seed; webServer: php artisan serve --port=8014
```

### CI (usulan job baru di `.github/workflows/tests.yml`, milik Lead)

Job `e2e` terpisah dari `ci`, `ubuntu-latest`, PHP 8.3 + Node 22: siapkan
`.env` sintetis (sudah ada di job `ci`), `composer install`, `npm ci`,
`npm run build`, `npx playwright install --with-deps chromium`,
`npx playwright test -c tests/E2E --reporter=junit,html`, upload
`storage/e2e/**` sebagai artifact. Varian PostgreSQL memakai `services: postgres:17`.
Awalnya `workflow_dispatch` + PR label, baru dijadikan wajib setelah stabil.

---

## 4. Masalah lingkungan yang harus ditangani harness

| Masalah | Penanganan |
|---|---|
| `.env` tidak ada → `package:discover` gagal "Konfigurasi production belum lengkap" (`AppServiceProvider.php:63`) | **Baru ditemukan di E2E-1.** Buat `.env` sintetis `APP_ENV=testing` sebelum `composer install`. Bukan masalah PR #11. |
| `composer install` gagal `Please provide a valid cache path` | rebase ke `main` pasca PR #11 (`storage/framework/*` placeholder); jangan didiagnosis ulang. |
| `public/build/manifest.json` hilang | `npm run build` (setelah `composer install`, karena plugin wayfinder memanggil `php artisan wayfinder:generate`, `vite.config.ts:29-33`). Hanya page Inertia (`resources/views/app.blade.php` memakai `@vite`) yang butuh; Filament tidak. |
| Asset Filament (`public/{js,css,fonts}/filament`, gitignored) | otomatis via `post-autoload-dump` → `php artisan filament:upgrade` (`composer.json:80-84`); bila `package:discover` gagal, jalankan ulang `php artisan filament:upgrade`. Global-setup harus memverifikasi `public/css/filament` ada. |
| Border 1px terbaca `0.666667px` di Chromium Windows (DPR 1.5) | jangan assert `borderWidth >= 1`; pakai predicate "border terlihat" (`width > 0`, style ≠ none/hidden, warna tidak transparan). Predicate ini **sudah ada** di `tests/Frontend/PsychologistReview/browser.test.mjs:16-54` — reuse ke `support/guards.ts`. |
| Lebar viewport vs clientWidth (scrollbar 15px) | assert `document.documentElement.scrollWidth <= clientWidth`, bukan `== innerWidth` (pola README 2026-09-14). |
| Konsol 404 dari navigasi concealed yang disengaja | klasifikasikan sebagai expected per-langkah, bukan global allowlist. |
| Rate limiter (`report-signing-access` 30/menit, `AppServiceProvider.php:106`; login throttle) | `CACHE_STORE=array` per proses server + satu worker; restart server per spec besar. |
| SQLite test isolation (`tasks/handoffs/sqlite-test-isolation-known-issue-2026-09-16.md`) | satu file DB per run, jangan share dengan phpunit `:memory:`. |

---

## 5. Skenario PERTAMA yang diusulkan (koreksi terhadap kandidat Lead)

**Koreksi:** kandidat Lead (alur F3→F5 lengkap di `ReportSigning`) tidak bisa
hijau di `main` karena §0.1. Saya usulkan dipecah:

### E2E-2 (bisa sekarang): "Admin access matrix + reachability F5" — terikat T-23, F5 exit gate (reachability)

Setup: SQLite disposable, seed 1 kasus sintetis dengan baseline V1 (pola
`seedBaseline`), akun sintetis psychologist, super_admin, branch_admin (cabang A),
staff (cabang A), dan tanpa login.

Acceptance check (masing-masing dapat diverifikasi otomatis):

1. Guest `GET /admin/report-signing?case=<ulid>` → redirect ke `/admin/login` (probe: sudah 302 ✔).
2. Psychologist → halaman merender judul "Tanda Tangan Laporan", label peserta
   sintetis, dan kolom level sistem + level final untuk 18 aspek.
   **Diharapkan MERAH di `main`** (500) — dijalankan sebagai *known-failing*
   yang terhubung ke tiket perbaikan, bukan di-skip diam-diam.
3. branch_admin/staff → 404 tanpa ID/konten kasus di DOM. **Diharapkan MERAH** (500).
4. super_admin → sesuai kontrak yang diputuskan (§7.1): 404 di kontrak `main`, 200 bila widening DeepSeek merge.
5. Resource admin lain: super_admin melihat `payment-methods`/`test-packages`/`integration-*`;
   branch_admin/staff tidak mendapat menu tersebut dan akses langsung → 403/404;
   `commission-entries`/`withdrawal-requests` hanya menampilkan baris cabang sendiri (varian PostgreSQL untuk bukti RLS).
6. Semua halaman pada 320/390/768/1280: tanpa scroll horizontal; konsol tanpa error selain 404 concealed yang disengaja; tidak ada request ke origin eksternal.

Nilai: langsung menghasilkan bukti browser pertama untuk T-23 dan membuktikan
bug §0.1 di browser sungguhan (bukan hanya di probe PHP).

### E2E-3 (setelah perbaikan route `ReportSigning` merge): kandidat Lead, lengkap

1. Akses sesuai §7.1.
2. Psikolog mengisi 4 klaster narasi, submit → notifikasi "Laporan berhasil ditandatangani", baris baru di `report_signing_snapshots` (cek via query DB disposable), halaman menjadi read-only dengan tombol "Tanda tangan sudah dibuat".
3. Override satu aspek dengan alasan ≥ minimum → snapshot menyimpan `system_level` dan `final_level` berdampingan; tampilan menampilkan keduanya.
4. DOM halaman tidak memuat teks "DASS"/subskala; ubah seed DASS (jika tersedia) tidak mengubah zona/label (T-07). Catatan: di `main` DASS sama sekali tidak dimuat oleh page, jadi T-07 di sini = assertion negatif DOM + snapshot.
5. G5: tidak ada kontrol publish/terbit di page mana pun; baseline V3 → tombol "Tanda tangan belum tersedia" disabled dan blocker `VALIDITY_V3`; submit ulang pada SIGNED ditolak.
6. Viewport 320/390/768/1280 tanpa scroll horizontal; keyboard: klik blocker memindahkan fokus ke field terkait (`focusBlocker`).

---

## 6. Probe yang dijalankan (read-only, sintetis)

- `php artisan route:list --path=admin` → `admin/report-signing` tanpa `{case}`
  (`tasks/evidence/e2e/e2e-1-probe/route-list-report-signing.txt`).
- PHPUnit probe **di luar repo** (salinan: `tasks/evidence/e2e/e2e-1-probe/E2e1ReportSigningReachabilityProbeTest.php.txt`),
  `APP_ENV=testing`, SQLite `:memory:`, data sintetis, via `vendor/bin/phpunit --no-coverage <file>`.
  Output: `tasks/evidence/e2e/e2e-1-probe/probe-output.txt`
  (psychologist 500 ×2, super_admin 500, branch_admin 500, staff 500, guest 302).
- Tidak ada migrasi ke DB nyata, tidak ada panggilan Xendit/notifikasi, tidak ada browser dijalankan.
- `.env` sintetis dibuat di worktree (gitignored, tidak di-commit) agar `package:discover` jalan.

---

## 7. Ambiguitas antar-kontrak (dilaporkan, TIDAK diselesaikan)

1. **Scope akses signing.** Kode + test di `main`: psychologist saja
   (`Admin.php:65`, `ReportSigningPageTest.php:270-294`). Kandidat skenario Lead
   dan ledger `AGENTS.md`: psychologist + super_admin (dikonfirmasi owner,
   di `deepseek/f5-resign-and-rls`, belum merge). E2E harus menguji salah satu;
   usul: E2E mengikuti `main` dan assertion super_admin diubah pada commit yang
   sama dengan merge widening. Perlu juga diputuskan apakah `ViewDass` ikut
   melebar (saat ini sepasang dengan `ReviewReports`, `Admin.php:64-65`;
   `SECURITY.md:40` menyatakan schema `dass` tidak tersedia bagi admin non-psikolog).
2. **Status F5 "ACCEPTED" vs reachability.** `tasks/f2-f9-acceptance.md` baris F5
   menyatakan UI produksi diterima; probe menunjukkan halaman tidak dapat dibuka
   lewat HTTP oleh role mana pun yang terautentikasi, dan non-psikolog mendapat
   500 (bukan 404 concealed seperti diklaim test). Bukti penerimaan hanya
   `Livewire::test`. Usul: tandai lapisan UI F5 sebagai `partial` sampai ada
   test HTTP/browser; perbaikan (route `report-signing/{case}` atau
   `#[Url]`/query binding + titik masuk dari daftar kasus) milik lane DeepSeek.
3. **Konsumsi narasi F4 oleh F5.** Acceptance F4 menyatakan teks INTEGRATION
   (termasuk edit psikolog) dikonsumsi UI F5; kode `main` tidak membaca
   `bilingual_narrative_versions.cluster_*` maupun `narrative_cluster_edits`
   di `ReportSigning*.php`/`ReportSigningService.php` — form klaster mulai kosong.
   Perlu keputusan: apakah form harus di-prefill dari teks F4 (dengan edit
   psikolog menang, sesuai aturan CLAUDE.md "teks INTEGRATION tersunting tidak
   boleh tertimpa"), dan update baris F4/T-20/T-21.
4. **G5 "DRAFT→PUBLISHED".** State machine (`ReportReviewStateMachine.php`)
   punya `SIGNED → PUBLISHED`, tapi tidak ada pemilik UI publish di `main`
   (F6 belum merge). E2E di `main` hanya bisa membuktikan "tidak ada kontrol
   publish"; bukti positif G5 menunggu F6 + definisi siapa yang boleh publish.
5. **Package manager/lockfile otoritatif** (npm `package-lock.json` vs
   `pnpm-workspace.yaml`) — lihat §3.

---

## 8. Estimasi dan urutan increment

| Inc | Isi | Baris acceptance | Prasyarat | Ukuran |
|---|---|---|---|---|
| E2E-2 | Scaffold harness (b) atau (a)-pinned + seeder E2E + spec access matrix & reachability F5 | T-23 (admin), F5 (reachability), F1 "browser tests" | Lead menyetujui harness; Lead/owner seed mengizinkan `database/seeders/E2E/**` | M |
| E2E-3 | Alur F3→F5 signing penuh | T-12..T-15, T-17..T-19, T-22, T-07 (negatif), F3/F5 exit gate | perbaikan route `ReportSigning` (DeepSeek) merge; keputusan §7.1 | M |
| E2E-4 | Registrasi/katalog/checkout + invitation → lobby | T-24, T-23 (peserta), F1 manual path | — (bisa paralel dengan E2E-3) | M |
| E2E-5 | F7 dashboard, commission, withdrawal per role; varian PostgreSQL RLS | F7 exit gate (non-proctoring), T-23 lintas cabang | container PostgreSQL disposable | S–M |
| E2E-6 | Narasi F4 di UI review + re-sign/REVISED | T-20, T-21, F4 exit gate, F5 re-sign | §7.3 diputuskan; `deepseek/f5-resign-and-rls` merge | S–M |
| E2E-7 | HPP/PDF F6: signed URL 15 menit, tak ada skor subskala DASS, akses psikolog+peserta | T-07, T-10, F6 | `glm/f6-hpp-report-draft` merge + terhubung data F5 | M |
| E2E-8 | Xendit path | F1 Xendit | kredensial sandbox (gate manusia) atau fake gateway env-testing (keputusan Lead) | S |
| E2E-9+ | Sesi tes, lalu proctoring mobile (kamera ditolak/putus/visibility) | T-01..T-11, T-25..T-27, F2, F7 proctoring | session engine (ADR-0030) + persistence proctoring F7; mobile emulation + device nyata | L |
| — | Face-match | T-28 | provider dipilih (gate manusia) | — |

Semua assertion proctoring akan dirumuskan sebagai **deteksi/penandaan**, bukan
pencegahan (CLAUDE.md).

---

## Worker handoff record

```text
Task/thread ID: lead-helper-e2e-1
Lane and phase: Browser E2E / E2E-1 (investigation + proposal)
Branch/worktree: lead/e2e-1-inventory / C:/Users/ThinkPad/AppData/Local/Temp/claude/D--LSI-Web-Psikotes/23068672-d6ec-4ff0-893b-60bb7e16b1e4/scratchpad/wt-e2e1 (not pushed; Lead reviews and pushes)
Baseline commit: 650eea5e393ea9258b6d72b9e53489a519cf0b36 (origin/main)
Owned files/directories: tasks/handoffs/e2e/** (new), tasks/evidence/e2e/** (new)
Acceptance criteria: inventory of main UI surfaces; per-row can-E2E-now/blocked with cited cause for F1 exit gate, T-12..T-28, F3/F4/F5/F7 exit gates; harness choice (a)/(b) with seeding, local Windows + CI run, folder layout; known env issues; first scenario with verifiable checks; E2E-2..n sizing bound to acceptance rows; cross-contract ambiguities reported
Verification commands: php artisan route:list --path=admin ; php -d memory_limit=1G vendor/bin/phpunit --no-coverage <out-of-repo probe> (APP_ENV=testing, SQLite :memory:, synthetic data) ; node tools/security/repository-content-scan.mjs --kind=pii (PASS) ; --kind=secret (PASS)
Result commit: (commit containing this file on lead/e2e-1-inventory)
Tests and evidence: tasks/evidence/e2e/e2e-1-probe/{probe-output.txt,route-list-report-signing.txt,E2e1ReportSigningReachabilityProbeTest.php.txt}; no browser run, no test suite added
Known blockers: ReportSigning page unreachable over HTTP (500 for all authenticated roles, §0.1); no case entry point (§0.2); SESSION_ENGINE_PENDING; F6 unmerged; F5 re-sign/RLS widening unmerged; F7 proctoring persistence not started; T-28 provider not chosen; Xendit sandbox credentials
Next dependency or increment: Lead approves harness (b) and E2E-2 scope; DeepSeek lane fixes ReportSigning route/entry point before E2E-3; Lead decides §7 ambiguities
Review status: pending
```
