# Security model and operational boundaries

Dokumen ini merangkum kontrol yang ada pada kode serta keputusan yang masih
memerlukan bukti operasional. Status release tetap **NO-GO**.

## Identitas dan sesi

- Peserta login dengan nomor tes dan tanggal lahir. JWT HS256 berumur 12 jam,
  tanpa refresh token, memakai `typ=participant+jwt` dan validasi algoritme,
  issuer, audience, subject, waktu, `participant_id`, serta `branch_id`.
- `PARTICIPANT_JWT_SECRET` harus key acak `base64:` minimal 32 byte dan berbeda
  dari `APP_KEY`, callback token, serta seluruh secret integrasi.
- Login peserta dibatasi 5/menit/IP. Riwayat kegagalan per nomor tes disimpan
  sebagai key HMAC di Redis; lockout meningkat 60 detik, 5 menit, lalu 15 menit.
- Admin memakai sesi Laravel/Filament dan guard `admin`. Hak super admin, admin
  cabang, staf, dan psikolog diperiksa server-side.
- Cookie admin production harus `Secure`, `HttpOnly`, `SameSite=Lax`. Token
  peserta tidak boleh berada di URL atau log.

## PostgreSQL RLS

DDL dan migrasi hanya menggunakan role owner melalui koneksi `pgsql_migration`.
Web, queue, integrations queue, dan scheduler menggunakan
`psikotes_runtime`, yang harus bukan owner, `NOSUPERUSER`, dan `NOBYPASSRLS`.
Tabel tenant memakai `ENABLE ROW LEVEL SECURITY` dan `FORCE ROW LEVEL SECURITY`.

Untuk request bertenant, principal tepercaya menyediakan `role`, `branch_id`,
dan `participant_id`. `ApplyRlsContext` menjalankan request dalam transaksi dan
`RlsContextRunner` memasang nilai dengan `set_config(..., true)`, sehingga
setting transaction-local hilang saat commit/rollback. Header, query, body, dan
ID dari browser tidak pernah menjadi sumber konteks RLS.

Controller bertenant wajib mengimplementasikan `RequiresRlsContext` dan route
wajib memasang middleware `rls`. Job tenant wajib mengimplementasikan
`ProvidesRlsContext` dan memasang `ApplyRlsContextToJob`; job tanpa principal
tepercaya gagal tertutup. `runAsService` bukan bypass umum: nested elevation
dari participant ditolak. Pengecualian start-session ADR-0030 harus berada di
command service tertutup, bukan callback participant-to-service generik.

Schema `dass` terpisah dan tidak tersedia bagi admin non-psikolog. DASS tidak
boleh memengaruhi zona atau label kelayakan.

## Data privat dan logging

- Foto identitas/selfie dan bukti transfer menggunakan storage private, key acak
  tanpa nama peserta/file asli, validasi isi/MIME/ukuran, dan URL sementara 15
  menit setelah policy check. Konfigurasi S3-compatible belum membuktikan bucket
  eksternal live atau policy production.
- Bukti identitas menerima JPG/PNG/WebP hingga 5 MB dan dimensi 480-8.000 px.
  Bukti transfer menerima JPG/JPEG/PNG/PDF hingga 5.000 KB.
- Event webhook menyimpan intent ter-normalisasi, bukan raw callback body.
  Token, authorization header, URL bertanda tangan, object key, jawaban mentah,
  telepon, nomor tes, dan detail exception tidak boleh masuk log.
- Mismatch face matcher adalah marker untuk tinjauan, bukan keputusan kelayakan.
  Provider pencocokan wajah otomatis belum dipilih.
- Capture proctoring menurut SPEC adalah berkala tiap 12-20 detik, plus saat
  mulai/submit; implementasi, storage, consent final, dan bukti mobile E2E tetap
  fase berikutnya. Jangan gunakan klaim lama "maksimal lima foto per sesi".

## Pembayaran dan webhook Xendit

Xendit dan transfer manual memakai state machine order yang sama tetapi jalur
input berbeda. Hanya transisi pertama `pending→paid` yang membuka entitlement.
Order, entitlement, event ledger, dan sinyal outbox berubah atomik dalam konteks
service RLS.

`POST /webhooks/xendit` memverifikasi `x-callback-token` secara konstan-waktu
sebelum normalisasi. Invoice reference, merchant reference, amount, dan currency
harus cocok. Event `(provider,event_id)` diklaim unik; intent berbeda dengan ID
yang sama menghasilkan `WEBHOOK_CONFLICT`. Authentication/payload/reference/
money yang salah menghasilkan respons generik `WEBHOOK_REJECTED` tanpa token
atau body di log.

Respons konflik atau penolakan tidak boleh "diperbaiki" dengan update langsung:
bekukan tindakan manual, catat waktu/status/error code aman, bandingkan order dan
event ledger dengan dashboard provider, lalu gunakan rekonsiliasi read-only.
Metode Xendit tetap OFF dan tidak ada bukti provider live/sandbox pada candidate
ini.

## Notifikasi dan dead-letter

Notifikasi aktivasi memakai outbox `participant.activation`, deduplication key,
dan queue Redis `notifications`. Job `DeliverOutboxMessage` memiliki lima
percobaan dengan backoff 30, 120, 600, dan 1.800 detik. Delivery gagal menulis
`status=failed`, `last_error` berupa kode aman, audit tanpa PII, dan waktu retry;
order paid/entitlement ready tidak dibatalkan.

Dispatcher hanya memilih `attempts < 5`. Setelah percobaan kelima, record outbox
tetap `failed` dan job queue dapat tercatat di `failed_jobs`. Ini adalah
dead-letter operasional dan tidak di-requeue massal. Operator harus:

1. periksa `queue:failed` dan agregat outbox berdasarkan `status`, `attempts`,
   `last_error`, serta expiry tanpa mencetak payload;
2. koreksi config/provider dan pastikan worker memakai environment terbaru;
3. cocokkan delivery di n8n/WAHA berdasarkan idempotency key sebelum retry;
4. retry hanya job/pesan yang telah direkonsiliasi, jangan membuat outbox baru
   atau mengubah attempts/status langsung.

Tidak ada pengiriman live n8n/WAHA yang diklaim. Workflow repository sengaja
default nonaktif dan harus tetap menjaga deduplikasi atomik serta tidak menyimpan
execution body.

## Retensi dan keputusan manusia

Kontrak saat ini mencatat HPP/data psikotes lima tahun, DASS dua tahun,
proctoring 90 hari, dan audit lima tahun tanpa PII. Command purge audit tersedia
secara inert tetapi belum dijadwalkan/diaktifkan. Masa retensi dan purge untuk
foto identitas, selfie, bukti pembayaran, serta consent final belum memiliki
otoritas hukum/psikolog yang cukup. Jangan menghapus atau menjadwalkan purge data
tersebut sampai keputusan tertulis, hold/exception, bukti restore, dan audit
operasi disetujui.

## Insiden minimum

- Dugaan secret bocor: nonaktifkan boundary terkait, rotasi secret pada kedua
  sisi, invalidasi token bila relevan, dan audit tanpa menyalin nilai secret.
- Webhook rejected/conflict: jangan replay payload mentah atau mengubah order;
  ikuti alur rekonsiliasi di `DEPLOYMENT.md`.
- Queue macet: tahan dispatch manual berulang, periksa worker/Redis/outbox/failed
  jobs, lalu restart worker setelah code/config tervalidasi.
- Dugaan lintas tenant: hentikan akses terdampak dan pertahankan bukti audit;
  jangan memakai owner credential untuk "menguji" akses aplikasi.

Monitoring backend, correlation contract, alert threshold/destination/owner, dan
health endpoint fail-closed pada dependency-down HTTP belum terbukti. Lihat
handoff observability 2026-09-14; ketiadaan bukti tidak boleh ditulis sebagai
kontrol production yang aktif.
