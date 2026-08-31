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

## Gelombang kedua — proposal sebelum implementasi

Instruksi terbaru parallel-work.md dan reports/integration-wave-1.md dibaca
read-only dari induk. Baseline worktree tidak di-reset/merge; delta berikut
dimulai dari cad7828. Tool pesan lintas task tetap tidak tersedia.

Keputusan yang diajukan untuk review koordinator:

- Credential opaque melalui Laravel Encrypter existing (authenticated encryption,
  MAC/tag diverifikasi framework), bukan implementasi JWT/HMAC baru.
- Purpose autentik `assessment-start`, version 1, issuer/audience terpisah dari
  legacy, scope participant/organization/assessmentParticipant, iat dan exp;
  TTL usulan 600 detik. Tidak ada paid/ready/consent, PII, URL atau credential
  legacy di payload. APP_KEY/cipher existing melalui dependency Encrypter;
  tidak menambah key/config/dependency baru.
- Credential adalah bearer akses berumur pendek, **bukan** handoff sekali pakai.
  Replay selama TTL tidak membuat sesi: gate diperiksa ulang pada setiap request
  dan controller tetap 501 SESSION_ENGINE_PENDING. Tidak mengklaim revocation
  token individual tanpa storage; revoke attempt/withdraw consent mengunci gate.
- Middleware khusus memverifikasi token dan menghasilkan AssessmentPrincipal;
  tidak mengambil scope dari input browser dan tidak fallback ke ParticipantJwt.
  Adapter controller start memilih gate attempt hanya dari principal ini.
- Route produksi tetap tidak berubah, termasuk middleware legacy. Verifikasi
  HTTP menggunakan route test-only menuju middleware dan controller asli;
  wiring endpoint publik memerlukan review terpisah. Token assessment tidak
  akan diterima endpoint legacy /me atau endpoint start legacy existing.
- Request yang meminta attempt dengan credential legacy ditolak, tidak diarahkan
  ke entitlement participant+test_type. Scope/prasyarat dibaca melalui gate P8a,
  tanpa salinan settlement predicate ketiga dan tanpa transisi sesi.

Sebelum review kontrak diterima, hanya persiapan tes/proposal; belum implementasi
verifier atau perubahan kode produksi gelombang kedua. Permintaan review juga
disampaikan melalui pesan async agar koordinator dapat memberi keputusan.
PG belum diperlukan oleh rencana read-only adapter ini; portal dapat memakai
runner. Bila perubahan RLS/transaksi ternyata diperlukan akan dikoordinasikan.

Persiapan TDD sudah tersedia pada tests/Unit/Auth/AssessmentAccessTokenTest.php:
**29 tes RED** (22 error + 7 failure), semuanya karena AssessmentAccessToken
belum diimplementasikan. Perintah memakai phpunit.organization-payment.xml
dan --debug, log lokal storage/logs/p8b-token-red.log. Ini bukan tes lulus dan
belum dicommit sebagai fitur selesai. Controller/middleware legacy serta lockfile
dicocokkan ke induk: hash sama, belum ada delta produksi gelombang kedua.

Rujukan primitive: Laravel 13 EncryptionServiceProvider menyediakan StringEncrypter
dengan key/cipher serta previous keys existing; encryptString/decryptString
mematikan serialisasi PHP. Signature/integritas pada usulan ini berarti MAC/tag
authenticated encryption, bukan klaim tanda tangan asimetris/JWT standar.
[Dokumentasi Laravel encryption](https://laravel.com/framework/docs/13.x/encryption).

## Gelombang kedua — implementasi lokal disetujui, increment token

Koordinator menyetujui proposal f505c2f secara eksplisit dengan batas 600 detik,
StringEncrypter existing, scope persisted, gate per request, tanpa route produksi
atau engine baru. Skill Auth & Tenant Access, Laravel, Security, TDD, Git Workflow,
Code Review dan API & Interface Design dipakai. Tidak ada agent tambahan.

AssessmentAccessToken memakai encryptString/decryptString JSON nonserialized,
allowlist sembilan claim dengan tipe ketat, ID positif, purpose/version/issuer/
audience setelah autentikasi ciphertext. Issuer/audience berasal config app.url,
bukan Host/browser. Batas token 4096 byte, TTL maksimum 600 detik, iat tidak boleh
di masa depan; tepat pada exp token ditolak. Tidak ada claim paid/consent.
Exception dekripsi disanitasi tanpa message/previous exception atau log payload.

Bukti aktual increment token:
- RED awal 29 tes karena class belum ada; GREEN awal 29/35, lalu penguatan batas
  TTL 601 detik dan missing claim/exception: **31 tes, 40 assertions**, lulus.
  `php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit/Auth/AssessmentAccessTokenTest.php --debug`
  Log lokal p8b-token-final.log (storage/logs), tidak dicommit.
- Pint targeted lulus. PHPStan targeted AssessmentAccessToken: **0 error**,
  APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, DB_URL kosong,
  cache/session array dan queue sync. Percobaan pertama tanpa environment
  eksplisit ditolak production config guard sebelum bootstrap; bukan hasil lulus.
- APP_KEY/previous_keys/cipher/config tidak diubah; framework menangani rotasi.
  Rotasi bukan revocation individual: replay credential tetap mungkin selama TTL,
  hanya pembacaan scope dan gate saat request yang menolak revoke/prasyarat berubah.
- Issuance hanya method internal untuk caller yang sudah mengautentikasi scope;
  belum ada producer/public route/consumer. Enkripsi bukan bukti settlement.

Increment ini hanya tiga file lane: AssessmentAccessToken.php,
tests/Unit/Auth/AssessmentAccessTokenTest.php, dan laporan ini. Middleware/request/
controller dan tes HTTP dipisah ke commit berikut (masih scope persetujuan sama).

## Gelombang kedua — adapter start siap review lokal

Commit token: **ee7ba62** (3 file). Increment berikut terdiri dari 5 file:

1. app/Http/Middleware/AuthenticateAssessmentToken.php (baru).
2. app/Http/Requests/StartAssessmentSessionRequest.php (baru).
3. app/Http/Controllers/StartParticipantSessionController.php (delta baseline).
4. tests/Feature/Auth/AssessmentSessionAuthorizationTest.php (baru).
5. tasks/organization-payment/reports/backend.md (laporan ini).

Middleware menerima satu Authorization Bearer saja, menolak header ambigu/ganda,
memberi batas panjang, memverifikasi payload autentik, menghapus principal lama,
dan memuat ulang participant+branch serta attempt+participant+organization.
Tidak membaca credential query/body/cookie dan tidak fallback ke JWT legacy.
Lookup scope memakai runner service existing; handler berikutnya berjalan di
luar context service. Error 401 generik tanpa ciphertext/payload/exception asli.

FormRequest memakai testType route yang divalidasi, tidak menerima input scope
pada jalur assessment. Selector participant/organization/assessment dalam bentuk
snake_case/camelCase juga ditolak pada legacy; input legacy lain tetap kompatibel.
Controller memanggil AssessmentEntitlementGate P8a pada setiap request assessment
dalam runAsService existing, tanpa menyalin predicate settlement/prasyarat.
Principal assessment malformed tidak pernah fallback ke legacy. Respons locked
403 dan **501 SESSION_ENGINE_PENDING** tetap sama; tidak ada transisi sesi/aktivasi.

| Aktor/credential | Permintaan pada harness assessment | Hasil |
| --- | --- | --- |
| Anonymous, token legacy/checkout, forged, expired, header ambigu | start attempt | 401 |
| Token autentik dengan participant/tenant/attempt asing atau hilang | start attempt | 401 |
| Pemilik dengan token valid tetapi unpaid/revoked/finalized/prasyarat berubah | start attempt | 403 |
| Pemilik dengan settlement dan prasyarat valid saat request | start attempt | 501 pending, tanpa write |
| Pemilik dengan input scope dari browser | start attempt | 422 |
| Admin/session lama tanpa credential assessment | start attempt | Tidak ada bypass; 401 |
| JWT legacy dan entitlement legacy ready, tanpa selector attempt | route start legacy | Tetap 501 pending |

### Bukti aktual

- HTTP RED: **22 error** karena middleware belum ada. GREEN pertama 21/22;
  satu fixture mencoba memindahkan participant lintas cabang tetapi composite FK
  melarangnya. Constraint tidak diubah; tes diperbaiki menjadi penghapusan peserta,
  penghapusan attempt dengan dependennya, serta kombinasi scope asing/ID hilang.
- HTTP GREEN 25/136. Penguatan berikut: Authorization header ganda terlebih dahulu
  **1 RED** (handler masih dipanggil), lalu ditolak; DASS withdrawal tidak memblokir
  IST dan input legacy non-scope tetap kompatibel. Final **27 tes/141 assertions**.
- Focused regression final **149 tes/414 assertions, semua lulus, tanpa skip**:
  `php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit/Auth/AssessmentAccessTokenTest.php tests/Unit/Auth/ParticipantJwtTest.php tests/Feature/Auth/AssessmentSessionAuthorizationTest.php tests/Feature/Auth/ParticipantLoginTest.php tests/Feature/Auth/ParticipantApiAuthorizationTest.php tests/Feature/Auth/AttemptEntitlementGateTest.php tests/Feature/Auth/SettledAssessmentActivationTest.php tests/Architecture/RlsMiddlewareCoverageTest.php --debug`
  Log lokal storage/logs/p8b-wave2-focused-regression.log. Meliputi 31 tes token,
  27 tes HTTP serta regresi gate/aktivasi/legacy dan architecture produksi.
- Pint targeted seluruh 6 file PHP perubahan lulus. PHPStan seluruh paths config
  proyek **0 error**, dengan environment testing SQLite memory seperti di atas.
  Pemeriksaan awal menemukan is_string redundan pada header bertipe string;
  diperbaiki tanpa ignore/baseline/widening type. Log p8b-wave2-phpstan.log.
- Percobaan lebih luas `tests/Unit/Auth tests/Feature/Auth` tidak sepenuhnya lulus:
  **175 tes, 168 lulus, 7 gagal, 479 assertions**. Ketujuh kegagalan adalah render
  halaman Authentication, EmailVerification, PasswordConfirmation, PasswordReset
  (2), Registration, TwoFactorChallenge: Vite manifest public/build/manifest.json
  tidak tersedia di worktree backend. Tidak menambahkan manifest palsu, menonaktifkan
  Vite, atau mengubah shared harness untuk menutupinya. Log p8b-wave2-auth-regression.log.
  Ini batas regresi UI lokal, bukan klaim semua suite hijau.

### Delta baseline dan batas integrasi

Controller ternyata **tracked** pada snapshot worker (`git ls-files` diverifikasi),
bukan untracked. Sebelum edit SHA256 sama dengan file induk yang dibaca read-only:
77613D40EFC71960D2174B56F857C15EDF0FE77DB4AD2DFD34A840737A4229C9.
Delta hanya **21 baris tambahan/9 dihapus**: tipe FormRequest, dependency gate/runner,
dan branch principal; body respons existing tetap. Tidak memasukkan ulang seluruh
baseline 3112e15. File baseline lain tidak disentuh oleh gelombang kedua.

Route assessment hanya didaftarkan di tes menggunakan middleware/controller asli.
Tidak ada alias bootstrap/route/issuer publik, schema, lockfile, key/config,
outbox consumer, invoice atau notifier baru. Route produksi masih participant.jwt
+ rls; token assessment tetap ditolak di sana. Tes architecture produksi lulus,
tetapi **bukan** pengesahan wiring assessment publik: AssessmentPrincipal belum
ProvidesRlsContext, dan pemasangan middleware RLS publik perlu review terpisah.
Harness ini hanya membuktikan lookup scope dan adapter service read-only; tidak
melemahkan RequiresRlsContext atau mengubah kebijakan RLS existing.

Tidak menjalankan PostgreSQL gelombang ini sesuai arahan koordinator: tidak ada
perubahan runner/policy/transaksi write/lock. Bukti HTTP lokal memakai SQLite
memory, bukan bukti endpoint baru pada PostgreSQL runtime atau browser. Engine
nanti wajib mengunci, memuat ulang gate dan membuat sesi dalam transaksi sama;
hasil gate ini tidak boleh disimpan sebagai tiket akses. Consent tetap record
existing per participant+versi yang dievaluasi saat tiap attempt/request.

Review mandiri mencakup purpose setelah decrypt, integritas framework, TTL,
scope persisted, fail-closed revoke/prasyarat, minimisasi respons dan compatibility.
Tidak ada bypass admin, salinan predicate settlement ketiga, atau state mutation.
Replay dalam TTL dan tidak adanya revocation token individual tetap batas eksplisit.
P8b keseluruhan **belum dinyatakan selesai**; public wiring, engine, consumer dan
regresi UI lengkap belum dibuktikan. Berhenti setelah commit lane untuk review,
tidak lanjut P9/P10. Tidak ada push/deploy, reset/merge baseline, atau agent baru.
