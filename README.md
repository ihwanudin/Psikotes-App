# psikotes.oncam.id

Sistem psikotes daring multi-organisasi untuk CPMI tujuan Jepang. Laravel
melayani Inertia/React untuk peserta dan Filament/Livewire untuk operator.
PostgreSQL dengan RLS menjadi batas data utama; DASS-21 tetap terpisah dari
jalur kelayakan. Laporan tidak boleh terbit tanpa tinjauan psikolog.

Status saat ini: **F1 parsial dan release NO-GO**. Alur transfer manual dan
sejumlah kontrak foundation sudah memiliki bukti lokal, tetapi Xendit sandbox
end-to-end belum dijalankan, metode Xendit harus tetap OFF, dan Task 19 maupun
Task 18 belum boleh dinyatakan selesai. Lihat `F1_VALIDATION.md` untuk matriks
`PROVEN`/`NOT-RUN`/`NOT-VERIFIABLE`/`BLOCKED`.

## Dokumen kanonis

| Dokumen | Kegunaan |
| --- | --- |
| `SPEC.md` | Perilaku produk dan fase F1-F9 |
| `SCORING_ALGORITHM.md` | Kontrak skoring kanonis |
| `tasks/parallel-work.md` | Ownership, dependensi, resume, dan status integrasi |
| `tasks/todo.md` | Checklist F1; hitung ulang dari checkbox, jangan dari chat |
| `tasks/f2-f9-acceptance.md` | Matriks exit gate F2-F9 |
| `DATABASE_SCHEMA.md` / `API_CONTRACT.md` | Skema/RLS dan kontrak HTTP |
| `SECURITY.md` / `DEPLOYMENT.md` | Batas keamanan dan runbook operasi |

## Topologi runtime

`compose.yaml` mendefinisikan:

- `app`: PHP-FPM, Nginx, Laravel, dan health check `/health`;
- `queue`: Redis queue `notifications,default`, lima percobaan, timeout 120 detik;
- `integrations-queue`: queue `integrations`, terpisah dari notifikasi peserta;
- `scheduler`: `php artisan schedule:work`;
- `migrate`: profile `tools`, koneksi owner `pgsql_migration` saja;
- `postgres` dan `redis`: hanya network internal `backend`, tanpa host port.

`app`, `queue`, `integrations-queue`, dan `scheduler` memakai role runtime
`psikotes_runtime`. Role tersebut harus `NOSUPERUSER`, `NOBYPASSRLS`, dan bukan
pemilik tabel. Hanya service `migrate` yang menerima credential owner.

Object storage S3-compatible, arsip Shared Drive, n8n/WAHA, Xendit, monitoring
eksternal, dan backup off-host adalah integrasi/configuration target. Keberadaan
konfigurasi atau adapter di repository bukan bukti bahwa layanan live tersebut
sudah dipasang atau divalidasi.

## Setup development tanpa secret nyata

Prasyarat sesuai image yang dipin: Docker dengan Compose, atau PHP 8.3.26,
Composer 2.9.4, Node 24.11.0, npm yang kompatibel dengan lockfile v3,
PostgreSQL 17, dan Redis 8.2. Dependensi aplikasi dikunci di `composer.lock`
dan `package-lock.json`; versi executable npm tidak dipin oleh repository.

1. Salin `.env.example` ke `.env` lokal yang tidak di-commit.
2. Ganti seluruh `change-this-*` dan field kosong yang wajib dengan nilai acak
   khusus development. Jangan memakai credential production atau data peserta.
3. Buat dua key acak terpisah dengan PHP, lalu simpan masing-masing sebagai
   `APP_KEY` dan `PARTICIPANT_JWT_SECRET` lokal:

   ```powershell
   php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
   php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
   ```

4. Validasi interpolasi Compose tanpa menampilkan resolved configuration:

   ```powershell
   docker compose config --quiet
   ```

5. Setelah operator memang mengizinkan start local stack, jalankan service dan
   migrasi dengan dua langkah terpisah:

   ```powershell
   docker compose up --build -d
   docker compose --profile tools run --rm migrate
   docker compose ps
   ```

6. Verifikasi `GET http://localhost:8000/health`, lalu jalankan test yang relevan.
   Jangan memakai `migrate:fresh`, reset schema, atau data nyata sebagai smoke
   test. Instruksi deployment, restart, dan diagnosis ada di `DEPLOYMENT.md`.

Untuk menjalankan test di host, pasang dependensi tepat dari lockfile pada
checkout development yang diizinkan, lalu gunakan suite terfokus sebelum suite
penuh:

```powershell
composer install --no-interaction
npm ci --ignore-scripts
php artisan test
npm run lint:check
npm run types:check
```

Langkah instalasi tersebut tidak dijalankan pada closeout dokumentasi ini.

Pada checkout dokumentasi ini hanya `docker compose config --quiet` yang dapat
dijalankan aman. `vendor/autoload.php` tidak tersedia, sehingga daftar Artisan,
schedule, route, dan test dicatat `NOT-VERIFIABLE`, bukan dianggap lulus.

## Batas pembayaran dan notifikasi

Semua metode pembayaran default OFF. Kanal OFF tidak tampil dan tidak menerima
order baru; order historis tetap dapat dibaca/diproses idempoten. Xendit tidak
boleh diaktifkan sebelum credential sandbox dan alur invoice → callback
terautentikasi → entitlement → notifikasi fake → login lulus.

Notifikasi peserta memakai transactional outbox. Scheduler menyeleksi pesan due
dengan `notifications:dispatch-outbox`; job dikirim ke queue `notifications`.
Kegagalan notifier tidak mengubah order `paid` atau entitlement `ready`. Adapter
n8n, workflow deduplikasi, dan fake notifier memiliki bukti repository, tetapi
tidak ada klaim pengiriman live n8n/WAHA pada closeout ini.

## Aturan kontribusi singkat

- Jangan commit `.env`, key, token, credential JSON, bukti identitas/pembayaran,
  atau data peserta.
- Route/controller bertenant harus mengikuti kontrak RLS; job bertenant wajib
  membawa middleware konteks RLS. Pengecualian start-session ADR-0030 hanya sah
  melalui command service sempit yang disetujui.
- Migrasi dijalankan owner; aplikasi dan worker selalu memakai runtime role.
- Jangan mengaktifkan provider, scheduler purge, integrasi, atau deployment hanya
  karena implementasi atau dokumentasinya tersedia.
