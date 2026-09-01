# Permit issuance dan rekonsiliasi single-intent diterima

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

P10c-a `e3066bc`/`4f4b5e3` dan review-fix `8ee792f` diintegrasikan sebagai
`6facbda`/`89c8732`/`1900b78`. Review-fix mengikat unknown/failed ke
`last_error=INVOICE_OUTCOME_UNKNOWN`, termasuk race setelah preflight; state
noncanonical kini recovery_required tanpa update/audit.

Bukti root final: **163/163 tes, 1.241 assertions** untuk lookup/claim/issuance/
reconciliation; PostgreSQL disposable **237/237 tes, 2.074 assertions** dan
cleanup berhasil; Pint file delta dan PHPStan seluruh proyek lulus. Nol
create ulang, settlement, public wiring, command atau scheduler. P10c-a diterima;
discovery/lease/operasional tetap P10c-b.

Backend cursor `163af1e1-5f6a-4358-82dd-3e4d2134e4ed:58`. Proposal P10c-b0
`306f3a7`/`7c96535` diintegrasikan sebagai `9d1db26`/`d99ffa4`; ADR009 menerima
lease dua fase. Turn `01a05a2e-0dcd-7001-8a4d-efcbd1092a9e` aktif pada P10c-b1
schema/model/config dan tes disposable saja. Acquisition, provider, command dan
scheduler tetap di luar scope. P10c-b1 `3dd1c6e`/`dec48ae` diintegrasikan sebagai
`256148f`/`3af4372`; config tracked diterapkan sebagai `062815e`.

Bukti root: database terkait **37/37 tes, 185 assertions**; PostgreSQL disposable
**252/252 tes, 2.140 assertions**, cleanup berhasil; Pint file delta dan PHPStan
seluruh proyek lulus. Schema lease diterima lokal, tanpa migrasi database aktif.
Turn `01a05a5a-44b2-74a3-9020-76bdcb7c4489` aktif pada P10c-b2a reservasi
provisional outbox-only. `8ffd771`/`1e4bcbe` diintegrasikan sebagai
`fccfedf`/`c54d895`; root invoice **182/182 tes, 1.323 assertions**, PG
**256/256 tes, 2.195 assertions**, Pint/PHPStan lulus dan cleanup berhasil.
Validator dan provider tetap belum dimulai.
Turn `01a05a74-d87d-7731-bfb1-894333267a73` kini aktif pada P10c-b2b validator
canonical + rotasi UUID permit; provider dan persistence outcome tetap dilarang.
Frontend/portal tetap not-loaded pada cursor :33 dan menunggu dependency. Satu
increment P10c-b berikutnya wajib didesain bounded dan tetap nonaktif sampai
review; jangan menggandakan instruksi saat task aktif.
