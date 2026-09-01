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

Backend cursor `163af1e1-5f6a-4358-82dd-3e4d2134e4ed:73`. Proposal P10c-b0
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
canonical + rotasi UUID permit. `dabc6f4`/`6163bff` diintegrasikan sebagai
`56b681c`/`20f376b`; root invoice **197/197 tes, 1.524 assertions**, PG
**258/258 tes, 2.247 assertions**, Pint/PHPStan dan cleanup lulus. Provider dan
persistence outcome tetap belum dimulai.
Turn `01a05a8c-c17b-7e81-8c22-e98ac100c8e1` aktif pada P10c-b3 strict GET dan
token-fenced outcome/cooldown; command, scheduler dan P11 tetap dilarang.
Frontend/portal tetap not-loaded pada cursor :33 dan menunggu dependency. Satu
increment P10c-b berikutnya wajib didesain bounded dan tetap nonaktif sampai
review; jangan menggandakan instruksi saat task aktif.

P10c-b3 `75c72d8`/`6faf0df` diterima sebagai `43a1a3d`/`b511b59`. Review root
membuktikan focused **32/32 tes, 476 assertions**, Pint dan PHPStan lulus, serta
PostgreSQL disposable **260/260 tes, 2.292 assertions** dengan cleanup sukses.
Tidak ada create/POST, command, scheduler, route atau operasi aktif. Backend siap
menerima satu increment P10c-b4 koordinator internal bounded; frontend/portal
tetap menunggu dependency masing-masing.

Turn backend `01a05aaa-d776-7913-a171-c8afde2536ff` aktif mengerjakan P10c-b4
dengan tes RED lebih dahulu. Instruksi tidak mencakup command/job/scheduler/route
atau P11; task frontend dan portal tidak diberi pekerjaan duplikat.

P10c-b4 awal `1766c65`/`4940da1` belum diintegrasikan. Review menemukan
`scan_limit` tidak pernah dipakai melampaui reservasi batch pertama, sehingga
hint invalid mengurangi lookup walau anggaran scan tersisa. Turn perbaikan
`01a05ab5-e4cd-72b2-acbc-b68626745b54` aktif pada cursor :66 dengan tes refill
batch/scan wajib; frontend dan portal tetap menunggu.

Fix `8a79d0a`/`ef5f5d8` diterima; rangkaian P10c-b4 diintegrasikan sebagai
`11cb271`/`afd003b`/`0ad06ad`/`67902c0`. Root focused **46/46 tes, 479
assertions**, Pint/PHPStan lulus, dan PostgreSQL disposable **261/261 tes,
2.302 assertions** dengan cleanup sukses. P10c internal selesai tanpa wiring
operasional; backend siap menerima P11a core finalizer atomik.

Turn backend `01a05acf-4ada-7543-afca-90f7a2db4238` aktif pada P11a1 core
finalizer atomik. Scope berhenti sebelum route/webhook/dispatcher/scheduler,
provider nyata dan P11b/P11c; frontend serta portal tetap menunggu dependency.

P11a1 `de7e6f2`/`6d97aa7` diintegrasikan secara bersyarat sebagai
`2f668b9`/`974ded1` untuk verifikasi root. Review source, satu feature smoke test,
Pint dan PHPStan lulus. Acceptance belum ditutup: runner PostgreSQL task dan dua
percobaan root berhenti sebelum bootstrap test akibat Docker Desktop bind-mount
I/O; seluruh container/network disposable exact-name sudah dibersihkan. P11b
tidak boleh dimulai sampai concurrency PostgreSQL P11a terbukti.

Retry heartbeat setelah jeda 40 menit kembali berhenti sebelum bootstrap PHPUnit;
resource disposable exact-name/label dibersihkan. Karena blocker sama kini telah
berulang dan kelanjutan aman memerlukan pemulihan/restart Docker Desktop yang dapat
mengganggu container aplikasi aktif, automation koordinasi dijeda. P11a tetap
conditional dan P11b tidak dimulai sampai pengguna memulihkan Docker lalu meminta
kelanjutan.

Pengguna meminta lanjut; Docker Desktop daemon ditemukan berhenti lalu dijalankan
kembali dari instalasi user. Setelah warm-up bind-mount, root PostgreSQL disposable
lulus **262/262 tes, 2.320 assertions** termasuk concurrency finalizer, dan cleanup
sukses tanpa menargetkan container aplikasi. P11a1 kini diterima; P11b dimulai dari
audit kontrak routing/status legacy sebelum perubahan kode.

Heartbeat koordinasi diaktifkan kembali setelah blocker Docker hilang. Backend
turn `01a05baa-b35c-7091-9b4c-ee8d547afad2` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:2`) menerima P11b0 audit/kontrak saja:
dispatcher `AB_` harus fail-closed, order legacy tetap kompatibel, status check
berkonvergensi ke finalizer yang sama, dan belum ada wiring produksi.

P11b0 `61c3310` direview lintas correctness/architecture/security dan
diintegrasikan sebagai `60489e7`; smoke regression root lulus 22 tes/98
assertions. Tidak ada source produksi pada proposal. Backend turn
`01a05bb5-1951-76a0-839d-777d3c653712` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:3`) aktif pada P11b1 dispatcher dan
terminal mapping melalui TDD, tetap tanpa route/provider/command/scheduler.

P11b1 awal `2c94e8e` ditahan karena settlement belum menegaskan status `Paid`
secara eksplisit. Fix `df2a2a6` menambah guard fail-closed dan matriks seluruh
`PaymentStatus::cases()`, lalu diintegrasikan root sebagai `419de35`/`a2fa256`/
`573b278`/`f389211`. Bukti root: dispatcher+finalizer **22/145**, legacy terdampak
**22/98**, default **1.187/7.440**, Pint/PHPStan lulus, PostgreSQL disposable
**262/2.320** dengan cleanup sukses. P11b1 diterima; P11b2 status reconciliation
internal berikutnya, tetap tanpa command/scheduler/wiring aktif.

Backend turn `01a05bc9-48c6-7ed3-8e74-626c50d62606` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:5`) aktif pada P11b2 reconciler assessment
internal bounded. Scope hanya selection singkat → GET fake di luar transaksi/RLS
→ processor/finalizer yang sama; command/job/scheduler dan outbound nyata tetap
tidak diaktifkan.

P11b2 `71dc062`/`0f3f623` direview dan diintegrasikan sebagai `f104801`/
`c850af3`. Focused root **30/177**, legacy terisolasi **22/98**, default
**1.195/7.472**, Pint/PHPStan, serta PostgreSQL disposable **263/2.332** lulus;
cleanup sukses. Percobaan SQLite campuran pertama gagal karena dua strategi
reset database berbeda dalam satu proses, lalu kedua kelompok fixture lulus saat
dijalankan terpisah. P11b diterima; P11c dimulai dari audit authority/tenant
SuperAdmin tanpa wiring UI atau transfer nyata.

Backend turn `01a05bde-42aa-7333-8337-dea639720218` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:6`) aktif pada P11c0 proposal-only:
actor/resource matrix SuperAdmin, lifecycle bukti, reuse finalizer, storage
private, dan threat model. Belum ada approval writer, UI, gate, atau data nyata.

P11c0 `6566cd7` direview dan diintegrasikan sebagai `5ff488c`. ADR-010 diterima:
persisted SuperAdmin saja reviewer, metadata proof all-or-none wajib tersedia,
dan finalizer memperoleh entrypoint manual typed tanpa memalsukan Xendit. P11c1a
dimulai schema-only; tidak ada izin menerapkan migrasi ke database aktif.
