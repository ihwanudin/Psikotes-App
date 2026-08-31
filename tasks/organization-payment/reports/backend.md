# Backend P8b — laporan lane

## Preflight (2026-08-31)

- Baseline gate P8a, reservation, fixture, dan parallel-work.md tersedia.
- CLAUDE.md dan parallel-work.md dibaca lengkap; spec billing/checkout/funding,
  plan/todo serta kontrak gate/schema/reservation dipelajari.
- Skill wajib tersedia dan dibaca: Laravel Specialist (termasuk local guidance),
  Auth & Tenant Access, Security & Hardening, Queues Webhooks & Cache, TDD,
  Git Workflow, Postgres (termasuk locking dan RLS).
- Worktree sendiri; tidak ada .env aktif. Composer lock cocok dengan induk
  (SHA256 44AA7EA181ECF0ACCDD18A05AE5DA39BC8D9016C88431BFE9AEBFCB536720E16).
  Vendor disalin independen, bukan junction. Tidak menyalin runtime/cache/data.
- Tool send_message_to_thread tidak tersedia; laporan ini menjadi kanal koordinasi.
- Baseline awal modified/untracked dipertahankan; commit hanya file baru lane.

## Slice pertama yang sedang dikerjakan

Aktivasi per attempt dan outbox atomik/idempotent saja; token/start dipisahkan
ke increment setelah review sesuai izin parallel-work.md. Tidak mengubah
schema, route, konfigurasi, shared fixtures, checklist atau dokumen kanonik.
P8b keseluruhan **belum selesai**. Tidak lanjut P9/P10.

Temuan jalur existing: ParticipantJwt tidak membawa scope attempt;
ConsumeAssessmentInvitation menerbitkan JWT legacy; StartParticipantSessionController
masih mengembalikan SESSION_ENGINE_PENDING (501), belum membuat sesi.
Slice ini tidak menerbitkan credential, tidak memakai undangan legacy untuk
checkout-v2, dan tidak membuka endpoint baru.

Matriks internal: hanya caller service tepercaya boleh menjalankan aktivasi;
principal harus cocok participant/organization/attempt persisted. Participant,
BranchAdmin, anonymous/tanpa context tidak boleh memanggil action langsung.
Settlement adalah bukti persisted milik writer P11, bukan input paid browser.

## Bukti pengujian

- RED: 26 tes gagal (25 error class belum ada + 1 mismatch exception), setelah
  bootstrap diperbaiki dengan membuat direktori cache/view kosong lokal.
- GREEN pertama: 26 tes/87 assertions. Perluasan rollback outbox dan penjagaan
  dispatcher legacy: 28 tes/98 assertions lulus. Final focused setelah kasus
  legacy ready dan foreign persisted scope: **30 tes/107 assertions lulus**.
- Regresi auth: **87 tes/218 assertions lulus**, mencakup 28 kasus aktivasi saat
  itu, AttemptEntitlementGateTest, ParticipantLoginTest,
  ParticipantApiAuthorizationTest, dan ParticipantJwtTest. Dua kasus tambahan
  kemudian masuk run focused final di atas; kode aplikasi tidak berubah.
- PHPStan seluruh aplikasi: **0 error** (konfigurasi existing mengecualikan tests).
- Pint empat file PHP lane: **lulus** setelah memperbaiki format tes baru.
- Tidak ada skip pada hasil lulus di atas. Full regression tidak diulang di lane.
- PostgreSQL disposable: **149 tes/739 assertions lulus tanpa skip**, termasuk
  lima tes lane (runtime non-owner/non-BYPASSRLS, penolakan context peserta,
  rollback outbox/savepoint, dua proses retry, dan withdrawal consent saat
  aktivasi menunggu lock). Kedua proses memakai backend PostgreSQL berbeda;
  barrier mengamati keduanya benar-benar menunggu lock sebelum dilepas.
  Runner membersihkan container/network/tmpfs miliknya setelah selesai.
- `git diff --cached --check` lulus; daftar staging tepat lima file lane.

Perintah aktual: `php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration
phpunit.organization-payment.xml <path tes> --debug` (log lokal di storage/logs).
Pint memakai empat path lane; PHPStan `analyse --no-progress --memory-limit=1G`
dengan APP_ENV=testing, SQLite :memory:, cache/session array, queue sync.
Runner PG: `powershell -NoProfile -ExecutionPolicy Bypass -File
tools/testing/run-org-postgres.ps1`. Runner tersebut memakai XML PG sendiri.

Percobaan bootstrap native dan container SQLite awal gagal karena direktori view
kosong belum dibuat; percobaan container juga tidak dapat menulis result cache
pada mount read-only. Ini kegagalan environment, bukan RED perilaku. Container
SQLite percobaan sudah auto-remove; verifikasi berhasil memakai native PHP dengan
direktori kosong lokal. Tidak mengubah harness untuk menyembunyikan kegagalan.
Startup runner PG memerlukan waktu lama pada mount Windows (proses teramati
menunggu p9_client_rpc); setelah bootstrap suite selesai dalam 65,915 detik.
Tidak ada perpindahan tes ke database aktif atau pengubahan harness.

Catatan review yang harus dibawa saat integrasi: predicate settlement pada
action mengikuti P8a. Konsolidasi menjadi helper bersama gate sebaiknya
dilakukan setelah baseline P8a tracked, agar commit worker tidak memasukkan
ulang seluruh file gate baseline yang untracked. Gate baseline tidak diubah.
Lock order baru: organisasi -> bill -> seluruh item bill terurut -> attempt ->
participant -> charge -> entitlement -> consent/identity. Finalizer P11 wajib
mengambil mutex organisasi sebelum lock bill; jangan memanggil action dari
transaksi yang sudah memegang lock dengan urutan terbalik.

Outbox hanya intent `assessment.activation` (ID attempt/organisasi, tanpa PII,
credential, URL atau data DASS); dispatcher legacy tetap mengecualikannya.
Consumer/delivery baru belum tersedia dan tidak boleh diaktifkan sebelum
token/start selesai direview. Tidak ada janji exactly-once pengiriman eksternal.

## Kontrak dan batas slice

`ActivateSettledAssessment::execute(AssessmentPrincipal)` mengembalikan list tipe
tes yang baru aktif. Ia tidak menaikkan privilege sendiri. Attempt/principal,
snapshot paket, settlement, status lifecycle, consent dan identitas dimuat ulang
dalam transaksi. Scope asing atau prasyarat belum lengkap menghasilkan list
kosong; kesalahan DB/integritas tetap exception, bukan disamarkan sebagai sukses.
Hak yang sudah ready/in_progress/done tidak di-rewind. DASS tidak memblokir tes
utama; persetujuan DASS belakangan tidak menggandakan notifikasi attempt.

Tidak membuat atau mengubah settlement, bill, invoice, legacy entitlement, token,
sesi, consent atau bukti identitas. Consent/identity masih record per peserta
sesuai schema P8a, **dievaluasi untuk setiap attempt**; tidak mengklaim schema
consent baru per attempt. P11/P15 kelak memanggil action setelah settlement atau
pemenuhan prasyarat, dalam outer transaction dengan lock order yang sesuai.

Belum dibuktikan: webhook/finalizer batch, checkout HTTP, token purpose/start,
pengiriman notifikasi, browser, DB aktif, cutover atau readiness produksi.
P8b tetap parsial dan P9/P10 tidak dikerjakan. Tidak ada flag diaktifkan, route,
schema, dependency lockfile, UI, fixture bersama atau dokumen kanonik diubah.

Rujukan teknis: [transaksi Laravel 13](https://laravel.com/framework/docs/13.x/database#database-transactions),
[locking PostgreSQL 17](https://www.postgresql.org/docs/17/explicit-locking.html),
[otorisasi OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).

## File lane

1. app/Actions/Payments/ActivateSettledAssessment.php
2. app/Actions/Notifications/EnqueueAssessmentActivation.php
3. tests/Feature/Auth/SettledAssessmentActivationTest.php
4. tests/Postgres/SettledAssessmentActivationTest.php
5. tasks/organization-payment/reports/backend.md

Semua file baru milik lane. Commit lokal dibatasi pada lima path ini, tanpa
baseline awal; tidak menjalankan git add -A/reset/switch/merge/push. Integrasi
koordinator menggunakan checkpoint 3112e1524292cebe4fbde19e1c24eaa3befc8d0e
sebagai acuan baseline, bukan mengambil ulang snapshot untracked worker.

Review mandiri memeriksa correctness, scope tenant, rollback, dedup, minimisasi
payload dan lock order. Tidak ada agent tambahan. Konsolidasi predicate settlement
dan integrasi token/start tetap perlu review koordinator; ini bukan persetujuan
merge atau rilis. **Slice pertama siap review; berhenti sebelum increment berikutnya.**
