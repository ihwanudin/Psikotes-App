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
| `version` | int unsigned | tidak | 1 untuk terbitan pertama; naik saat re-sign |
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
| `report_documents_number_unique` | `(document_type, report_number, version)` | Satu nomor+versi hanya boleh sekali per jenis dokumen |
| `report_documents_snapshot_type_unique` | `(signing_snapshot_id, document_type)` | **Kunci idempotensi**: satu snapshot hanya menghasilkan satu baris per jenis dokumen |
| `report_documents_case_idx` | `(assessment_case_id, document_type, version)` | Pencarian dokumen terbaru per kasus |
| `report_documents_object_key_unique` | `(object_key)` | Kunci objek tidak pernah dipakai ulang |

**Catatan idempotensi.** `report_documents_snapshot_type_unique` berarti: klik "Buat PDF" dua kali pada snapshot yang sama **tidak** membuat baris baru dan **tidak** membuat nomor baru. Dua pilihan perilaku, saya usulkan (b):
- (a) tolak pembuatan kedua, atau
- (b) **buat ulang PDF-nya** (file baru di storage), lalu `UPDATE` baris tersebut dengan `object_key`/`sha256`/`size_bytes` baru.
Pilihan (b) lebih ramah dipakai: psikolog bisa mengunduh ulang setelah tautan 15 menit kedaluwarsa, tanpa menambah nomor laporan. Konsekuensinya tabel **tidak bisa append-only murni** (lihat §4).

## 3. Penerbitan nomor

- Tabel urutan terpisah, pola sama dengan `test_number_sequences` + `MonthlyTestNumberIssuer`: kunci `(document_type, year, month)` → `last_number`, diambil dengan penguncian baris agar aman saat bersamaan.
- Nomor diterbitkan **sekali per kasus**, pada PDF pertama.
- **Re-sign / REVISED:** `report_number` **tetap**, `version` naik. Disetujui Lead secara prinsip; alasannya nomor laporan adalah identitas dokumen bagi penerima di Jepang, sehingga nomor baru pada revisi akan terbaca sebagai dua laporan berbeda untuk orang yang sama.
- Lembar Kerja Internal memakai `report_number` yang sama dengan HPP-nya, dibedakan `document_type`.

## 4. Append-only atau tidak — perlu keputusan Lead

`report_signing_snapshots` append-only murni (trigger menolak UPDATE dan DELETE). Untuk `report_documents` ada tarik-ulur:

- **Opsi A — append-only murni.** Setiap pembuatan ulang PDF menambah baris baru dengan `version` naik. Sederhana dan paling aman untuk audit, tapi `version` jadi ikut naik karena alasan teknis (tautan kedaluwarsa), bukan karena laporan benar-benar direvisi. Nomor versi kehilangan makna.
- **Opsi B — append-only dengan satu pengecualian sempit (usulan saya).** DELETE selalu ditolak. INSERT bebas. UPDATE **hanya** boleh mengubah `object_key`, `sha256`, `size_bytes`, `generated_at`, dan hanya bila `signing_snapshot_id`, `report_number`, `version`, serta seluruh kolom snapshot psikolog tidak berubah. Ditegakkan trigger `BEFORE UPDATE`, sama gayanya dengan `guard_report_signing_snapshot()`.
  Artinya: isi laporan dan identitasnya tidak bisa diam-diam diubah, tetapi file-nya boleh dibuat ulang.

Saya usulkan **B**, tetapi ini keputusan Anda karena menyangkut kekuatan jaminan audit.

## 5. RLS & hak akses

Mengikuti pola `report_signing_snapshots`:

```
REVOKE ALL PRIVILEGES ON report_documents FROM psikotes_runtime;
GRANT SELECT, INSERT ON report_documents TO psikotes_runtime;   -- + UPDATE bila Opsi B
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
        document_type IN ('hpp','internal')
        AND report_number ~ '^(HPP)/[0-9]{4}/[0-9]{2}/[0-9]{4}$'
        AND sha256 ~ '^[a-f0-9]{64}$'
        AND size_bytes > 0
        AND version >= 1
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

1. **Opsi A atau B** pada §4 (append-only murni vs pengecualian sempit untuk pembuatan ulang file).
2. Apakah Lembar Kerja Internal memang ikut dicatat di tabel yang sama sejak awal, atau `document_type` dibatasi `hpp` dulu.
3. Giliran migration: Lead yang menentukan urutannya terhadap proposal proctoring lane Codex.
