# Pengujian pembayaran lembaga — P1 sampai P3

Tanggal: 2026-08-31. Lingkup: harness lokal, bukan aktivasi fitur publik.

## Menjalankan pemeriksaan pengaman

Dari root repository dengan PHP dan vendor development tersedia:

```powershell
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml
php vendor/bin/pint --test tests/OrganizationPaymentTestCase.php tests/Feature/Database/OrganizationPaymentTestEnvironmentTest.php
```

Panggilan PHPUnit langsung sengaja dipakai agar aplikasi tidak boot melalui
Artisan sebelum konfigurasi test diterapkan. XML memaksa environment testing,
SQLite :memory:, DB_URL kosong, serta credential Xendit kosong pada proses test.
Tidak mengubah .env atau konfigurasi Docker. APP_KEY dalam XML adalah data sintetis.
Suite kosong atau tes skipped membuat runner khusus ini gagal.

Tes fitur baru wajib extends `Tests\OrganizationPaymentTestCase` dan didaftarkan
di XML saat ditambahkan. Base class memeriksa konfigurasi setelah LoadConfiguration,
sebelum provider boot dan sebelum RefreshDatabase dapat melakukan migrasi.
Jika ada config cache lokal yang tidak aman, tes berhenti; jangan menghapus atau
mengubah cache layanan publik untuk mengatasinya. Gunakan checkout test bersih.

## Jaminan dan batas

- Pada runner SQLite, hanya koneksi memori tersedia. Permintaan koneksi pgsql
  eksplisit gagal, tidak diam-diam dijalankan pada SQLite. PostgreSQL bernama
  *_test pun ditolak; gunakan runner PostgreSQL terpisah di bagian bawah.
- PaymentProvider dan Notifier terikat ke fake setiap aplikasi dibuat ulang.
- Laravel HTTP menolak request tanpa fixture. Tes adapter boleh memakai
  Http::fake untuk respons sintetis, bukan allowStrayRequests untuk layanan nyata.
- Mail dan storage local/s3 memakai fake; session/cache memakai array, queue sync,
  dan konfigurasi Redis dihapus dari aplikasi test.
- Ini pembatas pada aplikasi test Laravel, bukan firewall OS. Raw cURL, socket,
  SDK yang melewati Laravel HTTP, atau test yang sengaja mengganti konfigurasi
  dapat melewati pembatas; jangan menambahnya pada suite ini.
- Tes legacy yang extends Tests\TestCase tidak memperoleh pengaman base class
  baru. Jangan menyatakan seluruh suite legacy dilindungi oleh harness ini.
- Tidak ada bukti RLS atau concurrency PostgreSQL dari hasil SQLite ini. P2/P6
  tetap memerlukan database PostgreSQL disposable, role migration/runtime terpisah,
  serta verifikasi target sebelum migrasi. Tidak menjalankan reset DB aktif.

## Baseline dan perintah pemeriksaan

Regresi tanpa transaksi eksternal:

```powershell
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox
php vendor/bin/phpstan analyse --no-progress
npm run types:check
npm run lint:check
npm run build
```

Direktori eksplisit memilih suite lokal tanpa memasukkan tes PostgreSQL yang
memiliki runner terpisah. Grup sandbox sengaja tidak dijalankan
karena membuat invoice eksternal membutuhkan izin terpisah; ini bukan laporan
gerbang F1 lulus. Skip/failure lain harus tetap dicatat, tidak disembunyikan.

## Bukti eksekusi

- RED: tes baru gagal karena OrganizationPaymentTestCase belum tersedia.
- GREEN: runner khusus lulus 10 tes, 22 assertions, tanpa skip.
- Pint kedua file PHP baru: lulus.
- PHPStan aplikasi: lulus, 0 error. Konfigurasi PHPStan existing tidak mencakup tests/.
- Regresi + harness: 352 tes terpilih, 350 lulus, 2 skipped, 1.752 assertions.
  Exit code 1 karena failOnSkipped tetap aktif. Satu tes grup sandbox tidak
  disertakan; tidak membuat invoice Xendit eksternal.
- Kedua skip dikonfirmasi dengan menjalankan RegistrationTest tersendiri:
  dua tes/0 assertions, exit code 1. Features::registration() tidak aktif pada
  config/fortify.php. Tes ini milik registrasi user bawaan Fortify, bukan form
  peserta custom. Konfigurasi dan tes legacy tidak diubah untuk menghijaukan gate.
- TypeScript, ESLint, dan Vite build: lulus. Build masih memberi peringatan
  chunk >500 kB dan fontaine opsional belum terpasang; tidak mengubah dependency
  atau menaikkan batas peringatan pada tugas harness ini.

Pada increment awal, P1 belum dicentang selesai karena regresi masih memiliki
skip. Penyelarasan legacy dan runner PostgreSQL dikerjakan sebagai increment
terpisah; lihat pembaruan berikut untuk status terbaru.

Tidak ada migrasi database aktif, invoice eksternal, pengiriman WhatsApp,
perubahan harga, atau deployment pada langkah ini.

## Pembaruan setelah penyelarasan registrasi (2026-08-31)

Pengguna meminta melanjutkan penyelarasan tes legacy. Setelah skip dilepas,
dua tes lama benar-benar gagal: fixture cabang default belum dibuat dan route
register.store milik Fortify tidak tersedia. Aplikasi tidak diubah.

RegistrationTest sekarang memeriksa tiga perilaku yang berlaku:

- GET /register adalah halaman peserta dengan cabang dan persetujuan, bukan
  form pembuatan akun umum Fortify.
- POST /register ditolak dan tidak membuat user/admin/peserta.
- Payload signup umum ke POST /registrations tidak melewati syarat peserta.

Hasil: focused RegistrationTest + ParticipantRegistrationTest 10 tes lulus,
93 assertions. Regresi lokal 353 tes lulus, 1.776 assertions, tanpa skip;
grup sandbox tetap tidak dijalankan. Pint file yang diubah lulus.
Catatan 350 lulus/2 skip di atas adalah bukti historis sebelum perbaikan tes.
Pengujian PostgreSQL dijalankan terpisah seperti di bawah, bukan pada DB aktif.

## Runner PostgreSQL disposable

Prasyarat: Docker berjalan; image postgres:17.6-alpine dan psikotes-app:dev sudah
tersedia lokal; vendor development terpasang. Tidak perlu memasang driver pgsql
pada PHP Windows dan tidak perlu membuka port PostgreSQL aplikasi.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
```

Runner membuat network internal ber-ID acak, satu container PostgreSQL dengan
data tmpfs, dan satu container PHP untuk pengujian. Tidak ada port dipublikasikan,
tidak memakai volume database aplikasi, tidak memanggil Docker Compose, tidak
pull/build image, dan tidak menjalankan entrypoint aplikasi/supervisor.
Source/vendor di-mount read-only; storage dan bootstrap/cache memakai tmpfs.
Environment Laravel tidak membaca .env workspace.

PostgreSQL memakai trust authentication **hanya pada network disposable ini**.
Ini bukan konfigurasi produksi. Runner tidak memakai credential aplikasi.
Sebelum migrate, bootstrap memeriksa Docker, ID run, environment testing,
database tepat psikotes_organization_test, owner org_test_owner, marker unik
ONCAM_ORG_TEST:<run-id>, serta belum adanya tabel migrations. Pemanggilan langsung
di Windows ditolak sebelum memuat aplikasi atau menghubungi database.

Migrasi existing dari working tree berjalan sebagai owner hanya di database baru
tersebut. Setelah migrasi, koneksi owner diputus dan pengujian menggunakan login
psikotes_runtime yang tidak memiliki SUPERUSER/BYPASSRLS. Ini tidak membuktikan
bahwa image publik sudah menjalankan semua migrasi working tree.

Cleanup menargetkan nama container persis dan label run unik, lalu memeriksa
label network sebelum menghapusnya. Tidak menggunakan prune atau prefix umum.
Data sintetis/caches tmpfs dibuang sesudah run dan tidak dapat dipulihkan; fixture
dibuat kembali pada run berikutnya. Bila proses dimatikan paksa, periksa nama
network/ID run yang tercetak dan labelnya sebelum cleanup manual, jangan prune.

## Hasil PostgreSQL dan status P1

- RED awal: tes PostgreSQL menolak berjalan tanpa bootstrap khusus.
- Pengaman pemanggilan langsung di Windows: ditolak sebelum koneksi/migrasi.
- Bootstrap diperbaiki setelah direktori compiled view tmpfs belum tersedia;
  cleanup Windows diperbaiki setelah native quoting gagal. Resource percobaan
  gagal sudah dibersihkan berdasarkan ID dan label yang diverifikasi.
- Smoke awal: 2 tes/21 assertions lulus; runner membersihkan resource miliknya.
- Suite final: **8 tes/52 assertions lulus, tanpa skip**, PostgreSQL 17.6,
  PHP 8.3.26, PHPUnit 12.5.33. Network internal=true, port bindings kosong.
- Bukti mencakup runtime bukan owner/superuser/BYPASSRLS, FORCE RLS pada tabel
  yang diperiksa, penolakan akses tanpa context, isolasi baca/update lintas cabang,
  order/DASS milik peserta sendiri, DASS tersembunyi dari admin, context bersih
  setelah exception, dan Laravel HTTP tanpa fixture ditolak.
- Pint seluruh file PHP yang diubah/ditambah: lulus. Build/typecheck/frontend
  dari increment sebelumnya tetap relevan karena tidak ada perubahan aplikasi,
  frontend, dependency, atau konfigurasi build pada increment test-only ini.

**P1 selesai.** Ini gerbang harness, bukan audit seluruh RLS aplikasi, bukti
concurrency pembayaran, atau persetujuan go-live. Status P2 dicatat terpisah berikut.

## P2: konfigurasi pembayar (2026-08-31)

Migrasi `2026_08_31_000100_add_payer_policy.php` menambah
`allowed_payer_types` pada branches/integration_sources serta
`locked_payer_type` pada integration_sources. Nilai pembayar hanya `self` dan
`organization`; bukan kanal pembayaran atau status lunas.

- Kolom ditambahkan nullable tanpa default terlebih dahulu, sehingga baris
  lama tetap NULL dan tidak otomatis memperoleh izin checkout baru.
- Default database kemudian diterapkan untuk insert baru: branches `["self"]`,
  integration_sources `[]`. NULL berarti belum dipetakan; daftar kosong berarti
  tidak ada pilihan. Penegakan keputusan checkout merupakan P3, bukan P2.
- PostgreSQL menolak nilai di luar daftar, JSON bukan array, serta lock yang
  tidak tercantum pada allow-list sumber. Semua update tetap mengikuti RLS
  existing; tidak menambah role/policy yang memberi hak baru.
- Model hanya menambah fillable/cast/property terkait. Pengaturan legacy
  allowed_funding_modes, status sumber, dan versi kontrak tidak diubah.
- Tidak membuat order, entitlement, tagihan, atau endpoint/UI baru.

Pengujian dibagi menjadi increment schema/model (5 file), PostgreSQL (1 file),
dan pembaruan dokumentasi. Perintah tetap menggunakan runner aman di atas.

Bukti lokal: RED awal 3 tes gagal sebelum migrasi/cast tersedia; GREEN suite
khusus **13 tes/44 assertions**, regresi **356 tes/1.798 assertions**, tanpa skip.
Regresi tidak menyertakan sandbox eksternal. Pemeriksaan format kelima file PHP
P2 lulus. PHPStan aplikasi lulus dengan 0 error (konfigurasi existing tidak
mencakup tests/). PostgreSQL final lulus **27 tes/105 assertions**, tanpa skip,
termasuk assertion FORCE RLS branches/integration_sources. Container, tmpfs,
dan network pengujian dibersihkan oleh runner. `git diff --check` lulus.
Tinjauan lokal terhadap scope, null/default, constraint, RLS, dan perubahan model
selesai; tidak ada dependency/frontend yang berubah sehingga build frontend
tidak dijalankan ulang pada P2. Ini bukan persetujuan merge/deploy.

Catatan migrasi SQLite: perubahan default memerlukan rebuild tabel, sehingga
tes lifecycle migrasi memakai koneksi memori baru per tes tanpa transaksi
pembungkus RefreshDatabase. Up/down P2 diuji dengan fixture registry existing
dan memastikan baris serta konfigurasi legacy tetap utuh. Percobaan rollback
seluruh migrasi via DatabaseMigrations mengungkap masalah existing pada rollback
`2026_08_29_000200`: unique index organization_code belum dilepas sebelum drop
column di SQLite. Migrasi lama tidak diubah; rollback seluruh registry bukan
bagian P2. Bukti up/down berisi data saat ini berasal dari SQLite; PostgreSQL
menguji migrasi fresh, constraint, dan RLS sebagai runtime non-owner.

Perubahan masih lokal: tidak menjalankan migrasi database aktif, rebuild image
publik, mengirim WhatsApp, atau membuat invoice. Branch/IntegrationSource sudah
memiliki perubahan integrasi sebelumnya (sebagian untracked); perubahan P2
dibiarkan di working tree agar tidak memasukkan pekerjaan lama ke commit baru
tanpa peninjauan cakupan. P3 adalah resolver keputusan server, disusul P4 UI admin.

## P3: keputusan kebijakan server (2026-08-31)

Kontrak internal: `docs/PAYER_POLICY.md`. Implementasi berada di PayerType,
PayerDecision, dan ResolvePayerPolicy. Resolver menerima model registry dari
server; tidak mengautentikasi, memuat ulang DB, mengubah order, atau membuka tes.
Pemanggil tahap reservasi wajib memakai data baru dalam transaksi/RLS serta
memenuhi gerbang checkout opt-in. Tidak ada endpoint, route, UI, atau wiring
provisioning legacy yang diubah pada P3.

Increment pertama mencakup enum/DTO/resolver dan tes unit; increment kedua
menambah tes integrasi SQLite, memperluas tes PostgreSQL, serta registrasi XML.
Dokumentasi dikerjakan setelah verifikasi.

Bukti:

- RED awal: 14 tes gagal karena resolver belum ada; GREEN 14 tes/79 assertions.
- RED perluasan penolakan: 59 tes, 37 failure dan 2 error; setelah validasi
  pemetaan/status/periode/paket/config ditambah, **59 tes/351 assertions lulus**.
- Harness P1–P3: **75 tes/414 assertions lulus**, tanpa skip. Termasuk 3 tes
  integrasi P3/19 assertions: hydration, query log kosong ketika resolver
  berjalan, tidak ada mutasi model/order/entitlement/outbox, serta OFF tidak
  mengubah order paid/pending atau entitlement locked yang sudah tersimpan.
- Regresi lokal: **418 tes/2.168 assertions lulus**, tanpa skip; grup sandbox
  eksternal tetap tidak dijalankan. Tidak ada invoice atau WA nyata.
- PostgreSQL disposable: **28 tes/114 assertions lulus**, tanpa skip. Tambahan
  P3 membaca konfigurasi lewat role runtime/RLS, menghasilkan pembayar lembaga
  yang sesuai client, menolak penggantian lock dan organisasi berbeda, serta
  menolak keputusan baru setelah konfigurasi berubah. Tidak ada query di dalam
  resolver atau penambahan order/entitlement. Container/network/tmpfs dibersihkan.
- Pint enam file terkait dan `php vendor/bin/pint --parallel --test` lulus;
  PHPStan aplikasi lulus (0 error).
- `npm run types:check` dan `npm run lint:check` lulus.
- Build lulus dengan APP_ENV testing, koneksi SQLite memori, cache/session array,
  queue sync, dan `npm run build -- --outDir storage/app/private/verification/p3-build-20260831`.
  Output terpisah tidak mengganti public/build. Peringatan existing fontaine
  opsional dan chunk >500 kB tetap dicatat, tidak dihilangkan dengan menaikkan
  batas atau menambah dependency. Build juga melaporkan waktu plugin hooks.

Slice yang terbukti adalah registry DB → resolver → keputusan. Ini **bukan**
checkout browser end-to-end, verifikasi concurrency order, atau fitur pembayaran
lembaga lengkap. P3 tidak memvalidasi harga/consent dan tidak menyimpan snapshot
order; hal tersebut menjadi tanggung jawab tahap berikutnya. Tinjauan pengguna
pada checkpoint setelah P3 masih diperlukan sebelum P4. Tidak ada deploy,
migrasi DB aktif, perubahan harga, atau commit massal working tree existing.

## P4a: otorisasi dan audit perubahan policy

Rencana teknis kolektif disetujui pengguna melalui jawaban "lanjhutkan".
Implementasi P4a menambah FundingPolicyPolicy dan UpdateFundingPolicy sebagai
action internal organisasi/sumber, belum terhubung ke panel admin atau route.
Kontrak pemanggil, format input, urutan lock, dan audit dijelaskan dalam
`PAYER_POLICY.md`. Kontrol panel menjadi P4b, tidak dihitung selesai dari tes ini.

Bukti eksekusi:

- RED: 42 tes gagal karena UpdateFundingPolicy belum ada.
- GREEN pertama: 42 tes/162 assertions lulus. Setelah penambahan regresi OFF
  dan penyelarasan fixture, focused test **43 tes/166 assertions lulus**.
- Regresi lokal: **461 tes/2.334 assertions lulus**, tanpa skip; perintah
  `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox`.
- PostgreSQL pertama: 3 error pada fixture yang belum mengisi organization_code
  dan display_name wajib. Fixture diperbaiki; constraint database tidak diubah.
- PostgreSQL ulang: **31 tes/128 assertions lulus**, termasuk 3 tes baru tentang
  penulisan/audit runtime RLS, penolakan admin yang haknya dicabut, dan rollback
  kegagalan audit dalam transaksi luar yang menangkap error. Runner menggunakan
  database disposable, runtime non-owner/non-BYPASSRLS; semua container/network
  test sudah dibersihkan, container aplikasi tidak ditargetkan.
- `php vendor/bin/pint --parallel --test` lulus; PHPStan seluruh aplikasi lulus
  dengan 0 error. `git diff --check` lulus.

Tes mencakup role cabang/staff/psikolog, admin terhapus atau belum tersimpan,
role sesi kedaluwarsa, input palsu/duplikat/legacy, pasangan sumber-organisasi
berbeda, lock di luar allow-list, kosong/OFF, normalisasi/no-op, audit minimal,
dan rollback sekaligus pemulihan context. OFF mempertahankan isi order
paid/pending, entitlement locked, dan kanal aktif; tidak membuat outbox baru.

Review lokal memeriksa batas otorisasi, validasi ketat, transaksi/savepoint
mandiri, query terparameterisasi, dan scope perubahan empat file PHP. Tidak ada
dependency, migration, route, UI, atau frontend yang diubah pada P4a; build
frontend/browser tidak dijalankan ulang karena tidak terdampak. Verifikasi
browser wajib ketika kontrol panel P4b ditambahkan. Concurrency dua proses
dengan reservasi belum diuji; itu bagian P7/P17 saat reservasi tersedia.

Perubahan tetap working tree karena bergantung pada model/registry P2–P3 yang
sebagian belum tracked. Tidak melakukan commit massal atau push perubahan
existing. Tidak ada migrasi DB aktif, deploy, invoice, WA, atau transaksi nyata.

## P4b: kontrol pembayar pada panel ONCAM (2026-08-31)

Action bersama `FundingPolicyAction` dipasang pada tabel Klien Integrasi,
tabel Sumber Integrasi, dan header edit sumber. Form menggunakan action P4a,
bukan penyimpanan field bebas. Increment inti lima file; harness browser
`tools/testing/serve-funding-panel.php` dan dokumentasi dikerjakan terpisah.

Bukti eksekusi:

- RED awal: enam kegagalan/error dari sepuluh kasus karena action belum ada.
  Perluasan kasus array bersarang gagal sebelum validasi input mentah ditambah:
  cast opsi Filament dapat membuang elemen invalid menjadi daftar kosong.
- GREEN akhir: **12 tes panel/98 assertions lulus** melalui
  `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/FundingPolicyPanelTest.php`.
- Regresi akhir: **473 tes/2.432 assertions lulus**, tanpa skip, melalui
  `php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox`.
- `php vendor/bin/pint --parallel --test` lulus; PHPStan seluruh aplikasi
  **0 error**; `git diff --check` lulus.
- Tes meliputi simpan organisasi, lock/OFF sumber, header edit, NULL/batal,
  error lock/unknown/duplikat/nested, audit, dan penolakan role cabang/staff/
  psikolog. Injeksi field policy ke save registry umum tidak mengubah policy.

Browser menggunakan login SuperAdmin sintetis melalui halaman login biasa,
server `127.0.0.1:8766`, SQLite dan storage/cache/session khusus direktori
temporary. Harness tidak membaca `.env` workspace, tidak memakai database aktif,
memasang provider/notifier fake, dan menolak outbound HTTP tak terduga. Setup
pertama menemukan path cache absolut Windows yang diberi prefix workspace;
`addAbsoluteCachePathPrefix` memperbaikinya, lalu fixture baru berhasil dibuat.
Timeout awal browser ketika request PHP pertama lambat tidak diperlakukan
sebagai keberhasilan; DOM dan interaksi diperiksa kembali setelah siap.

Verifikasi browser yang diamati:

- Tombol **Atur pembayar** terlihat pada kedua tabel; modal lembaga menjelaskan
  efek ke seluruh client/sumber. Simpan memberi notifikasi berhasil.
- Sumber NULL memperlihatkan **Belum dikonfigurasi**, checkbox kosong, dan
  pilihan **Tidak dikunci**. Penyimpanan organization + lock terbukti tersimpan
  saat modal dibuka ulang; batal setelah edit invalid mempertahankannya.
- Mematikan pembayar yang masih terkunci menampilkan error inline dan tidak
  menutup dialog. Pesan bawaan Inggris diganti instruksi Indonesia; tes exact
  error sempat RED lalu GREEN. Setelah kunci dihapus, semua OFF dapat disimpan.
- Screenshot modal diperiksa pada 320×740, 768×900, 1024×768, dan 1440×900
  (tema gelap panel existing): teks/field/tombol tetap terbaca dan berada dalam
  viewport. Tidak mengubah CSS/tema global atau aset logo pada increment ini.
- Console browser tidak memperlihatkan error/warning pada pemeriksaan.

Batas: pengujian keyboard melalui runtime tidak menunjukkan perubahan checkbox
yang dapat dipastikan, sehingga bukan bukti kelulusan navigasi keyboard penuh
atau audit WCAG. Tema terang, screen reader, browser lain, checkout peserta,
dan pembayaran kolektif end-to-end belum diuji pada P4b. PostgreSQL tidak
dijalankan ulang pada perubahan UI ini; 31 tes/128 assertions P4a adalah bukti
historis. Tidak ada perubahan frontend asset, sehingga lint/typecheck/build
frontend tidak dijalankan ulang. P5 menjadi tahap berikutnya.

Panel uji ditutup dan server dihentikan; viewport browser dikembalikan. Upaya
menghapus fixture ditolak kebijakan tool, sehingga kedua direktori berikut
masih berisi data sintetis dan dapat dibersihkan manual (bukan data aplikasi):

- `C:\Users\ThinkPad\AppData\Local\Temp\oncam-p4b-baa05a5254584b0481bbceda0c61c88c`
- `C:\Users\ThinkPad\AppData\Local\Temp\oncam-p4b-952bdca5b3a34890915782d50c18bdc1`

Perubahan masih working tree, tanpa commit massal/push karena registry terkait
sebagian belum tracked. Tidak ada migrasi database aktif, deploy, invoice,
pengiriman WA, atau transaksi nyata.
