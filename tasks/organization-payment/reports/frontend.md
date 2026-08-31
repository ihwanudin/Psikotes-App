# Frontend — P16-prep

## P16-prep — payer unselected (2026-09-01)

Delta setelah **723bf2b**, yang menurut handoff diintegrasikan root **563fa6e**.
Root lint/probe sudah hijau; 14 import/order worker adalah perbedaan baseline dan
tidak ikut diperbaiki. ADR-004 induk dibaca read-only. Tidak reset/merge baseline.
Skill API/interface design, frontend, TDD dan Playwright yang tersedia dipakai.

**Kontrak internal DRAFT:** CheckoutPayment kini memiliki varian eksplisit
`{ payer: 'unselected', state: 'unselected', amountIdr: number | null }`.
Hanya satu state untuk payer ini; bukan sentinel funding_mode untuk disimpan,
bukan response HTTP, mapper atau keputusan P15. UI menampilkan label Pembayar /
Belum dipilih dan status **"Pembayar belum dipilih"**. Tidak fallback self/lembaga,
tidak ada pemilih payer, CTA bayar, handler baru atau invoice. Readonly berarti
tampilan informasional; type props tetap kontrak internal, bukan validator input HTTP.

Nominal null tetap "Belum tersedia"; nominal 0 ditampilkan Rp 0 tanpa menyimpulkan
free/paid/ready. Predicate CTA existing tetap self + unpaid/pending + actionAvailable;
organization/self/free dan akses milik server tidak diubah. CheckoutForm, consent,
auth/gate/schema/API tidak disentuh. Cabang, paket, biaya dan status tidak dihitung
atau dipilih browser. Tidak menambahkan naskah legal atau dependency.

**Tes nyata:** RED 22 lulus / 4 gagal pada renderer lama. GREEN **26 SSR lulus,
0 gagal/skip**: unselected untuk null, 0 dan nominal sintetis nonzero; injected
onPayment/actionAvailable tidak menghasilkan kontrol bayar; organizationName,
invoice/batchTotal/batchMembers ekstra tidak bocor. Kasus presentasi per props
unselected/self/organization/free/unselected mempertahankan akses locked.
Seluruh 22 SSR existing ikut diulang. SSR bukan bukti interaksi/effect.

**Browser mounted:** helper khusus membuktikan dua kelompok: (1) native
Tab/Space/Enter melewati card readonly, consent/refresh masih dapat dicapai, tanpa
callback pembayaran; (2) transisi fixture unselected → self pending → organization
pending → self paid → self free → unselected mengikuti props, tetap akses locked.
Callback self diuji positif lewat keyboard, membuktikan onPayment memang injected.
Pergantian skenario fixture existing me-remount checkout; tidak mengklaim rerender
instance yang sama atau transisi server/network. Tidak ada mutasi value/checked,
focus() atau dispatchEvent sebagai bukti native keyboard.

Enam screenshot asli card pembayaran (null/zero pada viewport 320/390/1280)
diperiksa: teks dan nominal utuh, tanpa CTA/field/link tersembunyi. Guard dokumen
scrollWidth = clientWidth **305/375/1265** (scrollbar Windows 15 px), padding card
20 px, radius 10 px dan h2 20 px; tidak ada clipping Range card. Tidak mengklaim
ulang audit visual seluruh halaman atau semua 25 kelompok browser gelombang lalu.
Browser final: **0 pageerror, console error/warning, request gagal/HTTP error,
request eksternal/API**. Favicon fixture mendapat respons sintetis 204 saja.

Typecheck focused, ESLint lima file kode/test, Prettier check dan preview build
lulus. JS preview **247.73 kB (gzip 77.05)**, CSS **70.37 kB (gzip 11.84)**.
Origin test-only 8011 dicek kosong sebelum server; envDir:false, Chrome session
baru checkout-unselected-d4ea dibuka about:blank. Seluruh data/callback sintetis;
tidak ada .env aktif, DB, login, payment/WA nyata. Browser/server ditutup dan port
8011 kembali kosong. Tidak menjalankan global tsc/lint, PHP/full suite atau P15.

Reproduksi focused dari worktree sendiri:

```powershell
node node_modules/vite/bin/vite.js build --config tests/Frontend/IntegratedCheckout/vite.config.ts --mode test
node --test storage/app/private/verification/frontend-test/checkout.test.js
node node_modules/typescript/bin/tsc --project tests/Frontend/IntegratedCheckout/tsconfig.json --noEmit
node node_modules/eslint/bin/eslint.js resources/js/types/integrated-checkout.ts resources/js/components/integrated-checkout/checkout-payment.tsx tests/Frontend/IntegratedCheckout/fixtures.ts tests/Frontend/IntegratedCheckout/checkout.test.tsx tests/Frontend/IntegratedCheckout/unselected-payment.mjs
node node_modules/vite/bin/vite.js build --config tests/Frontend/IntegratedCheckout/vite.config.ts --mode preview
```

Browser: pastikan port kosong seperti prosedur pada bagian sebelumnya, jalankan
Vite config/mode preview di terminal terpisah. Dengan CLI cached existing:

```powershell
$checkoutCli = 'C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js'
New-Item -ItemType Directory -Path output/playwright/checkout-unselected -Force | Out-Null
node $checkoutCli -s=checkout-unselected-d4ea open about:blank --headed
# run-code meminta function expression tanpa trailing semicolon formatter.
$unselectedRun = (Get-Content tests/Frontend/IntegratedCheckout/unselected-payment.mjs -Raw).Trim().TrimEnd(';')
[System.IO.File]::WriteAllText((Join-Path (Get-Location) 'output/playwright/checkout-unselected/run.js'), $unselectedRun)
node $checkoutCli -s=checkout-unselected-d4ea run-code --filename output/playwright/checkout-unselected/run.js > output/playwright/checkout-unselected/browser.log 2>&1
if ((Get-Content output/playwright/checkout-unselected/browser.log -First 1) -ne '### Result') { throw 'Browser failed; inspect log' }
node $checkoutCli -s=checkout-unselected-d4ea close
# Hentikan hanya server fixture milik lane.
```

Bukti ignored: output/playwright/checkout-unselected/{browser.log,run.js,
320-null.png,320-zero.png,390-null.png,390-zero.png,1280-null.png,1280-zero.png};
log SSR RED/GREEN di output/playwright/checkout-unselected-{red,green}.log.
Tidak commit generated output atau snapshot awal. Lima file perubahan utama:
types/integrated-checkout.ts, checkout-payment.tsx, fixtures.ts, checkout.test.tsx
dan laporan ini. Helper keenam unselected-payment.mjs diserahkan sebagai commit
bukti browser terpisah sesudah perubahan utama, sesuai izin ownership.
**Stop review; varian DRAFT ini tidak menyelesaikan P16 end-to-end/P15/server mapper.**

## Wave-7 tooling — cakupan ESLint generated output (2026-09-01)

Delta setelah **12f777c**, yang menurut handoff diintegrasikan root **a84d9bb**.
Preflight: eslint.config.js tracked, bersih sebelum perubahan, dan SHA-256 sama
dengan root yang dibaca read-only. Tidak commit snapshot atau reset/merge baseline.
Ownership tiga file: eslint.config.js, tests/Frontend/eslint-scope.test.mjs,
dan laporan ini. Skill debugging/Git workflow dipakai; tidak ada task baru.

**Perbaikan:** empat baris ditambahkan pada object global ignores existing:
komentar dan tiga pola `storage/app/private/verification/**`,
`output/playwright/**`, `.playwright-cli/**`. Tidak mengabaikan seluruh storage,
tests, resources, atau direktori source tetangga; tidak mengubah rules/settings.
Output verification ditetapkan kedua config Vite fixture di tests/Frontend dan
docs/ORGANIZATION_PAYMENT_TESTING.md. Browser artifacts sudah ditetapkan .gitignore;
storage/app/private/.gitignore juga mengecualikan output privat. .gitignore sendiri
tidak membuat ESLint otomatis mengabaikan bundle ketika `lint:check` = `eslint .`.

**Hasil nyata worktree (bukan angka root 44.154):**

| Kelompok | Sebelum: file / error | Sesudah: file / error |
| --- | --- | --- |
| Generated verification | 8 / 51.764 | 0 / 0 |
| Generated output/playwright | 2 / 2 | 0 / 0 |
| Source existing | 86 / 14 | 86 / 14 |
| Probe source baru | belum ada saat baseline | 1 / 0 |
| Total | 96 / 51.780 | 87 / 14 |

Kedua run `npm run lint:check` exit **1**. Penyebab generated terselesaikan,
tetapi **global lint belum hijau** karena 14 error source existing. Perbedaan jumlah
dengan root berasal dari snapshot/output bundle lokal; tidak mengklaim menjalankan
lint root. Tidak ada warning dalam kedua hasil global worktree.

Semua 86 file source baseline tetap muncul setelah perubahan; seluruh daftar pesan
lint per file dibandingkan deepEqual dan **tidak berubah**. File tambahan hanya
probe baru. Full effective rules untuk checkout-form.tsx, checkout.test.tsx dan
probe sendiri juga dibandingkan deepEqual sebelum/sesudah: identik.

Sisa error semuanya `import/order`, sudah ada sebelum ignore dan tidak diperbaiki:

| Source di resources/js/ | Error |
| --- | --- |
| components/app-header.tsx | 1 |
| components/app-sidebar.tsx | 1 |
| components/delete-user.tsx | 1 |
| components/manage-passkeys.tsx | 1 |
| components/user-menu-content.tsx | 1 |
| layouts/auth/auth-simple-layout.tsx | 1 |
| layouts/auth/auth-split-layout.tsx | 1 |
| layouts/settings/layout.tsx | 1 |
| pages/auth/confirm-password.tsx | 1 |
| pages/auth/login.tsx | 1 |
| pages/settings/profile.tsx | 2 |
| pages/settings/security.tsx | 2 |

**Regresi:** RED awal 1 lulus / 1 gagal karena bundle verification belum ignored.
GREEN **2 tes lulus, 0 gagal/skip**: lima path generated dicek isPathIgnored dan
lintText tidak menghasilkan input lint; 12 path app/tests/tetangga tetap included
dan kode sintetis invalid masih menghasilkan error `curly` severity 2. Probe
mencakup checkout/lobby source, SSR/browser tests, storage/output tetangga, dan
nama output/playwright yang berada di dalam tests (tidak boleh ikut diabaikan).
Probe lintText tidak membuat file palsu di direktori source. Lint focused config
dan probe, syntax check kedua file, serta Prettier check probe lulus. Prettier pada
eslint.config.js exit 1; baseline `git show HEAD:eslint.config.js` melalui stdin
Prettier juga exit 1. Formatting config existing tidak diperbaiki di luar empat
baris ignore. Tidak ada rule yang dimatikan.

Perintah reproduksi dari worktree sendiri, tanpa install atau --fix:

```powershell
New-Item -ItemType Directory -Path output/playwright/eslint-scope -Force | Out-Null
node --test tests/Frontend/eslint-scope.test.mjs
node node_modules/eslint/bin/eslint.js eslint.config.js tests/Frontend/eslint-scope.test.mjs
node --check eslint.config.js
node --check tests/Frontend/eslint-scope.test.mjs
node node_modules/prettier/bin/prettier.cjs --check eslint.config.js tests/Frontend/eslint-scope.test.mjs
npm.cmd run lint:check -- --format json --output-file output/playwright/eslint-scope/after.json > output/playwright/eslint-scope/after-cli.log 2>&1
$checkoutLintExit = $LASTEXITCODE
"Global lint exit: $checkoutLintExit"
node --input-type=module -e "import fs from 'node:fs'; const rows=JSON.parse(fs.readFileSync('output/playwright/eslint-scope/after.json','utf8')); console.log(JSON.stringify({files:rows.length,errors:rows.reduce((n,r)=>n+r.errorCount,0),warnings:rows.reduce((n,r)=>n+r.warningCount,0)}));"
```

Baseline command sama dengan output before.json/before-cli.log, dijalankan sebelum
perubahan. Log penuh JSON sengaja disimpan ignored, tidak dicetak sebagai puluhan
ribu error bundle. Bukti lokal: output/playwright/eslint-scope/{before,after}.json,
summary.json, rules-before.json, probe-red.log/probe-green.log dan log CLI.
summary.json mencatat source yang dipertahankan, pesan identik, dan sisa error.
Tidak commit semua output tersebut.

**Batas/serah-terima:** patch tooling tidak menyelesaikan 14 error source existing
atau seluruh wave-7. Tidak menjalankan browser/build/typecheck/DB/full suite karena
tidak ada perubahan TS/UI/runtime. Tidak mengubah dependencies, API/auth/schema,
.env, data aktif, gate atau mapper. P16 tetap belum end-to-end. **Stop untuk review;
perbaikan source import/order unrelated membutuhkan scope terpisah.**

## Verifikasi checkout gabungan — harness saja (2026-09-01)

Delta dari **1650115**, yang menurut handoff sudah diintegrasikan koordinator
sebagai **83e116d** (root SSR 22/22). Tidak reset/merge root atau snapshot baseline.
Ownership hanya `tests/Frontend/IntegratedCheckout/browser-interactions.mjs`, helper
baru `tests/Frontend/IntegratedCheckout/keyboard-reflow.mjs`, dan laporan ini.
Tidak ada perubahan produksi, props/payer types, API, schema, mapper, dependency,
config bersama atau gate. Skill Playwright yang tersedia dibaca/dipakai kembali.

**Hasil mounted browser gabungan:** seluruh **10 kelompok existing + 9 kelompok
optional-email + 6 kelompok keyboard native** lulus dalam satu invocation. Sepuluh
kasus lama dipindahkan dari Browser-tab API ke Playwright Page tanpa membuang
assertion: fokus dibaca langsung dari activeElement, bukan annotation snapshot.
Assertion kecil portable menggantikan import node:assert agar source yang sama
dapat dijalankan melalui CLI run-code. Sembilan kasus optional tetap dijalankan.

| Bukti keyboard tambahan | Hasil |
| --- | --- |
| Tab/Space/Enter pada email opsional blank, terisi, lalu dihapus | Blank tidak punya submit/callback; terisi mengirim hanya email; dihapus tidak mengirim update kosong |
| Tab ke submit lalu Enter dengan email invalid | Native typeMismatch menolak callback dan memindahkan fokus ke email |
| Callback absen, legal pending, busy, formKey | Enter tidak memanggil callback; kontrol busy dilewati Tab; formKey menghapus edit tanpa submit kosong |
| Phone wajib + email opsional | Enter memfokuskan phone kosong; email invalid juga ditolak; phone valid + email whitespace mengirim phone saja |
| Error summary dan consent terpisah | Error mendapat fokus, Tab/Enter pada tautan internal menuju phone; Space memilih consent utama, Space/ArrowDown memilih DASS decline, Enter mengirim pilihan terpisah |
| formKey dan kedua revisi consent | Aktivasi tombol dengan Tab/Enter mereset field dan kedua pilihan; guard bertahan, kemudian consent-only submit tetap dapat dilakukan |

Keyboard memakai `page.keyboard.press/type`, **tanpa** focus(), dispatchEvent,
penetapan value/checked atau mutasi DOM untuk mensimulasikan keyboard. evaluate
hanya membaca state/fokus/geometri. Observer React existing menunjukkan
`trusted=true` untuk input dalam root; Tab awal dari body berada di luar observer
itu dan dibuktikan oleh native key serta fokus berikutnya. Probe requestSubmit
programmatic tetap ada dalam 19 kelompok lama/optional, bukan bukti keyboard.
Pengujian keyboard final memakai viewport 1280×900 di Chrome desktop Windows;
tidak mengklaim keyboard fisik perangkat mobile atau audit screen reader.

**Visual:** enam screenshot asli (optional-only kosong dan mixed required kosong)
diperiksa. Logo/palette ONCAM, heading, label, input, copy dan tombol utuh. Pada
320/390 konten satu kolom; pada 1280 ringkasan pembayaran di kanan dan input mixed
dua kolom. Optional-only tanpa CTA; mixed tetap punya CTA. Tidak ada page overflow
horizontal atau clipping teks yang terdeteksi pada enam capture berikut.

| Viewport CSS px | clientWidth = scrollWidth | Padding content / card | h1 / radius card | Kasus |
| --- | --- | --- | --- | --- |
| 320 | 305 | 16 / 20 px | 30 / 10 px | optional, mixed |
| 390 | 375 | 16 / 20 px | 30 / 10 px | optional, mixed |
| 1280 | 1265 | 32 / 28 px | 30 / 10 px | optional, mixed |

Lebar konten dikurangi scrollbar Windows 15 px; guard membandingkan scrollWidth
dengan clientWidth, bukan mengasumsikan sama dengan innerWidth. Radius 10 px
ditelusuri ke app.css `--radius: 0.625rem`/rounded-lg. Guard styling padding/h1/radius
tetap eksplisit. Pemeriksaan Range teks menguji batas viewport konten dan ancestor
yang benar-benar melakukan clipping, tidak menyalahartikan line box sebagai clip.
Kegagalan awal adalah asumsi harness (observer body, scrollbar/radius), bukan bug
produksi yang diperbaiki diam-diam. CLI VM juga tidak menyediakan global URL;
pemeriksaan origin memakai pemisahan URL string, tetap mengunci origin 8011.

Bukti ignored: `output/playwright/checkout-combined/run.log`, `run.js`, dan
`{320,390,1280}-{optional,mixed}.png`. Enam screenshot menampilkan main checkout;
guard overflow tetap mengukur keseluruhan dokumen termasuk kontrol fixture.
Tidak commit screenshot/log/storage output. Bukti ini bukan snapshot test piksel.

**Console/network:** final memakai session terisolasi `checkout-combined-d4ea`
yang dibuka di about:blank; monitor dipasang sebelum navigasi fixture. Guard
menolak origin luar/API, console error/warning, pageerror, HTTP >=400 dan request
gagal. **Final: 104 request lokal; 0 request eksternal/API, 0 request gagal/HTTP
error, 0 pageerror dan 0 console error/warning.** Fixture tidak memiliki favicon;
browser harness menyuplai respons sintetis
204 hanya untuk `/favicon.ico`. Ini menghilangkan 404 favicon yang terlihat pada
open awal sebelum monitor; tidak mengubah atau menyembunyikan error aset aplikasi.
Tidak ada backend, .env aktif, login, token, data peserta/payment/WA nyata.
Port 8011 diperiksa kosong sebelum server dimulai. Session Chrome dan server Vite
milik lane sudah ditutup setelah run final; port 8011 kembali kosong.

Perintah reproduksi (PowerShell, cwd worktree sendiri, dependencies cached existing):

```powershell
# Terminal A: pastikan origin kosong, lalu hidupkan hanya server fixture ini.
if (Get-NetTCPConnection -LocalPort 8011 -State Listen -ErrorAction SilentlyContinue) {
    throw 'Port 8011 sudah dipakai; jangan hentikan server lain.'
}
node node_modules/vite/bin/vite.js --config tests/Frontend/IntegratedCheckout/vite.config.ts --mode preview
```

```powershell
# Terminal B: CLI cached yang tersedia pada run ini; tidak install dependency.
$checkoutCli = 'C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js'
node $checkoutCli -s=checkout-combined-d4ea open about:blank --headed
New-Item -ItemType Directory -Path output/playwright/checkout-combined -Force | Out-Null
$checkoutSources = @(
    'tests/Frontend/IntegratedCheckout/browser-interactions.mjs'
    'tests/Frontend/IntegratedCheckout/keyboard-reflow.mjs'
) | ForEach-Object { (Get-Content $_ -Raw).Replace('export async function', 'async function') }
$checkoutRun = 'async (page) => {' + "`n" + ($checkoutSources -join "`n") + "`n" +
    'return verifyCombinedCheckout(page, verifyCheckoutInteractions, verifyOptionalEmail);' + "`n}"
[System.IO.File]::WriteAllText((Join-Path (Get-Location) 'output/playwright/checkout-combined/run.js'), $checkoutRun)
node $checkoutCli -s=checkout-combined-d4ea run-code --filename output/playwright/checkout-combined/run.js > output/playwright/checkout-combined/run.log 2>&1
if ((Get-Content output/playwright/checkout-combined/run.log -First 1) -ne '### Result') {
    throw 'Browser run gagal; baca log, jangan hanya mengandalkan exit code CLI.'
}
node $checkoutCli -s=checkout-combined-d4ea close
# Ctrl+C hanya pada server milik lane di Terminal A.
```

Focused lint kedua helper, node --check kedua file, Prettier check, typecheck
`node node_modules/typescript/bin/tsc --project tests/Frontend/IntegratedCheckout/tsconfig.json --noEmit`
dan preview build `node node_modules/vite/bin/vite.js build --config tests/Frontend/IntegratedCheckout/vite.config.ts --mode preview`
lulus. Typecheck tersebut mencakup TS komponen/fixture existing; JS helper diverifikasi
oleh lint/syntax dan eksekusi browser. Build JS 247.37 kB (gzip 76.99), CSS 70.37 kB
(gzip 11.84), tanpa perubahan produksi. Tidak mengulang SSR/full suite/global tsc
pada increment verifikasi ini; angka root 22/22 di atas adalah laporan koordinator.

**Batas:** ini uji presentasi mounted dan callback sintetis, bukan E2E backend,
otorisasi, P15, atau wiring P16. Tidak membuka akses atau menganggap paid siap.
Tidak ada task/agent baru, reset/merge baseline, atau staging snapshot awal.
**P16 tetap belum end-to-end; stop untuk review sebelum increment berikutnya.**

## Email opsional — increment presentasi (2026-09-01)

Delta kecil setelah **aae2ffd**; tidak reset/merge snapshot baseline. Menurut
handoff koordinator, pasangan fixture e7a0fd3/0e727db telah diintegrasikan sebagai
5dec16f/cb3ddbf dan proposal aae2ffd sebagai **0b68ddb (DRAFT)**. Status tersebut
menggantikan catatan menunggu integrasi pada bagian historis di bawah. Arah adapter
server diterima; P14/P15, wire dan payer unresolved belum disahkan.

**Perubahan:** CheckoutProfile membedakan required missing dari hanya field opsional
yang kosong: "Data wajib sudah lengkap. Data opsional dapat diisi jika Anda ingin."
Input email opsional tetap tampil. CheckoutForm menawarkan konfirmasi bila ada
required missing, edit opsional nonblank, atau kebutuhan consent existing. Bila
hanya email opsional kosong/whitespace dan consent sudah tercatat, tidak ada tombol
submit maupun callback kosong melalui handler. Email kosong diomit dari
`missingProfile` pada konfirmasi required/consent; edit nonblank dikirim tanpa
mengubah nilainya. Key dalam summary dan union locked tidak diubah.

`state`/`required` tetap metadata props server, bukan keputusan otorisasi browser.
Guard legalReviewPending, busy, onConfirm dan consent utama dipertahankan. DASS
tetap pilihan terpisah; onPayment/onRefresh dan status akses dari server tetap.
Tidak ada mapper executable, endpoint, storage, schema, auth, gate, naskah legal,
harga runtime hardcode, shared config atau perubahan dependency.

**TDD dan bukti nyata:**

- RED: 20 SSR lulus / 2 gagal pada copy email opsional sebelum perubahan produksi;
  browser gagal pada copy yang sama. Probe handler baseline menaikkan callback
  0 menjadi 1 dengan payload salah `{"missingProfile":{"email":""}}`.
- GREEN: **22 SSR lulus, 0 gagal/skip**. Tiga kasus tambahan meliputi opsional saja,
  campuran required/opsional, dan consent yang tetap memerlukan konfirmasi.
- **9 kelompok interaksi mounted browser lulus**, bukan bukti SSR: email kosong
  tanpa CTA/callback; email terisi mengirim hanya email; hapus/whitespace tidak
  mengirim update kosong; email invalid ditolak validasi native dan difokuskan;
  busy/onConfirm absen/legal pending tetap diblok; formKey reset membersihkan edit;
  phone wajib + email kosong mengirim phone saja; consent utama tanpa email atau
  pilihan DASS tetap dapat dikonfirmasi; kedua versi consent reset; decline DASS
  eksplisit; callback payment/refresh tidak mengubah status pembayaran/akses.
- Run final setelah reload penuh: **0 pageerror, 0 console error, 0 request
  eksternal/API**. Log sesi sebelumnya memiliki error React createRoot saat HMR
  selama penyuntingan; tidak mengklaim seluruh log pengembangan bebas error.
- Typecheck focused, ESLint kelima file kode/fixture, build SSR dan build preview
  lulus. Preview: JS 247.37 kB (gzip 76.99), CSS 70.37 kB (gzip 11.84).
- Screenshot `output/playwright/checkout-email/optional-blank-390.png` diperiksa:
  copy data wajib lengkap, input opsional dan tanpa CTA konfirmasi terlihat.
  Screenshot bukan pengganti pengujian callback; tidak mengklaim audit reflow baru.

Origin test-only `127.0.0.1:8011` diperiksa kosong sebelum server fixture dijalankan
dengan envDir:false. Chrome memakai session baru `checkout-email-d4ea`, bukan tab
pengguna. Semua data/callback sintetis, tidak ada login/token/DB/payment nyata.
Session Chrome dan server fixture milik lane sudah ditutup; port 8011 kembali kosong.
Skill frontend, TDD, Playwright, Git workflow dan review yang sudah dibaca dipakai.

Reproduksi focused: jalankan build dengan config existing
`tests/Frontend/IntegratedCheckout/vite.config.ts`, mode `test`, lalu
`node --test storage/app/private/verification/frontend-test/checkout.test.js`.
Typecheck memakai `tests/Frontend/IntegratedCheckout/tsconfig.json`; build browser
memakai config Vite yang sama dengan mode `preview`. Untuk interaksi, jalankan dev
server config tersebut pada 8011; panggil export `verifyOptionalEmail(page)` dari
`tests/Frontend/IntegratedCheckout/browser-interactions.mjs` dengan Playwright Page.
Run ini memakai Playwright CLI cached: body export disalin ke ignored
`output/playwright/checkout-email/run.js` sebagai function expression async lalu
`playwright-cli -s=checkout-email-d4ea run-code --filename output/playwright/checkout-email/run.js`.
Hasil sembilan kelompok ada di ignored `output/playwright/checkout-email/final.log`.

**Batas:** probe requestSubmit bersifat programmatic, bukan bukti keyboard native.
Penolakan email/required memakai input browser dan klik submit native; whitespace
email mengikuti sanitasi native type=email. Sepuluh kelompok helper browser lama
tidak diklaim diulang dalam increment ini. Tidak menjalankan full suite/global tsc
atau memverifikasi server/P15; paid tidak berarti akses siap. Tidak membuat agent
baru atau mengubah dokumen kanonik. **P16 tetap belum end-to-end; stop review.**

File delta: checkout-profile.tsx, checkout-form.tsx; fixtures.ts,
checkout.test.tsx, browser-interactions.mjs (ketiganya di IntegratedCheckout);
laporan frontend ini. Snapshot awal tidak termasuk commit lane.

## Proposal pemetaan profil nullable — dokumen saja (2026-09-01)

Deliverable: [frontend-profile-mapping-proposal.md](frontend-profile-mapping-proposal.md).
**DRAFT internal, belum disetujui sebagai kontrak server/HTTP final atau mapper.**
Delta lane dari 0e727db hanya dua dokumen. Pasangan e7a0fd3 → 0e727db masih menunggu
review/verifikasi integrasi koordinator; tidak mengklaim sudah masuk root.

ADR-004, SPEC-integrated-checkout, todo P14/P15/P16, request provisioning,
CheckoutContractAdapter, prasyarat akses, validasi publik, types/components checkout,
dan audit nullable lane dibaca. Sumber induk pada HEAD
`0b3c739af2ffff4e7e620f4414114633185d2ebf` dibaca read-only. Skill API and Interface
Design serta Documentation and ADRs tersedia/dibaca dan dipakai.

Proposal memilih satu presenter server read-only sebelum props diserahkan ke React:
tujuh key selalu hadir, null/blank menjadi missing yang diizinkan server, enam field
wajib dan email opsional, locked displayValue tetap string nonblank. Nilai invalid
tidak dipalsukan menjadi missing; izin update diputuskan ulang P15 saat submit.
Field lengkap dipertahankan, tanpa registrasi ulang, default UMUM atau asumsi payer.

Dua gap sebelum wiring dicatat eksplisit: copy/konfirmasi untuk email saja yang
kosong, serta union pembayaran yang belum mewakili payer belum dipilih. Matriks
20 kasus mencakup formatting/enum, required/opsional, payload missing-only, tampering,
race, scope dan paid yang belum memenuhi syarat akses. P14/P15 dan review metadata
server tetap dependensi; penambahan intendedField backend bukan response HTTP final.

Verifikasi hanya review statis konsistensi source/kontrak dan git diff --check.
Tidak menjalankan browser/build/tes/DB atau membuat file produksi, mapping executable,
runner, endpoint, schema/config atau perubahan naskah legal. Tidak membuat task/agent
baru, reset/merge baseline atau mengedit checklist kanonik. **Stop review sebelum
implementasi mapper; P16 tidak ditandai selesai.**

## GREEN fixture lobby dan reflow (2026-09-01)

Delta setelah **e7a0fd3**. Integrasikan secara atomik dalam urutan **e7a0fd3 (RED)
→ commit GREEN yang memuat bagian ini** (`F16: restore lobby fixture styles and verify reflow`).
Jangan mengintegrasikan checkpoint RED sendirian. Baseline tidak di-reset/merge;
perubahan ini mengikuti ownership tambahan yang disetujui koordinator.

CSS baru `tests/Frontend/ParticipantLobby/preview.css` mengimpor app.css dan
mendeklarasikan @source eksplisit lobby.tsx; preview.tsx mengimpor entry baru itu.
Tidak mengubah CSS global, komponen produksi, konfigurasi bersama, dependency,
auth/API/schema, atau mapper checkout. Skill Playwright, frontend, debugging dan
review yang telah dibaca tetap dipakai; tidak ada task/agent tambahan.

Ada satu koreksi pengukuran pada browser.test.mjs: setelah CSS aktif, Range heading
di 320 px memiliki top/bottom 150/190 sementara line box 152/188. Overflow visible
dan screenshot memperlihatkan teks utuh, sehingga membandingkan batas vertikal
font dengan line box adalah false positive clipping. Tes kini memeriksa batas
ancestor yang benar-benar melakukan clipping (hidden/clip/auto/scroll), tetap
memeriksa overflow horizontal dan ellipsis. **Guard styling 16px/30px/16px tidak
dihapus, diubah, atau dilonggarkan.**

**Hasil nyata run final:** 9 kasus perilaku existing dan 12 capture/geometri lulus,
0 pageerror. Keempat state loading/null/complete/error pada tiap lebar diperiksa
dari screenshot asli, bukan hanya assertion DOM:

| Lebar CSS px | scrollWidth | Padding main / h1 / radius section | Hasil visual |
| --- | --- | --- | --- |
| 320 | 320 | 16px / 30px / 16px | Fallback utuh; nomor lengkap membungkus dua baris, tanpa terpotong |
| 390 | 390 | 16px / 30px / 16px | Nama/nomor null dan lengkap utuh; status dan card tidak bertabrakan |
| 1280 | 1280 | 16px / 30px / 16px | Konten terpusat; heading, nomor, loading/error tampil utuh |

Screenshot loading menunjukkan skeleton berukuran benar; error menampilkan ikon,
heading dan pesan utuh. Tidak ada page overflow horizontal atau label terpotong
dalam fixture yang diperiksa. Entitlement locked/Siap tetap seperti respons
sintetis, tanpa tombol akses. Native Tab tidak mengubah URL; tetap 0 kontrol atau
tautan pada lobby informasional ini, sehingga tidak mengklaim audit focus ring.

Build fixture lulus: CSS **16.41 kB (gzip 4.12)**, JS **320.19 kB (gzip 101.09)**.
Typecheck focused dan ESLint preview.tsx/browser.test.mjs lulus. Origin 8011
diperiksa kosong sebelum server dinyalakan; Chrome memakai session baru
`oncam-lobby-green-d4ea` dan profil sementara. Semua respons API tetap intercepted
fixture, token literal sintetis; tidak ada jaringan luar, DB, login nyata atau
penyimpanan placeholder. Error HTTP 401 dari skenario negatif memang diharapkan;
tidak mengklaim console zero-error. Server dan browser uji ditutup setelah tes.

Artifact ignored di `output/playwright/lobby-visual/`: results-green.log dan 12 PNG
`{320,390,1280}-{loading,null,complete,error}.png` sekarang berisi capture GREEN.
PNG RED sebelumnya diarsipkan ke subfolder `red-before-css/`; results-guarded.log
tetap menjadi bukti kegagalan styling terdahulu. Screenshot/log tidak di-commit.
Pengulangan memakai perintah build/typecheck/lint/run-code yang dicatat di bawah,
dengan session `oncam-lobby-green-d4ea`; path script browser dan config Vite tetap sama.

**Empat file delta:** preview.css (baru), preview.tsx, browser.test.mjs, laporan ini.
Bukti hanya fixture Chrome pada tiga ukuran, bukan auth/server end-to-end, semua
panjang nama/nomor, screen reader, zoom atau lintas browser. P9a0/P16/akses checkout
tidak ditandai selesai. **Stop untuk review dan integrasi atomik RED + GREEN.**

## Verifikasi visual lobby — fixture belum layak bukti geometri (2026-08-31)

Delta dari **e46bca4**, yang telah diintegrasikan koordinator sebagai 78d7c53.
**Hasil: visual acceptance BELUM lulus. Ditemukan cacat styling harness, bukan
kesimpulan cacat label produksi.** Sesuai instruksi, usulan perbaikan diserahkan
lebih dahulu; tidak mengubah komponen produksi atau memperluas ownership.

Playwright CLI cached memakai session baru `oncam-lobby-visual-d4ea` dan profil
Chrome sementara, bukan tab pengguna. Port 8011 diperiksa kosong sebelum Vite
fixture dijalankan. Tidak memakai .env, DB, login/credential nyata, API nyata,
atau navigasi eksternal. Sembilan kasus teks/fetch existing dijalankan ulang;
ditambah 12 capture full-page pada viewport tinggi 900 CSS px:

| Lebar | State yang dicapture | scrollWidth terukur | Styling lobby |
| --- | --- | --- | --- |
| 320 | loading, null, complete, error | 320 | Tidak termuat |
| 390 | loading, null, complete, error | 390 | Tidak termuat |
| 1280 | loading, null, complete, error | 1280 | Tidak termuat |

Screenshot `320-null.png` dan `1280-complete.png` diperiksa secara visual: layout
polos tanpa padding/card/warna header dan tanpa ukuran heading yang semestinya.
Computed style pada seluruh 12 capture mengonfirmasi padding main 0px, h1 16px,
radius section 0px; probe tambahan menunjukkan header transparan. Utility pada
komponen seharusnya memberi px-4 (16px), text-3xl (30px), rounded-2xl (16px).
Karena styling hilang, scrollWidth yang sama dengan viewport serta label yang
tidak terpotong **tidak diterima sebagai bukti reflow tampilan produksi**.

Penyebab yang terlokalisasi: `tests/Frontend/ParticipantLobby/preview.tsx` mengimpor
app.css langsung, sementara root Vite fixture tidak menyertakan sumber class lobby
di luar root. CSS build fixture sebelumnya tidak mengandung px-4/bg-teal-950/
max-w-2xl/rounded-2xl. Harness checkout lain sudah memakai entry CSS dengan @source
eksplisit. Tidak ada perubahan CSS global atau dugaan perubahan schema yang dibuat.

**Usulan minimal untuk review berikutnya:** tambahkan CSS entry khusus fixture
ParticipantLobby yang mengimpor app.css dan mendeklarasikan
`@source '../../../resources/js/pages/participant/lobby.tsx';`, lalu ubah import
preview.tsx ke entry tersebut. Rebuild dan ulangi 12 capture serta pemeriksaan
screenshot sebelum memutuskan ada/tidaknya clipping produksi. Belum diterapkan.

Harness browser sekarang memeriksa lebar halaman, batas fragmen teks heading/
nomor/status, overflow/ellipsis, dan keberadaan utility styling. Guard styling
membuat run final **gagal eksplisit** dengan pesan
`Visual fixture missing lobby utilities; geometry is NOT accepted` untuk 12 kasus,
setelah screenshot disimpan. Ini tes RED untuk cacat fixture yang dilaporkan;
tidak ditandai skip atau dilonggarkan agar hijau. Typecheck focused dan ESLint
focused lulus. Bukti teks/fetch increment sebelumnya tidak diklaim membuktikan CSS.

DOM lobby existing memiliki 0 tautan/kontrol/tabindex pada state yang diperiksa.
Native Tab tidak mengubah URL; tidak ada tautan eksternal yang diklik. Tidak ada
urutan kontrol/focus ring yang dapat dinyatakan lulus karena halaman informasional
ini memang tidak menyediakan kontrol. Audit screen reader/zoom/lintas browser
tidak dilakukan, dan keyboard checkout tidak termasuk scope ini.

Repro memakai perintah increment sebelumnya, session `oncam-lobby-visual-d4ea`,
dan `run-code --filename tests/Frontend/ParticipantLobby/browser.test.mjs`.
Artifact ignored di worktree ini: `output/playwright/lobby-visual/` berisi
`{320,390,1280}-{loading,null,complete,error}.png`, `results.log` (probe awal),
`results-guarded.log` (RED final), dan `lint-final.log`. Screenshot/log tidak
di-commit. Server dan browser uji ditutup setelah pengumpulan bukti.

**Hanya dua file tracked berubah:** browser.test.mjs dan laporan ini. Tidak
mengubah produksi/auth/API/schema/portal/mapper atau mereset/merge baseline.
P9a0 dan checkout/akses tetap belum selesai. **Stop untuk review koordinator.**

## Increment lobby nullable — temuan audit #1 (2026-08-31)

Delta terhadap **3105383**; audit telah diintegrasikan koordinator sebagai 793d494.
ADR-004 dan bagian akhir parallel-work.md induk dibaca read-only. Sebelum edit,
lobby.tsx sudah tracked dan bersih di worktree; SHA256 sama dengan induk:
`577788741D873E2E25079C4E233320773FE72F472418A927C5F1480D757E018B`.
Tidak reset/merge baseline atau memasukkan snapshot awal. Skill frontend/TDD/Git
yang telah dibaca tetap dipakai; skill Playwright dan Debugging & Error Recovery
dibaca untuk pengujian mounted component dan kendala tooling.

**Perubahan produksi hanya lobby.tsx:** full_name/test_number bertipe string|null.
Null, string kosong, dan whitespace dirender sebagai "Nama belum dilengkapi" serta
"Belum tersedia". trim hanya menentukan apakah blank; nilai nonblank tetap dirender
utuh, termasuk spasi awal/akhir. Tidak menyimpan placeholder atau mengubah fetch,
effect, token/auth, entitlement, controller/API, schema, portal, maupun union checkout.

**Bukti nyata:**

- RED: harness browser pada kode lama gagal `null: name was ""`.
- GREEN: **9 skenario browser lulus**, 0 kegagalan pada run final: kedua nilai null,
  empty, whitespace, lengkap, hanya nama hilang, hanya nomor hilang, nonblank dengan
  spasi dipertahankan, fetch gagal, dan token fixture tidak ada.
- Tujuh skenario profil masing-masing menahan respons sintetis sampai loading
  terlihat, lalu menjalankan fetch/effect asli hingga ready. Assertion membaca teks
  heading dan nomor secara persis; status locked/Siap tetap dari fixture entitlement,
  tidak ada tombol akses, hanya dua GET API yang diizinkan, token fixture tidak berubah.
  Respons 401 sintetis menampilkan error existing dan menghapus token; tanpa token
  langsung menampilkan pesan existing tanpa fetch API. Ini **bukan SSR**.
- Typecheck focused, ESLint focused konfigurasi proyek asli, dan Vite build fixture
  lulus. Build JS 320.19 kB (gzip 101.09), CSS 9.04 kB (gzip 2.49).
  Pemuatan awal lint sempat tertahan; probe loader/duplikat dihentikan atau selesai.
  Run final memakai perintah Node biasa, tanpa perubahan konfigurasi/dependency.
- Chrome melalui Playwright CLI cached, session khusus `oncam-lobby-d4ea`, profil
  sementara; tidak attach ke tab pengguna. Origin 127.0.0.1:8011 diperiksa kosong.
  Harness Inertia memuat komponen produksi tanpa Laravel/.env/DB/login nyata.
  Semua /api/* dicegat sebelum navigasi; hanya dua path GET dikenal yang diizinkan.
  Token adalah literal sintetis bukan credential, hanya dalam sessionStorage profil
  browser uji. Tidak ada write jaringan atau penyimpanan placeholder peserta.
- Run final memiliki 0 pageerror; dua console error HTTP 401 memang berasal dari
  skenario negatif sintetis. Tidak mengklaim console zero-error atau auth/server
  end-to-end. Browser uji dan server preview ditutup setelah verifikasi.

**Pengulangan dari root worktree:**

```powershell
node node_modules/vite/bin/vite.js --config tests/Frontend/ParticipantLobby/vite.config.ts
# Terminal terpisah; gunakan CLI cached/tersedia, tanpa install:
node C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js -s=oncam-lobby-d4ea open about:blank --browser chrome
node C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js -s=oncam-lobby-d4ea run-code --filename tests/Frontend/ParticipantLobby/browser.test.mjs
node C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js -s=oncam-lobby-d4ea close
node node_modules/typescript/bin/tsc --project tests/Frontend/ParticipantLobby/tsconfig.json --noEmit
node node_modules/eslint/bin/eslint.js resources/js/pages/participant/lobby.tsx tests/Frontend/ParticipantLobby/preview.tsx tests/Frontend/ParticipantLobby/vite.config.ts tests/Frontend/ParticipantLobby/browser.test.mjs
node node_modules/vite/bin/vite.js build --config tests/Frontend/ParticipantLobby/vite.config.ts
```

CLI mengevaluasi file tes sebagai function expression; jangan menambahkan semicolon
sebelum/sesudah expression. Satu pengecualian no-unused-expressions pada entrypoint
tes mendokumentasikan pemanggilan CLI; aturan produksi/config bersama tidak diubah.
Log build/browser lokal ada di storage/app/private/verification (tidak di-commit).

**File delta (7):** lobby.tsx, lima file harness baru di
tests/Frontend/ParticipantLobby (index.html, preview.tsx, vite.config.ts,
tsconfig.json, browser.test.mjs), dan laporan ini. Tidak ada dependency install,
full suite/global tsc, DB, endpoint, wiring P16, atau perubahan alur akses.
Schema/backend/portal tetap milik review terpisah. **Stop untuk review koordinator.**

## Audit read-only prasyarat P9a — profil nullable (2026-08-31)

**Status: temuan untuk review, bukan persetujuan migrasi atau aktivasi profil parsial.**
Delta lane terhadap **6f72711** hanya laporan ini. Proposal backend dibaca melalui
`git show 6146ef0 -- tasks/organization-payment/reports/backend.md`; consumer terbaru
dibaca read-only dari induk pada HEAD **c1c8a02e76e498d55a37a9134776890016e894fb**.
Path frontend, views, model Participant, profile controller dan Filament yang diaudit
tidak menunjukkan perubahan lokal di induk saat diperiksa. Tidak reset/merge baseline.
Skill Code Review and Quality dibaca dan dipakai bersama konteks frontend sebelumnya;
tidak mendelegasikan task/agent tambahan.

**Temuan yang perlu ditangani bila nullable disetujui:**

1. **Required — kontrak lobby tidak mencerminkan nama nullable.**
   `resources/js/pages/participant/lobby.tsx:11` menetapkan `full_name: string`,
   melakukan cast respons tanpa validasi di baris 68, lalu merendernya langsung ke
   heading di baris 151. `app/Http/Controllers/ParticipantProfileController.php:28`
   meneruskan nilai model apa adanya. Null menghasilkan heading kosong, bukan crash
   formatter/string. `/api/me` memakai JWT/RLS (`routes/api.php:32`);
   `AuthenticateParticipantJwt::participantExists()` memeriksa keberadaan dan cabang,
   bukan kelengkapan profil. Karena itu gate akses assessment bukan jaminan nama
   non-null pada API ini. Dampak bersyarat: participant parsial mempunyai token valid
   atau profil pemegang token menjadi parsial; audit ini tidak menyatakan checkout-v2
   sudah menerbitkan token tersebut. Minimal: sepakati `full_name: string | null`
   di boundary ini serta label tampilan seperti "Nama belum dilengkapi" untuk
   null/kosong; jangan simpan label ke database atau menganggapnya bukti identitas.
   FYI terkait: `test_number: string` di baris 12 juga tidak sesuai nullable yang
   **sudah ada** di schema/model; evaluasi `string | null` dan label "Belum tersedia"
   bersamaan tanpa mengklaim mismatch tersebut disebabkan proposal baru.

2. **Required untuk portal yang menampilkan peserta parsial — nama tabel kosong.**
   `app/Filament/Resources/AssessmentParticipants/AssessmentParticipantResource.php:68`
   dan `app/Filament/Resources/Orders/OrderResource.php:76` menggunakan kolom
   `participant.full_name` tanpa placeholder/normalisasi blank. Tabel peserta mencakup
   PROVISIONED sehingga tidak boleh mengandalkan profil sudah lengkap. Tabel order
   terdampak jika participant parsial memiliki order manual; bukan klaim alur itu
   sudah aktif. Minimal: fallback tampilan untuk null/blank, pertahankan ID kandidat
   atau order yang sudah tersedia untuk membedakan baris; tidak menambah PII/akses.
   Tidak ditemukan callback nama bertipe string yang langsung memformat null pada
   kedua kolom; risiko yang teridentifikasi adalah label kosong, belum bukti crash.
   `OrganizationBills/Pages/ViewOrganizationBill.php:87` **sudah** memakai fallback
   `?? 'Tidak tersedia'` dan menampilkan ID attempt terpisah. Null aman secara statis;
   string kosong/whitespace tidak terkena `??`. Normalisasi blank hanya perlu bila
   boundary mengizinkannya. Jangan hilangkan fallback aman relasi yang tidak tersedia.

3. **Required sebelum wiring P16 — null harus menjadi field `missing`.**
   `resources/js/types/integrated-checkout.ts:10` adalah union presentasi DRAFT,
   bukan raw Participant DTO. Cabang locked sengaja memiliki `displayValue: string`;
   missing sudah mendukung text/date/tel/select tanpa nilai awal. Pertahankan tipe
   itu; jangan memperlebar semua props menjadi nullable. Adapter yang kelak disetujui
   harus memetakan enam kolom null ke field missing, beserta label/options non-null
   dan required sesuai aturan completion server. Email tetap mengikuti kebijakan
   opsional yang ada. `checkout-profile.tsx:30` menyebut data lengkap ketika tidak
   ada field missing: menghilangkan key null dari array atau menguncinya dengan
   displayValue kosong akan memberi pesan keliru serta menghilangkan input koreksi.
   `checkout-form.tsx:63` hanya mengirim field missing. `intendedField` ada dalam
   tipe UI tetapi tidak dalam allowlist profil provisioning; sumber/pengisian yang
   sah harus direview, bukan default UMUM atau asumsi dari paket. Input string kosong
   adalah state form, bukan izin menyimpan placeholder. Tidak ada mapper HTTP yang
   diimplementasikan atau disahkan oleh audit ini.

4. **Required pada perubahan model — PHPDoc non-null perlu diselaraskan.**
   `app/Models/Participant.php:22` dan `:23` menyatakan `string $full_name` dan
   `CarbonInterface $birth_date`. Jika schema berubah, gunakan nullable pada keduanya
   dan pastikan metadata tipe empat atribut lain mengikuti schema; jangan mengubah
   typed input registrasi lengkap menjadi opsional secara global. Tidak ditemukan
   raw Participant interface bersama di TypeScript selain proyeksi kecil lobby.

**Pembacaan lain dan batas dampak:**

- Tidak ditemukan formatter birth_date, gender, education_level, intended_field atau
  phone pada frontend/portal yang diaudit. Checkout merender displayValue yang sudah
  disiapkan; tidak memanggil Date/Carbon pada profil. Adapter mendatang perlu guard
  tanggal null sebelum formatting, tanpa tanggal hari ini/epoch sebagai pengganti.
  `ParticipantLogin.php:31` sudah memakai nullsafe format dan sentinel pembanding;
  null DOB tidak dapat dipakai login dengan input tanggal valid. Jangan longgarkan
  autentikasi sebagai perbaikan UI. `AssessmentAccessPrerequisites.php:18–25`
  memeriksa string kosong, enum gender dan null DOB sebelum operasi tanggal; ini
  inspeksi statis, bukan bukti keseluruhan aktivasi/session aman pada schema baru.
- `use-initials.tsx:11` memang memanggil trim pada string, tetapi call site yang
  ditemukan adalah auth User (`user-info.tsx:19`, `app-header.tsx:225`), bukan
  Participant. Tidak ada dasar memperlebar User.name atau mengklaim crash avatar
  participant. Pertahankan pemisahan tipe User dan Participant.
- Registrasi publik `pages/registration/create.tsx` menerima konfigurasi, bukan
  profil participant tersimpan; required input dan StoreParticipantRegistrationRequest
  tetap berlaku. received/order-status dan kedua launch Blade tidak memformat enam
  atribut profil. Ekspor AssessmentParticipantExportController memakai ID dan hasil,
  bukan nama/DOB/phone. Tidak perlu menambah profil sensitif ke props/ekspor untuk
  mengatasi nullable. FYI: copy paid pada order-status.tsx:44 menyatakan akses aktif;
  jangan pakai ulang copy legacy itu untuk checkout parsial, karena paid bukan
  bukti completion. P16 tetap memakai access props terpisah.

**Verifikasi dan tindak lanjut minimal (belum dijalankan):**

Audit berupa pencarian `rg`, pembacaan call site, kontrak serta tes existing;
tidak mengubah kode/tipe/schema dan tidak menjalankan browser, tes, build, install,
server atau database. Bukti tes gelombang sebelumnya di bawah **bukan** bukti nullable.
Setelah keputusan schema/kontrak: uji API+lobby nama null/kosong dan nomor belum ada;
uji tabel portal dengan nama null sambil menjaga tenant scope; uji mapper checkout
dengan seluruh enam field hilang serta kombinasi field locked/missing (termasuk
intendedField dan DOB), label/select/date tidak palsu, dan payload hanya field missing.
Tes checkout sekarang mencontohkan phone missing, bukan pemetaan raw null; tes API
menegaskan birth_date/phone tidak ikut respons. Pertahankan batas tersebut.
Pemeriksaan akses/aktivasi dan migrasi tetap milik review backend koordinator.
**Hanya reports/frontend.md diserahkan; P9a/P16 tidak ditandai selesai. Stop review.**

## Gelombang kedua — hardening interaksi (2026-08-31)

Delta terhadap commit lane terakhir **895aeb8**, bukan baseline/checkpoint induk.
Koordinator mengintegrasikan wave 1 sebagai c7b9280. parallel-work.md dan
reports/integration-wave-1.md terbaru dibaca dari induk secara read-only; tidak
reset/merge/cherry-pick baseline. Skill Frontend UI, UI/UX Pro Max, React Best
Practices, Browser, TDD dan Git Workflow beserta referensi yang sudah dibaca
tetap dipakai. Tidak ada task/agent tambahan atau perubahan dependency.

**Hasil:** konfirmasi hanya boleh ketika `legalReviewPending === false`.
Predicate `canConfirm` yang sama mengendalikan disabled tombol dan early return
di handler onSubmit. Persetujuan utama yang sudah tercatat tidak melewati blok ini.
Alasan disabled terhubung melalui aria-describedby. Tidak mengganti teks/legal
policy server, menambah endpoint/storage, menghitung harga, atau memberi akses tes.

**RED → GREEN nyata:**

- Sebelum fix: tes SSR legal-pending gagal (18 pass/1 fail). Probe browser
  mencentang persetujuan sintetis lalu menjalankan requestSubmit pada form lengkap:
  CTA enabled=true dan counter callback naik 0 → 1 meski legal pending.
- Setelah fix: **19 tes Node/SSR lulus, 0 gagal/skip**. Tambahan dua tes menegaskan
  legal pending memblokir, dan legal false tetap menawarkan konfirmasi DASS ketika
  consent utama sudah tercatat. SSR tidak memanggil callback.
- **10 skenario interaksi browser lulus** melalui script baru
  tests/Frontend/IntegratedCheckout/browser-interactions.mjs:
  legal pending menahan CTA dan handler requestSubmit; DASS decline mengirim false;
  busy menahan CTA/handler; callback absent tidak bisa submit; kembali ke pending
  setelah konfirmasi kembali memblokir; error memfokuskan summary serta mempertahankan
  input; tiga reset terpisah (formKey, versi psikotes, versi DASS); callback pembayaran
  self pending tidak mengubah status.
- Pada tiga reset, checkbox/radio kembali kosong dan CTA disabled. Setelah consent
  dicentang ulang, klik submit tetap tidak memanggil callback dan fokus menuju phone
  wajib yang kosong. Setelah phone diisi ulang, callback membawa nilai baru; DASS
  yang belum dipilih tidak dikirim. Ini bukti browser, bukan kesimpulan dari SSR.
- Typecheck targeted, ESLint targeted dan Vite build preview: lulus. Build JS
  246.55 kB (gzip 76.82), CSS 70.37 kB (gzip 11.84). Console tab uji: 0 error/warning.
  Tidak menjalankan ulang global tsc yang diketahui kekurangan generated Wayfinder
  di worktree ini; global tsc koordinator adalah bukti checkout induk saja.
- Origin 127.0.0.1:8011 diperiksa kosong, tanpa .env aktif; harness tetap tanpa
  Laravel/DB. PHP/PG/full aplikasi tidak dijalankan karena tidak ada perubahan backend.
  Tab uji ditutup dan server preview dihentikan setelah verifikasi.

**Batas native keyboard masih terbuka, sekarang dengan bukti diagnostik:**

Harness menampilkan key dan nativeEvent.isTrusted serta fokus terakhir. Playwright
press(' ') pada checkbox menghasilkan `key= ; trusted=false`, tidak toggle. CUA
Space juga tidak toggle. ArrowDown pada radio menghasilkan `trusted=false`, tetap
di radio awal. CUA Tab menghasilkan `key=Tab; trusted=false`, fokus tetap pada radio.
Enter pada tombol enabled menghasilkan `key=Enter; trusted=false`, callback tetap 0.
Tidak ada mutasi DOM via evaluate, dispatchEvent, click pengganti, atau requestSubmit
yang diklaim sebagai bukti keyboard native. Karena tool memberi event sintetis tanpa
default action native, **Tab/Space/radio arrows/Enter belum terverifikasi lulus**.
Retest dengan keyboard nyata/alat native yang tersedia masih diperlukan sebelum
menutup acceptance keyboard; tidak membuat klaim WCAG/NVDA/axe penuh.

requestSubmit dalam harness adalah probe programatis untuk menguji guard handler
secara terpisah dari disabled CTA. Tombolnya berlabel **bukan keyboard**. Toggle
legal dan revisi dokumen hanya mengubah props fixture sintetis; bukan pengesahan
naskah atau izin untuk mematikan review server. Counter/telemetri hanya di test root,
tidak di komponen produksi, route, atau storage browser.

Pengulangan: jalankan perintah focused build-test/Node/tsc/eslint/build-preview/serve
yang dicatat di bagian verifikasi wave 1. Setelah bootstrap sesuai skill Browser,
buka tab khusus origin uji dan panggil melalui Node REPL tool:

```js
const { verifyCheckoutInteractions } = await import(
    'C:/Users/ThinkPad/.codex/worktrees/d4ea/Psikotes/tests/Frontend/IntegratedCheckout/browser-interactions.mjs'
);
await verifyCheckoutInteractions(tab); // mengembalikan 10 hasil; throws jika gagal
```

File delta (5 file): checkout-form.tsx; checkout.test.tsx; preview.tsx;
browser-interactions.mjs (baru); reports/frontend.md ini. Tidak mengubah types
contract, fixture naskah, UI/payment state lain, routes, global CSS, lockfile,
schema, checklist kanonik, atau folder proyek induk. Commit hanya path lane ini.
**P16 tetap belum end-to-end; stop untuk review koordinator.**

## Catatan historis gelombang pertama

Tanggal: 2026-08-31. Slice presentasi tersedia untuk review lokal.
**P16 belum selesai end-to-end; wiring final menunggu P14/P15 dan review kontrak.**

## Preflight dan isolasi

- Worktree: `C:/Users/ThinkPad/.codex/worktrees/d4ea/Psikotes`.
- Keempat baseline wajib tersedia: AssessmentEntitlementGate, ReserveAssessmentBill,
  AssessmentAccessFixture, dan parallel-work.md. Snapshot awal modified/untracked
  tidak di-stage atau di-commit ulang. Koordinator kemudian mengonfirmasi checkpoint
  induk `3112e1524292cebe4fbde19e1c24eaa3befc8d0e`; tidak reset/switch/merge baseline.
- CLAUDE.md, parallel-work.md, tiga spec terkait, plan/todo, kontrak checkout P5,
  gate akses P8a, dan UI registrasi/token ONCAM dibaca. Tidak mengedit dokumen kanonik.
- Skill tersedia/dibaca: Frontend UI Engineering, UI/UX Pro Max, React Best Practices,
  Browser, TDD, Git Workflow; referensi aksesibilitas/testing serta aturan React
  event-handler/derived-state dibaca. Pencarian UX menekankan error summary dan label.
- Tool `send_message_to_thread` tercantum di metadata tetapi pemanggilan gagal
  `is not a function`; koordinator mengizinkan komunikasi lewat laporan lane.
- `node_modules` disalin independen memakai robocopy `/E /XJ` (exit 1 = file tersalin).
  Kedua package-lock identik SHA256
  `E8A50F8A14992153085D621E8C2DFCA8DC8708F59D0FC40F8FDFFA7F9D3A1253`.
  Tidak menyalin vendor, .env, secret, database, atau cache runtime induk.
- Node 24.11.0; Vite terpasang 8.2.2. Tidak mengubah dependency/package/lockfile.
- Preview Vite tersendiri: root tests/Frontend/IntegratedCheckout, envDir=false,
  publicDir=false, tanpa plugin Laravel/Wayfinder/artisan. Loopback 127.0.0.1:8011
  diperiksa kosong sebelum bind; strictPort, no-store/no-referrer. Tidak memakai DB.
  Server dihentikan dan tab uji ditutup setelah verifikasi; viewport dikembalikan.

## Hasil slice

- Ringkasan profil lengkap, hanya field `missing` dapat diisi; cabang, sumber,
  paket, dan pembayar tidak memiliki input yang dapat diubah.
- Consent utama dimulai false; DASS dimulai null dengan pilihan setuju/tidak setuju.
  Pilihan DASS null tidak dikirim sebagai accepted. Menolak DASS tidak memblokir
  konfirmasi psikotes utama. Consent persisted ditampilkan sebagai teks, bukan checkbox
  prechecked. Perubahan formKey atau dokumen consent mereset form lokal.
- State self, organization unbilled/pending/paid, free, review, rejected, expired,
  loading, load error, serta busy ditampilkan. Lunas/gratis tidak membuka akses sendiri.
- Biaya hanya memakai amountIdr milik attempt dari props, diformat Intl.NumberFormat;
  tidak menjumlah, menghitung harga, atau menyimpulkan free dari angka nol.
- Organization tidak merender tombol pembayaran, invoice/link/bukti/anggota/total batch.
  Callback pembayaran yang terinjeksi sekalipun tidak membuat tombol organisasi muncul.
- Tanpa handler, tombol transaksi/konfirmasi disabled dan keterbatasan dijelaskan;
  bantuan/status opsional hanya tampil sebagai tombol bila callback tersedia.
- Logo PNG dan palette ONCAM existing dipakai ulang. Tidak ada route produksi,
  HTTP client, browser storage, token, start-test callback, timer, atau skoring baru.

## Props contract — DRAFT internal, bukan kontrak HTTP final

Definisi: resources/js/types/integrated-checkout.ts. Semua mapping berikut proposal
untuk review koordinator; jangan serialisasi model billing langsung menjadi props.

| Props | Usulan sumber/pemetaan server berikutnya | Batas |
| --- | --- | --- |
| screen | Lifecycle pemuatan sesi privat P14 | loading/error/expired tidak membawa summary/PII; invalid/replay dapat memakai expired generik |
| formKey | Identitas render attempt dari sesi server | Bukan token/credential; diganti ketika konteks peserta/attempt berubah |
| sourceName, branchName, packageName, attemptLabel | Registry/attempt yang dipetakan server | Tidak bisa diubah peserta; tidak berasal dari referral browser |
| profile[] | Proyeksi privat profil, whitelist missing fields P15 | Setiap key unik; locked memakai displayValue, missing memakai input/options/required; label/options dikirim server |
| identityMessage | Pesan aman dari prasyarat identitas P8/P15 | Kelengkapan profil bukan bukti verifikasi; uploader/verifikasi bukti di luar slice |
| payment.amountIdr | Snapshot charge attempt sendiri | Integer IDR aman/nonnegatif atau null jika belum tersedia, divalidasi server; tidak menerima harga dari input peserta |
| payment.payer/state | Payer efektif dan proyeksi alokasi sendiri P14 | paid hanya dari settlement tepat, free dari jalur free eksplisit; mapping review/expired/rejected perlu review |
| payment.actionAvailable | Izin server untuk melanjutkan self unpaid/pending | Tidak ada field ini untuk organization/paid/free; callback tetap harus diautentikasi server |
| access | Keputusan/pesan gate server | Label informasional saja, tanpa tombol atau hak mulai tes |
| consents | Dokumen versioned dan bukti consent yang masih berlaku P15 | Text di-escape React; draft legal tetap ditandai, fixture bukan naskah sah |
| busy, feedback | State request dari container P14/P15 | Container wajib mengelola lifecycle request, error aman dan busy; fieldErrors hanya untuk field editable |

`onConfirm` hanya menerima `{ missingProfile, psychotest?, dass? }`: tidak ada payer,
branch, paket, nominal, paid, ready, atau invoice. Version consent dikirim bersama
pilihan eksplisit; bukti/catatannya tetap diverifikasi server. `intendedField` termasuk
usulan field UI untuk prasyarat P8, **bukan** perluasan otomatis input profile P5.
`onPayment`, `onRefresh`, `onHelp` menerima tanpa argumen: container kelak harus memakai
sesi yang sah. Pending self berarti melanjutkan pembayaran yang sama, bukan reservasi
atau invoice baru. Callback tidak memiliki implementasi jaringan dalam slice ini.
Server tetap wajib validasi ulang field locked, scope attempt, nominal, consent,
CSRF, replay, sesi, dan otorisasi; TypeScript bukan boundary keamanan.

## Verifikasi nyata

Jalankan dari root worktree; tidak ada PHPUnit/PG karena slice tidak menyentuh PHP/DB.

```powershell
node node_modules/vite/bin/vite.js build --config tests/Frontend/IntegratedCheckout/vite.config.ts --mode test
node --test storage/app/private/verification/frontend-test/checkout.test.js
node node_modules/typescript/bin/tsc --noEmit -p tests/Frontend/IntegratedCheckout/tsconfig.json
node node_modules/eslint/bin/eslint.js resources/js/components/integrated-checkout resources/js/types/integrated-checkout.ts tests/Frontend/IntegratedCheckout
node node_modules/vite/bin/vite.js build --config tests/Frontend/IntegratedCheckout/vite.config.ts --mode preview
node node_modules/vite/bin/vite.js --config tests/Frontend/IntegratedCheckout/vite.config.ts --mode preview
```

- Tes SSR memakai Node test runner bawaan (repo tidak punya Vitest/RTL). RED awal:
  import komponen IntegratedCheckout belum ada. GREEN akhir: **17 tes lulus, 0 gagal,
  0 skip**. Menguji ringkasan, editable missing-only, consent awal, state pembayaran,
  larangan payment organization/terminal/free/paid, tidak bocor props batch tambahan,
  nol bukan free otomatis, loading/error/expired tanpa PII, serta busy/handler absent.
- Typecheck targeted, ESLint targeted, dan build preview: lulus. Build akhir JS
  243.90 kB (gzip 76.24), CSS 70.34 kB (gzip 11.82), logo 53.28 kB.
- `npm run types:check` global **gagal** karena modul generated Wayfinder
  resources/js/routes dan resources/js/actions tidak tersedia di snapshot. Tidak
  mengklaim typecheck global/build aplikasi Laravel lulus; tidak menjalankan artisan
  untuk mengatasinya. Focused config mencakup seluruh komponen, fixtures dan harness.
- Browser in-app pada origin test 8011: 14 skenario diperiksa (13 state matrix dan
  profil parsial). Submit synthetic mengirim hanya phone + consent versioned; DASS
  false dikirim eksplisit dan null dihilangkan. Status paid/access tidak berubah.
  Error summary aktif/fokus, tautan field, inline error dan retensi input terlihat.
  Busy menonaktifkan konfirmasi. State organization/paid/free/terminal tidak punya
  payment CTA. Expired/error/loading tidak menampilkan identitas.
- Ukuran viewport 320/768/1024/1440: scrollWidth sama dengan clientWidth
  (305/753/1009/1425, karena scrollbar 15 px); screenshot dan reflow diperiksa.
  Artefak lokal tidak committed: storage/app/private/verification/checkout-mobile.png
  dan checkout-desktop.png (viewport saja). Full-page stitching browser sempat
  menduplikasi bagian tangkapan, sehingga artefak itu diganti screenshot viewport.
- Temuan preview diperbaiki: Tailwind tidak memindai komponen di luar root test
  (ditambah preview.css @source, global CSS tidak diubah); HMR createRoot ganda
  (dispose/unmount test harness); encoding UTF-8 saat pemisahan file. Console tab
  pemeriksaan akhir setelah reload: **0 error/warning**.
- Keyboard: kontrol native/label, fokus error dan tautan field terverifikasi. Perintah
  Tab/Space tool tidak memberikan perpindahan/toggle native yang dapat diandalkan;
  **audit keyboard end-to-end belum dinyatakan lulus**. Butuh retest keyboard nyata.
  Tidak menjalankan NVDA/axe, pengukuran contrast otomatis, dark mode atau zoom 200%.

## File lane dan handoff

Commit lokal komponen dasar: `2e8f8ef`; komposisi/tes: `3ac7e4e`. Commit ketiga
berisi preview/laporan ini; hash disertakan pada pesan serah-terima. Seluruh commit
dibuat dengan daftar path eksplisit setelah memeriksa cached names; tanpa baseline.

Increment komponen dasar (4 file):

- resources/js/types/integrated-checkout.ts
- resources/js/components/integrated-checkout/checkout-profile.tsx
- resources/js/components/integrated-checkout/checkout-consents.tsx
- resources/js/components/integrated-checkout/checkout-payment.tsx

Increment komposisi dan tes (5 file):

- resources/js/components/integrated-checkout/checkout-form.tsx
- resources/js/components/integrated-checkout/integrated-checkout.tsx
- tests/Frontend/IntegratedCheckout/fixtures.ts
- tests/Frontend/IntegratedCheckout/checkout.test.tsx
- tests/Frontend/IntegratedCheckout/vite.config.ts

Increment preview dan laporan (5 file):

- tests/Frontend/IntegratedCheckout/index.html
- tests/Frontend/IntegratedCheckout/preview.tsx
- tests/Frontend/IntegratedCheckout/preview.css
- tests/Frontend/IntegratedCheckout/tsconfig.json
- tasks/organization-payment/reports/frontend.md

Tidak menyentuh route/controller/global CSS/package/lockfile/config/schema/shared
fixture/backend, checklist kanonik, maupun folder induk. Tidak push/deploy/flag ON,
invoice/WA nyata, atau mengerjakan slice berikutnya. Review kontrak dan privacy
boundary server, wiring P14/P15, integration tests, keyboard nyata dan full build
aplikasi tetap outstanding. Tunggu review koordinator sebelum melanjutkan.
