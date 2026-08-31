# P10b-a diterima dan P10b-b dibatasi

2026-09-01; heartbeat `lanjutkan-task-psikotes-setelah-selesai`.

Backend menyerahkan d72051d + 1ccc01c. Source, feature/PG tests, laporan dan
patch config dibaca; perubahan diintegrasikan sebagai 1e79f44 + fe49304.
`invoice_duration_hours=24` diterapkan hanya setelah SHA-256 root cocok dengan
hash sebelum patch dan menghasilkan hash sesudah yang dilaporkan; commit c642e50.

Review lima sumbu menerima action service-only: lock/persisted scope, payer,
snapshot, sum/count/linkage, initial funding, policy/channel/revoke, expiry dan
replay gagal tertutup. Claim menulis satu intent pending/0 + audit dan issuing
atomik. Tidak ada job, dispatcher, HTTP, settlement, hak akses atau data PII
dalam payload. P10b belum selesai.

Bukti root:

- feature claim: **60 tes/444 assertions**;
- regresi Unit/Payments + Feature/Payments + Feature/Integrations:
  **489/489 tes, 2.692 assertions**, tanpa skip;
- PostgreSQL disposable runtime: **231/231 tes, 1.954 assertions**, cleanup;
- Pint tiga file dan PHPStan action: lulus/0 error.

Run PostgreSQL pertama gagal sebelum tes karena probe socket init sementara.
Runner diperbaiki terpisah agar readiness/marker memakai TCP final, commit
2696e7d; run berikutnya lulus penuh dan tidak menyentuh container aplikasi.
Satu run UI sempat gagal karena nama storage acak mengandung substring `ayu`;
tes yang sama lulus saat diisolasi dan suite terkait kemudian lulus penuh. Tidak
ada perubahan production untuk collision assertion itu.

ADR-007 membatasi P10b-b lokal pada permit pending/0 -> processing/1 yang commit
sebelum provider fake, satu create maksimum, strict lookup P10a, attach atau
unknown fail-closed, dan late response guards. Tidak ada dispatcher/route/
scheduler/credential/provider nyata. P10c tetap recovery terpisah.

Cursor backend `163af1e1-5f6a-4358-82dd-3e4d2134e4ed:38`, turn
`01a059c5-8a81-7c70-b54c-40f71372b8aa` aktif setelah menerima tepat satu
increment P10b-b ADR-007; jangan kirim ulang selama aktif.
Frontend `a428d2aa-49a3-4a6e-80ad-288f45f9a2ae:33` dan portal
`4a41be93-41bc-4ef3-a96c-6fdb30836acb:33` berstatus not-loaded tetapi turn
terakhir tetap selesai dan sudah diterima; keduanya menunggu dependency.
Instruksi membatasi handler/tes/PG tanpa dispatcher, route, credential atau
outbound nyata. Review commit/delta dan uji root wajib sebelum integrasi.
Snapshot terakhir: feature issuance + regresi claim 80 tes/686 assertions lulus;
worker sedang memperbaiki dua type-boundary PHPStan pada snapshot mixed, belum
hasil final atau acceptance koordinator.
