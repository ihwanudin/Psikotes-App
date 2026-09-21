# Deployment and operations runbook

Status: **documentation candidate only; release NO-GO**. Tidak ada deployment,
provider call, migration aktif, scheduler activation, atau outbound notification
yang dijalankan untuk closeout ini.

## Runtime topology and trust boundary

| Service | Peran | Credential database |
| --- | --- | --- |
| `app` | Nginx + PHP-FPM; Laravel/Inertia/Filament; `/health` | runtime |
| `queue` | Redis `notifications,default`; tries 5; timeout 120s | runtime |
| `integrations-queue` | Redis `integrations`; tries 5; timeout 120s | runtime |
| `scheduler` | `php artisan schedule:work` | runtime |
| `migrate` | `php artisan migrate --database=pgsql_migration --force` | owner only |
| `postgres` | PostgreSQL 17.6; internal network | owner bootstrap + runtime role |
| `redis` | queue/cache/session; internal network | Redis password |

Hanya `app` memublikasikan `${APP_BIND_ADDRESS:-127.0.0.1}:${APP_PORT:-8000}`.
PostgreSQL dan Redis tidak memiliki host port. Service Laravel memakai image,
configuration contract, dan volume storage yang sama. `compose.tunnel.yaml`
menambah cloudflared opsional dengan token file di `.secrets/`; file/token
tersebut bukan bagian Git dan tunnel tidak terbukti live.

## Credential inventory (nama saja, tanpa nilai)

| Boundary | Variable/reference | Penanggung jawab minimum |
| --- | --- | --- |
| Laravel | `APP_KEY` | platform |
| PostgreSQL runtime | `DB_RUNTIME_USERNAME`, `DB_RUNTIME_PASSWORD` | platform/DBA |
| PostgreSQL owner | `DB_OWNER_USERNAME`, `DB_OWNER_PASSWORD` | DBA; migrate only |
| Redis | `REDIS_PASSWORD` | platform |
| Participant JWT | `PARTICIPANT_JWT_SECRET` | security/platform |
| Selection provisioning | `SELECTION_INTEGRATION_CLIENT_SECRET` | both app owners |
| Selection result callback | `SELECTION_RESULT_CALLBACK_SECRET` | both app owners |
| Generic integrations | `ASSESSMENT_INTEGRATION_CREDENTIALS_JSON` containing credential references | integration owner |
| n8n | `N8N_WEBHOOK_URL`, `N8N_WEBHOOK_TOKEN` | notification owner |
| WAHA (workflow side) | `WAHA_URL`, `WAHA_TOKEN` | notification owner |
| Xendit | `XENDIT_SECRET_KEY`, `XENDIT_CALLBACK_TOKEN` | finance/platform |
| Private object storage | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`/`FILESYSTEM_S3_ENDPOINT` | storage owner |
| Identity/payment disks | `IDENTITY_FILESYSTEM_*`, `PAYMENT_PROOF_FILESYSTEM_*` | storage/security |
| IST asset disk | `IST_ASSET_FILESYSTEM_*` | storage/security |
| Shared Drive archive | `DRIVE_SA_JSON`, `DRIVE_SHARED_FOLDER_ID` | archive owner |
| Error tracker | `SENTRY_DSN` or approved equivalent | operations |
| Cloudflare tunnel | `.secrets/cloudflare_tunnel_token` | platform |

Gunakan secret manager/environment deployment. Jangan masukkan nilai ke Git,
chat, command history, resolved Compose output, screenshot, atau incident note.
Credential owner dan runtime harus berbeda. Xendit, Selection, n8n, WAHA, Drive,
S3, monitoring, dan tunnel tetap tidak aktif sampai owner dan evidence tersedia.

## Development/bootstrap sequence

1. Buat `.env` lokal dari `.env.example`; ganti placeholder dengan nilai acak
   development. Jangan memakai production secret/data.
2. Validasi tanpa mencetak resolved configuration:

   ```powershell
   docker compose config --quiet
   ```

3. Setelah start local stack diizinkan:

   ```powershell
   docker compose up --build -d
   docker compose ps
   docker compose --profile tools run --rm migrate
   ```

4. Pastikan migrasi dijalankan `migrate`, bukan `app`/worker. Untuk managed
   PostgreSQL, DBA membuat role terlebih dahulu; password tidak ditaruh dalam
   `database/schema/postgres_roles.sql`.
5. Setelah migrasi, jalankan `php artisan assets:sync-ist` (F2 IST reader
   Stage 1, 2026-09-21) pada tahap yang sama dengan `migrate` -- deploy-time,
   bukan scheduler berkala, karena tidak ada apa pun untuk diperiksa ulang
   di antara deploy. **Peran DB**: berbeda dari `migrate` (owner-only,
   `pgsql_migration`), command ini adalah `php artisan` biasa yang jalan
   lewat koneksi `pgsql` normal milik `app` -- yaitu peran runtime
   `psikotes_runtime`, RLS `app.role='service'` (lewat
   `RlsContextRunner::runAsService()`, sama seperti tulisan app lainnya).
   Tidak butuh credential owner. Command ini menyalin
   `database/seeders/data/assets/ist/**/*.png` (sumber checked-in, ditinjau
   sama seperti `ist_items.json`) ke disk privat `ist-assets` dan mencatat
   `asset_id` opaque per berkas di `assessment_asset_references` (tabel RLS
   `service`-only, sama pola dengan `instrument_versions`). **Exit code
   bukan-nol WAJIB menggagalkan deploy** -- command menolak melanjutkan bila
   checksum berkas yang baru ditulis tidak cocok dengan sumbernya setelah
   sync (byte korup), dan tidak pernah diam-diam tetap memakai aset
   lama/rusak.
6. Periksa `GET /health` dan daftar proses/schedule sebelum test. `/up` juga
   terdaftar oleh Laravel, tetapi semantics liveness-vs-readiness belum dibekukan.

Pada candidate ini `docker compose config --quiet` lulus dengan placeholder
ephemeral. Artisan tidak dapat diboot karena `vendor/autoload.php` tidak ada;
perintah berikut adalah runbook yang harus diverifikasi pada checkout lengkap.

## Pre-deploy and process checks

```powershell
php artisan list --raw
php artisan schedule:list
php artisan route:list --path=health
php artisan route:list --path=webhooks
docker compose ps
docker compose logs --since=15m app queue integrations-queue scheduler
```

Jangan menyalin payload/authorization header dari log. Expected schedule dari
`routes/console.php`:

- `test-numbers:prepare-month`: tanggal 1 pukul 00:00 pada timezone config;
- `payments:reconcile-xendit`: tiap 5 menit;
- `notifications:dispatch-outbox`: tiap menit;
- `integrations:dispatch-outbox`: tiap menit;
- `integrations:reconcile-callbacks`: tiap 5 menit;
- callback hasil generic: tiap 5 menit hanya bila feature flag aktif.

Seluruh schedule memakai `onOneServer`; pekerjaan periodik yang berpotensi
tumpang tindih juga memakai `withoutOverlapping`. Audit purge ada sebagai
command inert dan **tidak** terdaftar pada scheduler.

## Reviewed recreate/update and health procedure

`docker compose restart` tidak mengadopsi image baru atau membuat ulang
container setelah perubahan config/env. Untuk release yang sudah direview,
tetapkan `APP_IMAGE_TAG` immutable yang disetujui. Sediakan image itu dengan
`docker compose build app` dari source/commit yang direview, atau ambil image
berdigest tepat lewat prosedur registry yang direview; jangan memakai `latest`.
Setelah identitas image diverifikasi, validasi hasil render lalu recreate
keempat service proses dengan image dan konfigurasi baru:

```powershell
docker compose config --quiet
docker compose up -d --no-deps --force-recreate app queue integrations-queue scheduler
docker compose ps
php artisan schedule:list
```

Pastikan identitas image aktual sama dengan tag/digest yang direview. Verifikasi
bahwa app sehat, worker mendengarkan queue yang tepat, Redis dapat dijangkau,
dan `REDIS_QUEUE_RETRY_AFTER` lebih besar dari timeout worker (default 150 > 120
detik). Jangan menjalankan dua scheduler tanpa distributed lock bersama.

`/health` melakukan query PostgreSQL dan Redis ping serta controller memiliki
respons redacted 503. Namun route masih berada dalam middleware `web` dengan
session Redis; dependency failure dapat terjadi sebelum controller. Karena itu
mocked controller test dan Compose healthcheck belum membuktikan HTTP
dependency-down fail-closed. Perlakukan `/health` sebagai bukti parsial sampai
semantik route dan probe HTTP DB-down/Redis-down disposable diterima.

## Notification outbox incident flow

`notifications:dispatch-outbox` **bukan diagnostic read-only**. Command ini
memilih row outbox yang sudah due lalu mengantrekan job delivery. Ketika worker
mengonsumsi job, claim menaikkan `attempts`, mengubah status, menulis audit, dan
dapat memanggil endpoint live n8n/WAHA melalui notifier. Jalankan command hanya
dengan otorisasi outbound/provider yang eksplisit dan environment tujuan yang
telah diverifikasi. `queue:failed` sendiri hanya menginventarisasi failed job.

```powershell
php artisan notifications:dispatch-outbox --limit=100
php artisan queue:failed
```

Jangan menjalankan dispatcher manual bila provider live belum diizinkan. Outbox
memilih topic `participant.activation`, due/unexpired, `attempts < 5`, status
`pending|failed`, atau `processing` yang stale lebih dari 10 menit. Job queue
mencoba lima kali; delivery service menulis retry 30/120/600/1800 detik.

Diagnosis tanpa membaca payload:

- `n8n_not_configured`: URL/token tidak tersedia pada environment worker;
- `n8n_connection_failed` atau notifier failure: periksa network/provider dan
  idempotency key sebelum retry;
- `notification_payload_invalid`/`notification_contract_invalid`: hentikan
  retry; ini defect/data-contract incident;
- `notification_order_not_paid`: jangan kirim; rekonsiliasi state order;
- attempts 5 atau entry `failed_jobs`: dead-letter. Jangan membuat message baru,
  mengubah counter/status, atau mass `queue:retry` sebelum outcome n8n/WAHA
  direkonsiliasi.

Kegagalan notifikasi tidak boleh mengubah order `paid` atau entitlement `ready`.
Tidak ada bukti live n8n/WAHA dalam closeout ini.

## Manual transfer verification

Aktifkan `manual_transfer` hanya setelah rekening, instruksi, reviewer, storage
private, dan retensi operasional disetujui. Peserta mengunggah bukti dari sesi
registrasi; browser tidak mengirim order/participant ID. Admin berkemampuan dan
ber-scope tepat membuka URL sementara, lalu keputusan mengikat object key yang
telah ditinjau sehingga replacement concurrent gagal tertutup.

- Approve pertama mengubah `pending→paid`, membuka entitlement, dan membuat
  outbox dalam transaksi; replay identik adalah no-op.
- Reject menyimpan alasan tanpa membuka entitlement; keputusan terminal
  berlawanan tidak boleh menimpa state.
- Jangan mengunduh ke lokasi publik, menyalin URL/object key ke tiket insiden,
  atau memakai bukti/data peserta nyata untuk smoke test.
- Retensi/purge bukti belum disetujui hukum/psikolog; replacement pending yang
  membersihkan object lama bukan kebijakan retensi terminal.

## Xendit webhook and reconciliation incident flow

Prerequisite aktivasi: credential development Xendit, callback HTTPS, approval
finance/security, sandbox contract test, dan full synthetic E2E. Sebelum itu
metode `xendit` tetap OFF.

`POST /webhooks/xendit` dapat menghasilkan:

- `WEBHOOK_REJECTED` (401): callback token tidak valid;
- `WEBHOOK_REJECTED` (422): payload/status tidak dapat dinormalisasi, atau
  reference, nominal, currency, maupun bill/order tidak cocok. Jangan log
  token/body atau mengubah order;
- `WEBHOOK_CONFLICT` (409): event ID yang sama memiliki intent berbeda. Bekukan
  tindakan manual, cocokkan invoice/order/event ledger dengan dashboard;
- 200 `received`: dapat berarti applied, duplicate, ignored, atau
  `invalid_transition`. Untuk `invalid_transition`, event dicatat rejected
  dengan error code tersebut, state terminal order dipertahankan, dan controller
  sengaja tetap merespons 200.

`payments:reconcile-xendit` adalah rekonsiliasi **state-changing**, bukan
diagnostic read-only. Command melakukan network status call ke provider untuk
setiap kandidat lalu meneruskan hasilnya melalui `PaymentWebhookProcessor`.
Pemrosesan dapat menyimpan payment event serta mengubah order, entitlement,
audit, dan outbox secara transaksional. Jalankan hanya dengan credential Xendit
development/target yang disetujui, environment yang telah diverifikasi, dan
otorisasi operator/provider yang eksplisit.

```powershell
php artisan payments:reconcile-xendit --limit=100
```

Command hanya memilih order Xendit `pending` dengan `gateway_ref`, menerima
limit 1-500, dan exit failure bila ada lookup/rejection/conflict gagal. Tinjau
event log aman `xendit_api_request`, `payment_webhook_processed`,
`xendit_status_reconciliation_failed`, dan completion counters. Jangan memakai
rekonsiliasi sebagai alasan membuat invoice kedua atau replay callback mentah.

Invoice API yang dipakai adalah legacy. Migrasi ke Payment Session memerlukan
perubahan adapter/kontrak terpisah; jangan mengganti diam-diam saat insiden.

## Backup, restore, monitoring, and retention status

Repository memiliki bukti **bounded local logical dump/restore**: custom archive,
single-transaction restore, schema/data/sequence comparison, dan corrupt-archive
rejection. Bukti itu tidak menetapkan encryption, off-host/object-storage
retention, WAL/base backup, PITR, production volume, RPO, atau RTO.

Target `pg_basebackup`/WAL + dump terenkripsi, object storage `backups/`, restore
berkala, error tracker, log aggregation, uptime eksternal, queue-depth alert,
threshold, destination, escalation owner, dan SLO semuanya memerlukan keputusan
dan bukti operasional terpisah. Tidak ada yang boleh diklaim live dari dokumen
ini. Audit observability 2026-09-14 juga menyatakan correlation/structured HTTP
metrics dan dependency-down HTTP behavior `NOT-VERIFIABLE`.

Command `retention:purge-expired-audits --limit=1000` tersedia tetapi inert dan
tidak dijadwalkan. Retensi/purge identity evidence, selfie, payment proof, serta
consent membutuhkan keputusan hukum/psikolog. Jangan aktifkan purge sebelum
hold/exception, owner, backup/restore, audit, dan rollback disetujui.

## Staging, production, rollback

Urutan ini adalah target runbook, bukan bukti bahwa deployment telah terjadi:

1. review immutable image/tag dan backup/restore evidence;
2. deploy ke staging dengan provider/feature flags OFF;
3. jalankan owner-only migration dan smoke test sintetis;
4. validasi route, RLS runtime role, worker/scheduler, upload private, dan
   negative security paths;
5. aktifkan satu boundary setelah credential+evidence+owner disetujui;
6. baru pertimbangkan production dari commit yang direview.

Rollback aplikasi menetapkan kembali `APP_IMAGE_TAG` immutable sebelumnya yang
sudah direview dan menyediakan image tepat itu lewat build/registry procedure
yang direview. Lalu validasi hasil render dan recreate keempat service:

```powershell
docker compose config --quiet
docker compose up -d --no-deps --force-recreate app queue integrations-queue scheduler
```

Setelah recreate, verifikasi health app, identitas image, subscription queue,
dan schedule. Rollback database hanya mengikuti migration-specific reviewed
plan; `migrate:rollback`, destructive migration, atau restore tidak boleh
dijalankan sebagai respons default. Jangan menggunakan `latest`,
`migrate:fresh`, atau owner credential untuk runtime verification.
