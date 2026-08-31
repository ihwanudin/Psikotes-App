# Permit issuance diterima dan rekonsiliasi dimulai

2026-09-01. Backend b387e6e + e59cab5 diintegrasikan sebagai c3de46c +
216601d setelah review action, DTO, feature/PG tests dan laporan.

Handler hanya menerima message ID persisted, menolak context/transaksi ambient,
mengonsumsi pending/0 menjadi processing/1 dalam transaksi service yang commit,
lalu melakukan maksimal satu create dan strict lookup. Attach/unknown kembali
atomik dan late/paid/corrupt replay tidak mengembalikan counter ke nol. Payload
tetap tanpa PII dan tidak ada settlement, entitlement, job, dispatcher, route,
scheduler, config/schema, credential, atau outbound nyata.

Bukti root:

- focused P10b-b + claim: **82/82 tes, 717 assertions**;
- regresi pembayaran/integrasi: **511/511 tes, 2.965 assertions**, tanpa skip;
- PostgreSQL disposable: **235/235 tes, 2.025 assertions**, cleanup selesai;
- Pint empat file dan PHPStan action/DTO: lulus, 0 error.

Review tidak menemukan jalur POST kedua. Dua proses PG membuktikan satu winner,
satu loser, attempts tetap satu, rollback audit melepas permit, dan crash boundary
terlihat koneksi baru. Xendit adapter hanya diuji Http::fake 1 POST + 1 GET.
Ini core P10b lokal, bukan exactly-once provider atau production wiring.

ADR-008 menerima P10c-a single-intent: strict lookup terhadap issuing/processing1
atau unknown/failed1, tanpa create/rearm. Exact result memakai persistence boundary
bersama; unknown tetap fail-closed. Command/discovery/scheduler tetap P10c-b.

Backend cursor `163af1e1-5f6a-4358-82dd-3e4d2134e4ed:42`, turn
`01a059f0-c6b2-7ed3-ac8a-48677e4c7f80` aktif setelah tepat satu instruksi
P10c-a; jangan kirim ulang selama aktif.
Frontend/portal tetap not-loaded pada cursor :33 dan menunggu dependency. Satu
Instruksi tetap lookup-only, nol create/command/scheduler/outbound; review delta
dan uji root wajib sebelum integrasi.
