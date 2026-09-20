# Proposal skema: `report_documents` (+ kolom SILP/STR)

Status: **PROPOSAL untuk review Lead. Belum ada migration, dan tidak akan dibuat sebelum Lead memberi giliran** (aturan proyek: satu pemilik migration pada satu waktu; proposal proctoring lane Codex juga sedang antre).
Dari: sesi GLM-channel. Tanggal: 2026-09-20.
Dasar: SPEC §11 entitas `Laporan(no_laporan, status, hash, psikolog_id, versi)`; Template HPP v2.3 Bagian I.A (field "Nomor Laporan") dan I.C (identitas psikolog); keputusan user 2026-09-20.
Pola yang ditiru: `2026_09_17_000200_create_report_signing_snapshots.php`.

## 1. Masalah yang diselesaikan

1. HPP mencetak **Nomor Laporan**, tetapi nomor itu belum ada sumbernya.
2. PDF yang sudah terbit **tidak tercatat di mana pun**. `ReportDocumentPublisher` menyimpan objek ke disk privat `reports` dengan kunci acak, lalu kuncinya hilang begitu tautan sementara kedaluwarsa. Tidak ada cara mengambil ulang laporan yang sama, dan tidak ada cara memverifikasi keaslian PDF yang beredar.
3. Identitas psikolog (SILP/STR) belum tersimpan.

## 2. Tabel `report_documents`

Satu baris = satu PDF yang benar-benar diterbitkan.

| Kolom | Tipe | Null | Catatan |
|---|---|---|---|
| `id` | ulid | tidak | PK, pola sama dengan `report_signing_snapshots` |
| `assessment_case_id` | bigint | tidak | FK → `assessment_cases`, `restrictOnDelete` |
| `signing_snapshot_id` | ulid | tidak | FK → `report_signing_snapshots`. Dokumen selalu terikat pada snapshot SIGNED |
| `document_type` | varchar(16) | tidak | CHECK `IN ('hpp','internal')` |
| `report_number` | varchar(32) | tidak | Format `HPP/YYYY/MM/NNNN` |
| `report_version` | int unsigned | tidak | Versi laporan bagi penerima. Disalin dari `report_signing_snapshots.version`. Naik **hanya** saat re-sign |
| `render_seq` | int unsigned | tidak | Nomor urut pembuatan file untuk (snapshot, document_type) yang sama, mulai 1. Naik **hanya** bila file benar-benar dirender ulang |
| `object_key` | varchar(160) | tidak | Kunci acak dari `ReportDocumentPublisher`, tidak mengandung PII |
| `sha256` | char(64) | tidak | Hash isi PDF (SPEC "hash"), untuk verifikasi keaslian |
| `size_bytes` | int unsigned | tidak | |
| `psychologist_admin_id` | bigint | tidak | FK → `admins`, `restrictOnDelete` |
| `psychologist_name_snapshot` | varchar(160) | tidak | Disalin saat terbit |
| `psychologist_silp_snapshot` | varchar(64) | tidak | Disalin saat terbit |
| `psychologist_str_snapshot` | varchar(64) | ya | Disalin saat terbit; null bila akun belum mengisi |
| `facility_name_snapshot` | varchar(160) | tidak | Disalin dari konfigurasi penerbit |
| `generated_at` | timestamptz(6) | tidak | |
| `created_at` | timestamptz(6) | tidak | |

**Kenapa disalin (snapshot):** laporan yang sudah terbit harus tetap terbaca sama persis bertahun-tahun kemudian. Kalau psikolog mengganti nomor SILP atau nama fasilitas berubah, laporan lama tidak boleh ikut berubah. Ini sejalan dengan G8 (standar berversi, tidak berlaku surut).

### Index & unique

| Nama | Kolom | Tujuan |
|---|---|---|
| `report_documents_number_unique` | `(document_type, report_number, report_version, render_seq)` | Cegah nomor+versi dipakai snapshot lain pada render_seq yang sama |
| `report_documents_render_unique` | `(signing_snapshot_id, document_type, render_seq)` | Tiap render punya barisnya sendiri; file yang berlaku = `render_seq` tertinggi |
| `report_documents_case_idx` | `(assessment_case_id, document_type, report_version)` | Pencarian dokumen terbaru per kasus |
| `report_documents_object_key_unique` | `(object_key)` | Kunci objek tidak pernah dipakai ulang |

**Koreksi ditemukan lewat test (2026-09-20), bukan hanya ditinjau ulang di atas kertas:** rancangan awal `report_documents_number_unique` tanpa `render_seq` ternyata salah — ia menolak render ulang yang SAH (mis. objek hilang dari storage lalu dirender ulang), karena render ulang pada snapshot yang sama secara sengaja memakai `report_number`+`report_version` yang SAMA dengan `render_seq` yang berbeda. Test `ReportDocumentIssuerTest::test_re_renders_with_a_new_render_seq_when_the_object_is_missing_from_storage` menangkap ini via `UNIQUE constraint failed`. Sudah diperbaiki dengan menambahkan `render_seq` ke unique tersebut.

**Idempotensi ditegakkan di aplikasi, bukan oleh unique constraint.** Saat psikolog menekan "Buat PDF": bila sudah ada baris untuk (snapshot, document_type) **dan** objeknya masih ada di storage, kembalikan baris itu dan terbitkan tautan baru dari `object_key` yang sama — jangan render ulang, jangan menambah baris. Render ulang (baris baru, `render_seq` naik) hanya terjadi bila file hilang dari storage atau template laporan berubah.

## 3. Penerbitan nomor

- Tabel urutan terpisah, pola sama dengan `test_number_sequences` + `MonthlyTestNumberIssuer`: kunci `(document_type, year, month)` → `last_number`, diambil dengan penguncian baris agar aman saat bersamaan.
- Nomor diterbitkan **sekali per kasus**, pada PDF pertama.
- **Re-sign / REVISED:** `report_number` **tetap**, `report_version` naik (disalin dari versi snapshot tanda tangan yang baru). Disetujui Lead secara prinsip; alasannya nomor laporan adalah identitas dokumen bagi penerima di Jepang, sehingga nomor baru pada revisi akan terbaca sebagai dua laporan berbeda untuk orang yang sama.
- Lembar Kerja Internal memakai `report_number` yang sama dengan HPP-nya, dibedakan `document_type`.

## 4. Append-only murni (revisi setelah review Lead)

**Keputusan Lead 2026-09-20: append-only murni, tanpa pengecualian kolom.** UPDATE dan DELETE ditolak trigger, persis pola `report_signing_snapshots`.

Usulan awal saya (A: append-only tapi version naik tiap render; B: melonggarkan UPDATE untuk kolom file) **dua-duanya keliru**, dan alasannya penting untuk dicatat:

- **A tidak konsisten dengan unique-nya sendiri.** Dengan unique `(signing_snapshot_id, document_type)`, pembuatan ulang tidak mungkin menambah baris, sehingga A justru memaksa UPDATE. Jadi A bukan append-only.
- **B melonggarkan integritas demi kasus yang tidak ada.** Tautan sementara yang kedaluwarsa **tidak** memerlukan render ulang: `object_key` tetap, dan tautan bertanda tangan baru bisa diterbitkan on-demand dari objek yang sama. Render ulang hanya perlu bila file hilang dari storage atau template berubah.

Akar masalahnya: satu kolom `version` dipakai untuk dua makna. Setelah dipisah menjadi `report_version` (versi laporan bagi penerima, naik hanya saat re-sign) dan `render_seq` (urutan pembuatan file, naik hanya saat render ulang), append-only murni tidak lagi kehilangan makna.

**Konsekuensi untuk kode F6:** `ReportDocumentPublisher` sekarang menggabungkan "simpan objek" dan "terbitkan tautan". Perlu dipisah, supaya tautan baru bisa diterbitkan untuk `object_key` yang sudah ada tanpa menyentuh storage. Ini pekerjaan lane F6 dan dikerjakan bersama migration-nya.

## 5. RLS & hak akses

Mengikuti pola `report_signing_snapshots`:

```
REVOKE ALL PRIVILEGES ON report_documents FROM psikotes_runtime;
GRANT SELECT, INSERT ON report_documents TO psikotes_runtime;   -- tanpa UPDATE/DELETE: append-only murni
ALTER TABLE report_documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE report_documents FORCE ROW LEVEL SECURITY;
CREATE POLICY report_documents_service_select ON report_documents
    FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
CREATE POLICY report_documents_service_insert ON report_documents
    FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
```

- Basis datanya **hanya mengenal peran `service`**, sama seperti snapshot tanda tangan. Pembedaan peran manusia terjadi di lapisan aplikasi, bukan RLS, karena seluruh akses melewati `RlsContextRunner::runAsService()`.
- Pembatasan per peran di aplikasi: `document_type = 'hpp'` boleh diakses psikolog (dan peserta, bila jalur peserta dibuka nanti); `document_type = 'internal'` **hanya psikolog** (CLAUDE.md: Lembar Kerja Internal hanya psikolog+peserta, tidak pernah admin/LPK/kumiai).
- Baris ini **tidak memuat PII**: tidak ada nama peserta, nomor tes, maupun isi laporan. Hanya identitas psikolog penanda tangan, yang memang tercetak di laporan.

### Contract check (PostgreSQL)

```
ALTER TABLE report_documents
    ADD CONSTRAINT report_documents_contract_check CHECK (
        document_type IN ('hpp','internal')   -- kedua nilai diizinkan sejak awal, walau kini hanya 'hpp' yang ditulis
        AND report_number ~ '^(HPP)/[0-9]{4}/[0-9]{2}/[0-9]{4}$'
        AND sha256 ~ '^[a-f0-9]{64}$'
        AND size_bytes > 0
        AND report_version >= 1
        AND render_seq >= 1
    );
```
Plus trigger yang memastikan `signing_snapshot_id` menunjuk snapshot ber-`state = 'SIGNED'` milik `assessment_case_id` yang sama (pola `guard_*`), sehingga PDF tidak mungkin tercatat untuk laporan yang belum ditandatangani (G5).

## 6. Kolom pada `admins`

| Kolom | Tipe | Catatan |
|---|---|---|
| `silp_number` | varchar(64) null | Wajib terisi sebelum psikolog boleh menandatangani (ditegakkan di `ReportSigningPrerequisitePolicy`, lane DeepSeek) |
| `str_number` | varchar(64) null | Dicetak di HPP bila ada |

**Tidak ada kolom masa berlaku dan tidak ada validasi kedaluwarsa.** Ini **keputusan user 2026-09-20** ("tidak perlu cek masa berlaku"), bukan kelalaian — jangan "diperbaiki" belakangan tanpa menanyakan ulang ke user.

Nama fasilitas dan alamatnya adalah identitas penerbit, sama untuk semua laporan, jadi lebih tepat sebagai konfigurasi penerbit daripada kolom per-admin. Nilainya sudah pasti (Konfirmasi Akhir butir 7): Unit Layanan Psikologi — PT Online Career Mentor, Salatiga, Jawa Tengah, Indonesia.

## 7. Dampak pada kode F6 setelah disetujui

1. `ReportGeneration` memanggil penerbit nomor, menulis `report_documents`, lalu memakai nomor itu saat render.
2. `ReportSupplementalData::reportNumber()` dan `psychologistSippNumber()` (ganti nama jadi SILP) dipenuhi oleh implementasi nyata; `UnavailableReportSupplementalData` tinggal dipakai di test.
3. Gap `REPORT_NUMBER_UNAVAILABLE` dan `PSYCHOLOGIST_SIPP_UNAVAILABLE` hilang dari daftar pemblokir.
4. Test baru: idempotensi (dua kali generate → satu baris, nomor sama), nomor berurutan per bulan, re-sign mempertahankan nomor, dan bukti RLS di harness PostgreSQL.

## 8. Yang masih perlu keputusan

Tidak ada lagi. Ketiga butir sebelumnya sudah diputuskan Lead 2026-09-20:

1. **Append-only murni**, dengan `report_version` dan `render_seq` dipisah (§4).
2. **`document_type` mengizinkan `hpp` dan `internal` sejak awal** pada check constraint, meski untuk sekarang hanya `hpp` yang ditulis — menambah nilai belakangan berarti migration lagi tanpa alasan.
3. **Giliran migration:** `report_documents` lebih dulu, proposal proctoring lane Codex menyusul. Migration tetap tidak dibuat sampai pembekuan branch dicabut.

## 9. Definition of Done untuk increment migration (disetujui Lead 2026-09-20)

Skema di atas sah untuk dijadikan migration, **setelah pembekuan branch dicabut dan verdict verifikasi keluar**. Increment tersebut wajib memuat:

1. **Pemisahan `ReportDocumentPublisher`** menjadi dua tanggung jawab — "simpan objek" dan "terbitkan tautan" — dikerjakan **di dalam increment yang sama**, bukan sesudahnya. Tanpa itu, perilaku idempoten pada §2 (objek masih ada → kembalikan baris yang ada, terbitkan tautan baru, tanpa render dan tanpa baris baru) tidak bisa dibuktikan.
   - Test wajib: **render ulang TIDAK terjadi** saat objek masih ada di storage. Bukti konkretnya, misalnya, renderer PDF tidak dipanggil sama sekali dan `object_key` yang dikembalikan sama persis.
2. **Test penegakan trigger lewat insert nyata di PostgreSQL** (harness disposable, bukan hanya SQLite), mengikuti pola tabel ledger lane lain:
   - `UPDATE` ditolak;
   - `DELETE` ditolak;
   - `INSERT` yang menunjuk snapshot **non-SIGNED** ditolak (penegak G5 di level basis data).

Catatan pelaksanaan: bukti PostgreSQL memakai `tools/testing/run-org-postgres.ps1`, sama seperti `tests/Postgres/SignedReportDatasetRlsTest.php` yang sudah ada.
