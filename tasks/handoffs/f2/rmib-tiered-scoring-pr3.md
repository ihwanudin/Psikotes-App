# ADR-0032 PR3 — RMIB aturan bertingkat untuk ranking tidak lengkap (2026-09-23)

Untuk: tinjauan Lead atas branch `f2/rmib-tiered-scoring`. PR ketiga (dan
terakhir yang besar) dari 4 yang disepakati untuk ADR-0032. Dibangun di atas
PR1 (`f2/scoring-orchestrator`, #122, merge) dan PR2
(`f2/expired-session-scoring`, #140, merge) — branch ini sudah di-rebase ke
main terbaru yang memuat keduanya; tidak ada tumpang-tindih file dengan PR2
sehingga rebase bersih tanpa konflik.

## Apa yang berubah

**`RmibRawScoreCalculator::calculate()`** tidak lagi mewajibkan 108 respons
lengkap tanpa duplikat rank. Kesembilan kelompok (12 posisi/rank masing-
masing) diklasifikasikan independen, sesuai jawaban psikolog P3:

- **Lengkap** atau **kurang satu peringkat**: bila kurang satu, peringkat
  yang hilang direkonstruksi (satu-satunya nilai 1–12 yang belum dipakai di
  kelompok itu — dijamin unik oleh pigeonhole karena 11 rank lain sudah
  berbeda). Dianggap sepenuhnya sah, skor identik dengan kelompok lengkap.
- **Dikecualikan**: 2+ posisi hilang, DAN/ATAU ada rank berduplikat di
  kelompok yang sama (dua syarat independen, dicek terpisah — bisa terjadi
  bersamaan). Kelompok ini dikeluarkan dari SEMUA kategori, bukan hanya
  kontribusinya sendiri. Karena setiap kategori muncul tepat sekali per
  kelompok pada rotasi kanonis (diverifikasi ulang sebelum menerapkan
  aturan ini, sesuai permintaan), mengecualikan satu kelompok mengurangi
  `cell_count` SETIAP kategori tepat satu — perbandingan rank antar-kategori
  tetap adil.
- Sebelum kelompok apa pun (lengkap/direkonstruksi) dipakai, kode secara
  eksplisit memverifikasi ulang bahwa kelompok itu memuat tepat rank 1–12
  dan berjumlah 78 — permintaan Lead yang diulang dua kali. Untuk kelompok
  yang lolos klasifikasi di atas, ini secara matematis pasti benar; sebuah
  kegagalan di titik ini berarti bug di logika klasifikasi sendiri, bukan
  data peserta.
- **2 kelompok atau lebih dikecualikan** → `scorable: false` — sesi tidak
  dapat dinilai sama sekali, administrasi ulang, tidak ada skor sebagian.
- Return shape baru: `scorable`, `excluded_groups` (list kelompok 1–9),
  `review_required`, `review_reason` (`RMIB_GROUP_EXCLUDED` /
  `RMIB_MULTIPLE_GROUPS_INVALID`) — field `review_required`/`review_reason`
  sudah ada sejak awal sebagai placeholder yang selalu `false`/`null`,
  tampaknya memang disiapkan untuk PR ini.

**`ScoreSealedRmibAnswerSet::responses()`** ditulis ulang dari indexing
posisional ke lookup berdasar `item_no` (mencerminkan pola IST di PR1) —
`item_no` yang tidak ada di jawaban tersegel dilewati (bukan error), dibaca
kalkulator sebagai sel kosong. Bila `calculate()` melaporkan `scorable:
false`, method melempar exception baru `SEALED_RMIB_RESULT_NOT_SCORABLE`
SEBELUM menyentuh `RmibScoreCalculator`/`RmibRankLevelCalculator` (yang
mengasumsikan rank nyata) dan sebelum mencoba membangun `SealedRmibResult`.

**`ScoreAssessmentSession::execute()`** — arm RMIB pada `match($instrument)`
sekarang dibungkus try/catch yang secara spesifik mengenali
`SEALED_RMIB_RESULT_NOT_SCORABLE` dan mencatatnya sebagai
`AssessmentScoringOutcome::notScorable('RMIB_MULTIPLE_GROUPS_INVALID')` —
TANPA membatalkan transaksi status sesi (pola sama dengan penanganan
`SEALED_GENERIC_ANSWER_INCOMPLETE` yang sudah ada). Exception lain (dari
RMIB maupun IST/PAPI) tetap menembus tanpa ditangkap, sesuai desain semula.

**`AssessmentScoringOutcome::notScorable(string $reasonCode)`** — factory
baru, mencerminkan bentuk `failedToScore()`, memenuhi CHECK constraint yang
sama di `assessment_scoring_attempts` (outcome `not_scorable` sudah
diantisipasi sejak migrasi PR1: `outcome IN
('scored','failed_to_score','not_scorable')`).

**`SealedRmibResult`** — payload gained `reviewRequired: bool` dan
`excludedGroups: list<int>` (maks 1 elemen — 2+ tidak pernah sampai ke
`seal()`, divalidasi lewat `assertExcludedGroups()` baru, termasuk
konsistensi `reviewRequired` dengan `excludedGroups` non-kosong).
`CONTRACT_VERSION` v2→v3. `ENGINE_VERSION` v1→v2 — berbeda dari PR1/PR2
yang murni orkestrasi, ini kenaikan sungguhan karena ATURAN skoringnya
berubah. Bound `rawScore` per kategori melebar dari [9,108] ke [8,108]
(satu kelompok dikecualikan → 8 sel kontribusi minimum, bukan 9).

**`ScoringFailuresReview.php` (#139)** — TIDAK disentuh. Sudah diperiksa:
docblock-nya sendiri menyatakan query sudah mencakup outcome `not_scorable`
sejak awal, menunggu kode yang benar-benar menulisnya — PR ini adalah kode
itu. Dua-tingkat akses (psikolog lihat `reason_code`, SuperAdmin/CentralAdmin
hanya keberadaan baris) langsung berlaku untuk baris `not_scorable` baru
tanpa perubahan.

## Yang TIDAK disentuh

`ScoreSealedPapiAnswerSet.php`/`PapiRawScoreCalculator.php` (P2 belum
dijawab, tetap di luar cakupan seluruh ADR-0032). `IstRawScoreCalculator`.
Skema database — tidak ada migrasi baru; `reviewRequired`/`excludedGroups`
hidup di dalam `result_payload` JSON yang sudah ada (pola sama seperti
`engineVersion` PR1: field baru di payload tidak selalu perlu kolom baru).

## Catatan untuk Lead — belum diputuskan, perlu keputusan psikolog/produk

**Belum ada UI yang menampilkan `reviewRequired`/`excludedGroups` ke
psikolog.** P3 eksplisit bilang hasil kelompok-dikecualikan "dibaca
kualitatif" — tapi field ini hari ini hanya tersimpan di dalam
`result_payload` ledger, tidak muncul di laporan atau panel psikolog mana
pun. Di luar cakupan PR ini (bukan diminta, dan menyentuh UI laporan adalah
perubahan terpisah) — dilaporkan sebagai temuan, bukan dikerjakan diam-diam,
sesuai aturan CLAUDE.md.

**Judgment call (dikonfirmasi Lead, 2026-09-23)**: nilai jawaban RMIB yang
ADA tapi cacat bentuk (tidak cocok pola rank) tetap
`SEALED_RMIB_RESULT_INVALID` (gagal keras, seluruh sesi), BUKAN
diperlakukan sama seperti "hilang". Alasan (Lead): keputusan psikolog P3
soal peringkat kurang/ganda konteksnya peserta yang tidak lengkap mengisi
DALAM domain valid {1-12}, bukan data yang rusak/di luar domain — kalau
yang terakhir ini dianggap "hilang" dan ikut masuk jalur
rekonstruksi/eksklusi, bug penyimpanan data bisa tersamar jadi perilaku
psikometri normal. Konsisten dengan pembedaan `INCOMPLETE` vs `INVALID`
yang sudah ada di loader.

## Test

- Unit baru (`RmibRawScoreCalculatorTest`): rekonstruksi satu-hilang identik
  dengan lengkap; satu kelompok cacat (dua varian: 2 sel hilang, rank
  duplikat) dikecualikan tapi sesi tetap skor, `cell_count` setiap kategori
  turun tepat satu, total turun; dua kelompok cacat → `scorable: false`. Dua
  kasus lama ('missing cell', 'duplicate rank within group') yang dulu
  menguji hard-reject dihapus dari `invalidResponses()` (sekarang valid di
  bawah aturan baru) — empat kasus structural/domain lain (`duplicate cell`,
  `out of domain cell`, `malformed rank`, `rank outside 1-12`) tetap
  hard-reject tanpa perubahan.
- Feature baru (`ScoreSealedRmibAnswerSetTest`): pipeline penuh
  (loader→kalkulator→seal) untuk ketiga tingkatan yang sama, termasuk
  `SEALED_RMIB_RESULT_NOT_SCORABLE` benar-benar terlempar untuk kasus dua
  kelompok cacat.
- Unit baru (`SealedRmibResultTest`): floor `rawScore` turun ke 8 (dulu 9);
  `reviewRequired`/`excludedGroups` harus konsisten satu sama lain; 2+
  `excludedGroups` ditolak (kasus itu tidak pernah boleh sampai `seal()`).
- SQLite penuh (setelah rebase ke main yang memuat PR2): `Unit` (1323, 9
  skip konsisten baseline), `AssessmentResults`+`AssessmentSessions`+
  `Architecture` (340) — semua hijau. (`--testsuite=Feature` penuh dalam
  satu proses OOM di 512MB memory_limit lokal -- ini bukan regresi, migrasi
  yang disebut di pesan errornya, `2026_09_10_000300_expand_generic_
  entitlement_case_identity.php`, tidak tersentuh PR ini maupun PR2;
  dijalankan per-direktori seperti PR1/PR2 sebagai gantinya.)
- **Postgres nyata** (`run-org-postgres.ps1`, suite penuh 714 test, tanpa
  filter): 7 gagal (2 error + 5 failure). Untuk memverifikasi ini bukan
  regresi PR3, dijalankan DUA KALI dengan kondisi identik -- sekali di
  branch ini, sekali di `origin/main` bersih (checkout terpisah,
  worktree yang sama) -- hasilnya SAMA PERSIS nama-per-nama dan jumlah
  (`Tests: 714, Assertions: 6805, Errors: 2, Failures: 5` di keduanya):
  `AssessmentBillingMigrationTest::test_populated_policy_and_schema_rollback_preserve_legacy_and_reupgrade`,
  `AssessmentCaseSecurityTest::test_owner_empty_roundtrip_and_populated_rollback_are_fail_closed`,
  `AssessmentBillingMigrationTest::test_result_ledger_fixture_preserves_reverse_down_and_forward_up_order`,
  `AssessmentBillManualReviewTest::test_same_review_in_two_runtime_processes_has_one_settlement_audit_and_outbox`,
  `AssessmentBillManualReviewTest::test_opposite_reviews_in_two_processes_commit_only_one_terminal_decision`,
  `BilingualNarrativeRlsTest::test_guard_function_owned_by_migration_owner`,
  `EligibilityDecisionRlsTest::test_guard_function_owned_by_migration_owner`.
  Tidak satu pun menyentuh RMIB/PR3; semuanya sudah gagal identik di
  `origin/main` sebelum PR ini disentuh sama sekali. (Catatan: memori sesi
  mengira baseline saat ini 8 kegagalan -- dua kali jalan bersih hari ini
  konsisten menunjukkan 7 bernama sama; kemungkinan catatan itu sudah
  kedaluwarsa, dilaporkan untuk verifikasi Lead, bukan diperbaiki sendiri.)
- Pint + PHPStan (`--no-progress`, seluruh proyek, bukan hanya file
  tersentuh): bersih.

## Dokumen

`SCORING_ALGORITHM.md` §5 diperluas dengan aturan bertingkat lengkap,
`Versi kontrak` SCORING-4.4.0→4.5.0. `CHANGELOG.md` entri baru.
