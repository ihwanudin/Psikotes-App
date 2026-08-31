# Frontend — P16-prep

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
