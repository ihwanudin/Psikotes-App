# Integration wave 17 — P11c1b manual review core

Tanggal: 2026-09-01

Backend `8bc0310` dan laporan `235c991` direview lalu diintegrasikan sebagai
`d8e21b7` dan `ca4e21a`. Boundary internal hanya menerima DTO typed; persisted
SuperAdmin non-deleted dikunci sebelum lookup bill. BranchAdmin/Staff dengan
flag legacy, Psychologist, actor hilang, role berubah, dan actor deleted ditolak.

Approval memakai graph validation, settlement, activation, audit, dan outbox
yang sama dengan provider. Rejection hanya menyimpan verifier, waktu, bounded
reason, dan audit. Exact replay terikat actor, fingerprint proof, decision, dan
reason; stale/opposite/corrupt state gagal tertutup. Jalur provider dan order
manual legacy tidak diubah.

## Bukti root

- Manual review focused: **22/22 tes, 102 assertions**.
- Provider/finalizer/issuance/reconciliation/webhook terkait: **73/73 tes,
  710 assertions**.
- Manual transfer legacy terisolasi: **7/7 tes, 38 assertions**.
- Default synthetic dengan sandbox eksternal dikecualikan: **1.224/1.224 tes,
  7.629 assertions**.
- PostgreSQL disposable runtime non-owner: **289/289 tes, 2.471 assertions**;
  race reviewer dan actor revocation tercakup, cleanup sukses.
- Pint file delta dan PHPStan source delta: lulus.

Satu run SQLite yang mencampur provider dan legacy gagal karena dua strategi
reset database berbeda dalam proses yang sama; bukan regresi behavior. Kelompok
provider dan legacy kemudian lulus pada proses terpisah.

P11c1b diterima lokal. Belum ada storage I/O, upload/access endpoint, policy,
route, Filament/UI, command/job/scheduler, notifikasi/provider nyata, migrasi DB
aktif, deploy, atau push. P11c2 harus dilanjutkan bertahap dan tetap default-off.

Backend task existing menerima tepat satu increment P11c2a core storage/replacement
proof, tanpa HTTP/access/policy/UI. Turn `01a05c39-f02f-7481-912d-9d194c7ecfe5`
aktif pada cursor `67fb332a-ee06-4280-b3fe-55c3b79bcc9c:10`; jangan mengirim
instruksi duplikat selama turn ini berjalan. Frontend dan portal tetap menunggu
dependency masing-masing.
