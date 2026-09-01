# Integration wave 18 — P11c2a private proof storage

Tanggal: 2026-09-01

Rangkaian backend `24c9591`/`ec1e36b` dan review-fix `22407ad`/`b951c2e`
diintegrasikan root sebagai `b9a819e`/`a8efd18`/`8bf0660`/`a113702`.

Boundary internal menerima persisted BranchAdmin organisasi pembayar atau exact
AssessmentPrincipal untuk self bill. Konten dideteksi server, dibatasi jpeg/png/
pdf dan 1..5.120.000 byte, diberi checksum SHA-256 dan object key acak privat.
Storage I/O berada di luar transaksi; persist melakukan locked canonical recheck.
Replacement memakai fingerprint expected, object baru dibersihkan bila gagal,
dan object lama baru dihapus best-effort sesudah commit.

Review awal menemukan expiry belum dicek, participant/attempt belum dikunci ulang
setelah storage I/O, dan metadata proof lama belum canonical sebelum replacement.
Fix menolak expires_at null/past/equal-now, soft-delete/revoke sebelum bill lookup,
serta key/checksum/MIME/size/timestamp korup sebelum fingerprint atau delete.

## Bukti root

- Storage focused: **37/37 tes, 201 assertions**.
- Manual review + provider finalizer: **32/32 tes, 167 assertions**.
- Upload bukti order legacy: **9/9 tes, 94 assertions**.
- Default synthetic, sandbox eksternal dikecualikan: **1.261/1.261 tes,
  7.830 assertions**.
- PostgreSQL disposable runtime non-owner: **292/292 tes, 2.508 assertions**;
  initial/replacement race dan participant revocation process proof lulus,
  cleanup sukses.
- Pint file delta, PHPStan source delta, dan diff-check: lulus.

Belum ada HTTP controller/route, proof reader/temporary URL reviewer, policy,
Filament/UI, purge, config/schema baru, layanan eksternal, migrasi DB aktif,
deploy, atau push. P11c2b tetap increment terpisah dan default-off.

Task backend existing menerima P11c2b policy + internal private-proof issuer.
Turn `01a05c68-8fed-7990-8901-c9609b08c687` aktif pada cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:13`; jangan mengirim prompt lain selama
aktif. Controller, route, Filament/UI, dan decision wiring tetap ditunda.
