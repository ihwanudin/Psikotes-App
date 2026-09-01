# Integration wave 22 — P11c3b proof-bound reviewer decisions

Tanggal: 2026-09-01

Backend `3d39987`/`ae58935` diintegrasikan root sebagai `877f0a3`/`fb8fd88`.
Boundary baru membaca audit sukses proof-access terbaru milik actor+bill sebagai
review context berumur pendek. Context exact, expiry, occurred_at, fingerprint,
subject, actor, source, dan ambiguity divalidasi; kemudian boundary hanya membuat
DTO manual review dan mendelegasikan ke finalizer canonical.

UI default-off menambah konfirmasi approve dan reject dengan lima alasan enum
berlabel Indonesia. Tanpa proof-access audit keputusan ditolak; proof diganti,
context expired/tampered/wrong actor/bill, atau role dicabut juga gagal tertutup.
UI tidak memanggil issuer diam-diam dan tidak menyimpan URL/key/checksum/
fingerprint/PII di state atau action arguments.

## Bukti root

- Decision UI: **26/26 tes, 134 assertions**.
- Reviewer resource + finalizer + issuer: **54/54 tes, 294 assertions**.
- Portal cabang + legacy Filament: **18/18 tes, 237 assertions**.
- Default synthetic tanpa sandbox: **1.346/1.346 tes, 8.352 assertions**.
- Pint, PHPStan, dan diff-check: lulus.
- PostgreSQL tidak diulang karena boundary audit read-only; bukti finalizer/RLS
  terakhir tetap **293/293 tes, 2.519 assertions**.

Belum ada browser acceptance, route/discovery/navigation produksi, outbound,
DB aktif, deploy, atau push.

Task backend existing menerima P11c3c browser acceptance testing-only. Turn
`01a05ce5-c61f-7861-a42a-491cde1b4564`, cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:21`; jangan menggandakan prompt selama
aktif dan jangan mengaktifkan resource/route produksi.
