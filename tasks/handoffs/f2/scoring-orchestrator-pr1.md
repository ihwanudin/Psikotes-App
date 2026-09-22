# ADR-0032 PR1 — orkestrator penilaian inti + IST kosong=salah (2026-09-22)

Untuk: tinjauan Lead atas branch `f2/scoring-orchestrator`. Bagian pertama
dari 4 PR yang disepakati (rencana dikirim dan disetujui Lead sebelum kode
ditulis — lihat riwayat percakapan). PR ini HANYA mencakup jalur submit
eksplisit: sesi `expired` belum ikut dinilai (PR2), RMIB masih pakai aturan
lama semua-atau-tidak-sama-sekali (PR3).

## Apa yang berubah

**Orkestrator baru** — `app/Actions/AssessmentResults/ScoreAssessmentSession.php`,
dipanggil sinkron di dalam transaksi `SubmitAssessmentSession::withinTransaction()`,
tepat setelah `status='submitted'` berhasil ditulis, HANYA untuk IST/PAPI/RMIB
(Kraepelin dilewati — pipeline sendiri, di luar cakupan ADR-0032). Rantai
loader→skorer→penyimpan yang sudah ada dan sudah teruji sekarang benar-benar
dipanggil untuk pertama kalinya dari kode produksi — sebelumnya tidak ada
satu pun jalur yang memanggilnya sama sekali (temuan inti ADR-0032).

Tidak pernah melempar exception untuk kegagalan yang bisa diduga: satu-satunya
kasus yang dikenali adalah `SEALED_GENERIC_ANSWER_INCOMPLETE` (baru, lihat di
bawah) dari loader — ditangkap, dicatat sebagai `failed_to_score`, TIDAK
membatalkan transaksi submit. Exception lain (data rusak, versi instrumen
tidak aktif, dst.) sengaja TIDAK ditangkap — itu bug/masalah infrastruktur
sungguhan, bukan peserta yang mengosongkan jawaban, dan harus tetap
membatalkan (sesuai prinsip ADR §1a: hanya kegagalan yang predictable yang
tidak boleh rollback).

**Tabel audit baru** — `assessment_scoring_attempts` (migrasi
`2026_09_22_000200`). Satu baris per upaya penilaian (bukan per sesi — sesi
bisa dicoba lebih dari sekali), `outcome` in (`scored`,`failed_to_score`,
`not_scorable` — nilai ketiga disiapkan untuk PR3, belum pernah dipakai di
PR ini), append-only + RLS service-only, pola sama seperti
`generic_instrument_result_ledger` tapi TANPA `assertExactState()`
byte-hash-pinning (itu penguatan ekstra migrasi lama, bukan syarat universal —
lihat docblock migrasi).

**Gerbang kelengkapan jadi per-instrumen** —
`LoadSealedGenericAnswerSet::execute()` (bukan `responses()` — koreksi kecil
atas rencana awal). PAPI TETAP menuntut jumlah jawaban persis sama dengan
definisi (baris kode identik perilakunya, hanya errornya sekarang kode baru
`SEALED_GENERIC_ANSWER_INCOMPLETE`, dibedakan dari `SEALED_GENERIC_ANSWER_INVALID`
supaya orkestrator bisa membedakan "predictable, boleh ditangkap" dari
"data rusak, jangan ditangkap"). IST dan RMIB boleh punya jawaban lebih
sedikit dari definisi. Pengecekan per-baris disederhanakan sekalian: dari
asumsi `item_no === offset+1` (rusak begitu ada celah) jadi "item_no dalam
rentang, naik monoton" — berlaku sama untuk ketiga instrumen.

**IST: butir kosong dinilai salah (P1)** — `ScoreSealedIstAnswerSet::responses()`
ditulis ulang dari indexing posisional ke lookup-by-item_no, celah diisi
sentinel string kosong. **`IstRawScoreCalculator` sendiri TIDAK diubah sama
sekali** — string kosong tidak pernah cocok dengan kunci jawaban (huruf a-e)
atau jawaban GE manapun (yang menolak string kosong saat data dibangun),
jadi otomatis bernilai salah lewat jalur perbandingan yang sudah ada.

**PAPI: nol perubahan pada `ScoreSealedPapiAnswerSet.php`/`PapiRawScoreCalculator.php`**,
sesuai instruksi eksplisit Lead. Yang berubah hanya `SealedPapiResult.php`
(domain, field `engineVersion` baru) dan `PersistSealedPapiResult.php`
(kolom ledger baru) — keduanya provenance, bukan skoring.

**`engine_version` di level ledger, bukan cuma proyeksi hilir** (migrasi
`2026_09_22_000100`). Kolom NOT NULL baru di `generic_instrument_results`,
mengikat setiap hasil ke versi KODE skoring yang menghasilkannya — terpisah
dari `instrument_version` (versi DATA/norma) dan `result_contract_version`
(versi BENTUK JSON, dinaikkan v1→v2 untuk IST/PAPI/RMIB karena payload-nya
kini memuat `engineVersion`). Masuk ke payload yang di-hash jadi
`resultChecksum` dan ke pengecekan `replayMatches()` — perubahan rumus
otomatis mengubah checksum, bukan cuma catatan terpisah yang bisa basi.
Kraepelin (di luar cakupan ADR-0032) tetap dapat kolom ini di level DB saja
(`PersistSealedKraepelinResult`), TIDAK di level payload/checksum — lihat
komentar di file itu untuk alasan pemisahannya.

## Temuan yang mengubah bentuk implementasi (tidak disebut di rencana awal)

1. **`SealedGenericAnswerSet::canonicalAnswers()` menolak array kosong** —
   ternyata TIDAK PERNAH tercapai untuk kasus "nol jawaban sama sekali",
   karena invarian TERPISAH yang sudah ada sebelum ADR-0032
   (`LoadSealedGenericAnswerSet` mensyaratkan `answers_revision >= 1`,
   dan revisi hanya naik saat ada jawaban tersimpan) sudah menolaknya lebih
   dulu. Sempat dicoba dilonggarkan, lalu dikembalikan setelah test
   membuktikan itu kode mati — dicatat di sini supaya tidak dicoba lagi.
2. **`PersistedIstResult.php`** (pembaca ledger terpisah, dipakai
   `LoadPersistedIstResult`) ternyata memvalidasi `array_keys($payload)`
   PERSIS — menambah `engineVersion` ke payload tanpa memperbarui reader ini
   akan merusaknya. Diperbarui (exact-keys list, cross-check
   `payload['engineVersion'] === parent['engine_version']`,
   `LoadPersistedIstResult`'s explicit column `select()`). Tidak ada
   pembaca setara untuk PAPI/RMIB (belum dibangun), jadi hanya IST yang
   terkena di sini.
3. **Ledger fixture di banyak test lain juga insert langsung ke
   `generic_instrument_results`** dengan daftar kolom eksplisit — 6 file
   test (Postgres + Feature) butuh `engine_version` ditambahkan ke fixture-nya
   supaya insert tidak gagal NOT NULL di Postgres (SQLite tidak menuntut ini,
   kolomnya nullable di sana secara sengaja — SQLite tidak bisa ALTER ADD
   COLUMN NOT NULL tanpa DEFAULT, jadi enforcement penuh cuma di Postgres,
   konsisten dengan pola "SQLite tidak punya CHECK constraint setara" yang
   sudah ada di ledger ini sebelum PR ini).
4. **Beberapa test lama membangun sesi 'ist'/'papi' tanpa `session_definition_payload`
   yang valid atau tanpa jawaban sama sekali** (mereka hanya menguji mekanika
   submit/HTTP, dibuat sebelum orkestrator sungguhan ada). Begitu orkestrator
   benar-benar dipanggil, ini gagal dengan `SEALED_GENERIC_ANSWER_INVALID`
   yang TIDAK ditangkap (benar, sesuai desain — bukan kegagalan predictable).
   Diperbaiki dengan mengganti `test_type` fixture-fixture itu ke `kraepelin`
   (satu-satunya instrumen didukung yang orkestrator lewati sepenuhnya),
   BUKAN dengan melonggarkan gerbang orkestrator — lihat komentar di setiap
   test yang diubah untuk alasannya. Kraepelin sendiri punya bentuk definisi
   yang kaku (matriks 50×27 tetap), jadi beberapa fixture butuh field
   `generator` lengkap, bukan sekadar ganti string `test_type`.
5. **Docblock `SubmitAssessmentSessionController.php` dan komentar test
   terkait** yang secara eksplisit menyatakan "scoring adalah pipeline
   terpisah yang action ini tidak jalankan" — itulah bukti yang dikutip
   ADR-0032 sendiri sebagai temuan awal — sekarang salah, diperbarui.
6. **Postgres tidak punya positioning kolom pada `ALTER TABLE ADD COLUMN`**
   (itu ekstensi khusus MySQL) — `engine_version` mendarat di posisi
   TERAKHIR (setelah `created_at`), bukan "setelah `result_contract_version`"
   seperti hint `->after()` di migrasinya. Ditemukan lewat kegagalan nyata,
   bukan diduga. Konsekuensi lebih dalam: `2026_09_13_000100_create_generic_
   instrument_result_ledger.php`'s `assertPostgresState()` (byte/bentuk-persis,
   dijalankan ulang setiap kali migrasi ini di-replay — termasuk oleh test
   "owner rerun" yang sengaja memanggil `up()`-nya lagi untuk membuktikan
   pemalsuan RLS/CHECK ditolak) tidak tahu kolom baru ini ada, sehingga
   replay itu gagal duluan di pengecekan kolom, sebelum sempat menguji apa
   yang test itu sebenarnya ingin buktikan. Diperbaiki dengan pola yang
   SUDAH ada persis untuk kasus serupa (`raw_score`/`band_low`/`band_high`
   widenable di migrasi yang sama): migrasi lama diberi toleransi eksplisit
   untuk kolom BARU yang lahir setelahnya, didokumentasikan di tempatnya,
   bukan ditulis dari nol. `GenericResultLedgerMigrationFixture.php` (dipakai
   test lain yang men-suspend lalu me-restore seluruh ledger) juga diberi
   panggilan `->up()` migrasi baru ini setelah rebuild-nya, mengikuti pola
   yang SUDAH didokumentasikan di file itu sendiri untuk kasus
   `2026_09_20_000200`.

## Test

- Semua area yang tersentuh dijalankan penuh di SQLite: `AssessmentResults`
  (74), PAPI regresi penuh (34, sesuai syarat tambahan Lead), `AssessmentSessions`
  Feature (36, termasuk test baru untuk kasus incomplete-submit-tidak-rollback
  yang ADR wajibkan), `Unit` (1281), `Architecture` (46).
- **Postgres nyata** (`run-org-postgres.ps1`, suite penuh 646 test, tanpa
  filter): 7 gagal, PERSIS cocok nama-per-nama dengan 7 baseline yang sudah
  diketahui (`organization-postgres-baseline-policy`) — tidak ada regresi
  baru. Dua kali gagal sebelum ini di 13 dan 7 kegagalan (temuan #6 di atas
  plus kolom `engine_version` hilang setelah replay migrasi lama), keduanya
  diperbaiki dan diverifikasi ulang sampai persis 7/7 cocok baseline.
- Golden test IST/RMIB/PAPI yang sudah ada (`Unit/Scoring`) tidak disentuh
  dan tetap hijau — membuktikan `IstRawScoreCalculator`/`PapiRawScoreCalculator`/
  `RmibRawScoreCalculator` benar-benar tidak berubah.

## Belum diamati di produksi sungguhan

Sama seperti timed-segments dulu: mekanisme ini sekarang BISA dipanggil,
tapi belum ada sesi produksi nyata yang lewat jalur ini (belum ada
psikolog/peserta sungguhan yang submit IST/PAPI/RMIB sejak PR ini merge).
Baris pertama di `generic_instrument_results`/`assessment_scoring_attempts`
akan menjadi bukti nyata pertama kali mekanisme ini benar-benar jalan.

## Tidak disentuh

`ScoreSealedPapiAnswerSet.php`, `PapiRawScoreCalculator.php`,
`IstRawScoreCalculator.php`, `RmibRawScoreCalculator.php` (semua rumus
skoring, nol perubahan), sesi `expired` (PR2), aturan RMIB bertingkat (PR3),
panel Filament untuk meninjau `failed_to_score`/`not_scorable` (follow-up
eksplisit, sama seperti ADR-0032 menandai panelnya sendiri di luar cakupan).
