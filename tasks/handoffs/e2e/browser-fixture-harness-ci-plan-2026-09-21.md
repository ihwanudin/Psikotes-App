# Plan — running the frontend fixture browser harnesses in CI

**Tanggal:** 2026-09-21. **Ditulis oleh:** kanal FE-Infra, atas instruksi Lead.
**Sifat:** rencana saja. Tidak ada kode/workflow yang diubah di sini.

## 0. Kenapa ini penting sekarang

Dua cacat nyata (`resources/js/pages/participant/lobby.tsx` meluap di
320px, PR #58; `browser-interactions.mjs`'s reset-loop mengklik tombol
yang di-assert disabled, PR #60) lolos ke `main` karena harness browser
fixture — `tests/Frontend/ParticipantLobby` dan
`tests/Frontend/IntegratedCheckout` — **tidak pernah dijalankan CI**.
Keduanya baru terungkap saat harness ini diperbaiki (PR #53) dan seseorang
menjalankannya manual. Keduanya sekarang **hijau di `main`** — diverifikasi
ulang sendiri untuk rencana ini (lihat §6), bukan diasumsikan dari laporan
lama.

## 1. Ini BUKAN E2E-2 (`tests/E2E/**`) — jangan tabrakan

`tests/E2E/**` (ditambahkan PR #33, README-nya sudah memuat draf job CI
yang belum pernah dipasang) memakai `@playwright/test` resmi
(`playwright.config.ts`, `npm run e2e:ci`) melawan **halaman admin Laravel
sungguhan** (`php artisan serve` + SQLite seed sintetis via
`tests/E2E/setup/start-server.mjs`). Targetnya sama sekali berbeda: T-23
matriks akses admin, T-24 bundling DASS, dan F5 `ReportSigning`.

Harness fixture yang saya maksud di sini memakai `@playwright/cli`
(`playwright-cli open/run-code/close`, dijalankan lewat
`npx --yes --package @playwright/cli playwright-cli`), melawan **server
Vite fixture berdiri sendiri** (`tests/Frontend/*/vite.config.ts`, tanpa
backend PHP sama sekali) yang me-render komponen React langsung dengan data
sintetis di dalam file fixture itu sendiri.

Rekomendasi: **job CI terpisah**, bukan digabung ke job `e2e` milik E2E-2
saat itu dipasang. Kedua mekanisme memang sama-sama butuh Chromium
ter-install, jadi langkah `npx playwright install --with-deps chromium`
bisa (tapi tidak wajib) dipakai bersama kalau kedua job berjalan di
runner/step yang sama — lihat §5.

## 2. Cakupan awal yang diusulkan

- `tests/Frontend/ParticipantLobby/browser.test.mjs` (satu file, semua
  skenario lobi + regresi visual 320/390/1280px).
- `tests/Frontend/IntegratedCheckout` lewat `run-checkpoint.mjs` (6 suite:
  baseline, optional, keyboard-reflow, unselected, payment-refresh,
  partial-profile).
- `tests/Frontend/oncam-token-runtime-bridge.test.mjs` — ini **bukan**
  browser test (`node --test` biasa, sudah bisa jalan tanpa Chromium),
  tapi relevan disebut karena satu regression test-nya (PR #53) benar-benar
  menjalankan `vite build()` sungguhan; kalau job CI baru ini menambah
  Chromium sekalian, `npm run test:frontend-unit`-gaya (belum ada script
  resminya — cek §7) bisa jalan di job yang sama tanpa biaya tambahan
  berarti.
- `tests/Frontend/PsychologistReview/browser.test.mjs` — TIDAK termasuk di
  cakupan awal ini. Fixture itu melawan `php artisan serve` sungguhan
  (bukan Vite berdiri sendiri), jadi kebutuhannya (migrasi, seed, `.env`)
  lebih dekat ke pola E2E-2 daripada dua fixture di atas. Usulkan sebagai
  increment terpisah setelah pola job ini terbukti stabil.

## 3. Langkah-langkah job yang diusulkan

Job baru, mis. `frontend-fixture-browser`, berjalan paralel dengan `ci` dan
`organization-postgres` yang sudah ada (independen, tidak butuh PHP/DB):

1. Checkout, setup Node (`node-version-file: '.nvmrc'`, sama seperti job
   `ci`).
2. `npm ci`.
3. `npx playwright install --with-deps chromium` (headless secara default —
   `playwright-cli open` TIDAK butuh `--headed`; diverifikasi langsung:
   `--headed` adalah flag opsional, bukan default, jadi tidak perlu Xvfb).
4. Untuk **ParticipantLobby**: start `npx vite --config
   tests/Frontend/ParticipantLobby/vite.config.ts` di background, tunggu
   siap (poll `http://127.0.0.1:8011/` — lihat §4 soal port), lalu
   `playwright-cli -s=<sesi> open about:blank` + `run-code --filename
   tests/Frontend/ParticipantLobby/browser.test.mjs`, cek exit code/output
   mengandung `### Error` atau tidak, lalu `close`.
5. Untuk **IntegratedCheckout**: start server Vite-nya sendiri, tunggu
   siap, lalu `node tests/Frontend/IntegratedCheckout/run-checkpoint.mjs
   <path-cli>` (lihat §7 soal path CLI ini perlu disesuaikan untuk CI,
   bukan path lokal seperti yang saya pakai manual selama investigasi).
   `run-checkpoint.mjs` sudah punya `process.exitCode` yang benar (0 kalau
   semua suite pass, 1 kalau ada yang gagal) — tinggal diperiksa exit code
   step-nya.
6. Upload artifact: `output/playwright/**` (screenshot regresi visual
   lobi, log per-suite checkout) supaya kegagalan bisa didiagnosis dari
   halaman Actions tanpa reproduksi lokal.
7. Matikan kedua server Vite di langkah `if: always()` supaya runner tidak
   menyisakan proses menggantung.

## 4. Port: sekarang sudah bisa diatur (PR #63), tapi CI harus eksplisit

PR #63 membuat port fixture bisa diatur lewat
`PARTICIPANT_LOBBY_FIXTURE_PORT` / `INTEGRATED_CHECKOUT_FIXTURE_PORT`, dengan
default berbeda (8011 vs 8012) supaya dua fixture tidak tabrakan satu sama
lain. Untuk CI, ini tidak terlalu relevan (satu runner = satu job, tidak
ada sesi lain yang berebut port) — cukup pakai default masing-masing, tidak
perlu di-set eksplisit. Poin pentingnya: `run-checkpoint.mjs` PERLU env var
itu untuk suite `browser-interactions.mjs` dkk-nya bekerja benar (lihat
komentar di file itu, PR #63) — CI tidak perlu mengatur env var apa pun
untuk memakai default, cukup pastikan tidak ada job lain di runner yang
sama yang juga memakai port 8011/8012.

## 5. Perkiraan tambahan waktu

- `npx playwright install --with-deps chromium`: ~30-60 detik di runner
  Ubuntu GitHub Actions standar (unduh + dependensi sistem), berdasarkan
  angka yang sudah didokumentasikan `tests/E2E/README.md` untuk kebutuhan
  serupa (unduhan Chromium, bukan seluruh matriks browser).
- Start server Vite fixture: <5 detik per fixture (diverifikasi lokal
  berulang kali selama investigasi; server dev Vite fixture minimal ini
  konsisten siap dalam 1-3 detik setelah proses `vite` dimulai).
- ParticipantLobby suite: puluhan detik (banyak skenario + 4 lebar
  viewport x 4 state = 16 screenshot regresi visual; dijalankan lokal
  berulang kali dalam investigasi ini, konsisten di bawah satu menit).
- IntegratedCheckout 6 suite lewat `run-checkpoint.mjs`: masing-masing
  suite adalah proses `playwright-cli` terpisah (`open` lalu `run-code`
  lalu `close`), jadi overhead start/stop browser per suite. Dijalankan
  lokal berulang kali (termasuk untuk PR #60 dan #63) — total di bawah
  3-4 menit untuk 6 suite secara berurutan.
- **Total perkiraan kasar: 5-8 menit** untuk job baru ini, di luar Chromium
  install. Ini ANGKA LOKAL (Windows workstation, bukan runner CI Ubuntu) —
  perlu diverifikasi ulang begitu job ini benar-benar dipasang; PR #33
  sendiri mencatat mesin lokal vs CI runner bisa berbeda signifikan untuk
  beban Playwright (kasus ekstrem: 7.8 detik jadi 54 detik untuk satu
  halaman admin di workstation Windows tertentu). Jangan jadikan angka di
  atas sebagai janji tanpa pengukuran nyata di runner sungguhan pada
  increment implementasi.

## 6. Status "merah" saat ini: TIDAK ADA — diverifikasi, bukan diasumsikan

Sebelum menulis rencana ini, saya jalankan ulang kedua harness terhadap
`main` terkini (setelah PR #58 dan #60 merge):

- ParticipantLobby: **lulus penuh**, termasuk regresi visual 320px yang
  dulu gagal — `scrollWidth === viewport` di semua state/lebar, tidak ada
  label yang `outside`/`clipped`.
- IntegratedCheckout lewat `run-checkpoint.mjs`: **6/6 suite lulus**
  (baseline 10, optional 9, keyboard-reflow 6, unselected 2,
  payment-refresh 8, partial-profile 9).

Jadi tidak ada fixture merah yang perlu di-karantina untuk increment
pertama ini. Tapi karena ini akan jadi kebiasaan berulang (halaman
pengerjaan tes IST/PAPI/RMIB/Kraepelin akan menambah fixture baru), tetap
perlu kebijakan untuk KASUS SUATU HARI ADA yang merah saat pertama kali
dipasang di CI:

- **Jangan melonggarkan assertion untuk membuatnya hijau** (sama seperti
  batasan yang berlaku di semua pekerjaan lane ini).
- Kalau sebuah suite genuinely merah saat pertama kali dipasang di CI (baru
  ketahuan karena belum pernah dijalankan), opsi yang jujur: (a) perbaiki
  cacatnya dulu di PR terpisah sebelum job CI diaktifkan untuk suite itu,
  seperti yang terjadi pada PR #58/#60 di sesi ini, atau (b) pasang job-nya
  sebagai **non-blocking status check** (`continue-on-error: true` di
  level step, bukan gerbang merge) untuk SATU siklus review supaya semua
  lane melihat hasilnya dulu tanpa memblokir PR yang sedang berjalan, lalu
  promosikan ke gerbang wajib begitu semua suite diverifikasi hijau. Opsi
  (b) hanya untuk PEMASANGAN AWAL, bukan untuk suite yang sudah established
  lalu mendadak merah — itu harus tetap memblokir.

## 7. Yang perlu diselesaikan di fase implementasi (bukan di rencana ini)

Ditemukan selama investigasi, dicatat supaya tidak mengejutkan siapa pun
yang mengambil implementasi:

1. `run-checkpoint.mjs` saat ini mewajibkan **path absolut** ke skrip
   `playwright-cli` (`process.argv[2]`, divalidasi
   `path.isAbsolute(cli) && existsSync(cli)`). Di mesin lokal saya pakai
   path ke cache `npx` (`~/AppData/Local/npm-cache/_npx/<hash>/...`, tidak
   portabel). Di CI, `@playwright/cli` perlu jadi devDependency biasa
   (`npm install --save-dev @playwright/cli` atau setara) supaya path-nya
   stabil (`node_modules/@playwright/cli/playwright-cli.js`), atau
   `run-checkpoint.mjs` diubah menerima nama perintah (`npx playwright-cli`)
   selain path absolut. Ini perubahan kode kecil, bukan keputusan
   arsitektur — disebut di sini supaya implementer tidak kaget saat
   `run-checkpoint.mjs` menolak jalan di CI dengan pesan error yang sama
   seperti yang saya lihat sendiri saat lupa memberi path absolut.
2. Tidak ada script `npm run` khusus untuk memicu harness ini (unlike
   `npm run e2e:ci` milik E2E-2). Implementasi CI perlu menulis langkah
   shell eksplisit (seperti didraf §3) atau menambah script `package.json`
   baru (mis. `fixture:lobby`, `fixture:checkout`) — keputusan penamaan
   diserahkan ke implementer, disebut di sini supaya tidak lupa.
3. `tests/Frontend/PsychologistReview/browser.test.mjs` sengaja dikeluarkan
   dari cakupan awal (lihat §2) — jangan tercampur saat implementasi.

## 8. Ringkasan keputusan yang perlu Lead/tim setujui sebelum implementasi

- Job baru terpisah dari `ci`/`organization-postgres`/E2E-2 (§1, §3) — atau
  digabung ke job E2E-2 begitu itu dipasang (butuh Chromium yang sama)?
  Rekomendasi saya: **terpisah**, karena targetnya (fixture Vite mandiri vs
  halaman admin Laravel sungguhan) sudah cukup berbeda untuk digabung
  hanya demi berbagi satu langkah instalasi Chromium.
- Non-blocking untuk siklus pertama (§6 opsi b) atau langsung wajib sejak
  awal, mengingat kedua fixture sudah terbukti hijau saat ini (§6)?
- Siapa yang mengambil implementasi (kode + workflow), dan kapan — di luar
  cakupan rencana ini.
