# Pengujian pembayaran lembaga — P1

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
concurrency pembayaran, atau persetujuan go-live. P2 dan seterusnya belum
diimplementasikan; perlu memperluas tes PostgreSQL untuk schema/fitur masing-masing.
