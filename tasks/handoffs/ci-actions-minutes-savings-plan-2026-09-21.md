# Rencana penghematan menit GitHub Actions

**Tanggal:** 2026-09-21. **Ditulis oleh:** kanal FE-Infra, atas instruksi Lead
(konteks: CI seluruh repo berhenti hari ini karena masalah tagihan GitHub).
**Sifat:** rencana saja, dari data nyata. Tidak ada workflow yang diubah di
sini. Pemilik proyek yang memutuskan opsi mana yang dijalankan.

## 0. Sumber data

`gh run list --limit 500 --json ...` (mencakup 2026-09-16 s/d 2026-09-21),
disaring ke run hari ini (`createdAt` berawalan `2026-09-21`) **sebelum**
gangguan tagihan mulai (dipotong di `08:47:00Z` — run setelah itu selesai
dalam 3-4 detik, pola yang cocok persis dengan laporan Lead, dan bukan
representasi biaya nyata). Untuk setiap cabang yang punya run hari ini, daftar
berkas diambil dari `gh pr list --search "head:<branch>" --json files`
(bukan `git diff` terhadap `main` sekarang, yang salah untuk cabang yang
sudah merge). Untuk durasi per job (bukan per run — run `ci` dan
`organization-postgres` berjalan paralel, jadi durasi run ≠ jumlah menit
yang ditagih), diambil `gh api .../actions/runs/<id>/jobs` untuk 3 run
sampel.

## 1. Angka dasar (diverifikasi, bukan dari laporan Lead saja)

- **Run hari ini sebelum gangguan: 65** (44 `pull_request`, 21 `push` ke
  `main`). Semua workflow bernama `tests` — hanya satu file workflow di
  repo ini.
- **Durasi per job, dari 3 run sampel** (semuanya berjalan paralel dalam
  satu run):
  - `ci`: 11m10s, 11m9s, 11m19s → **~11 menit**, cocok dengan perkiraan
    Lead (9-11 menit).
  - `organization-postgres`: 4m20s, 3m21s, 3m46s → **~4 menit** (dibulatkan
    ke atas dari rata-rata ~3.8), cocok dengan perkiraan Lead.
  - **Total ditagih per run lengkap: ~15 menit** (dua job dihitung
    terpisah oleh billing meski berjalan paralel di wall-clock).
- **Perkiraan total menit hari ini (sebelum gangguan): 65 × 15 ≈ 975
  menit** (~16.25 jam Actions-minutes untuk `ubuntu-latest`, pengali 1x).
  Ini kasar (mengasumsikan setiap run menjalankan kedua job sampai
  selesai; run yang gagal lebih awal di langkah lint/format menagih
  lebih sedikit) tapi cukup sebagai orde besaran untuk membandingkan opsi.
- **Fitur "required status checks" GitHub TIDAK aktif di repo ini**
  (`gh api repos/.../branches/main/protection` → 403, perlu upgrade
  paket berbayar untuk repo privat). Artinya kebijakan "`ci` wajib
  hijau" saat ini murni ditegakkan manual oleh Lead yang me-review PR,
  bukan oleh mekanisme GitHub yang bisa "macet menunggu" status yang
  tidak pernah dilaporkan. Ini relevan untuk Opsi 2 — lihat §2.2.

## 2. Opsi, dinilai dengan angka nyata

### 2.1 Opsi 1 — `concurrency` + `cancel-in-progress` per cabang/PR

**Data:** dari 65 run hari ini, **19 run "digantikan"** — run berikutnya
untuk cabang yang SAMA mulai sebelum run sebelumnya selesai (jadi hasil
run sebelumnya sudah tidak relevan begitu run berikutnya lahir). Delapan
di antaranya adalah push ke `main` sendiri (merge PR yang berurutan
cepat), sisanya PR yang di-push ulang dalam hitungan menit (kadang detik
— `fe/fix-frontend-fixture-harness` dua run hanya berjarak 30 detik).

Cabang yang terpengaruh: `main` (8×), `glm/resume-answer-readback` (3×),
`f0/extract-ist-items`, `f0/extract-papi-items`, `f2/item-delivery-stage1`,
`glm/kraepelin-grid-separate-file`, `fe/fix-frontend-fixture-harness`,
`docs/owner-decision-branch-admin-reports`, `f2/g7-server-side-sources`.

**Hemat:** 19 run × ~15 menit ≈ **285 menit hari ini saja** — kategori
terbesar dari semua opsi. Tidak melemahkan gerbang apa pun: commit yang
DIBATALKAN sudah digantikan oleh commit yang lebih baru sebelum siapa pun
sempat melihat hasilnya; hanya commit TERAKHIR di setiap cabang/PR yang
keputusan mergenya bergantung padanya.

**Risiko:** untuk `push` ke `main`, membatalkan run main yang sedang
berjalan berarti TIDAK ada catatan CI lengkap untuk commit main
perantara yang digantikan sebelum selesai. Ini standar dan umumnya
diterima (yang penting adalah status commit TERAKHIR di `main`, bukan
setiap commit perantara) — tapi sebutkan eksplisit ke pemilik proyek
kalau mereka pernah mengandalkan histori CI penuh per-commit-main untuk
audit.

**Rekomendasi:** grup concurrency terpisah untuk `push`
(`concurrency: group: push-main`) dan untuk `pull_request`
(`concurrency: group: pr-${{ github.event.pull_request.number }}`), agar
membatalkan run PR tidak pernah menyentuh run push milik cabang lain.

### 2.2 Opsi 2 — filter path untuk PR yang hanya mengubah dokumen

**Data:** dari 26 cabang non-`main` yang berjalan hari ini, **3 murni
dokumen** (nihil kode/config/data): PR #47
(`tasks/handoffs/decisions/owner-decisions-2026-09-21.md` saja), PR #64
(`tasks/handoffs/e2e/browser-fixture-harness-ci-plan-2026-09-21.md`
saja — punya saya sendiri hari ini), PR #68 (`API_CONTRACT.md` saja).
Bersama menyumbang **4 run** (PR #47 di-push dua kali) — salah satunya
JUGA masuk daftar "digantikan" di §2.1, jadi jangan dijumlah begitu saja
dengan hemat Opsi 1 (lihat §2.4 untuk gabungan tanpa duplikat).

**Hemat:** 4 run × ~15 menit ≈ **60 menit hari ini**. Kecil dibanding
Opsi 1 di sampel hari ini, tapi akan bertambah seiring makin banyak PR
dokumentasi/handoff (pola yang sudah mapan di repo ini — lihat volume
`tasks/handoffs/**` yang terus tumbuh).

**Risiko yang ditanyakan Lead — PR dokumen "menunggu" selamanya kalau
`ci` jadi required check:** ini terjadi HANYA kalau path filter dipasang
di level TRIGGER workflow (`on.pull_request.paths`/`paths-ignore`) untuk
job yang menjadi required check — dengan begitu, workflow-nya sendiri
TIDAK PERNAH berjalan untuk PR yang cocok filter, jadi TIDAK ADA status
apa pun yang pernah dilaporkan ke GitHub, dan aturan "wajib lulus check
X" menunggu selamanya karena check X tidak pernah ada.

**Cara aman:** JANGAN filter di level trigger. Biarkan workflow tetap
terpicu untuk SEMUA `pull_request`/`push`, tapi jadikan langkah-langkah
berat di dalam job `ci` (dan `organization-postgres`) BERSYARAT lewat
deteksi perubahan berkas di DALAM job (mis. action `dorny/paths-filter`
atau `git diff --name-only` terhadap base ref sebagai langkah pertama),
lalu langkah-langkah setelahnya pakai `if:` berdasarkan hasil deteksi
itu. Job yang di-skip lewat `if:` tetap MELAPORKAN status (`skipped`,
bukan `failure`, dan bukan "tidak pernah ada") — GitHub memperlakukan
job yang skip sebagai lulus untuk keperluan required check. Pola ini
dipakai luas di proyek open-source besar justru untuk menghindari
kebuntuan yang Lead khawatirkan.

**Catatan tambahan yang relevan (§1):** karena required status checks
belum aktif di repo privat ini (403 upgrade-required), risiko kebuntuan
ini SAAT INI murni teoretis untuk repo ini — merge tetap ditentukan
review manual Lead, bukan gerbang otomatis GitHub. Tapi pola aman di
atas tetap direkomendasikan karena costless untuk diterapkan dari awal,
dan relevan begitu repo diupgrade atau required checks diaktifkan nanti.

### 2.3 Opsi 3 — hindari run ganda `push` + `pull_request` untuk commit yang sama

**Data:** diperiksa langsung — **nol** kasus hari ini. Setiap SHA di 65
run hari ini hanya memicu SATU run. Ini karena trigger `pull_request` di
workflow ini tidak menyebutkan `types:`, yang berarti default GitHub
(`opened, synchronize, reopened`) — TIDAK termasuk `closed`, jadi
menge-merge PR (yang menghasilkan `push` ke `main`) tidak juga memicu
run `pull_request` kedua untuk commit merge yang sama.

**Hemat: 0 menit hari ini.** Bukan sumber pemborosan nyata di
konfigurasi saat ini — dicatat di sini supaya tidak diasumsikan sebagai
peluang tanpa bukti, sesuai permintaan Lead untuk data nyata bukan
tebakan.

### 2.4 Opsi 4 — `organization-postgres` hanya untuk perubahan berbasis path relevan

**Data:** dari 26 cabang, **8 jelas-jelas tidak menyentuh apa pun yang
relevan untuk Postgres** (nihil `app/**`, `database/**`, `routes/**`,
`config/**`, `composer.*`, `docker/**`, `tests/Postgres/**`):
`docs/frontend-fixture-harness-ci-plan` (#64), `fe/configurable-fixture-port`
(#63), `fe/fix-checkout-reset-loop-click` (#60),
`fe/fix-frontend-fixture-harness` (#53), `glm/lobby-320-overflow` (#58),
`glm/resume-answer-readback` (#54), `glm/participant-lobby-status-labels`
(#48), `docs/owner-decision-branch-admin-reports` (#47) — total **15 run**
hari ini (beberapa cabang punya beberapa run/push).

**Hemat (konservatif, hanya kasus jelas):** 15 run × ~4 menit
(`organization-postgres` saja, BUKAN seluruh 15 menit — `ci` tetap perlu
jalan untuk kode apa pun, termasuk frontend) ≈ **60 menit hari ini**.

**Yang SENGAJA tidak saya hitung sebagai hemat pasti** (dicatat sebagai
"perlu keputusan tim", bukan otomatis dimasukkan): lima cabang
menyentuh `database/seeders/data/*.json` (data instrumen F0 — mis.
`f0/extract-papi-items`, `f0/extract-rmib-items`, `f0/extract-ist-items`,
`ds/f0-kraepelin-extract`, `glm/kraepelin-grid-separate-file`) dan satu
menyentuh `composer.json`/`package.json`
(`glm/participant-session-runner-shell`). File data seeder BISA relevan
untuk `organization-postgres` (seeder dijalankan di sana lewat
`InstrumentSeeder`), tapi saya tidak menelusuri apakah data itu memang
ikut diverifikasi jalur Postgres atau hanya SQLite — **inilah persis
risiko yang diperingatkan Lead**: pola path yang kelihatan aman ("cuma
file JSON") bisa saja melewatkan sesuatu yang penting. Rekomendasi: pola
filter yang LEBAR dan konservatif — kecualikan `organization-postgres`
HANYA untuk PR yang murni menyentuh `tests/Frontend/**`,
`resources/js/**` (kecuali yang diketahui dikonsumsi backend),
`resources/css/**`, `tools/design-tokens/**`, `tasks/**`, `*.md` — apa
pun yang menyentuh `database/**`, `app/**`, `routes/**`, `config/**`,
`composer.*`, `docker/**` tetap menjalankan `organization-postgres`
tanpa terkecuali, termasuk file data JSON seeder (lebih aman
menjalankannya walau mungkin tidak perlu, daripada melewatkannya).

**Peringatan tambahan:** JANGAN filter path untuk PR yang mengubah
`.github/workflows/**` itu sendiri (seperti `f0/ci-extract-tests` hari
ini) — perubahan workflow harus selalu diverifikasi penuh.

### 2.5 Opsi 5 — cache (composer, npm, Chromium)

Ini TIDAK mengurangi jumlah run, tapi memperpendek run yang memang perlu
jalan — kategori terpisah dari Opsi 1-4, risikonya jauh lebih rendah
(tidak mengubah APA yang diperiksa, hanya seberapa cepat).

- **npm** (`ci` dan job baru `frontend-fixture-browser`): `actions/setup-node`
  punya dukungan cache bawaan (`cache: 'npm'`, kunci otomatis dari
  `package-lock.json`) yang BELUM dipakai di workflow ini sekarang. Dari
  log CI nyata yang pernah saya baca untuk PR #53 sebelumnya, `npm ci`
  tanpa cache di runner Ubuntu makan ~12 detik (bukan lambat di sana
  sebenarnya) — potensi hemat kecil untuk `npm ci` saja, tapi berarti
  untuk job `frontend-fixture-browser` yang saya bangun (`npm install
  --no-save @playwright/cli` + `npx playwright install --with-deps
  chromium`) karena unduhan Chromium dan paket sistemnya BISA di-cache
  lewat `actions/cache` (kunci: versi `@playwright/cli`/`playwright-core`
  + OS), berpotensi memangkas ~30-90 detik dari setiap run job itu.
- **composer**: `shivammathur/setup-php` tidak punya cache bawaan;
  `actions/cache` untuk `vendor/` (kunci `composer.lock`) bisa membantu,
  tapi dari log CI nyata yang sama, instalasi composer di job `ci`
  sudah relatif cepat (di bawah 1 menit termasuk download paket) —
  potensi hemat lebih kecil dibanding Chromium.

**Hemat:** tidak dihitung sebagai menit/hari (tidak mengurangi run),
tapi realistis memangkas **1-2 menit per run** dari job baru saya begitu
dipasang, dan sedikit dari job `ci`. Direkomendasikan sebagai perbaikan
"gratis" — risiko rendah, tidak menyentuh gerbang, layak dikerjakan
terlepas dari opsi mana yang dipilih.

### 2.6 Opsi tambahan yang saya lihat, di luar daftar Lead

6. **`timeout-minutes` yang lebih ketat per job.** `organization-postgres`
   sudah punya `timeout-minutes: 90` (jauh di atas durasi nyata ~4
   menit) — bukan sumber pemborosan langsung (timeout tidak menagih
   kalau job selesai lebih cepat), tapi kalau job itu PERNAH macet/hang
   (mis. kontainer Postgres gagal start tanpa exit bersih), batas 90
   menit berarti menagih hampir 1.5 jam sebelum GitHub memaksa hentikan.
   Usul: turunkan ke sesuatu seperti 15-20 menit (marjin aman di atas ~4
   menit nyata) supaya kegagalan-diam tidak menagih penuh. Job `ci` dan
   job baru saya tidak punya `timeout-minutes` eksplisit sama sekali
   (default GitHub 360 menit) — risiko serupa kalau ada yang hang,
   walau belum pernah terjadi di data hari ini.
7. **Jangan jalankan kedua job frontend baru saya (`ParticipantLobby`
   dan `IntegratedCheckout`) kalau HANYA `tests/E2E/**` yang berubah**,
   dan sebaliknya begitu job E2E-2 dipasang — dua target itu independen
   satu sama lain (lihat pemisahan yang sudah dijelaskan di #64), jadi
   filter path per-job yang SAMA seperti Opsi 4 (bukan cuma untuk
   `organization-postgres`) juga berlaku untuk kedua target frontend
   ini begitu keduanya aktif bersamaan.

## 3. Total gabungan (tanpa duplikat)

Menjumlah Opsi 1+2+4 secara naif akan menghitung ganda run yang
memenuhi lebih dari satu kondisi (satu run PR #47 "digantikan" DAN
"dokumen"). Dihitung persis (skrip, bukan taksiran manual) atas 65 run
hari ini, dengan setiap run dihitung SEKALI memakai hemat terbesar yang
berlaku untuknya (15 menit kalau memenuhi Opsi 1 atau 2 — keduanya
menghindarkan seluruh run, dua job sekaligus; 4 menit kalau HANYA
memenuhi Opsi 4 — `ci` tetap jalan, cuma `organization-postgres` yang
dilewati):

- Opsi 1 saja (digantikan): 19 run.
- Opsi 2 saja (dokumen): 4 run, 1 di antaranya tumpang tindih dengan
  Opsi 1.
- Opsi 4 saja (Postgres tidak relevan, kasus jelas): 15 run, sebagian
  tumpang tindih dengan Opsi 1 (mis. `fe/fix-frontend-fixture-harness`
  muncul di keduanya).
- **Union (run yang diuntungkan SETIDAKNYA satu opsi): tepat 30 dari 65
  run (46%).**
- **Menit yang dihemat hari ini kalau ketiganya dipasang: tepat 362
  dari ~975 menit total (37%).** Didominasi Opsi 1 (concurrency), yang
  sendirian sudah menangkap proporsi terbesar dan paling murah untuk
  diterapkan (satu blok `concurrency:` per job, tanpa logika deteksi
  path).

## 4. Batas keras yang saya jaga

Tidak ada opsi di atas yang mengurangi CAKUPAN pemeriksaan untuk
perubahan yang relevan — hanya menghindari MENGULANG pemeriksaan yang
sudah usang (Opsi 1, 3), atau MELEWATI pemeriksaan yang provably tidak
bisa terpengaruh oleh perubahan yang ada (Opsi 2, 4, dengan filter
konservatif dan daftar pengecualian eksplisit di §2.4). Opsi 5 dan 6
tidak menyentuh gerbang sama sekali.

## 5. Urutan yang saya sarankan, kalau pemilik proyek setuju melanjutkan

1. Opsi 1 (`concurrency`) — hemat terbesar, risiko terendah, perubahan
   workflow terkecil (beberapa baris per job).
2. Opsi 5 (cache) — gratis, tidak menyentuh gerbang, bisa dikerjakan
   kapan saja termasuk bersamaan dengan Opsi 1.
3. Opsi 2 (path filter dokumen) — pola aman sudah dijelaskan §2.2,
   tapi butuh sedikit lebih banyak logika (deteksi path in-job).
4. Opsi 4 (`organization-postgres` bersyarat) — hemat nyata tapi
   BUTUH keputusan tim eksplisit untuk daftar path yang dikecualikan
   (§2.4), karena ini job yang paling berisiko kalau filternya salah.
   Jangan dikerjakan sendirian tanpa review dari lane yang memahami
   persis apa yang `organization-postgres` tangkap dan tidak tertangkap
   SQLite.
