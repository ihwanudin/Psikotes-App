# Keputusan pemilik proyek — 2026-09-21

Dicatat oleh Lead dari jawaban langsung pemilik proyek. Dokumen ini kanonik
untuk butir-butir di bawah; kalau ada dokumen lain yang bertentangan,
laporkan konfliknya, jangan diam-diam memilih.

Tiga butir (3, 5a-c, 5d) diserahkan pemilik proyek kepada "best practice".
Untuk butir itu Lead menuliskan angka dan bentuk konkretnya di bawah, supaya
bisa dikoreksi. **Butir yang ditandai PERLU KONFIRMASI HUKUM belum boleh
dianggap final untuk produksi.**

## 1. Sumber angka Kraepelin — SELESAI

Lembar soal resmi yang biasa dipakai psikolog adalah berkas PDF yang
dikirim pemilik proyek: `Soal _ Ljk Kraeplin (1).pdf`.

Angkanya **TETAP dan sama untuk semua peserta** (lihat juga `CLAUDE.md`,
diperbarui 2026-09-21). Administrasi: 50 kolom, 27 baris jawaban per kolom,
15 detik per kolom, jumlahkan dari bawah ke atas, tulis digit terakhir,
pindah kolom otomatis saat waktu habis.

**Konsekuensi teknis:** angka lembar itu adalah data instrumen. Masuknya
hanya lewat `tools/extract/` + review, tidak boleh ditulis tangan ke kode.
Ekstraksi dari PDF berisiko salah baca digit; satu digit salah berarti skor
salah untuk semua peserta. Karena itu ekstraksi WAJIB disertai verifikasi
ulang independen (bandingkan hasil ekstraksi terhadap lembar, kolom per
kolom) sebelum dipakai.

## 2. File IST yang otoritatif — SELESAI

Keputusan: **pakai berkas terbaru.**

| Berkas | Diubah | SHA-256 (16 pertama) | Status |
|---|---|---|---|
| `PSIKOTEST LSI\PSIKOTEST\Master Kamus Tes IST.xlsx` | 2026-07-10 08:36:53 | `23CEADF36DB46A08` | **OTORITATIF** |
| `PSIKOTEST LSI\Master Kamus Tes IST.xlsx` | 2026-07-07 17:51:55 | `9252223C6A52498A` | bukan sumber |

Berkas otoritatif ini juga yang selama ini dikutip authority pack sebagai
bukti timing. Berkas kedua tidak boleh dipakai sebagai sumber ekstraksi.

## 3. Backup: kustodi kunci dan lokasi penyimpanan — SELESAI

**Dikoreksi pemilik proyek pada hari yang sama.** Versi pertama bagian ini
berisi usulan "best practice" Lead (layanan pengelola kunci dengan dua
kustodi bernama, dan wilayah Indonesia yang ditandai perlu konfirmasi
hukum). Pemilik proyek kemudian memutuskan lain, dan keputusan itulah yang
berlaku:

**Kustodi kunci enkripsi: dipegang pemilik proyek sendiri** — satu kustodi.
Model dua kustodi TIDAK dipakai.

**Penyimpanan backup luar-host: dikelola pemilik proyek sendiri**, dan
**mengikuti aturan yang berlaku** (termasuk UU 27/2022). Butir ini **tidak
diperlakukan sebagai penghambat produksi** — tanda "perlu konfirmasi hukum"
yang sebelumnya ada di sini dicabut.

Rekomendasi Lead yang tetap dicatat, bukan syarat: simpan **salinan
pemulihan kunci yang tersegel** di tempat terpisah (mis. amplop tertutup di
brankas, atau kode pemulihan tercetak). Tujuannya bukan memberi akses ke
orang lain, melainkan memastikan kunci tidak hilang bersama satu perangkat
— kunci yang hilang membuat seluruh backup terenkripsi tidak bisa
dipulihkan.

**Batas untuk semua sesi dan lane, tanpa pengecualian:** tidak ada sesi
mana pun yang memegang kunci enkripsi atau kredensial penyimpanan sungguhan.
Yang dibangun adalah tooling-nya; nilai rahasianya dimasukkan pemilik
proyek sendiri ke `.env` di mesinnya dan ke secret CI. Semua test dan
gladi resik memakai kunci serta kredensial sintetis sekali pakai. Tooling
membaca rahasia dari environment atau secret store, tidak pernah dari
berkas di repo, dan tidak pernah mencetaknya ke log atau keluaran test.

Angka lain yang sudah diputuskan sebelumnya tetap berlaku: RPO 24 jam,
RTO 8 jam, retensi 30 hari, enkripsi wajib (`tasks/handoffs/f9/`).

## 4. Siapa yang boleh menerbitkan laporan (G5) — SELESAI

Keputusan: **psikolog ATAU admin boleh menerbitkan.**

Batas yang mengikat, karena G5 tidak berubah:
- Penerbitan hanya mungkin untuk laporan yang SUDAH ditandatangani psikolog.
  Tidak ada jalur dari draf langsung ke terbit.
- Menandatangani tetap HANYA psikolog (keputusan 2026-09-20). Admin boleh
  menekan "terbitkan", tidak boleh menandatangani.
- Isi laporan sudah terkunci oleh tanda tangan; penerbitan tidak mengubah
  isi, hanya mengirimkannya.
- Siapa yang menerbitkan dan kapan harus tercatat, terpisah dari siapa yang
  menandatangani.

## 5. Retensi

Diserahkan ke best practice. Angka yang Lead tetapkan, sekaligus
menyelesaikan pertentangan antar dokumen yang ada:

| Kategori | Retensi | Dasar |
|---|---|---|
| a. Media proctoring (foto DAN video, diperlakukan sama) | **90 hari** | SPEC §12 dan SECURITY sudah menyebut 90 hari; `PRIVACY_POLICY.md` yang menyebut foto 6 bulan DIKOREKSI mengikuti ini |
| b. Jejak audit (tanpa PII) | **5 tahun** | SPEC dan SECURITY sudah sepakat |
| c. Log aplikasi | **2 tahun** | Berbeda dari jejak audit; `PRIVACY_POLICY.md` "log 2 tahun" merujuk ini |
| d. Foto dokumen identitas + selfie awal | **90 hari setelah laporan terbit**, lalu dihapus; catatan verifikasi (hash, waktu, pemeriksa) disimpan 5 tahun | Minimalisasi data: gambar identitas tidak perlu disimpan setelah fungsinya selesai. **PERLU KONFIRMASI HUKUM** |
| Data psikotes & laporan | 5 tahun | sudah diputuskan sebelumnya |
| DASS / skrining | 2 tahun | sudah diputuskan sebelumnya |

Butir (a) dan (c) mengharuskan `PRIVACY_POLICY.md` disunting agar tidak lagi
bertentangan. Itu pekerjaan tersendiri, bukan diselipkan ke lane lain.

## 6. IST subtes verbal — SELESAI

Dokumen internal menulis "Verbal (3)" tetapi mendaftar empat kode
(SE, WA, AN, GE). Keputusan: **ikuti standar IST**, yaitu **empat** subtes
verbal. Tulisan "(3)" diperlakukan sebagai salah ketik, bukan aturan.

## 7. Peninjauan cutoff Kraepelin — SELESAI

Keputusan: **sistem yang memantau hitungannya**, otomatis.

Artinya: sistem menghitung jumlah sesi Kraepelin yang sudah selesai dan
memberi tahu psikolog saat ambang peninjauan tercapai (sekitar 200 sesi
V1). Yang otomatis adalah PEMANTAUAN dan PENGINGATnya. Penetapan angka
cutoff yang baru tetap keputusan psikolog, diterbitkan sebagai versi tabel
norma baru — bukan dihitung ulang sendiri oleh sistem.

## 8. Lisensi instrumen — DITUTUP

Pemilik proyek menyatakan psikolognya sudah tersertifikasi dan butir ini
tidak perlu dibahas lagi. Dicatat sekali di sini; jangan diangkat ulang.

## 9. Admin cabang dan pembuatan laporan — SELESAI

Keputusan: **`branch_admin` TIDAK boleh membuat/menghasilkan laporan**
(`GenerateReports`), termasuk untuk peserta di cabangnya sendiri.

Pembagian yang berlaku sekarang:

| Aksi | Siapa |
|---|---|
| Menandatangani laporan (`ReviewReports`) | Hanya psikolog (butir 4, keputusan 2026-09-20) |
| Membuat/menerbitkan laporan yang sudah ditandatangani (`GenerateReports`) | Psikolog atau super_admin |
| `branch_admin` | Tidak keduanya |

Jangan menambahkan `GenerateReports` ke `branch_admin`, baik dengan
pembatasan cabang maupun tanpa, kecuali pemilik proyek memutuskan ulang.

## 10. DASS-21 di lobi peserta — SELESAI

Keputusan: **DASS-21 tetap ditampilkan di daftar "Tes yang tersedia" pada
lobi peserta (`/participant/lobby`), berderet bersama IST, PAPI, RMIB, dan
Kraepelin**, seperti perilaku saat ini.

Yang TIDAK berubah oleh keputusan ini:
- "Alur terisolasi" DASS-21 di ADR-0030 tetap berlaku untuk lapisan
  sesi/command: DASS-21 tidak masuk command start generik dan tetap memakai
  penyimpanan terpisah.
- Pembatasan akses data DASS (`CLAUDE.md`): hasil dan skor DASS hanya boleh
  dilihat psikolog dan peserta, tidak pernah admin/LPK/kumiai. Menampilkan
  nama tes dan status aksesnya kepada peserta itu sendiri tidak melanggar
  pembatasan ini.
- DASS-21 tetap tidak pernah masuk ekspresi zona/label kelayakan (G4).

## 11. Kamera proctoring — WAJIB untuk semua peserta — SELESAI

Keputusan: **kamera wajib hidup untuk SEMUA peserta**, tanpa pengecualian per
cabang. Alasannya dari pemilik proyek: sistem akan menyimpan foto (snapshot)
acak selama tes.

Ini konsisten dengan `SPEC.md` (model perekaman): foto diambil pada selang acak
setiap 12–20 detik sepanjang sesi, ditambah wajib saat mulai dan saat
mengumpulkan. Penanda "wajib kamera per cabang" yang sempat disebut di SPEC
tidak dipakai: satu aturan untuk semua cabang.

Konsekuensi yang mengikat:
- Peserta **tidak bisa memulai tes** tanpa kamera aktif. Status `denied`
  (menolak izin) dan `unavailable` (kamera tidak ada/rusak) sama-sama menahan
  start, dengan pesan yang membedakan keduanya dan memberi petunjuk cara
  mengaktifkannya.
- Proctoring tetap **DETEKSI, bukan CEGAH** (`CLAUDE.md`). Keputusan validitas
  (V1/V2/V3) tetap di psikolog.
- Karena kamera wajib dan foto disimpan, **backend penyimpanan proctoring (F7)
  menjadi syarat go-live**. Layar persetujuan tidak boleh dipasang ke alur
  peserta sungguhan sebelum penyimpanan itu ada.

Belum diputuskan: perilaku bila kamera mati **di tengah tes** (misalnya HP
berpindah aplikasi). Lihat butir terbuka di bawah.

## Butir yang masih terbuka setelah dokumen ini

- Konfirmasi hukum untuk retensi foto identitas (butir 5d). Butir 3
  (penyimpanan backup) sudah diputuskan pemilik proyek dan tidak lagi
  menunggu konfirmasi.
- Pembagian waktu subtes ME sudah diputuskan (180 detik menghafal + 360
  detik menjawab) dan tercatat di `CLAUDE.md`.

- Kamera mati di tengah tes (butir 11): apakah tes dijeda sampai kamera aktif lagi, atau tes berlanjut sementara sistem mencoba menyalakan ulang dan mencatat celahnya untuk psikolog.
