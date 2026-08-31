# P12a-prep — portal cabang baca-saja

## Increment verifikasi keyboard/responsif — delta dari 2e8ae42

Tanggal 2026-08-31. Gelombang kedua diintegrasikan induk sebagai 3a17d64.
Instruksi review parallel-work.md dan reports/integration-wave-2.md induk dibaca
read-only; tidak reset/merge baseline. **Increment ini hanya mengubah laporan
ini**, tanpa perubahan resource/page, otorisasi, harness, fitur pembayaran atau
gate. Tidak menjalankan PG, full regression, PHPUnit/Pint/PHPStan ulang karena
tidak ada edit kode aplikasi/test; angka suite sebelumnya tetap bukti historis.

### Lingkungan dan metode

- Skill Playwright dibaca beserta referensi CLI/workflows. npx tersedia; memakai
  CLI cached yang ditunjuk koordinator, bukan install dependency/browser:
  `C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js`.
- Sesi bernama `oncam-portal-keyboard`, `open --browser chrome`, default headless
  dan profil terpisah; **tidak attach** sesi/tab pengguna maupun sesi koordinator.
  Versi browser teramati 151.0.7922.171, tema terang default profil baru.
- Origin hanya `http://127.0.0.1:8012`; port diperiksa kosong sebelum bind.
  Tidak menggunakan port 8011. Init harness existing sukses pada direktori baru
  tanpa .env, snapshot capture/fromCharge valid, 14 bill cabang + satu bill asing.
  Tidak membuat invoice/notifikasi nyata atau menampilkan data klinis.
- Keyboard dikirim lewat CLI `press` / `page.keyboard.press/type`. Listener
  keydown hanya mengamati Tab/Enter/Space/Escape/arrow serta isTrusted, tidak
  dispatchEvent, focus(), click DOM, mengubah value, atau requestSubmit.
  Evaluate dipakai untuk membaca fokus, ukuran, nilai hasil, jumlah baris dan
  event; bukan untuk menjalankan interaksi aplikasi. Resize memakai viewport
  browser; mouse wheel native hanya mereset posisi scroll untuk screenshot.

### Hasil aktual

| Alur | Bukti |
| --- | --- |
| Login keyboard | Type email/password sintetis, Tab lewat toggle password ke Remember me, Space mencentang (assert isChecked), Space kembali kosong, Tab/Enter Sign in menuju dashboard. Screenshot fokus tombol tersimpan. |
| Menu → daftar | Tab mencapai Tagihan Cabang dengan :focus-visible=true; Enter membuka daftar 14 bill. |
| Filter desktop | Tab ke Filter, Space membuka; Tab melewati Reset ke select; Home + ArrowDown memilih Lunas. Enter membuka native select, sehingga Tab pertama menutup popup dan Tab berikutnya mencapai Apply. Space Apply menghasilkan dua row Lunas (id 9 dan 2). |
| Filter 320 px | Ulang tanpa Enter pada select: Home + lima ArrowDown → paid, Tab ke Apply, Space menghasilkan dua row. Tab/ArrowDown/Space yang diamati semuanya isTrusted=true; ring fokus Filter/Apply terlihat. |
| Daftar → detail | Tab melewati kolom row sampai Detail, Enter membuka bill 9; peserta sintetis 9, snapshot paket, IDR 100 dan konsultasi IDR 0 sesuai. |
| Detail → daftar | Tab/Enter pada breadcrumb Tagihan Cabang mengembalikan daftar tanpa filter (14 bill). Ini bukti breadcrumb, bukan history back. |
| Pagination 320 px | Shift+Tab mencapai Next; Space membuka page=2 dengan empat row. Shift+Tab mencapai Previous; Enter kembali ke halaman pertama. Fokus tombol terlihat. |
| Per page | Tab mencapai select Per page, ArrowDown dari 10 ke 25 lalu Tab; kedua select responsif mencerminkan 25, seluruh 14 row tampil. |
| Tabel horizontal | Tab ke Detail pada 320 px menggulir kontainer sampai tautan tampak utuh; rect tautan x=245.61–303.78 di dalam kontainer x=16–304. Fokus memakai underline dan :focus-visible=true, tidak terjebak di luar viewport. |

Listener membuktikan native keydown `isTrusted=true` pada Tab/Enter select,
Space Filter/Apply/Next dan ArrowDown. Bukti akhir filter mobile menyimpan nol
event untrusted. Enter navigasi dinilai dari input Playwright dan halaman tujuan,
bukan klaim bahwa log keydown bertahan melewati pergantian dokumen.

### Responsif dan batas verifikasi

| Viewport CSS px (tinggi 800) | Root/body list & detail | Kontainer/tabel list | Batas panel filter |
| --- | --- | --- | --- |
| 320 | 320 / 320, tanpa overflow halaman | 288 / 1050, scroll internal | x=0–320 |
| 390 | 390 / 390, tanpa overflow halaman | 358 / 1050, scroll internal | x=38–358 |
| 1280 | 1280 / 1280, tanpa overflow halaman | 896 / 1066, scroll internal | x=904–1224 |

Screenshot list/detail/filter diperiksa pada tiga lebar tersebut; referensi dan
attempt detail membungkus pada mobile, nominal/riwayat tetap terbaca. Tabel lebar
memerlukan scroll horizontal; tidak diklaim seluruh kolom terlihat bersamaan.
Screenshot awal resize/fokus menangkap animasi sebelum selesai; bukti akhir
diulang setelah transisi 400–450 ms. Tidak ada perbaikan aplikasi yang diperlukan.

**Zoom browser 200% belum terverifikasi.** Lima Control+Equal tidak mengubah
innerWidth=1280, DPR=1 maupun visualViewport.scale=1 pada Chrome headless ini;
Control+0 dikirim setelah probe. Tidak menggantinya dengan CSS zoom/pinch lalu
mengklaim zoom browser. Alt+ArrowLeft juga tidak mengubah halaman; navigasi
kembali yang terbukti memakai breadcrumb. Tidak mengklaim audit WCAG lengkap,
screen reader, seluruh state/role, mobile hardware, touch, dark theme ulang,
atau full end-to-end pembayaran.

Login sempat melewati waitForURL 30 detik dan snapshot/eval ikut timeout/context
destroyed selama dashboard memuat. Dashboard kemudian teramati selesai dan
pengujian dilanjutkan setelah snapshot baru. Perintah CLI --help mengeluarkan
Node UV_HANDLE_CLOSING saat exit; perintah browser berikutnya tetap berhasil.
Ini dicatat sebagai kendala alat/runtime, bukan bukti alur gagal tertutup atau
hasil tes aplikasi hijau. Console sesi akhir: **0 error, 0 warning**.

### Artefak dan cleanup

Artefak lokal tidak di-commit di
`C:/Users/ThinkPad/.codex/worktrees/6e61/Psikotes/output/playwright/`:
`keyboard-evidence.txt`, `list-return.txt`, `page2.txt`, script probe `.js`,
`list-{320,390,1280}.png`, `detail-{320,390,1280}.png`,
`filter-{320,390,1280}.png`, `apply-focus-320.png`,
`detail-link-focus-320.png`, `pagination-focus-320.png`, `back-focus.png`,
`menu-focus.png`, `login-focus.png`. Snapshot sementara CLI di `.playwright-cli/`
juga tidak commit. Jalankan probe lewat `node <CLI> -s=oncam-portal-keyboard
run-code --filename <script>`; script merupakan langkah berurutan yang bergantung
pada state, bukan suite replay mandiri atau tes CI.

CLI `close` mengonfirmasi sesi oncam-portal-keyboard ditutup; server dihentikan
dan lookup listener 8012 kosong. Tidak memakai close-all/kill-all. Folder SQLite
sintetis tetap lokal di
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-bills-1e94aa4e484942e8b4eb334159c819d2`
(pointer output/playwright/preview-directory.txt), tidak commit atau diklaim
sudah dihapus. Tidak menyentuh folder/proses induk, tidak push/deploy.

Siap review sebagai increment verifikasi saja. P12a tetap menunggu P11c dan
integrasi end-to-end; gate test-only tidak dibuka.

## Gelombang kedua — delta dari 55ffc29 (2026-08-31)

**Siap review sebagai P12a-prep, bukan aktivasi P12a.** Gelombang pertama sudah
diintegrasikan koordinator sebagai 9decef5. Instruksi gelombang kedua pada
parallel-work.md dan reports/integration-wave-1.md dibaca dari induk secara
read-only. Worktree tidak reset/merge/cherry-pick baseline induk. Bagian setelah
gelombang kedua ini mempertahankan bukti historis gelombang pertama.

Skill Laravel, Auth & Tenant Access, Security, TDD, Git Workflow, PostgreSQL dan
Browser dipakai kembali; acuan API ialah source Filament 5.7.6 terpasang serta
[otorisasi resource Filament 5](https://filamentphp.com/docs/5.x/resources/overview#authorization)
dan [RLS PostgreSQL 17](https://www.postgresql.org/docs/17/ddl-rowsecurity.html).

### Delta file milik lane

- `tests/Postgres/OrganizationBillPortalTest.php`: tujuh tes baru, menjalankan
  `getEloquentQuery()`, `resolveRecordRouteBinding()`, authorization resource,
  dan metode alokasi detail existing melalui reflection (tidak menyalin query).
- `app/Filament/Resources/OrganizationBills/OrganizationBillResource.php`:
  tautan navigasi detail eksplisit menggantikan ViewAction yang melakukan
  authorization DB berulang ketika dirender. Action hanya berisi URL dan guard
  mount persisted; tidak memiliki form, modal data, atau handler mutasi.
- `tests/Feature/Admin/OrganizationBillAccessTest.php`: pengukuran rendering
  Livewire pada 10/25/50 baris; regresi URL/action lama setelah membership/role
  berubah; snapshot fixture dibuat dan divalidasi melalui layanan existing.
- `tools/testing/serve-organization-bills-panel.php`: snapshot preview lengkap
  dari katalog sintetis melalui capture/fromCharge, bukan object packageName saja.
- `tasks/organization-payment/reports/branch-portal.md`: laporan delta ini.

Tidak mengubah shared runner/fixtures/schema/config/routes/policies, halaman
detail/list, AssessmentParticipants, dependency atau checklist kanonik. Gate
test-only masih sama. Tidak ada cache otorisasi lintas request atau pelemahan
pemeriksaan persisted pada query, URL detail, action mount, atau hydration.

### Bukti runtime dan batasnya

PG memverifikasi login `psikotes_runtime`, `rolsuper=false`, `rolbypassrls=false`,
bukan pemilik tabel, serta ENABLE/FORCE RLS pada lima tabel proyeksi. BranchAdmin
hanya memperoleh bill organisasi sendiri; self-payer, cabang lain, ID tidak ada,
dan record ID asing dengan organization_id dipalsukan ditolak. Admin stale setelah
role dicabut tetap ditolak meski context DB super_admin dapat membaca luas.
Guest, branch null, soft-deleted admin dan environment produksi juga ditolak.

Perubahan membership A→B diuji dengan auth object lama: context A tidak dapat
membaca A maupun B, context B hanya memperoleh B, detail A yang sudah terambil
ditolak saat proyeksi dipanggil ulang. PDO dan pg_backend_pid tetap sama. Context
staff/psychologist/participant menolak query walau auth object BranchAdmin masih
ada; exception context dan tanpa context juga diperiksa.

Batas koneksi: fixture berada dalam outer transaction, sehingga pergantian
context berjalan melalui savepoint runner pada satu backend. Context kosong
disetel eksplisit untuk menguji fail-closed; rollback outer transaction kemudian
dibuktikan menghapus GUC pada PDO yang sama. Ini bukan uji PgBouncer, beberapa
worker HTTP, atau race perubahan membership selama satu statement berjalan.

Snapshot charge valid dibuktikan `capture()` dan `fromCharge()` sebelum alokasi.
Perubahan katalog setelah snapshot tidak mengubah nama/nominal detail. Allowlist
field hasil dan sentinel membuktikan proyeksi tidak membawa metadata klinis,
invoice, proof, gateway, review privat, atau peserta cabang lain. SQL detail
memakai eager-load existing; tidak membuat invoice atau reservasi action.

PG bootstrap memakai PHPUnit biasa. Tes ini **bukan HTTP/Livewire PostgreSQL**:
middleware, render, hydration, request action dan status HTTP tetap dibuktikan
oleh tes HTTP/Livewire SQLite terpisah. Reflection hanya menjangkau proyeksi
privat existing; tidak memperluas API produksi demi tes.

### Query count terukur

| Pengukuran | 10 baris | 25 baris | 50 baris |
| --- | ---: | ---: | ---: |
| SQLite Livewire render sebelum optimasi | 97 | 232 | 457 |
| SQLite Livewire render sesudah optimasi | 7 | 7 | 7 |
| PG paginator resource (tanpa render) | 3 | 3 | 3 |

Dataset 50 bill organisasi sendiri, ditambah bill asing dan self untuk PG.
SQLite menghitung query pada update tableRecordsPerPage setelah initial mount,
termasuk hydration/render. PG menghitung reload admin + count + page select;
set_config/setup fixture tidak masuk pengukuran. Detail PG sepuluh alokasi
memakai tujuh query, termasuk persisted authorization dan eager-load; resolve
record awal di luar hitungan detail. Bukan klaim latency/load-test produksi.

RED budget rendering (maksimum 20 query dengan ruang untuk overhead framework)
gagal pada 97 query sebelum perubahan. GREEN menjadi konstan tujuh query;
query budget kini dijaga oleh tes. Tidak mengoptimasi dengan cached membership.

### Verifikasi gelombang kedua

- Focused SQLite akhir: **14 tes / 210 assertions lulus**, tanpa skip.
- Pint targeted resource/pages, dua test lane dan harness: lulus.
- PHPStan targeted seluruh resource/pages: **0 error**, lingkungan proses
  testing + SQLite memory + DB_URL kosong/cache-session array seperti perintah
  gelombang pertama. Tests tidak termasuk cakupan PHPStan aplikasi existing.
- PG run awal: **151 tes / 871 assertions lulus**, termasuk tujuh tes portal,
  bukan hanya baseline 144. Run final: **151 tes / 872 assertions lulus**, tanpa
  skip, setelah penguatan assertion record ID palsu (forceFill memastikan id
  tidak dibuang mass-assignment). Delta di atas baseline worktree 144/690 ialah
  **7 tes / 182 assertions portal**; hasil induk 149/739 tidak dipakai sebagai
  denominator karena worktree tidak memuat integrasi backend gelombang pertama.
- Browser 127.0.0.1:8012 dengan DB baru: login sintetis, daftar 14 tagihan,
  klik Detail pada row id 14, lalu DOM detail menunjukkan referensi/attempt,
  peserta 14, snapshot paket, IDR 100 dan konsultasi IDR 0 yang sesuai.
  Tautan tetap native link ke halaman detail. Bukan bukti keyboard penuh,
  screen reader, semua breakpoint, atau browser negatif lintas cabang.
- Preview init berhasil dengan snapshot valid. Tab uji ditutup (tab list kosong),
  server 8012 dihentikan. Tidak membuka tab/data pengguna.

Perintah utama: focused PHPUnit dan PHPStan seperti gelombang pertama; Pint
menambah `tests/Postgres/OrganizationBillPortalTest.php`; PostgreSQL tetap
`powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1`.
Tidak menjalankan full regression gabungan induk atau mengklaim angka 668/3080
sebagai hasil worktree ini. Tidak mengedit runner untuk memilih/filter suite.

Run PG pertama `c55b03fde2f340308ace4f65c7d11d17` dibersihkan runner dan lookup
container/network berlabel persis kosong. Run final
`2777427fdf674b7596483a44c8330289` juga dibersihkan runner. Preview browser baru disimpan lokal di
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-bills-d2ac4c63c4ec4516a265d655e2168e98`
dengan pointer `storage/bill-preview-wave2-directory.txt`; keduanya tidak commit.
Folder sintetis dipertahankan, bukan DB aktif; tidak mencoba jalan penghapusan
alternatif setelah blok tool gelombang pertama. Artefak lama tetap tidak commit.

Delta ini berhenti untuk review. Gate produksi, P11c, HTTP/Livewire PG dan
verifikasi end-to-end pembayaran tetap pekerjaan integrasi berikutnya.

## Catatan gelombang pertama (historis)

Status: **slice persiapan siap review; P12a belum selesai dan tetap menunggu
P11c. Tidak lanjut ke slice berikutnya sebelum review koordinator.**

## Preflight

- Worktree: `C:/Users/ThinkPad/.codex/worktrees/6e61/Psikotes`; HEAD awal
  `58da1de`, dengan snapshot baseline modified/untracked. Koordinator menyatakan
  checkpoint ekuivalen kini `3112e1524292cebe4fbde19e1c24eaa3befc8d0e`.
- CLAUDE.md dan parallel-work.md dibaca; empat file baseline wajib tersedia.
  Tidak reset/switch/merge baseline, tidak commit ulang snapshot awal.
- Skill Laravel Specialist (termasuk local implementation, Eloquent, testing,
  Livewire), Auth & Tenant Access, Security & Hardening, Frontend UI Engineering,
  Browser, TDD, Git Workflow, dan Postgres tersedia dan dibaca.
- Composer lock cocok SHA256 dengan induk. Vendor disalin independen memakai
  robocopy `/E /XJ`; tidak menyalin .env, runtime cache, DB aktif, atau node_modules.
  Direktori storage/cache lokal kosong dibuat agar Laravel dapat boot.
- Filament terpasang 5.7.6. Shared routes/config/policies/schema tidak diubah.
- Tool `send_message_to_thread` sempat tercantum tetapi pemanggilan gagal
  `not a function`; discovery berikutnya tidak menawarkannya. Sesuai arahan
  koordinator, laporan lane ini menjadi kanal status.

## Keputusan scope

Gate checkout existing bukan kontrak kesiapan portal. Resource tetap test-only:
discovery, navigasi, authorization dan query menolak di luar environment testing.
Tidak menambah config toggle atau mengaktifkan flag existing. Integrasi gate
produksi dan P12a tetap menunggu P11c/review koordinator.

Matriks actor: hanya BranchAdmin persisted, tidak terhapus, dengan branch_id
yang sama boleh membaca bill `payer_type=organization`. Guest, participant,
Staff, Psychologist, SuperAdmin, admin tanpa cabang dan lintas cabang ditolak.
Flag legacy can_verify_payments tidak memberi kemampuan tulis pada resource ini. Pembayar mandiri
tidak dimasukkan ke menu tanggungan cabang ini.

Ancaman utama: IDOR list/detail/child, state Livewire setelah role/tenant dicabut,
metadata klinis dan referensi/proof privat ikut terhidrasi, UI memberi kemampuan
tulis atau menyiratkan terminal bill boleh ditagih ulang. Tes negatif ditulis
sebelum implementasi. Jumlah diberi label attempt sesuai item_count server;
dua attempt orang yang sama tidak diklaim sebagai dua peserta unik.

## Bukti verifikasi

- RED lingkungan: direktori compiled views belum ada; dibuat kosong lokal.
- RED perilaku: 10 tes error karena resource/page belum diimplementasikan.
- GREEN awal: 9/10 lulus, satu kesalahan nama tabel outbox di assertion diperbaiki
  menjadi outbox_messages; tidak mengubah schema. Ditambah kasus dua attempt
  orang yang sama dan rejected.
- Focused PHPUnit terakhir: **12 tes / 104 assertions lulus**, tanpa skip, XML
  phpunit.organization-payment.xml dan path tests/Feature/Admin/OrganizationBillAccessTest.php.
- Pint kelima file PHP lulus; PHPStan targeted resource/pages **0 error**.
- Runner tools/testing/run-org-postgres.ps1 selesai: **144 tes / 690 assertions
  lulus**, tanpa skip. Runtime non-owner memakai FORCE RLS. Container/network
  disposable dibersihkan runner; tidak menargetkan container aplikasi.
- Batas PG: ini regression RLS/schema baseline existing, bukan tes HTTP/Livewire
  resource baru pada PostgreSQL. Tidak menambah file tests/Postgres atau mengubah
  runner/XML shared karena di luar ownership; integrasi ini perlu koordinator.
- Browser lokal 127.0.0.1:8012: login sintetis berhasil, daftar 14 tagihan sendiri
  tampil; filter Lunas menampilkan tepat dua bill paid dari sumber yang sama.
  Next menampilkan 11–14 dari 14 hasil. Detail paid menampilkan peserta/attempt,
  snapshot paket, nominal, dan settled_at; detail rejected memberi arahan
  petugas tanpa tombol reinvoice/verifikasi/upload.
- Desktop list diperiksa pada 1440 px; tabel lebar menggunakan scroll horizontal
  bawaan Filament. Detail diperiksa pada 320, 768, dan 1024 px. Judul bawaan
  dengan ULID terpotong di mobile: diganti judul Indonesia pendek, referensi
  lengkap tetap di ringkasan. Setelah reload, 320 px tidak overflow halaman.
- Console sebelum navigasi negatif tidak memiliki error/warning teramati.
  Percobaan keyboard Enter hanya memfokuskan link; navigasi detail akhirnya
  diverifikasi dengan klik. **Bukan bukti keyboard penuh, screen reader, WCAG,
  tema terang, browser lain, atau semua breakpoint untuk tabel.**
- Percobaan URL asing id 15 di browser tidak menghasilkan bukti yang dapat
  dibaca karena error/policy halaman internal browser. Bukti 404 lintas cabang,
  bill self, dan ID tidak ada berasal dari focused HTTP tests, bukan browser.
- Awal server lambat menyebabkan connection-refused/navigation timeout dan
  reset koneksi browser. Server kemudian dijalankan dengan opcache.enable_cli=0;
  ini opsi proses, bukan perubahan php.ini. Screenshot full-page sempat memiliki
  artefak stitching (DOM memastikan hanya satu alokasi), sehingga bukti akhir
  diganti screenshot viewport setelah resize+reload.
- Tidak menjalankan full regression PHP/JS sekaligus. Tidak ada perubahan
  frontend asset source; tidak menjalankan npm build/lint/typecheck. Asset
  Filament vendor dipublikasikan lokal oleh harness terisolasi untuk preview.

Perintah verifikasi akhir:

```powershell
php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/OrganizationBillAccessTest.php
php -d opcache.enable_cli=0 vendor/bin/pint --test app/Filament/Resources/OrganizationBills tests/Feature/Admin/OrganizationBillAccessTest.php tools/testing/serve-organization-bills-panel.php
# PHPStan dijalankan dengan APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:,
# DB_URL kosong, CACHE_STORE/SESSION_DRIVER=array dan QUEUE_CONNECTION=sync.
php -d opcache.enable_cli=0 vendor/bin/phpstan analyse --no-progress app/Filament/Resources/OrganizationBills
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
```

## File lane dan commit lokal

Commit inti **547e74a** (hanya empat file, 476 baris additive):

- app/Filament/Resources/OrganizationBills/OrganizationBillResource.php
- app/Filament/Resources/OrganizationBills/Pages/ListOrganizationBills.php
- app/Filament/Resources/OrganizationBills/Pages/ViewOrganizationBill.php
- tests/Feature/Admin/OrganizationBillAccessTest.php

Increment pendukung terpisah: tools/testing/serve-organization-bills-panel.php
dan laporan ini. Index diperiksa sebelum commit; tidak git add -A atau commit
baseline. Commit dibuat pada detached HEAD worktree, tidak reset/merge/switch,
tidak push. Koordinator dapat meninjau dua commit lane terhadap 3112e15.

Harness menerima direktori temp baru bernama oncam-bills-{32 hex}; mode `init`
mengisi DB sintetis baru, `assets` menyiapkan asset Filament, lalu serve hanya
127.0.0.1:8012. Init menolak DB yang sudah ada. .env workspace tidak dibaca,
cache/storage/session/DB terpisah; koneksi DB lain dihapus, provider/notifier
fake, Mail fake, Laravel HTTP menolak request tak terduga. Tidak ada invoice,
reservasi action, writer, proof-upload, finalizer atau notifikasi nyata.

Contoh reproduksi dari worktree ini (periksa port 8012 kosong sebelum serve):

```powershell
$env:ONCAM_BILL_PREVIEW_DIRECTORY = Join-Path ([System.IO.Path]::GetTempPath()) ('oncam-bills-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory $env:ONCAM_BILL_PREVIEW_DIRECTORY
php -d opcache.enable_cli=0 tools/testing/serve-organization-bills-panel.php init
php -d opcache.enable_cli=0 tools/testing/serve-organization-bills-panel.php assets
php -d opcache.enable_cli=0 -S 127.0.0.1:8012 -t public tools/testing/serve-organization-bills-panel.php
```

Login sintetis ditampilkan oleh init. Hentikan proses serve setelah review.
Jangan gunakan harness ini sebagai endpoint publik atau pada data aktif.

## Batas integrasi dan cleanup

- Gate test-only harus diganti kontrak produksi yang ditinjau setelah P11c;
  jangan sekadar mengaktifkan APP_ENV=testing pada deployment.
- Diperlukan tes resource list/detail/eager-load dengan runtime PostgreSQL,
  termasuk pooled context dan performa query pada batch besar; regresi RLS
  baseline tidak menggantikannya. Detail memakai eager loading, tetapi daftar
  masih memeriksa persisted membership/record pada authorization tiap baris.
- Belum ada create invoice, pembayaran, proof, verifier atau integrasi P12b/c.
  P12a tidak dicentang selesai. Shared policies/routes/config/schema,
  AssessmentParticipants resource dan checklist kanonik tidak diedit.
- Sidebar Transfer Manual legacy masih mengikuti aturan existing; lane ini
  tidak mengubahnya. Flag legacy tidak mengizinkan mutasi bill baru.
- Server 8012 dihentikan; viewport dikembalikan. Tab uji utama ditutup; tab
  error sementara browser tidak dapat ditutup lewat API karena URL policy.
- Runner PostgreSQL membersihkan container/network; lookup label run
  2821c1beb7b34a3b8f33d6172a43afe6 sesudahnya kosong.
- Penghapusan folder browser sintetis ditolak kebijakan tool meskipun path
  sudah diperiksa. Folder **masih ada** dan bukan DB aktif:
  C:/Users/ThinkPad/AppData/Local/Temp/oncam-bills-81d2dbe9a3254a258ab324dfc0823cee.
  Pointer lokal storage/bill-preview-directory.txt juga belum terhapus;
  keduanya tidak di-commit. Tidak mencoba melewati blok penghapusan.
- Screenshot sintetis tersimpan lokal (tidak di-commit) di
  C:/Users/ThinkPad/.codex/worktrees/6e61/Psikotes/storage/app/private/verification/branch-portal/
  sebagai detail-320.png, detail-768.png dan detail-1024.png.

## Sumber implementasi

API disesuaikan dengan source terpasang Laravel 13.26.1, Filament 5.7.6,
Livewire 4.4.2. Resource memakai getAuthorizationResponse untuk menolak semua
aksi selain viewAny/view, serta query scope dan hydration check. Acuan:
[Filament resource authorization](https://filamentphp.com/docs/5.x/resources/overview#authorization),
[view records](https://filamentphp.com/docs/5.x/resources/viewing-records),
[testing tables](https://filamentphp.com/docs/5.x/testing/testing-tables),
[Laravel authorization](https://laravel.com/framework/docs/13.x/authorization),
dan [OWASP authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).
