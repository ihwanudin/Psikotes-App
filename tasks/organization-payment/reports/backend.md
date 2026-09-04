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

## P9a — preflight: keputusan schema/kontrak diperlukan

Instruksi kelanjutan koordinator setelah integrasi ee7ba62/fe96239 diterima.
parallel-work.md, bagian P9a todo.md, plan.md dan integration-wave-3.md terbaru
dibaca read-only dari induk; spec checkout dan implementasi v1/P5 diperiksa.
728/3367, PG 156/921 dan tujuh tes UI yang telah hijau adalah **bukti koordinator**,
bukan pengujian ulang worker. Baseline worker tetap fe96239, tidak di-reset/merge.

Skill Laravel beserta local implementation guidance, Auth, Security, Database
Schema & Migrations, Postgres, TDD, Incremental dan Git relevan telah tersedia
dan dibaca. Scope provisioning internal tidak mengizinkan perubahan shared
schema/request otomatis; alasan meminta keputusan adalah instruksi eksplisit
koordinator tentang profil parsial/kontrak ambigu, bukan permintaan izin ulang
untuk action yang sudah diotorisasi.

### Temuan yang menghalangi implementasi sesuai acceptance

1. ProvisionCheckoutParticipantRequest menerima profile kosong serta enam field
   nullable: fullName, birthDate, gender, educationLevel, email, phone.
   Migration 2026_08_25_000100_create_tenant_identity_tables.php:44–49 masih
   mewajibkan full_name, gender, birth_date, education_level, intended_field,
   phone (NOT NULL). Tidak ada migration berikutnya yang melonggarkannya.
2. intended_field wajib, tetapi tidak terdapat dalam allowlist profile checkout
   atau pemetaan field pada IntegrationSource/TestPackage. Action v1 mengisi UMUM
   secara literal; menyalinnya akan menganggap bidang peserta tanpa bukti.
   Bahkan payload dengan semua field checkout terisi belum menjawab bidang ini.
3. Payer boleh belum dipilih ketika policy mengizinkan self dan organization.
   ResolvePayerPolicy/PayerDecision secara eksplisit menghasilkan
   selectedPayerType=null dan requiresSelection=true, tetapi
   assessment_participants.funding_mode tetap NOT NULL tanpa default.
   Belum ada kontrak persisted untuk kondisi tersebut. Mengisi self, organization,
   SPONSORED atau sentinel baru diam-diam bukan solusi yang disetujui.

Request, adapter, policy dan kedua migration terkait dicocokkan melalui SHA256
ke induk: kelimanya sama. Pencarian migration induk juga tidak menemukan perubahan
nullable setelahnya, jadi ini bukan akibat worker tertinggal snapshot.
Gate AssessmentAccessPrerequisites sudah menolak profil kurang lengkap, tetapi
itu tidak membuat penyimpanan profil parsial menjadi mungkin pada schema kini.

### Pilihan untuk review koordinator

**A — disarankan: prasyarat schema untuk data belum lengkap.** Tinjau increment
shared terpisah sebelum action P9a: kolom profil yang belum diketahui boleh NULL,
dan representasi payer belum dipilih untuk checkout-v2 ditetapkan eksplisit
(usulan funding_mode NULL khusus attempt checkout-v2 PROVISIONED). Pertahankan
validasi penuh v1/registrasi publik serta check nilai enum ketika nilai tersedia.
intended_field tetap NULL sampai data sah tersedia, tidak diisi UMUM otomatis.
Audit pembaca/model/type yang menganggap profil selalu lengkap, pertahankan gate
akses fail-closed, dan verifikasi migration/constraint/legacy di PostgreSQL
disposable. Ini usulan desain untuk review, **belum izin atau migration jadi**.
P15 tetap wajib mengisi kekurangan sebelum akses. Owner shared perlu menetapkan
scope/file migration dan aturan rollback tanpa memalsukan data yang masih NULL.

**B — scope sementara yang lebih sempit.** Batasi P9a pada profil lengkap dan
payer sudah dipilih, dengan sumber intended_field yang disepakati eksplisit
(field kontrak atau mapping server yang benar). Ini tetap memerlukan review
request/mapping dan perubahan scope; acceptance profil parsial/payer belum
dipilih tetap terbuka. Tidak saya implementasikan sebagai pengganti diam-diam.

Kedua pilihan tidak mengaktifkan route/source, tidak memberikan ready rights,
dan tidak membuat charge/bill/invoice/token/consent/identity verification/outbox.
Sesudah keputusan, action internal dapat dilanjutkan dengan signed client,
reload registry/policy dalam transaksi, unique idempotency/logical attempt,
rollback dan tes race PostgreSQL. Tidak perlu meniru action v1 yang memberi
entitlement ready dan mengantre event provisioning.

### Bukti aktual preflight dan batasnya

- `php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutContractCompatibilityTest.php --filter 'partial_profile|omitted_payer' --debug`
  **3 tes / 16 assertions lulus**; log storage/logs/p9a-contract-preflight.log.
  Ini bukti kontrak existing, bukan implementasi provisioning baru.
- Probe diagnostik disposable storage/logs/P9aSchemaProbeTest.php, memakai
  OrganizationPaymentTestCase + RefreshDatabase dan konfigurasi yang sama:
  **7 tes / 14 assertions lulus**. Tiap probe membuktikan QueryException NOT NULL
  untuk satu dari enam kolom profil atau funding_mode. Log
  storage/logs/p9a-schema-preflight.log. Probe/log diabaikan Git; tidak menjadi
  tes acceptance permanen yang mengunci kelemahan schema.
- Tidak ada query ke DB aktif, migration shared baru, perubahan request/model,
  action baru, test-only route baru, atau perubahan sumber aktif. Probe hanya
  pada SQLite :memory:, bukan bukti PostgreSQL RLS/race P9a.
- PostgreSQL, Pint/PHPStan dan regresi UI tidak dijalankan ulang pada checkpoint
  ini karena belum ada implementasi lane; tidak mengklaim P9a selesai atau hijau.
  Bukti race/atomicity menunggu action sesudah keputusan prasyarat.

Delta commit preflight hanya laporan backend ini. Tidak ada baseline yang
diikutsertakan, agent tambahan atau kelanjutan P10. **Menunggu pilihan/approval
prasyarat schema/kontrak dari koordinator sebelum implementasi P9a.**

## P9a0 — implementasi prasyarat nullable sesuai ADR-004

Opsi A disetujui koordinator; ADR-004, P9a0 todo.md dan bagian terbaru
parallel-work.md dibaca dari induk tanpa ditulis. Ini persetujuan implementasi
lokal, bukan migration database aktif atau izin action P9a/intendedField.
Skills Laravel, Auth, Security, DB/Postgres, TDD, Incremental, Git dan Code Review
dipakai. Tidak ada task/agent tambahan, reset/merge baseline, atau perubahan writer.

### Perubahan dan invariant

- Migration baru 2026_08_31_000600_allow_checkout_partial_profiles.php membuat
  full_name, gender, birth_date, education_level, intended_field, phone nullable.
  Tidak menulis ulang nilai existing atau menambah nomor tes, rights, consent,
  identity verification, credential, charge/bill/invoice/notifikasi.
- funding_mode NULL hanya untuk marker checkout-v2 dan status PROVISIONED,
  REVOKED atau VOID. PostgreSQL memakai CHECK bernama
  assessment_participants_checkout_funding_check dengan COALESCE(..., FALSE).
  SQL NULL, missing key, JSON null, tipe marker salah dan versi lain ditolak.
  Nilai funding non-null mempertahankan perilaku storage legacy existing.
- PostgreSQL memakai ALTER COLUMN DROP/SET NOT NULL, sehingga tipe/panjang,
  check enum, FK, index/unique, default, pemilik tabel dan RLS tidak diubah.
  DDL kedua tabel berada dalam transaksi dengan ACCESS EXCLUSIVE lock sebelum
  preflight/down; perubahan tidak boleh berlomba dengan writer saat restore.
  Lock dapat memblokir trafik: ini bukan klaim migration tanpa downtime.
- Down memeriksa seluruh tujuh kolom sebelum DDL. NULL apa pun yang tidak
  kompatibel menyebabkan penolakan eksplisit tanpa menghapus/mengisi data.
  Preflight PG memakai SET LOCAL row_security=off agar query yang akan difilter
  policy gagal, bukan memberi hasil scan parsial. Ini **tidak bypass RLS** dan
  tidak mengubah policy/config permanen; role migrator yang tidak dapat melihat
  semua baris ditolak. Runtime tetap non-owner/NOBYPASSRLS.
- SQLite memakai rebuild kolom Laravel dalam transaksi, memulihkan pragma FK
  dan memeriksa foreign_key_check sebelum commit. Karena SQLite tidak mendukung
  ADD CHECK, dua trigger INSERT/UPDATE menerapkan predicate funding setara.
  Migration SQLite menolak surrounding transaction sebelum mutasi karena pragma
  FK tidak dapat diubah efektif di dalam transaksi. Ini hanya dialek tes lokal;
  bukti constraint CHECK/RLS produksi menggunakan PostgreSQL.
- PHPDoc kedua model mengikuti nullable; tidak mengubah casts/fillable/reader.
  Gate existing tetap menolak profil parsial. Validasi v1 dan registrasi tetap
  required; optional profile.intendedField belum diimplementasikan.

Rujukan: [PG17 CHECK dan NULL](https://www.postgresql.org/docs/17/ddl-constraints.html),
[PG17 row_security](https://www.postgresql.org/docs/17/runtime-config-client.html#GUC-ROW-SECURITY),
[PG17 table locks](https://www.postgresql.org/docs/17/explicit-locking.html),
[SQLite ALTER TABLE](https://www.sqlite.org/lang_altertable.html) serta
SQLiteGrammar Laravel terpasang (compileAlter). Tidak menambah dependency.

### Bukti aktual

- RED feature sebelum migration: 23 tes, 14 lulus, 1 failure, 8 error. Kasus
  negatif NULL funding sudah ditolak schema lama, sedangkan penyimpanan profil
  parsial/nullable funding dan lifecycle belum tersedia. GREEN: **23/124**.
- Regresi terkait **127 tes/422 assertions lulus**:
  `php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Database/CheckoutPartialProfileSchemaTest.php tests/Feature/Integrations/CheckoutContractCompatibilityTest.php tests/Feature/Integrations/GenericAssessmentProvisioningTest.php tests/Feature/Auth/AttemptEntitlementGateTest.php tests/Feature/Auth/SettledAssessmentActivationTest.php --debug`
- Dua tes HTTP registrasi existing (test_valid_input dan test_invalid_input)
  **2 tes/22 assertions lulus**. Tidak menjalankan ulang tes render yang belum
  memiliki manifest di worker dan tidak membuat manifest palsu.
- PostgreSQL disposable pertama **188 tes/883 assertions lulus**, termasuk 39
  tes lane baru. Runner membersihkan container/network unik miliknya. Startup
  sempat menunggu I/O bind mount Windows (p9_client_rpc), bukan menunggu DB lock.
  Verifikasi akhir menambahkan tes penolakan preflight yang difilter RLS.
- PostgreSQL final: `powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1`
  **189 tes/888 assertions lulus**, termasuk **40 tes lane baru**, tanpa skip.
  PostgreSQL 17 disposable; koneksi runtime non-owner/NOBYPASSRLS. Run final
  selesai dan membersihkan container/network miliknya. Schema feature final
  setelah guard RLS tetap **23 tes/124 assertions lulus**.
- Pint lima file PHP perubahan lulus. PHPStan awal setelah PHPDoc nullable:
  **0 error**; tidak memerlukan perubahan reader di luar ownership.
  Environment eksplisit testing, SQLite memory, DB_URL kosong, cache/session
  array, queue sync. PHPStan final seluruh cakupan proyek setelah guard RLS juga
  **0 error**, dan Pint final kelima file PHP lulus.

Log lokal (tidak dicommit): p9a0-schema-red.log, p9a0-schema-green.log,
p9a0-focused-regression.log, p9a0-registration.log, p9a0-postgres.log,
p9a0-schema-final.log, p9a0-postgres-final.log, p9a0-phpstan-final.log di storage/logs.
Tes DDL owner hanya pada DB disposable bermarker yang diverifikasi; tes constraint,
gate dan lintas tenant memakai psikotes_runtime non-superuser/non-owner/NOBYPASSRLS.
Tidak mengklaim tes owner sebagai bukti otorisasi runtime.

### Handoff delta model dan batas commit

Enam file kerja lane:
1. database/migrations/2026_08_31_000600_allow_checkout_partial_profiles.php (baru).
2. app/Models/Participant.php (PHPDoc saja).
3. app/Models/AssessmentParticipant.php (PHPDoc saja; baseline untracked).
4. tests/Feature/Database/CheckoutPartialProfileSchemaTest.php (baru).
5. tests/Postgres/CheckoutPartialProfileSchemaTest.php (baru).
6. tasks/organization-payment/reports/backend.md.

Participant masih membawa tiga baris Fillable baseline (package_id/source_system/
attribution_source). Hanya hunk PHPDoc yang dimasukkan index; tiga baris baseline
tetap di luar commit. AssessmentParticipant sepenuhnya untracked sejak snapshot:
agar tidak commit ulang baseline, **file itu tidak ditambahkan Git**. Perubahan
lokalnya telah ikut Pint/PHPStan, dan delta tepat terhadap induk adalah:

```diff
--- a/app/Models/AssessmentParticipant.php
+++ b/app/Models/AssessmentParticipant.php
@@ -25 +25 @@
- * @property string $funding_mode
+ * @property string|null $funding_mode
```

Koordinator perlu menerapkan satu baris PHPDoc tersebut pada model tracked di
induk saat integrasi. Ini penyerahan delta eksplisit, bukan izin menyalin seluruh
model/snapshot worker. Selain dua hunk PHPDoc, file baseline lain tidak disentuh.
Status global worktree tetap memuat baseline lama; jangan menafsirkan seluruh
git status sebagai pekerjaan P9a0. Index diperiksa sebelum commit lane saja.

P9a0 belum memberi izin sumber aktif, route publik, perubahan request, action
provisioning, payer writer, pembatalan lifecycle, atau P10. Consumer/engine tetap
di luar scope. Data NULL tetap harus dilengkapi secara sah sebelum akses, dan
rollback atas data tersebut sengaja menolak. **P9a0 siap review lokal dengan
delta model untracked di atas; STOP sebelum action P9a/kontrak intendedField.**

## Increment kontrak intendedField — 2026-09-01

P9a0 diterima koordinator melalui bfc0587 (dari b6589d5); izin berikutnya hanya
request intendedField, tes khusus, dan laporan ini. ADR-004, bagian terakhir
parallel-work.md, dan reports/integration-wave-5.md dibaca dari induk tanpa
diubah. Skill Laravel/Auth/Security/TDD/Incremental/Git yang telah dibaca tetap
digunakan. Tidak ada action P9a, migrasi, controller, route produksi, konfigurasi,
dependency, sumber aktif, atau dokumen kanonik yang diubah.

### Perubahan dan asumsi kontrak

- `profile.intendedField` masuk allowlist checkout-v2 dan memakai nullable,
  string, serta enum existing KAIGO/KENSETSU/NOUGYOU/SEIZOU/GAISHOKU/UMUM.
- Field boleh tidak dikirim atau null. Tidak ada default UMUM, coercion baru,
  atau normalisasi tambahan. Middleware HTTP existing tetap berlaku.
- Pengesahan request tidak memberi hak akses, settlement, identitas/consent,
  atau izin payer. Test memakai middleware HMAC nyata, client persisted sintetis,
  dan adapter checkout/payer existing. Route validasi hanya didefinisikan di tes.
- Request v1 tidak diubah: profil lengkap masih wajib dan intendedField tetap
  merupakan key yang tidak didukung, termasuk ketika null.

### Bukti aktual

- RED sebelum perubahan request: **21 tes, 4 lulus, 17 gagal, 71 assertions**,
  tanpa error runtime. Keenam nilai valid/null ditolak allowlist lama; validasi
  nested field belum ada. Run awal sebelumnya juga menemukan dua error fixture
  `allowed_funding_modes` wajib; fixture dibetulkan sebelum run RED tersebut.
- GREEN tes baru `CheckoutIntendedFieldContractTest`: **21 tes/82 assertions**.
  Mencakup enam enum, missing/null tanpa default, unknown/lowercase, integer,
  float, boolean, list/object, key profile tambahan termasuk paid/consent/
  identityVerified, signature hilang/palsu, client disabled, payer invalid/
  ambigu, opt-in checkout disabled, payer locked, dan kontrak v1.
- Regresi gabungan tes baru + `CheckoutContractCompatibilityTest` +
  `GenericAssessmentProvisioningTest`: **51 tes/218 assertions lulus**.
- Semua PHPUnit memakai `--configuration phpunit.organization-payment.xml`
  dengan testing SQLite memory. Tidak menjalankan DB aktif, PostgreSQL, UI,
  atau full regression; request-only tidak mengubah transaksi/RLS/schema.
- Pint request + tes baru: **lulus**. PHPStan seluruh cakupan proyek:
  **0 error**, environment eksplisit testing/SQLite memory/DB_URL kosong,
  cache dan session array, queue sync. Tidak perlu perubahan reader produksi.

Log lokal tidak dicommit: storage/logs/intended-field-red.log,
intended-field-green.log, intended-field-regression.log, intended-field-phpstan.log.

### Handoff patch request untracked

Sebelum edit, SHA256 request worker dan induk sama:
`322D157EBDB90A44228F29F5D0D2ADFD6C2DD199163281C5A052C50CEC746AA5`.
Request masih baseline untracked pada worker, sehingga **tidak ditambahkan ke
Git**. File lokal dengan patch berikut ikut semua tes/Pint/PHPStan di atas.
Koordinator harus menerapkan delta ini pada request tracked di induk; jangan
menyalin/commit ulang snapshot baseline. Diff terhadap induk hanya dua perubahan:

```diff
--- a/app/Http/Requests/ProvisionCheckoutParticipantRequest.php
+++ b/app/Http/Requests/ProvisionCheckoutParticipantRequest.php
@@ -34,7 +34,8 @@
-            'profile' => ['present', 'array:fullName,birthDate,gender,educationLevel,email,phone'],
+            'profile' => ['present', 'array:fullName,birthDate,gender,educationLevel,email,phone,intendedField'],
             'profile.fullName' => ['nullable', 'string', 'min:2', 'max:200'],
             'profile.birthDate' => ['nullable', 'date_format:Y-m-d', 'before:today'],
             'profile.gender' => ['nullable', 'string', Rule::in(['FEMALE', 'MALE'])],
             'profile.educationLevel' => ['nullable', 'string', 'max:64'],
+            'profile.intendedField' => ['nullable', 'string', Rule::in(['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'])],
             'profile.email' => ['nullable', 'email:rfc', 'max:255'],
             'profile.phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{7,30}$/'],
```

Daftar file lane increment ini:
1. app/Http/Requests/ProvisionCheckoutParticipantRequest.php — delta di atas,
   lokal diuji, baseline untracked tidak masuk commit.
2. tests/Feature/Integrations/CheckoutIntendedFieldContractTest.php — baru.
3. tasks/organization-payment/reports/backend.md — laporan dan patch handoff.

Index diperiksa kosong sebelum staging; commit dibatasi pada tes baru dan
laporan ini. Perubahan baseline lama termasuk model/Fillable tidak disentuh.
Tidak ada baseline induk yang di-reset/merge. **Siap review lokal; STOP sebelum
action P9a maupun increment lain.**

## P9a internal provisioning — 2026-09-01

Koordinator menerima kontrak intendedField melalui dcfd96f dan mengizinkan
action internal + tes feature/PG + laporan. Implementasi ini memakai ADR-004,
acceptance P9a terbaru, request/adapter/policy existing, dan pola mutex organisasi
reservation/activation. Skill Laravel/Auth/Security/TDD/Incremental/Git existing
tetap digunakan; DB/schema dan Supabase Postgres (RLS dan transaksi singkat)
dibaca kembali. Tidak ada schema/request/controller/route/config/v1 baru atau
perubahan baseline pada increment ini. Tidak ada task/agent tambahan.

### Boundary dan semantik yang diimplementasikan

- Entry internal `ProvisionCheckoutParticipant::handle(ProvisionCheckoutParticipantRequest)`.
  Caller wajib mengautentikasi request lebih dahulu dan membentuk service RLS
  context. Action **tidak** memanggil runAsService untuk menaikkan hak sendiri.
  Tanpa context serta participant/branch_admin/staff/psychologist/super_admin
  ditolak sebelum query. FormRequest tervalidasi dan atribut client authenticated
  wajib; Idempotency-Key memakai validator helper existing.
- Dalam transaksi/savepoint, lock branch organisasi lalu reload/lock client,
  source checkout-v2, paket dan satu item paket yang membuktikan tidak kosong.
  Client ID, client_id dan organization_id harus tetap cocok principal yang
  terautentikasi. Adapter existing memeriksa flag opt-in, organisasi, keaktifan/
  masa berlaku client/source/package serta payer policy **sebelum replay**.
- Identity mapping hanya exact organization + source_system + external_candidate_id.
  Email/phone/nama tidak pernah digunakan untuk mencari identitas. Sumber atau
  organisasi berbeda menghasilkan participant berbeda meskipun kontak sama.
  Client pengganti dalam organisasi/source yang sama dapat memakai mapping orang
  untuk round baru; replay attempt milik client lain ditolak. Mapping ambigu,
  participant soft-deleted atau branch tidak cocok gagal tertutup.
- Profil baru menyimpan tujuh nilai sah yang tersedia; absent/null tetap NULL.
  Gender MALE/FEMALE dipetakan eksplisit ke male/female; tidak ada default UMUM.
  Round baru pada mapping yang sama boleh mengisi field yang masih NULL, tetapi
  konflik nilai non-NULL ditolak. Replay tidak menulis profil sehingga data yang
  dilengkapi belakangan tidak dihapus oleh payload awal parsial.
- Attempt baru selalu PROVISIONED, result_version=0, marker server
  metadata.checkout_contract_version=checkout-v2. Hanya metadata cohortCode
  diikutkan. Payer hasil resolver self/organization dipetakan ke funding existing;
  jika resolver belum memilih, funding_mode NULL. Tidak menulis paid/verified/
  consent, nomor tes, credential, entitlement, charge, bill/item, order, outbox,
  audit/notifier atau sesi. Keluaran berisi ID/status/replayed, bukan token/hak.
- Hash request mengurutkan key object; absent vs explicit-null tetap berbeda
  untuk konflik replay. Logical key purpose checkout-v2 mencakup source,
  candidate, process, round dan package (registration ID tetap bagian hash request).
  Key yang sama dengan payload lain ditolak; logical retry dengan key lain memakai
  attempt existing. Key alternatif **tidak** disimpan sebagai alias baru karena
  schema hanya menyimpan satu idempotency key per attempt; client sebaiknya tetap
  mengirim key asli. Package/round berbeda dengan key baru adalah attempt berbeda.
- Lookup memeriksa benturan key client dan logical attempt organisasi sekaligus;
  beda client/tenant/source/package/payer/hash atau dua hasil bertabrakan ditolak.
  Payer berubah akibat policy sesudah provisioning juga konflik, bukan update
  diam-diam. REVOKED/VOID atau revoked_at tidak direplay. Status lain tidak diubah.
  Constraint unique existing tetap backstop; action tidak menangkap semua error
  SQL sebagai replay. Savepoint mencakup participant + attempt: crash setelah
  insert attempt rollback keduanya meskipun caller menangkap exception dan commit.

Mutex organisasi memberi serialization untuk writer ini termasuk saat belum ada
identity row. Ini bukan jaminan terhadap writer eksternal yang tidak memakai
mutex, bukan izin menjalankan v1 dan v2 bersamaan saat cutover, dan bukan bukti
throughput produksi. Transaksi tidak melakukan network I/O. Dasar primitive:
[PostgreSQL 17 row locks](https://www.postgresql.org/docs/17/explicit-locking.html#LOCKING-ROWS)
dan [Laravel 13 Form Requests](https://laravel.com/docs/13.x/validation#form-request-validation).

### Bukti feature dan batas tahap pertama

- RED awal sebelum action: 24 tes, 1 lulus, 7 gagal, 16 error (class action belum
  ada), 8 assertions. Setelah implementasi, tiga assertion memakai nama tabel
  credential yang tidak ada dikoreksi; absence credential dibuktikan dari
  test_number/registration token NULL dan tidak memanggil issuer, bukan tabel palsu.
- Focused awal: **34 tes/125 assertions lulus**. Empat kasus tambahan memeriksa
  key order/collision, client dipindah tenant, client dihapus, dan attempt revoked.
- Focused final (action, CheckoutContractCompatibility, CheckoutIntendedFieldContract,
  GenericAssessmentProvisioning, AttemptEntitlementGate): **133 tes/406 assertions
  lulus**, tanpa skip. Seluruh PHPUnit memakai phpunit.organization-payment.xml,
  testing SQLite memory.
- Regresi direktori integrations sebelum empat tes terakhir: **102 tes, 101 lulus,
  1 gagal, 431 assertions**. Satu kegagalan existing SelectionLaunchTest lobby
  disebabkan public/build/manifest.json tidak tersedia di worker; tidak memalsukan
  manifest/withoutVite atau mengubah harness. Suite direktori penuh tidak diklaim
  lulus. Root memiliki build dan perlu mengulang tes UI itu saat integrasi.
- Pint ketiga file PHP lane lulus. PHPStan seluruh proyek awal mendeteksi dua
  isu (collection call tidak perlu dan match mixed tidak exhaustive); diperbaiki
  dengan query first dan default penolakan gender. PHPStan final **0 error**, env
  testing/SQLite memory/DB_URL kosong/cache dan session array/queue sync.
- Tes PostgreSQL khusus telah ditulis; runner disposable existing sedang berjalan
  saat checkpoint pertama ini, sehingga **belum dihitung lulus**. Observasi startup
  `p9_cli` menunjukkan I/O bind mount, bukan bukti lock DB atau test failure.

File lane: app/Actions/Integrations/ProvisionCheckoutParticipant.php (baru),
tests/Feature/Integrations/CheckoutProvisioningTest.php (baru),
tests/Postgres/CheckoutProvisioningTest.php (baru), dan laporan ini.
Commit tahap pertama hanya action + feature test + laporan; tes PG menunggu bukti
runner sebelum commit kedua. Baseline untracked termasuk request tidak dimasukkan
ke index. Tidak memakai .env/DB aktif, sumber nyata, gateway, atau notifikasi.
Log lokal: p9-action-red.log, p9-action-green.log, p9-action-regression.log,
p9-action-focused-final.log, p9-action-phpstan-final.log, p9-action-postgres.log.

Public wiring, P10 dan lifecycle berikutnya tetap belum dikerjakan. Penyerahan
akhir menunggu hasil PG dan pemeriksaan delta; checklist kanonik tidak diedit.

### P9a hasil final PostgreSQL dan handoff

Commit tahap pertama: **eb53cfd** (action + feature + laporan). Setelah itu tidak
ada perubahan action atau baseline. Tes feature lane dijalankan lagi sendiri:
**38 tes/133 assertions lulus** (p9-action-feature-final.log). Focused gabungan
tetap **133/406**, Pint ketiga file PHP lulus, PHPStan 0 error sebagaimana di atas.

Runner `powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1`
selesai exit 0: **197 tes/997 assertions lulus**, tanpa skip. Ini suite worker,
bukan klaim jumlah suite root yang memiliki lane lain. Delapan tes PG baru:

1. Runtime `psikotes_runtime`, bukan owner, non-superuser dan NOBYPASSRLS,
   menyimpan profil parsial/PROVISIONED tanpa hak akses atau billing.
2. Race key identik menghasilkan satu participant/attempt, retry replay.
3. Race logical retry dengan key berbeda tetap satu attempt.
4. Race dua round berbeda memakai satu exact external identity, dua attempt.
5. Race payload payer konflik menghasilkan satu sukses dan IdempotencyConflict,
   tanpa participant/orphan kedua.
6. Sumber dinonaktifkan saat provisioning menunggu lock: reload menolak,
   participant/attempt tetap nol.
7. Crash setelah insert attempt rollback participant dan attempt ke savepoint,
   walau outer caller menangkap exception dan commit; retry berikutnya berhasil.
8. Lima context non-service ditolak action, participant tidak membaca attempt,
   branch lain tidak membaca participant/attempt, dan tanpa context tidak melihat
   attempt sesudah koneksi dipakai ulang. Context runner pulih setelah denial.

Kelima race memakai pola barrier socket/pcntl existing yang disalin hanya ke tes
lane ini: dua proses dan backend PID berbeda, keduanya diamati menunggu Lock pada
mutex organisasi sebelum parent melepasnya. Bukan tes sekuensial yang diberi
label concurrency. Tidak menambah helper atau mengubah shared harness. Startup
Windows bind mount sekitar empat menit; waktu PHPUnit 59.935 detik, bukan hang
DB. Runner membuang container/network tepat miliknya; pengecekan ulang berdasarkan
label run e6e40047c6014bad97ed9bbfd3a4fdbb menghasilkan nol container/network.

Commit kedua dibatasi pada tests/Postgres/CheckoutProvisioningTest.php dan laporan
ini. Index diperiksa sebelum commit. Empat file lane keseluruhan tercantum di
atas; tidak ada file baseline baru yang berubah sejak 34be1da. Request lokal
dibandingkan ulang dengan request root dcfd96f: identik, tanpa patch tambahan.
Tidak menyertakan snapshot awal atau baseline model/Fillable yang masih dirty.

Batas yang tetap terbuka: satu tes UI lobby pada regresi direktori penuh gagal
karena manifest worker tidak tersedia; tidak diubah atau diklaim lulus. Tes PG
membuktikan transaksi/RLS/race internal, bukan autentikasi HTTP end-to-end atau
kapasitas produksi. Integrasi HTTP berikutnya wajib memakai middleware HMAC nyata,
FormRequest existing, boundary service yang sah dan error response tersanitasi;
action sendiri tidak membuktikan signature dari model caller. Tidak ada public
wiring, gateway, handoff, token, sesi, consent/identity writer, outbox consumer,
migration aktif atau sumber yang diaktifkan. **P9a internal siap review lokal;
STOP sebelum public wiring/P10 atau increment lain.**

## Review fix P9a: replay sesudah pilihan payer — 2026-09-01

Koordinator belum mengintegrasikan eb53cfd/5f4bb3b. Instruksi review meminta
reproduksi dan melarang perubahan semantik bila kontrak belum menentukan kasus
ini, sebelum bukti/opsi diserahkan. **Bagian ini menggantikan klaim siap integrasi
P9a sebelumnya: bug terkonfirmasi, belum diperbaiki/GREEN.** Tidak melanjutkan
P10/public wiring. Skill debugging/TDD digunakan untuk mempertahankan reproduksi;
alasan berhenti sebelum perubahan action adalah instruksi review koordinator,
bukan kebutuhan izin menjalankan tes atau pekerjaan reversible biasa.

### Reproduksi dan hasil aktual

Hanya tests/Feature/Integrations/CheckoutProvisioningTest.php dan laporan ini
diubah. Delapan kasus positif baru:

- Provision payload/key identik tanpa payer, policy awal self+organization,
  funding NULL. Profil awal kosong.
- Simulasikan state lifecycle persisted dengan profil lengkap, funding self atau
  organization, dan status PROVISIONED/READY/IN_PROGRESS/COMPLETED.
- Retry payload/key provisioning semula seharusnya mengembalikan attempt yang
  sama tanpa mengubah profil/funding/status/hash atau menulis efek samping.
- Semua delapan kasus gagal pada `IdempotencyConflict`: funding persisted sudah
  selected, sementara hasil resolver atas payload awal tetap NULL. Test menangkap
  exception spesifik itu dan menghasilkan pesan RED yang terbaca.

Perubahan lifecycle di fixture memakai update sintetis untuk memodelkan state
yang hendak diuji, **bukan** implementasi writer settlement/payer/sesi atau bukti
transisi end-to-end sudah sah. Tidak ada entitlement atau bukti paid diciptakan.

Tes tambahan yang GREEN memverifikasi payload provisioning berubah tetap
ditolak, source SUSPENDED/payer policy kosong ditolak setelah payer dipilih,
dan attempt revoked tetap ditolak tanpa mutasi. Pada kode lama, funding conflict
mendahului alasan revoked; tes mempertahankan denial, tidak menganggap kode error
spesifiknya sudah benar. Guard lama perubahan keputusan karena source lock tetap
ada dan lulus, tidak dihapus atau diperlemah. Regresi lama replay profil yang
dilengkapi, tenant, source, package, key, rollback, dan role tetap berjalan.

Command: `php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutProvisioningTest.php --debug`

Hasil final **RED: 51 tes, 43 lulus, 8 gagal, 169 assertions, tanpa runtime error**.
Log: storage/logs/p9-replay-lifecycle-red.log. Pint file tes lulus; PHPStan
seluruh proyek **0 error** (p9-replay-lifecycle-phpstan.log), environment eksplisit
testing/SQLite memory/DB_URL kosong/cache+session array/queue sync. Tidak menjalankan
PG ulang: tidak ada perubahan action/query/schema/transaksi. Hasil PG 197/997
sebelumnya bukan bukti fix replay. Tidak mengulang UI, mengubah harness, atau
menyembunyikan tes RED dengan skip/expected-exception sukses.

### Celah kontrak yang membutuhkan keputusan sempit

ADR-004 mengizinkan funding NULL saat PROVISIONED dan mengharuskan profil existing
tidak dihapus replay. Kontrak P5 mewajibkan reload policy, tetapi tidak menetapkan
snapshot keputusan awal untuk replay. `request_hash` mengikat input; input tanpa
payer juga dapat menghasilkan payer otomatis akibat lock/satu pilihan policy.
Karena itu hash input tidak membuktikan keputusan awal resolver.

Tes baru `test_stored_request_and_funding_cannot_distinguish_initial_policy_from_later_choice`
mereproduksi dua riwayat dengan transaksi sintetis yang di-rollback di antaranya:

| Riwayat | Keputusan awal | Perubahan kemudian | State yang dapat dibaca replay |
| --- | --- | --- | --- |
| A | NULL, dua payer diizinkan | Lifecycle memilih self; source kemudian lock self | hash input, funding self, metadata marker, policy lock self |
| B | self otomatis dari lock source | Tidak ada pilihan lifecycle | hash input, funding self, metadata marker, policy lock self |

Assertion membuktikan keempat nilai akhir tersebut sama. Histori keputusan awal
tidak tersimpan, sehingga menghapus perbandingan funding atau memberi pengecualian
berdasarkan input payer kosong saja tidak dapat mempertahankan guard keputusan
awal secara konsisten. Bahkan guard lama dapat kehilangan perubahan keputusan
policy bila funding lifecycle kebetulan sama dengan keputusan policy terbaru.

### Proposal untuk review, belum diimplementasikan

**Opsi A (direkomendasikan): snapshot keputusan awal di metadata server.**

1. Pada create saja, simpan field server semisal
   `checkout_initial_funding_mode` yang wajib hadir, nullable, dan tidak berubah
   oleh lifecycle; nilainya hasil resolver awal. Nama/format final perlu disetujui.
   Tidak perlu schema/request baru; metadata input tetap hanya cohortCode.
2. Replay tetap membandingkan request_hash dan semua scope, reload registry,
   jalankan adapter atas input asli. Bandingkan hasil keputusan sekarang terhadap
   snapshot awal, bukan funding lifecycle. Ini mempertahankan guard perubahan
   keputusan policy existing, termasuk pilihan otomatis dari lock/satu payer.
3. Funding lifecycle dinilai terpisah: snapshot NULL boleh tetap NULL atau menjadi
   self/organization yang masih diizinkan oleh PayerDecision existing. Snapshot
   awal selected tidak boleh berubah ke payer lain/NULL. Tidak menduplikasi
   predicate policy atau menganggap funding sebagai bukti pembayaran.
4. Profil/status/funding lifecycle tidak ditulis ulang pada retry. Payload
   berubah, policy tidak sah, scope salah, revoked/void tetap ditolak. Snapshot
   hilang/tipe invalid harus fail-closed, **jangan diinfer dari funding mutable**.
   P9a belum diintegrasikan/dipasang publik, sehingga tidak mengusulkan backfill
   aktif. Perilaku row tanpa snapshot harus eksplisit dalam acceptance berikutnya.

Opsi A menambah kontrak metadata internal dan definisi replay terhadap keputusan
policy awal. Itulah keputusan yang tidak saya buat sepihak. Setelah disetujui,
scope fix tetap action + tes feature/PG + laporan, termasuk race retry yang
menunggu perubahan lifecycle commit dan pengujian snapshot absent/invalid.

**Opsi B: replay dinilai dari input identik dan validitas payer lifecycle terhadap
policy saat ini saja**, tanpa snapshot awal. Ini lebih kecil, tetapi tidak bisa
mempertahankan semantik guard perubahan keputusan awal: case awal NULL lalu
policy memilih payer otomatis perlu didefinisikan ulang. Opsi ini **tidak memenuhi
instruksi mempertahankan guard existing tanpa persetujuan kontrak baru** dan
tidak direkomendasikan. Tidak diimplementasikan sebagai jalan pintas.

Mohon keputusan koordinator atas opsi A, khususnya metadata snapshot server dan
fail-closed untuk snapshot hilang. Commit increment ini hanya reproduksi RED,
regresi negatif, bukti ambiguitas dan laporan; **bukan commit perbaikan siap merge**.
Action/PG/shared files/baseline tetap utuh. Tidak ada .env/DB aktif, network
outbound, migrasi, sumber/gate aktif, reset/merge atau commit snapshot awal.
**STOP untuk review kontrak sebelum RED→GREEN; P9a belum diterima.**

## P9a fix opsi A / ADR-005 — 2026-09-01

Koordinator menyetujui opsi A setelah d0ed7cf. ADR-005
`docs/decisions/0005-checkout-initial-funding-snapshot.md` dibaca read-only dari
root setelah tersedia; instruksi dan implementasi cocok. Tidak mengubah dokumen
kanonik, schema/request/shared files atau public wiring. Skill Laravel, Auth,
Security, DB/Postgres, TDD, incremental dan Git yang telah dibaca tetap dipakai.

### Delta perilaku

- Create menulis metadata server `checkout_initial_funding_mode` dari keputusan
  resolver awal, hanya null/COMMERCIAL_SELF_PAY/INVOICED_TO_ORGANIZATION. Request
  existing tetap melarang field tersebut dari payload. Tidak ada default/infer.
- Replay mewajibkan metadata array, marker checkout-v2, `array_key_exists` untuk
  snapshot (null sah), allowlist tipe/nilai strict, dan keputusan resolver saat
  ini sama dengan snapshot awal. Scope, request_hash, reload/lock registry dan
  policy existing tidak dikurangi. Key hilang/metadata null/tipe invalid/versi
  salah ditolak tanpa backfill, inferensi dari funding mutable atau mutasi.
- Funding lifecycle diperiksa terpisah: initial null boleh tetap null atau payer
  self/organization dalam `PayerDecision::allowedPayerTypes`; initial selected
  harus tetap funding yang sama. Mode legacy seperti SPONSORED bukan pilihan
  lifecycle checkout yang sah. Tidak menyalin predicate resolver/policy baru.
- Replay sah mengembalikan ID/status existing tanpa menulis profil, funding,
  status, snapshot, hash atau efek samping. Revoked/void tetap tertutup. Pilihan
  lifecycle yang kebetulan sama dengan keputusan policy baru tidak lagi menutupi
  perubahan keputusan awal: snapshot null versus resolver self tetap konflik.
- Immutability snapshot adalah kontrak writer aplikasi ADR-005; **bukan constraint
  database baru**. Tidak ada data aktif yang dimigrasikan; writer lifecycle
  berikutnya wajib mempertahankan snapshot. Tidak ada bukti settlement/consent/
  identitas/entitlement dari nilai funding ini.

### TDD dan verifikasi berjalan

Sebelum mengubah action, perluasan tes menghasilkan **RED: 68 tes, 45 lulus,
22 gagal, 1 error, 148 assertions**. Error berasal dari cabang revoked yang masih
tertutup oleh IdempotencyConflict lama; assertion metadata create serta replay
lifecycle juga RED. Log p9-initial-funding-red.log. Implementasi awal kemudian
**GREEN 68 tes/358 assertions** (p9-initial-funding-green.log).

Tes riwayat ambigu lama tidak di-skip: diubah menjadi pembuktian hash/funding/
policy akhir sama tetapi snapshot awal berbeda (NULL versus self otomatis).
Tes negatif lama dipertahankan. Tambahan mencakup payload snapshot ditolak,
snapshot absent/invalid/metadata null, keputusan policy berubah termasuk funding
lifecycle kebetulan sama, initial selected berganti/di-null-kan, dan funding legacy.
Replay positif mencakup kedua payer dengan profil lengkap dan semua status dari
PROVISIONED sampai FINALIZED; initial selected juga direplay tanpa menulis snapshot.
State lifecycle disiapkan sebagai fixture sintetis, bukan writer/engine baru.

Focused terkait (P9a feature + CheckoutContractCompatibility +
CheckoutIntendedFieldContract + GenericAssessmentProvisioning +
AttemptEntitlementGate): **171 tes/725 assertions lulus**. PHPUnit selalu memakai
phpunit.organization-payment.xml, testing SQLite memory. Pint tiga file PHP lulus;
PHPStan seluruh proyek **0 error** dalam env eksplisit testing/SQLite memory,
DB_URL kosong, cache+session array, queue sync. Tidak ada perbaikan harness.

Empat kasus PG baru ditambahkan dalam file P9a existing: retry menunggu commit
pilihan self dan organization, race lifecycle yang bertepatan perubahan policy,
serta snapshot absent/invalid/version mismatch tanpa backfill. Barrier dua proses
existing dipakai tanpa modifikasi helper atau runner. Runner disposable sedang
berjalan pada saat catatan ini ditulis; hasil akhirnya dicatat di bawah, bukan
menggunakan ulang angka PG sebelumnya. Regresi integrations penuh juga berjalan;
batas manifest Vite worker tetap akan dilaporkan jika kegagalan UI yang sama muncul.

Ownership tetap empat file: app/Actions/Integrations/ProvisionCheckoutParticipant.php,
tests/Feature/Integrations/CheckoutProvisioningTest.php,
tests/Postgres/CheckoutProvisioningTest.php, dan reports/backend.md ini.
Baseline/overlay dipertahankan; tidak reset/merge/commit snapshot. Tidak ada .env,
DB aktif, schema, route, source activation, outbound nyata, public wiring atau P10.

### Hasil final ADR-005 dan commit fix

- Feature P9a sendiri: **76 tes/452 assertions lulus**, tanpa skip
  (p9-initial-funding-feature-final.log).
- Focused terkait: **171 tes/725 assertions lulus**
  (p9-initial-funding-focused.log).
- Direktori tests/Feature/Integrations penuh: **144 tes, 143 lulus, 1 gagal,
  758 assertions** (p9-initial-funding-integrations.log). Kegagalan tunggal
  `SelectionLaunchTest::test_participant_lobby_is_available_without_exposing_server_data`
  masih ViteManifestNotFoundException: public/build/manifest.json tidak ada pada
  worker. Tidak mengubah withoutVite, manifest palsu, harness atau tes UI itu.
  Suite penuh tidak diklaim GREEN; root perlu memakai build sah saat integrasi.
- PostgreSQL runner established exit 0: **201 tes/1.058 assertions lulus**,
  tanpa skip (p9-initial-funding-postgres.log). Seluruh test runtime memakai
  psikotes_runtime non-owner/non-superuser/NOBYPASSRLS, diverifikasi dalam suite.
  Empat kasus baru lulus: dua payer lifecycle commit lalu retry, policy berubah
  meskipun lifecycle funding cocok, dan snapshot hilang/invalid/versi salah.
  Tiga race baru memakai dua proses/backend PID berbeda dengan observed lock
  wait dan barrier yang sama. State committed tidak di-reset oleh replay.
- Waktu PHPUnit PG 29.976 detik setelah startup bind mount Windows; bukan bukti
  throughput aplikasi. Runner cleanup selesai. Pengecekan ulang label run
  59bdbd0ce2164824884968eb9071c36d menunjukkan nol container/network tersisa.
- Pint final ketiga file PHP **lulus**, PHPStan seluruh proyek **0 error**.
  Diff check lulus. Log hanya lokal, tidak dicommit.

Delta action hanya 18 baris tambah/3 hapus: snapshot create dan pemisahan dua
guard replay. Tes riwayat lama direvisi menjadi pembuktian snapshot, bukan
di-skip; semua delapan reproduksi awal GREEN. Empat file ownership saja masuk
commit fix sejak d0ed7cf. Index sebelumnya kosong dan diperiksa ulang; tidak ada
baseline untracked/model/Fillable atau overlay induk yang ikut commit.

Tidak ada schema baru, backfill, immutable DB constraint, writer lifecycle,
HTTP endpoint, token, gate publik, pembayaran, consent, notifikasi, atau P10.
Keberhasilan replay tidak membuktikan status akses/settlement dari fixture;
gate akses tetap bertanggung jawab memeriksa bukti persisted pada endpointnya.
**Fix ADR-005 siap review lokal; STOP sebelum integrasi/public wiring/P10.**

## P9b adapter HTTP tanpa route produksi — 2026-09-01

Koordinator menerima rangkaian P9a dan ADR-005 pada root; increment ini hanya
controller HTTP, tes baru khusus, pembaruan kontrak P5/P9, dan laporan. Skill
Laravel/Auth/Security/TDD/Git existing digunakan. Tidak mengubah action P9a,
middleware, request, bootstrap, route, config, schema, v1 atau sumber aktif.

### Perilaku dan boundary

- Controller baru `CheckoutParticipantProvisioningController` memakai FormRequest
  dan action P9a existing. Route hanya ada dalam tes, menggunakan alias nyata
  `integration.client`; tidak ada route produksi yang didaftarkan.
- Atribut IntegrationClient terautentikasi wajib ada sebelum service context
  dibuat. Controller menggunakan `RlsContextRunner::run(new RlsContext('service'))`,
  bukan runAsService yang dapat menaikkan context admin. Context existing ditolak
  dengan 403; tanpa client 401. Controller bukan verifier HMAC tersendiri: wiring
  berikutnya wajib memasang middleware existing, tidak cukup membuat atribut model.
- Sukses 201 create/200 replay hanya mengirim `data.participantId` sebagai string,
  `assessmentAttemptId`, `assessmentStatus`. Tidak ada PII/profil, metadata,
  snapshot, funding, credential, token, checkoutURL atau sesi. Status persisted
  adalah proyeksi, bukan bukti pembayaran atau izin akses.
- IdempotencyConflict dipetakan 409/IDEMPOTENCY_CONFLICT; exception kontrak memakai
  code/status existing (termasuk 422 key invalid dan 503 opt-in OFF). Pesan generic
  tidak menyalin input, PII, credential, metadata atau exception message.
- Respons yang **dibuat controller**, sukses dan contract error, memakai
  `Cache-Control: no-store, private`. Auth rejection dan validation error berhenti
  sebelum controller dan tetap memakai pipeline existing; no-store seluruh
  pipeline tidak diklaim. Handler api/* existing memberikan VALIDATION_FAILED
  generic. Error tak terduga tetap dirender framework dengan APP_DEBUG=false;
  tidak menambah catch-all atau mapping status/error baru.
- Context/transaksi dipulihkan setelah sukses, contract error dan exception
  tak terduga. Tes crash setelah insert membuktikan rollback dan retry berikutnya
  berhasil. Tidak ada observer/notifier/gateway baru; efek samping tetap milik
  action existing yang sudah membatasi provisioning ke participant/attempt.

### Bukti aktual

- RED sebelum controller: **17 tes/17 error**, class controller belum ada,
  tanpa assertions; p9b-http-red.log. Tes menjadi GREEN setelah adapter dibuat,
  kemudian ditambah kasus crash dan replay lifecycle.
- HTTP final: **19 tes/229 assertions lulus**, tanpa skip (p9b-http-green.log).
  HMAC nyata memakai secret sintetis in-memory, raw body dan timestamp. Mencakup
  missing/malformed/wrong signature, body tampered, malformed/stale timestamp,
  client disabled sesudah create, opt-in OFF, tenant/source/package mismatch,
  key missing/conflict, metadata authority ditolak, create/replay/proyeksi minimal,
  lifecycle replay, no rights/bill/outbox, serta denial direct caller/context.
- Focused regresi HTTP baru + P9a + kontrak checkout + intendedField + provisioning
  v1 + gate assessment: **190 tes/954 assertions lulus**, tanpa skip
  (p9b-http-regression.log). Seluruhnya menggunakan konfigurasi
  phpunit.organization-payment.xml, testing SQLite memory.
- Pint kedua file PHP **lulus**. PHPStan seluruh proyek **0 error**, env eksplisit
  testing/SQLite memory/DB_URL kosong/cache+session array/queue sync
  (p9b-http-phpstan.log). Tidak perlu perubahan reader/shared atau dependency.
- Tidak menjalankan PG baru karena tidak mengubah query/schema/action/primitive
  RLS; tes HTTP ini tidak diklaim sebagai bukti PostgreSQL controller. Tidak
  mengulang UI/full application suite: manifest Vite worker tetap tidak tersedia.
  Angka root 880/4652 dan PG 222/1659 adalah bukti koordinator, bukan run increment ini.

### Dokumen baseline untracked dan handoff patch

docs/ORGANIZATION_CHECKOUT_CONTRACT.md masih untracked sejak snapshot worker.
Sebelum edit, SHA256 worker/root identik:
`C9D7299B2E633316F7225C2F67EED884A53A4EC190235F42632AEB0377B01883`.
File lokal diperbarui untuk intendedField/ADR-004, snapshot ADR-005, action versus
adapter/endpoint, response/status/no-store dan batas error sebelum controller.
Tautan ADR merujuk dokumen canonical yang tersedia di root, tidak disalin menjadi
baseline baru di worker. Agar tidak commit ulang snapshot, dokumen **tidak di-add**;
koordinator menerapkan patch berikut ke file tracked di root.

```diff
--- a/docs/ORGANIZATION_CHECKOUT_CONTRACT.md
+++ b/docs/ORGANIZATION_CHECKOUT_CONTRACT.md
@@ -1,10 +1,13 @@
-# Kontrak checkout opt-in (P5)
+# Kontrak checkout opt-in (P5/P9)

 ## Status dan batas

-Kontrak internal `checkout-v2` disiapkan untuk provisioning P9. Belum tersedia
-endpoint checkout publik. Validasi/adaptasi tidak membuat participant, attempt,
-invoice, entitlement, consent, atau outbox. Harga bukan input integrasi.
+Kontrak internal `checkout-v2` memiliki action P9a yang menyimpan participant dan
+attempt PROVISIONED secara atomik/idempotent. Request dan adapter policy sendiri
+tetap tidak melakukan persistence. Adapter HTTP P9b disiapkan tanpa registrasi
+route produksi: endpoint checkout publik belum tersedia dan P9 belum live.
+Tidak membuat invoice, entitlement, consent, credential, token atau outbox.
+Harga bukan input integrasi.

 ## Input

@@ -14,8 +17,11 @@ ## Input
   `organizationCode`, `externalCandidateId`, `assessmentPackageCode`, `profile`.
 - ID opsional: `externalProcessId`, `externalRegistrationId`, `assessmentRoundId`.
 - `profile` menerima hanya fullName, birthDate, gender, educationLevel, email,
-  phone. Object kosong/field null diperbolehkan sebagai profil parsial; nilai
-  yang diberikan tetap wajib valid. Ini bukan bukti identitas atau persetujuan.
+  phone, intendedField. Object kosong/field null diperbolehkan sebagai profil
+  parsial; nilai yang diberikan tetap wajib valid. intendedField opsional/nullable
+  dengan nilai KAIGO/KENSETSU/NOUGYOU/SEIZOU/GAISHOKU/UMUM, tanpa default UMUM.
+  Missing/null tetap belum diketahui. Ini bukan bukti identitas atau persetujuan;
+  kontrak v1 tidak berubah. Lihat [ADR-004](decisions/0004-checkout-partial-profile.md).
 - `payerType` opsional/null atau tepat `self`/`organization`. Tanpa pilihan,
   resolver P3 dapat memilih satu pilihan efektif atau meminta pilihan peserta.
 - `fundingMode` hanya untuk adapter legacy eksplisit: COMMERCIAL_SELF_PAY → self,
@@ -25,7 +31,7 @@ ## Input
 - `metadata` hanya menerima cohortCode. Field tambahan pada root/profile/
   metadata ditolak, termasuk paid, amount, branchId dan consent.
 - Idempotency-Key wajib valid sebelum provisioning P9; request helper memeriksa
-  karakter/ukuran, tetapi P5 tidak mengklaim kunci atau membuat respons replay.
+  karakter/ukuran, action P9a memeriksa konflik dan replay secara transaksional.

 Validasi memakai Form Request dan allow-list array seperti
 [dokumentasi Laravel](https://laravel.com/docs/13.x/validation#validating-arrays).
@@ -39,8 +45,54 @@ ## Gerbang dan keluaran
 `CheckoutContractAdapter::resolve(client, source, package, input)` menerima input
 tervalidasi serta model registry yang dipetakan server. Ia memeriksa versi,
 pemetaan organisasi/sumber/paket, lalu memakai ResolvePayerPolicy. Keluaran
-PayerDecision bukan izin tes atau lunas. Pemanggil persistence berikutnya wajib
-reload registry dalam transaksi/RLS dan memenuhi gate identitas/consent/harga.
+PayerDecision bukan izin tes atau lunas. Action P9a me-reload registry dalam
+transaksi/service RLS sebelum create maupun replay; akses tetap memerlukan gate
+identitas/consent/settlement terpisah.
+
+## Persistence dan keputusan funding awal (P9a)
+
+Identitas dipetakan lewat organisasi/source/external candidate tepat, tidak
+digabung lintas organisasi lewat email/telepon. Profil parsial sah disimpan tanpa
+placeholder, attempt baru selalu PROVISIONED. Replay tidak mengosongkan profil
+yang telah dilengkapi dan tidak mengubah status/funding lifecycle.
+
+Sesuai [ADR-005](decisions/0005-checkout-initial-funding-snapshot.md), create menulis
+metadata server checkout_contract_version=checkout-v2 dan
+checkout_initial_funding_mode. Key snapshot awal wajib hadir, dengan nilai tepat
+null/COMMERCIAL_SELF_PAY/INVOICED_TO_ORGANIZATION dari resolver. Payload tidak
+boleh memasok keduanya; metadata input tetap hanya cohortCode. Snapshot tidak
+diubah replay/lifecycle; ini kontrak aplikasi, bukan constraint immutable DB baru.
+
+Replay mencocokkan scope/hash dan keputusan resolver terkini dengan snapshot
+awal, lalu memeriksa funding lifecycle secara terpisah terhadap PayerDecision.
+Initial null boleh dipilih kemudian; initial selected tidak boleh berubah/null.
+Snapshot hilang/invalid atau versi salah gagal tertutup tanpa infer/backfill.
+Policy tidak sah, keputusan awal berubah, revoked/void tetap ditolak. Nilai ini
+bukan bukti paid, persetujuan, identitas atau entitlement.
+
+## Adapter HTTP belum dipasang (P9b)
+
+CheckoutParticipantProvisioningController memakai ProvisionCheckoutParticipantRequest
+dan action P9a; route hanya didaftarkan oleh tes sintetis. Wiring mendatang wajib
+memasang middleware `integration.client` existing yang memverifikasi HMAC atas
+timestamp dan raw body. Controller bukan pengganti signature verifier. Context
+service dibentuk setelah autentikasi; context peserta/admin yang sudah aktif
+tidak dinaikkan haknya. Context dan transaksi dipulihkan pada sukses/error.
+
+Response sukses hanya `data.participantId` (string), `assessmentAttemptId`, dan
+`assessmentStatus`: HTTP 201 untuk create, 200 untuk replay. Tidak mengirim profil,
+metadata, funding snapshot, credential, token atau checkoutURL. Status adalah
+proyeksi existing, bukan izin mulai tes. Respons controller (sukses maupun error
+kontrak) memakai Cache-Control: no-store, private.
+
+Conflict menghasilkan HTTP 409/IDEMPOTENCY_CONFLICT; key missing/invalid
+422/IDEMPOTENCY_KEY_REQUIRED; exception kontrak memakai code/status existing.
+Pesan controller generik dan tidak menyalin exception/payload. Autentikasi gagal
+dan validasi FormRequest terjadi sebelum controller, tetap memakai middleware/
+handler JSON existing (termasuk VALIDATION_FAILED pada path api/*). Header error
+awal dan error tak terduga tetap milik pipeline existing; P9b tidak menambah
+no-store global atau mengubah shared auth. Error tak terduga memakai renderer
+framework dengan debug dimatikan, bukan contract-error mapping baru.

 Kesalahan memakai IntegrationContractViolation: CHECKOUT_NOT_ENABLED (503),
 CHECKOUT_CONTRACT_REQUIRED/INTEGRATION_CONTEXT_INVALID (403),
@@ -66,11 +118,11 @@ ## Cutover tanpa fallback
 Cabang/sumber lain yang belum dipindahkan tetap memakai perilaku v1 existing.
 Guard hanya boleh dijalankan dalam service RLS context. Tanpa context tersebut,
 ia melempar LogicException sebelum query, bukan menganggap hasil yang disembunyikan
-RLS sebagai tidak ada penanda. Kedua action provisioning menyiapkan context ini.
+RLS sebagai tidak ada penanda. Kedua action provisioning legacy menyiapkan context ini.
 Data historis/akses tes existing tidak diubah; guard ini khusus provisioning,
 bukan pencabutan hak lama. Cutover konkuren tidak dilakukan saat writer aktif:
-jeda ingress dan drain request/worker sebelum perubahan registry; uji race
-reservasi merupakan tahap berikutnya, bukan klaim P5.
+jeda ingress dan drain request/worker sebelum perubahan registry. Bukti race
+reservasi/provisioning internal tidak menjadi izin cutover pada sumber aktif.

 Threat model: cegah caller memilih organisasi lain, menyuntik paid/harga,
 menggunakan label sponsored sebagai akses gratis, atau downgrade/replay lewat
@@ -79,8 +131,10 @@ ## Cutover tanpa fallback

 ## Pemanggil P9 dan checkpoint

-P9 wajib menghubungkan request → reload registry → adapter → persist PROVISIONED
-dalam transaksi, dengan idempotensi atomik dan gate publik terpisah. Flag P5
-bukan sakelar untuk membuka endpoint yang belum dibuat. Pemindahan sumber aktif
-memerlukan persetujuan terpisah dan runbook setelah alur end-to-end terbukti.
+P9a internal dan adapter HTTP P9b belum berarti route publik terdaftar atau
+checkout end-to-end tersedia. Tes P9b memakai HMAC dan database sintetis; bukan
+izin cutover. Flag P5 bukan sakelar untuk membuka endpoint yang belum dipasang.
+Pemindahan sumber aktif memerlukan persetujuan terpisah dan runbook setelah alur
+end-to-end terbukti. No-store untuk seluruh pipeline error sebelum controller
+perlu ditinjau pada tahap wiring; jangan mengklaim header controller mencakupnya.
 Lihat [bukti checkpoint](ORGANIZATION_CHECKOUT_VALIDATION.md).
```

Daftar file lane: app/Http/Controllers/CheckoutParticipantProvisioningController.php
(baru), tests/Feature/Integrations/CheckoutProvisioningHttpTest.php (baru),
docs/ORGANIZATION_CHECKOUT_CONTRACT.md (patch untracked di atas), dan laporan ini.
Index diperiksa kosong sebelum staging; commit hanya controller + tes baru +
laporan yang membawa delta dokumen, bukan snapshot lama. Tidak reset/merge root,
menyalin .env/data/cache aktif, menjalankan transaksi nyata atau spawn task.

Tidak diperlukan perubahan shared untuk respons privat yang dibuat controller.
Jika tahap wiring mengharuskan no-store juga pada seluruh early-auth/validation/
unexpected error response, perubahan pipeline perlu scope/review tersendiri;
P9b tidak memperluas ownership untuk itu. **Siap review adapter HTTP lokal dan
patch dokumen; STOP sebelum registrasi route produksi/gate aktif/P10.**

## P9c — boundary privacy HTTP checkout, belum diregistrasi produksi

Increment ini mengikuti review coordinator atas 835a622/e3d0b74 dan instruksi
P9c terbaru. Parallel-work, todo, plan, ADR-004 serta ADR-005 root dibaca
read-only; tidak ada reset/merge/rebase baseline worker. Skill Laravel,
Auth/Tenant, Security, TDD, Incremental dan Git yang tersedia tetap menjadi
rujukan. Scope hanya middleware baru, tes khusus, dan laporan ini.

`PreventCheckoutResponseCaching` mengatur `Cache-Control: no-store, private`
pada respons downstream. Middleware harus mendahului `integration.client`
ketika nantinya wiring disetujui. Dalam increment ini urutan tersebut hanya
terdaftar pada route sintetis tes; tidak ada alias/global middleware/route
produksi yang diubah. Controller P9b, HMAC, FormRequest, action provisioning,
exception handler, konfigurasi, dan jalur v1 tidak diedit.

Dasar implementasi adalah source framework Laravel terpasang:
`Illuminate/Routing/Pipeline.php::handleException()` memanggil handler asli
`report()` lalu `render()` untuk exception downstream sebelum respons kembali
melalui middleware luar. Boundary tidak catch exception, tidak membuat body
error sendiri, dan tidak meniadakan reporting. Status, JSON body, serta header
selain Cache-Control dibiarkan mengikuti pipeline existing.

Tes route-only menggunakan HMAC dengan secret sintetis in-memory, request dan
controller asli. Control route tanpa boundary membandingkan body/status untuk
penolakan signature/client disabled, validasi 422, opt-in OFF 503, throttle 429,
dan konflik 409. Create 201/replay 200 tetap hanya mengandung tiga field data,
tanpa hak, bill/charge/order, consent/identity verification atau outbox.

Kasus unexpected 500 menyuntik exception sintetis sesudah INSERT attempt di
dalam transaksi, dengan APP_DEBUG=false dari XML testing. Handler asli tetap
melaporkan objek exception yang sama tepat sekali; respons persis
`{"message":"Server Error"}` dan tidak memuat nama/email/secret/payload/SQL/
trace sintetis. Participant dan attempt rollback, level transaksi dan konteks
RLS pulih, serta retry berikutnya berhasil. Tes juga menjalankan route v1
existing: create READY/replay dan penolakan signature tetap kompatibel, tanpa
menambahkan no-store ke route tersebut. Semua data sintetis; tidak ada consumer
atau transaksi eksternal nyata.

TDD: scaffold middleware pass-through menghasilkan RED **9 tes, 4 lulus,
5 gagal, 91 assertions**, exit 1. Kelima kegagalan khusus header no-store pada
malformed/disabled auth, validation, throttle, dan 500; tidak ada fixture error.
Setelah penambahan header, focused GREEN **9 tes/137 assertions**, exit 0.
Log lokal: `storage/logs/p9c-privacy-red.log` dan
`storage/logs/p9c-privacy-green.log` (tidak di-commit).

Verifikasi akhir aktual, semuanya exit 0:

- PHPUnit memakai `--configuration phpunit.organization-payment.xml`:
  **199 tes/1.091 assertions**, gabungan CheckoutPrivacyHeadersTest,
  CheckoutProvisioningHttpTest, CheckoutProvisioningTest,
  CheckoutContractCompatibilityTest, CheckoutIntendedFieldContractTest,
  GenericAssessmentProvisioningTest dan Feature/Auth/AttemptEntitlementGateTest.
  Log lokal `storage/logs/p9c-privacy-related.log`.
- Pint `--test` terhadap kedua file PHP baru: lulus.
- PHPStan seluruh project `analyse --no-progress --memory-limit=1G` dengan
  APP_ENV=testing, SQLite :memory:, DB_URL kosong, cache/session array:
  **0 error**. Log lokal `storage/logs/p9c-privacy-phpstan.log`.
- Index kosong sebelum staging; staging/commit dibatasi tiga file lane:
  `app/Http/Middleware/PreventCheckoutResponseCaching.php`,
  `tests/Feature/Integrations/CheckoutPrivacyHeadersTest.php`, dan laporan ini.
  Snapshot awal staged/untracked tidak ikut commit.

Batas: jaminan header berlaku pada pipeline route downstream dari boundary.
Error routing/global middleware yang terjadi sebelum boundary atau kegagalan
di handler framework sendiri tidak dicakup oleh middleware route ini. Public
wiring masih memerlukan review urutan tersebut; tidak ada klaim P9 publik live.
Tidak menjalankan PG ulang karena tidak ada perubahan query, schema, RLS atau
primitive transaksi; bukti rollback di increment ini berasal dari SQLite
memory. Tidak menjalankan seluruh UI suite dengan manifest worker yang belum
tersedia, dan angka regresi root dari coordinator bukan hasil run worker ini.

**Siap review P9c lokal; STOP sebelum registrasi route produksi, gate/source
aktif, P9 publik atau P10.**

## P10a — lookup invoice internal berdasarkan merchant reference tetap

P9c 5417326 diterima coordinator sebagai root 451cc73. Instruksi berikutnya
mengizinkan P10a internal saja, bukan P9 publik/E2E atau writer P10b. Plan,
todo, parallel-work serta ADR-004/005 root dibaca read-only. Skill API/interface,
Laravel (termasuk local guidance), Security, Queues/Webhooks dan TDD digunakan;
stack tetap Laravel 13.26.1, tanpa dependency/config/schema baru.

Audit menemukan hanya XenditProvider dan FakePaymentProvider mengimplementasikan
PaymentProvider. Tidak ditemukan anonymous implementation/mock interface yang
memerlukan perluasan massal. Consumer existing CreateRegistrationInvoice,
ReconcilePendingXenditPayments, webhook controller dan binding provider dibaca;
tidak ada consumer/writer yang diubah atau mulai memakai lookup baru.

### Kontrak dan implementasi

`PaymentProvider::lookupInvoice(string $merchantReference, int $amount,
string $currency): PaymentInvoice` adalah operasi terpisah dari create/status/
expire/webhook. Input reference ASCII 1–64 karakter, amount integer positif,
currency IDR sesuai domain create existing. Invalid input melempar
InvalidArgumentException generik sebelum HTTP, termasuk amount nol/negatif,
reference kosong/terlalu panjang/injection/newline dan currency tidak didukung.
Tidak memakai CreateInvoiceRequest karena recovery harus menerima invoice yang
expiry-nya sudah lampau tanpa membutuhkan description atau expiry baru.

Satu hasil valid mengembalikan PaymentInvoice existing; bukan event paid, izin
akses, atau bukti settlement. Empty/ambiguous/malformed/mismatch/transport/config
failure melempar PaymentProviderException dengan pesan tetap
`Invoice lookup outcome is unknown.` tanpa previous exception/provider payload.
Unknown tidak pernah menjadi izin POST ulang. Tidak ada automatic retry, create,
expire, DB write, queue atau notifikasi di lookup.

Xendit memakai GET `/v2/invoices?external_id=<reference>&limit=2`, dengan request
builder existing (fixed HTTPS host, Basic auth, connect/total timeout, tanpa
redirect). Tidak memakai filter status/tanggal yang bisa menyembunyikan kandidat.
Respons harus HTTP 200, JSON array dengan tepat satu objek. Dua kandidat, termasuk
satu yang amount-nya salah, atau foreign result bersama exact match tetap unknown.
Tidak memilih kandidat pertama atau melakukan pagination/retry mutatif.

Reference, amount dan currency diperiksa exact sebelum DTO dibuat. Lookup juga
memvalidasi provider ID, status yang dikenal, HTTPS URL host Xendit tanpa
userinfo/port, serta expiry bertipe timestamp dengan zona eksplisit dan tanggal
kalender sah. JSON object dengan key numerik tidak disamakan dengan array.
Parser lookup sengaja terpisah dari helper recovery create legacy: tidak mengubah
semantik legacy yang mengambil kandidat pertama setelah create gagal.
Fake membaca record yang sudah ada, memakai guard input/mismatch dan unknown
yang sama, serta mempertahankan seluruh state pending/paid/expired pada lookup.

### Verifikasi dokumentasi provider

Sebelum menambahkan HTTP request baru, dokumentasi resmi SDK Xendit dibaca tanpa
credential. [InvoiceApi resmi](https://github.com/xendit/xendit-php/blob/master/docs/InvoiceApi.md)
menyatakan GET `/v2/invoices`, filter external_id, limit dan hasil array Invoice.
[Source SDK resmi](https://raw.githubusercontent.com/xendit/xendit-php/master/lib/Invoice/InvoiceApi.php)
mengonfirmasi Basic auth; tidak memasang SDK atau mengirim request ke API payment.
[Model Invoice resmi](https://github.com/xendit/xendit-php/blob/master/docs/Invoice/Invoice.md)
memuat field identitas, amount, currency, status, URL dan expiry ISO8601.
Endpoint dokumentasi `docs.xendit.co/apidocs/get-invoices` tidak dapat dibaca oleh
tool, sehingga rujukan yang benar-benar diverifikasi adalah repo resmi tersebut.

Kebijakan fail-closed aplikasi lebih sempit daripada seluruh tipe SDK: hanya IDR
integer JSON diterima, konsisten dengan parser create/status existing. String atau
float amount (termasuk `350000.0`) tidak dikoersi. Ini belum bukti response akun
provider nyata. Satu observasi lookup juga bukan jaminan uniqueness global atau
read-after-write consistency provider; claim/unknown/reconciliation tetap perlu
ditangani writer P10b/P10c kelak tanpa menganggap empty sebagai aman recreate.

### Bukti TDD dan regresi aktual

- RED pertama: 47 tes/4 assertions, 47 undefined-method errors sebelum kontrak
  dan method lookup ditambahkan; exit 1 (`p10a-lookup-red.log`).
- RED boundary lanjutan: dua tes membuktikan provider ID newline dan named-zone
  expiry semula diterima. Keduanya diperketat khusus lookup, bukan parser legacy;
  2 tes/2 assertions, 2 gagal, exit 1 (`p10a-lookup-boundary-red.log`).
- RED review offset: parser Carbon menerima `+99:99`; 1 tes/1 assertion gagal,
  exit 1 (`p10a-lookup-offset-red.log`). Guard format lookup menolak offset di
  luar rentang jam/menit tanpa mengubah parsing legacy.
- GREEN akhir lookup: **52 tes/232 assertions**, exit 0
  (`p10a-lookup-final.log`). Seluruh tes baru memakai Http::fake dan
  preventStrayRequests; setup fake kosong tidak menelan fixture per skenario.
  Timeout/redirect guards, exact GET/filter/Basic auth, no POST/expiry, sanitasi
  unknown serta state fake tidak berubah dibuktikan tanpa outbound nyata.
- Regresi focused: **147 tes/541 assertions**, exit 0. Files:
  AssessmentInvoiceLookupTest, PaymentProviderContractTest, XenditProviderTest,
  PaymentDataValidationTest, XenditWebhookTest, XenditStatusReconciliationTest,
  PaymentWebhookProcessorTest, PaymentEventApplierTest, AssessmentBillReservationTest
  dan AssessmentBillPreviewTest (`p10a-lookup-focused-regression.log`).
- Percobaan regresi luas Unit/Payments + Feature/Payments + Feature/Registration,
  exclude-group sandbox: **278 tes, 272 lulus, 6 gagal, 1.189 assertions**, exit 1
  (`p10a-lookup-related.log`). Keenam kegagalan memuat ViteManifestNotFoundException:
  ManualPaymentProofUploadTest received page; PackageSelectionTest dua screen;
  ParticipantRegistrationTest screen; PaymentMethodSelectionTest dua screen.
  Tidak di-skip, tidak membuat fake manifest/mengubah harness, dan tidak diklaim
  lulus. Regresi root yang mempunyai build tetap diperlukan saat review.
- Pint `--test` empat PHP file lane lulus. PHPStan seluruh project dengan
  APP_ENV=testing, SQLite :memory:, DB_URL kosong, cache/session array:
  **0 error**, exit 0 (`p10a-lookup-phpstan.log`). Semua PHPUnit memakai
  phpunit.organization-payment.xml; log lokal di storage/logs tidak di-commit.

### Delta dan batas serah-terima

Lima file lane: PaymentProvider.php, XenditProvider.php, FakePaymentProvider.php,
tests/Feature/Payments/AssessmentInvoiceLookupTest.php, dan laporan ini. Tiga
shared PHP files sudah tracked dan bersih sebelum edit; blob baseline worker
dan root sama, berurutan interface/Xendit/fake:
`56d94b2184d0ad6f8c4279ab679d52f1a2646d9e`,
`5435376fed4da63f43a63fc22aeacbad228e6919`,
`f4b47559e796bf07909d367e0449ec3134c7292c`.
Tidak perlu patch untracked untuk increment ini. Index kosong sebelum staging;
commit hanya lima path di atas, tanpa snapshot lama/reset/merge/rebase root.

Tidak ada PG run karena tidak ada query/schema/transaksi/RLS yang diubah.
Tidak ada .env/DB aktif, credential nyata, payment sandbox/live call, deployment,
push, route/gate/source ON, consumer/notifier, atau task/agent baru. HTTP fake
membuktikan kontrak adapter, bukan interoperabilitas akun Xendit nyata. Return
PaymentInvoice tidak memutasi settlement atau hak. Legacy retry policy tetap
existing dan tidak menjadi pola claim/recovery baru secara otomatis.

**P10a internal siap review dengan batas regresi UI di atas; STOP sebelum P10b,
P11, integrasi writer/reconciliation atau public wiring.**

## P10b-prep — proposal issuance, tanpa implementasi

P10a a1808f1 diterima coordinator sebagai ce9b3a0. Full root **988 tes/5.569
assertions**, Pint/PHPStan lulus menurut review coordinator dan wave-13; termasuk
enam halaman yang gagal karena manifest worker. Ini bukti root, bukan run ulang
worker. Instruksi berikutnya membatasi lane pada dua dokumen, bukan writer/job.

Proposal baru: [backend-invoice-issuance-proposal.md](backend-invoice-issuance-proposal.md).
Plan/todo/parallel-work terbaru, wave-13, ADR-004/005 root dibaca read-only.
Skill API/interface, queues/webhooks, DB/schema dan security digunakan; audit
schema/model/constraints/RLS, P7 reservation, P8b activation, outbox consumers,
payment-method toggle, provider legacy/P10a, RlsContextRunner, queue config dan
source DatabaseTransactionsManager terpasang dilakukan tanpa DB aktif.

Usulan untuk keputusan review:

- Reuse bill statuses + outbox topic khusus, tanpa schema baru: claim
  reserved->issuing membuat pending/0 intent; satu worker consume izin
  processing/1 lalu commit sebelum satu create. Counter tidak pernah reset.
- Dispatch hanya setelah outer commit, dengan outbox durable untuk crash sebelum
  enqueue. Lease/stale tidak menjadi izin POST kedua; celah consume-commit
  sebelum POST secara konservatif menjadi unknown bila worker mati.
- Reuse satu create legacy dengan lookup ketat P10a sebelum attach pending;
  legacy fallback first-match dan retry registration lima menit tidak diwarisi.
  Boundary create-once terpisah disajikan sebagai alternatif yang perlu review.
- Urutan organization->bill/items->attempt/participant/package/charge->method
  ->outbox konsisten dengan mutex P7/P8b; registry dikunci setelah organization
  sebelum bill bila dipakai. Harga tetap snapshot, bukan repricing katalog.
- Guards service/scope/policy/metode/free/corrupt/terminal, late response, timeout,
  rollback dan reconciliation dipisahkan dari settlement/hak tes. OFF sebelum
  consume memblokir; OFF setelah consume tidak diklaim bisa membatalkan remote.
- Pembagian P10b-a claim dan P10b-b issuance/job serta 17 kelompok matriks tes
  feature/PG dua proses, outer commit fisik, outbox/rollback/late issuer disusun.
  Usulan requested expiry awal 24 jam dan retensi intent perlu keputusan review.

Verifikasi increment ini **read-only review + git diff --check**, bukan PHPUnit,
PG, Pint/PHPStan atau bukti implementasi GREEN baru. Hanya Markdown berubah;
tidak menulis tes yang meniru dokumen. Sumber resmi Laravel queues dan PostgreSQL
locking dicocokkan dengan source installed; rujukan tercantum dalam proposal.
Tidak ada writer/job/schema/route/config yang diedit, .env/DB aktif, network
payment, deploy/push, consumer/notifikasi nyata, baseline reset/merge atau task
baru. File enum AssessmentBillStatus belum ada; proposal tidak menganggapnya
sudah tersedia atau membuatnya sebagai bagian audit.

Index kosong sebelum staging. Commit dibatasi proposal baru dan laporan ini;
tidak menyertakan baseline snapshot. **STOP untuk keputusan proposal sebelum
implementasi P10b-a/P10b-b atau schema/ADR baru.**

## P10b-a — claim intent invoice internal, belum ada network/consumer

### Kontrak yang diimplementasikan

ADR-006 root dibaca penuh dan menjadi batas slice. Action baru
`ClaimAssessmentBillInvoice` hanya dapat dipanggil dalam explicit service RLS
context. Action mengambil mutex organization, registry source/client, bill dan
item, lalu attempt/participant/package/charge serta payment method dengan urutan
yang konsisten terhadap P7/P8b. Tidak ada principal/admin/browser input yang
dipakai sebagai authority.

Claim pertama memvalidasi persisted tenant, payer, bill reference/hash/key,
jumlah item dan sum, composite linkage, price/policy snapshot, status attempt,
revocation, checkout-v2 source, current opt-in/policy serta channel Xendit aktif.
Manual transfer mengembalikan `not_applicable`. Free/zero, terminal, corrupt,
settled atau payer tak konsisten ditolak sebelum write. Metadata ADR-005 wajib
memiliki key initial funding; nilainya tidak ditulis atau diubah. Hash identitas
attempt mendeteksi perubahan seluruh identifier provisioning tanpa menyalin
candidate PII ke payload.

Transaksi yang valid menulis tepat satu intent topic
`assessment.bill.invoice-issuance`, pending/attempts=0, mengubah reserved menjadi
issuing dan menulis audit `assessment_bill.invoice_claimed`. Dedup key mengikat
purpose/version/organization/bill. Payload versi1 mengikat bill, metode,
reference, nominal/currency, ordered item linkage, price/policy/initial-funding,
claim timestamp dan requested expiry. Retensi outbox/audit ditetapkan dua tahun.
Tidak ada insertOrIgnore, job dispatch, HTTP/provider lookup/create, permit
consumption, settlement, consent, credential, session, order atau entitlement.

Replay mencari intent melalui dedup identity maupun aggregate identity, lalu
membandingkan payload canonical dan hash terhadap persisted state. Missing,
duplicate, changed key/topic/snapshot/expiry, invalid counter, atau kombinasi
bill/outbox tak konsisten gagal tertutup. Pending/0 pada issuing hanya replay;
processing/1 atau unknown/1 hanya `recovery_required`, tidak membuat/reset intent.
Durasi dan expiry tersimpan tidak diperpanjang saat replay. Policy, revocation,
scope dan channel tetap direload sebelum hasil replay; hasil action bukan izin
POST provider.

### TDD dan bukti aktual

- RED awal: **53 tes, 0 lulus, 219 assertions**, action belum ada sehingga seluruh
  skenario claim gagal/error (`p10ba-claim-red.log`). Ini bukan GREEN parsial.
- Feature claim final: **60 tes, 60 lulus, 444 assertions**
  (`p10ba-claim-green2.log`). Mencakup service-only roles, create/replay,
  self/organization payer, manual not-applicable, corrupt sum/count/linkage,
  overflow, config/expiry representability, policy/channel/revoke, lost/corrupt
  intent, recovery state, rollback outbox/audit dan legacy consumer ignore.
- Disposable PostgreSQL run pertama setelah test PG ditambah: **209 tes, 1.324
  assertions**, lulus dan cleanup. Run matriks yang diperluas kemudian menemukan
  satu error tes karena model OutboxMessage memang guarded; fixture diperbaiki
  memakai `forceFill`, bukan production guard dilonggarkan. Hasil final dicatat
  sebagai **210 tes, 210 lulus, 1.353 assertions**, cleanup selesai
  (`p10ba-postgres-final2.log`).
- Regresi terkait Unit/Payments + Feature/Payments + Feature/Integrations:
  **490 tes, 487 lulus, 2 gagal, 1 skip, 2.635 assertions**
  (`p10ba-regression.log`). Dua kegagalan adalah
  `ManualPaymentProofUploadTest` received page dan `SelectionLaunchTest`
  participant lobby, keduanya `ViteManifestNotFoundException`. Worker tidak
  mempunyai `public/build/manifest.json`; tidak dibuat fake manifest dan hasil
  tidak diklaim lulus. Koordinator perlu menutup dua UI itu pada root ber-build.
- PHPStan seluruh project dengan testing SQLite environment: **0 error**
  (`p10ba-stan.log`). Pint final untuk action dan dua test file lulus.

Tes PostgreSQL memverifikasi runtime `psikotes_runtime` bukan owner,
`rolsuper=false`, `rolbypassrls=false`; no-context/admin/participant/staff/
psychologist tidak dapat memanggil action atau menulis outbox. Dua proses
independen mempunyai PID backend berbeda dan diamati `wait_event_type=Lock`.
Pada commit, waiter mereplay message ID sama; pada rollback outer, waiter membuat
satu message ID baru dan tidak ada orphan audit/outbox. Unique dedup/composite FK
serta CHECK currency tetap aktif. Corrupt sum/count/linkage/overflow/expiry dan
initial funding replay semuanya ditutup. Consumer legacy mengembalikan nol.

### Patch config untracked dan batas serah-terima

`config/assessment_billing.php` adalah baseline untracked worker dan **tidak
distage**. Patch exact yang perlu diterapkan koordinator terhadap file root:

```diff
 return [
     // Operational batch limit only; prices remain in the package catalog.
     'max_items' => 100,
+    'invoice_duration_hours' => 24,
 ];
```

SHA-256 sebelum patch `EAED639067AF55B6F6A888BDDF02540EC2B181FC59A3BDF2D95D45BEC3FA549F`;
sesudah patch `7D63AFD3ECABE14653166C9AFC879DFE7D7A8D4003F41830EE7403DE827557B3`
(Git blob sesudah `a1c3c825dad36e83cc1a15fddab674d615bc2b78`). Config harus strict
positive integer dan kedua horizon tanggal representable; replay memakai durasi
snapshot, bukan config baru.

Lane commit hanya action, feature test, PostgreSQL test dan laporan ini. Tidak
ada schema/request/route/controller/job/consumer/provider/config global lain,
.env/data aktif, outbound nyata, migration, source/gate ON, deploy, push, reset,
merge/rebase baseline atau task/agent baru. **STOP setelah P10b-a untuk review;
P10b-b/network tetap memerlukan instruksi baru.**

## P10b-b — permit penerbitan sekali pakai, tanpa dispatcher

### Batas dan implementasi

ADR-007, wave-15, ADR-006, proposal issuance, P7/P8b, kontrak provider dan P10a
dibaca penuh. Increment menambah `IssueAssessmentBillInvoice` sebagai entrypoint
internal dan DTO in-memory `AssessmentInvoicePermit`; tidak menambah job karena
belum ada dispatcher/queue wiring dan handler langsung sudah membuktikan crash
boundary. Satu-satunya input runtime adalah persisted `message_id`.

Entrypoint menolak RLS context apa pun dan `DB::transactionLevel() != 0` sebelum
membaca state. Fase permit membuka service transaction sendiri. Outbox hanya
dipakai sebagai routing hint tanpa lock; action claim P10b-a kemudian mengambil
organization mutex dan memvalidasi ulang canonical intent, tenant, bill/items,
price/policy/funding, current opt-in/channel/policy/revoke. Hanya issuing +
pending/attempts=0 + requested expiry masih future yang berubah atomik menjadi
processing/attempts=1 bersama audit `invoice_permit_consumed`. Return DTO hanya
terjadi setelah transaksi service fisik selesai dan context kembali kosong.
Method `consume(message_id)` sengaja menjadi testable crash boundary; DTO tidak
boleh diserialisasikan menjadi izin retry.

Fase network berjalan dengan context null dan transaction level0. Tepat satu
`createInvoice` memakai reference/amount/currency/description/expiry dari intent.
Baik create return maupun melempar, handler memanggil `lookupInvoice` P10a dengan
reference, amount dan currency yang sama. Hasil create dan lookup harus memiliki
provider reference sama; lookup mismatch/empty/ambiguous/error/timeout atau
expiry yang sudah lewat menjadi unknown. Unexpected exception dilaporkan dengan
pesan sanitasi tanpa menempelkan payload/credential dan tetap tidak membuka izin
create kedua.

Fase persist membuka service transaction baru dan mengulang lock organization ->
registry -> bill/items -> attempt/participant/package/charge -> method -> intent.
Payload digest, identity hash, initial funding, price/policy snapshot, item/charge
linkage, sum/count, payer, method dan scope dicocokkan dengan permit. Exact result
menulis gateway reference/URL/expiry + bill pending + intent processed/attempts1
+ audit secara atomik. Unknown menulis bill unknown + intent failed/attempts1 +
kode generik `INVOICE_OUTCOME_UNKNOWN`. Counter tidak pernah reset. Exact late
result hanya dapat melewati state issuing/processing atau unknown/failed yang
masih identik; paid/terminal, reference existing, corrupt/lost/changed state dan
processed replay tidak ditimpa. Replay processed yang canonical hanya
`recovery_required` dan nol provider call.

Tidak ada settlement, `settled_at`, consent, identity verification, entitlement,
order, credential, session, notification, atau hak ready. Policy/channel/revoke
OFF sebelum permit memblokir create. Perubahan setelah permit tidak dianggap
mampu membatalkan remote request; persist tetap mengikat exact accounting result
tanpa memberi akses. Topic tetap diabaikan consumer legacy.

### TDD dan bukti aktual

- RED awal: file feature memuat 20 skenario dan gagal karena class handler belum
  ada (`p10bb-red.log`). Error lanjutan pada run RED berasal dari teardown setelah
  kegagalan pertama, sehingga tidak diklaim sebagai 20 bukti perilaku terpisah.
- Focused final handler + claim P10b-a: **82/82 tes, 717 assertions**
  (`p10bb-focused-final.log`). Issuance sendiri mempunyai 22 cases termasuk
  batch10, create exception + exact lookup, create success + lookup unknown,
  mismatched refs, real Xendit adapter dengan HTTP fake, crash setelah permit,
  ambient transaction/semua role, tenant/policy/channel/revoke/expiry/corrupt/
  lost guards, paid late response, processed replay dan consumer legacy.
- Real adapter test memakai secret sintetis in-memory dan `Http::fake` +
  `preventStrayRequests`: tepat **1 POST + 1 GET strict**, tanpa request liar.
  Mock tests membuktikan create maksimal sekali dan lookup tepat sekali; sepuluh
  item tidak menghasilkan sepuluh create.
- PostgreSQL disposable final: **214/214 tes, 1.424 assertions**, cleanup selesai
  (`p10bb-postgres-final2.log`). Test baru menjalankan dua process/runtime backend
  berbeda, keduanya teramati menunggu organization lock. Tepat satu winner
  issued/create/lookup dan satu loser recovery; attempts tetap1. Audit failure
  rollback mengembalikan pending/0 dan melepas lock, lalu retry valid hanya satu
  create. Crash boundary processing/1 terlihat dari koneksi baru dan tidak
  direarm. Runtime `psikotes_runtime` non-owner, non-superuser, NOBYPASSRLS;
  ambient service/user/admin ditolak.
- Regresi terkait Unit/Payments + Feature/Payments + Feature/Integrations sebelum
  tambahan satu foreign-scope case terakhir: **511 tes, 508 lulus, 2 gagal,
  1 skip, 2.896 assertions** (`p10bb-related.log`). Dua kegagalan tetap halaman
  `ManualPaymentProofUploadTest` received dan `SelectionLaunchTest` lobby karena
  `ViteManifestNotFoundException`; worker tidak membuat fake manifest. Semua 82
  focused cases termasuk tambahan terakhir kemudian lulus. Review root dengan
  build perlu menutup dua UI tersebut.
- PHPStan seluruh project testing environment: **0 error**
  (`p10bb-stan-final.log`). Pint empat file PHP lane lulus setelah formatting;
  `git diff --check` lulus. Diagnosis generic `collect(mixed)` diperbaiki dengan
  validasi list dan typed item map, tanpa ignore/baseline/type widening.

### Files dan batas serah-terima

Lima file lane: action handler, DTO permit, feature test, PostgreSQL test dan
laporan ini. Tidak ada shared untracked file yang diedit; config durasi dari
P10b-a tetap baseline dan tidak distage ulang. Tidak ada provider interface/
adapter/fake, schema/migration, job, route, console, scheduler, config, source,
gate, consumer, notifier, credential/.env/data aktif, outbound nyata, deploy,
push, reset/merge/rebase baseline atau task/agent baru.

P10b-b ini membuktikan permit dan persistence lokal, bukan delivery queue,
exactly-once provider, production recovery atau settlement. **STOP untuk review;
P10c/P11/public wiring tetap memerlukan instruksi berikutnya.**

## P10c-a — rekonsiliasi satu intent tanpa create ulang

### Kontrak dan implementasi

ADR-008 dan wave-16 dibaca penuh bersama ADR-006/007, plan/todo/parallel,
proposal issuance, lookup P10a, claim P10b-a dan issuance P10b-b. Increment ini
menambah entrypoint internal `ReconcileAssessmentBillInvoice` dengan satu input
persisted `message_id`. Tidak ada command, job, dispatcher, scheduler atau route.

Entrypoint menolak ambient RLS context dan transaksi sebelum membaca state.
Preflight service transaction memakai routing hint outbox lalu menjalankan claim
canonical yang sama untuk mengunci/reload organization, registry, bill/items,
attempt/participant/package/charge, payer, current policy/channel/revoke dan
intent. Hanya pasangan issuing + processing/attempts1 tanpa error atau unknown +
failed/attempts1 dengan `INVOICE_OUTCOME_UNKNOWN` yang lolos. Pending0,
processed, paid/expired/rejected, corrupt/lost, foreign scope, policy/channel OFF
dan revoked berhenti sebelum provider. Transaksi dan context selesai sebelum
network.

Fase network hanya memanggil satu `lookupInvoice(reference, amount, currency)`;
kode action tidak memiliki jalur `createInvoice`. Exact result, termasuk expiry
provider yang sudah lewat, mempertahankan expiry asli dan menempelkan reference/
URL lewat `PersistAssessmentInvoiceOutcome`. Ini penting karena lookup P10a
secara sengaja dapat menemukan invoice expired dan recovery tidak boleh
memperpanjang expiry. Empty/ambiguous/mismatch/timeout/error diperlakukan unknown.

Boundary persist P10b dipindahkan utuh ke service bersama agar issuance dan
reconciliation memakai lock, snapshot/item/linkage checks dan late-state fence
yang sama. Issuing/processing unknown berubah atomik ke unknown/failed1 dengan
satu audit. Unknown/failed1 existing mengembalikan unknown tanpa update timestamp,
reset counter, perubahan expiry atau audit tambahan. Exact dari kedua pasangan
recoverable menjadi bill pending + intent processed/attempts1 + satu audit.
Paid/terminal/changed state setelah GET tidak ditimpa. Persistence dan audit satu
transaksi; kegagalan audit menggulung balik invoice fields dan status.

Tidak ada settlement, consent, identity verification, entitlement, order,
credential, notification atau hak assessment. Consumer legacy tetap mengabaikan
topic invoice issuance.

### Bukti aktual

- Selama ekstraksi boundary, regresi issuance pertama menemukan **8 error**
  `undefined audit` dari 22 tes (14 lulus/163 assertions). Helper audit permit
  dikembalikan ke action issuance; ini menangkap regresi refactor sebelum final.
- Focused reconciliation final: **26/26 tes, 256 assertions**. Bukti mencakup
  crash-state exact, unknown exact, empty/error/mismatch pada kedua state,
  repeated unknown stabil, Xendit `Http::fake` GET-only/no POST, expiry provider
  lama tanpa extension, invalid/terminal states, ambient roles/transaksi, current
  policy/channel/revoke, corrupt/lost/foreign, late paid, rollback persistence dan
  consumer legacy.
- Regresi P10a lookup + P10b claim/issuance + P10c reconciliation:
  **160/160 tes, 1.205 assertions** dengan konfigurasi
  `phpunit.organization-payment.xml` SQLite memory.
- PostgreSQL disposable runtime non-owner/NOBYPASSRLS: **216/216 tes,
  1.473 assertions**, cleanup selesai. Dua process benar-benar overlap pada
  organization lock. Exact menghasilkan satu persistence/audit dan nol create;
  unknown menghasilkan satu transisi/audit dan nol create. Total lookup 1 atau 2
  tergantung jadwal lock; P10c-a tidak mengklaim lease atau global single-lookup.
- Pint seluruh lima file PHP yang disentuh lulus. PHPStan seluruh project dengan
  environment testing eksplisit lulus **0 error**. `git diff --check` lulus.

### Files dan batas

Delta lane terdiri dari `IssueAssessmentBillInvoice` (refactor pemanggil),
`PersistAssessmentInvoiceOutcome`, `ReconcileAssessmentBillInvoice`, feature test,
tambahan race di test PostgreSQL issuance, dan laporan ini. Commit dipisahkan
karena total enam file: core + feature proof, lalu PG proof + laporan. Baseline
dirty/untracked, termasuk config shared, tidak distage ulang.

Tidak ada perubahan provider interface/adapter/fake, schema/migration, config,
route/console/scheduler, source/gate, credential/.env/data aktif, outbound nyata,
deploy, push, reset/merge/rebase atau task/agent baru. P10c-a hanya single-intent;
discovery, durable lease, operasional scheduler dan observability tetap P10c-b.
**STOP untuk review sebelum P10c-b/P11.**

### Review fix — canonical `last_error` pada late-state fence

Review koordinator menemukan shared persistence masih menerima setiap pasangan
unknown/failed tanpa mengikat kode error. Predicate boundary sekarang eksplisit:
issuing/processing hanya canonical bila `last_error` NULL, sedangkan
unknown/failed hanya canonical bila `last_error` tepat
`INVOICE_OUTCOME_UNKNOWN`. Predicate yang sama dipakai untuk hasil exact dan
unknown/null. State lain melempar conflict; action reconciliation mengembalikan
`recovery_required` tanpa update atau audit.

Tes baru membuktikan state awal unknown/failed dengan error noncanonical berhenti
sebelum provider. Dua race memutasi bill/message setelah preflight, di dalam
callback lookup: exact dan exception/unknown. Sebelum fix keduanya RED dengan
hasil aktual `issued` dan `unknown` (**29 tes, 27 lulus, 2 gagal, 278 assertions**).
Sesudah fix keduanya `recovery_required`, mempertahankan error noncanonical dan
tidak menulis gateway, outcome audit atau perubahan state tambahan.

Bukti final review fix: issuance + reconciliation **51/51 tes, 565 assertions**;
regresi lookup/claim/issuance/reconciliation **163/163 tes, 1.241 assertions**;
PostgreSQL disposable **216/216 tes, 1.473 assertions** dengan cleanup selesai.
Pint file delta dan PHPStan seluruh project lulus. Tidak ada perubahan PG test,
schema, provider, wiring, atau perilaku P10b canonical. **STOP untuk review.**

## P10c-b0 — proposal bounded discovery dan durable lease

Audit proposal-only selesai. Field outbox existing tidak aman di-overload:
`available_at` terikat canonical ke `claimedAt`, attempts/status/processed/expiry
memiliki arti issuance/terminal, dan `updated_at` tidak memiliki owner fence serta
dipakai stale recovery legacy. Advisory/cache lock juga bukan authority durable.

Review lock-order merevisi acquisition menjadi dua transaksi. Fase 1 sangat
pendek dan outbox-only memakai `FOR UPDATE SKIP LOCKED` untuk memasang UUID
provisional + expiry tanpa counter atau authority provider. Setelah commit, fase
2 memakai urutan organization-first existing untuk full canonical validation,
memeriksa token terakhir, lalu conditional increment lookup counter dan permit.
Hint invalid dibersihkan token-fenced tanpa provider/counter/audit atau dibiarkan
expire bila crash. Dengan pemisahan transaksi ini tidak ada outbox-first lock yang
dibawa menuju organization dan tidak ada klaim non-blocking palsu.

Rekomendasi schema tetap migration additive: PostgreSQL/Laravel native UUID
(SQLite text), `timestampTz` expiry/cooldown, dan smallint counter CHECK 0..100.
Index/query menangani nullable due tanpa predicate waktu volatil. Late persist
wajib token+generation-fenced; worker lama gagal setelah expiry/steal. Rolling
schema-first/default-OFF dan rollback drain-first dijelaskan di proposal.

State machine, API internal, config bounds, observability tanpa PII, rollback
refusal, risiko consumer legacy, matriks SQLite/PG dua proses, dan pembagian
increment <=5 file ada di
`reports/backend-invoice-reconciliation-lease-proposal.md`. Increment ini tidak
mengubah kode/schema/config/command/provider dan tidak menjalankan test karena
hanya dua dokumen. **STOP untuk review ADR/migration sebelum implementasi.**

## P10c-b1 — kontrak schema durable reconciliation lease

ADR-009 diimplementasikan sebagai migration additive yang tetap nonaktif. Empat
kolom outbox baru adalah UUID lease nullable, expiry dan cooldown `timestampTz`
nullable, serta counter `unsignedSmallInteger` default 0. PostgreSQL menegakkan
pasangan token/expiry, counter 0..100, isolasi metadata untuk topic selain
`assessment.bill.invoice-issuance`, dan active lease hanya pada aggregate
`AssessmentBill` canonical: attempts1, belum processed, serta processing tanpa
error atau failed dengan `INVOICE_OUTCOME_UNKNOWN`. Partial discovery index tidak
memakai `now()`/`CURRENT_TIMESTAMP`. SQLite hanya mendapat ordinary index dan
tidak diklaim membuktikan CHECK atau concurrency PostgreSQL.

Rollback melakukan satu preflight sebelum DDL. Token, expiry, cooldown, atau
counter nonzero mana pun membuat rollback gagal dengan error eksplisit; tidak ada
kolom/index/constraint yang sudah terhapus dan tidak ada metadata yang diisi atau
dibuang. Roundtrip populated legacy dan invoice mempertahankan kolom/data lama.
Model menambah PHPDoc/cast immutable timestamp dan integer counter.

Config lokal menambah default bounded `batch=25`, `scan=100`, `lease=60`,
`cooldown=300`, `max=12` dengan komentar bahwa schema/config tidak mengaktifkan
command, scheduler, atau source. Karena `config/assessment_billing.php` merupakan
shared baseline untracked pada snapshot worker, file itu sengaja tidak distage.
Patch yang perlu diterapkan koordinator adalah blok lima key tersebut setelah
`invoice_duration_hours`; SHA-256 file lokal lengkap:
`3F422A7DBA8420326AB5B1E564BBF1C1E5A2AF32F64871D2D262D4DD005EB9C9`.

### Bukti aktual

- RED sebelum migration/model/config: **6 tes, 1 failure + 5 errors, 7
  assertions**; kolom, defaults, casts, index, config dan rollback belum ada.
- Focused SQLite final: **6/6 tes, 41 assertions**. Regresi schema terkait
  payment operations + checkout partial profile: **37/37 tes, 185 assertions**.
- PostgreSQL disposable final: **231/231 tes, 1.539 assertions**, cleanup sukses.
  Test baru berjalan melalui runtime `psikotes_runtime` non-owner,
  NOBYPASSRLS; 13 direct-SQL negative cases mencakup setiap constraint, dan
  owner transaction membuktikan index definition, up/down roundtrip serta
  refusal down atomik.
- Pint lima file lokal lulus. PHPStan seluruh project dengan environment testing
  eksplisit/SQLite memory lulus **0 error**. `git diff --check` lulus.

Tidak ada acquisition/permit/action/provider call, command/job/scheduler/route,
RLS policy, schema historis, source/gate, credential/.env/data aktif, outbound,
deploy atau push. P10c-b1 hanya kontrak schema; **STOP untuk review sebelum
P10c-b2/P11**.

## P10c-b2a — reservasi provisional hint outbox-only

Increment ini menambah `ReserveAssessmentInvoiceReconciliationHints` dengan
entrypoint internal `execute(remainingBatch, remainingScan)`. Caller wajib tanpa
ambient RLS context dan tanpa outer transaction. Action memvalidasi seluruh
config ADR-009 beserta relasinya, serta meminta remaining batch/scan positif dan
tidak melebihi config; jumlah kandidat maksimal adalah minimum keduanya.

Satu service transaction singkat membaca database clock dan hanya memilih row
`outbox_messages`. Filter membatasi topic invoice, aggregate `AssessmentBill`,
attempts1, processed NULL, pasangan processing/null-error atau
failed/`INVOICE_OUTCOME_UNKNOWN`, counter di bawah maksimum, cooldown NULL/due,
serta lease kosong atau expired. Urutan deterministic adalah cooldown NULL,
cooldown due, lalu id. PostgreSQL memakai `FOR UPDATE SKIP LOCKED`; SQLite hanya
membuktikan semantik. Update conditional menulis UUID dan expiry saja, tanpa
menyentuh `updated_at`, available/status/attempts/processed/error/next/counter,
audit, organization, bill, registry, policy atau provider. Transaction commit
selesai sebelum list DTO dikembalikan.

DTO `ProvisionalAssessmentInvoiceLease` hanya membawa message ID, UUID token, dan
expiry immutable. Token ini bukan permit validator, provider authority, hak
create/rearm, settlement, atau entitlement.

### Bukti aktual

- RED feature sebelum class ada: **19 tes, 19 errors, 19 assertions**.
- Focused feature final dengan SQLite memory: **19/19 tes, 82 assertions**.
  Bukti mencakup bounds/config, order dan database clock, seluruh ineligible
  hints, active skip, expired reclaim, rollback, ambient context/transaction,
  business columns unchanged, nol audit dan tidak ada query organization/bill/
  registry.
- Regresi lookup/claim/issuance/reconciliation/reservation: **182/182 tes,
  1.322 assertions**.
- PostgreSQL disposable final: **235/235 tes, 1.594 assertions**, cleanup sukses.
  Empat tes baru menjalankan runtime `psikotes_runtime` non-owner/NOBYPASSRLS:
  dua process mendapat set disjoint dan bounded; worker melewati row outbox yang
  dikunci dan selesai saat organization lock masih ditahan; active/cooldown/max/
  ineligible skip; expired reclaim mendapat token baru tanpa counter; synthetic
  failure menggulung balik token. Tidak ada deadlock.
- Pint empat file kode/tes dan `git diff --check` lulus. PHPStan seluruh project
  dengan environment testing eksplisit/SQLite memory lulus **0 error**; scoped
  check setelah perubahan assertion terakhir juga nol error.

Tidak ada perubahan schema/config/model existing, validator canonical, claim,
provider GET/POST, persistence outcome, command/job/scheduler/route, source/gate,
credential/.env/data aktif, outbound, deploy atau push. **STOP sebelum
P10c-b2b/P10c-b3/P11**.

## P10c-b2b — validasi canonical dan permit ber-token rotasi

Increment ini menambah `ValidateAssessmentInvoiceReconciliationLease` dengan
input tunggal DTO provisional P10c-b2a. Entrypoint menolak ambient RLS context,
outer transaction, message ID bukan ULID, token bukan UUID, dan seluruh config
ADR-009 yang invalid. Ia tidak menerima organization/bill dari caller.

Fase 2 membuka service transaction baru. Routing hint dibaca tanpa lock dan hanya
dipakai untuk menemukan organization/bill; seluruh authority canonical tetap
didelegasikan ke `ClaimAssessmentBillInvoice::execute`, sehingga urutan lock
organization-first serta predicate payer/policy/snapshot/item/linkage tidak
disalin. Hanya hasil `recovery_required` untuk message yang sama dilanjutkan.
Outbox dikunci terakhir lalu diperiksa ulang terhadap topic/aggregate/linkage,
pasangan issuing+processing/null-error atau unknown+failed/error canonical,
attempts1, processed NULL, token+expiry provisional exact pada presisi database,
belum expired, serta lookup counter di bawah maksimum.

Update conditional mengganti UUID provisional dengan UUID permit baru, refresh
expiry dari database clock, dan increment generation tepat satu. Query builder
tidak menyentuh `updated_at`, available/status/attempts/processed/error/next atau
audit. DTO `AssessmentInvoiceReconciliationPermit` membungkus
`AssessmentInvoicePermit` existing, UUID permit, generation, dan expiry.
Provisional replay/lost/stolen hanya menghasilkan null; owner token baru tidak
dihapus.

Candidate yang gagal dengan `DomainException` menggulung balik transaksi
organization-first lalu menjalankan cleanup outbox-only dengan fence token+expiry,
tanpa counter/audit/provider. Unexpected programming/database exception tetap
propagate dan lease provisional dibiarkan expire. Decoder payload permit P10c-a
dipindahkan utuh ke `AssessmentInvoicePermitFactory`; P10c-a dan validator kini
memakai satu parser canonical, bukan near-duplicate.

### Bukti aktual

- RED setelah setup fixture benar: **14 tes, 14 errors, 98 assertions** karena
  validator belum ada.
- Focused feature final: **15/15 tes, 201 assertions**. Processing dan unknown
  canonical menerbitkan permit; token diputar, expiry direfresh dan generation
  naik. Replay, lost/expired/stolen, max exhausted, policy/channel OFF, revoked,
  corrupt/foreign/paid, ambient/config, cleanup, audit/business-column stability,
  serta rollback unexpected failure terbukti.
- Regresi P10c-a bersama validator setelah ekstraksi factory: **43/43 tes,
  480 assertions**. Regresi lookup/claim/issuance/P10c-a/reservation/validation
  final: **197/197 tes, 1.524 assertions**.
- Runner PostgreSQL pertama menemukan mismatch presisi mikrodetik antara database
  clock dan binding timestamp (**237 tes, 1 failure, 1.636 assertions**). Validator
  dinormalisasi ke presisi penyimpanan detik. Run kedua menemukan fixture hint
  mengambil model stale (**237 tes, 1 failure, 1.641 assertions**); fixture
  diperbaiki memakai row fresh. Final disposable: **237/237 tes, 1.646
  assertions**, cleanup sukses.
- Tes PG menjalankan runtime `psikotes_runtime` non-owner/NOBYPASSRLS: dua
  validator benar-benar menunggu mutex organization, hanya satu mendapat permit
  generation1 dan satu null; worker fase 1 tetap menyelesaikan hint berbeda saat
  organization lock ditahan; expired/stolen fence tidak increment atau menghapus
  owner baru. Tidak ada deadlock atau provider call.
- PHPStan seluruh project dengan environment testing eksplisit lulus **0 error**;
  scoped check setelah perubahan terakhir juga nol. Pint enam file kode/tes dan
  `git diff --check` lulus.

Tidak ada PaymentProvider GET/POST/create, persistence outcome, command/job/
scheduler/route, schema/config baru, source/gate, credential/.env/data aktif,
outbound, deploy atau push. **STOP sebelum P10c-b3/P11**.

## P10c-b3 — strict lookup leased dan outcome fence

Commit kode lokal `75c72d8` menambah entrypoint internal
`ReconcileAssessmentBillInvoice::executeLeased`. Entrypoint menolak ambient RLS
context dan outer transaction, memvalidasi cooldown, lalu menjalankan transaksi
service sangat pendek yang hanya mengunci outbox dan membaca database clock.
Preflight memeriksa topic/aggregate, message dan digest payload, UUID token,
generation, expiry exact dan belum kedaluwarsa, serta pasangan message canonical.
Transaksi dan context selesai sebelum tepat satu `lookupInvoice` berdasarkan
reference/amount/currency snapshot. Jalur ini tidak pernah memanggil
`createInvoice`.

`PersistAssessmentInvoiceOutcome` kini menyediakan persistence khusus permit
rekonsiliasi dengan seluruh lock/predicate late-state canonical yang sama dengan
issuance. Fence token, generation, expiry dan database clock diperiksa setelah
seluruh row authoritative dikunci. Exact membuat bill pending dan message
processed secara atomik, mempertahankan generation, membersihkan token/expiry/
cooldown, serta menyimpan expiry asli provider dan satu audit. Outcome unknown
dari issuing membuat pasangan unknown/failed canonical dan satu audit; unknown
existing tidak ditulis ulang dan tidak menambah audit. Keduanya mengonsumsi lease
dan memasang cooldown dari database clock tanpa mengubah counter.

Worker dengan token hilang/dicuri/kedaluwarsa, generation atau digest berubah,
atau bill/message terminal mendapat `recovery_required` tanpa mutasi, audit, atau
provider call bila gagal pada preflight. Race khusus ADR-009 juga ditutup pada
boundary bersama: exact issuance P10b membersihkan metadata lease dalam update
processed yang sama, sehingga constraint PostgreSQL tetap valid dan hasil worker
leased lama kemudian gagal fence. Unknown issuance tetap mempertahankan lease
canonical untuk rekonsiliasi.

### Bukti aktual

- RED awal: **16 tes**, **1 passed + 15 errors**, **132 assertions**, karena
  `executeLeased` belum tersedia.
- Feature P10c-b3 final mencakup processing/unknown exact, expiry provider lama,
  error/mismatch/unknown stabil, cooldown tepat 300 detik, counter unchanged,
  UUID/generation/digest/token/expiry stale, late stolen/paid, rollback audit,
  ambient context/transaction, dan Xendit `Http::fake` GET-only tanpa POST.
  File khusus ini lulus **17/17 tes, 275 assertions**.
- Regresi focused P10b/P10c final: **83/83 tes, 1.041 assertions**. Default suite
  `phpunit.organization-payment.xml`: **75/75 tes, 414 assertions**.
- Runner PostgreSQL disposable pertama menemukan tes race yang belum menjamin GET
  telah dimulai sebelum issuance (**239 tes, 1 failure, 1.686 assertions**). Tes
  diperbaiki memakai barrier provider. Final: **239/239 tes, 1.691 assertions**,
  cleanup sukses. Runtime adalah `psikotes_runtime` non-owner/NOBYPASSRLS; exact
  issuance saat GET leased tertahan menghasilkan tepat satu persistence/audit,
  dan hasil leased setelah token dicuri dibuang tanpa menghapus owner baru.
- Pint empat file kode/tes dan `git diff --check` lulus. PHPStan seluruh project
  dengan environment testing eksplisit/SQLite memory lulus **0 error**.

Tidak ada batch coordinator, command/job/scheduler/route, schema/config/source
aktif, create/POST, settlement/finalizer/entitlement/notifikasi, credential/.env/
data aktif, outbound nyata, deploy atau push. **STOP sebelum P10c-b4/P11**.

## P10c-b4 — koordinator internal bounded

Commit kode lokal `1766c65` menambah
`CoordinateAssessmentInvoiceReconciliation`. Entrypoint tanpa argumen membaca
`invoice_reconciliation_batch_size`, `invoice_reconciliation_scan_limit`, dan
`invoice_reconciliation_max_lookups` dari config canonical. Ia tidak memiliki
default atau literal kebijakan sendiri. Phase-1 reservation existing tetap
memvalidasi seluruh range dan relasi config secara fail-closed serta menjadi
satu-satunya boundary outbox-only/SKIP LOCKED.

Setiap provisional lease kemudian diproses berurutan melalui
`ValidateAssessmentInvoiceReconciliationLease::execute` dan, bila mendapat
permit, `ReconcileAssessmentBillInvoice::executeLeased`. Dengan demikian urutan
lock organization-first, commit sebelum GET, UUID/generation/expiry fence,
cooldown, dan strict lookup tetap berada pada boundary yang sudah diterima;
koordinator tidak menambah transaksi, query, lock, provider POST, atau predicate
canonical baru. Hint invalid/stale dihitung recovery-required dan loop lanjut.
Provider lookup yang unknown ditangani boundary leased sebagai unknown lalu loop
juga lanjut ke hint berikutnya. Programming/database failure unexpected tetap
propagate, bukan disamarkan sebagai hasil bisnis.

Ringkasan hasil memiliki urutan key tetap dan hanya memuat tiga limit serta
counter `reserved`, `validated`, `issued`, `unknown`, `validationRejected`, dan
`recoveryRequired`.
Tidak ada message ID, merchant reference, invoice URL, payload, participant,
organization, token, credential, atau metadata lain. Invariant yang dibuktikan:
setiap reserved hint terhitung sebagai validation rejection atau validated;
setiap permit validated berakhir issued, unknown, atau execution recovery.

### Bukti aktual

- RED: **8 tes, 0 passed, 5 errors + 3 failures, 50 assertions**, karena class
  koordinator belum ada.
- Tes khusus final: **8/8 tes, 92 assertions**. Bukti mencakup empty deterministic,
  config batch/scan satu, max-lookups exhausted, invalid config fail-closed,
  ambient context/transaction, mixed issued/unknown/recovery-required, invalid
  lease di tengah batch, provider failure di tengah batch, hint sesudah kegagalan
  tetap issued, urutan provider deterministic, no `createInvoice`, dan summary
  tidak memuat identifier/reference.
- Regresi reserve/validate/leased/issuance/reconciliation: **110/110 tes, 1.215
  assertions**. Default `phpunit.organization-payment.xml`: **75/75 tes, 414
  assertions**.
- PostgreSQL disposable existing penuh: **239/239 tes, 1.691 assertions**,
  cleanup sukses. Tidak ditambah tes PG coordinator baru karena action tidak
  menambah transaksi/query/lock; suite ini tetap membuktikan SKIP LOCKED dua
  proses, organization-first serialization, runtime non-owner/NOBYPASSRLS,
  token fence, dan race persistence yang benar-benar dipakai komposisi.
- Pint kedua file kode/tes dan `git diff --check` lulus. PHPStan seluruh project
  dengan environment testing eksplisit/SQLite memory lulus **0 error**.

Tidak ada command/job/scheduler/route/webhook, finalizer P11, schema/migration/
config change, credential/.env/data aktif, provider nyata, notifikasi, deploy,
push, atau source/gate activation. **STOP sebelum wiring operasional/P11**.

### P10c-b4 review fix — scan budget dan refill lookup

Review menemukan commit awal `1766c65` hanya memanggil reservation sekali,
sehingga `scanLimit > batchLimit` tidak pernah dipakai untuk mengganti slot lookup
yang ditolak validator. Commit fix lokal `8a79d0a` menggantinya dengan loop budget
eksplisit. `remainingScan` berkurang untuk setiap provisional lease yang benar-benar
diperiksa; `remainingLookups` hanya berkurang setelah validator menerbitkan permit
yang kemudian dieksekusi. Loop berhenti saat lookup budget nol, scan budget nol,
atau reservation mengembalikan kosong.

Phase-1 reservation mendapat parameter optional exclusion set yang divalidasi
sebagai list ULID unik dan dibatasi scan config. Query awal memakai `whereNotIn`
untuk message yang sudah diperiksa, sedangkan conditional update row terpilih tetap
memakai predicate canonical existing. Ini diperlukan karena validator sengaja
membersihkan provisional token kandidat invalid; tanpa exclusion, row invalid yang
due akan langsung terpilih ulang dan dapat menghabiskan scan tanpa maju. Tidak ada
cooldown/backfill/mutasi bisnis baru pada kandidat invalid.

Summary kini memisahkan `validationRejected` dari `recoveryRequired` hasil
`executeLeased`. Karena itu invariant observable menjadi `reserved = validated +
validationRejected`, `validated = issued + unknown + recoveryRequired`,
`reserved <= scanLimit`, dan `validated <= batchLimit`. Tidak ada identifier yang
ditambahkan ke summary.

Regresi wajib batch2/scan4 membuat urutan due invalid, valid, valid, valid. Hasil
aktual adalah reserved3, validationRejected1, validated2, issued2; kandidat keempat
tetap processing tanpa lease. Tes mixed terpisah membuktikan provider unknown dan
execution recovery di tengah urutan tidak mencegah kandidat setelahnya menjadi
issued.

#### Bukti aktual review fix

- RED khusus: **1 tes, 1 failure, 10 assertions**; implementasi awal mengembalikan
  reserved2, bukan reserved3.
- Tes coordinator final: **9/9 tes, 117 assertions**. Coordinator + reservation:
  **29/29 tes, 204 assertions**.
- Regresi reserve/validate/leased/issuance/reconciliation final: **112/112 tes,
  1.245 assertions**. Default suite organisasi: **75/75 tes, 414 assertions**.
- PostgreSQL run pertama terganggu oleh timeout barrier proses existing saat host
  lambat; child terlambat melanjutkan suite dan menimbulkan output berulang serta
  collision fixture. Resource disposable dibersihkan. Run kedua pada network/DB
  baru lulus **240/240 tes, 1.701 assertions**, cleanup sukses. Satu tes PG baru
  membuktikan exclusion set melewati row awal dan mereservasi dua row due berikutnya
  pada runtime non-owner.
- Pint lima file kode/tes, PHPStan seluruh project, dan `git diff --check` lulus.

Tetap tidak ada command/job/scheduler/route/P11, POST/create provider, perubahan
config/schema, credential/.env/data aktif, notifikasi, deploy, push, atau aktivasi
source/gate. **STOP untuk review integrasi P10c-b4**.

## P11a1 — finalizer pembayaran bill atomik

Commit kode/tes lokal `de7e6f2` menambah boundary internal
`FinalizeAssessmentBill`. Input memakai `PaymentEvent` typed existing: merchant
reference `AB_`, provider reference, nominal integer, currency IDR, status,
`occurredAt`, dan event ID. Boundary tidak melakukan autentikasi webhook atau
wiring publik; P11b tetap harus memasok event yang sudah diautentikasi.

Finalizer membaca bill hanya sebagai routing hint dalam service RLS, kemudian
mengunci organization sebelum bill/items, attempt, participant, package, charge,
dan payment method. Seluruh scope persisted, funding checkout-v2, snapshot harga,
item count/sum, payer, reference, amount, dan currency diperiksa ulang. Policy dan
status aktif saat ini tidak dipakai untuk membatalkan invoice existing yang sudah
dibayar. Context non-service serta transaksi ambient tanpa authority ditolak;
caller yang sudah berada dalam transaksi service sah tetap didukung, dan seluruh
efek mengikuti commit/rollback transaksi induk.

Event paid canonical mengubah bill menjadi `paid`, menetapkan `paid_at`, lalu
menulis `settled_at` pada setiap bill item satu per satu dalam transaksi yang
sama. Schema tidak memiliki timestamp settlement berbayar pada charge;
`free_settled_at` sengaja tidak disalahgunakan karena hanya sah untuk charge nol.
Satu audit `assessment_bill.paid` menyimpan hash event/provider, nilai pembayaran,
paidAt, dan daftar ID allocation untuk trace bill-ke-item. Setelah itu primitive
P8b dipanggil per attempt. Attempt dengan consent/identity lengkap dapat menjadi
READY dan mendapat outbox; attempt yang belum lengkap tetap lunas tetapi locked,
tanpa menggagalkan anggota lain atau membuat tagihan kedua. Audit aktivasi
`assessment.activated` tetap terpisah dan hanya dibuat P8b untuk attempt yang
benar-benar aktif.

Replay paid exact memerlukan paidAt serta audit identity yang sama dan menjadi
no-op. Provider/reference/amount/currency atau payload replay berbeda ditolak.
Callback expired/cancelled terlambat hanya diabaikan bila state paid, seluruh
allocation, dan audit masih canonical; ia tidak dapat menurunkan paid. State paid
yang korup gagal tertutup tanpa rewrite/audit tambahan.

### Bukti aktual

- RED pertama: **8 tes, 0 passed, 2 failures + 6 errors, 2 assertions**. Selain
  class finalizer yang belum ada, fixture kolektif awal juga menemukan reference
  provider sintetis harus unik; fixture tes kemudian dibatasi satu reference
  induk.
- Feature final: **10/10 tes, 65 assertions**. Cakupan meliputi collective dua
  attempt dengan consent tertunda, exact replay, late expired/cancelled, replay
  identity berbeda, allocation/snapshot/metadata korup, partial/overpayment,
  reference/provider/currency mismatch, nonpaid pending no-op, crash pada save
  item kelima, context denial, transaksi ambient denial, serta rollback transaksi
  service induk yang membatalkan bill/items/audit/activation/outbox.
- Regresi terkait dijalankan per file karena satu proses yang mencampur test
  `DatabaseTruncation` baru dan file lama `RefreshDatabase` kehilangan schema
  SQLite setelah 48 tes. Per-file semuanya lulus: finalizer **10/65**, aktivasi
  P8b **30/107**, gate **44/55**, issuance P10b **22/273**, leased reconciliation
  **17/275**; total **123 tes, 775 assertions**. Default XML lulus **75/75 tes,
  414 assertions**.
- Pint tiga file kode/tes, PHPStan seluruh project **0 error**, dan
  `git diff --check` lulus.
- Tes PostgreSQL dua proses sudah ditambahkan dan memaksa dua backend runtime
  berbeda menunggu mutex organization, dengan ekspektasi tepat satu `settled`,
  satu `replayed`, satu audit payment, dan satu set activation/outbox. Namun dua
  invocation runner disposable pada host ini sama-sama masuk status proses OS
  `D` (I/O wait) selama 6–8 menit **sebelum ada koneksi `psikotes_runtime` di
  `pg_stat_activity`**. Keduanya dihentikan dan container/network bernama exact
  sudah dibersihkan. Karena itu bukti PG P11a1 masih **belum terverifikasi**, bukan
  lulus atau failure assertion aplikasi; koordinator perlu menjalankan runner
  disposable dari root/review host.

Tidak ada route, webhook dispatcher, command/job/scheduler, provider GET/POST,
proof transfer, P11b/P11c, schema/migration/config, credential/.env/data aktif,
notifikasi terkirim, deploy atau push. **STOP untuk review P11a1**.

## P11b0 — audit kontrak routing event pembayaran

Increment proposal-only ini membaca baseline root `c027140`, wave-16 final,
todo/plan/parallel-work, SPEC organization billing, ADR-002 dan ADR-006–009,
serta source autentikasi webhook, provider normalization, event claim, order
legacy, status reconciliation, dan finalizer P11a. Proposal lengkap ada di
`tasks/organization-payment/reports/backend-payment-event-routing-proposal.md`.

Boundary yang direkomendasikan mempertahankan autentikasi di controller/provider
existing: callback token diverifikasi `hash_equals` dan payload dinormalisasi
sebelum `PaymentWebhookProcessor`. Dispatcher baru kelak berada **sesudah** event
claim dalam transaksi service yang sama. Semua merchant reference yang dimulai
`AB_`, termasuk malformed/unknown, hanya menuju bill finalizer dan tidak pernah
fallback ke order. Reference non-AB_ tetap memakai handler/state machine legacy.
Bill namespace hanya menerima provider Xendit; transfer manual P11c tetap
authority terpisah.

Processor dapat memakai hasil dispatcher `applied|ignored`: settlement baru
menjadi applied; replay/nonpaid no-op menjadi ignored. Error finalizer dipetakan
dengan allowlist ke reference/money/bill-invalid; exception programming/DB tidak
disamarkan dan rollback claim. Formula intent hash lama tidak diubah agar row
historis replay-compatible. Gap merchant reference ditutup kelak dengan compare
kolom persisted exact pada duplicate, bukan mengganti hash version diam-diam.

Mapping bill yang diajukan untuk review: pending+paid memakai settlement P11a;
pending+expired menjadi expired; pending+cancelled menjadi rejected; pending
event ignored. Paid canonical mengabaikan pending/expired/cancelled terlambat,
sedangkan paid setelah expired/rejected ditolak. Terminal tidak melepaskan item,
tidak memberi reinvoice, settlement, entitlement, atau activation. Perubahan
terminal harus berada di finalizer yang sama agar scope/lock/replay/late-state
predicate tidak disalin ke writer kedua. Xendit adapter existing belum mengenal
raw CANCELLED dan tidak diubah tanpa kontrak provider.

Status reconciliation assessment direkomendasikan sebagai action bounded
terpisah yang memilih pending Xendit dengan gateway_ref, menutup transaksi/RLS
context sebelum GET, lalu meneruskan normalized event ke processor/dispatcher/
finalizer yang sama. Reconciler dan command legacy tidak diubah karena union akan
mengubah arti limit, ordering, counter, dan log existing. Action assessment belum
didaftarkan ke command/scheduler.

Matriks proposal mencakup missing/wrong token, malformed payload, spoofed/
malformed/unknown AB_, provider/merchant/reference/money mismatch, duplicate
intent merchant conflict, PAID-vs-SETTLED, webhook-vs-status race, seluruh status
bill, rollback item kelima/outbox, GET-only/no POST, selection bounded, legacy
HTTP/body/state regression, serta PostgreSQL non-owner/RLS/two-process.

### Bukti audit aktual

- Kontrak webhook/provider/status/order legacy existing: **40/40 tes, 149
  assertions**, lulus memakai XML organization-payment dan HTTP fake existing.
- Finalizer P11a existing: **10/10 tes, 65 assertions**, lulus pada proses
  terpisah agar `DatabaseTruncation` tidak bercampur dengan RefreshDatabase.
- Tidak dibuat RED tests tracked karena terminal mapping dan bentuk dispatcher
  masih menunggu keputusan review. Matriks proposal adalah kontrak TDD untuk
  increment berikutnya; suite repository tetap hijau.

Tidak ada source produksi, route, controller, provider, command/scheduler,
schema/config, credential/.env/data aktif, outbound, notifier, deploy atau push
yang berubah. **STOP untuk review P11b0 sebelum dispatcher implementation**.

## P11b1 — dispatcher event pembayaran internal

Commit kode/tes lokal `2c94e8e` menempatkan `PaymentEventDispatcher` setelah
autentikasi/normalisasi provider dan durable claim existing di
`PaymentWebhookProcessor`. Namespace merchant reference dipisahkan tegas: semua
prefix `AB_`, termasuk malformed atau tidak ditemukan, hanya melewati finalizer
assessment bill; reference non-AB tetap menuju handler order legacy. Bill hanya
menerima provider `xendit`. Dispatcher juga mensyaratkan service RLS context yang
sudah aktif sehingga ia tidak menjadi entrypoint publik baru.

Duplicate `(provider,event_id)` kini tetap memakai intent hash historis dan juga
membandingkan `merchant_reference` persisted secara exact. Formula hash tidak
diubah. Hasil finalizer `settled|transitioned` menjadi applied, sedangkan
`replayed|ignored` menjadi no-op. Domain failure dipetakan lewat allowlist sempit
ke reference, money, atau bill-invalid generik; exception DB/programming yang
tidak dikenal tetap keluar dan membatalkan claim.

Finalizer P11a tetap menjadi satu-satunya writer bill. Selain settlement paid,
state pending sekarang memetakan event pending menjadi no-op, expired menjadi
bill expired, dan cancelled sintetis menjadi rejected. Transisi terminal tidak
menyentuh allocation, activation, entitlement, atau outbox serta menulis satu
audit terminal. Replay terminal exact tidak menambah audit. Bill paid canonical
mengabaikan pending/expired/cancelled yang terlambat; bill expired/rejected tidak
dapat dihidupkan kembali atau dipindahkan ke terminal lawan.

### Bukti aktual

- RED awal: **10 tes, 2 passed, 8 failures, 20 assertions**. Kegagalan
  membuktikan reference AB masih jatuh ke lookup order legacy, merchant reference
  duplicate belum dibandingkan, terminal belum ditransisikan, dan rollback
  finalizer belum tercapai melalui processor.
- GREEN kontrak dispatcher: **12/12 tes, 73 assertions**. Cakupan: malformed dan
  unknown AB tanpa query order, spoof provider, amount mismatch, merchant
  duplicate conflict, pending/expired/cancelled, paid late-state, terminal
  fail-closed/replay, rollback event claim beserta seluruh efek finalizer,
  service-context denial, dan order legacy non-AB.
- Regresi finalizer P11a: **10/10 tes, 65 assertions**. Ekspektasi no-op lama
  diperjelas memakai event pending; coverage expired/cancelled kini berada di
  suite dispatcher sesuai kontrak P11b1.
- Regresi webhook/provider/reconciliation/order legacy: **40/40 tes, 149
  assertions**. Default XML organization-payment: **75/75 tes, 414 assertions**.
- PostgreSQL disposable runtime non-owner: **241/241 tes, 1.719 assertions**;
  resource cleanup sukses dan container aplikasi tidak disentuh. Angka lebih
  kecil dari root terbaru karena worker mempertahankan snapshot baseline sesuai
  instruksi; runner ini memverifikasi regresi transaksi/RLS yang tersedia di
  snapshot, sementara concurrency finalizer dua proses telah diverifikasi root
  pada integrasi P11a.
- Pint enam file kode/tes bersih, PHPStan seluruh project **0 error**, dan
  `git diff --check` lulus.

Tidak ada route/controller baru, perubahan autentikasi/normalisasi provider,
status reconciler assessment, command/job/scheduler, provider call, schema/config,
credential/.env/data aktif, settlement manual, notifikasi terkirim, deploy, push,
atau aktivasi source/gate. **STOP untuk review P11b1 sebelum P11b2/P11c**.

### P11b1 review fix — explicit paid-only settlement guard

Commit lokal `df2a2a6` menambahkan `assertPaidEvent()` tepat sebelum pembentukan
`paidAt` dan seluruh mutasi settlement. Status normalized selain `Paid` yang tidak
memiliki cabang eksplisit sekarang gagal tertutup dengan
`ASSESSMENT_PAYMENT_STATE_INVALID`; dispatcher memetakannya menjadi
`bill_invalid`. Guard tidak bergantung pada exhaustiveness enum saat ini.

Tes kontrak status dirapikan menjadi matriks `PaymentStatus::cases()`: Pending
ignored dan tidak settled; Paid applied serta settled; Expired applied menjadi
expired tanpa settlement; Cancelled applied menjadi rejected tanpa settlement.
Daftar expected juga dibandingkan exact dengan seluruh case enum saat ini agar
penambahan status baru memaksa keputusan tes dan implementasi.

Bukti setelah fix: dispatcher + finalizer **22/22 tes, 145 assertions**; regresi
webhook/provider/reconciliation/order legacy **40/40 tes, 149 assertions**; Pint
dua file, PHPStan seluruh project **0 error**, dan `git diff --check` lulus.
Perubahan tidak menyentuh transaksi, lock, schema, atau RLS, sehingga runner PG
241/1.719 dari increment P11b1 tetap menjadi bukti runtime yang relevan dan tidak
diulang. **STOP untuk review ulang P11b1; belum P11b2/P11c**.

## P11b2 — status reconciliation assessment bill internal

Commit kode/tes lokal `71dc062` menambah action internal
`ReconcilePendingAssessmentBills` tanpa command, scheduler, job, route, atau
controller. Action menerima `limit` dan `scan` 1–500 dengan `limit <= scan`.
Selection service yang singkat mengambil pasangan ID/gateway reference secara
urut `assessment_bills.id`, hanya untuk bill `pending`, gateway reference
non-NULL, dan payment method persisted berkode `xendit`. Status reserved,
issuing, unknown, paid, expired, rejected, gateway kosong, serta kanal manual
tidak dipilih.

Transaksi selection selesai sebelum loop provider. Boundary menolak ambient DB
transaction atau RLS context dan memeriksa kembali keduanya sebelum setiap GET.
`scan` membatasi row snapshot dan `limit` membatasi panggilan provider. Setiap
`PaymentEvent` normalized dari `PaymentProvider::checkStatus` selalu diteruskan
ke `PaymentWebhookProcessor`, sehingga durable claim, dispatcher namespace, dan
`FinalizeAssessmentBill` P11a/P11b1 tetap satu-satunya writer status/settlement.

Result typed hanya membawa counter `scanned`, `checked`, `applied`, `ignored`,
dan `failed`; tidak membawa bill/reference, URL invoice, atau identitas peserta.
Timeout/HTTP/payload provider dihitung gagal generik dan kandidat berikutnya
tetap diproses. Warning kegagalan tidak memiliki context identifier, sedangkan
completion log hanya memiliki lima counter tersebut. Unexpected DB/programming
exception tidak ditangkap. `XenditProvider::checkStatus` kini membungkus
`ConnectionException` menjadi `PaymentProviderException` generik tanpa previous
exception, sehingga URL/credential tidak menyeberangi boundary. Request existing
tetap HTTPS allowlist `api.xendit.co`, Basic Auth, timeout, dan no redirect.

### Bukti aktual

- RED awal: **8 tes, 0 passed, 8 errors, 0 assertions**, seluruhnya karena action
  belum tersedia (satu tes juga menemukan `Log::fake` bukan API facade yang sah;
  assertion dipindahkan ke counter typed dan implementasi log generik diaudit
  langsung).
- GREEN feature action: **8/8 tes, 32 assertions**. Cakupan: selection/order
  deterministic; budget scan 3/outbound 2; seluruh status dan kanal ineligible;
  GET pada transaction level 0 dengan RLS context NULL; paid/expired melalui
  claim-dispatcher-finalizer; timeout dan payload malformed tidak menghentikan
  paid berikutnya; race webhook menang saat GET menghasilkan duplicate tanpa
  audit/outbox ganda; invalid bounds; serta GET-only tanpa POST/expire.
- Gabungan action + dispatcher + finalizer: **30/30 tes, 177 assertions**.
  Regresi webhook/status reconciliation/provider/order legacy: **40/40 tes,
  149 assertions**. Default XML organization-payment: **75/75 tes, 414
  assertions**.
- PostgreSQL disposable final pada runtime `psikotes_runtime` non-owner dan
  NOBYPASSRLS: **242/242 tes, 1.731 assertions**, cleanup sukses. Tes baru
  membuktikan selection service dapat membaca kandidat RLS, commit/context clear
  sebelum GET, lalu event expired masuk lagi ke processor dan menghasilkan satu
  claim serta satu audit terminal.
- Run PG pertama menemukan `Http::fake` tes baru bocor ke tes stray-request
  berikutnya; factory kini dipulihkan fail-closed pada teardown. Run kedua
  melewati masalah itu tetapi terkena assertion race rollback existing di
  `AssessmentInvoiceClaimTest`; run ketiga pada DB/network disposable baru lulus
  penuh, sehingga dicatat sebagai flake concurrency baseline, bukan hasil yang
  disembunyikan.
- Pint lima file kode/tes, PHPStan seluruh project **0 error**, dan
  `git diff --check` lulus.

Reconciler/command legacy tidak berubah. Tidak ada createInvoice/expireInvoice,
POST, status writer kedua, command/job/scheduler/route/controller, schema/
migration/config/.env, credential/data aktif, provider nyata, notifier, deploy,
push, atau P11c. **STOP untuk review P11b2 sebelum wiring operasional**.

## P11c0 — audit authority dan kontrak transfer manual bill

Increment proposal-only ini menghasilkan ADR-010 berstatus Proposed di
`docs/decisions/0010-assessment-bill-manual-transfer-verification.md` dan audit
rinci di `tasks/organization-payment/reports/backend-manual-bill-verification-proposal.md`.
Tidak ada source produksi atau RED test tracked karena dua keputusan wajib masih
menunggu review: migration metadata proof additive dan bentuk entrypoint manual
typed pada finalizer.

Temuan utama:

- ability/order policy legacy tidak cocok: `can_verify_payments` saat ini dapat
  memberi BranchAdmin/Staff hak review order; assessment bill wajib policy/resource
  terpisah dengan persisted non-deleted SuperAdmin ONCAM saja;
- `assessment_bills` hanya menyimpan object key, tanpa checksum/MIME/size/uploadedAt
  durable. Proposal merekomendasikan empat kolom typed nullable dengan all-or-none
  CHECK bersama key sebelum upload/review writer dibuat;
- `PaymentEvent` mengharuskan gateway reference provider. Manual review tidak
  boleh mengisi gateway palsu; proposal menambah DTO manual sibling dan membawa
  approve/reject ke private lock/allocation/finalization primitive yang sama;
- actor reload+lock mendahului organization→bill→allocation locks. Browser hanya
  membawa bill reference, proof fingerprint, decision enum dan reason code;
  canonical money/currency/status/scope berasal dari server;
- exact replay actor/proof/decision sama adalah no-op; actor lain, stale proof,
  opposite decision, terminal state, method bukan manual, atau allocation korup
  fail closed. Reject tidak settle, release, reinvoice, activate, atau enqueue;
- raw proof tetap private, short-lived dan reviewer-only. BranchAdmin payer atau
  participant self kelak hanya boleh submit sesuai scope, bukan view reviewer atau
  decide. Path/URL/PII dilarang di response/log/audit;
- proof terminal dipertahankan setidaknya hingga audit dua tahun berakhir. Tidak
  ada purge otomatis pada P11c.

Proposal memuat actor/action/resource matrix, missing-vs-forbidden contract,
lifecycle upload/review/replay/race, lock/transaction sequence, STRIDE threat
model, compatibility legacy, matriks SQLite/PostgreSQL TDD, dan pembagian
P11c1a schema, P11c1b core finalizer, P11c2a storage/access, P11c2b UI/wiring.

### Bukti audit aktual

- Finalizer + dispatcher existing: **22/22 tes, 145 assertions**, lulus.
- Pure legacy manual verification/proof access: **10/10 tes, 48 assertions**;
  upload validation/storage/replacement tanpa received-page render: **8/8 tes,
  44 assertions**.
- Run gabungan legacy 23 tes menghasilkan **20 passed, 107 assertions**, dengan
  satu failure received-page karena Vite manifest worker tidak tersedia dan dua
  error Filament karena Windows compiled-view rename access denied. Ini batas
  harness worker, bukan diklaim passed; root sebelumnya mempunyai regresi default
  hijau pada baseline terbaru.
- `git diff --check` dua dokumen proposal lulus. Tidak perlu Pint/PHPStan/PG karena
  tidak ada PHP, schema, query, lock, atau RLS implementation yang berubah.

Tidak ada policy/action/resource/route/controller, writer, schema/migration,
config/.env, akun/role/data aktif, provider/notifier/outbound, command/scheduler,
deploy, push, atau P11c implementation. **STOP untuk review ADR-010 dan schema
prerequisite sebelum P11c1**.

## P11c1a — schema identitas proof transfer manual

Commit kode/tes lokal `1fb1da9` menambah migration additive
`2026_09_01_000200_add_manual_proof_identity_to_assessment_bills.php`. Empat
kolom nullable baru adalah checksum SHA-256 lowercase, MIME, ukuran byte, dan
waktu upload. PostgreSQL memberi lima named CHECK: seluruh metadata dan
`proof_object_key` harus all-NULL atau all-non-NULL; checksum tepat 64 hex
lowercase; MIME hanya JPEG/PNG/PDF; ukuran 1–5.120.000; dan key hanya berbentuk
`assessment-bills/<2 lowercase alnum>/<62 lowercase alnum>.(jpg|png|pdf)`.
Tidak ada index, writer, upload, atau akses object storage pada increment ini.

`up()` menolak sebelum DDL bila satu kolom target sudah ada atau satu key legacy
sudah terisi; tidak menebak checksum maupun metadata. `down()` menolak sebelum
drop bila metadata baru mana pun berisi nilai, lalu hanya menjatuhkan empat
kolom baru dan mempertahankan `proof_object_key`. Transactional DDL PostgreSQL
dan SQLite membuktikan penolakan tidak meninggalkan schema parsial. RLS, FORCE
RLS, owner, policy, index, urutan kolom legacy, tipe, dan default lama dibandingkan
sebelum/sesudah roundtrip pada PostgreSQL.

### Bukti aktual

- RED schema: **8 tes, 0 passed, 1 failure + 7 errors, 9 assertions** karena
  migration/kolom belum ada.
- GREEN feature SQLite memory: **8/8 tes, 57 assertions**. Focused bersama
  schema billing/items dan finalizer P11a: **40/40 tes, 227 assertions**.
- PostgreSQL disposable runtime `psikotes_runtime`, non-owner,
  `rolsuper=false`, `rolbypassrls=false`: **265/265 tes, 1.827 assertions**;
  cleanup sukses. Test baru mencakup tipe nyata, lima named CHECK, seluruh
  pasangan missing, checksum case/length, MIME, size, path absolute/traversal/
  backslash/control/case/extension, RLS matrix, owner roundtrip, dan kedua
  preflight atomik.
- Run PG pertama menemukan test migrasi historis menyimpan empat kolom additive
  ke snapshot yang kemudian dibuat ulang hanya oleh migrasi historis. Patch
  kompatibilitas membatasi snapshot/comparison ke kolom yang memang dimiliki
  rangkaian migrasi tersebut. Run kedua membuktikan satu comparison lain masih
  mengambil seluruh row; sesudah semua comparison memakai key snapshot, run
  ketiga lulus penuh. Tidak ada migration historis yang diubah.
- Pint seluruh file lane dan patch kompatibilitas lulus. PHPStan seluruh project
  dengan environment testing/SQLite eksplisit lulus **0 error**.
  `git diff --check` lulus.

Dua file existing merupakan baseline untracked pada snapshot worker dan sengaja
tidak di-stage sebagai file penuh. Patch integrasi yang diperlukan:

1. `app/Models/AssessmentBill.php`: PHPDoc empat property baru; tambahkan empat
   nama ke `#[Fillable]`; cast `proof_size_bytes` ke integer dan
   `proof_uploaded_at` ke immutable datetime. Hash file worker dicatat saat
   handoff: `27A1BBF42F1FF102364467ABF9B1CADBE02B60E41FE64BC1A9F04D71BAA6E87A`.
2. `tests/Postgres/AssessmentBillingMigrationTest.php`: saat membuat snapshot
   historical `assessment_bills`, keluarkan empat kolom additive; pada tiga
   comparison row gunakan `first(array_keys($row))`. Ini hanya membuat test
   lifecycle historis sadar akan migration additive sesudahnya. Hash file worker:
   `68605D28611D4587B8DABBF8A17536663FB5ABD570742FA94BA667AB9246AD9B`.

Tidak ada route/controller/policy/resource, writer/upload/storage call, schema
historis, config/.env/data aktif, provider/notifier/outbound, command/scheduler,
UI, deploy, push, atau P11c1b. **STOP untuk review P11c1a**.

## P11c1b — core typed review/finalizer transfer manual

Commit kode/tes lokal `8bc0310` menambah boundary internal
`ReviewAssessmentBillTransfer` yang hanya menerima
`AssessmentBillManualReview`. DTO memerlukan actor admin positif, reference
`AB_`+ULID, fingerprint proof 64 lowercase hex, decision enum APPROVE/REJECT,
dan rejection code enum. Constructor menolak approve dengan reason maupun reject
tanpa reason. Allowlist rejection adalah `AMOUNT_MISMATCH`,
`UNREADABLE_PROOF`, `WRONG_BENEFICIARY`, `DUPLICATE_PROOF`, dan
`OTHER_UNVERIFIABLE`; tidak ada free text.

`FinalizeAssessmentBill::executeManual()` menjadi sibling typed dari provider
entrypoint. Ia menolak ambient RLS context/transaction, masuk service transaction,
lalu lock/reload Admin terlebih dahulu dan hanya menerima persisted non-deleted
SuperAdmin. Missing/stale/deleted/role-changed actor serta BranchAdmin owner atau
foreign, Staff dengan legacy flag, Psychologist, dan ID non-admin ditolak sebelum
query `assessment_bills`, sehingga reference tidak menjadi oracle. Ability
`can_verify_payments` dan policy order legacy tidak dibaca.

Sesudah actor sah, graph loader yang sama dipakai oleh finalizer provider dan
manual dengan urutan organization→bill→items→attempts→participants→packages→
charges→payment method. Manual path mengharuskan method persisted tepat
`manual_transfer` walaupun kini inactive, gateway/invoice NULL, lima proof field
lengkap dan canonical, serta fingerprint recompute exact. Money, currency, item,
charge, attempt, participant, package, payer, snapshot, dan checkout metadata
seluruhnya direload dari DB; browser tidak menyediakan authority tersebut.

APPROVE menggunakan waktu server sebagai paid/verified time, mengisi actor,
melunasi semua item melalui private settlement primitive yang juga dipakai
provider, menulis satu audit `assessment_bill.paid`, lalu menjalankan primitive
activation/outbox P8b dalam transaksi yang sama. Consent/identity incomplete
tetap paid+locked/PROVISIONED. REJECT mengubah bill menjadi rejected dengan code
bounded dan satu audit actor admin, tanpa settlement, activation, entitlement,
release/reinvoice, atau outbox. Context audit version 1 hanya membawa source,
decision/code, fingerprint, canonical money, review time, dan item IDs; tidak
membawa object key, path, URL, organization ID, atau PII.

Exact replay actor+proof+decision+reason yang sama adalah no-op. Actor lain,
opposite decision, reason/proof berbeda, state/channel/allocation/snapshot/money
korup, serta terminal noncanonical gagal tertutup tanpa audit/outbox baru.
Provider `PaymentEvent`, provider audit context, terminal behavior, dan webhook
dispatcher tidak diubah.

### Bukti aktual

- RED pertama: **3 tes, 0 passed, 0 assertions**, seluruhnya karena action typed
  belum tersedia; tidak ada query bill yang tercapai.
- GREEN feature + provider finalizer: **32/32 tes, 167 assertions**. Cakupan
  termasuk constructor matrix, authority-before-lookup, owner/foreign legacy
  flags, missing/deleted/role-changed actor, approve/reject 1 dan 10 item, mixed
  prerequisites, exact/opposite/stale replay, proof/channel/scope/snapshot/money
  corruption, serta rollback item kelima, activation, outbox dan ambient context.
- Provider dispatcher/webhook legacy terpisah: **19/19 tes, 85 assertions**.
  Legacy manual order/proof: **18 passed, 93 assertions**; satu received-page
  gagal karena `public/build/manifest.json` tidak tersedia. Tidak dibuat fake
  manifest atau perubahan harness.
- PostgreSQL disposable runtime `psikotes_runtime`, non-owner,
  `rolsuper=false`, `rolbypassrls=false`: **268/268 tes, 1.870 assertions**,
  cleanup sukses. Tiga test baru membuktikan same-review race menghasilkan satu
  settlement/replay, opposite decision hanya satu terminal audit/outbox set,
  dan revocation actor yang commit saat worker menunggu lock ditinjau ulang
  sebelum bill lookup/mutasi.
- Full default synthetic `--exclude-group=sandbox`: **1.149 tes**, 1.126 passed,
  6.203 assertions, 23 failure render halaman karena Vite manifest worker tidak
  tersedia. Tidak ada sandbox/external service yang dijalankan. Root memiliki
  baseline build dan regresi default otoritatif terpisah.
- Pint sembilan file lane lulus. PHPStan seluruh project dalam environment
  testing/SQLite eksplisit lulus **0 error**. `git diff --check` lulus.

Tidak ada upload/object-storage I/O, policy/route/controller/Filament/UI,
command/job/scheduler, schema/migration/config/.env, provider/notifier nyata,
DB aplikasi, deploy, push, atau P11c2. **STOP untuk review P11c1b**.

## P11c2a — core private storage dan replacement bukti transfer

Commit kode/tes lokal `24c9591` menambah boundary internal
`StoreAssessmentBillProof`. Input typed hanya menerima persisted BranchAdmin
payer organisasi atau `AssessmentPrincipal` exact untuk bill self. Actor selalu
direload; guest/raw ID, SuperAdmin, Staff, Psychologist, foreign branch, stale
principal, deleted/missing actor, dan legacy verification flag tidak menjadi
authority maupun oracle bill.

Boundary memakai empat fase terisolasi: preauthorization service transaction
singkat, private object write di luar transaksi/context RLS, canonical locked
recheck dan persist dalam service transaction kedua, lalu best-effort deletion
object lama sesudah commit. Kegagalan recheck/persist menghapus object baru
secara best effort. Kegagalan delete lama tidak membatalkan identitas proof baru
yang sudah commit. Outer transaction dan ambient RLS context ditolak.

Konten dideteksi dari byte server dengan `finfo`, hanya JPEG/PNG/PDF, ukuran byte
nyata 1–5.120.000, dan SHA-256 lowercase. Object key random private berbentuk
`assessment-bills/<2>/<62>.<ext>` pada disk existing `payment-proofs`; filename,
PII, reference bill, dan object key tidak dikembalikan. Receipt hanya membawa
boolean replacement dan fingerprint opaque untuk optimistic replacement fence.

Locked recheck memuat organization, bill, item, attempt, participant, package,
charge, dan payment method dengan urutan canonical. Bill harus pending manual
transfer, gateway/invoice/verification/terminal kosong, payer serta allocation
graph/snapshot/checkout metadata canonical, dan belum settled. Inactive manual
method tetap sah karena channel persisted, tanpa menjadikannya pilihan baru.
Replacement memerlukan fingerprint seluruh lima field proof saat ini. Upload
awal memerlukan expected fingerprint NULL. Dua writer pada versi sama hanya
memiliki satu pemenang; loser fail closed dan membersihkan object barunya.

Tidak ada audit review, settlement, activation, entitlement, outbox, purge,
proof reader, signed URL, policy, HTTP/controller, Filament/UI, atau public
wiring pada increment ini.

### Bukti aktual

- RED awal: **1 tes, 0 assertions**, error class
  `AssessmentBillProofUpload` belum ada. Percobaan GREEN pertama juga menemukan
  spoof `.jpg` lolos bila MIME berasal dari helper upload; implementasi kemudian
  memakai `finfo` pada byte nyata dan regresi spoof menjadi hijau.
- Focused storage SQLite memory: **24/24 tes, 129 assertions**. Cakupan termasuk
  tiga tipe, ukuran tepat maksimum, spoof/GIF/empty/oversize, private/random key,
  I/O tanpa transaksi/context, actor matrix, self-vs-organization ownership,
  state/channel/scope corruption, fingerprint replacement, rollback, cleanup
  failure, storage failure, dan ambient transaction/RLS context.
- Focused storage + manual review P11c1b + provider finalizer:
  **56/56 tes, 296 assertions**.
- Legacy manual upload: **8 passed, 45 assertions**; satu received-page render
  gagal karena `public/build/manifest.json` tidak tersedia di worker. Tidak ada
  manifest palsu, build, atau pelonggaran harness.
- Full default `--exclude-group=sandbox`: **1.174 tes**, 1.151 passed,
  6.336 assertions; seluruh 23 failure adalah render halaman yang sama karena
  Vite manifest worker tidak tersedia. Tidak ada kegagalan backend lain dan
  sandbox/external service tidak dijalankan.
- PostgreSQL disposable penuh pada runtime non-owner: **270/270 tes,
  1.898 assertions**, cleanup sukses. Dua-process proof tests membuktikan initial
  upload dan replacement concurrent menghasilkan tepat satu fingerprint/object,
  satu conflict, serta cleanup old/loser tanpa deadlock.
- Pint seluruh tujuh file lane lulus. PHPStan seluruh project pada environment
  testing/SQLite eksplisit lulus **0 error**. `git diff --check` lulus sebelum
  commit kode.

Batas verifikasi: private disk menggunakan fake storage pada tes; tidak ada
credential atau object store nyata. Cleanup bersifat best effort sehingga
kegagalan delete dapat meninggalkan object orphan yang tidak authoritative;
purge/reconciliation object tetap di luar scope. Tidak ada proof access/review
UI atau public authorization wiring, dan tidak ada schema/config/.env/data aktif,
provider/notifier/outbound, deploy, atau push. **STOP untuk review P11c2a sebelum
P11c2b**.

### P11c2a review fix — expiry, participant race, dan proof canonicality

Commit fix lokal `22407ad` menutup tiga finding review root tanpa memperluas
boundary. Locked recheck kini mengharuskan `expires_at` persisted dapat diparse
dan strictly future terhadap waktu server; NULL, past, dan exact-now menjadi
`STATE_INVALID`. Baik upload awal maupun replacement expired membersihkan object
baru, tidak mengubah lima metadata, dan mempertahankan object lama.

Untuk uploader participant, fase persist sekarang mengunci dan reload
`Participant::withTrashed()` lalu exact `AssessmentParticipant` sebelum Branch
atau query bill. Participant harus non-deleted dengan organization/ID exact dan
attempt harus masih memetakan organization+participant yang sama serta belum
revoked. Ini mempertahankan urutan authority participant→attempt→organization→
bill→allocations. Revocation atau stale mapping sesudah storage precheck menjadi
generic `NOT_FOUND`, tidak membuka oracle bill, dan object baru dibersihkan.

Existing proof all-or-none kini juga harus memenuhi regex namespace private key,
checksum lowercase 64 hex, MIME allowlist, ukuran 1–5.120.000, dan date cast yang
sah sebelum fingerprint dihitung atau old-key delete dijadwalkan. Timestamp masa
lalu tetap canonical dan replaceable dengan fingerprint exact; timestamp masa
depan fail closed sebagai `SCOPE_INVALID` karena writer hanya menghasilkan waktu
server current/past. Malformed cast disanitasi menjadi error domain. Path
traversal, prefix/case key salah, checksum uppercase, MIME asing, zero/oversize,
malformed/future timestamp tidak mengubah metadata, tidak menghapus old key, dan
hanya membersihkan object replacement baru.

Bukti RED: focused bertambah menjadi 37 tes dan menghasilkan **11 failure + 1
error, 142 assertions** pada expiry, participant race, canonical metadata, dan
malformed timestamp. Sesudah implementasi:

- storage focused **37/37 tes, 201 assertions**;
- storage + P11c1b manual review + provider finalizer **69/69 tes,
  368 assertions**;
- legacy upload **8 passed, 45 assertions**, dengan satu received-page failure
  yang tetap hanya `public/build/manifest.json` tidak tersedia; harness/build
  tidak diubah;
- PostgreSQL disposable penuh **271/271 tes, 1.907 assertions**, cleanup sukses.
  Test proses ganda baru menahan worker tepat sesudah private write, meng-commit
  soft-delete participant dari proses lain, lalu membuktikan recheck menolak,
  metadata tetap NULL, dan object dibersihkan. Run PG pertama mencapai 270 tes
  lain tetapi fixture self-payer baru gagal sebelum action karena composite FK
  immediate; fixture diperbaiki dengan remove/reinsert satu item dalam transaksi
  setup, lalu run kedua lulus penuh;
- Pint tiga file delta lulus; PHPStan seluruh project pada environment testing
  eksplisit lulus **0 error**; staged `git diff --check` lulus.

Tidak ada perubahan DTO/error contract, schema/config, finalizer/manual review,
HTTP/policy/UI/reader, provider/outbound, DB aktif, sandbox, deploy, atau push.
**STOP untuk review ulang P11c2a; P11c2b belum dimulai.**

## P11c2b — reviewer policy dan internal private-proof access

Commit `aa98577` mengekstrak `AssessmentBillProofIdentity` sebagai satu primitive
canonical untuk P11c1b manual decision, P11c2a storage/replacement, dan issuer
baru. Primitive menangani all-null, all-present, key namespace, checksum, MIME,
size, date cast, future timestamp, UTC serialization, dan formula fingerprint.
Store memakai primitive yang sama untuk proof lama maupun byte baru; finalizer
manual tidak lagi memiliki salinan formula sendiri. Provider finalization tidak
berubah.

Commit `458f327` menambah `AssessmentBillPolicy`, DTO akses opaque, dan
`AssessmentBillProofUrlIssuer`. Policy terpisah dari `OrderPolicy`; `viewAny`,
`view`, dan `viewProof` selalu reload actor persisted dan hanya menerima Admin
non-deleted dengan role `SuperAdmin`. BranchAdmin payer, Staff dengan legacy
flag, Psychologist, stale/deleted/unsaved Admin, guest, participant, dan uploader
tidak memperoleh reviewer authority. Ability/order policy legacy tidak dibaca
atau diubah.

Issuer internal hanya menerima Admin object dan reference `AB_`. Ambient RLS
context/outer transaction ditolak. Fase pertama service transaction mengunci
reviewer sebelum bill lookup, membaca routing hint sesudah authority sah, lalu
mengunci organization→bill→payment method dan snapshot fingerprint/key canonical.
Hanya `manual_transfer` dengan gateway/invoice NULL dan lima proof field valid
yang lolos. Missing, corrupt, non-manual, unauthorized, dan reference invalid
menjadi generic `ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND`.

Sesudah commit/context kosong, disk private existing `payment-proofs` menjalankan
`exists` dan `temporaryUrl` dengan durasi config existing 15 menit (validated
1–60). Driver exception disanitasi menjadi `...ACCESS_UNAVAILABLE`; object
missing menjadi not-found. Tidak ada storage I/O selama row lock/RLS context.
DTO hanya membawa URL opaque, expiry, dan proof fingerprint; object key/path
tidak menjadi property, audit, atau log.

Fase kedua mengunci ulang reviewer→organization→bill→method, menghitung ulang
fingerprint, dan menolak role/delete, replacement, metadata clear, channel/proof
change, atau row stale setelah URL dibuat. URL yang gagal recheck tidak
dikembalikan dan tidak dicatat. Hanya sukses menulis satu audit service-side
`assessment_bill.proof_temporary_url_issued`; context version 1 hanya memuat
source, fingerprint, dan URL expiry. Audit menyimpan actor admin/subject bill dan
retention dua tahun pada kolom typed, tanpa URL, object key, payer, participant,
atau data profil. Setiap access sukses adalah event audit baru; audit failure
rollback dan tidak mengembalikan URL.

### Bukti aktual

- RED awal: **20 tes, 0 passed, 12 assertions**, dua failure dan 18 error karena
  policy/issuer belum tersedia. Satu error fixture non-manual kosong ditemukan
  dan diperbaiki sebelum GREEN agar RED hanya mewakili kontrak yang hilang.
- Focused policy/issuer: **22/22 tes, 92 assertions**. Cakupan meliputi policy
  role/persistence/guest Gate, unauthorized-before-bill query, I/O tanpa locks,
  success/audit shape, actor delete/role race, replacement/clear race, object
  missing, exists/temporaryUrl failure, canonical proof matrix, audit rollback,
  repeated access, invalid reference, unsaved/deleted actor, dan ambient context.
- Gabungan issuer + P11c2a storage + P11c1b manual review + provider finalizer:
  **91/91 tes, 460 assertions**. Legacy manual proof access tetap **3/3 tes,
  10 assertions**.
- Full default `--exclude-group=sandbox`: **1.209 tes**, 1.186 passed,
  6.500 assertions; seluruh 23 failure adalah render halaman karena
  `public/build/manifest.json` tidak tersedia di worker. Tidak ada failure
  backend baru, skip, sandbox, atau external service.
- PostgreSQL disposable runtime non-owner: **272/272 tes, 1.918 assertions**,
  cleanup sukses. Test proses ganda menghentikan worker sesudah temporary URL
  dibuat, meng-commit soft-delete reviewer pada proses lain, lalu membuktikan
  return dan audit fenced. Run pertama menjalankan 271 tes lain tetapi fixture
  baru gagal sebelum issuer karena `paid_at` bawaan bertentangan dengan status
  pending; fixture state sintetis dilengkapi, lalu run kedua lulus penuh.
- Pint delapan file delta lulus. PHPStan seluruh project pada environment testing
  eksplisit lulus **0 error**. Staged `git diff --check` lulus pada kedua commit.

Tidak ada controller/route/Filament/resource/UI, decision wiring, perubahan
upload writer behavior, schema/migration/config/.env, command/job/scheduler,
purge, provider/notifier/outbound nyata, DB aktif, deploy, atau push. Temporary
URL diuji dengan fake/mock private storage; tidak ada credential/object store
nyata. **STOP untuk review P11c2b sebelum HTTP/UI atau P11c berikutnya.**

## P11c2c — adapter HTTP internal proof redirect dan manual decision

Commit kode/tes lokal `e4f82a9` menambah tiga file lane:
`AssessmentBillManualReviewController`, `ReviewAssessmentBillTransferRequest`,
dan test HTTP route-only. Tidak ada perubahan `routes/web.php`, route API,
bootstrap, controller legacy, policy, issuer, finalizer, storage writer, atau UI.

Method `proof` membaca actor hanya dari guard admin existing dan reference hanya
dari route. Guest/non-Admin menjadi generic 404. Controller tidak membaca bill,
amount, channel, object key, atau allocation; ia memanggil issuer dua-fase
P11c2b. Success melakukan redirect away ke URL opaque. Not-found/unauthorized/
missing menjadi 404 dan storage unavailable menjadi generic 503; driver path dan
credential tidak dipantulkan. Redirect serta seluruh error proof menetapkan
`Cache-Control: no-store, private`, `Pragma: no-cache`, dan
`Referrer-Policy: no-referrer`.

FormRequest decision hanya mengizinkan tiga root key. Fingerprint harus tepat 64
hex lowercase; decision enum hanya APPROVE/REJECT; rejection code enum bounded
wajib saat reject dan prohibited/NULL saat approve. Unknown key, bill reference
di body, amount, tipe salah, dan free text ditolak 422. `authorize()` hanya
memastikan user guard admin; role persisted tetap direload dan dikunci oleh
finalizer, sehingga stale/deleted/role-changed session tidak menjadi authority.
Failure authorization menjadi generic 404.

Method `review` membangun `AssessmentBillManualReview` dengan actor ID dari
server dan route reference, lalu hanya memanggil `ReviewAssessmentBillTransfer`.
Tidak ada settlement writer/predicate kedua. Typed NotFound dipetakan 404,
Conflict termasuk stale/opposite dipetakan 409, dan state/scope/channel/proof
invalid dipetakan generic 422. Unexpected exception tidak ditangkap sebagai
success. Response sukses minimal hanya `data.result` (`settled`, `rejected`, atau
`replayed`), tanpa amount, organization, item, participant, object key, atau PII.
Semua response controller juga private/no-store/no-referrer.

Tes mendaftarkan GET proof dan POST review dengan middleware group `web` hanya di
runtime test. Test membuktikan review GET menjadi 405, route POST memakai group
web yang memuat `PreventRequestForgery`, dan source route produksi tidak memuat
controller. PHPUnit menonaktifkan enforcement CSRF selama HTTP test seperti
standar framework; kontrak future route tetap POST di bawah middleware web.

### Bukti aktual

- RED: **23 tes**, 1 passed, 22 failed, 26 assertions, 1 risky. Seluruh failure
  adapter berasal dari controller yang belum ada; satu test method/absence route
  dapat lulus tanpa controller.
- Focused HTTP final: **27/27 tes, 196 assertions**. Cakupan guest, BranchAdmin/
  Staff legacy flag, Psychologist, deleted/role-changed SuperAdmin, strict payload
  matrix, route-body authority, proof redirect/privacy, storage unavailable,
  missing/invalid reference, stale/opposite conflict, approve/reject, exact
  replay, state/scope/channel/proof 422, response minimization, POST/web/CSRF
  contract, dan no production route.
- Gabungan HTTP + P11c2b issuer/policy + P11c2a storage + P11c1b manual review +
  provider finalizer: **118/118 tes, 656 assertions**, dijalankan serial agar
  `Storage::fake` tidak saling membersihkan direktori.
- Legacy proof access tetap **3/3 tes, 10 assertions**. Legacy upload menghasilkan
  **8 passed, 45 assertions** dan satu received-page failure karena
  `public/build/manifest.json` tidak tersedia; tidak dibuat manifest palsu atau
  perubahan harness.
- Full default final `--exclude-group=sandbox`: **1.236 tes**, 1.213 passed,
  6.696 assertions; seluruh 23 failure adalah render halaman karena Vite manifest
  worker tidak tersedia. Tidak ada failure backend baru, skip, sandbox, atau
  external service.
- Pint tiga file lane lulus. PHPStan seluruh project pada environment testing
  eksplisit lulus **0 error**. `git diff --check` dan staged diff-check lulus.

PostgreSQL tidak diulang karena source delta hanya controller/FormRequest dan
route sintetis test; tidak ada perubahan query lock, transaksi, RLS, schema,
atau concurrency primitive. Bukti authority race PostgreSQL P11c2b 272/1.918
tetap batas database terakhir. Tidak ada route/controller registration produksi,
Filament/resource/UI, decision UI wiring, schema/config/.env, command/job/
scheduler, purge, outbound nyata, DB aktif, deploy, atau push. **STOP untuk review
P11c2c sebelum route/UI.**

## P11c3a — reviewer Filament list/detail dan buka bukti default-off

Commit kode/tes lokal `d4eae99` menambah resource reviewer assessment bill yang
terpisah dari `OrderResource` legacy dan resource tagihan organisasi. Discovery
serta navigation hanya aktif ketika environment tepat `testing`; tidak ada
perubahan panel provider, route produksi, bootstrap, config, atau `.env`.

Resource memakai `AssessmentBillPolicy` existing pada list, detail, hydration,
dan direct record resolution. Hanya persisted active SuperAdmin yang lolos.
BranchAdmin pembayar, Staff dengan flag verifikasi legacy, Psychologist, guest,
serta SuperAdmin stale/deleted/role-changed ditolak. List unauthorized menjadi
403, sedangkan detail dan aksi record fail closed sebagai 404 agar reference
bill tidak menjadi oracle.

Query reviewer hanya memilih ID teknis record serta field aman: public reference,
nama organisasi, amount IDR, item count, status, proof upload time, verification
time, dan bounded rejection reason. Lima atribut proof privat tidak dipilih;
payment method manual dan lima metadata lengkap hanya menjadi predicate query.
Tidak ada relation peserta atau allocation yang dimuat. Pagination dibatasi 10
atau 25, default 10, filter status hanya pending/paid/rejected, dan urutan default
berdasarkan waktu upload. Detail hanya memakai infolist readonly; tidak ada form,
bulk action, upload, edit, delete, approve, reject, settlement, atau activation.

Livewire sempat menemukan gap saat GREEN awal: record terproyeksi aman pada mount
direhidrasi framework menjadi model dengan seluruh atribut sebelum action. Page
kini selalu memuat ulang record melalui query proyeksi resource pada setiap
hydration, lalu mengulang policy persisted sebelum action. Tes membuktikan key,
checksum, external candidate ID, dan nama peserta tidak masuk HTML maupun state
komponen sebelum atau sesudah redirect.

Action `Buka bukti` hanya mengirim actor guard admin dan public reference
persisted kepada `AssessmentBillProofUrlIssuer` P11c2b. URL opaque tetap variabel
lokal dan redirect effect; URL, object key, dan fingerprint tidak disimpan dalam
property, argument, notification, log, tabel, atau infolist. Livewire primitive
`disableBackButtonCache()` diaktifkan dan redirect response membawa no-store,
no-cache, serta no-referrer. Error storage/domain menjadi notification generik
tanpa detail driver; sukses tetap menulis audit issuer existing.

### Bukti aktual

- RED pertama: **6 tes, 0 passed, 2 assertions, 6 errors** karena resource/page
  belum tersedia; fixture kedua juga menemukan collision payment-method sintetis
  dan diperbaiki tanpa mengubah source produksi.
- Focused P11c3a final: **10/10 tes, 96 assertions**. Cakupan mencakup discovery
  testing-only, navigation authority, list/filter/pagination, query projection dan
  budget, safe detail/direct URL, seluruh role/guest/stale/deleted denial, revoke
  setelah mount, no mutation actions, opaque redirect/audit, state minimization,
  serta storage failure generik.
- Regresi P11c2b issuer **22/22, 92 assertions**; P11c2c HTTP adapter **27/27,
  196 assertions**; P11c1b manual finalizer **22/22, 102 assertions**. Bersama
  focused baru: **81 tes, 486 assertions**, seluruhnya lulus saat dijalankan
  serial.
- Legacy `ManualTransferFilamentTest`: **4/4 tes, 27 assertions** lulus dalam run
  terisolasi. Run campuran pertama menghasilkan empat error `branches` missing
  hanya setelah suite `DatabaseTruncation`, sesuai incompatibility reset strategy
  SQLite yang sudah diketahui; tidak ada assertion legacy yang gagal dan harness
  tidak diubah.
- Pint empat file kode/tes lulus. PHPStan seluruh project pada APP_ENV testing
  dan SQLite memory lulus **0 error**. Staged `git diff --check` lulus.

PostgreSQL tidak diulang: resource hanya menambah query baca terproyeksi dan tidak
mengubah RLS policy, lock, transaksi, schema, atau concurrency primitive; bukti
P11c2b terakhir tetap **293/293 tes, 2.519 assertions**. Test portal
`OrganizationBillAccessTest` tidak ada pada snapshot worker ini sehingga tidak
disalin dari root; koordinator perlu menjalankannya pada baseline integrasi.
Full default tidak diulang karena scope meminta focused regression dan worker
tetap tidak mempunyai Vite manifest; tidak dibuat manifest palsu.

Tidak ada route produksi, decision UI, public wiring, source/gate ON, provider,
notifier, object store nyata, DB aktif, migration/deploy/push, atau perubahan
legacy. **STOP untuk review P11c3a sebelum P11c3b.**

## P11c3b — keputusan reviewer terikat audit akses bukti

Commit kode/tes lokal `3d39987` menambah `ReviewAssessmentBillFromProofAccess`
dan dua action detail Filament testing-only. Resource tetap tidak ditemukan di
luar environment `testing`; tidak ada perubahan route, panel provider,
controller/request HTTP P11c2c, schema, config, atau source produksi.

Boundary baru menerima actor Admin, public reference, decision enum, dan bounded
rejection enum. Ia menolak ambient RLS context/transaction, membuka transaksi
service baca yang singkat, memakai `AssessmentBillPolicy` existing untuk reload
authority persisted, lalu mencari maksimal dua audit akses terbaru untuk actor,
bill, organization, action, subject type, dan subject ID exact. Audit bukan input
browser dan tidak ditulis oleh action decision.

Context audit wajib mempunyai tepat empat key dalam urutan issuer existing:
version 1, source `assessment_bill_manual_review`, fingerprint lowercase 64 hex,
dan `url_expires_at` ISO-8601 UTC. `occurred_at` hanya menerima bentuk timestamp
database UTC yang calendar-valid, tidak future; expiry harus future, sesudah
occurred time, dan maksimal 60 menit. Context malformed, extra key, source salah,
expired, wrong actor/bill, non-UTC/future/invalid date, atau missing ditolak.
Dua audit pada timestamp terbaru hanya diterima bila fingerprint dan expiry
identik; context berbeda pada timestamp sama dianggap ambigu dan fail closed.

Sesudah transaksi baca commit dan RLS context kosong, fingerprint server-held
dibungkus dalam `AssessmentBillManualReview` typed dan didelegasikan ke
`ReviewAssessmentBillTransfer`/`FinalizeAssessmentBill` existing. Karena itu
locked recheck actor, bill, channel, current proof fingerprint, allocation,
settlement, activation, outbox, audit decision, rollback, dan exact replay tetap
berasal dari writer canonical. Replacement/clear setelah open menjadi conflict
atau proof-invalid tanpa settlement. Boundary tidak memanggil issuer, tidak
membuat URL, tidak menulis access audit palsu, dan tidak mempunyai jalur paid
sendiri.

Detail reviewer kini menampilkan `Setujui pembayaran` dengan confirmation dan
`Tolak pembayaran` dengan select lima enum berlabel Indonesia; tidak ada free
text. Keduanya hanya visible pada record pending dari query aman. Missing/invalid
review context serta conflict/state/proof failure menjadi notification generik
yang meminta reviewer membuka bukti lagi; actor not-found/revoked menjadi 404.
Unexpected DB/programming exception tidak ditangkap dan transaksi finalizer
rollback. Sukses hanya menampilkan notification generik dan reload record lewat
proyeksi aman. URL, key, checksum, fingerprint, participant identifier, amount
override, atau allocation tidak disimpan dalam Livewire state/action argument.
Tidak ada upload/edit/delete/bulk action.

### Bukti aktual

- RED: **19 tes**, 1 passed, 32 assertions, 12 failures, 6 errors. Failure utama
  adalah action UI dan boundary audit yang belum ada. Fixture awal juga
  memperlihatkan settled item bawaan; fixture decision lalu dinormalisasi menjadi
  pending/locked tanpa mengubah source produksi.
- Focused P11c3b final: **26/26 tes, 134 assertions**. Cakupan: no-open denial,
  open→approve, open→reject + enum validation, exact replay, replacement/clear,
  expired/malformed/tampered/wrong actor/wrong bill/ambiguous audit, equivalent
  repeated access, timestamp canonicality, role/delete setelah modal mount,
  seluruh role/guest denial, ambient boundary, state/HTML secrecy, unexpected DB
  failure propagation, rollback, activation outbox, dan no duplicate audit.
- Gabungan P11c3a + P11c3b + P11c1b finalizer + P11c2b issuer: **80/80 tes,
  428 assertions**. P11c2c HTTP adapter tetap **27/27, 196 assertions**.
- Legacy `ManualTransferFilamentTest` terisolasi: **4/4 tes, 27 assertions**.
  `OrganizationBillAccessTest` tetap tidak ada pada snapshot worker dan tidak
  disalin dari root; root perlu menjalankannya pada integrasi.
- Suite XML terdaftar: **75/75 tes, 414 assertions**. Architecture: **2/2 tes,
  6 assertions**.
- Default synthetic final `tests/Feature tests/Unit --exclude-group=sandbox`:
  **1.272 tes**, 1.249 passed, 6.930 assertions. Seluruh **23 failures** berasal
  dari `public/build/manifest.json` worker yang tidak tersedia; 22 melaporkan
  Vite manifest langsung dan satu `SecurityTest` melaporkan Inertia invalid dengan
  exception Vite yang sama di log. Tidak dibuat manifest/build palsu atau
  pelonggaran harness.
- Pint empat file kode/tes lulus. PHPStan seluruh project pada APP_ENV testing
  dan SQLite memory lulus **0 error**. Staged diff-check lulus.

Satu run eksplorasi `tests` seluruhnya tidak dipakai sebagai bukti karena itu
juga memasukkan `tests/Postgres` tanpa runner disposable dan menghasilkan
`Target class [db] does not exist`; tidak ada DB PostgreSQL yang disentuh. Runner
PG tidak dijalankan: fase audit baru read-only tanpa row lock/concurrency writer,
sedangkan serialisasi dua reviewer, locked fingerprint recheck, rollback, dan
outbox sudah menjadi kontrak finalizer dengan bukti PG root terakhir **293/293,
2.519 assertions**. Tidak ada klaim concurrency baru dari SQLite.

Tidak ada production discovery/route, browser rollout, provider/storage call
baru, command/job/scheduler, outbound, DB aktif, migration/deploy/push, atau
perubahan OrganizationBills/Orders legacy. **STOP untuk review P11c3b.**

## P11c3c — acceptance browser reviewer testing-only

Increment ini menambah harness browser sintetis dan skenario Playwright CLI di
`tools/testing/tests/Browser` saja. Harness selalu memakai direktori temp baru
bernama acak, menolak `.env`, memaksa SQLite disposable, `APP_DEBUG=false`,
fake payment provider/notifier, `Http::preventStrayRequests()`, dan origin
loopback. Route kontrol hanya menerima header fixture khusus dan mengembalikan
status/counter tanpa identifier privat. Probe environment non-testing juga
membuktikan resource `assessment-bill-reviews` tidak ditemukan. Tidak ada route,
resource, panel provider, config, atau source produksi yang diubah.

Chrome cached yang nyata menjalankan alur SuperAdmin dari login, navigation,
list, detail, open proof, approve/reject, double-click replay, dan replacement
race. Alur approve tanpa membuka proof ditolak; approve setelah open membayar
sekali dengan satu decision audit, satu access audit, dan satu activation outbox.
Reject dapat dicapai lewat keyboard dengan focus-visible, tampil sebagai action
danger yang berbeda, Escape dan Cancel tidak memutasi, serta hanya menerima enum
alasan bounded. Replacement proof setelah open tetap pending tanpa decision
audit. BranchAdmin, Staff dengan flag legacy, Psychologist, dan guest tidak bisa
mengakses navigation/detail. Tiga console 404 pada probe direct-URL role terlarang
adalah hasil denial yang diharapkan; selain itu console dan daftar request gagal
kosong.

Pengujian juga memblokir seluruh origin eksternal dan memeriksa DOM serta URL
network agar tidak memuat nama peserta sintetis, external candidate ID, object
key, checksum, gateway reference, atau invoice URL. Fixture mengganti avatar
default dengan data URI lokal agar pengujian tidak mengirim alamat akun ke
layanan avatar eksternal. Ini hanya boundary testing; sebelum discovery produksi
diaktifkan, perilaku avatar panel produksi tetap perlu dinilai tersendiri.

### Bukti aktual

- Browser GREEN: **5 kelompok acceptance**, tanpa console error tak terduga dan
  tanpa request gagal/eksternal. Bukti visual aman tersimpan lokal sebagai
  `desktop-list.png`, `mobile-list.png`, dan `keyboard-reject.png`; file gambar
  tidak dimasukkan commit.
- Focused Livewire P11c3a: **10/10 tes, 100 assertions**; P11c3b:
  **26/26 tes, 134 assertions**. Total **36 tes, 234 assertions**, lulus serial
  memakai `phpunit.organization-payment.xml` dan SQLite memory.
- PHP syntax untuk server harness, Node `--check`, ESLint dengan dependency/config
  root yang cocok, serta Pint scoped seluruhnya lulus. PHPStan scoped harness
  lulus **0 error** dan `git diff --check` lulus.
- PostgreSQL tidak diulang karena increment hanya harness acceptance, tanpa
  perubahan schema, RLS, transaksi, lock, atau source produksi. Tidak ada klaim
  concurrency baru dari browser/SQLite.

Server Chrome/PHP, port 8023, browser session, marker lokal, dan seluruh direktori
temp/database sintetis dibersihkan setelah run. Tidak ada endpoint/source/gate
produksi yang diaktifkan, DB/data aktif, credential, outbound provider/notifier,
upload nyata, migration, deploy, atau push. **STOP untuk review P11c3c.**

## P13a1 — kontrak schema checkout handoff (2026-09-02)

Schema additive `checkout_handoffs` dan model internal telah dibuat sesuai
ADR-011 accepted. Kontrak menyimpan digest bearer dan idempotency tanpa raw
secret, composite attempt scope, lifecycle exact, TTL maksimum 600 detik,
one-active marker, serta service-only FORCE RLS. Belum ada issuer action, token
generation, route/controller, consume, session, atau wiring publik.

Bukti final: focused P13a1 **5 tes/32 assertions**; regresi migration SQLite
**52 tes/294 assertions**; PostgreSQL 17.6 disposable **298 tes/2.032
assertions** dengan runtime non-superuser/NOBYPASSRLS dan cleanup sukses; Pint
dan PHPStan scoped lulus tanpa error. Rincian desain, RED→GREEN, temuan runner,
file delta, dan batas verifikasi dicatat di
`tasks/organization-payment/reports/backend-p13a1-schema.md`.

Perubahan test-only pada `tests/Postgres/AssessmentBillingMigrationTest.php`
memastikan dependent handoff diturunkan sebelum ancestor billing dan dipulihkan
setelah ancestor up; perubahan ini dipisahkan dari commit schema/model. **STOP
untuk review P13a1 sebelum IssueCheckoutHandoff/P13a2/P13b/P14.**

### P13a1 review fix — durable composite scope

Review hardening menambah composite FK database-authoritative untuk
attempt+client+organization+participant+package serta
source+client+source-system+contract-version. Preflight rollback PostgreSQL kini
memeriksa history menggunakan service context lokal yang dipulihkan, sehingga
context kosong di bawah FORCE RLS tidak dapat menyamarkan row. RLS SELECT proof
memakai satu row nyata sebelum denial assertions.

Focused SQLite lulus **6/41**, related migration regression **53/303**, dan full
PostgreSQL disposable lulus **299/2.050** dengan cleanup sukses. Pint dan PHPStan
scoped lulus tanpa error. Deployment belum dijalankan; dua parent unique indexes
memerlukan maintenance window dan lock/statement timeout plan. Detail patch dan
batas ada di `backend-p13a1-schema.md`. **STOP review; belum ada action/config/
route/P13a2/P13b/P14.**

### P13a1 review fix kedua — complete durable graph

Attempt composite scope sekarang mencakup `source_system`; client juga terikat
ke organisasi melalui composite restrict FK `(integration_client_id,
organization_id)`. Migration menambah tiga parent unique indexes total. Negative
tests mengisolasi source sistem lain pada client sama dan client milik organisasi
lain; keduanya ditolak database, sementara graph valid tetap masuk. Focused
SQLite lulus **6/43**, related regression **53/305**, dan PostgreSQL disposable
**299/2.054** dengan cleanup sukses. Pint/PHPStan scoped lulus. **Tetap STOP
sebelum P13a2.**

## P13a2 — internal checkout handoff issuer (2026-09-02)

Core `IssueCheckoutHandoff` internal selesai tanpa route/controller/consume atau
session. Typed surface hanya menerima authenticated persisted client, public
attempt ULID, source selector, private sensitive idempotency key, dan enum
ISSUE/REISSUE. Fixed purpose/destination/checkout-v2 berasal dari server. Bearer
`och1_` berentropy 256-bit hanya disimpan sebagai SHA-256 digest; replay exact
tidak mengembalikan raw token.

Graph authoritative dikunci dalam urutan ADR-011, history/lifecycle diperiksa
fail closed, reissue terminalizes prior active secara atomik, dan satu audit aman
ditulis dalam transaksi. Focused GREEN **12/144**, related checkout/schema
regression **138/967**, Pint dan PHPStan scoped lulus. Config default-OFF/TTL 600
diserahkan sebagai patch kecil karena file config baseline untracked di worker.
Rincian bukti ada di `backend-p13a2-issuance.md`. PostgreSQL concurrency tetap
P13a3. **STOP review sebelum P13a3/P13b/P14.**

### P13a2 review fix — clock final dan rollback reissue

Issuer kini membaca database clock lagi setelah seluruh graph/history terkunci,
memvalidasi ulang effective window client/source, lalu memakai clock final itu
untuk seluruh transition, issue/expiry, dan audit timestamp. Package juga wajib
active, source-allowed, mempunyai item, amount non-null >=0, currency IDR, serta
consultation amount null atau >=0.

Test baru membuktikan application clock bukan authority dan audit failure pada
REISSUE mengembalikan old active byte-for-byte tanpa generation/audit baru.
Focused lulus **13/156**, related regression **139/979**, Pint/PHPStan scoped
lulus. Race waiter melewati `effective_until` dicatat wajib untuk P13a3
two-process. **STOP sebelum P13a3/route/P13b/P14.**

## P13a3 — PostgreSQL concurrency/authority evidence (2026-09-02)

Test dua proses PostgreSQL authoritative membuktikan same-ISSUE single commit,
distinct-REISSUE contiguous serialization, final active digest, post-lock
effective-window denial, serta kedua urutan linearizable issue/revocation.
Sembilan waiter benar-benar terlihat dengan `wait_event_type=Lock`; child hanya
mengirim credential-presence dan digest, tanpa raw bearer.

P13a3 menemukan bug nyata: PostgreSQL `CURRENT_TIMESTAMP` dibekukan pada awal
transaksi, sehingga clock kedua tetap stale setelah lock wait. Action kini
memakai `clock_timestamp()` hanya pada PostgreSQL dan mempertahankan
`CURRENT_TIMESTAMP` untuk SQLite. Focused PG lulus **8/113**, full disposable
**307/2.167**, related local **139/979**, Pint/PHPStan scoped lulus, dan seluruh
resource disposable dibersihkan. Rincian ada di
`backend-p13a3-postgres-concurrency.md`. **STOP sebelum P13b/P14.**

## P13b — atomic checkout handoff consume (2026-09-02)

Boundary internal `ConsumeCheckoutHandoff` kini menukar raw bearer `och1_` satu
kali menjadi `CheckoutSessionScope` typed. Input/raw dan digest tidak dapat masuk
descriptor, audit, exception, atau IPC; seluruh invalid/unknown/expired/revoked/
consumed/wrong-scope menghasilkan `CHECKOUT_HANDOFF_INVALID` yang sama. Action
memiliki service transaction sendiri, mengunci dan memvalidasi ulang graph
authoritative dengan urutan P13a, lalu atomik menulis `CONSUMED` serta satu audit.
Expiry boleh diterminalkan menjadi `EXPIRED` sambil tetap gagal generik.

RED awal **3 errors** karena boundary belum ada. GREEN feature consume akhir
**7 tes/64 assertions**, bersama issuer **20/220**. PostgreSQL disposable final
**310/2.210** membuktikan same-token single winner, consume-vs-reissue
linearizable, dan revoke-first denial dengan lock wait nyata sebagai runtime
non-owner/NOBYPASSRLS. Pint scoped dan PHPStan full lulus. Regresi Integrations
**191/192, 1.338 assertions**; satu batas existing ialah manifest Vite yang tidak
tersedia pada halaman SelectionLaunch, tanpa fake manifest/harness relaxation.
Rincian ada di `backend-p13b-core-consume.md`. Tidak ada route/controller/session,
config/schema, billing/access/order/outbox, source/gate aktif, outbound, atau P14.
**STOP untuk review P13b.**

## P14a0 — kontrak sesi checkout privat (2026-09-02)

ADR-012 revisi menolak global Laravel session untuk initial exchange. Top-level
cross-site POST dari kedua source nyata tidak mengirim cookie auth SameSite=Lax;
menulis cookie global bernama sama dapat mengganti pointer session existing yang
tidak terlihat request. Temporary config mutation juga request-unsafe pada
Octane. Pilihan proposed kini durable `checkout_sessions` + dedicated cookie
host-only Path `/checkout`, tanpa membaca/menulis cookie auth/global session.

Bearer tetap hanya exact POST form body dan sukses redirect 303 tokenless.
Session durable menyimpan selector/CSRF digest dan scope non-PII; seluruh mutation
setelah exchange memakai selector cookie + CSRF secret bound ke record. Consume+
record ditargetkan satu transaksi PostgreSQL, sedangkan cookie delivery tetap
crash window fail-closed tanpa klaim exactly-once.

Hydration setiap request reload graph persisted/latest handoff dan menolak role
participant/admin/branch/guest sebagai bypass. Output hanya attempt sendiri;
anggota/count/total batch, bill/gateway reference, invoice URL/proof, credential,
external identity, dan data klinis dilarang. Seluruh response pipeline harus
no-store/private/no-referrer dan credential failures generik.

Preflight menemukan dependency yang belum tertutup: issuer P13 tidak dapat
reissue setelah latest handoff CONSUMED, padahal kegagalan session write setelah
consume harus fail closed lalu recovery lewat generation baru. ADR menetapkan
amendment recovery terpisah sebelum route wiring; token lama tidak dihidupkan.
Rincian trust matrix, dua opsi, crash semantics, typed surface, dan RED matrix ada
di `docs/decisions/0012-private-integrated-checkout-session.md` serta
`backend-p14a0-private-session-preflight.md`. P14a0 hanya dokumen; belum ada
config/schema/route/controller/middleware/session/source aktif. **STOP review.**

## P14a1 — durable checkout-session schema (2026-09-02)

Migration additive `checkout_sessions` dan model internal kini menerapkan
kontrak ADR-012: ULID noncredential, digest selector/CSRF hidden lowercase hex,
composite scope handoff/attempt/client/tenant/source, lifecycle ACTIVE/REVOKED/
EXPIRED, one-active-per-attempt, index lookup/latest/expiry, serta FORCE RLS
service-only. Handoff/attempt cascade menginvalidasi session; client/source tetap
restrict. Tidak ada durasi UX proposed yang ditanam pada schema.

Down populated menolak sebelum DDL, empty down/up exact, dan parent preflight
fail closed. Dua test migration historis hanya disesuaikan untuk menurunkan child
session sebelum parent handoff secara transaksional. RED awal **5 tes** (1
failure/4 errors); GREEN focused **5/43**, related SQLite **11/86**, dan
PostgreSQL disposable penuh **330/2.299** dengan cleanup sukses. Pint lulus,
PHPStan full **0 error**, syntax dan diff-check bersih. Rincian ada di
`backend-p14a1-checkout-session-schema.md`. Belum ada action/cookie/CSRF runtime/
HTTP/config/cleanup/source aktif, migration DB aktif, outbound, deploy, atau
push. **STOP review sebelum recovery/action/wiring P14 berikutnya.**

## P13 recovery — explicit consumed-session reissue (2026-09-02)

Issuer internal kini menerima typed `CheckoutHandoffIntent::Recovery`. Setelah
seluruh authority dan graph persisted dikunci ulang dalam urutan canonical,
recovery hanya menerima latest handoff `CONSUMED` dengan tepat satu checkout
session `ACTIVE` yang exact dan belum due. Satu transaksi merevoke session dengan
`RECOVERY_REISSUED`, mempertahankan handoff lama CONSUMED, membuat generation
ISSUED baru, dan menulis satu audit aman. Raw bearer hanya keluar sekali.

Replay key/hash yang sama credentialless dan tidak menggandakan audit/transisi;
intent/scope conflict ditolak. Distinct recovery berikutnya, session missing/
terminal/due/foreign/corrupt, authority revoked, atau latest non-CONSUMED gagal
tertutup. Focused recovery+issuance+consume lulus **25/329**. PostgreSQL
disposable penuh lulus **333/2.349** dengan tiga race proses, lock wait nyata,
runtime non-owner/NOBYPASSRLS, dan cleanup sukses. Pint lulus, PHPStan full **0
error**, syntax/diff-check bersih. Rincian ada di
`backend-p13-checkout-handoff-recovery.md`. Tidak ada P14 establish/HTTP/cookie/
CSRF/config/migration/cleanup atau operasi aktif. **STOP review sebelum P14.**

### P13 recovery review-fix — terminal restart lifecycle

Recovery tidak lagi membuat natural EXPIRED atau LOGOUT menjadi jalan buntu.
Enum internal `CheckoutSessionRecoveryState` membatasi ACTIVE-undued,
ACTIVE-due, EXPIRED, dan LOGOUT. ACTIVE-undued direvoke
`RECOVERY_REISSUED`; ACTIVE-due diterminalkan EXPIRED dengan DB clock;
EXPIRED/LOGOUT existing tidak ditulis ulang. Audit merekam prior state allowlist
tanpa ID session/credential. SCOPE_REVOKED, future/corrupt/foreign history, dan
RECOVERY_REISSUED/REPLACED tanpa generation berikutnya tetap fail closed.

RED review-fix **6 tes**: 4 passed, 1 failure, 1 error. GREEN focused final
**26/348**. PostgreSQL disposable final **339/2.456** mencakup sembilan case
proses ACTIVE/EXPIRED/LOGOUT/SCOPE_REVOKED dengan lock wait nyata dan cleanup
sukses. Review juga menemukan mismatch rounding `timestampTz` PostgreSQL;
recovery kini membandingkan `clock_timestamp()::timestamptz(0)` pada presisi
storage. Pint lulus, PHPStan full **0 error**, syntax/diff-check bersih. Tidak ada
schema/HTTP/cookie/config/P14 establish atau operasi aktif. **STOP review.**

## P14a2 — atomic private checkout-session establishment (2026-09-02)

Internal `EstablishCheckoutSession` now exchanges one P13 bearer for one durable
checkout session without HTTP or Laravel's global session. The typed input holds
only the sensitive handoff bearer. The result returns newly generated
`ocs1_` selector and `ocsrf1_` CSRF secrets once through private properties;
only SHA-256 digests are durable, hidden on the model, and absent from safe
descriptors and audits.

P13 consumption was narrowly extracted into
`ConsumeCheckoutHandoffTransaction`, which requires an existing service RLS
context and transaction. The public P13 `ConsumeCheckoutHandoff` still owns its
transaction and keeps its prior signature/error contract. P14 owns one service
transaction and calls the same canonical validator/lock sequence before locking
checkout-session history, inserting the ACTIVE record, and writing the session
audit. Therefore an insert or audit failure rolls back handoff consumption,
both new audits, and the session. Package items are now locked as the complete
ordered set, consistent with issuer/recovery lock order.

Session time is derived from the database-clock consumption timestamp at storage
precision. Config is strict and bounded: enabled must be exactly true, idle
1..120 minutes, absolute 1..1440 minutes, terminal retention 1..365 days, and
idle cannot exceed absolute. Defaults remain OFF with 30/120/30. This worker's
`config/assessment_integration.php` is an untracked baseline file, so it was not
staged. The exact local delta after the existing `checkout` block is:

```php
    'checkout_session' => [
        'enabled' => false,
        'idle_minutes' => 30,
        'absolute_minutes' => 120,
        'terminal_retention_days' => 30,
    ],
```

The complete local config file SHA-256 is
`e5a60f58ae5bfe47f6d23aaf0173b2829d44a514bf476bd9cbf159387e948645`.

TDD RED was **6 errors / 17 assertions**, all caused by the intentionally absent
DTO/action. GREEN focused P13/P14 feature regression is **32 tests / 459
assertions**. PostgreSQL disposable full suite is **341 tests / 2,495
assertions**, including runtime non-owner/NOBYPASSRLS two-process same-bearer
serialization and establishment-vs-recovery linearizability with observed lock
waits; cleanup succeeded. Pint and `git diff --check` pass. PHPStan full-project
passes with **0 errors** under the test environment; an initial invocation without
`APP_ENV=testing` was correctly rejected by the production-config guard before
analysis.

Commits: `5c13097` (canonical transaction refactor) and `c4ff646` (establishment,
DTOs, feature/PG tests). No route, controller, middleware, cookie delivery, CSRF
verification, hydration, cleanup, source activation, global session mutation,
billing/access/order/outbox change, active migration, external call, deploy, or
push was added. Browser delivery remains a documented fail-closed crash window
requiring P13 recovery. **STOP review before P14 HTTP/hydration or P15/P16.**

## P14a3 — internal checkout-session hydrate/touch/logout lifecycle (2026-09-02)

`CheckoutSessionLifecycle` now owns each service RLS context and transaction for
two internal operations. `hydrate()` accepts only a private sensitive `ocs1_`
selector and no CSRF credential. `logout()` accepts private sensitive selector
and `ocsrf1_` CSRF values and compares both durable SHA-256 digests with
`hash_equals` after the canonical rows are locked. Malformed, unknown, replayed,
terminal, foreign, and corrupt credentials all produce the same
`CHECKOUT_SESSION_INVALID` boundary error without identifiers or credential
material.

The selector digest is only a bounded routing hint. Every operation locks and
reloads organization, client, source, package and all items, attempt,
soft-deletable participant, complete handoff history, and complete session
history in canonical order. It validates exact checkout-v2 scope, latest consumed
handoff generation, session-to-consume timing, lifecycle history, effective
client/source windows, package authorization, participant deletion, attempt
revocation, and server metadata. Natural idle/absolute expiry becomes EXPIRED;
persisted authority loss becomes REVOKED/SCOPE_REVOKED; valid logout becomes
REVOKED/LOGOUT. Each terminal transition and one safe audit are atomic. Replay
is generic and writes no second audit.

Successful hydrate advances `last_seen_at` using the database clock and refreshes
idle expiry to `min(database_now + configured idle, absolute_expires_at)` without
an audit, so read activity cannot extend the absolute lifetime. Its safe
`CheckoutSessionPrincipal` projection contains only own attempt/session scope,
assessment status/funding mode, and lifecycle timestamps. It excludes raw or
digested selector/CSRF, profile PII, credential references, batch membership or
counts, totals, bills, invoice/gateway references, proof data, and clinical
results.

Initial RED was **8 errors / 8 assertions**: seven missing boundary/DTO errors and
one test-fixture timestamp that correctly violated the existing schema CHECK; the
fixture was corrected to preserve canonical handoff/session time ordering before
GREEN. Focused P13/P14 SQLite regression is **28 tests / 372 assertions**.
PostgreSQL disposable full suite is **343 tests / 2,523 assertions**, including
runtime non-owner/NOBYPASSRLS two-process logout replay serialization and a
hydrate waiting on the organization lock before persisted client revocation;
cleanup succeeded. Pint, full-project PHPStan (**0 errors**), and `git diff
--check` pass.

Commits: `2e33e7c` (five-file lifecycle/credential/principal boundary) and
`9f35ddc` (feature and PostgreSQL race tests). Config remains default OFF and was
not changed. No controller, route, middleware, cookie/header/browser behavior,
global Laravel session, cleanup worker, source activation, billing/access side
effect, active database operation, outbound call, deploy, or push was added.
**STOP review before P14 HTTP wiring or P15/P16.**

### P14a3 review fix — canonical history invariant parity

Review correctly found that the lifecycle-local handoff history predicate was
weaker than P13 consume. `CheckoutHandoffHistoryValidator` is now the single
application-level source of truth used by both boundaries. It mirrors the schema
contract for every status: null pairing, consumed timestamp range, revoked reason
allowlist and `revoked_at >= issued_at`, expired timestamp boundary, fixed
scope/purpose/destination, TTL, issue sequence, and at most one active issuance.
Each caller retains its existing generic exception contract and lock order.

The P14 session-history validator was also brought to parity with relevant schema
checks: ULID and digest formats, nonblank source, `last_seen_at >= established_at`,
strict idle/absolute ordering, revoke at/after last-seen, expiry at/after the
effective idle/absolute boundary, exact terminal reason/null pairing, and
session establishment within the consumed handoff window. A model-only test
constructs states that database CHECK constraints intentionally prevent and
proves eight temporal/reason corruptions return false; the database-backed
corrupt issue sequence still fails without touch or audit.

Focused P13/P14 regression passes **42 tests / 538 assertions**. PostgreSQL
disposable full suite passes **343 tests / 2,523 assertions** with cleanup.
Pint, full-project PHPStan (**0 errors**), and diff-check pass. Fix commit:
`0422898`. No HTTP, route, cookie, source activation, or operational behavior was
added. **STOP review.**

## P14b0 — private checkout HTTP boundary preflight (2026-09-02)

ADR-012 now has an implementation-ready HTTP amendment without enabling any
route or code. It fixes four endpoints/methods, strict form-body bearer transport,
two exact trusted selection origins, destination host authority from reviewed
server configuration, generic redirect/error behavior, and a minimal route stack
that excludes Laravel's global `web` session/cookie/CSRF/auth middleware.

The amendment resolves the post-303 CSRF delivery gap explicitly. A successful
exchange will eventually set two host-only `/checkout` cookies: the HttpOnly
selector and an HttpOnly CSRF delivery secret, both Secure and SameSite Lax.
Database storage remains digest-only. GET hydration may project the raw CSRF value
from the delivery cookie into a server-rendered hidden field and page-local meta
only after selector plus CSRF digest verification. Every mutation must supply the
token explicitly through exactly one `_checkout_csrf` form field or
`X-Checkout-CSRF` header and pass constant-time cookie + DB digest comparison;
automatically sent cookies and SameSite alone never authorize mutation.

The contract also specifies login-cookie byte preservation, exact clear semantics,
privacy headers for every outcome, Origin checks, bounded IP-only limiter keys
without credential material, default-OFF ordering, recovery/crash behavior,
minimum own-attempt projection, test-only route registration, and a complete
cross-site/guest/IDOR/CSRF/replay/error matrix. Existing stack inspection confirms
`routes/web.php` receives the `web` group, so future checkout routes must use a
separate minimal registration rather than that file/group.

Documentation commit: `c62f215`. `git diff --check` passes. No PHPUnit/PG run was
needed because this increment changes documentation only. No controller, route,
middleware, config, cookie, source activation, environment, database, browser,
outbound operation, deploy, or push was performed. **STOP before P14b1.**

## P14b1 — test-only private checkout HTTP adapter (2026-09-02)

The adapter now composes the accepted P14a2 exchange and P14a3 lifecycle through
a minimal checkout-specific HTTP boundary. Production routes remain unchanged:
the four routes are registered only by `CheckoutSessionHttpTest`. Stack
inspection proves they do not inherit `web`, Laravel session, queued/encrypted
cookies, framework CSRF, or application auth middleware. The boundary is
default-OFF, accepts only the fixed `https://psikotes.oncam.id` destination and the two
reviewed exchange origins, rate-limits by IP and endpoint class, and applies
`no-store, private` plus the full privacy header set to success, validation,
authorization, throttling, unavailable, and rendered unexpected-error responses.

Exchange accepts one exact URL-encoded `handoffToken` field, never query, JSON,
cookie, or Authorization transport. Success issues exactly the host-only Secure,
HttpOnly, SameSite Lax selector and CSRF-delivery cookies on `/checkout`, replaces
attacker-supplied checkout cookies, preserves the unrelated login cookie, and
uses a 303 redirect. Hydration now has one canonical lifecycle primitive that
verifies both delivery-cookie digests in the same authoritative transaction
before rendering the CSRF value into the private page. Mutation accepts exactly
one strict form or empty-body header CSRF channel, requires the fixed destination
Origin, compares delivery and explicit secrets in constant time, and then calls
the existing canonical logout action. Missing, mismatched, expired, replayed,
foreign, and revoked credentials clear both checkout cookies generically; logout
replay creates no second audit.

Focused P14 HTTP/lifecycle/establishment regression passes **26 tests / 597
assertions**. The wider integration directory produced **223 passing of 224 tests
/ 2,069 assertions**; its sole failure is the pre-existing UI-only
`SelectionLaunchTest` because this worker intentionally has no
`public/build/manifest.json`. No fake manifest or relaxed harness was introduced.
PostgreSQL disposable full suite passes **343 tests / 2,523 assertions** and
cleanup succeeded. Full-project PHPStan passes with **0 errors** under the synthetic
testing environment; Pint and staged `git diff --check` pass.

Code commits: `ac17ffa` (canonical CSRF-delivery hydration), `b2ccd36` (five-file
HTTP boundary), and `0a17f34` (controller, private view, route-only feature tests).
The local untracked `config/assessment_integration.php` was deliberately not
staged. Its exact approved addition under `checkout_session` is:

```php
        'http' => [
            'destination_origin' => 'https://psikotes.oncam.id',
            'trusted_exchange_origins' => [
                'https://seleksi.beasiswajepang.id',
                'https://seleksi.serbaindo.com',
            ],
            'exchange_per_minute' => 10,
            'hydrate_per_minute' => 60,
            'mutation_per_minute' => 10,
        ],
```

The complete local config SHA-256 is
`bc2b1b72f8feb6efaf65193e9d66fba2947f532be4307582d04e46e065bdf3c1`.
No production route/bootstrap/global middleware, source activation, browser run,
billing/access/order/outbox behavior, active database operation, outbound call,
deploy, or push was added. **STOP for P14b1 review before production wiring or
P15/P16.**

### P14b1 review fix — destination, raw form, login isolation, named limiter

Review identified three acceptance gaps, all corrected without production
wiring. Destination authority now matches the repository contract exactly:
`https://psikotes.oncam.id`. The configured constant is strict, a request for the
shorter `oncam.id` host fails 404 before action, and no source-supplied return URL
or arbitrary Host becomes checkout scope.

One bounded raw URL-encoded parser is shared by exchange and form mutation. It
accepts only the canonical ASCII field/value byte sequence (maximum 128 bytes),
then requires the framework ParameterBag to contain exactly the same single
string. Duplicate valid-first/valid-last keys, brackets, percent encoding,
malformed/extra separators, unknown fields and collapsed-key disagreement fail
generically without echoing the credential. The progressive header channel now
also requires an empty body. Route-only tests register and inspect the named
`checkout-session-http` limiter; production provider/route registration remains
absent and default-OFF.

Login isolation now uses a real encrypted Laravel session cookie. A synthetic
user accesses a test-only `web`+`auth` probe with that byte, performs the
cross-site checkout exchange without sending the Lax login cookie, receives no
replacement login `Set-Cookie`, and accesses the authenticated probe afterward
with the original exact byte. This proves server-side session authority survives;
actual browser SameSite delivery remains explicitly reserved for browser
acceptance and is not claimed here.

Corrected focused P13/P14/HTTP regression passes **27 tests / 693 assertions**.
Full-project PHPStan passes with **0 errors** and Pint passes. The integration-wide
run was not repeated because `public/build/manifest.json` remains absent and no
fake manifest is permitted; the last run remains **223 passing of 224**, with the
single UI manifest failure already recorded. PostgreSQL was not repeated because
this review delta changes only the HTTP contract/request/middleware/test and ADR;
the lifecycle code and last disposable result (**343 / 2,523**) are unchanged.
No production route, global middleware/session/CSRF config, source activation,
browser operation, database operation, deploy, or push was added. **STOP for
review.**

## P14b2 — browser acceptance RED and ADR proposal (2026-09-04)

Harness commit `04f92fe` preserves baseline `a49354f` and the existing snapshot.
The three lane-owned browser files run real Laravel HTTP components through
isolated HTTPS loopback and a fresh synthetic SQLite database. The login probe
uses actual `web` + `auth` and exact user identity before/after both cross-site
exchanges. Cookie transport is untouched by interception; Laravel's installed
TrustProxies API supplies the correct loopback proxy boundary.

Both source exchanges, login byte/authority isolation, fixation/replay,
history/refresh, multi-tab, 1280/390/320px and keyboard, CSRF negatives,
progressive logout, expiry/revoke/recovery, privacy and controlled-network
assertions pass. The ninth canonical exchange succeeds without JavaScript, but
its native logout remains **RED: expected 303, got 419, Origin=null**. No-referrer
causes Chromium's native form to suppress Origin even though both cookies and
the canonical explicit CSRF are present. No production contract was weakened.

At coordinator request, the detailed [browser evidence and proposed ADR-012
amendment](backend-p14b2-browser-red.md) compares JS-required mutation against a
strict credential/CSRF-authenticated literal-null native branch. It distinguishes
missing Origin, opaque/foreign origins and optional Fetch Metadata rejection
signals, recommends a bounded logout-only amendment, and lists required negative
tests. It is a proposal, not an accepted rule or implementation.

Focused checkout regression passes **53 tests / 1,041 assertions**, with no skips.
Pint, Node/Python syntax, full-project PHPStan (**0 errors**) and staged diff-check
pass. PostgreSQL and unrelated UI regression were not repeated for this harness
delta; no Vite manifest was fabricated. The synthetic database has zero order,
bill/item/charge/entitlement/outbox rows, and disposable logs have zero raw
credential matches. Browser acceptance and P14 remain open. **STOP for review;
no production wiring, source activation, P15, deploy or push.**

The named browser and lane PHP/proxy processes are stopped; test ports are free.
Automatic execution policy blocked recursive removal of the lane's synthetic
temporary directories, so those scratch files were retained outside Git rather
than retrying through another mechanism. Cleanup details are in the report.

### P14b2 bounded native-logout fix — GREEN locally, review pending

Coordinator-approved option B is implemented in `6e0faea`: a single literal-null
Origin can qualify only for exact HTTPS POST `/checkout/logout`, with the existing
verified principal/cookie digests and canonical raw-form CSRF proof. Every present
Fetch Metadata field must individually match same-origin/navigate/document;
duplicates, lists, missing Origin, foreign values and header-only/double-channel
null requests are rejected. `mutationOriginMatches`, source exchange and future
mutation rules were not generalized. Lifecycle/transaction logic is unchanged.

RED reproduced native 419 and multi-value Origin acceptance; the new matrix is
**5 passed / 753 assertions**, full relevant P13/P14 **58 passed / 1,794 assertions**,
zero skips. Pint and full-project PHPStan (**0 errors**) pass.

Browser rerun passes nine exchanges and six hostile native forms, real Laravel
login/byte preservation, exactly one attempt-scoped LOGOUT audit, replay, and all
prior isolation/CSRF/history/recovery/expiry/viewports checks. Two opaque forms
are blocked by Chromium Local Network Access before HTTP; two known Playwright
sandbox instrumentation errors are counted explicitly. They are not represented
as server rejection or zero console errors. Four foreign/sibling forms reach HTTP
and are denied. Async observations are drained; no-JS cleanup uses `finally`.
Detailed decision, results and limitations are appended to
[the P14b2 evidence report](backend-p14b2-browser-red.md).

No new PostgreSQL or unrelated UI result is claimed for this HTTP-only delta.
Canonical ADR/integration remain for root review. No active route/config/source,
DB/.env, provider/notifier, deployment or push; no retry of blocked recursive
scratch cleanup. **STOP before P15.**

## P14c prerequisite — shared settlement reader (2026-09-04)

Code/test commit `90f3aa5923c07451e07bd65aaf6878bf024ad800` adds
`app/Services/Payments/AssessmentSettlementReader.php`, injects it into
`ActivateSettledAssessment`, and adds
`tests/Feature/Payments/AssessmentSettlementReaderTest.php`. The gate is also
updated locally, but remains untracked from the original snapshot: its exact
patch and before/after hashes follow, rather than committing the whole baseline.

This is a behavior-preserving extraction, not a summary projector or access API.
`isSettled(AssessmentCharge): bool` requires an existing service RLS context and
does not elevate a caller. Its documented precondition is a validated persisted
charge in the caller's authorized transaction, with existing scope/snapshot
checks and locks. It accepts neither request-selected IDs nor a browser paid
flag. It returns only settlement evidence, not entitlement or authorization.

The old gate predicate body compares text-identical after removal of the added
service-context guard and method rename. Activation had the same conditions,
with a local payer variable, different query predicate ordering, and collection
`count()` instead of `count(collection)`. Zero/free marker, absence of bill item,
own positive allocation, both payer-participant bindings, paid/item timestamps,
all-member positive/overflow-safe sum/count and timestamps are preserved.
Existing `now()`/Carbon handling is retained; no new captured/database clock,
query lock, transaction, policy check, business write or exception conversion.
Scope/snapshot and prerequisite checks remain in the callers at the same points.

Current policy OFF is **not** a new denial of historical paid evidence or
acquired ready rights: the characterization test explicitly disables client/
allowed payer policy and still reads settlement and the existing ready gate.
Policy wording in the P14c projection proposal remains unapproved for this case;
it was not implemented. Consent, identity and profile are tested independently:
paid remains settled while gate rejects and activation is a no-op, with no
activation audit/outbox or rebilling. No DTO/profile/consent/projector/HTTP work
is included in this slice.

### Actual verification

The new reader API first ran RED because the service did not exist (15 tests,
zero passing). Initial fixture setup also exposed composite FK enforcement and
an incorrect assumed client column; fixtures were corrected without disabling
constraints or changing production predicates. Final characterization has
**17 tests / 44 assertions**, including collective partial/future allocation,
self payer mismatch, count/total mismatch, pending/missing/future timestamps,
zero absent/future marker and unexpected item, no-context/admin/participant
denial, no business row changes, and paid prerequisites independent of access.
SQLite-only synthetic inconsistent rows are not claimed possible under PG CHECKs.

All following processes used `php vendor/bin/phpunit -c
phpunit.organization-payment.xml <file> --do-not-cache-result` (SQLite memory,
synthetic fixture, fake outbound). Final per-file results:

| File under tests/Feature | Tests | Assertions |
| --- | ---: | ---: |
| Payments/AssessmentSettlementReaderTest.php | 17 | 44 |
| Auth/AttemptEntitlementGateTest.php | 44 | 55 |
| Auth/SettledAssessmentActivationTest.php | 30 | 107 |
| Auth/AssessmentSessionAuthorizationTest.php | 27 | 141 |
| Payments/AssessmentBillPaymentFinalizationTest.php | 10 | 65 |
| Payments/AssessmentBillWebhookDispatchTest.php | 12 | 80 |
| Payments/AssessmentBillStatusReconciliationTest.php | 8 | 32 |
| Payments/AssessmentBillManualReviewTest.php | 22 | 102 |
| Payments/AssessmentInvoiceClaimTest.php | 60 | 444 |
| **Separate-process total, zero skips/failures** | **230** | **1,070** |

`tools/testing/run-org-postgres.ps1` passed the full existing worker PostgreSQL
suite: **343 tests / 2,523 assertions**, runtime non-owner/NOBYPASSRLS. This includes
the existing gate free/collective/scope cases, activation two-process concurrency
and savepoint rollback, and finalizer two-process settlement/rollback tests.
No PG test/harness/schema was changed. Network run label
`3b63745ae3b64bc9af1db43011e8b038` had no published ports; runner reported cleanup
complete, and exact-label container/network inventories were empty afterward.
The worker suite is its preserved snapshot, not a claim to contain every newer
root test. Two initial SQLite Docker runs completed independently; later SQLite
checks used installed local PHP 8.3.26 and the same guarded XML.

Pint passed on the four changed PHP paths. Full-project PHPStan passed with
**0 errors** using process-local testing/SQLite-memory/array settings. Its initial
invocation without testing environment was refused by the existing production
configuration guard, before a successful configured rerun; no .env was created
or changed. `git diff --check` and staged diff-check passed.

Broader combined-directory attempt is **not GREEN**: Payments plus three auth
files reported 582 tests, 469 passed, 2,817 assertions, one skip, a missing Vite
manifest failure in the legacy manual-payment received page, and missing
`branches` table errors in multiple classes in that combined run. Do not count
it as passed or attribute every error to the manifest. Claim and the affected
shared-payment paths pass when run as separate processes above; the combined-run
database-state interaction has not been fixed or fully diagnosed in this slice.
No fake Vite manifest, weakened harness, skipped test filter, or shared test-base
edit was used. Root full-suite validation remains a handoff limitation.

### Gate overlay for coordinator integration

Target: `app/Services/ParticipantAuth/AssessmentEntitlementGate.php` (untracked
worker baseline; root's before file was independently hash-matched).

- Before SHA256: `d9feedeeea8567e023fe813cd2ad53b3009696910839216920decb27caead34f`
- After SHA256: `1c9f033150e0962ed03d50dc15874272a7e8da53a327429b0a145888298517c9`

Apply only the following delta with the code commit; do not add the worker's
baseline gate file wholesale. This report is the second lane-only commit.
No root canonical document, frontend DRAFT, HTTP/route/config/source activation,
credential/.env, active data, provider/notifier, deploy or push was changed.
Blocked browser scratch cleanup remains untouched. **STOP for review before
any further reader/projector/consent/HTTP/P15 increment.**

```diff
diff --git a/app/Services/ParticipantAuth/AssessmentEntitlementGate.php b/app/Services/ParticipantAuth/AssessmentEntitlementGate.php
index 537a704..ab239c5 100644
--- a/app/Services/ParticipantAuth/AssessmentEntitlementGate.php
+++ b/app/Services/ParticipantAuth/AssessmentEntitlementGate.php
@@ -11,15 +11,14 @@
 use App\Security\RlsContextRunner;
 use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
 use App\Services\Payments\AssessmentPriceSnapshot;
-use Carbon\CarbonImmutable;
+use App\Services\Payments\AssessmentSettlementReader;
 use DomainException;
-use Illuminate\Support\Facades\DB;
 use LogicException;

 /** Internal read-only gate. Session creation must recheck this inside its own locking transaction. */
 final readonly class AssessmentEntitlementGate
 {
-    public function __construct(private AssessmentPriceSnapshot $prices, private AssessmentAccessPrerequisites $prerequisites) {}
+    public function __construct(private AssessmentPriceSnapshot $prices, private AssessmentAccessPrerequisites $prerequisites, private AssessmentSettlementReader $settlement) {}

     public function assertReady(AssessmentPrincipal $principal, string $testType): AssessmentEntitlement
     {
@@ -53,45 +52,11 @@ public function assertReady(AssessmentPrincipal $principal, string $testType): A
             ->where('organization_id', $principal->organizationId)->where('participant_id', $principal->participantId)
             ->where('charge_id', $charge->id)->where('test_type', $testType)->where('status', 'ready')
             ->whereNotNull('ready_at')->where('ready_at', '<=', now())->whereNull('started_at')->whereNull('completed_at')->first();
-        if ($entitlement === null || ! $this->settled($charge)) {
+        if ($entitlement === null || ! $this->settlement->isSettled($charge)) {
             throw new EntitlementLocked;
         }
         $this->prerequisites->assertSatisfied($participant, $testType);

         return $entitlement;
     }
-
-    private function settled(AssessmentCharge $charge): bool
-    {
-        if ($charge->amount === 0) {
-            return $charge->free_settled_at !== null && $charge->free_settled_at->lte(now())
-                && ! DB::table('assessment_bill_items')->where('charge_id', $charge->id)->exists();
-        }
-        $item = DB::table('assessment_bill_items as item')->join('assessment_bills as bill', 'bill.id', '=', 'item.bill_id')
-            ->where('item.charge_id', $charge->id)->where('item.organization_id', $charge->organization_id)
-            ->where('item.participant_id', $charge->participant_id)->where('item.payer_type', $charge->payer_type)
-            ->where('item.amount', $charge->amount)->where('item.currency', $charge->currency)
-            ->whereNotNull('item.settled_at')->where('item.settled_at', '<=', now())
-            ->where('bill.organization_id', $charge->organization_id)->where('bill.payer_type', $charge->payer_type)
-            ->where('bill.currency', $charge->currency)->where('bill.status', 'paid')
-            ->whereNotNull('bill.paid_at')->where('bill.paid_at', '<=', now())
-            ->where('bill.payer_participant_id', $charge->payer_type === 'self' ? $charge->participant_id : null)
-            ->where('item.payer_participant_id', $charge->payer_type === 'self' ? $charge->participant_id : null)
-            ->first(['bill.id', 'bill.amount', 'bill.item_count']);
-        if ($item === null) {
-            return false;
-        }
-        $members = DB::table('assessment_bill_items')->where('bill_id', $item->id)->get(['amount', 'settled_at']);
-        $total = 0;
-        foreach ($members as $member) {
-            $amount = (int) $member->amount;
-            if ($amount <= 0 || $total > PHP_INT_MAX - $amount || $member->settled_at === null
-                || CarbonImmutable::parse($member->settled_at)->gt(now())) {
-                return false;
-            }
-            $total += $amount;
-        }
-
-        return count($members) === (int) $item->item_count && $total === (int) $item->amount;
-    }
 }
```

## P14c — bounded SQLite lifecycle fix (2026-09-04)

Following accepted diagnosis `ac25a87`, code/regression commit
`60fa63b71aed711fdc7954b91003a2dd88ddb1dc` changes only three test files:

- `tests/OrganizationPaymentTestCase.php`: 14-line additive delta. Parent teardown
  remains inside try/finally; only recursive `DatabaseTruncation` users invalidate
  `RefreshDatabaseState::$migrated` in finally. This base already rejects anything
  except guarded testing SQLite memory. No connection/PDO cache, migration command,
  FK, configuration guard, application callback or exception is replaced.
- `tests/Support/OrganizationDatabaseLifecycleFixture.php`: exercises the actual
  guarded framework setup/teardown with RefreshDatabase or DatabaseTruncation.
  Truncation preserves the existing setup reset and zero outer transactions.
- `tests/Feature/Database/OrganizationDatabaseTraitIsolationTest.php`: ordered
  truncation→refresh, refresh→truncation→refresh, repeated truncation→refresh,
  normal refresh caching, and teardown-failure recovery. Each regression case has
  a fresh PHPUnit child process; multiple real application lifecycles run
  sequentially within that process. There is no mocked PDO or state-reset shim.

The shared base was already tracked and clean, so its small delta is committed
normally; no whole untracked snapshot or gate overlay was added. Production
settlement code remains unchanged in this increment.

### RED → GREEN and combined verification

After correcting the test fixture's access to the framework's protected callback
registration through a public fixture wrapper, the RED run had **5 tests,
1 passed / 4 failed, 48 assertions**. Three sequence cases and failure cleanup
reported the stale migrated flag; the normal RefreshDatabase cache control
already passed. After the base fix: **5 passed / 81 assertions**. The tests prove
schema and row isolation across lifecycles, transaction level 0 for truncation
versus 1 for refresh, cached PDO reuse for consecutive refresh lifecycles, app
destruction, and unchanged first-exception identity even when two teardown
callbacks throw and a later callback still runs. A refresh after the failed
teardown has a working empty schema. No callback exception is swallowed.

Both minimal commands in `backend-p14c-combined-test-diagnosis.md` now pass:
finalizer→claim **2/40**, reverse **2/40**. The same-process combined invocation
below passes **176 tests / 818 assertions**, zero failures/errors/skips:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillPaymentFinalizationTest.php tests/Feature/Payments/AssessmentInvoiceClaimTest.php tests/Feature/Payments/AssessmentSettlementReaderTest.php tests/Feature/Auth/AttemptEntitlementGateTest.php tests/Feature/Auth/SettledAssessmentActivationTest.php tests/Feature/Database/OrganizationPaymentTestEnvironmentTest.php tests/Feature/Database/OrganizationDatabaseTraitIsolationTest.php --do-not-cache-result
```

The finalizer, claim, reader, gate, activation and environment-guard cases share
the main PHPUnit process in that order, proving the original cascade is fixed.
Only the five lifecycle regression cases use the explicit isolated child-process
attribute to start their static-state experiments cleanly; each ordered sequence
itself is within one child. This is not a claim to have run the whole application
suite or the previously failed 582-case Payments-directory invocation again.

Pint passes for all three changed PHP files, and `php -l` passes for all three.
Scoped PHPStan passes with **0 errors on the two new regression/fixture files**.
Including the shared base reports one pre-existing `assign.propertyType` mismatch
on its unchanged assignment of `class_uses_recursive(static::class)` to vendor
`$traitsUsedByTest`: inferred `array<string,string>` versus the installed vendor
PHPDoc `array<class-string,int>`. A temporary source copy from
`git show ac25a87:tests/OrganizationPaymentTestCase.php` reproduced the
same error at original line 24 (current line 38). The actual pre-fix revision was
`ac25a87`; no checkout/reset or active configuration was used. No cast, annotation
override, suppression, vendor patch or unrelated assignment change was made to
silence it. Thus **all-three-file PHPStan is not claimed clean**; syntax/Pint and
new-file static checks are clean, with this known baseline issue disclosed.

PHPStan used process-local testing, synthetic APP_KEY, SQLite memory and array
cache/session settings; no .env was created or read for configuration changes.
PostgreSQL was not rerun because this is exclusively test-side SQLite lifecycle
state, with no DB/RLS/schema/application change. Sandbox remains intact,
`failOnSkipped=true` remains unchanged, and neither a credential/exclusion nor
fake Vite manifest was introduced. The separate skip/manifest findings remain
open under their own ownership.

`git diff --check` and staged diff-check pass. Code and this evidence report are
separate lane-only commits; baseline dirty state and blocked scratch cleanup are
preserved. **STOP for review before any further harness fix, projector/HTTP,
consent/P15, active data, outbound, deploy or push.**

## P14c — internal profile facts only (2026-09-04)

Code/test commit `0846e863d46147f8f981b438f8a2805aba6b6129` contains three new files:
`app/Data/Integrations/CheckoutProfile.php`,
`app/Services/Integrations/CheckoutProfileMapper.php`, and
`tests/Feature/Integrations/CheckoutProfileProjectionTest.php`. No existing
production class, validator, frontend DRAFT, schema, route or config is changed.

The readonly DTO stores seven nullable string facts and serializes exactly one
`profile` list in fixed order: fullName, birthDate, gender, educationLevel,
intendedField, email, phone. Each row contains key/label/state/required, and only
locked rows have displayValue. A null never becomes an empty placeholder or
default UMUM; email is optional. Arrays returned by serialization do not mutate
the DTO. There are no nested model/collection references in the DTO.

This deliberately remains an **internal profile-facts contract**, not a full
summary or drop-in frontend form contract. Input/options/autocomplete, callbacks,
payment/access/consent sections and action decisions are absent, not fabricated.
Human-readable gender/field labels are presentational mappings of existing enum
values. No P15 input contract or completion writer is established by these facts.

The mapper accepts a Participant graph and an explicit DateTimeImmutable as-of
calendar date. It performs no queries, writes, clock lookup, RLS context change,
authentication, or lazy relation load. The Participant argument is marked
SensitiveParameter. Before eventual use, the lifecycle boundary must revalidate
session/handoff/current authoritative scope and supply the fully loaded persisted
participant and date. Accepting a synthetic/in-memory model here proves no
authorization; there is no principal-based public entrypoint or controller.

Every profile column must be loaded: absent raw attribute fails closed, while
an explicit SQL NULL becomes missing. Valid nonnull values remain locked. Arrays,
booleans, numeric phone, blank strings, unknown enums, invalid dates/contact
formats or excessive lengths throw only `CHECKOUT_PROFILE_UNAVAILABLE`, without
echoing the value or attaching a validation exception/payload. Other model
attributes and relations are ignored by an explicit allowlist, not serialized.

### Existing-validator comparison and choices

Before implementation, the following differences were reported to coordinator:

| Criterion | Existing sources | Profile-only behavior |
| --- | --- | --- |
| Full name | Checkout and registration min2/max200; gate nonblank | Use checkout limits plus nonblank reviewed-table rule; do not normalize or overwrite stored text. |
| Education | Checkout/v1 string max64, no min2; registration min2 | Keep checkout behavior: a nonblank one-character value is valid. No invented education enum. |
| Phone | Checkout regex permits trailing punctuation/space; public registration requires digit ending | Keep checkout regex/max32, including an accepted `1234567)` edge value. Do not import the stricter public rule. |
| Birth date | Checkout/v1 and gate before today; public registration allows today | Follow reviewed checkout/gate table: before the explicit as-of day. Public registration is not changed or redefined. |
| Gender | Checkout input FEMALE/MALE; provisioning persists female/male | Validate persisted lowercase values, map to Perempuan/Laki-laki. Raw uppercase in a stored model is not silently repaired. |
| Intended field | Six existing enum values | Map each explicitly; invalid value fails, null stays missing, never fallback UMUM. |
| Email | nullable email:rfc/max255 | Optional, same RFC primitive without DNS verification; nonnull blank/corrupt values are not interpreted as SQL NULL. |

Rules use installed Laravel validation primitives, not a new handwritten email,
phone or calendar validator. The small profile rule subset mirrors checkout-v2;
no shared-request extraction was attempted in this ownership slice. Tests compare
accepted checkout edge values through the actual FormRequest rules. If ingress
rules later change, this mapper/compatibility matrix must be reviewed with them.
The more permissive gate is not treated as a complete ingress validator.

Persisted date representations differ from the request: a database DATE can be
`Y-m-d`, while Eloquent writes `Y-m-d H:i:s` for this model. The mapper validates
either exact format and the before-as-of condition, then returns the first ten
calendar characters. It never converts UTC or uses Carbon's permissive rollover
to rescue malformed data. Tests cover valid leap day, invalid leap-day rollover,
today/future, model-generated timestamp from a +14:00 date and three application
timezone settings. No minimum age or new business date restriction was added.

Own profile text is intentionally PII and stays private to the future authorized
consumer. Valid markup-looking names remain ordinary strings, never HtmlString,
HTML flags or rendered markup. This slice contains no HTML sink; future UI must
use escaped text rendering and the existing private response boundary. It does
not claim browser/XSS/page acceptance. Never log the DTO or treat its flags as
permission to edit, start tests, or grant rights.

### Actual evidence and limits

- RED before service/DTO existed: **49 tests, 0 passed**, missing mapper class.
- Final focused projection: **50 tests / 200 assertions**, zero failures/skips.
  Covers full/all-null/each-partial-null profiles, unloaded-column rejection,
  invalid types and values, each enum, existing-rule edge acceptance, date
  formatting, DTO immutability and recursive serialization key allowlists.
- Sentinel fields cover external IDs/source, model IDs/package/branch,
  registration credential/hash/test number, clinical and evidence metadata,
  invoice metadata and other-participant relation data. None enter output.
  A malformed unrelated date attribute is not cast merely to build a profile.
  Query log stays empty, RLS context stays null, and raw model attributes are
  unchanged during projection. These are pure in-memory synthetic fixtures,
  not proof of graph authorization or SQL isolation.
- Related combined run passes **172 tests / 833 assertions**, zero failures/skips:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutProfileProjectionTest.php tests/Feature/Integrations/CheckoutIntendedFieldContractTest.php tests/Feature/Integrations/CheckoutContractCompatibilityTest.php tests/Feature/Integrations/CheckoutProvisioningTest.php --do-not-cache-result
```

Pint and PHP syntax checks pass on all three new files. Full-project PHPStan
passes with **0 errors** using process-local synthetic testing/SQLite-memory/array
settings; no config file or .env changes. This is the existing application-path
PHPStan scope, not a claim that the separately documented vendor/test-base type
mismatch has been fixed. No PG or browser run was needed for this pure mapper;
no whole application regression, public summary, payment/access/consent projection
or P15 acceptance is claimed. Existing sandbox/manifest limitations remain.

`git diff --check` and staged diff-check pass; code/tests and this lane evidence
are separate scoped commits, leaving the dirty baseline and blocked scratch
cleanup untouched. **STOP for review before lifecycle wiring, more summary
sections, HTTP/frontend/P15, active data, outbound, deploy or push.**

## P14c — shared accepted-consent evidence (2026-09-04)

Commit `ff55a5ef8ca9f3ae85358bafc7b837d07113e66a` adds only
`app/Services/ParticipantAuth/AcceptedConsentReader.php` and
`tests/Feature/Auth/AcceptedConsentReaderTest.php`. A minimal local delta injects
the reader into `AssessmentAccessPrerequisites`; that file is still an untracked
initial snapshot and is **not** committed wholesale. Its patch/hashes follow.

The internal `isAccepted(Participant, string type): bool` loads the current
`ConsentDocument::for(type)` on every call and executes the extracted query.
Participant ID, consent type, document version, text hash and accepted status
must match; consented_at must be nonnull and <= the existing `now()`, and
withdrawn_at must be null. Query text compares identical to the original after
whitespace normalization. There are no new predicates, locks, writes, clock
capture, cache, attempt FK, policy/identity checks, or legal-approval conditions.

Call-site audit found exactly two application users of AssessmentAccessPrerequisites:
`AssessmentEntitlementGate` and `ActivateSettledAssessment`. Both retain their
existing service-context check, persisted scope loading, and profile/snapshot/
settlement validation. No direct manual prerequisite constructor call was found
in app/tests. The legacy `ParticipantEntitlementGate` does not use this prerequisite
and remains untouched. **No new reader context guard or role elevation is added**:
the original primitive had none, and the caller must still supply the authorized
persisted participant within its authorized RLS context/transaction. The reader
is not a safe public endpoint for request-selected participant IDs and does not
independently authorize a foreign model supplied by a caller. Its model argument
is marked SensitiveParameter; only a boolean escapes, not records or payloads.

Evidence stays participant-bound. It does not prove a new consent was captured
for a particular attempt or approve configured legal text. False covers missing,
declined, withdrawn, stale/mismatched or future-dated evidence; it is **not** a
declined UI state. No declined projection, consent writer, complete summary/DTO,
HTTP, frontend, schema or P15 behavior was introduced.

### Preserved configuration and prerequisite semantics

Document lookup stays after the existing profile checks and before identity
checks, once per required consent type. Invalid configured documents continue
throwing the existing InvalidArgumentException rather than being translated to
false or EntitlementLocked. For invalid profile plus invalid config, the earlier
profile EntitlementLocked still wins. The new dependency does not eagerly load
configuration in its constructor.

Changing text while keeping the version invalidates the old accepted hash;
changing the version also invalidates it. Changing title alone does not change
the predicate. The configured document is reloaded even when the same reader
instance is reused. Existing ConsentDocument allows empty string version/title/
text; a characterization test preserves that behavior with matching synthetic
evidence, rather than silently adding document/legal hardening in this extraction.
This is not approval to publish empty/draft documents. Any such policy change
requires its own decision. DASS acceptance remains additional only for dass21;
declined DASS does not block the main test's prerequisites.

### Actual validation

- RED: **20 tests, 2 passed**, remaining tests failed/errored because the new reader
  did not exist; the two existing prerequisite/config controls already passed.
- GREEN new reader suite: **20 tests / 41 assertions**, zero failures/skips.
  Covers accepted/current/exact-now, missing/type mismatch, same-organization and
  foreign-organization other-participant records, old version/hash, declined,
  withdrawn status/non-null withdrawal, missing/future consent timestamp, changed
  text with same version, title-only change, config errors, and DASS independence.
  SQLite can express some inconsistent timestamp rows that PostgreSQL CHECKs may
  already reject; this is not a claim those rows can be written through PG.
- One reader call issues exactly one SELECT EXISTS, no lock statement or other
  query; context identity and consent rows stay unchanged, with no audit/outbox.
- Combined new/gate/activation/start-auth/finalizer/legacy API regression:
  **135 tests / 427 assertions**, zero failures/errors/skips:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Auth/AcceptedConsentReaderTest.php tests/Feature/Auth/AttemptEntitlementGateTest.php tests/Feature/Auth/SettledAssessmentActivationTest.php tests/Feature/Auth/AssessmentSessionAuthorizationTest.php tests/Feature/Payments/AssessmentBillPaymentFinalizationTest.php tests/Feature/Auth/ParticipantApiAuthorizationTest.php --do-not-cache-result
```

`tests/Unit/Registration/ConsentDocumentTest.php` additionally passes **2/6** under
the same XML. Pint and PHP syntax pass for the three changed/new PHP paths;
full-project application PHPStan passes with **0 errors** using process-local
synthetic testing/SQLite-memory/array settings, without .env/config changes.
The separate test-base/vendor PHPDoc mismatch was not changed or claimed fixed.

No new PostgreSQL run: this is an equivalent extraction of the same query in the
same caller contexts/order; no SQL/RLS/transaction/clock semantics changed. The
last disposable PostgreSQL result belongs to its previously reported slice,
not this one. No browser or broad/full suite result is claimed. Existing sandbox
and manifest limitations remain independent and untouched.

### Prerequisite overlay — apply only this delta

Target `app/Services/ParticipantAuth/AssessmentAccessPrerequisites.php`:

- Before SHA256: `850fe2dda6b3976208929b04005bddbdacf7d35e05ed959f56da56e4b0e93b6e`
- After SHA256: `396d52139d61ee9c55d2a4de66b9f40328dbe1aee7f6f5a7427225c6f2330e4c`

The before hash matched the root file read-only. Pair the following patch with
the code commit; do not add the worker baseline file wholesale. This report is
the separate lane evidence commit. Diff/staged checks pass, cached paths are
explicit, and baseline/scratch are preserved. **STOP for review; no further
summary, consent/P15, public wiring, active data, outbound, deploy or push.**

```diff
diff --git a/app/Services/ParticipantAuth/AssessmentAccessPrerequisites.php b/app/Services/ParticipantAuth/AssessmentAccessPrerequisites.php
index 515464a..6797737 100644
--- a/app/Services/ParticipantAuth/AssessmentAccessPrerequisites.php
+++ b/app/Services/ParticipantAuth/AssessmentAccessPrerequisites.php
@@ -5,7 +5,6 @@
 namespace App\Services\ParticipantAuth;

 use App\Models\Participant;
-use App\Registration\ConsentDocument;
 use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
 use Carbon\CarbonImmutable;
 use Illuminate\Support\Facades\DB;
@@ -13,6 +12,8 @@
 /** Read-only prerequisites shared with the future settlement activation action. */
 final class AssessmentAccessPrerequisites
 {
+    public function __construct(private readonly AcceptedConsentReader $consents) {}
+
     public function assertSatisfied(Participant $participant, string $testType): void
     {
         foreach (['full_name', 'education_level', 'intended_field', 'phone'] as $field) {
@@ -27,12 +28,7 @@ public function assertSatisfied(Participant $participant, string $testType): voi
         }
         $types = $testType === 'dass21' ? ['psychotest', 'dass'] : ['psychotest'];
         foreach ($types as $type) {
-            $document = ConsentDocument::for($type);
-            $accepted = DB::table('consent_records')->where('participant_id', $participant->id)
-                ->where('consent_type', $type)->where('document_version', $document->version)
-                ->where('document_hash', $document->hash)->where('status', 'accepted')
-                ->whereNotNull('consented_at')->where('consented_at', '<=', now())->whereNull('withdrawn_at')->exists();
-            if (! $accepted) {
+            if (! $this->consents->isAccepted($participant, $type)) {
                 throw new EntitlementLocked;
             }
         }
```

## P14c — authorized profile read through canonical lifecycle (2026-09-04)

Code/test commit: `e27d3ae`. This is one internal profile-read increment from
worker HEAD `aa2390f`; it is not the complete P14 summary or P15. Only these
three tracked lane files changed in the code commit:

- `app/Actions/Integrations/CheckoutSessionLifecycle.php`
- `tests/Feature/Integrations/CheckoutSessionLifecycleTest.php`
- `tests/Postgres/CheckoutSessionLifecycleConcurrencyTest.php`

### Contract and transaction behavior

`readProfile(CheckoutSessionMutationCredentials): CheckoutProfile` accepts the
existing sensitive selector/CSRF pair only. There is no caller-selected tenant,
participant, attempt, principal, or generic projection callback. It reuses the
private `operate` path and the existing handoff history validator, with unchanged
organization -> client -> source -> package/items -> attempt -> participant ->
handoff history -> session lock order. All authority, current generation,
revocation, effective scope, and expiry checks precede projection.

The existing pure mapper receives the freshly loaded, validated participant while
those locks are held, plus the database clock converted to the server calendar
(current application timezone UTC). A stale principal cannot authorize a later
read. Only the existing seven allowlisted profile facts are returned; there are
no summary/payment/access/consent placeholders, billing parent fields, credentials,
metadata, or cross-tenant profile fields. NULL remains missing, with no fabricated
profile/default intended field. Actor contexts including service and every admin
role are rejected at entry, as are outer transactions; possession of ordinary
login or an old DTO is not a bypass.

This is a profile read, **not a transaction without writes**: successful reads
retain the existing last-seen/idle refresh capped by absolute expiry. Observed
expiry/scope revocation retains its canonical terminalization and audit before
generic rejection; replay does not add audits. Mapper failure propagates the
existing generic profile error and rolls back the idle update. No billing,
allocation, entitlement, consent, participant, attempt, or outbox writes were
added. RLS context and transaction ownership are restored on success and failure.

### Actual verification

- RED: eight new profile tests failed because `readProfile` did not exist. An
  initial test fixture used nonexistent RLS role `admin`; it was corrected to
  existing roles before the final RED run (8 errors, all missing method).
- GREEN focused new cases: **8 tests / 134 assertions**, no failures/skips.
  Covers strict signature/output, fresh own profile vs foreign sentinel, malformed
  and mixed-tenant credential pairs, real recovery generation and logout, current
  client/source/attempt/participant revocation, corrupt history, terminal expiry
  replay, ambient contexts/transaction/config OFF, mapper rollback, and database
  calendar despite deliberately stale/future application test clocks.
- Related SQLite-memory regression: **116 tests / 2,128 assertions**, all passed:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutSessionLifecycleTest.php tests/Feature/Integrations/CheckoutProfileProjectionTest.php tests/Feature/Integrations/CheckoutSessionEstablishmentTest.php tests/Feature/Integrations/CheckoutSessionHttpTest.php tests/Feature/Integrations/CheckoutHandoffConsumeTest.php tests/Feature/Integrations/CheckoutHandoffRecoveryTest.php tests/Feature/Integrations/CheckoutHandoffIssuanceTest.php --do-not-cache-result
```

An earlier combined invocation referenced a nonexistent `CheckoutProfileMapperTest`
filename and ran no tests; the corrected command above is the actual result.
The guarded XML/base kept SQLite `:memory:`, fake payment/notifier and
`Http::preventStrayRequests`; no runtime guard was loosened.

- Existing PostgreSQL disposable runner `tools/testing/run-org-postgres.ps1`:
  **347 tests / 2,567 assertions**, no failures/skips, exit 0. The four new tests
  reuse the established two-process socket barrier and observed PostgreSQL lock
  wait, with separate runtime non-owner/NOBYPASSRLS connections. They prove:
  recovery commit before projection rejects the old generation; committed scope
  revoke rejects and terminalizes once; rolled-back recovery permits the original
  generation; projection holding the canonical organization lock completes before
  the waiting recovery, and subsequent use of old credentials is denied. No PII
  or raw credentials are sent over the test IPC. Runner-owned containers/network
  were cleaned up; application containers were not targeted.
- Scoped Pint, syntax lint on all three changed PHP files, full application
  PHPStan (**0 errors**) and `git diff --check` passed. PHPStan used process-local
  synthetic testing/SQLite-memory/array configuration; no `.env` was read/created.

### Boundaries and handoff

No mapper/DTO/validator/shared config/schema/middleware/controller/route/harness
rewrite, frontend, operational wiring, source activation, active data, outbound,
provider calls, notification delivery, deploy or push. No new browser run or full
application suite claim: existing independent Vite-manifest/sandbox limitations
remain as previously reported. SQLite tests do not prove locks/RLS; the separate
PostgreSQL run does. No new canonical document was edited in this worker.

Code commit cached paths were exactly the three paths above. Existing dirty and
untracked baseline overlays and scratch files remain untouched and unstaged.
This report is a separate lane-only commit. STOP for coordinator review before
any further summary/HTTP/P15 work.

## P14c — own-attempt payment facts, credential lifecycle (2026-09-04)

One authorized internal payment-facts increment from `ad756a0`, split into two
bounded code commits:

- `53fc8da`: `CheckoutPaymentFacts` immutable DTO,
  `CheckoutPaymentFactsReader`, `CheckoutPaymentFactsTest` (three new files).
- `cf0c1eb`: closed `CheckoutSessionOperation` enum, existing
  `CheckoutSessionLifecycle`, its feature tests, and existing PostgreSQL lifecycle
  concurrency tests (four files).

This report is separate. There is no full-summary/HTTP/frontend/P15 implementation.
No shared snapshot baseline file is part of either code commit.

### Authority, projection and historical payment

`CheckoutSessionLifecycle::readPayment(CheckoutSessionMutationCredentials)` is
credential-only. It reuses the same persisted graph, handoff history, current
session/recovery, scope and database expiry validation as other lifecycle calls.
The internal operation enum replaces the accumulated boolean operation flags;
no public operation selector or generic callback was introduced. Other public
hydrate/profile/logout methods retain their contracts. Mapper/reader failure
rolls back the existing idle touch and restores context. Successful reads retain
idle refresh, and canonical expiry/revocation may still terminalize/audit; this is
not a claim that the entire lifecycle is write-free.

The service reader takes only the lifecycle's freshly validated attempt graph
inside its existing service transaction. It does not elevate RLS and explicitly
rejects other/no contexts. As with the accepted settlement reader, an arbitrary
model is not authorization proof; it is not an external entrypoint. The lifecycle
never accepts caller-selected attempt/participant/bill IDs or a stale principal.

Output is exactly `payment` with `payer`, `state`, `amountIdr`, `amountSource`,
`consultationRequested`, and `actionAvailable=false`. Payer comes from persisted
funding lifecycle, checked against the mandatory initial snapshot without
inferring/backfilling a missing key. Initial null may precede a persisted choice;
a selected initial value cannot change. No current resolver auto-selection is
presented as a persisted choice.

Only a valid own charge supplies amount and consultation choice. The canonical
`AssessmentPriceSnapshot::fromCharge` validates snapshot identity/version/scalars,
package, amount, currency and consultation linkage. DTO transport accepts only
IDR integer values in 0..9,007,199,254,740,991, with no rounding/clamping. Provenance
is `charge_snapshot` or `unavailable`; no charge means amount and consultation are
both null, even when the catalog has a price. Catalog changes cannot replace a
frozen charge amount. This slice deliberately implements no catalog estimate.

The reader validates own charge/attempt/org/participant/package/payer and own
allocation amount/currency/payer linkage, then calls the accepted shared
`AssessmentSettlementReader` for paid/free evidence. Paid does not depend on
consent, identity, profile completeness, ready entitlements, or today's purchasing
policy. Zero alone is not free: canonical nonfuture free marker and absence of
allocation remain required. Scope/session revocation remains an authentication
barrier; a disabled integration client is not treated as a purchasing-policy
bypass into the lifecycle. Existing gate/access code is unchanged.

State mapping: null payer without charge is `unselected`; selected self is
`unpaid`, organization `unbilled`; reserved/issuing is `preparing`; pending remains
`pending`; unknown is `recovery_required`; terminal `expired`/`rejected` remain
terminal; valid shared settlement yields `paid`/`free`. Mixed/corrupt/future payment
evidence is generic `CHECKOUT_PAYMENT_UNAVAILABLE`, never inferred unpaid with an
action. All states have action false. No reinvoice, lookup, provider call, payment
writer, activation, consent, entitlement or outbox write occurs in this reader.

Parent bill totals/member counts/URLs/references/proof, other participants,
clinical fields, model metadata and credential values are absent from the DTO.
The settlement primitive may read collective amounts/timestamps internally for
integrity, but returns only the existing boolean evidence to this projection.

### Lock contract and real interleavings

Read-only audit confirmed reservation, finalizer/activation and invoice outcome
persistence acquire the organization mutex before their billing/attempt graph.
The lifecycle already holds this mutex throughout projection. The new reader adds
no lock on bill/item/charge after attempt/session locks; it performs ordinary
reads in the same transaction. No lock-order contract change was needed. This
protects against the existing cooperating writers, not arbitrary SQL that ignores
the mutex. Purchase-policy writers also use the same mutex, but no separate
concurrent policy-writer test is claimed in this increment.

Three new PostgreSQL tests reuse the existing synchronized process/socket harness:

1. A profile-incomplete attempt's real finalizer commits while payment read waits
   on the organization lock: the read sees paid and its own allocation only, with
   no entitlement/activation outbox created for the incomplete profile.
2. The same finalizer is rolled back while read waits: the read sees pending,
   never a partially paid bill/allocation.
3. Read owns the organization lock first, sees pending and completes; the waiting
   finalizer then commits, and a subsequent read sees paid. A query observer
   rejects any new billing FOR UPDATE in the projection, preventing a false
   lock-order claim. All reader exits check restored context/transaction.

These tests use synthetic normalized payment events and an isolated synthetic
payment-method record. They do not simulate provider network acceptance or claim
payment-method activation. Current purchasing policy is OFF in the PG fixture;
historical finalization and payment facts still work. The public gate stays OFF.

### Actual TDD and validation

- Reader RED: **29 tests, 0 passed** (28 errors / 1 expected-message failure),
  because the reader did not exist. Additional scope/consultation/zero tests were
  subsequently added. Lifecycle RED: **4 tests, 0 passed**, missing `readPayment`.
- Reader + canonical settlement/gate/activation regression: **125 tests / 299
  assertions**, all passed. Includes collective ten with exact recursive output
  keys, immutable catalog snapshot, missing prerequisites/current policy OFF,
  no-charge null price, initial history, all status mappings, zero/free/future,
  same participant other attempt, internal foreign graph mismatch, wrong self
  payer allocation, JS safe range and no business writes.
- Combined related checkout/settlement/finalizer/gate/activation: **255 tests /
  2,525 assertions**, all passed with `phpunit.organization-payment.xml`:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutPaymentFactsTest.php tests/Feature/Integrations/CheckoutSessionLifecycleTest.php tests/Feature/Integrations/CheckoutProfileProjectionTest.php tests/Feature/Integrations/CheckoutSessionEstablishmentTest.php tests/Feature/Integrations/CheckoutSessionHttpTest.php tests/Feature/Integrations/CheckoutHandoffConsumeTest.php tests/Feature/Integrations/CheckoutHandoffRecoveryTest.php tests/Feature/Integrations/CheckoutHandoffIssuanceTest.php tests/Feature/Payments/AssessmentSettlementReaderTest.php tests/Feature/Payments/AssessmentBillPaymentFinalizationTest.php tests/Feature/Auth/AttemptEntitlementGateTest.php tests/Feature/Auth/SettledAssessmentActivationTest.php --do-not-cache-result
```

- PHPStan initially reported redundant null checks and nullsafe/coalesce access.
  These were corrected without suppressions after consulting official
  [nullsafe.neverNull](https://phpstan.org/error-identifiers/nullsafe.neverNull)
  and [identical.alwaysFalse](https://phpstan.org/error-identifiers/identical.alwaysFalse)
  guidance. Full application PHPStan then passed with **0 errors**; final focused
  reader/lifecycle rerun passed **57 tests / 342 assertions**. The 255-test result
  preceded these semantics-preserving type cleanups; the 57-test and PG results
  exercise the corrected code.
- Disposable `tools/testing/run-org-postgres.ps1`: **350 tests / 2,597 assertions**,
  zero failures/skips, runtime non-owner/NOBYPASSRLS, observed lock waits and
  independent connections. Cleanup completed successfully; application containers
  were not targeted. SQLite is not claimed as locking/RLS evidence.
- Scoped Pint, PHP syntax checks for all seven PHP files, and `git diff --check`
  passed. Testing XML uses SQLite memory/fakes/preventStrayRequests; PHPStan uses
  process-local synthetic testing/SQLite-memory/array settings, no `.env` changes.

No full application/browser/public E2E run is claimed; previous independent
manifest/sandbox limitations remain. No schema/config/source activation, writer,
provider, notifier, command/job/scheduler/route, active DB/data, deployment, push,
new task or agent. Baseline dirty overlays and blocked scratch remain untouched.
Explicit cached path checks preceded each commit; no snapshot baseline was staged.
STOP for coordinator review before further P14 summary or P15 work.

## Identity writer/read diagnostic after composition proposal adce9c3

Diagnostic-only handoff; no production writer or summary implementation changed.
See [identity read diagnostic](backend-identity-read-diagnostic.md) for the source
audit, controlled interleavings, fixture corrections and verification limits.

The actual StoreIdentityEvidence replacement committed while canonical
organization/attempt/participant locks were held: the safety regression remains
**RED**. Initial INSERT did block on the parent. A later evidence timestamp made
the gate fail closed; same-second mixed old verification/new evidence produced
ready before final pending became locked, but that does not prove never-valid
readiness because the initial state was ready. Caught-exception rollback restored
identity rows and synthetic file inventory. Activation's verification-before-
evidence order differs from the writer's evidence-before-verification order;
adding child locks is not an approved fix.

Final established disposable PostgreSQL run: **355 tests / 2,687 assertions /
1 failure / 0 errors / 0 skips**, exit 1; cleanup completed. The failure is the
new mutex expectation, while four new characterization/control tests and 350
existing tests pass. Two preceding runs failed new-test setup (missing GD, then
fake disk name length), documented without treating them as race evidence. Pint,
PHP syntax and diff checks passed. No new PHPStan/full SQLite/browser result is
claimed for this test-only diagnostic. No harness/schema/source activation,
active DB/.env/data, provider/notifier, deployment/push or new task/agent.
Commit only the new PostgreSQL test and these two lane reports. STOP for review;
do not proceed to production mutex changes or full summary composition.

## Bounded identity participant-mutex fix after diagnostic review

Coordinator explicitly approved this follow-up after reviewing `f1c5d63`.
The [historical diagnostic report](backend-identity-read-diagnostic.md) remains
unchanged: its RED result describes the pre-fix writer, not the current one.
No never-valid readiness or activation-deadlock claim is added.

### Production delta and callers

One production line changes in tracked `app/Actions/Identity/StoreIdentityEvidence.php`:

```diff
- Participant::query()->findOrFail($participantId);
+ Participant::query()->lockForUpdate()->findOrFail($participantId);
```

Before SHA-256 (also matched read-only root):
`d6498f98ef3b07db5997bcbdb7afc63ce2507bf56d000ff06c1e120f7027ef05`.
After SHA-256:
`1ecdb7e2ed2f040faac93eb8c903440dd20e139bec353869ad6ca04d016135a5`.
No snapshot overlay is included. Installed Laravel Query Builder's lockForUpdate
uses the write connection and the existing service transaction holds the participant
lock before either child-table read/write. No organization lock after participant,
new child lock, isolation-level change, auth change, matcher or storage rewrite.

The application-wide caller search found only IdentityEvidenceUploadController,
reached through `/registration/identity-evidence` with web middleware, throttle
and StoreIdentityEvidenceRequest. The request retains its registration-session
authorization and image validation; the controller does not open an outer DB/RLS
transaction. Bootstrap's web middleware does not install an RLS transaction for
that route. No job/other action calls the writer; identity model sources/providers
show no observer/touch path taking an organization lock after participant. The
matcher remains the local ManualReviewIdentityMatcher.

Activation already takes organization/bill/items/attempt/participant before
verification/evidence. A writer holding participant now never requests organization
or attempt; a canonical reader waits before accessing the child rows. This removes
the demonstrated gap for participants sharing that mutex without reversing the
organization-first order. This is not a claim that raw gate calls without the
canonical mutex gain a single snapshot, or that every possible writer is covered.

Storage remains pre-stored before the transaction; caught failures delete new
objects; successful action completion deletes replaced old objects afterwards.
`RlsContextRunner::run` still rejects ambient RLS, and StoreIdentityEvidence catches
that failure and removes pre-stored objects. A hypothetical caller that opens a
bare outer DB transaction without RLS could still reach old-file cleanup after a
savepoint but before outer commit. That is an existing composition limitation,
not an approved reuse contract; no such production caller was found. Future nested
reuse or a remote matcher/storage change needs separate approval. Process crashes
between filesystem and DB work and remote cleanup failures remain unverified.

### PostgreSQL regression and test isolation

The original mutex assertion is retained. Reader-first tests at different and
same-second timestamps now require the writer to block, evidence keys to remain
the old revision during the gate read, old ready before release and pending/locked
after the replacement commits. Writer-first holds the actual action after its
participant SELECT and verifies the canonical reader waits and sees committed
pending identity. Two actual writers must serialize and leave exactly the two
current referenced objects. Initial INSERT/FK control and post-verification UPDATE
rollback remain covered; the latter preserves all rows and prior file inventory.

Test-only listeners use cloned connection dispatchers. The parent gate listener
is restored in finally; child listeners remain in their process. Fixture DB cleanup
uses finally-protected storage/matcher/clock restoration. A paused writer is aborted
and its response drained before the socket closes; a dedicated test checks that
abort rolls back and removes its pre-stored files. Child result writes are caught
and followed by explicit process exit, preventing inherited PHPUnit continuation.

The first expanded RED run exposed an IPC cleanup defect (broken pipe allowed a
child to unwind into inherited PHPUnit and contaminated later suite output).
That run is not used as a clean regression count. The test-only abort/drain and
child-exit correction was applied before repeating RED; no assertion was disabled.
Clean RED on the unchanged writer: **358 tests / 2,730 assertions / 5 failures /
0 errors / 0 skips**. Failures were the retained mutex assertion, both reader-first
coherence cases, writer-first blocking and two-writer blocking. Initial INSERT,
rollback and abort cleanup controls passed. Disposable resources were cleaned.

GREEN established `tools/testing/run-org-postgres.ps1`: **358 tests / 2,753
assertions / 0 failures / 0 errors / 0 skips**, exit 0. This includes all eight
identity concurrency/cleanup tests and the existing 350-test PG suite. Actual
action/local matcher, independent processes, observed parent blockers, runtime
non-owner/NOBYPASSRLS and isolated synthetic storage were used. The original
participant-mutex RED is now GREEN. Cleanup completed; application containers
were not targeted. SQLite is not offered as row-lock/concurrency evidence.

### Other actual validation and remaining boundary

SQLite-memory XML regression command:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Identity/IdentityEvidenceUploadTest.php tests/Feature/Identity/IdentityEvidenceAccessTest.php tests/Feature/Auth/AttemptEntitlementGateTest.php tests/Feature/Auth/SettledAssessmentActivationTest.php --do-not-cache-result
```

Result: **81 tests / 218 assertions, 80 passed, 1 failed**. The authorized upload
test reaches the subsequent received-page render and fails because this worker
has no `public/build/manifest.json` (ViteManifestNotFoundException). This known
artifact limitation was not bypassed with fake Vite, skipped assertions, test edits
or copied build artifacts. No all-green HTTP/browser regression is claimed; root
must verify that page using its genuine build. Other identity auth/validation,
access, gate and activation tests passed in the same command.

Full application PHPStan: **0 errors**, using process-local values loaded from
the synthetic organization-payment XML (SQLite memory, array services and empty
provider secrets). Scoped Pint, both PHP syntax checks and git diff --check passed.
No schema/config/route/gate activation, live DB/.env/data, provider or notifier,
deployment/push or new task/agent. STOP for bounded fix review; no summary clock
seams, form-key changes or composition implementation.

Code/test commit: `c7b5a948057c3f153644d560951acc50e6f1fa9e`. This builds on the
unintegrated diagnostic test/report commit `f1c5d63`; review the final GREEN test
content together with the one-line writer delta, not the RED intermediate alone.
Cached-path checks listed only the writer and its PG test for that commit; this
report is committed separately. Baseline dirty/untracked overlays remain unstaged.
