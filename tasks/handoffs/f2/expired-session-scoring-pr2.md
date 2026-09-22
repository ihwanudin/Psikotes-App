# ADR-0032 PR2 — sesi expired ikut dinilai (2026-09-23)

Untuk: tinjauan Lead atas branch `f2/expired-session-scoring`. PR kedua dari
4 yang disepakati untuk ADR-0032. Dibangun di atas PR1 (`f2/scoring-
orchestrator`, sudah merge sebagai #122) dan di atas pekerjaan F2
(2026-09-21) yang sudah lebih dulu mengekstrak `SealExpiredAssessmentSession`
dan menambah command `sessions:sweep-expired` — PR ini mewujudkan
`hookForFutureOrchestrator()` yang sengaja dikosongkan di pekerjaan itu,
sekarang psikolog sudah menjawab P4.

## Apa yang berubah

**`SealExpiredAssessmentSession::sealWithinTransaction()`** sekarang memanggil
`ScoreAssessmentSession` (IST/PAPI/RMIB; Kraepelin dilewati) tepat setelah
`UPDATE ... SET status='expired'` berhasil, di transaksi yang sama —
menggantikan `hookForFutureOrchestrator()` yang sebelumnya kosong. Karena
`sealWithinTransaction()` dipakai oleh KETIGA pemanggil (autosave/submit yang
menemukan sesi lewat waktu secara lazy, DAN `seal()` milik command sapu yang
membungkusnya dalam transaksinya sendiri), ketiganya otomatis ikut menilai
tanpa perlu diubah satu per satu.

**Gerbang status dilebarkan ke `expired`** di tiga tempat, konsisten dengan
temuan rencana PR1 dulu (gerbang ganda PHP + trigger DB):
1. `LoadSealedGenericAnswerSet::execute()` — `in_array(...,
   ['submitted','scored','expired'])`.
2. Ketiga `PersistSealed{Ist,Papi,Rmib}Result::sessionMatches()` —
   `in_array(...,['submitted','expired'])` (PAPI juga ikut — kelengkapannya
   sendiri TIDAK berubah, tetap semua-atau-tidak-sama-sekali, tapi sesi PAPI
   yang KEBETULAN lengkap sebelum tenggat tetap sesi valid untuk dinilai).
3. Trigger `guard_generic_instrument_result()` (Postgres) + padanan SQLite —
   migrasi baru `2026_09_23_000100`, `CREATE OR REPLACE FUNCTION` (Postgres)
   / `DROP TRIGGER`+rebuild (SQLite), pola sama seperti PR1's
   `2026_09_22_000100`.

**`COALESCE(submitted_at, expired_at)`** dipakai di ketiga tempat di atas,
bukan mengubah skema. `test_sessions_lifecycle_check` mewajibkan
`submitted_at IS NULL` untuk status `expired` (mutlak, dari migrasi
`2026_09_08_000100`) — jadi kolom/field `submitted_at` yang sudah ada di
mana-mana di seluruh basis kode TIDAK diubah namanya, hanya maknanya
diperluas secara terdokumentasi jadi "kapan jawaban sesi ini final", bukan
literal "kapan tombol submit ditekan".

**`test_sessions.status` TIDAK PERNAH menjadi `scored`** untuk sesi yang
expired (keputusan Lead 2026-09-22, blast-radius terkecil). Baris di
`generic_instrument_results` sendiri yang jadi bukti "sudah dinilai",
terpisah dari `test_sessions.status` — konsisten dengan fakta bahwa tidak
ada satu pun kode di basis ini yang pernah menulis transisi ke `scored`
(digrep, nol hasil).

## Penelusuran konsumen `status==='scored'` (syarat sebelum PR ini)

Sudah dilaporkan ke Lead secara terpisah sebelum PR ini dimulai: grep
menyeluruh di `app/` dan `resources/js/` menemukan HANYA satu tempat yang
menganggap `status==='scored'` sebagai bagian dari "sudah dinilai" —
`LoadSealedGenericAnswerSet.php`, yang memang jadi salah satu titik yang
dilebarkan di PR ini. Tidak ada konsumen lain yang perlu diperbarui.

## Bug nyata ditemukan sambil membaca (bukan bagian rencana awal)

**`App\Contracts\SealsExpiredAssessmentSessions` tidak pernah didaftarkan
sebagai binding di `AppServiceProvider`.** Ditemukan sambil menelusuri kode
yang sudah ada (2026-09-21) sebelum mengedit — `SweepExpiredAssessmentSessionsCommand::handle()`
menerima `SweepExpiredAssessmentSessions` lewat container Laravel, yang
constructor-nya sendiri butuh `SealsExpiredAssessmentSessions` (interface).
Tanpa binding, setiap eksekusi command nyata (`sessions:sweep-expired`,
terjadwal tiap menit) akan melempar `BindingResolutionException` — test yang
ada tidak pernah menangkap ini karena semuanya membangun
`SweepExpiredAssessmentSessions` manual (`new ...`), bukan lewat container.
Diperbaiki dengan menambah `$this->app->bind(SealsExpiredAssessmentSessions::class,
SealExpiredAssessmentSession::class)`.

## Test

- SQLite penuh: `AssessmentSessions`+`AssessmentResults` (482), `Unit`
  (1318, 9 skip konsisten baseline), `Architecture` (54) — semua hijau.
- Test baru: `SealExpiredAssessmentSessionScoringTest` — sesi PAPI expired
  dengan jawaban tidak lengkap tetap tersegel ke `expired` dengan baris
  `assessment_scoring_attempts` `outcome=failed_to_score` (TIDAK rollback,
  sesuai ADR §1 atomicity), dan sesi Kraepelin yang expired terbukti TIDAK
  PERNAH memanggil skorer sama sekali (fixture sengaja tanpa
  `session_definition_payload`, akan gagal loudly kalau skorer dipanggil).
  `LoadSealedGenericAnswerSetTest`: `test_open_terminal_and_unbound_sessions_fail_closed`
  ditulis ulang (bukan dihapus) untuk mencerminkan `expired` yang sekarang
  DITERIMA, plus test baru `test_expired_sessions_are_readable_via_coalesced_submitted_at`.
- Banyak fixture test LAMA (di luar cakupan PR ini secara langsung) ternyata
  membangun sesi `in_progress` tanpa `assessment_case_id`/data skoring nyata
  untuk menguji mekanika tenggat waktu/HTTP — begitu skoring benar-benar
  terpasang di jalur seal, ini mulai gagal (`SEALED_GENERIC_ANSWER_INVALID`
  tidak ditangkap, sesuai desain). Diperbaiki dengan mengganti `test_type`
  fixture-fixture itu ke `kraepelin` (satu-satunya instrumen yang
  `ScoreAssessmentSession` lewati sepenuhnya), BUKAN dengan melonggarkan
  gerbang — mengikuti pola yang sama persis dari PR1. File yang tersentuh:
  `AutosaveAssessmentAnswersTest`, `SubmitAssessmentSessionTest`,
  `AssessmentSessionHttpTest`, `SweepExpiredAssessmentSessionsTest`,
  `SweepExpiredAssessmentSessionsConcurrencyTest` (Postgres).
- **Postgres nyata** (`run-org-postgres.ps1`, suite penuh 714 test, tanpa
  filter): 7 gagal, PERSIS cocok nama-per-nama dengan 7 baseline yang sudah
  diketahui (`organization-postgres-baseline-policy`) — tidak ada regresi
  baru. Dua kali gagal sebelum ini (13→9→7), keduanya fixture Postgres lain
  (`AssessmentSessionAutosaveActionTest`, `AssessmentSessionSubmitActionTest`)
  yang punya pola sama seperti temuan SQLite di atas — perlu digilir ke
  `kraepelin` juga, tidak terlihat oleh test SQLite karena hanya ada di
  suite Postgres.
- Pint + PHPStan: bersih di semua file yang tersentuh.

## Belum diamati di produksi sungguhan

Sama seperti PR1: mekanisme ini sekarang BISA dipanggil (lewat command
terjadwal `sessions:sweep-expired`, sudah berjalan tiap menit sejak F2's
2026-09-21 kerja), tapi belum ada sesi produksi nyata yang benar-benar
kehabisan waktu DAN dinilai lewat jalur ini sejak PR ini merge.

## Tidak disentuh

`ScoreSealedPapiAnswerSet.php`/`PapiRawScoreCalculator.php`, kalkulator
skor IST/RMIB (semua rumus, nol perubahan), aturan RMIB bertingkat (PR3),
`SweepExpiredAssessmentSessions.php`'s candidate-selection logic sendiri
(sudah benar dari F2's pekerjaan 2026-09-21, hanya docblock-nya yang
diperbarui).
