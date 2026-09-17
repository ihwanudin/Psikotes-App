# Handoff: generic assessment result contract

## Scope

Foundation ini menyediakan satu proyektor murni untuk payload hasil yang kelak dipakai bersama oleh callback dan poll. Ia tidak dipasang ke route, outbox, provider, atau scoring engine.

`GenericAssessmentResultProjector` hanya menerima snapshot hasil terotorisasi dengan field exact berikut: `assessmentAttemptId`, `iq`, `engineVersion`, `completedAt`, `finality`, `revokedAt`, dan `resultVersion`. Output menambahkan `resultChecksum` SHA-256 dari representasi kanonik berversi. Timestamp dinormalisasi ke UTC mikrodetik. IQ integer atau pecahan dipertahankan sebagai angka tanpa pembulatan.

`assessmentAttemptId` dinormalisasi ke ULID uppercase sebelum checksum dan audit reference dihitung. Input ULID lowercase dan uppercase karena itu menghasilkan envelope/checksum yang identik, dan replay lowercase terhadap envelope uppercase tetap idempoten. Persistence juga mengubah attempt lookup menjadi uppercase sebelum ownership query agar normalisasi proyektor tidak terputus pada boundary database. Ini menyelaraskan kontrak dengan registry Selection tanpa mengubah identitas attempt.

Kontrak fail-closed:

- Attempt wajib ULID, versi awal wajib 1, dan hasil wajib literal `FINALIZED`.
- IQ wajib numeric, finite, dan berada pada domain psikometrik eksplisit `0 < IQ <= 300`; engine version wajib identifier aman dan terbatas; timestamp wajib RFC 3339 valid secara kalender.
- Replay versi yang sama hanya sah bila checksum sama.
- Koreksi/revokasi wajib memakai versi tepat berikutnya dan mengubah isi semantik.
- Versi stale, lompatan versi, pergantian attempt, envelope sebelumnya korup, field hilang/asing, dan payload non-final ditolak dengan kode error stabil tanpa nilai mentah.
- `revokedAt` adalah nullable marker pada snapshot final dan tidak boleh mendahului `completedAt`. Snapshot revokasi mempertahankan IQ hasil yang direvoke untuk rekonsiliasi, tetapi consumer Selection harus menahannya dari keputusan lulus.

`safeAuditContext()` sengaja hanya menghasilkan nama event, hash attempt, versi, checksum, finality, dan boolean revocation. IQ, raw attempt ID, engine version, PII, jawaban, narasi, dan credential tidak masuk metadata log. Callback maupun poll nanti wajib memakai proyektor yang sama dan mencatat audit aman pada boundary masing-masing.

## Keputusan arsitektur yang diterapkan

- Generic assessment adalah kontrak target; endpoint Selection v1 tetap adapter kompatibilitas sementara dan tidak disentuh di irisan ini.
- Delivery target adalah `CALLBACK_AND_POLL`, tetapi transport dan autentikasinya belum diimplementasikan di sini.
- Kredensial provisioning dan callback harus dipisah saat boundary transport dibangun.
- IQ hanya boleh diproyeksikan setelah scoring IST server-side benar-benar menghasilkan snapshot tervalidasi. Baseline saat ini belum mempunyai persistence skor/IQ authoritative; karena itu irisan ini tidak mengarang nilai atau mengubah `RecordAssessmentOutcome::finalize()`.

## Integrasi berikutnya

1. Tambahkan persistence hasil authoritative append-only yang mengikat attempt, engine version, IQ, completed time, finality/revocation, version, dan checksum; terbitkan hanya setelah validasi scoring/review yang diwajibkan dokumen psikolog.
2. Ganti proyeksi callback dan poll lama agar keduanya mengambil exact snapshot persisted lalu melewati proyektor ini. Jangan membangun payload terpisah di controller/outbox.
3. Tambahkan audit event per publish/poll/replay/revoke dengan allowlist `safeAuditContext()` dan actor/client terotorisasi; jangan log payload penuh.
4. Tambahkan credential reference terpisah untuk request provisioning inbound dan callback outbound, signature verification, timestamp tolerance, idempotency, UNKNOWN reconciliation, serta contract tests lintas dua aplikasi.
5. Deprekasi adapter Selection v1 hanya setelah consumer generic terbukti stabil; jangan menghapusnya dalam perubahan ini.

## Batas verifikasi

Unit test membuktikan payload callback/poll identik secara deterministik, IQ pecahan tidak dibulatkan, UTC canonicalization, replay, koreksi, revocation, corruption/conflict, strict input, dan privacy audit. Tidak ada bukti scoring IST, persistence database, PostgreSQL concurrency, webhook authenticity, queue delivery, atau end-to-end Selection karena seluruh hal tersebut berada di luar scope foundation ini.

## Irisan persistence authoritative append-only

`GenericAssessmentResultStore::persistAuthorizedSnapshot()` kini menjadi boundary persistence internal untuk envelope dari proyektor yang sama. Service mengunci `assessment_participants` berdasarkan exact `assessmentAttemptId`, memproyeksikan ulang snapshot eksplisit, lalu menyimpan histori versi linear di `generic_assessment_result_versions`. Versi pertama wajib 1 dan tidak boleh sudah revoked; koreksi/revokasi wajib tepat versi berikutnya. Revokasi hanya boleh menambahkan `revokedAt` pada IQ, engine version, dan completion snapshot yang sama. Hasil yang sudah revoked terminal. Replay checksum identik tidak menggandakan baris hasil.

IQ disimpan sebagai kolom numeric tanpa pembulatan serta representasi JSON numeric kanonik untuk merekonstruksi checksum yang membedakan integer/float secara deterministik. Koreksi yang hanya mengubah representasi `99` menjadi `99.0` tetap ditolak sebagai tidak ada perubahan numerik. Ownership attempt diproteksi FK komposit. Unique constraints dan trigger SQLite/PostgreSQL memproteksi urutan versi/supersedes linear, revocation semantics, nilai dasar, serta menolak seluruh UPDATE/DELETE. Service mengunci parent attempt sebelum membaca latest version agar competing writes memakai mutex yang sama; concurrency PostgreSQL nyata belum diuji.

Domain IQ dibatasi eksplisit sampai 300 pada projector dan kedua guard database. Ini adalah batas data psikometrik, bukan threshold kelulusan; aturan lulus IQ 99 tetap berada di aplikasi Selection dan tidak disentuh. Batas ini juga memastikan seluruh nilai IQ yang diterima dapat dibandingkan secara tepat dengan representasi binary64, sehingga koreksi/revokasi tidak mengalami identity collapse di atas `2^53`.

Setiap create, exact replay, correction, dan revocation menulis `audit_logs` dalam transaksi yang sama. Audit hanya berisi hash attempt, result version/checksum, finality, dan boolean revocation; tidak ada IQ, raw attempt ID, engine version, jawaban, narasi, PII, atau credential. Kegagalan audit membatalkan create dan mencegah replay dilaporkan sukses.

Boundary ini sengaja belum dipasang ke route, callback, poll, queue, atau `RecordAssessmentOutcome`. Repo belum mempunyai tabel/snapshot scoring IST authoritative yang dapat diverifikasi oleh service, sehingga nama parameter `authorizedSnapshot` adalah kontrak caller, bukan bukti authorization persistence. Aktivasi runtime tetap diblokir sampai scoring server-side yang telah divalidasi menyuplai exact snapshot ini dan binding source ID/version/checksum ditambahkan. Status sesi `UNDER_REVIEW`, `COMPLETED`, atau `FINALIZED` sendiri tidak pernah dipakai untuk mengarang IQ.

Verifikasi irisan: focused test persistence mencakup IQ pecahan, create/replay/correction/revocation, ownership, stale/conflict/sequence/no-change, terminal revocation, audit privacy/atomicity, dan direct-write guards. PostgreSQL SQL ditulis dengan null-safe comparisons, tetapi host ini tidak memiliki `pdo_pgsql` maupun `psql`; tidak ada Compose/shared database yang dijalankan, sehingga parity runtime dan concurrency PostgreSQL tetap checkpoint integrasi.

## Irisan outbox hasil provider-neutral

`GenericAssessmentResultOutbox::enqueueExact()` membuat intent publikasi durable hanya dari exact baris `generic_assessment_result_versions` yang sudah tersimpan. Caller wajib memberi source result ID, raw attempt ID, result version, dan result checksum yang seluruhnya harus cocok dengan ownership persisted. Service mengunci parent `assessment_participants`, sehingga pembuatan versi hasil dan enqueue memakai mutex yang sama. Ia tidak membaca status sesi dan tidak mempunyai jalur untuk mengarang IQ.

Tabel `generic_assessment_result_outbox` sengaja hanya menyimpan source binding immutable dan contract marker `generic-assessment-result:v1`; IQ, engine version, payload, PII, credential, URL callback, dan status transport tidak diduplikasi. Source result sendiri append-only, sehingga callback dan poll kelak harus memuat envelope dari source ID yang sama dan memvalidasinya melalui `GenericAssessmentResultProjector`. Delivery attempt/status/retry harus berada pada tabel terpisah agar identity outbox ini tidak dimutasi.

Pembuatan intent latest bersifat idempoten. Replay exact mengembalikan outbox ID yang sama. Source lama yang belum pernah diterbitkan setelah versi koreksi/revokasi lebih baru tersedia menghasilkan `SKIPPED_STALE`; versi revokasi terbaru tetap publishable agar consumer dapat membatalkan keputusan yang menggunakan versi sebelumnya. Composite foreign key mengikat source ID, owner, attempt, version, dan checksum. Unique constraints serta trigger SQLite/PostgreSQL menolak source ganda, attempt/version ganda, contract asing, seluruh UPDATE, dan seluruh DELETE.

Audit `created`, `replayed`, dan `skipped` berada di transaksi yang sama dengan aksi outbox. Binding invalid/not-found/conflict menghasilkan audit `failed` durable setelah transaksi gagal dan kemudian mengembalikan exception fail-closed. Context hanya hash attempt, version, checksum, finality, boolean revocation, dan reason code allowlisted; tidak ada IQ, raw attempt, engine, payload, PII, atau secret. Kegagalan audit create membatalkan insert outbox.

Irisan ini belum memasang route, callback, poll, network client, credential, queue worker, scheduler, retry, atau provider. Ia juga belum mengaktifkan persistence result karena authoritative scoring snapshot masih belum ada di repo. PostgreSQL DDL ditulis, tetapi runtime host tidak menyediakan `pdo_pgsql`/`psql`; hanya migration up/down dan guards SQLite yang diuji. Concurrency multi-koneksi PostgreSQL, ordered transport untuk koreksi/revokasi, acknowledgment, UNKNOWN reconciliation, retention, dan consumer contract test lintas aplikasi tetap checkpoint integrasi.

## Irisan lease dan attempt dispatch

`GenericAssessmentResultDispatch` menambahkan lifecycle claim/complete internal untuk mode literal `CALLBACK_AND_POLL` tanpa mengubah intent `generic_assessment_result_outbox`. Setiap attempt berada pada tabel terpisah, mengikat exact outbox/source result/version/checksum, menyimpan hash token lease, batas waktu lease, outcome, safe reason code, serta waktu retry. Token mentah tidak disimpan.

Lease default 300 detik dibatasi 30–1.800 detik. Maksimum attempt default 4 dibatasi 1–10, dengan backoff internal default 60/300/900 detik dan konfigurasi tervalidasi. Same-token hanya replay sebelum expiry. Token berbeda sebelum expiry di-skip sebagai lease aktif. Tepat/pasca expiry, token lama tidak boleh digunakan lagi dan takeover wajib token baru yang membuat attempt versi berikutnya. Completion juga wajib terjadi sebelum expiry.

Outcome durable adalah `ACKNOWLEDGED`, `RETRYABLE`, `PERMANENT`, atau `UNKNOWN`. `RETRYABLE` baru dapat di-claim setelah backoff dan berhenti otomatis saat maksimum attempt tercapai. `ACKNOWLEDGED` dan `PERMANENT` terminal. `UNKNOWN` juga terminal untuk dispatcher dan tidak pernah di-resend otomatis karena remote side effect mungkin sudah terjadi; rekonsiliasi/poll terpisah kelak harus menentukan keadaan authoritative sebelum tindakan lanjutan. Test mengunci perilaku claim setelah `UNKNOWN` menjadi `SKIPPED_TERMINAL` serta mencatat audit skip.

Trigger SQLite/PostgreSQL menolak insert sequence ilegal, mode asing, lease tidak valid, perubahan identity, completion di luar lease, perubahan outcome kedua, dan delete. Insert attempt baru hanya sah setelah lease attempt sebelumnya expired atau retry due. Service mengunci exact outbox binding sebelum membaca latest attempt, tetapi konkurensi multi-koneksi PostgreSQL masih harus diuji pada runtime PostgreSQL nyata.

Claim, replay, takeover, completion, dan seluruh terminal/not-due/exhausted skip diaudit dalam transaksi aksi. Input/binding/lease/completion conflict menghasilkan audit failure sesudah transaksi gagal. Context audit hanya hash assessment attempt, result version/checksum/finality/revocation, nomor attempt, outcome, dan reason code dari daftar generik tertutup (`TRANSIENT_UNAVAILABLE`, `RATE_LIMITED`, `REMOTE_REJECTED`, `OUTCOME_UNCERTAIN`); tidak ada IQ, PII, raw attempt, raw lease token, exception, payload, atau credential. Kegagalan audit claim/completion membatalkan mutasi attempt.

Irisan ini tidak mempunyai provider, HTTP, callback route, poll endpoint, credential, scheduler, queue worker, scoring, atau activation. Ia belum menyelesaikan ordered delivery antar-versi hasil dan belum membuat reconciler `UNKNOWN`; dua hal itu tetap checkpoint sebelum integrasi transport produksi.

## Irisan proyeksi poll internal

`GenericAssessmentResultPollProjection::project()` adalah boundary read provider-neutral untuk mode poll. Input hanya integration client ID yang sudah diautentikasi oleh caller, assessment attempt ULID yang dikanonisasi uppercase, dan cursor result version/checksum opsional. Query dimulai dari integration client yang masih enabled lalu memverifikasi ownership `assessment_participants`, exact immutable outbox binding, source result ID/version/checksum, contract `generic-assessment-result:v1`, dan bahwa source tersebut juga versi result persisted terbaru. Client disabled/revoked menerima response generik yang sama dengan attempt hilang.

Jika latest result belum mempunyai outbox, outbox stale, tenant asing, attempt hilang, cursor parsial/invalid, atau source gagal divalidasi ulang oleh `GenericAssessmentResultProjector`, response selalu generic `UNAVAILABLE` tanpa envelope. Tanpa cursor menghasilkan `AVAILABLE`; cursor yang sama persis menghasilkan `REPLAYED`; cursor lama menerima `AVAILABLE` berisi koreksi terbaru. Cursor pada versi yang sama dengan checksum berbeda atau versi lebih tinggi ditolak sebagai `UNAVAILABLE` agar conflict/downgrade fail-closed. Envelope tersedia direkonstruksi dari exact persisted result dan mempertahankan IQ numerik tanpa pembulatan. Outcome dispatch sengaja tidak dibaca: `UNKNOWN` transport tetap menghasilkan poll `AVAILABLE` karena hasil persisted, bukan status delivery, adalah sumber kebenaran.

Projection memakai satu SELECT tetap tanpa N+1 dan satu INSERT audit. Setiap `AVAILABLE`, `REPLAYED`, dan `UNAVAILABLE` diaudit dalam transaksi request. Audit hanya memuat hash attempt canonical serta version/checksum/finality/revocation bila binding sah; tidak ada IQ, PII, raw attempt, credential reference, client secret, dispatch token, atau payload.

Service ini internal dan belum mempunyai HTTP route, authentication adapter, rate limit, credential, network, scheduler, scoring, atau activation. PostgreSQL tidak diuji runtime pada host ini; query menggunakan konstruksi Query Builder yang kompatibel SQLite/PostgreSQL, sedangkan ownership dan immutability tetap dijaga constraint yang sudah ada.

## Irisan HTTP poll Selection yang belum di-mount

Route module `routes/selection-result-poll.php` menyediakan boundary read-only `GET /api/integrations/v1/selection/assessment-attempts/{assessmentAttemptId}/result`, tetapi sengaja belum dimuat oleh `routes/api.php`. Koordinator harus mereview kontrak lintas aplikasi dan mounting sebelum endpoint tersedia. Path hanya menerima ULID attempt; query hanya menerima pasangan `version` dan `checksum`. Output sukses dibatasi pada `data.status` (`AVAILABLE`, `REPLAYED`, atau `UNAVAILABLE`) dan envelope generik dari `GenericAssessmentResultPollProjection`. Foreign/missing/stale/corrupt serta cursor parsial/konflik tetap `UNAVAILABLE` tanpa membocorkan keberadaan attempt. Semua response yang melewati boundary diberi `Cache-Control: no-store, private`.

Boundary memakai kredensial khusus arah Selection→Psikotes dari `selection_integration`, bukan `assessment_integration.credentials` yang juga digunakan callback keluar. `selection_integration.client_id` wajib sama persis dengan satu `integration_clients.client_id` yang enabled, masih dalam masa aktif, dan mengizinkan `POLL` atau `CALLBACK_AND_POLL`; row registry itulah sumber tenant/organization authoritative yang diteruskan ke projection. Registry row dikunci dan validasi otorisasi serta projection dijalankan dalam satu transaksi service-RLS agar pencabutan client/mode tidak mempunyai celah TOCTOU. Tidak ada fallback branch, client ID dari query/body, atau secret baru yang disimpan.

Poll mewajibkan header `X-Integration-Contract: selection-result-poll:v1` dan `X-Signature-Version: v2`. HMAC-SHA256 v2 mengikat timestamp, contract, method uppercase, path, query yang diurutkan serta di-percent-encode RFC 3986, dan SHA-256 body. Dengan demikian signature tidak dapat dipakai lintas path/query; replay read-only masih mungkin hanya di dalam toleransi timestamp yang dikonfigurasi. Caller Selection harus menerapkan canonicalization yang sama sebelum route diaktifkan.

Bad signature, client mismatch, registry disabled/expired, mode delivery salah, dan konfigurasi tidak tersedia menghasilkan error autentikasi generik tanpa data hasil. Setiap denial dicatat durable dengan reason code allowlisted serta hash client/request reference; baris denial tidak mengikat raw organization/client/result ID dan audit tidak memuat IQ, raw attempt, client ID mentah, signature, secret, query mentah, body, atau PII. Request yang lolos autentikasi tetap memakai audit `AVAILABLE`/`REPLAYED`/`UNAVAILABLE` pada projection existing. Rate limit berbasis hash IP dijalankan sebelum verifikasi/audit agar permintaan tidak sah tidak dapat membuat write amplification tanpa batas; respons 429 juga no-store.

Irisan ini tidak menambah network outbound, callback, credential material, scheduler, scoring, mutation result/outbox, atau activation. Test HTTP menggunakan route module test-local dan SQLite; mounting normal, consumer contract test terhadap Selection, proxy preservation atas raw path/query, production rate/load, dan runtime PostgreSQL belum diverifikasi.

## Irisan adapter callback hasil ke Selection

`GenericAssessmentResultCallbackDispatcher::dispatchExact()` adalah adapter internal belum terjadwal yang mengirim exact latest `generic_assessment_result_outbox` ke receiver Selection `POST /api/v2/integrations/psychotest/results`. Loader mengunci parent assessment yang sama dengan writer result serta exact row registry integration client, memverifikasi ulang binding outbox/result/attempt/version/checksum, contract `generic-assessment-result:v1`, source version terbaru, client enabled/effective, dan mode `CALLBACK` atau `CALLBACK_AND_POLL`, lalu membuat durable claim di transaksi service-RLS yang sama. Dengan lock registry dipertahankan sampai claim selesai, pencabutan client, masa aktif, atau mode callback tidak mempunyai celah TOCTOU. Envelope direkonstruksi dari source immutable dengan IQ numeric tanpa pembulatan lalu divalidasi melalui `GenericAssessmentResultProjector`; JSON memakai urutan field tetap, unescaped slash, dan preserve-zero-fraction.

Transport memakai kredensial arah Psikotes→Selection yang baru dan terpisah (`SELECTION_RESULT_CALLBACK_SECRET`), bukan `SELECTION_INTEGRATION_CLIENT_SECRET` arah sebaliknya atau credential callback tenant lama; konfigurasi gagal tertutup bila dua secret arah tersebut identik. Endpoint berasal dari base URL khusus yang harus berupa origin HTTPS publik dengan hostname allowlist exact `seleksi.beasiswajepang.id`, tanpa userinfo/query/fragment, IP literal, port non-443, atau path tambahan; path receiver tetap hardcoded. Redirect dinonaktifkan. Timeout/connect timeout dibatasi 2–30 detik. Feature flag `SELECTION_RESULT_CALLBACK_ENABLED` default `false`, sehingga irisan ini tidak mengaktifkan jaringan produksi.

Header dan signing input mengikuti receiver aktual: `X-Psychotest-Timestamp`, contract `generic-assessment-result`, contract version `1`, signature version `v2`, dan lowercase HMAC-SHA256. Canonical input diawali `psychotest-selection-hmac:v2`, lalu timestamp, contract, version, `POST`, exact path, query yang disortir dengan pasangan duplikat tetap dipertahankan dan percent-encoding RFC 3986, serta SHA-256 body. Endpoint saat ini mengirim query kosong, sedangkan signer memiliki regression parity untuk canonical query receiver.

Sebelum I/O, adapter memakai `GenericAssessmentResultDispatch` untuk claim/lease durable. HTTP `202` hanya menjadi `ACKNOWLEDGED` bila respons JSON maksimal 4 KiB memiliki struktur exact `{data: {status: "ACCEPTED"}}`; HTTP `200` wajib struktur yang sama dengan status `REPLAYED`. Media type non-JSON, body terlalu besar/rusak, key tambahan, atau pasangan status-code yang tidak cocok menjadi terminal dispatcher `UNKNOWN/OUTCOME_UNCERTAIN`, karena side effect remote mungkin sudah terjadi. `429` menjadi `RETRYABLE/RATE_LIMITED`; `5xx` menjadi `RETRYABLE/TRANSIENT_UNAVAILABLE`; `408` dan connection/timeout ambiguity juga menjadi terminal `UNKNOWN`; response lainnya menjadi `PERMANENT/REMOTE_REJECTED`. Redirect 3xx tidak diikuti. Same-token replay dapat mengulang HTTP dalam lease, tetapi receiver menyimpan envelope secara idempoten; token baru setelah outcome `UNKNOWN` tidak mengirim ulang dan menunggu reconciler/poll terpisah. Claim, replay/skip, completion, dan outcome tetap diaudit atomik oleh dispatch existing dengan context aman tanpa IQ, raw attempt, lease token, body, PII, secret, exception, atau URL bercredential.

Konfigurasi callback kini terdokumentasi default-disabled di `.env.example`. Ketika flag callback diaktifkan pada production, boot gagal tertutup kecuali base URL adalah exact origin HTTPS `seleksi.beasiswajepang.id` pada port default/443 tanpa path tambahan, query, fragment, atau userinfo; secret outbound minimal 32 karakter dan berbeda dari secret inbound; serta timeout berada pada 2–30 detik. Compose dan activation tetap tidak disentuh. Sebelum mounting worker/scheduler, koordinator harus memasang secret yang sama dengan `PSYCHOTEST_WEBHOOK_SECRET` pada Selection melalui secret manager dan menjalankan contract test lintas aplikasi. Runtime PostgreSQL, DNS-rebinding-aware egress proxy/firewall, network timeout setelah remote commit, concurrency worker, retry broker, serta ordered delivery ketika koreksi lahir sesudah claim belum dibuktikan pada irisan ini.

## Irisan orkestrasi antrean callback

`GenericAssessmentResultCallbackOrchestrator::schedule()` memilih maksimal 100 intent outbox secara oldest-first dengan tie-break ULID. Query hanya memasukkan exact result version terbaru yang masih terikat pada outbox, integration client enabled/effective dengan mode callback, serta state transport yang memang dapat diklaim: belum pernah dicoba, lease `PROCESSING` sudah kedaluwarsa, atau `RETRYABLE` yang sudah due dan belum mencapai empat attempt. `ACKNOWLEDGED`, `PERMANENT`, `UNKNOWN`, retry belum due/exhausted, lease aktif, source stale, dan client yang dicabut tidak dijadwalkan.

Setiap outbox memiliki satu schedule durable. Sebelum menyerahkan job ke broker, orkestrator mempersist request dan audit dalam transaksi service-RLS. State `PENDING` khusus menandai satu generasi dispatch broker yang sudah dipersiapkan dan selalu due segera agar queue `sync` atau worker sangat cepat dapat mengeksekusi job di antara dispatch broker dan pencatatan acceptance tanpa kehilangan pekerjaan. Selector tidak dapat mempersiapkan ulang generasi `PENDING` tersebut selama 300 detik berdasarkan `updated_at`, sehingga scheduler paralel tidak dapat menghabiskan attempt ketika broker call masih in-flight. Jika proses mati sebelum hasil broker tercatat, generasi itu baru boleh dipulihkan setelah batas tersebut.

Keberhasilan broker mengubah `PENDING` menjadi `QUEUED` bila marker exact broker-attempt dan waktu persiapan masih sama; exception broker mengubah marker exact itu menjadi `BROKER_FAILED` dengan recovery setelah 300 detik. Bila job cepat sudah mencapai `RUNNING`, `COMPLETED`, atau `RETRY_WAIT`, pencatatan broker menjadi no-op dan tidak menimpa hasil worker. Backoff transport memakai state terpisah `RETRY_WAIT`; deadline-nya tidak dapat dilewati oleh duplicate job dan hanya selector due yang mengubahnya menjadi generasi `PENDING` baru. `BROKER_FAILED` sebelum deadline juga ditolak oleh eksekusi langsung. Lock unique job memakai horizon 300 detik yang sama, sehingga exception setelah unique lock diperoleh tidak membuat intent hilang. Accepted-but-lost job juga dapat dipilih ulang setelah lease ini. Ketika empat upaya broker sudah habis, selector mengubah schedule due menjadi `BROKER_EXHAUSTED` secara atomik dan mengauditnya, sehingga row oldest tidak dapat terus menyumbat batch berikutnya. State ini membutuhkan intervensi/recovery tool masa depan; ia tidak dianggap terkirim.

Job `DispatchGenericAssessmentResultCallback` membawa hanya schedule ULID, menerapkan `ShouldBeEncrypted`, `ShouldBeUnique`, after-commit, satu try, timeout 45 detik, dan queue `integrations`. Setiap eksekusi menghasilkan lease token acak baru, lalu adapter callback memuat ulang exact binding authoritative. Retry transport menggunakan `next_attempt_at` dari lifecycle existing; schedule kembali `RETRY_WAIT` sampai due. Duplicate job sebelum due hanya menghasilkan audit skip dan tidak boleh menutup schedule atau melakukan HTTP kedua. Terminal outcome selesai, sedangkan crash/exception worker menjadi `WORKER_FAILED` yang dapat dipilih ulang sesudah recovery lease. Duplicate atau state yang tidak eligible di-skip dan diaudit tanpa network. Guard SQLite/PostgreSQL membekukan identity, membatasi broker attempts pada 1–4, mewajibkan exhaustion tepat pada attempt keempat, serta menerapkan graph transisi state dan invariant timestamp/failure code yang sama.

Audit orchestration hanya memuat result version/checksum, jumlah broker attempt, serta reason/action/outcome allowlisted. Tidak ada IQ, raw assessment attempt, nama/PII, body, URL, token, secret, atau exception. Audit claim/replay/skip/outcome/exhaustion transport tetap dimiliki `GenericAssessmentResultDispatch`; orchestration tidak menduplikasikan atau mengubah kebenaran tersebut.

Irisan ini belum memasang scheduler/command/route, tidak mengaktifkan queue produksi, dan seluruh HTTP test memakai fake. Runtime broker Redis, recovery worker yang benar-benar mati, delivery lintas proses, konkurensi serta trigger PostgreSQL, dan alert/manual recovery untuk `BROKER_EXHAUSTED` belum diuji. Selector harus dipanggil oleh entry point terjadwal yang direview pada irisan terpisah; jangan menjalankannya dari request kandidat.

## Irisan kesiapan runtime lokal callback

Compose kini mempunyai worker `integrations-queue` terpisah dari worker
`notifications,default`. Worker baru hanya mendengarkan queue `integrations`,
memakai Redis yang sama, timeout 120 detik dengan `retry_after` default 150 detik,
recycle memori Laravel 256 MiB, maksimum hidup satu jam, restart
`unless-stopped`, grace period 135 detik, dan tidak menerbitkan port. Grace
period ini melampaui timeout worker 120 detik agar shutdown tidak memotong job
yang masih sah. Jaringan
`edge` hanya dibutuhkan untuk callback HTTPS keluar; jaringan `backend` tetap
menjadi jalur privat ke Redis/PostgreSQL. Health check web dinonaktifkan karena
proses CLI tidak menyediakan HTTP readiness endpoint.

Satu service `scheduler` menjalankan `php artisan schedule:work`, memakai cache
Redis bersama untuk mutex `onOneServer`/`withoutOverlapping` yang telah
didefinisikan pada jadwal aplikasi, serta memperoleh grace period 30 detik.
Konfigurasi Compose meneruskan flag poll dan callback beserta konfigurasi
callback runtime karena file ini memakai environment map eksplisit, bukan
`env_file`. Kedua feature flag tetap default `false`; tidak ada credential yang
ditanam ke image atau nilai secret yang dicatat di repository.

Runbook HTTPS lokal telah menyertakan worker integrasi pada perintah recreate.
`docker compose config --quiet` lulus menggunakan nilai sintetis tanpa memulai
container atau mencetak konfigurasi terurai. Test statis mengunci pemisahan
queue, batas worker, tidak adanya port CLI, satu scheduler, cache Redis, serta
default-off. Runtime Redis broker, shutdown proses nyata, konsumsi memori,
egress HTTPS, health operasional berbasis metrics/log, dan multi-host scheduler
belum diuji. Scoring IQ authoritative tetap belum tersedia, sehingga menyalakan
worker tidak membuat outbox hasil dengan sendirinya dan flag callback tetap
harus dipertahankan nonaktif sampai kontrak lintas aplikasi siap.
