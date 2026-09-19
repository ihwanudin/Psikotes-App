# Inventaris Otoritas Sumber — dokumen psikolog di `D:\LSI\Psikotes\PSIKOTEST LSI`

Status: **dokumentasi saja.** Tidak ada kode/migrasi/data instrumen yang diubah oleh dokumen ini.
Dari: sesi GLM-channel, atas permintaan Lead. Tanggal: 2026-09-20.
Ruang lingkup: seluruh lane (bukan hanya F6).

Hierarki yang dipakai untuk memutuskan konflik (CLAUDE.md):
`SPEC.md` menang sebagai kebenaran fungsional; `Spesifikasi_Tim_Teknis_HPP_v2.3.docx` dari psikolog adalah rujukan otoritatif untuk skoring/laporan/etika bila ada keraguan; `SCORING_ALGORITHM.md` kanonik untuk rumus.
Bila dua dokumen psikolog bertentangan, **yang lebih baru menang**, kecuali SPEC menyatakan lain.

## 1. Daftar dokumen (terbaru lebih dulu)

Tanggal = tanggal berkas; tanggal/versi dalam kurung = yang tertulis di dalam dokumen.

| Tgl | Dokumen | Peran |
|---|---|---|
| 2026-08-22 | `PSIKOTEST\Konfirm Akhir\Konfirmasi_Akhir_Terisi.docx` (v2.3) | **Otoritas terbaru.** Jawaban final psikolog atas butir tersisa: Hanker=b×50, larangan pengacakan, provenans norma IST, sumber narasi DASS, identitas fasilitas, ambang proctoring ikut Spesifikasi §9, rencana validasi norma Kraepelin digital |
| 2026-08-22 | `…\Lampiran_Butir4_Teks_Item_DASS21_1.docx` | Teks 21 item DASS-21 versi Indonesia |
| 2026-08-22 | `…\Lampiran_Butir5_Golden_Test_Kraepelin_SMA-SMK_1.docx` (v2.3) | Golden test Kraepelin grup SMA/SMK |
| 2026-08-18 | `Update DASS\Spesifikasi Tim Teknis - Engineer Sistem_Psikotes.docx` (18 Agu 2026, "Versi 2.2 menggantikan v2.1", diberi label v2.3) | **Blueprint utama**: skala 1–5, Grey Area, G1–G9, DASS jalur terpisah, perakitan narasi, tata kelola data, proctoring |
| 2026-08-18 | `Update DASS\Template Laporan HPP Psikotes.docx` | **Format HPP v2.3 yang berlaku** (skala 1–5, 18 aspek ID/EN/JP, DASS, rekomendasi, identitas psikolog, Bagian V) |
| 2026-08-18 | `Update DASS\Bank Narasi Formula HPP Psikotes.xlsx` | Sumber `reporting.json` + `dass21.json` (sheet 8 = narasi DASS) |
| 2026-08-18 | `Update DASS\Contoh Laporan HPP Psikotes.pdf` | Contoh HPP terisi |
| 2026-08-18 | `Update DASS\Pertanyaan_DASS21_untuk_Psikolog (1).docx` | Daftar tanya DASS |
| 2026-08-16 | `PSIKOTEST\DASS 21.docx` | Materi DASS-21 |
| 2026-08-07 | `PSIKOTEST\Skoring\*` v1.1 (Format HPP, Formula Drafting, Tabel Lookup, Konfirmasi+Lampiran) | **Model lama** (skala 1–10, ambang, knockout). Masih berguna untuk teks/struktur |
| 2026-08-06 | `PSIKOTEST\Tabel_Lookup_Skoring_Psikotes_v1.xlsx`, `Konfirmasi Jawaban…docx` (4 Agu 2026, v1.0) | Pendahulu v1.1 |
| 2026-07-09 | `PSIKOTEST\Manual Skoring HPP.docx` | **Manual skoring manual-hitung**: konversi kategori → angka 1–10, rata-rata antar sumber. Model lama |
| 2026-07-07 … 2026-07-18 | Master Kamus IST/PAPI, Master Norma Kraepelin, RMIB Master Formula, Alat Tes Kraepelin, Formula Drafting, Format Mentahan, Buku Panduan Skoring | Data instrumen mentah + format awal |
| 2026-06-11 | `Penilaian IST.xlsx` | Tertua |

## 2. Topik diatur dokumen mana

| Topik | Otoritas | Catatan |
|---|---|---|
| Skala laporan | Spesifikasi v2.3 + Template v2.3 → **1–5** | Manual 2026-07 dan v1.1 memakai 1–10 (lihat konflik K1) |
| Model kelayakan | Spesifikasi v2.3 → **Grey Area** terhadap standar bidang, G1–G9, aspek kritis A1/B2/C4/C5 | v1.1 memakai ambang+knockout (K2) |
| Pemetaan skor 1–10 → level 1–5 | Spesifikasi v2.3 §4 (baris 615) → **1–2→1, 3–4→2, 5–6→3, 7–8→4, 9–10→5** | Sudah diputuskan; bukan pertanyaan terbuka (K3) |
| Pita narasi | Spesifikasi v2.3 §5.1 → POOR/MARGINAL/AVERAGE/GOOD/EXCELLENT = kolom HPP 1/2/3/4/5, label ID Rendah–Tinggi, nilai jangkar 1/3/5/7/9 | Teks narasinya dari Formula Drafting + Bank Narasi |
| Label & definisi 18 aspek | Template v2.3 Bagian II.A–D (ID + EN + JP + definisi operasional ID/JP) | Selaras SPEC baris 132 |
| Perakitan INTEGRATION | Spesifikasi v2.3 §8 + `Lampiran_Pendukung_Balasan.docx` (kerangka 5 paragraf, aturan pemadatan, konektor tertutup, 350–450 kata, subjek "Klien") | Draf otomatis lalu disunting psikolog |
| Kalimat penutup / alasan rekomendasi | Template v2.3 Bagian IV (teks baku per label ID+JP) + `Konfirmasi_dan_Permintaan_Manual_HPP` butir 5 (draf-lalu-sunting, disimpan sebagai teks yang dapat diubah, bukan di kode) | |
| Format HPP & isi laporan | Template v2.3 | Termasuk Bagian V (batasan/kerahasiaan) + dasar hukum |
| DASS-21 | Spesifikasi v2.3 §7 + Template v2.3 Bagian III + Bank Narasi sheet 8 + Lampiran Butir 4 | Subskala tidak dicetak di HPP; tindak lanjut: kat≥4 rujukan, =3 pemantauan, selain itu **tidak ada** |
| Identitas psikolog & fasilitas | Template v2.3 Bagian I.C + Konfirmasi Akhir butir 7 | Nama, **SILP**, **STR**, fasilitas, alamat |
| Identitas peserta | Template v2.3 Bagian I.A | Termasuk field yang belum kita simpan (lihat §5) |
| Norma & kunci instrumen | Master Kamus/Norma + Bank Narasi, lewat `tools/extract` | Konfirmasi Akhir butir 3: norma IST = tabel internal unit layanan |
| Proctoring & validitas | Spesifikasi v2.3 §9 | Konfirmasi Akhir butir 8: pakai ambang §9 yang lebih ketat, **bukan** usulan longgar 60 dtk/3×/8× |
| Kraepelin | Konfirmasi Akhir butir 1 → **Hanker = b×50**; Lampiran Butir 5 golden test SMA/SMK | Sudah ada di CLAUDE.md |
| Pengacakan soal/opsi | Konfirmasi Akhir butir 2 → **dilarang seluruh baterai** | Sudah ada di CLAUDE.md |

## 3. Konflik yang ditemukan, beserta pemenangnya

**K1 · Skala 1–10 vs 1–5.** `Manual Skoring HPP` (2026-07-09) dan seluruh berkas v1.1 (2026-08-07) memakai 1–10; Spesifikasi + Template v2.3 (2026-08-18) memakai 1–5.
→ **Menang: v2.3 (1–5).** Lebih baru, dan sejalan SPEC + CLAUDE.md ("Skala laporan 1–5, bukan 1–10"). Angka 1–10 hanya hidup sebagai lapisan antara di dalam perhitungan master, lalu dipadatkan ke 1–5.

**K2 · Ambang + knockout vs Grey Area.** `Konfirmasi_dan_Permintaan_Manual_HPP` v1.1 menetapkan ambang resmi (Disarankan ≥7,00; Dipertimbangkan 5,00–6,99; Tidak Disarankan <5,00) dan aturan knockout skor 1, dengan Job Interest dikecualikan. Spesifikasi v2.3 §6 menggantinya: kelayakan = perbandingan level tiap aspek terhadap standar bidang (Grey Area), bukan rata-rata berbobot/ambang.
→ **Menang: v2.3 (Grey Area).** Lebih baru, dan CLAUDE.md melarang model knockout+ambang secara eksplisit. Spesifikasi v2.3 sendiri menyatakan model ini menggantikan Skor Komposit Kelayakan v2.0.

**K3 · "Pemetaan pita narasi ke 1–5 belum diputuskan" — ternyata SUDAH.** Saya sempat melaporkan ini sebagai keputusan terbuka (ke Lead dan user, 2026-09-20) berdasarkan Lampiran C v1.1 yang berbasis 1–10.
→ **Koreksi: sudah diputuskan** di Spesifikasi v2.3 §4 (1–2→1 … 9–10→5) dan §5.1 (band → kolom HPP 1–5). Tidak perlu keputusan psikolog untuk ini.

**K4 · Jumlah aspek: 26 vs 18.** Sheet 12/13 v1.1 sempat menghitung 26 aspek.
→ **Menang: 18 sub-aspek** (Konfirmasi v1.1 butir 4 + Spesifikasi v2.3 + SPEC). Skor 26 alat, bila ditampilkan, hanya sebagai lampiran skor rinci tanpa narasi.

**K5 · Bobot antar sumber.** Manual 2026-07 menyuruh "rata-ratakan"; sheet 12 sempat memberi bobot 2 pada General Intelligence.
→ **Menang: bobot setara (semua 1).** Konfirmasi v1.1 butir 1 + Spesifikasi v2.3 §5 ("jangan menambahkan bobot sendiri di kode") + CLAUDE.md.

**K6 · Nomor laporan dicetak atau tidak.** Format HPP v1.1 hanya punya "Nomor Test"; Template v2.3 punya "Nomor Laporan / 報告書番号" di sampul dan Bagian I.A.
→ **Menang: v2.3, nomor laporan dicetak.** (Ini juga mengoreksi dugaan saya sebelumnya bahwa nomor laporan tidak perlu.)

**K7 · Istilah lisensi psikolog: SIPP vs SILP.** Dokumen resmi memakai **SILP** (SILP-D8A35113BB4D) dan **STR**.
→ **Menang: SILP + STR.** Istilah "SIPP" di proposal F6 sebelumnya salah dan sudah dikoreksi.

**K8 · Ambang proctoring.** Usulan tim pengembang (kamera mati 60 dtk kumulatif / 3× / 8×) vs Spesifikasi v2.3 §9.
→ **Menang: Spesifikasi v2.3 §9** (lebih ketat), sesuai jawaban psikolog di Konfirmasi Akhir butir 8. Lane proctoring perlu memverifikasi implementasinya memakai angka §9.

## 4. Keputusan yang masih harus diambil psikolog/user

1. **Format nomor laporan** (mis. `HPP/2026/09/0001`), dan perilaku saat laporan ditandatangani ulang: nomor sama dengan versi naik, atau nomor baru.
2. **Apakah masa berlaku SILP/STR dicek** saat tanda tangan (tolak bila kedaluwarsa).
3. **Redaksi kalimat "Tidak tersedia"** untuk bagian DASS ketika peserta tidak punya hasil skrining (implementasi sudah jalan, tinggal persetujuan redaksi).
4. **Apakah kalimat penutup per label** dipakai apa adanya dari Template v2.3, atau psikolog ingin redaksi lain sebagai draf awal.
5. **Nomor ID CPMI/SISKOP2MI dan level bahasa Jepang**: wajib diisi atau opsional pada pendaftaran (memengaruhi apakah HPP boleh terbit tanpa keduanya).
6. **Validasi norma Kraepelin digital**: Konfirmasi Akhir butir 9 menargetkan ±200 sesi V1 sebelum cutoff ditinjau. Perlu pemilik dan pemicu peninjauannya.

## 5. Temuan untuk lane lain (jangan dikerjakan dari lane F6)

- **Lane registrasi/F2** — Template v2.3 Bagian I.A meminta field yang belum tersimpan: **Nomor ID CPMI/SISKOP2MI**, **tempat lahir** (kita hanya simpan tanggal lahir), **level bahasa Jepang**, dan **Program yang Dituju (TITP / SSW / lainnya)**.
- **Lane F4/narasi (DeepSeek)** — `Lampiran_Pendukung_Balasan.docx` Lampiran A (teks PURPOSE baku) dan Lampiran B (kerangka 5 paragraf, aturan pemadatan, konektor tertutup, target 350–450 kata, subjek "Klien") belum tercermin di kode perakitan narasi. Spesifikasi v2.3 §8 adalah rujukan utamanya.
- **Lane proctoring (Codex)** — ambang validitas wajib mengikuti Spesifikasi v2.3 §9, bukan usulan longgar; lihat K8.
- **Lane F2/scoring** — Konfirmasi Akhir butir 3 mencatat provenans norma IST (tabel internal unit layanan, diturunkan dari Master Kamus Tes IST). Berguna untuk authority pack Codex, termasuk pertanyaan metode hash KRA-A7/RMIB-A7.
- **Lane F6 (lane ini)** — Bagian V Template v2.3 (batasan, kerahasiaan, ketentuan penggunaan + dasar hukum UU 23/2022, UU 18/2017, UU 27/2022) belum ada di template HPP F6. Lead sudah menyatakan ini boleh dikerjakan di lane F6 karena merupakan isi laporan.
