# Handoff: generic assessment result contract

## Scope

Foundation ini menyediakan satu proyektor murni untuk payload hasil yang kelak dipakai bersama oleh callback dan poll. Ia tidak dipasang ke route, outbox, provider, atau scoring engine.

`GenericAssessmentResultProjector` hanya menerima snapshot hasil terotorisasi dengan field exact berikut: `assessmentAttemptId`, `iq`, `engineVersion`, `completedAt`, `finality`, `revokedAt`, dan `resultVersion`. Output menambahkan `resultChecksum` SHA-256 dari representasi kanonik berversi. Timestamp dinormalisasi ke UTC mikrodetik. IQ integer atau pecahan dipertahankan sebagai angka tanpa pembulatan.

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
