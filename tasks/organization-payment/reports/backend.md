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
