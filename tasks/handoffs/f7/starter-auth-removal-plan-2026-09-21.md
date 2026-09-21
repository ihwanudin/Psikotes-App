# Rencana: hapus klaster login starter-kit tak terpakai (butir 13.2)

Status: **RENCANA — DISETUJUI Lead (`809110a`), belum ada kode/migrasi yang
dijalankan.** Ditulis oleh sesi FE atas permintaan Lead (dipindah dari antrean
F2 yang penuh), berdasarkan `origin/main` (`3003a47`) dan diverifikasi ulang
terhadap investigasi F2 di
`tasks/handoffs/f2/fortify-users-cluster-investigation.md` (cabang
`origin/f2/database-rls-coverage-ratchet`, PR #80) + KNOWN_GAPS RLS-GAP-09..12
di `tests/Postgres/DatabaseRlsCoverageSecurityTest.php` pada cabang yang sama.
Setiap klaim di bawah dicek langsung ke kode `origin/main`, bukan ditebak dari
laporan F2 — perbedaan/temuan baru ditandai eksplisit.

**Syarat tambahan dari Lead saat persetujuan (2026-09-21), sudah dimasukkan ke
rencana ini:** (1) makna keamanan tiga test checkout harus dipertahankan lewat
aktor pengganti nyata, bukan dihapus — §1.8; (2) cabang `default` di
`app.tsx` diputuskan sekarang, bukan ditunda ke saat implementasi — §1.5;
(3) `down()` migrasi (c) harus membuat ulang struktur tabel kosong, bukan
sekadar tag snapshot — §3; (4) dependency composer/npm dicabut hanya setelah
dibuktikan grep tidak ada pemakai lain (termasuk Filament/config), dengan
diff lockfile bersih — §3 PR (b). **Kode PR (a) baru dimulai setelah PR #89
merge.**

## 0. Ringkasan keputusan yang perlu dikonfirmasi pemilik dulu

1. **Hapus** guard `web` + `App\Models\User` + seluruh scaffold Fortify
   (login, reset password, verifikasi email, 2FA, passkeys) dan halaman
   `/dashboard`, `settings/*`.
2. **`sessions` table TIDAK ikut dihapus** — lihat §2.3, ini koreksi atas
   asumsi RLS-GAP-11 bahwa tabel itu "khusus guard `web`".
3. Urutan PR §3 butuh dua keputusan merge-order dari Lead sebelum dieksekusi:
   PR #89 (tombol "Portal pengelola") dan PR #80 (ratchet RLS) — lihat §3 dan §4.

## 1. Inventaris (dibaca dari `origin/main`, bukan ditebak)

### 1.1 Konfigurasi & provider
- `config/auth.php:22` — guard default `web` → provider `users` → `App\Models\User`.
  Guard `admin` terpisah, provider `admins` → `App\Models\Admin`. **Hapus** guard
  `web` dan provider `users` dari `config/auth.php`; **hapus** `AUTH_PASSWORD_BROKER`
  broker `users` bila tidak dipakai broker lain.
- `config/fortify.php` — seluruh file jadi tak terpakai (`resetPasswords()`,
  `emailVerification()`, `twoFactorAuthentication()`, `passkeys()` semuanya ON;
  `registration()` sudah OFF). **Hapus file.**
- `app/Providers/FortifyServiceProvider.php` — **hapus file**; hapus
  registrasinya dari `bootstrap/providers.php:9` (baris
  `App\Providers\FortifyServiceProvider::class`).
- `composer.json:14` — `laravel/fortify: ^1.37.2` → **hapus dependency**
  (jalankan `composer remove laravel/fortify`, bukan edit manual, agar
  `composer.lock` konsisten).
- `package.json:41` — `@laravel/passkeys: ^0.2.0` → **hapus dependency**
  (`npm uninstall @laravel/passkeys`).

### 1.2 Routes (per `routes/web.php`, `routes/settings.php`, dan Fortify's own
`vendor/laravel/fortify/routes/routes.php` yang otomatis berhenti terdaftar
begitu `FortifyServiceProvider` dicabut)
- `GET/POST /login`, `POST /logout` (guard `web`)
- `GET/POST /forgot-password`, `GET /reset-password/{token}`, `POST /reset-password`
- `GET /email/verify`, `GET /email/verify/{id}/{hash}`, `POST /email/verification-notification`
- `GET/POST /user/confirm-password`, `GET /user/confirmed-password-status`
- `GET/POST /two-factor-challenge`, `/user/two-factor-*` (enable/confirm/qr-code/secret-key/recovery-codes)
- `/passkeys/login*`, `/passkeys/confirm*`, `/user/passkeys*`
- `routes/settings.php` seluruh isi file: `settings` redirect, `settings/profile`
  (GET/PATCH/DELETE), `settings/security` (GET + `settings/password` PUT),
  `settings/appearance`, `.well-known/passkey-endpoints`.
- `routes/web.php:223-225` — `GET /dashboard` (`auth`+`verified`, guard `web`).
- **Tidak termasuk**: `GET /register` + `POST /registrations` (`routes/web.php:91-93`)
  — ini milik `ParticipantRegistrationController`, bukan Fortify. Sudah
  dikonfirmasi lewat `tests/Feature/Auth/RegistrationTest.php` (nama test
  `test_registration_screen_is_the_participant_flow_not_fortify_signup`,
  assert `Route::has('register.store')` false, `POST /register` 405 di guard
  Fortify). **Tetap hidup, tidak disentuh.**

### 1.3 Controller & action
- `app/Http/Controllers/Settings/ProfileController.php`
- `app/Http/Controllers/Settings/SecurityController.php`
- `app/Actions/Fortify/CreateNewUser.php` (dipanggil `FortifyServiceProvider`,
  reachable HANYA lewat `register.store` Fortify yang sudah mati karena
  `registration()` OFF — dead code hari ini, tapi bukan dead code file
  terpisah sampai `FortifyServiceProvider` ikut dihapus)
- `app/Actions/Fortify/ResetUserPassword.php`

### 1.4 Halaman Inertia/React (`resources/js/pages/`)
- `auth/login.tsx`, `auth/forgot-password.tsx`, `auth/reset-password.tsx`,
  `auth/confirm-password.tsx`, `auth/two-factor-challenge.tsx`,
  `auth/verify-email.tsx`
- `settings/profile.tsx`, `settings/security.tsx`, `settings/appearance.tsx`
- `dashboard.tsx` — **placeholder starter-kit murni** (3 `PlaceholderPattern`
  kosong, tidak pernah dibangun untuk produk), bukti kuat rute ini memang
  tidak pernah dipakai nyata.

### 1.5 Komponen & layout yang jadi tak terpakai
Dicek lewat `resources/js/app.tsx`'s Inertia layout resolver — `pages/`
hanya berisi 6 grup top-level (`auth`, `dashboard.tsx`, `participant`,
`registration`, `settings`, `welcome.tsx`); `welcome`/`registration/*`/
`participant/*` eksplisit `return null` (layout sendiri), `auth/*` →
`AuthLayout`, `settings/*` → `[AppLayout, SettingsLayout]`, dan
**`default` case (satu-satunya pemakai: `dashboard.tsx`) → `AppLayout`**.
Jadi `AppLayout` dan turunannya HANYA dipakai oleh `dashboard.tsx` — tidak
dipakai peserta/admin/publik sama sekali. Tak terpakai setelah dashboard
dihapus:
- `resources/js/layouts/app-layout.tsx`, `layouts/app/app-header-layout.tsx`,
  `layouts/app/app-sidebar-layout.tsx`
- `resources/js/layouts/auth-layout.tsx`, `layouts/auth/auth-card-layout.tsx`,
  `layouts/auth/auth-simple-layout.tsx`, `layouts/auth/auth-split-layout.tsx`
- `resources/js/layouts/settings/layout.tsx`
- `resources/js/components/app-header.tsx`, `components/app-sidebar.tsx`,
  `components/user-menu-content.tsx`
- `resources/js/components/manage-passkeys.tsx`, `components/manage-two-factor.tsx`,
  `components/passkey-item.tsx`, `components/passkey-register.tsx`,
  `components/passkey-verify.tsx`, `components/two-factor-recovery-codes.tsx`,
  `components/two-factor-setup-modal.tsx`
- `resources/js/hooks/use-two-factor-auth.ts`
- `resources/js/types/auth.ts` (perlu dicek isinya per-simbol — mungkin
  sebagian dipakai tipe lain, verifikasi saat eksekusi PR (a), bukan diasumsikan
  di sini)
- `resources/js/app.tsx` sendiri perlu diedit: hapus cabang `name.startsWith('auth/')`
  dan `name.startsWith('settings/')`, hapus import `AppLayout`/`AuthLayout`/
  `SettingsLayout`.
- **Keputusan `default` case (diminta Lead, diputuskan sekarang, bukan
  ditunda ke implementasi):** setelah `dashboard.tsx` dihapus, tiga grup
  halaman yang tersisa (`welcome`, `registration/*`, `participant/*`) SEMUA
  sudah eksplisit `return null` (tanpa layout — tiap halaman mengurus chrome
  sendiri). Tidak ada halaman tersisa yang butuh fallback `default` secara
  legitimate. Dua opsi yang diberikan Lead: (i) arahkan ke layout
  publik/peserta yang sudah ada, atau (ii) buat gagal eksplisit saat
  build/test kalau ada nama halaman tak dikenal.
  **Dipilih: opsi (ii), `default` melempar `Error` eksplisit** (mis.
  `throw new Error(`Unrecognized Inertia page "${name}" has no assigned
  layout`)`), bukan diam-diam jatuh ke `null`. Alasan: opsi (i) — memetakan
  `default` ke pola `null` yang sama seperti `welcome`/`participant`/
  `registration` — kelihatan setara tapi sebenarnya berbeda maknanya: untuk
  ketiga grup itu, `null` adalah keputusan sadar (masing-masing punya alasan
  sendiri kenapa tanpa layout). Kalau `default` MENGARAH ke `null` juga,
  maka nama halaman baru apa pun di masa depan yang lupa didaftarkan secara
  eksplisit akan diam-diam ikut pola itu tanpa ada yang sadar itu keputusan
  yang diambil — bisa jadi masalah kalau halaman baru itu sebenarnya butuh
  chrome/nav/auth-gate (mis. halaman admin-facing Inertia baru). Melempar
  error membuat kesalahan itu ketahuan saat build/test, bukan diam-diam lolos
  ke production — konsisten dengan prinsip "jangan diam-diam memilih" di
  CLAUDE.md. Diimplementasikan di PR (a).

### 1.6 Wayfinder-generated routes (`resources/js/routes/`, `resources/js/actions/`)
Kedua folder ini **di-gitignore** (`.gitignore` baris untuk
`/resources/js/actions/`, `/resources/js/routes/`, `/resources/js/wayfinder/`)
— dibangkitkan otomatis dari route PHP saat build. Tidak ada file untuk
dihapus manual, tapi import-nya harus diaudit karena begitu route PHP hilang,
fungsi generated-nya ikut hilang dan build TypeScript akan merah kalau masih
diimpor. Pemakai `import { ... } from '@/routes'` yang relevan (`git grep`
langsung, bukan tebakan):
- `dashboard`: `components/app-header.tsx`, `components/app-sidebar.tsx`,
  `pages/dashboard.tsx`, **`pages/welcome.tsx:18,308`** ⚠️
- `login`: `pages/auth/forgot-password.tsx`, **`pages/welcome.tsx:18,308`** ⚠️
- `logout`: `components/user-menu-content.tsx`, `pages/auth/verify-email.tsx`
- `home`: `layouts/auth/auth-card-layout.tsx`, `auth-simple-layout.tsx`, `auth-split-layout.tsx`
  (semua tiga ini sendiri jadi tak terpakai per §1.5, jadi `home` import ikut hilang bersamanya)
- `register`: `pages/welcome.tsx:18,355,612` — **ini `register` milik app
  sendiri (`routes/web.php:91`), BUKAN Fortify. Tetap ada, tidak dihapus.**

⚠️ = lihat §3 soal PR #89 — `welcome.tsx` memakai `login()`/`dashboard()` untuk
tombol "Portal pengelola" yang sedang diubah di PR #89. Plan ini **tidak
menyentuh `welcome.tsx`**; koordinasi merge-order wajib, lihat §3.

### 1.7 Migrasi
- `database/migrations/0001_01_01_000000_create_users_table.php` — membuat
  **tiga** tabel sekaligus: `users`, `password_reset_tokens`, `sessions`.
- `database/migrations/2024_01_01_000000_create_passkeys_table.php` — `passkeys`.
- `database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php`
  — kolom 2FA di `users`.

### 1.8 Test
- **Dihapus** (murni menguji fitur yang dihapus): `tests/Feature/Auth/AuthenticationTest.php`,
  `EmailVerificationTest.php`, `PasswordConfirmationTest.php`,
  `PasswordResetTest.php`, `TwoFactorChallengeTest.php`,
  `VerificationNotificationTest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`,
  `tests/Feature/Settings/SecurityTest.php`, `tests/Feature/DashboardTest.php`.
- **Tetap, tidak disentuh**: `tests/Feature/Auth/RegistrationTest.php` (menguji
  flow peserta, bukan Fortify — lihat §1.2), `ParticipantLoginTest.php`,
  `ParticipantApiAuthorizationTest.php`, dan seluruh test lain yang namanya
  ada di folder `Auth/` tapi isinya soal peserta/JWT, bukan guard `web`.
- **Perlu direfaktor, bukan dihapus** — tiga test yang memakai
  `User::factory()->create()` + `actingAs()` sebagai aktor "pengguna lain yang
  sedang login" untuk membuktikan isolasi checkout, TIDAK terkait Fortify
  sama sekali secara fungsional:
  - `tests/Feature/Integrations/CheckoutProductionWiringTest.php:185`
  - `tests/Feature/Integrations/CheckoutSessionHttpTest.php:149`
  - `tests/Feature/Integrations/CheckoutSummaryHttpTest.php:306`
  Begitu `App\Models\User`/guard `web` hilang, ketiga assertion ini butuh
  pengganti sebelum PR (b)/(c) bisa hijau. **Ini harus jadi langkah eksplisit
  di PR (b)**, bukan ditemukan belakangan saat CI merah.
  - **Syarat Lead:** makna keamanan tiap test (aktor lain yang sedang login
    TIDAK bisa melihat/mengubah checkout peserta) harus dipertahankan, bukan
    dihapus assertion-nya. Pengganti aktor: principal nyata yang masih ada
    setelah cluster ini hilang — admin (`Admin::factory()->create()`, guard
    `admin`, `actingAs($admin, 'admin')`) dan/atau peserta lain lewat token
    JWT (`AuthenticateParticipantJwt`), TIDAK pakai `User::factory()` sintetis
    lagi karena modelnya sendiri sudah dihapus di PR (b).
  - **Pemetaan lama → baru per test, ditulis di badan PR (b) (kewajiban
    Lead):**
    - `CheckoutProductionWiringTest.php:185` — `actingAs(User::factory()->
      create())` menguji `test_wrong_origin_query_role_and_session_fail_
      closed_with_private_headers`: aktor login lain tidak boleh membuka
      `/checkout` foreign attempt. Ganti ke admin guard
      (`actingAs(Admin::factory()->create(), 'admin')`) — cek dulu saat
      eksekusi PR (b) apakah middleware checkout membedakan reaksinya
      terhadap guard `admin` vs `web` (kalau checkout middleware secara
      eksplisit hanya mengecek "ada sesi Filament aktif", pakai admin;
      kalau assertion-nya justru soal "sesi peserta LAIN", pakai token JWT
      peserta lain — keputusan final saat baca implementasi middleware
      checkout, bukan ditebak di rencana ini).
    - `CheckoutSessionHttpTest.php:149` dan `CheckoutSummaryHttpTest.php:306`
      — pola serupa, sama-sama perlu dibaca konteks assertion persisnya saat
      eksekusi PR (b) untuk menentukan admin vs JWT peserta lain, lalu
      didokumentasikan mapping lama→baru + alasan pemilihannya di badan PR.
    - Assertion penolakannya (redirect ke `/checkout/unavailable`, 403/419,
      dsb.) harus tetap identik nilainya — hanya cara membuat "seseorang
      yang sudah login" berubah, bukan hasil yang diharapkan.

## 2. Yang HARUS tetap hidup, dan buktinya

1. **`/register` pendaftaran peserta** — `ParticipantRegistrationController`,
   `routes/web.php:91-104`, diuji `RegistrationTest.php`. Tidak disentuh.
2. **Login admin Filament** (`/admin/login`, guard `admin`) — dicek langsung
   di `app/Providers/Filament/AdminPanelProvider.php:34`
   (`->authGuard('admin')`) dan `app/Models/Admin.php` (extends
   `Illuminate\Foundation\Auth\User as Authenticatable`, model terpisah total
   dari `App\Models\User`). Tidak ada `Auth::guard('web')`/`auth('web')` di
   `app/` sama sekali — dicek dengan grep, nol hasil. Zero overlap kode.
3. **Login peserta (JWT)** — `AuthenticateParticipantJwt` middleware (alias
   `participant.jwt`, `bootstrap/app.php`) tidak mereferensikan
   `App\Models\User` sama sekali (dicek langsung, nol match). Independen total.
4. **Tabel `sessions` — HARUS tetap ada, koreksi atas RLS-GAP-11.** Klaim di
   `DatabaseRlsCoverageSecurityTest.php` bahwa `sessions` adalah "Laravel web
   session storage for the `web` guard specifically" **tidak akurat** dicek
   ke kode: `app/Providers/Filament/AdminPanelProvider.php:44-52` mendaftarkan
   `StartSession::class` + `AuthenticateSession::class` di middleware stack
   panel admin sendiri (independen dari `routes/web.php`'s `web` middleware
   group) — jadi panel Filament/admin **juga** memakai mekanisme session
   Laravel, bukan eksklusif guard `web`. `config/session.php:21` driver
   default `database` (env `SESSION_DRIVER`), tabelnya `sessions`
   (`config/session.php:89`, `env('SESSION_TABLE', 'sessions')`). **Karena
   session storage adalah infrastruktur Laravel yang dipakai bersama semua
   guard (bukan per-guard), tabel `sessions` TIDAK BOLEH ikut dihapus** —
   hanya bagian `users`+`password_reset_tokens` dari migrasi
   `0001_01_01_000000_create_users_table.php` yang boleh dicabut; `sessions`
   pindah ke migrasi barunya sendiri, atau `down()`-nya diedit supaya hanya
   drop dua tabel itu.
   - **Ketidakpastian yang tidak bisa dijawab dari repo** (poin ini juga
     dicatat F2): `.env.example:32` set `SESSION_DRIVER=redis`, artinya kalau
     production betulan pakai redis, tabel `sessions` di Postgres kosong/tak
     tertulis — tapi `.env` produksi sesungguhnya tidak ada di repo ini.
     **Perlu konfirmasi ops/pemilik** sebelum menganggap tabel ini aman
     untuk skip-RLS selamanya; untuk sekarang: **jangan hapus tabelnya**,
     independen dari pertanyaan RLS.
5. **Apa pun yang mereferensikan `App\Models\User`**:
   - `app/Http/Requests/*ProfileValidationRules*` — `Rule::unique(User::class)`,
     dipakai validasi. Perlu dicek satu per satu di PR (b): apakah aturan
     unique ini juga dipakai validator lain yang bukan settings/profile (grep
     saat eksekusi, belum diverifikasi di sini).
   - Tiga test checkout di §1.8 (`User::factory()`).
   - Foreign key ke `users.id`: hanya dua, keduanya sudah dicek isi
     migrasinya langsung. `sessions.user_id` (nullable, tanpa `constrained()`
     — bukan FK constraint sungguhan, cuma kolom berindeks) dan
     `passkeys.user_id` (`$table->foreignId('user_id')->constrained()
     ->cascadeOnDelete()` — FK sungguhan di
     `database/migrations/2024_01_01_000000_create_passkeys_table.php`).
     Karena `passkeys` sendiri ikut di-drop di PR (c) (§3), FK ini tidak jadi
     masalah urutan drop asal `passkeys` di-drop sebelum atau bersamaan
     `users` (FK constraint akan menolak drop `users` duluan kalau
     `passkeys` masih ada — urutan `DROP TABLE passkeys; DROP TABLE users;`
     di migrasi baru, bukan sebaliknya). Tidak ditemukan FK lain ke
     `users.id` di tabel manapun (dicek: tidak ada migrasi lain yang
     mereferensikan `users` sebagai FK target).

## 3. Urutan PR yang aman

Prasyarat lintas-PR: **tag snapshot `pre-remove-starter-auth` di `origin/main`
sebelum PR (c) dijalankan** (CLAUDE.md, migrasi destruktif), dan **tidak ada
migrasi yang benar-benar dieksekusi di production sebelum konfirmasi
pemilik eksplisit** — PR (c) boleh dibuka sebagai draft, tapi `php artisan
migrate` di production menunggu izin terpisah.

**Dependensi merge-order yang wajib dicek sebelum PR (a) dibuka:**
- **PR #89** (F2) sedang mengubah tombol "Portal pengelola" di
  `welcome.tsx` yang memakai `login()`/`dashboard()`. Rencana ini **tidak
  menyentuh `welcome.tsx`** sama sekali (sesuai instruksi Lead) — tapi kalau
  PR (a) di bawah ini menghapus route `/login`+`/dashboard` SEBELUM #89
  merge, dan #89 belum mengganti pemakaian `login()`/`dashboard()` di
  `welcome.tsx`, build TypeScript `welcome.tsx` akan merah (Wayfinder tidak
  lagi menghasilkan fungsi itu). **PR (a) harus menunggu #89 merge dulu**,
  atau Lead mengonfirmasi #89 sudah tidak lagi memakai kedua fungsi itu
  sebelum PR (a) dibuka.
- **PR #80** (ratchet RLS) belum merge ke `main` (`git merge-base
  --is-ancestor` dicek: NOT merged). `KNOWN_GAPS` di
  `DatabaseRlsCoverageSecurityTest.php` (hanya ada di cabang #80) berisi
  entri `users`/`password_reset_tokens`/`sessions`/`passkeys`
  (RLS-GAP-09..12). Test itu juga punya
  `test_every_listed_table_still_exists()` yang **GAGAL kalau tabel di
  KNOWN_GAPS sudah tidak ada** — jadi urutan mana pun (auth-removal duluan
  atau #80 duluan) butuh koordinasi commit yang sama menghapus baris
  KNOWN_GAPS terkait pada saat tabel benar-benar di-drop. Dua opsi aman:
  (i) #80 merge dulu, lalu PR (c) auth-removal ini juga menghapus 4 baris
  KNOWN_GAPS terkait di commit yang sama dengan migrasi drop; atau
  (ii) kalau auth-removal PR (c) merge duluan, PR #80 harus di-rebase dan
  4 baris KNOWN_GAPS itu dihapus dari PR #80 sebelum #80 dibuka untuk
  review. **Rekomendasi: #80 merge dulu**, supaya PR (c) di sini tinggal
  menghapus baris yang sudah ada, bukan mengoordinasikan dua PR yang
  sama-sama menyentuh file test yang sama secara paralel.

### PR (a) — cabut route dan UI (frontend + route registration saja)
- Hapus route Fortify-terdaftar dari registrasi (lewat cabut
  `FortifyServiceProvider` dari `bootstrap/providers.php` + hapus
  `routes/settings.php`'s require dari `routes/web.php` kalau ada, dan hapus
  `Route::middleware(['auth','verified'])->group(...)` block untuk
  `/dashboard`, `routes/web.php:223-225`).
- Hapus semua file di §1.4 (halaman) dan §1.5 (layout/komponen/hook/types).
- Edit `resources/js/app.tsx` (hapus cabang `auth/`, `settings/`, putuskan
  nasib `default` case — lihat §1.5).
- **Tidak** menyentuh `welcome.tsx` (milik #89) — pastikan sudah merge duluan
  (lihat prasyarat di atas).
- Hapus test UI-level murni yang menguji halaman ini (kalau ada test React
  komponen terpisah dari `tests/Feature/*`, cek saat eksekusi — belum
  ditemukan di inventaris `tests/` PHP di atas karena itu semua PHPUnit;
  cek folder JS test terpisah bila ada).
- Bukti: `php artisan route:list` tidak lagi menampilkan `/login`,
  `/dashboard`, `/settings/*`, dll; `npm run build`/`tsc --noEmit` hijau
  (memastikan tidak ada import Wayfinder yang menggantung).

### PR (b) — cabut kode backend
- Hapus `FortifyServiceProvider.php` filenya sendiri, `config/fortify.php`,
  `app/Actions/Fortify/*`, `app/Http/Controllers/Settings/*`.
- Refaktor 3 test checkout di §1.8 (mapping lama→baru wajib ditulis di badan
  PR, lihat §1.8 di atas).
- **Cabut dependency `laravel/fortify` (composer) dan `@laravel/passkeys`
  (npm) — HANYA setelah dibuktikan lewat grep bahwa tidak ada pemakai lain**,
  termasuk secara eksplisit dicek: Filament (`app/Providers/Filament/**`,
  `app/Filament/**`), `config/*.php` lain di luar `fortify.php` sendiri
  (mis. apakah ada `Filament\Facades\Filament::serving()` atau plugin yang
  diam-diam memakai salah satu paket ini), dan `composer.json`/`package.json`
  punya konsumen lain yang mendeklarasikan paket ini sebagai dependency-nya
  sendiri (bukan cuma dipakai kode aplikasi). Jalankan lewat installer resmi
  (`composer remove laravel/fortify`, `npm uninstall @laravel/passkeys`),
  jangan edit `composer.lock`/`package-lock.json` manual. **Tunjukkan diff
  lockfile yang bersih di badan PR** (hanya baris terkait paket yang dicabut
  dan dependency transitifnya yang murni miliknya — bukan `npm install`
  drift tak terkait, disiplin yang sama seperti insiden lockfile GLM yang
  pernah terjadi).
- Edit `config/auth.php` — cabut guard `web` + provider `users` (dan broker
  `password_reset_tokens` bila memang tak dipakai lagi setelah dicek ulang).
- Bukti: `composer test`/`node --test`/`phpunit` full suite hijau, termasuk
  ketiga test checkout yang direfaktor tetap lolos dengan makna assertion
  yang sama (aktor lain yang login tidak bisa akses sesi checkout milik
  orang lain), dan diff `composer.lock`/`package-lock.json` yang bersih
  ditempel di badan PR.

### PR (c) — migrasi penghapusan tabel
- Migrasi baru yang men-drop `users`, `password_reset_tokens`, `passkeys`
  (bukan `sessions` — lihat §2.4), urutan drop `passkeys` sebelum/bersamaan
  `users` supaya FK `passkeys.user_id` tidak menolak (lihat §2.5).
- **Syarat Lead: `down()` WAJIB membuat ulang struktur tabel (kosong)**,
  persis skema lama (`users`, `password_reset_tokens`, `passkeys` lengkap
  dengan kolom, index, FK `passkeys.user_id`) — bukan cuma mengandalkan tag
  snapshot sebagai satu-satunya jalur rollback. Badan PR menyatakan eksplisit
  bahwa `down()` mengembalikan STRUKTUR saja, bukan DATA — karena §1.2/§1.8
  sudah membuktikan tidak ada jalur non-test yang pernah mengisi baris
  `users` (registrasi Fortify mati), jadi tidak ada data nyata yang hilang
  untuk dipulihkan, hanya data uji. Tag `pre-remove-starter-auth` tetap wajib
  sebagai lapis kedua (mis. untuk skenario di luar skema: GRANT/RLS policy
  custom yang tidak tertangkap migrasi `down()`), bukan pengganti `down()`
  yang benar.
- **Tidak dijalankan (`php artisan migrate`) di production sebelum
  konfirmasi eksplisit pemilik** — PR ini boleh berisi migrasi file + dibuka
  draft, tapi eksekusinya adalah langkah terpisah yang butuh izin ulang.
  Tag `pre-remove-starter-auth` dibuat di `origin/main` SEBELUM migrasi
  dijalankan, bukan sebelum PR dibuka.
- Hapus 4 baris KNOWN_GAPS (RLS-GAP-09..12) di
  `tests/Postgres/DatabaseRlsCoverageSecurityTest.php` — lihat §4.
- Bukti: `test_every_listed_table_still_exists()` dan
  `test_every_public_table_is_rls_protected_or_an_explicit_exception()`
  tetap hijau setelah tabel di-drop + baris dihapus bersamaan.

## 4. Pemeriksaan RLS/GRANT — keluar dari ratchet #80

`tests/Postgres/DatabaseRlsCoverageSecurityTest.php` (cabang
`origin/f2/database-rls-coverage-ratchet`, belum di `main`) punya
`KNOWN_GAPS` berisi:
```
'users' => 'RLS-GAP-09: ... under investigation ... See
    tasks/handoffs/f2/fortify-users-cluster-investigation.md.',
'password_reset_tokens' => 'RLS-GAP-10: same Fortify/Jetstream cluster ...',
'sessions' => 'RLS-GAP-11: ... Laravel web session storage for the `web`
    guard specifically, not participant JWT sessions.',
'passkeys' => 'RLS-GAP-12: ... stores WebAuthn credential_id/credential per
    users.id.',
```
Dan `test_every_listed_table_still_exists()` men-assert setiap entri di
`KNOWN_GAPS` masih ada sebagai tabel — **gagal loud kalau tabel sudah
di-drop tapi barisnya belum dihapus.**

Tindakan yang benar di PR (c), pada commit yang sama dengan migrasi drop:
- Hapus baris `users`, `password_reset_tokens`, `passkeys` dari `KNOWN_GAPS`
  sepenuhnya (tabelnya sudah tidak ada, jadi tidak masuk `EXCEPTION_TABLES`
  juga — cukup dihapus, bukan dipindah).
- Baris `sessions` **tetap ada**, TAPI catatan "specifically for the `web`
  guard"-nya perlu dikoreksi (lihat §2.4) — sarankan pesan baru: "Laravel
  session storage, dipakai bersama oleh guard `web` (dihapus) dan panel
  Filament/admin (`StartSession`+`AuthenticateSession` di
  `AdminPanelProvider`); tetap ada, RLS-nya perlu keputusan produk terpisah
  soal driver session production." — ini bukan celah yang selesai oleh PR
  ini, cuma catatannya diperbaiki.
- Koordinasi merge-order dengan PR #80 seperti dijelaskan di §3.

## 5. Rencana bukti test (untuk PR final, dieksekusi saat kode ditulis)

1. **Login admin** — `/admin/login` sukses dengan kredensial admin fixture,
   redirect ke panel; guard `admin` tidak tersentuh perubahan ini sama
   sekali (test regresi, bukan test baru).
2. **Peserta daftar → login → mulai sesi** — jalur penuh
   `RegistrationTest` → `ParticipantLoginTest`/JWT issuance → start-session
   endpoint, membuktikan tidak ada dependency tersembunyi ke guard
   `web`/`User` di jalur ini.
3. **Route lama 404** — `GET /login`, `GET /dashboard`, `GET
   /settings/profile`, `GET /forgot-password`, dll semuanya 404 (bukan 500 —
   500 berarti ada referensi menggantung yang belum dibersihkan).
4. Full `phpunit`/`node --test` suite hijau tiap PR, termasuk 3 test checkout
   yang direfaktor (§1.8) dan dua test ratchet RLS (§4) di commit migrasi.

## 6. Yang tidak dijawab di sini (butuh keputusan pemilik)

- Apakah `sessions` benar-benar boleh tetap tanpa RLS selamanya, atau perlu
  proteksi RLS-nya sendiri sebagai PR terpisah setelah cluster `web`/`User`
  hilang (tabel itu sendiri tetap dipakai Filament) — di luar cakupan
  "hapus starter-kit auth", jadi diusulkan sebagai temuan terpisah, bukan
  dikerjakan diam-diam di PR (c).
- Nasib cabang `default` di `resources/js/app.tsx`'s layout resolver setelah
  `dashboard.tsx` hilang (§1.5) — keputusan implementasi kecil, tidak butuh
  keputusan pemilik, tapi dicatat di sini supaya tidak diam-diam diputuskan
  tanpa disebut di PR (a).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
