# DEPLOYMENT.md (v4.0 — Docker Compose di VPS)

## Environment
| Env | Cara jalan | DB |
|---|---|---|
| dev | `docker compose up --build` (app, queue, scheduler, Redis, Postgres lokal) | Postgres lokal (kontainer) |
| staging | Docker Compose di VPS staging, domain `staging.psikotes.oncam.id` | Postgres terpisah (VPS/managed), migrasi diuji di sini dulu |
| production | Docker Compose di VPS produksi, domain `psikotes.oncam.id` | Postgres produksi (PITR aktif) |

Kontainer terpisah (semua env): `app` (PHP-FPM+Nginx, Laravel+Inertia+Filament dalam satu build), `queue` (Laravel Queue worker — render PDF/Browsershot, rakit narasi, sinkron Drive, notifikasi WAHA/n8n), `scheduler` (Laravel Scheduler), `redis`, dan `postgres`. Ketiga proses Laravel memakai image/config yang sama. Redis & Postgres hanya berada di jaringan Docker internal dan tidak memiliki host port; hanya `app` yang memublikasikan `${APP_BIND_ADDRESS:-127.0.0.1}:${APP_PORT:-8000}` untuk reverse proxy lokal.

## Menjalankan stack development

1. Salin `.env.example` menjadi `.env`, lalu ganti seluruh placeholder password/secret development.
2. Jalankan `php artisan key:generate` untuk membuat `APP_KEY` lokal; jangan commit `.env`.
3. Jalankan `docker compose up --build -d`.
4. Periksa `docker compose ps`; `postgres`, `redis`, dan `app` harus sehat.
5. Buka `http://localhost:8000/health`. Respons siap adalah `{"status":"ok"}`; kegagalan dependency menghasilkan status HTTP 503 tanpa detail koneksi.

Docker Compose otomatis membaca `.env`. Volume bernama `postgres-data`, `redis-data`, dan `app-storage` mempertahankan state development saat kontainer dibuat ulang.

## Langkah deploy
1. Migrasi DB: `php artisan migrate` (staging dulu; migrasi destruktif WAJIB tag git `pre-{aksi}` + backup manual sebelum jalan — aturan CLAUDE.md).
2. Build image: `docker compose build` → push ke registry (atau build langsung di VPS untuk skala saat ini) → smoke test di staging.
3. Deploy produksi: `docker compose pull && docker compose up -d` (rolling — `app` baru naik, health check lulus, baru kontainer lama dimatikan). Production hanya dari `main` yang sudah direview.
4. Restart queue worker setelah tiap deploy (`docker compose restart queue`) agar kode lama di worker tidak terus jalan.
5. Validasi data sumber dengan `python -m unittest discover -s tools/extract/tests -v`, lalu seed instrumen memakai `php artisan db:seed --class=InstrumentSeeder`. Versi yang sudah tersimpan immutable; revisi norma wajib memakai versi baru dan bump `engine_version`.

## Secrets (`.env` di server, TIDAK di git — lihat `.env.example`)
`APP_KEY`, `DB_*` (host/port/db/user/password Postgres privat), `REDIS_*`, `PARTICIPANT_JWT_SECRET`, `FILESYSTEM_S3_*` (endpoint/key/secret/bucket — object storage S3-compatible), `DRIVE_SA_JSON` (base64, service account) + `DRIVE_SHARED_FOLDER_ID`, `WAHA_URL`/`WAHA_TOKEN` atau `N8N_WEBHOOK_URL`, `XENDIT_SECRET_KEY` + `XENDIT_CALLBACK_TOKEN`, `SENTRY_DSN` (atau Laravel error tracker pilihan).

## Rollback
- `app`/`queue`: `docker compose up -d --no-deps app` dengan tag image sebelumnya (image versioned per rilis, bukan `latest`) — cepat karena image lama masih ada di registry/lokal.
- DB: migrasi selalu berpasangan up/down (`php artisan migrate:rollback`); data instrumen versioned (`instrument_versions`) — rollback = aktifkan versi sebelumnya, JANGAN edit in-place.
- Tag git `pre-{aksi}` sebelum operasi berisiko (aturan CLAUDE.md).

## Backup & monitoring
Postgres PITR (WAL archiving / `pg_basebackup`) + dump harian terenkripsi ke object storage S3-compatible (`backups/`, retensi 30 hari); uji restore per kuartal. Monitoring: error tracker (Sentry atau setara, terpasang di `app` dan `queue`), log terpusat (Docker logging driver → agregator pilihan), uptime eksternal (healthcheck `/health` tiap 1 menit — Laravel route ringan yang cek DB+Redis), alert webhook gagal beruntun & antrean PDF macet (queue depth Redis dipantau).
