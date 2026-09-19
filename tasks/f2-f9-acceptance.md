# Canonical F2-F9 Acceptance Matrix

Status tanggal 2026-09-19 (fase F3/F4/F5/F6/F9 diperbarui; T-01..T-28 belum
direview ulang baris-per-baris pada putaran ini — lihat "Phase exit gates" di
bawah untuk perubahan terverifikasi). Matriks ini melengkapi checklist F1 di
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
| T-12 | Guardrail G1 | pass | pass | pass | pass | open | open | Persistence (`eligibility_decision_versions`, G8-versioned) dan HTTP projection diterima di `deepseek/f3-f4-eligibility-narrative` (`d1ad798`..`3f67d6e`); PR #2 belum di-merge ke branch integrasi. PostgreSQL/RLS dibuktikan runtime pada PostgreSQL 17.6 sungguhan (`tests/Postgres/EligibilityDecisionRlsTest.php`, 20 tes): RLS FORCE, policy service-only, SECURITY DEFINER guard, trigger append-only, semua diverifikasi independen dua kali (worker + coordinator, container terpisah). Konsumsi di review UI (F5) dan browser E2E masih terbuka. |
| T-13 | Guardrail G2 | pass | pass | pass | pass | open | open | Sama dengan T-12 — persistence/HTTP/PostgreSQL-RLS diterima dan dibuktikan runtime, integrasi review UI dan browser E2E masih terbuka. |
| T-14 | Guardrail G3 | pass | pass | pass | pass | open | open | Kolom `validity` tersimpan versioned di `eligibility_decision_versions`; PostgreSQL/RLS dibuktikan runtime; konsumsi hingga review UI masih terbuka. |
| T-15 | Guardrail G5/tanda tangan | pass | partial | partial | open | open | open | Di luar scope increment ini — signing snapshot tetap milik F5 (Codex), belum end-to-end. |
| T-16 | Guardrail G7 | pass | partial | partial | open | open | open | Persistence F3 diterima, tapi integrasi G7 discrepancy ke review workflow tetap milik F5 (Codex), belum lengkap. |
| T-17 | Guardrail G8 | pass | pass | pass | pass | open | open | Versioned append-only result persistence diterima — trigger guard + chain invariant diuji lewat insert nyata di SQLite DAN PostgreSQL sungguhan (append-only, broken-chain reject, field_code check constraint, RLS service-only, SECURITY DEFINER search_path terkunci). Review UI dan browser E2E masih terbuka. |
| T-18 | Standar per bidang | pass | pass | pass | pass | open | open | `field_code`/`standard_version` tersimpan per versi, dibuktikan runtime di PostgreSQL; runtime selection standar di luar scope increment ini. |
| T-19 | Batas aspek non-kritis | pass | pass | pass | pass | open | open | Persistence/HTTP/PostgreSQL-RLS F3 diterima dan dibuktikan runtime; integrasi ke review/HPP (F5/F6) masih terbuka. |
| T-20 | Determinisme narasi | pass | pass | pass | pass | open | open | Persistence (`bilingual_narrative_versions`) dan HTTP projection (`BilingualNarrativeController`, `ReviewInputController`) diterima. PostgreSQL/RLS dibuktikan runtime (`tests/Postgres/BilingualNarrativeRlsTest.php`, 18 tes, termasuk FK ke `eligibility_decision_versions`). Konsumsi UI review masih terbuka. |
| T-21 | Rotasi konektor | pass | pass | pass | pass | open | open | Sama dengan T-20 — persistence/HTTP/PostgreSQL-RLS diterima dan dibuktikan runtime, jalur review/HPP (F5/F6) masih terbuka. |
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
| F3 | partial (naik) | Pure eligibility/guardrail T-12..T-19. Versioned persistence + HTTP projection accepted, merged to `main` via PR #2 (`b18d5b4`), RLS terbukti runtime di PostgreSQL 17.6 sungguhan. **Baru:** benar-benar dikonsumsi oleh UI produksi F5 (`ReportSigning.php`, bukan lagi fixture) sejak PR #9 merge 2026-09-19. | Browser E2E untuk seluruh alur F3→F5 (belum pernah dijalankan sama sekali di browser sungguhan). |
| F4 | partial (naik) | Composer bilingual deterministik T-20/T-21, persistence+HTTP accepted (PR #2). Psychologist-edited-text gap closed via `narrative_cluster_edits` (PR #4). **Baru:** teks INTEGRATION (termasuk edit psikolog) sekarang benar-benar dikonsumsi F5 production UI, bukan cuma tersedia via HTTP. | Browser E2E; F6 masih pakai data fixture, belum data F4 asli (GLM sedang mengerjakan). |
| F5 | **partial — DIBUKA ULANG 2026-09-20** (sebelumnya ACCEPTED). Alasan: E2E-1 (PR #15) + verifikasi Lead di kode menemukan `ReportSigning.php` punya `$slug = 'report-signing'` tanpa `{case}` padahal `mount(string $case, ...)` mewajibkannya → setiap role yang login dapat HTTP 500; tidak ada titik masuk ke kasus tertentu. Bukti acceptance lama hanya `Livewire::test()` yang menyuntik `case` langsung, jadi tidak pernah menguji route HTTP nyata. Bug sama ada di `deepseek/f5-resign-and-rls`. | Signing snapshot persistence (`report_signing_snapshots`, versioned append-only) + UI Filament produksi (`ReportSigning.php`) — bukan fixture. Merged `main` PR #9 (`603a74f`) 2026-09-19, diverifikasi independen coordinator: 46/46 test spesifik (217 assertion) + full regression 3532 test (cuma 27 gagal, semua terlacak ke 1 sebab tak terkait: manifest Vite hilang). Snapshot di-derive dari data tersimpan server, bukan input klien (bug keamanan asli ditemukan+diperbaiki sebelum diterima). DASS terbukti tidak pernah masuk snapshot (T-07). | **Route HTTP ReportSigning yang membawa case + halaman daftar kasus + feature test lewat HTTP nyata (bukan Livewire::test) — syarat buka-ulang, diminta ke lane DeepSeek sebelum PR re-sign di-merge.** Teks INTEGRATION/edit psikolog (`narrative_cluster_edits`) ternyata belum dibaca halaman F5 (hanya `narrative_version_id`) — temuan E2E-1, perlu keputusan. Alur re-sign/REVISED (sedang dikerjakan DeepSeek, belum push), pelebaran RLS ke psikolog+super_admin (dikonfirmasi user, sedang dikerjakan bareng), G7 real aggregator (di-hold, kemungkinan scope F2), browser E2E. |
| F6 | partial (naik dari open) | Draft HPP+internal report (fixture, `d829295`) coordinator-verified. **Baru:** pipeline render PDF sungguhan (dompdf) + penyimpanan private + signed URL 15 menit (`PdfReportRenderer`, `ReportDocumentPublisher`) dibangun GLM, diverifikasi independen coordinator 2026-09-19: 71/71 test (226 assertion) termasuk render dompdf nyata (bukan mock) dan test kebocoran object-key. Belum di-merge ke `main` (masih di `glm/f6-hpp-report-draft`, perlu rebase — sedang dikerjakan). | Sambungkan ke data F5 asli (masih fixture, sedang dikerjakan GLM), tanda tangan digital pada dokumen, E2E. |
| F7 | partial | Operational dashboard, branch fee/commission ledger with live PostgreSQL RLS, idempotent commission recording, withdrawal request lifecycle — ACCEPTED and merged via PR #5 (`621d859`). | Proctoring persistence (`proctor_photos`/`proctor_logs`) + timeline UI (diotorisasi, belum dimulai), dan matriks browser menyeluruh. |
| F8 | deferred | Xendit mencukupi keputusan saat ini. | Provider tambahan hanya dimulai setelah keputusan eksplisit. |
| F9 | partial (naik sedikit) | Full disposable PostgreSQL 533/533, hardening bertahap, command retensi inert. **Baru:** F9-O1 health-failure rehearsal PASS setelah repair (`/health` dikeluarkan dari middleware `web`), diverifikasi independen coordinator (test PHPUnit dijalankan langsung, bukan cuma dibaca laporannya). **Juga baru:** bug lingkungan `storage/framework` yang menghambat hampir semua worktree bootstrap sudah diperbaiki (PR #11) — dampaknya lintas-fase, bukan cuma F9. | Backup/restore rehearsal dan load-performance baseline: branch lama (`codex/f9-backup-restore-rehearsal`, `codex/f9-load-performance-baseline`) sudah basi (berbasis commit lama, akan "menghapus" test RLS kalau di-diff ke main) — **perlu dikerjakan ulang dari nol**, bukan dilanjutkan. Scheduler/config activation authority, launch gate final. |
