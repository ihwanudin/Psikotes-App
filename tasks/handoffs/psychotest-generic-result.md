# Handoff: generic assessment result contract

## Scope

Foundation ini menyediakan satu proyektor murni untuk payload hasil yang kelak dipakai bersama oleh callback dan poll. Ia tidak dipasang ke route, outbox, provider, atau scoring engine.

`GenericAssessmentResultProjector` hanya menerima snapshot hasil terotorisasi dengan field exact berikut: `assessmentAttemptId`, `iq`, `engineVersion`, `completedAt`, `finality`, `revokedAt`, dan `resultVersion`. Output menambahkan `resultChecksum` SHA-256 dari representasi kanonik berversi. Timestamp dinormalisasi ke UTC mikrodetik. IQ integer atau pecahan dipertahankan sebagai angka tanpa pembulatan.

Kontrak fail-closed:

- Attempt wajib ULID, versi awal wajib 1, dan hasil wajib literal `FINALIZED`.
- IQ wajib finite; engine version wajib identifier aman dan terbatas; timestamp wajib RFC 3339 valid secara kalender.
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
