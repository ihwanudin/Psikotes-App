# ADR-0032: Orkestrator penilaian generik (IST/PAPI/RMIB)

## Status

**Diusulkan — menunggu keputusan pemilik proyek/psikolog** pada titik-titik
keputusan psikometri di bawah (§Keputusan Terbuka). Bagian arsitektur/teknis
(pemicu, idempotensi, visibilitas kegagalan) tidak menunggu psikolog dan bisa
mulai diimplementasikan begitu Lead menyetujui pendekatannya.

## Date

2026-09-21

## Context

Ditemukan lewat investigasi F2 (dipicu laporan GLM): **tidak ada jalur produksi
yang pernah memanggil rantai penilaian untuk sesi apa pun, status apa pun,
instrumen apa pun.** `POST /sessions/:id/submit` hanya mengubah
`test_sessions.status` dari `in_progress` ke `submitted`; tidak memanggil apa
pun setelahnya (`SubmitAssessmentSessionController.php` docblok sendiri
menyatakan ini eksplisit). Ini bukan sekadar status `expired` yang terlewat —
sesi `submitted` sekalipun tidak pernah dinilai secara otomatis hari ini.

Komponen intinya justru **sudah ada dan sudah teruji**, hanya tidak pernah
dirangkai/dipanggil dari luar test:

| Komponen | File | Peran |
|---|---|---|
| Loader tersegel | `app/Services/AssessmentResults/LoadSealedGenericAnswerSet.php` | Membaca sesi + jawaban, mensyaratkan status `submitted`/`scored`, cakupan butir PERSIS lengkap, menghasilkan `SealedGenericAnswerSet` + checksum |
| Skorer per instrumen | `ScoreSealedIstAnswerSet.php`, `ScoreSealedPapiAnswerSet.php`, `ScoreSealedRmibAnswerSet.php` | Fungsi murni: sealed set + data skor bersumber `instrument_versions` → hasil ternormalisasi |
| Penyimpan tersegel | `PersistSealedIstResult.php`, `PersistSealedPapiResult.php`, `PersistSealedRmibResult.php` | Menulis ke `generic_instrument_results`/`generic_instrument_result_sources` (append-only, replay-aman: identitas sama → kembalikan hasil lama; identitas beda → `CONFLICT`, bukan timpa diam-diam) |
| Ledger | migrasi `2026_09_13_000100_create_generic_instrument_result_ledger.php` | Skema dengan trigger tolak-UPDATE/DELETE (append-only DB-level), sudah ada |

Jadi dokumen pembekuan arsitektur sebelumnya
(`tasks/handoffs/f2-authoritative-result-persistence-readiness.md`, yang
menyimpulkan "no authoritative result writer exists") **sudah usang untuk R0–R2
di taksonominya sendiri** — komponen-komponen itu sudah dibangun sejak dokumen
itu ditulis. Yang benar-benar hilang, dikonfirmasi dua kali oleh investigasi
terpisah, adalah **orkestrasi**: sesuatu yang (a) memutuskan KAPAN sebuah sesi
layak dinilai, (b) memanggil rantai loader→skorer→penyimpan untuk instrumen
yang tepat, dan (c) menangani sesi yang jawabannya tidak lengkap.

Kenapa ini mendesak dan bukan sekadar kerapian teknis: **IST dirancang supaya
sebagian besar peserta kehabisan waktu di subtes berwaktu** — itu bagian normal
dari instrumennya, bukan kegagalan peserta. Kalau sesi yang berakhir `expired`
(bukan `submitted` eksplisit) tidak pernah bisa dinilai, mayoritas peserta IST
tidak akan pernah punya hasil, tidak peduli seberapa benar komponen di atas.
`SCORING_ALGORITHM.md` sendiri tidak punya satu pun aturan untuk butir IST/PAPI/
RMIB yang tidak terjawab (satu-satunya aturan kelengkapan ada untuk DASS-21).

## Keputusan: struktur orkestrator (bagian yang tidak menunggu psikolog)

### 1. Pemicu — usulan: submit sinkron + expired via job terjadwal, keduanya

Dua peristiwa berbeda perlu memicu penilaian, dengan mekanisme berbeda karena
sifatnya berbeda:

**(a) Submit eksplisit** — peserta menekan submit sebelum waktu habis.
Usul: orkestrator dipanggil **sinkron, di dalam transaksi yang sama** dengan
`SubmitAssessmentSession::execute()`'s `UPDATE ... SET status='submitted'`,
sebelum commit. Alasan: `status='submitted'` dan hasil ternilai menjadi SATU
peristiwa atomik — tidak ada jendela waktu di mana sesi berstatus `submitted`
tapi belum ada (atau gagal punya) hasil karena proses terputus di antaranya.
Konsisten dengan pola atomik yang sudah dipakai di seluruh basis kode ini
(mis. autosave, alokasi sesi).

**Koreksi wajib (Lead, 2026-09-21): orkestrator TIDAK BOLEH melempar
exception untuk kegagalan penilaian yang sudah bisa diduga** (mis.
kelengkapan ditolak di bawah kebijakan semua-atau-tidak-sama-sekali, Titik
B — perilaku hari ini). Kalau ia melempar di dalam transaksi submit, SELURUH
transaksi di-rollback, termasuk `status='submitted'`: peserta menekan
"Kumpulkan", gagal, mencoba lagi dan gagal lagi, sementara timer terus
berjalan sampai sesinya kedaluwarsa — kesalahan kelengkapan kita menjadi
masalah peserta. Baris kegagalan audit di §3, kalau ditulis di transaksi
yang sama, juga ikut hilang saat rollback, sehingga psikolog/admin tidak
pernah melihatnya — persis yang §3 ingin cegah.

Perbaikannya, tanpa kehilangan atomisitas yang diinginkan: **orkestrator
tidak melempar untuk kegagalan yang sudah bisa diduga — ia MENGEMBALIKAN
NILAI berupa hasil `scored` atau `failed_to_score` (dengan kode alasan), dan
KEDUANYA (baik hasil penilaian maupun baris kegagalan §3) ditulis dalam
TRANSAKSI YANG SAMA dengan `status='submitted'`.** Dengan begitu:

- submit peserta SELALU tercatat — `status='submitted'` tidak pernah
  di-rollback karena alasan penilaian;
- "status + (hasil-penilaian ATAU baris-kegagalan)" tetap satu peristiwa
  atomik — tidak ada jendela di mana submit sukses tapi tidak ada satu pun
  jejak hasil/kegagalan penilaian.

Satu-satunya yang BOLEH me-rollback seluruh submit adalah kegagalan
infrastruktur yang benar-benar tidak terduga (database mati dan sejenisnya)
— di kasus itu submit memang belum sungguh terjadi, jadi rollback penuh
tetap benar dan bukan pengecualian dari aturan di atas.

**Test wajib**: submit dengan jawaban tidak lengkap di bawah kebijakan
semua-atau-tidak-sama-sekali → respons HTTP **sukses**, `test_sessions.status`
menjadi `submitted`, dan satu baris kegagalan penilaian (§3) tercatat dalam
transaksi yang sama. Tidak ada rollback; peserta tidak terjebak mencoba
submit berulang kali sementara timernya terus berjalan.

Biaya: latensi respons `/submit` bertambah sebesar waktu penilaian murni
(kalkulator-kalkulator ini murni/cepat, bukan I/O berat, jadi kemungkinan
besar dapat diabaikan — perlu diukur, bukan diasumsikan).

**(b) Kedaluwarsa tanpa request lanjutan** — peserta meninggalkan tes, tidak
pernah mengirim autosave/submit lagi setelah `ends_at` lewat. Transisi ke
`expired` SAAT INI murni lazy: hanya terjadi kalau ADA request berikutnya
(autosave atau submit) yang mendarat setelah deadline. Kalau tidak pernah ada
request lagi, sesi itu **selamanya tetap `in_progress` di database**, bukan
hanya "belum dinilai" — bahkan transisi status pun tidak pernah terjadi.
Usul: **command terjadwal baru** (pola sama seperti 5 command yang sudah ada di
`routes/console.php`, mis. `payments:reconcile-xendit`), berjalan tiap
beberapa menit, mencari `test_sessions WHERE status='in_progress' AND
ends_at < now()`, mentransisikan ke `expired` (menggunakan LOGIKA TRANSISI YANG
SAMA yang sekarang terduplikasi di `AutosaveAssessmentAnswers.php` dan
`SubmitAssessmentSession.php` — **usul refactor kecil**: ekstrak jadi satu
fungsi/aksi bersama supaya tidak ada logika transisi ketiga yang berbeda di
command baru ini), lalu memicu orkestrator untuk sesi itu (kalau keputusan
psikometri di bawah memutuskan sesi `expired` memang harus dinilai).

Command ini murni housekeeping/pemicu — tidak menaruh logika waktu di klien,
tidak mengubah `ends_at`/`started_at`, hanya menyapu sesi yang jamnya SUDAH
lewat menurut jam server yang sama yang dipakai di tempat lain.

**Prinsip yang sama seperti §1(a) berlaku di sini**: kegagalan menilai SATU
sesi (dicatat sebagai baris §3) TIDAK BOLEH menghentikan penyapuan sesi-sesi
lain dalam batch yang sama. Setiap sesi diproses dalam transaksinya sendiri
(transisi ke `expired` + hasil-penilaian-atau-kegagalan sebagai satu unit
atomik per sesi, sama seperti §1a); command lanjut ke sesi berikutnya
setelah mencatat kegagalan satu sesi, tidak berhenti di tengah batch.

### 2. Penyegelan — sudah cukup, satu perubahan diperlukan pada gerbang status

`LoadSealedGenericAnswerSet.php:69` saat ini hanya menerima status `submitted`
atau `scored`. **Kalau** keputusan psikometri di bawah memutuskan sesi
`expired` boleh dinilai, gerbang ini harus diperluas menerima `expired` juga
(perubahan kontrak kecil, satu baris `in_array`, tapi menyentuh test yang sudah
ada — termasuk `LoadSealedGenericAnswerSetTest.php:135-144` yang SAAT INI
secara eksplisit menguji bahwa `expired` ditolak; test itu perlu ditulis ulang,
bukan dihapus, untuk mencerminkan kontrak baru). Checksum tersegel
(`sealed-generic-answers:v1|...`) dan mekanisme replay-aman di
`PersistSealed*Result` tidak perlu berubah — keduanya sudah generik terhadap
status asal, hanya membaca apa pun yang loader berikan.

### 3. Idempotensi dan visibilitas kegagalan

**Idempotensi**: `PersistSealed*Result` SUDAH menangani ini dengan benar
(identitas sama → kembalikan hasil lama; identitas beda → `CONFLICT`, bukan
timpa). Orkestrator tidak perlu membangun ulang mekanisme ini, hanya
memanggilnya.

**Visibilitas kegagalan — bagian yang benar-benar hilang**: kalau orkestrator
mencoba menilai sebuah sesi dan gagal (mis. kelengkapan ditolak di bawah
kebijakan yang dipilih), kegagalan itu TIDAK BOLEH hanya jadi baris log yang
hilang di worker antrean. Usul: catatan audit tahan-lama (append-only, mis.
tabel baru `assessment_scoring_attempts` atau perluasan `audit_logs` yang
sudah ada) berisi minimal: session_id, instrument, waktu percobaan, hasil
(`scored`/`failed`), alasan gagal (kode, bukan pesan bebas, supaya bisa
difilter).

**Wajib (Lead, 2026-09-21): baris kegagalan ini HARUS ditulis dalam transaksi
YANG SAMA dengan transisi status yang memicunya** (`status='submitted'` untuk
§1a, transisi ke `expired` untuk §1b) — TIDAK BOLEH di transaksi terpisah,
job susulan, atau proses async lain yang bisa gagal/hilang secara independen
dari transisi status itu sendiri. Kalau ditulis terpisah, sebuah rollback
pada transaksi utama (jarang, tapi mungkin untuk kegagalan infrastruktur)
bisa meninggalkan status berubah tanpa baris kegagalan yang menjelaskannya,
atau sebaliknya. Menulis di transaksi yang sama menjamin keduanya konsisten
selalu — persis prinsip yang sama dengan koreksi §1(a) di atas.

Ini yang membuat peserta bernilai kosong TERLIHAT oleh psikolog/admin, bukan
hilang diam-diam persis seperti temuan submit-kelengkapan GLM. Menampilkannya
di panel admin (Filament) adalah pekerjaan susulan di luar scope ADR ini,
bukan syarat untuk versi pertama.

### 4. Hubungan ke hilir (F3/F4/F5)

`LoadGenericInstrumentResultSourcesForAspect` (F3, dites di
`tests/Feature/AssessmentResults/LoadGenericInstrumentResultSourcesForAspectTest.php`)
SUDAH membaca dari `generic_instrument_results`/`generic_instrument_result_sources`
yang sungguhan — begitu orkestrator mengisi baris nyata di sana, F3 seharusnya
langsung mendapat data nyata tanpa perubahan lebih lanjut. Ini **perlu
diverifikasi**, bukan diasumsikan selesai — saya belum menelusuri F4/F5 secara
spesifik untuk ADR ini. Ditandai sebagai langkah verifikasi terpisah setelah
orkestrator berjalan, bukan bagian dari ADR ini.

## Keputusan Terbuka — psikometri, BUKAN saya yang memilih

Ini titik keputusan yang perlu dibawa ke pemilik proyek/psikolog. Saya
tunjukkan pilihannya dan konsekuensi tekniknya, tidak memilih.

### Titik A — Apakah sesi `expired` boleh dinilai sama sekali?

- **Ya**: perlu perubahan §2 di atas (perluas gerbang status). Konsisten
  dengan sifat IST (kehabisan waktu = normal). Tanpa ini, mayoritas peserta
  IST tidak akan pernah punya hasil.
- **Tidak**: peserta yang kehabisan waktu tanpa submit eksplisit tidak pernah
  dinilai, titik. UI klien HARUS memaksa submit sebelum/tepat saat waktu
  habis (logika ini sudah ada sebagai kewajiban GLM di sisi klien untuk
  kasus lain — perlu dikonfirmasi cakupannya mencakup semua subtes
  berwaktu, tidak hanya sesi utuh).

### Titik B — Kelengkapan jawaban, per instrumen

Hari ini SEMUA gerbang (loader + tiap skorer) menuntut cakupan butir PERSIS
lengkap — semua-atau-tidak-sama-sekali, tanpa pengecualian, untuk ketiga
instrumen generik. Tidak ada satu pun jalur yang memperlakukan butir kosong
sebagai "salah". Tiga pilihan, bisa berbeda per instrumen:

1. **Tetap semua-atau-tidak-sama-sekali** (perilaku hari ini): sesi tidak
   lengkap tidak pernah dinilai, hanya tercatat gagal (§3). Paling aman
   secara teknis, tapi untuk IST berarti kombinasi dengan Titik A=Ya masih
   tidak cukup — butir yang benar-benar tidak terjawab (bukan cuma sesi yang
   expired) tetap memblokir penilaian TOTAL, bukan hanya subtes yang
   terkena.
2. **Butir kosong = salah** (konvensi umum tes berwaktu): skorer perlu
   diubah untuk mengisi slot kosong sebagai jawaban salah, bukan menolak.
   Ini PERUBAHAN LOGIKA SKOR, bukan sekadar orkestrasi — menyentuh
   `ScoreSealedIstAnswerSet`/`PapiAnswerSet`/`RmibAnswerSet` langsung, jadi
   di luar scope "hanya orkestrator" ADR ini kalau dipilih; perlu ADR/PR
   skoring terpisah, dan `SCORING_ALGORITHM.md` perlu diperbarui menulis
   aturan ini secara eksplisit (saat ini KOSONG untuk tiga instrumen ini).
3. **Ambang minimum per subtes**: subtes yang tidak mencapai ambang
   jawaban-terjawab tertentu ditolak (menghasilkan flag validitas, mirip
   V1/V2/V3 proctoring yang sudah ada sebagai kosakata domain), sementara
   subtes yang cukup lengkap tetap dinilai. Paling rumit, butuh definisi
   ambang per subtes dari psikolog.

### Titik C — Kalau Titik B memilih opsi 2 atau 3: siapa yang bertanggung jawab menulis ulang skorer?

Bukan bagian ADR ini untuk diputuskan sekarang, hanya dicatat: itu perubahan
`app/Services/Scoring/*` yang menyentuh `SCORING_ALGORITHM.md`, wajib test
baru, dan (per CLAUDE.md) TIDAK boleh menanam ambang di kode — kalau ambang
per subtes (opsi 3) dipilih, ambang itu harus datang dari Tabel Lookup, sama
seperti bobot/norma lainnya.

## Konsekuensi

- Implementasi §1–§3 (pemicu + gerbang status kondisional + audit kegagalan)
  bisa mulai sebelum Titik A/B dijawab, dengan asumsi sementara "hanya
  `submitted`, semua-atau-tidak-sama-sekali" (perilaku hari ini) sebagai
  baseline yang sudah aman, LALU diperluas begitu jawaban psikolog datang.
  Ini menghindari memblokir seluruh pekerjaan sampai psikolog menjawab,
  tanpa mendahului keputusan psikometrinya.
- Command terjadwal baru berarti permukaan operasional baru (perlu dipantau
  seperti 5 command lain yang sudah berjalan).
- Test PostgreSQL wajib untuk orkestrator: idempotensi di bawah proses
  konkuren (dua worker mencoba menilai sesi yang sama bersamaan — pola sama
  seperti test balapan yang sudah ada di lane ini), RLS/append-only pada
  tabel audit kegagalan baru kalau dipilih sebagai tabel terpisah, dan bukti
  langsung dari koreksi §1(a)/§3: submit dengan jawaban tidak lengkap
  menghasilkan `status='submitted'` PLUS baris kegagalan, bukan rollback
  seluruh transaksi.

## Alternatif yang Dipertimbangkan

- **Memicu penilaian dari respons `/items` atau `/answers` (baca)**: ditolak
  — endpoint baca tidak boleh punya efek samping tulis (prinsip yang sudah
  dipegang teguh di seluruh lane `/items`/`/answers` readback: "GET tidak
  boleh menulis").
- **Menilai secara sinkron di dalam command penyapu `expired`** (bukan
  hanya memicu): dipertimbangkan tapi command terjadwal sebaiknya tetap
  ringan/cepat (menyapu banyak sesi sekaligus); memanggil orkestrator
  penuh per sesi di dalam loop yang sama berisiko membuat satu sesi yang
  gagal dinilai menghambat penyapuan sesi lain. Usul tetap memisahkan
  "menyapu status" dari "memicu penilaian" sebagai dua tanggung jawab,
  meski keduanya bisa satu command untuk versi pertama.
