# Canonical F2-F9 Acceptance Matrix

Status tanggal 2026-09-17. Matriks ini melengkapi checklist F1 di
`tasks/todo.md`; ia tidak mengganti atau mengurangi acceptance F1.

Legenda: `pass` = bukti telah direview; `partial` = sebagian lapisan sudah
terbukti tetapi exit gate belum lengkap; `open` = belum ada bukti penerimaan
yang memadai; `n/a` = lapisan bukan bagian langsung dari acceptance tersebut.

Progress proyek dihitung dari exit gate fase, bukan dari jumlah commit atau
penjumlahan 81/100 dan 92/120.

| ID | Acceptance | Domain | Persistence | PostgreSQL/RLS | HTTP | UI | Browser E2E | Exit gap utama |
|---|---|---|---|---|---|---|---|---|
| T-01 | IQ ke level | pass | partial | partial | open | open | open | Hasil instrumen otoritatif belum tersimpan dan diproyeksikan end-to-end. |
| T-02 | SW ke level | pass | partial | partial | open | open | open | Sama dengan T-01. |
| T-03 | PAPI raw/dimensi | pass | partial | partial | open | open | open | Definisi/item authority dan hasil otoritatif belum end-to-end. |
| T-04 | PAPI zona optimal | pass | partial | partial | open | open | open | Manifest definisi PAPI dan UI sesi belum diterima. |
| T-05 | RMIB level/tie | pass | partial | partial | open | open | open | Timing/definition authority dan UI sesi belum diterima. |
| T-06 | Zona aspek | pass | partial | partial | open | open | open | Komposisi hasil F2 ke kasus belum menjadi jalur runtime penuh. |
| T-07 | DASS tidak memengaruhi kelayakan | pass | partial | partial | open | open | open | Separation domain lulus; bukti hasil persisten hingga review/HPP belum lengkap. |
| T-08 | Skoring DASS | pass | partial | partial | open | open | open | Sesi/jawaban/hasil otoritatif belum dibuktikan end-to-end. |
| T-09 | Ambang DASS | pass | partial | partial | open | open | open | Sama dengan T-08. |
| T-10 | Kategori umum DASS terberat | pass | partial | partial | open | open | open | Proyeksi aman ke review/HPP belum end-to-end. |
| T-11 | DASS tidak lengkap fail-closed | pass | partial | partial | open | open | open | Boundary payload runtime dan browser belum diterima. |
| T-12 | Guardrail G1 | pass | pass | partial | pass | open | open | Persistence (`eligibility_decision_versions`, G8-versioned) dan HTTP projection diterima di `deepseek/f3-f4-eligibility-narrative` (`d1ad798`, `e84b8cd`, `2fdcdf9`, `09be48b`); belum di-merge ke branch integrasi. Migration+trigger dijalankan dan diverifikasi di SQLite; belum dieksekusi di PostgreSQL sungguhan, jadi RLS `service`-only belum dibuktikan runtime. Konsumsi di review UI (F5) dan browser E2E masih terbuka. |
| T-13 | Guardrail G2 | pass | pass | partial | pass | open | open | Sama dengan T-12 — persistence/HTTP diterima (SQLite-verified), PostgreSQL/RLS belum dieksekusi runtime, integrasi review UI dan browser E2E masih terbuka. |
| T-14 | Guardrail G3 | pass | pass | partial | pass | open | open | Kolom `validity` tersimpan versioned di `eligibility_decision_versions`; PostgreSQL/RLS belum dieksekusi runtime; konsumsi hingga review UI masih terbuka. |
| T-15 | Guardrail G5/tanda tangan | pass | partial | partial | open | open | open | Di luar scope increment ini — signing snapshot tetap milik F5 (Codex), belum end-to-end. |
| T-16 | Guardrail G7 | pass | partial | partial | open | open | open | Persistence F3 diterima, tapi integrasi G7 discrepancy ke review workflow tetap milik F5 (Codex), belum lengkap. |
| T-17 | Guardrail G8 | pass | pass | partial | pass | open | open | Versioned append-only result persistence diterima — trigger guard + chain invariant diuji lewat insert nyata di SQLite (append-only, broken-chain reject, field_code check constraint). PostgreSQL/RLS service-only dituliskan mengikuti pola `generic_instrument_result_ledger` (09-13) tapi belum dieksekusi di PostgreSQL sungguhan. Review UI dan browser E2E masih terbuka. |
| T-18 | Standar per bidang | pass | pass | partial | pass | open | open | `field_code`/`standard_version` tersimpan per versi (SQLite-verified); PostgreSQL/RLS belum dieksekusi runtime; runtime selection standar di luar scope increment ini. |
| T-19 | Batas aspek non-kritis | pass | pass | partial | pass | open | open | Persistence/HTTP F3 diterima (SQLite-verified); PostgreSQL/RLS belum dieksekusi runtime; integrasi ke review/HPP (F5/F6) masih terbuka. |
| T-20 | Determinisme narasi | pass | pass | partial | pass | open | open | Persistence (`bilingual_narrative_versions`) dan HTTP projection (`BilingualNarrativeController`, `ReviewInputController`) diterima di branch yang sama (SQLite-verified). PostgreSQL/RLS belum dieksekusi runtime. Konsumsi UI review masih terbuka. |
| T-21 | Rotasi konektor | pass | pass | partial | pass | open | open | Sama dengan T-20 — persistence/HTTP diterima (SQLite-verified), PostgreSQL/RLS belum dieksekusi runtime, jalur review/HPP (F5/F6) masih terbuka. |
| T-22 | DIPERTIMBANGKAN bersyarat/review | pass | partial | partial | open | open | open | State machine ada; review UI dan signing integration belum lengkap. |
| T-23 | Matriks akses | pass | pass | pass | partial | partial | partial | Permukaan peserta/admin/cabang belum seluruhnya mendapat browser E2E. |
| T-24 | DASS mandiri dan otomatis dalam paket utama | pass | pass | pass | pass | partial | partial | Paket utama sudah wajib memuat DASS tanpa pilihan; standalone dan bundled belum satu gate browser penuh. |
| T-25 | Kamera ditolak | pass | partial | partial | partial | partial | open | Domain/proctoring contract ada; mobile browser E2E belum diterima. |
| T-26 | Stream kamera terputus | pass | partial | partial | partial | partial | open | Runtime recovery dan mobile E2E belum diterima. |
| T-27 | Visibility duration | pass | partial | partial | partial | partial | open | Persistence/event-to-validity browser E2E belum diterima. |
| T-28 | Face-match gagal menjadi penanda | pass | partial | partial | partial | partial | open | Provider belum dipilih; keputusan otomatis tetap dilarang. |

## Phase exit gates

| Phase | Status | Evidence yang sudah diterima | Yang masih membuka exit gate |
|---|---|---|---|
| F1 | partial | Foundation, payment/manual paths, package composition, dan mayoritas acceptance F1. | Xendit E2E, full browser/security gate, dependency scan, dan operations documentation. |
| F2 | partial | Pure scoring T-01..T-11, case/grant/session schema, selection policy, dan full PostgreSQL 533/533. | Manifest empat instrumen, ADR-0030 runtime start (masih `SESSION_ENGINE_PENDING`), UI sesi, answer/result persistence end-to-end. |
| F3 | partial | Pure eligibility/guardrail T-12..T-19. Versioned persistence (`eligibility_decision_versions`, append-only + G8 chain invariant, SQLite-verified) dan HTTP projection (`EligibilityDecisionController`, `ReviewInputController`) accepted on `deepseek/f3-f4-eligibility-narrative` (`d1ad798`..`09be48b`), not yet merged into the integration branch. | PostgreSQL/RLS runtime execution (SQLite-verified only), integrasi ke review UI (F5), dan browser E2E. |
| F4 | partial | Composer bilingual deterministik T-20/T-21. Versioned persistence (`bilingual_narrative_versions`) dan HTTP projection (`BilingualNarrativeController`) accepted on the same branch — system baseline only, not the psychologist-edited final text (see known gap below). | PostgreSQL/RLS runtime execution, review UI, dan keluaran dokumen. Psychologist-edited INTEGRATION text has no persistence home yet — open gap for whichever lane owns F5/F6 review output. |
| F5 | partial | State machine, G6 override, dan signing prerequisites domain. | Derived signing snapshot, psychologist review UI, dan browser E2E. |
| F6 | open | Kontrak keluaran ada di SPEC/HPP. | Rendering HPP/internal report, signature, storage, dan E2E belum diterima. |
| F7 | partial | Sejumlah resource admin/cabang dan domain proctoring tersedia. | Dashboard/proctoring view serta matriks browser menyeluruh. |
| F8 | deferred | Xendit mencukupi keputusan saat ini. | Provider tambahan hanya dimulai setelah keputusan eksplisit. |
| F9 | partial | Full disposable PostgreSQL 533/533, hardening bertahap, dan command retensi inert. | Scheduler/config activation authority, load/recovery/backup, observability, serta launch gate final. |
