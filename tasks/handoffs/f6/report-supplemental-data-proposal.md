# F6 — Proposal: sumber data untuk 5 input HPP yang belum tersimpan

Status: **PROPOSAL, belum ada migration.** Untuk direview Lead + user.
Dari: sesi GLM-channel. Tanggal: 2026-09-20.
Basis kode: `glm/f6-hpp-report-draft` @ `b2f6e05`.

## Konteks

`SignedReportDataset` (F6) membangun HPP dari snapshot SIGNED dan menolak membuat PDF (fail-closed) selama ada input yang belum punya sumber.
Titik sambungnya satu interface, `ReportSupplementalData`.
Implementasi saat ini, `UnavailableReportSupplementalData`, jujur mengembalikan `null` untuk semua input.
Proposal ini menjelaskan dari mana kelima input seharusnya berasal.

Ringkasan: hanya **dua dari lima yang butuh skema DB**. Satu cukup perubahan kontrak F5 (JSON snapshot). Dua lainnya **data instrumen**, bukan skema.

| # | Input | Jenis solusi | Lane pemilik (usulan) | Butuh migration? |
|---|---|---|---|---|
| 1 | Nomor laporan | Tabel baru `report_documents` (SPEC §11 entitas `Laporan`) | F6 (GLM-channel) | Ya |
| 2 | SIPP psikolog | Kolom `admins.sipp_number` + disalin ke dokumen saat terbit | F6 + F7 (admin CRUD) | Ya |
| 3 | Alasan rekomendasi | Field baru di input tanda tangan F5 → `snapshot_json.prerequisite_input` | F5 (DeepSeek) | Tidak |
| 4 | Label aspek ID/JP | Data: tambah peta aspek ke `reporting.json` via `tools/extract` | F0 extract + psikolog/penerjemah | Tidak |
| 5 | Teks narasi & tindak lanjut DASS | **Sudah ada** di `dass21.json`; ada 2 celah kecil | F6 (baca) + keputusan psikolog | Tidak (opsional 1 kolom) |

---

## 1. Nomor laporan → tabel `report_documents`

SPEC §11 (Model Data) sudah mendefinisikan entitas `Laporan(no_laporan, status, hash, psikolog_id, versi)`, tetapi belum ada tabelnya.
Saat ini PDF yang terbit disimpan di disk privat `reports` dan tidak dicatat di mana pun, sehingga object key-nya hilang.

Usulan tabel (append-only, pola sama dengan `report_signing_snapshots`):

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | ulid PK | |
| `assessment_case_id` | bigint FK `assessment_cases` | |
| `signing_snapshot_id` | ulid FK `report_signing_snapshots` | Dokumen selalu terikat ke satu snapshot SIGNED |
| `document_type` | varchar | CHECK `hpp`/`internal` |
| `report_number` | varchar(32) | UNIQUE per `(document_type, report_number)` |
| `version` | int | Naik bila snapshot di-resign/REVISED |
| `object_key` | varchar | Kunci acak dari `ReportDocumentPublisher` (tidak mengandung PII) |
| `sha256` | char(64) | Hash PDF, untuk verifikasi keaslian (SPEC "hash") |
| `size_bytes` | int | |
| `psychologist_admin_id` | bigint FK `admins` | |
| `psychologist_name_snapshot` | varchar | Disalin saat terbit; edit akun kemudian tidak mengubah laporan lama |
| `psychologist_sipp_snapshot` | varchar | Idem (lihat #2) |
| `created_at` | timestamptz(6) | |

- **Penerbitan nomor:** pola sama dengan `MonthlyTestNumberIssuer` / `test_number_sequences`, yaitu tabel sequence per bulan.
- **Idempoten per snapshot:** generate ulang untuk snapshot yang sama memakai nomor yang sama (PDF baru boleh, nomor tetap).
- **RLS:** baca/tulis hanya `service`. Akses UI dijaga per halaman: `hpp` untuk psikolog; `internal` untuk psikolog saja (CLAUDE.md: Lembar Internal hanya psikolog+peserta).
- **Trigger guard:** immutable, tidak ada UPDATE/DELETE (pola `guard_*_history`).

**Keputusan yang dibutuhkan (user/psikolog):**
- a) Format nomor, misalnya `HPP/2026/09/0001` atau format resmi LSI.
- b) Resign/REVISED: nomor sama dengan versi naik, atau nomor baru?
- c) Apakah nomor dicetak juga di Lembar Internal?

## 2. SIPP psikolog → `admins.sipp_number`

- Kolom `admins.sipp_number varchar(64) NULL`. Opsional: `sipp_valid_until date NULL`.
- Tidak di-CHECK di DB (admin non-psikolog memang tanpa SIPP). Syaratnya ditegakkan di aplikasi: **prasyarat tanda tangan F5 menolak psikolog tanpa SIPP.** Ini perubahan kecil di `ReportSigningPrerequisitePolicy`, lane DeepSeek.
- Nilainya **disalin** ke `report_documents.psychologist_sipp_snapshot` saat terbit (#1), sehingga pergantian SIPP tidak mengubah laporan yang sudah terbit.
- Form admin (F7/Codex) perlu field ini.

**Keputusan:** apakah masa berlaku SIPP ikut dicek (tolak tanda tangan bila kedaluwarsa)?

## 3. Alasan rekomendasi → input tanda tangan F5 (tanpa migration)

HPP mencetak blok "rekomendasi + alasan" (`resources/views/reports/hpp.blade.php:83`), tetapi alasan tidak ada di kontrak tanda tangan.
Karena teks ini masuk ke dokumen bertanda tangan, sebaiknya **ditulis/disetujui psikolog saat tanda tangan**.

- Tambah `recommendation_rationale` (string, wajib, misalnya ≥20 karakter seperti alasan G6) ke structural input `ReportSigningSnapshotComposer`. Otomatis ikut tersimpan di `snapshot_json.prerequisite_input`, jadi tidak perlu kolom baru.
- Form `ReportSigning.php` mendapat textarea. Boleh diisi awal dengan draf otomatis (lihat keputusan di bawah).
- Adapter F6 lalu membaca dari snapshot, dan `ReportSupplementalData::recommendationRationale()` bisa dihapus.

**Keputusan:**
- a) Murni tulisan psikolog, atau diisi awal dari templat Bank Narasi berdasarkan zona? Kalau templat, teksnya harus dari psikolog (data, bukan kode).
- b) Snapshot SIGNED yang sudah ada tidak punya field ini. Untuk snapshot lama: resign, atau tetap fail-closed?

## 4. Label aspek ID/JP → data instrumen (tanpa migration)

Saat ini ada **dua set label yang saling bertentangan di kode**, dan keduanya bukan sumber otoritatif:
- `app/Filament/Pages/ReportSigning.php` `ASPECT_LABELS`, contoh C5 = "Ketahanan kerja";
- fixture/template F6, contoh C5 = "Ketaatan Aturan & Keselamatan" + JP.

Sesuai CLAUDE.md ("jangan menanam … di kode; data instrumen hanya lewat `tools/extract`"):
- Tambah `aspects: {A1: {label_id, label_jp}, …}` ke `reporting.json`, diekstrak dari Bank Narasi. File sumber `Bank Narasi Formula HPP Psikotes.xlsx` **tidak ada di mesin ini**, dan belum dicek apakah ada sheet berisi nama aspek.
- Label JP wajib melewati review penerjemah tersertifikasi (SPEC §15 Butir Terbuka no. 7).
- Adapter F6 membaca label dari instrumen `reporting` versi `standard_version` yang dipakai snapshot. `ReportSigning.php` sebaiknya ikut membaca sumber yang sama (lane DeepSeek).

**Keputusan:** psikolog menyediakan daftar nama resmi 18 aspek (ID + JP).

## 5. Teks narasi & tindak lanjut DASS → sudah ada di `dass21.json`

Isi `database/seeders/data/dass21.json` (sudah di-seed ke `instrument_versions` kode `dass21`):
- `narratives` bertipe `Kategori umum`: 5 teks (Normal … Sangat Parah), ID + JP.
- `follow_up.monitoring` (kategori umum = Sedang) dan `follow_up.referral` (Parah/Sangat Parah), ID + JP.

Tidak perlu skema untuk teksnya. Dua celah kecil:
- a) **Normal/Ringan tidak punya teks tindak lanjut**, sedangkan `DassScreeningSummary` saat ini mewajibkan `follow_up` terisi. Opsi: (i) psikolog menambah teks "tanpa tindak lanjut khusus" ke Bank Narasi; atau (ii) `follow_up` boleh null dan template menyembunyikan barisnya. **Keputusan psikolog.**
- b) **Versi teks:** `dass.results` tidak mencatat versi instrumen `dass21` yang dipakai saat skoring. Opsi: pakai versi aktif saat generate (sederhana, tapi bisa tidak konsisten setelah revisi teks); atau kolom opsional `dass.results.instrument_version_id` (migration kecil di skema DASS, milik lane DASS/F2).

## Ketergantungan lintas-lane (penting)

- Input #4 dan #5 dibaca dari `instrument_versions` (jsonb). **Temuan jsonb/checksum** (lihat `tests/Postgres/SignedReportDatasetRlsTest.php`) berlaku juga di sini: checksum atas teks mentah tidak bisa diverifikasi ulang di PostgreSQL. Aturan integritas baru (misalnya checksum atas bentuk kanonik) harus diputuskan dulu, **sebelum** F6 membaca label/teks dari tabel itu. Kalau tidak, di PostgreSQL keduanya akan fail-closed sama seperti IQ_CATEGORY.
- #3 dan prasyarat SIPP (#2) adalah perubahan di lane F5 (DeepSeek).
- Form SIPP (#2) di lane F7 (Codex).

## Urutan usulan

1. Keputusan user/psikolog: format nomor (#1a–c), rationale (#3a–b), 18 label resmi (#4), follow-up Normal/Ringan (#5a).
2. Keputusan Lead/owner F2: aturan checksum jsonb.
3. Migration `report_documents` + `admins.sipp_number` (satu pemilik migration, setelah disetujui).
4. F5: field rationale + prasyarat SIPP. F7: form SIPP. F0 extract: label aspek.
5. F6: ganti `UnavailableReportSupplementalData` dengan implementasi nyata, lalu catat dokumen terbit di `report_documents`.
