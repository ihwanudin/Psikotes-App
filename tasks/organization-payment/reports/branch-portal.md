# P12a-prep — portal cabang baca-saja

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
