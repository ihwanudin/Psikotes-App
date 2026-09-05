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
