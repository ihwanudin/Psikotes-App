# Canonical F2-F9 Acceptance Matrix

Status tanggal 2026-09-13. Matriks ini melengkapi checklist F1 di
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
| T-12 | Guardrail G1 | pass | partial | partial | open | open | open | Hasil persisten/API integrasi F3 belum lengkap. |
| T-13 | Guardrail G2 | pass | partial | partial | open | open | open | Hasil persisten/API integrasi F3 belum lengkap. |
| T-14 | Guardrail G3 | pass | partial | partial | open | open | open | Validitas persisten hingga review belum lengkap. |
| T-15 | Guardrail G5/tanda tangan | pass | partial | partial | open | open | open | UI psikolog dan derived signing snapshot belum end-to-end. |
| T-16 | Guardrail G7 | pass | partial | partial | open | open | open | Integrasi hasil kasus dan review belum lengkap. |
| T-17 | Guardrail G8 | pass | partial | partial | open | open | open | Versioned result persistence belum end-to-end. |
| T-18 | Standar per bidang | pass | partial | partial | open | open | open | Runtime selection dan persistence standar belum lengkap. |
| T-19 | Batas aspek non-kritis | pass | partial | partial | open | open | open | Integrasi F3 ke review/HPP belum lengkap. |
| T-20 | Determinisme narasi | pass | n/a | n/a | open | open | open | Composer lulus; konsumsi hasil persisten dan UI review belum lengkap. |
| T-21 | Rotasi konektor | pass | n/a | n/a | open | open | open | Composer lulus; jalur review/HPP belum lengkap. |
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
| F3 | partial | Pure eligibility/guardrail T-12..T-19. | Persistence, HTTP projection, dan integrasi review. |
| F4 | partial | Composer bilingual deterministik T-20/T-21. | Integrasi hasil persisten, review UI, dan keluaran dokumen. |
| F5 | partial | State machine, G6 override, dan signing prerequisites domain. | Derived signing snapshot, psychologist review UI, dan browser E2E. |
| F6 | open | Kontrak keluaran ada di SPEC/HPP. | Rendering HPP/internal report, signature, storage, dan E2E belum diterima. |
| F7 | partial | Sejumlah resource admin/cabang dan domain proctoring tersedia. | Dashboard/proctoring view serta matriks browser menyeluruh. |
| F8 | deferred | Xendit mencukupi keputusan saat ini. | Provider tambahan hanya dimulai setelah keputusan eksplisit. |
| F9 | partial | Full disposable PostgreSQL 533/533, hardening bertahap, dan command retensi inert. | Scheduler/config activation authority, load/recovery/backup, observability, serta launch gate final. |
