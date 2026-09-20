# Keputusan user — target backup & restore F9

**Tanggal:** 2026-09-20. **Sumber:** keputusan user langsung di sesi kanal Codex#2.
**Status:** keputusan tercatat. **Eksekusi belum dimulai** (ditahan sampai PR #24 beres).
**Basis:** `main` `80ade92`.

Dokumen ini mencatat keputusan, bukan hasil. Tidak ada gladi resik yang dijalankan
untuk dokumen ini.

## 1. Keputusan

| Parameter | Nilai | Artinya |
|---|---|---|
| **RPO** — data yang boleh hilang | **~24 jam** | Backup harian sudah cukup |
| **RTO** — lama boleh mati | **~8 jam** | Pemulihan manual terpandu, tanpa mesin siaga |
| **Lokasi** | **Salinan ke luar host** | Backup tidak boleh hanya ada di mesin yang sama dengan database |
| **Retensi** | **30 hari** | |
| **Enkripsi** | **Wajib** | Lihat §3 |

## 2. Konsekuensi langsung: WAL archiving dan PITR TIDAK dibangun

RPO 24 jam berarti dump logis harian memenuhi target. **WAL archiving dan
point-in-time recovery tidak diperlukan** dan tidak boleh dibangun atas dasar dokumen
ini — keduanya hanya relevan bila RPO turun ke hitungan menit.

Konsekuensi yang diterima secara sadar: bila database hilang, pekerjaan peserta sejak
backup terakhir hilang, dan **peserta hari itu harus tes ulang.** Itu berarti waktu
peserta, jadwal LPK, dan kemungkinan penjadwalan ulang dengan pihak penerima di Jepang
— bukan sekadar baris data.

Kalau konsekuensi itu kemudian dinilai tidak bisa diterima, yang berubah adalah RPO,
dan WAL/PITR baru masuk pembicaraan. Itu keputusan baru, bukan perluasan dokumen ini.

## 3. Enkripsi — bukan pilihan bebas, dan yang tersisa adalah kustodi kunci

Backup memuat identitas peserta dan **data DASS**. Di sistem ini akses data DASS sudah
dibatasi hanya untuk psikolog dan peserta. Backup tanpa enkripsi memindahkan data itu
ke tempat yang tidak dijaga pembatasan tersebut, sementara UU No. 27 Tahun 2022 sudah
menjadi dasar hukum yang dikutip template laporan HPP.

Karena itu enkripsi dicatat sebagai **syarat**, bukan opsi.

**Yang belum diputuskan dan masih dibutuhkan sebelum implementasi:**

1. **Siapa memegang kunci enkripsi, dan disimpan di mana.** Kunci yang hilang membuat
   backup setara dengan tidak punya backup. Ini menuntut jawaban yang jelas, termasuk
   siapa yang bisa memulihkan bila pemegang utama tidak tersedia.
2. **Tujuan penyimpanan luar-host yang konkret** — penyedia dan bucket/wilayahnya.
   Pemilihan wilayah punya implikasi pada UU 27/2022 dan perlu disebut eksplisit,
   bukan diasumsikan.

Kedua butir ini **tidak boleh ditebak oleh lane mana pun.**

## 4. Keadaan yang sudah ada — koreksi atas catatan sebelumnya

Catatan sebelumnya di ledger menyatakan pekerjaan F9 backup/restore "perlu dikerjakan
ulang dari nol". **Itu keliru sebagai deskripsi pekerjaannya**, dan sudah dikoreksi
Lead setelah diperiksa ulang.

Yang benar:

- **Harness-nya sudah ada di `main` dan bisa langsung dijalankan.** Diverifikasi lewat
  perbandingan blob antara `main` dan branch lama:

  | Berkas | `main` | branch lama |
  |---|---|---|
  | `tools/testing/run-postgres-backup-restore.ps1` | `f28ae39` | `f28ae39` — sama |
  | `tools/testing/run-f9-session-result-load.ps1` | `8c69381` | `8c69381` — sama |
  | `tools/testing/f9-session-result-load.php` | `446f32b` | `446f32b` — sama |

  Berikut `tools/testing/tests/postgres-backup-restore-contract.ps1` dan kedua dokumen
  handoff tertanggal 2026-09-14.

- **Yang tidak boleh dilakukan adalah me-merge branch lamanya.**
  `codex/f9-backup-restore-rehearsal` tertinggal 189 commit dan
  `codex/f9-load-performance-baseline` tertinggal 184 commit dari `main`; di-diff ke
  `main` keduanya akan tampak menghapus test RLS.

- Gladi resik 2026-09-14 **lulus untuk cakupan lokal terbatas**, dan dokumennya sendiri
  menyatakan batasnya: *"does not prove production backup readiness, encryption,
  off-host/object-storage retention, WAL archiving, PITR, or production RPO/RTO."*
  Batas itu dinyatakan penulisnya sendiri, sehingga tidak ada klaim berlebih yang perlu
  dibongkar lebih dulu.

## 5. Yang tersisa untuk memenuhi keputusan §1

Didaftar sebagai pekerjaan, bukan rencana yang sudah disetujui urutannya. Penjadwalan
milik Lead.

1. **Jalankan ulang gladi resik lokal di atas `main` sekarang.** Murah — harness sudah
   ada. Bukti yang berlaku sekarang berumur enam hari dan berbasis commit lama.
   Tidak butuh keputusan user tambahan.
2. **Enkripsi arsip backup**, setelah kustodi kunci (§3.1) diputuskan.
3. **Salinan ke luar host**, setelah tujuan penyimpanan (§3.2) diputuskan.
4. **Penegakan retensi 30 hari**, termasuk pembuktian bahwa arsip yang kedaluwarsa
   benar-benar terhapus — bukan hanya tidak terdaftar.
5. **Gladi resik pemulihan terhadap target RTO 8 jam**, diukur sebagai waktu nyata dari
   "database hilang" sampai "peserta bisa tes lagi", bukan hanya durasi perintah
   restore.

Butir 1 tidak bergantung pada butir lain dan bisa dikerjakan lebih dulu.
Butir 2 dan 3 **diblokir** oleh keputusan yang belum ada di §3.

## 6. Yang sengaja TIDAK diputuskan di sini

- WAL archiving dan PITR — lihat §2.
- Mesin siaga atau replikasi — RTO 8 jam tidak menuntutnya.
- Backup untuk lingkungan selain produksi.
- Jadwal pelaksanaan. Itu milik Lead.
