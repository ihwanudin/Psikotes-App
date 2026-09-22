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
| d. Foto dokumen identitas + selfie awal | **90 hari setelah laporan terbit**, lalu dihapus; catatan verifikasi (hash, waktu, pemeriksa) disimpan 5 tahun | Minimalisasi data: gambar identitas tidak perlu disimpan setelah fungsinya selesai. Ditetapkan pemilik proyek (butir 15); bukan penghambat produksi |
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

Perilaku bila kamera mati **di tengah tes**: lihat butir 12.

## 12. Kamera mati di tengah tes — SELESAI

Keputusan: **tes BERLANJUT**, dan celahnya **dicatat**.

Bila kamera berhenti di tengah tes (di HP: berpindah aplikasi, layar
terkunci, telepon masuk), tes tidak dijeda dan timer server tidak berhenti.
Sistem mencoba menyalakan ulang kamera, dan rentang waktu tanpa kamera dicatat
sebagai sinyal untuk ditinjau psikolog. Konsisten dengan `CLAUDE.md`:
proctoring adalah DETEKSI, bukan CEGAH, dan keputusan validitas (V1/V2/V3)
ada di psikolog. Deteksi stream mati + upaya aktif ulang sudah ada di
kerangka runner (`useProctoringCamera`); pencatatan celahnya menunggu backend
penyimpanan proctoring (F7).

## 13. Login bawaan starter kit dan tombol "Portal pengelola" — SELESAI

Temuan (lane F2, 2026-09-21): tombol **"Portal pengelola"** di beranda
mengarah ke `/login` milik Fortify (guard `web`, tabel `users` yang selalu
kosong), bukan ke login admin yang sebenarnya di **`/admin/login`** (Filament,
guard `admin`). Sistem login `users` bawaan starter kit (login, reset kata
sandi, verifikasi email, 2FA, passkeys, `/dashboard`, `/settings/*`) aktif
tetapi tidak dipakai apa pun.

Keputusan pemilik proyek:
1. **Arahkan tombol "Portal pengelola" ke `/admin/login`.**
2. **Hapus sistem login bawaan yang tidak terpakai** beserta tabelnya
   (`users`, `password_reset_tokens`, `sessions` bila tidak dipakai guard
   lain, `passkeys`). Ini izin eksplisit menurut `CLAUDE.md` untuk menghapus
   kode yang ada. Rute `/register` untuk pendaftaran peserta **tidak** ikut
   dihapus. Penghapusan tabel adalah migrasi destruktif: wajib tag snapshot
   `pre-{aksi}`, dan harus dibuktikan bahwa login admin dan login peserta
   tetap berfungsi.

## 14. Spesifikasi foto berkala proctoring — SELESAI

Keputusan pemilik proyek: foto berkala yang diambil kamera setiap 12–20 detik
acak (SPEC.md §8A.2) disimpan dengan spesifikasi berikut:

- **Resolusi 480×360** (4:3). Kalau kamera memberi rasio lain, frame diperkecil
  agar muat di dalam 480×360 tanpa diregangkan.
- **JPEG, kualitas ≈ 0,6.**
- **Dihapus otomatis 90 hari** setelah diambil (lifecycle penyimpanan). Setelah
  itu yang tersisa hanya ringkasan peristiwa, tanpa gambar (SPEC.md §8A.7).

Konsekuensi teknis:
- Ketiga nilai di atas dibaca dari **konfigurasi server**, bukan ditanam
  sebagai konstanta di klien, supaya bisa direvisi tanpa rilis frontend.
- Ukuran satu foto diperkirakan puluhan KB, sehingga beban penyimpanan per
  peserta per baterai tes tetap kecil. Angka pastinya diukur ulang saat
  backend F7 dibangun.
- Frame yang gagal diambil (kamera mati) dicatat sebagai fakta, bukan diisi
  ulang. Lihat butir 12.
- Foto identitas (butir 5d) **tidak** diatur oleh butir ini.

## 15. Retensi foto identitas (butir 5d) — SELESAI

Keputusan pemilik proyek: **angka usulan butir 5d berlaku**. Foto dokumen
identitas dan selfie awal dihapus 90 hari setelah laporan terbit, dan catatan
verifikasinya (hash, waktu, pemeriksa) disimpan 5 tahun. Tanda "perlu
konfirmasi hukum" dicabut, dan butir ini **bukan penghambat produksi**. Kalau
nanti ada masukan hukum, angkanya direvisi lewat konfigurasi, bukan rilis.

## 16. Teks layar persetujuan proctoring — SELESAI

Keputusan pemilik proyek: teks layar persetujuan kamera mengikuti **praktik
terbaik**, tanpa tinjauan kata per kata oleh pemilik. Acuannya prinsip
persetujuan yang sah menurut UU No. 27/2022 tentang Pelindungan Data Pribadi:
- tujuan pengumpulan disebut jelas;
- jenis data disebut (foto berkala, pencocokan wajah, catatan kepergian
  layar);
- masa simpan disebut (90 hari, butir 14);
- siapa yang memproses dan siapa yang melihat hasilnya (psikolog);
- hak peserta atas datanya, serta kontak untuk menggunakannya;
- bahasa yang tidak menyalahkan dan tidak mengklaim mencegah kecurangan.

Lane FE mencocokkan teks yang ada (PR #83) dengan daftar ini dan melengkapi
bagian yang kurang.

## 17. Siapa yang boleh menerbitkan laporan: "staff" = admin aplikasi pusat — SELESAI

Klarifikasi pemilik proyek atas kata "admin" di butir 4: yang dimaksud adalah
**staf pusat (admin aplikasi), satu tingkat di bawah super_admin**, bukan staf
cabang.

- **Menerbitkan (`GenerateReports`)**: psikolog, super_admin, dan admin
  aplikasi pusat. Hanya untuk laporan yang **sudah ditandatangani**
  psikolog.
- **Menandatangani (`ReviewReports`)**: tetap **hanya psikolog** (butir 4).
- **`branch_admin` dan `staff` cabang**: tetap **tidak boleh** menerbitkan
  (butir 9).

Kondisi sistem saat ini: peran `staff` selalu terikat cabang (`branch_id`
wajib, konteks RLS `staff` per cabang), dan peran admin aplikasi pusat
**belum ada**. Sampai peran itu dibuat, hanya psikolog dan super_admin yang
bisa menerbitkan. Membuat peran baru (ability, konteks RLS, pengelolaan akun)
adalah perubahan keamanan: rencana dulu, ditinjau Lead, baru kode.

## 18. Dana talang (bridge funding) — SELESAI, arah kebijakan

Sistem **belum live** (belum ada organisasi memakai integrasi produksi apa pun,
termasuk API integrasi versi lama). Ini melonggarkan penutupan celah
entitlement lama (lihat handoff `legacy-entitlement-provisioning-plan`,
PR #100): tidak ada klien aktif yang bisa terganggu, sehingga Direction A
(kunci entitlement sampai pembayaran terverifikasi untuk mode berbayar)
boleh diterapkan penuh tanpa periode transisi.

Keputusan pemilik proyek untuk dana talang:

- **Yang menalangi:** holding (induk perusahaan ONCAM), bukan LPK/lembaga
  dan bukan ONCAM sendiri sebagai penanggung akhir.
- **Yang menyetujui:** admin, bertindak berdasarkan data/instruksi dari
  manajemen — bukan keputusan admin sendiri secara independen. Butuh jalur
  persetujuan yang mencatat siapa admin-nya dan referensi data manajemen
  yang menjadi dasar (nomor surat/instruksi, dsb.), bukan sekadar tombol
  approve tanpa jejak.
- **Pengalaman peserta:** **tidak ada perbedaan** dengan peserta yang
  membayar. Akses, urutan tes, dan penerbitan laporan berjalan sama
  persis. Dana talang tidak boleh terlihat oleh peserta maupun tercatat
  sebagai status khusus yang membedakan perlakuan tesnya.
- **Tagihan:** setelah talangan diberikan, sistem menerbitkan **invoice
  tagihan** untuk ditagihkan kemudian. Riwayat tagihan ini tidak boleh
  hilang atau tertimpa (lihat temuan retensi pembayaran di audit RLS).
- **Wajib saat go-live:** ya. Ini bukan fitur yang bisa menyusul setelah
  peluncuran.

Konsekuensi teknis (untuk tim, bukan bagian keputusan pemilik):
- Ini adalah implementasi konkret dari SPEC.md:259 klausul (c) —
  "aktivasi manual (super_admin, teraudit)" — yang sampai saat ini belum
  punya kode sama sekali. Dana talang dan klausul (c) dirancang sebagai
  **satu mekanisme yang sama**, bukan dua jalur terpisah.
- "Admin" yang menyetujui: perlu diperjelas peran mana (super_admin,
  admin aplikasi pusat butir 17, atau keduanya) saat desain teknis
  dibuat — psikolog/pemilik tidak diminta memutuskan detail peran di
  sini, itu keputusan teknis Lead.
- Batas jumlah/nominal talangan per peserta atau per cabang **belum
  ditentukan** pemilik proyek. Desain teknis dibuat agar batas ini bisa
  ditambahkan lewat konfigurasi tanpa perubahan skema, dan defaultnya
  tanpa batas sampai pemilik menentukan lain.

## 19. Batas percobaan ulang tes (retest) — SELESAI

Keputusan pemilik proyek:

- **Batas: 3 kali percobaan per peserta di lembaga yang sama.** Dihitung
  per kasus/lembaga seperti cara sistem bekerja sekarang, **bukan** lintas
  lembaga — pemilik proyek eksplisit memilih ini karena "yang penting
  tidak ribet", yaitu tanpa perlu menyimpan nomor identitas resmi (KTP/
  paspor) peserta untuk mencocokkan orang yang sama lintas lembaga.
  Peserta yang pindah lembaga otomatis mendapat hitungan baru, sesuai
  cara data peserta tersimpan hari ini (baris peserta baru per lembaga).
- **Yang boleh menyetujui percobaan ke-4 dan seterusnya:** super_admin,
  **admin aplikasi pusat** (butir 17), dan **psikolog**. Admin cabang dan
  staf cabang **tidak** termasuk.

Konsekuensi teknis (untuk tim): mekanisme "izin mengulang tes"
(`AssessmentRetestGrant`) sudah ada di kode tapi belum pernah dipakai —
saat ini mengulang tes SELALU ditolak. Menyambungkannya perlu: ability
baru untuk menyetujui retest (digerbangi ketiga peran di atas), hitungan
percobaan per `assessment_case`/lembaga, dan audit trail yang sama
polanya dengan verifikasi pembayaran manual (aktor, alasan, waktu).

## 20. Hak admin aplikasi pusat — SELESAI (pelebaran dari butir 17)

Keputusan pemilik proyek: admin aplikasi pusat, selain menerbitkan
laporan yang sudah ditandatangani (butir 17), juga diberi:
- verifikasi pembayaran transfer manual (`VerifyPayments`);
- edit data peserta (`EditParticipants`);
- kelola paket tes (`ManageTestPackages`);
- lihat daftar metode pembayaran (bagian dari `ManagePaymentMethods` yang
  bersifat baca; pengelolaan penuh tetap dipertimbangkan terpisah oleh
  tim teknis saat desain ability dibuat).

Yang **tetap tidak boleh**: mengelola akun admin lain (`ManageAdmins`),
menandatangani laporan (`ReviewReports`), dan melihat DASS (`ViewDass`) —
tidak berubah dari butir 17.

## 21. Jawaban psikolog atas dokumen "Sepuluh keputusan psikometri" — SEBAGIAN SELESAI (2026-09-22)

Psikolog menjawab dokumen versi 8 pertanyaan (P1–P8); P9 (batas kamera mati)
dan P10 (selesai lebih awal IST) belum dikirimkan — susulan terpisah.

**P1 (IST, soal kosong)** — SELESAI: dihitung salah (nilai 0). Psikolog:
wajar ada soal tak terjawab karena waktu subtes terbatas.

**P2 (PAPI, durasi/soal kosong)** — SELESAI, lihat juga butir 22 di bawah.
PAPI Kostick aslinya dirancang tanpa batas waktu dan idealnya wajib
dijawab semua. Karena versi online tetap perlu batas waktu, psikolog
memilih **40 menit** (bukan dihapus). Dengan batas waktu ini tetap ada,
kemungkinan soal kosong tetap ada — psikolog tidak eksplisit memilih
opsi P1-gaya (nilai 0) untuk PAPI; **default aman**: perlakukan sama
seperti P1 (nilai netral/tidak dihitung sebagai salah untuk soal ganjil-
genap PAPI, ikuti `SCORING_ALGORITHM.md`) — **tim teknis: konfirmasi ke
psikolog secara terpisah kalau rumus PAPI butuh aturan soal-kosong
eksplisit**, jangan diasumsikan sama dengan IST tanpa konfirmasi karena
mekanisme skoring PAPI (ipsative ROLE/NEED) berbeda dari IST.

**P3 (RMIB, peringkat tidak lengkap)** — SELESAI:
- Tepat satu peringkat hilang dalam satu kelompok → **direkonstruksi**
  (angka 1–12 dipakai sekali, sisanya pasti angka yang hilang), skor
  penuh tetap sah.
- Lebih dari satu hilang, atau ada angka ganda, dalam satu kelompok →
  **kelompok itu diabaikan** untuk semua kategori minat, supaya skor
  antar-peserta tetap sebanding; hasil kelompok itu dibaca kualitatif
  saja, tidak masuk perhitungan.
- Lebih dari satu kelompok cacat (memenuhi kondisi di atas) → **tidak
  bisa diskor sama sekali**, peserta perlu administrasi ulang.
- **Prasyarat wajib sebelum aturan di atas dijalankan**: verifikasi
  tiap kelompok RMIB berjumlah tepat 78 (1+2+...+12), totalnya 702
  untuk 9 kelompok. Ini pemeriksaan integritas data sebelum skoring,
  bukan bagian dari kebijakan kelompok cacat itu sendiri.

**P4 (waktu habis sebelum "Kumpulkan")** — SELESAI: tetap dinilai dari
jawaban yang sudah masuk saat sesi ditutup sistem, mengikuti aturan
P1–P3 untuk bagian yang kosong.

**P5 (Kraepelin, koreksi angka)** — SELESAI: boleh dibetulkan selama
kolom itu masih berjalan (dalam 15 detik); begitu pindah kolom,
terkunci, tidak bisa diubah lagi.

**P6 (Kraepelin, jawaban telat)** — SELESAI: **tidak dihitung**. Kolom
dinilai hanya dari jawaban yang tiba tepat waktu di server.

**P7 (IST subtes ME, daftar hafalan)** — SELESAI, dikonfirmasi pemilik
proyek 2026-09-22: **Versi A — TEKUKUR (Burung) dan QUINTET (Kesenian)**.
Ini juga versi yang cocok dengan contoh soal tercetak di halaman
petunjuk ME. Data instrumen ME di `ist_items.json` (dan turunannya)
sekarang boleh diubah dari status `draft` menjadi `final` HANYA setelah
kata-kata Versi A ini benar-benar dipakai (lewat `tools/extract/` +
review, bukan ditulis tangan) — sesuai batas fail-closed yang sudah
ditetapkan untuk ME.

**P8 (IST, waktu membaca petunjuk)** — SELESAI: **tidak termasuk waktu
subtes**. Peserta membaca petunjuk dan contoh tanpa batas waktu khusus,
lalu menekan tombol untuk memulai — dan psikolog secara spesifik minta
label tombolnya **"Mulai mengerjakan"** (bukan "Mulai subtes").

## 22. Revisi durasi PAPI dan RMIB — SELESAI (2026-09-22)

Mengganti angka di butir yang sudah "diputuskan" sebelumnya (dan di
`CLAUDE.md`):

- **PAPI: 40 menit**, batas waktu keras tetap ditegakkan server (naik
  dari 30 menit). Teks pembuka PAPI tidak lagi menyebutkan aturan
  waktu khusus tambahan apa pun di luar angka ini.
- **RMIB: tanpa batas waktu keras yang terasa oleh peserta**, tapi
  server tetap punya deadline sungguhan sebagai batas teknis, diset
  longgar (60 menit) supaya praktis tidak pernah tersentuh peserta
  yang mengerjakan wajar. Teks pembuka RMIB diganti: **"tidak ada
  batas waktu, biasanya selesai sekitar 20 menit."** Tampilan
  hitung-mundur di halaman tes tetap mengikuti `remaining_seconds`
  dari server apa adanya (tidak ada logika timer baru di klien) —
  hanya angkanya yang berubah jadi lebih longgar.

## Susulan yang masih menunggu psikolog

- **P9** (batas waktu kamera mati sebelum dianggap validitas V2) dan
  **P10** (bolehkah peserta pindah subtes IST sebelum waktu habis) —
  belum dikirim ke psikolog dalam dokumen yang sudah dijawab ini,
  perlu susulan terpisah.
- **P2 lanjutan**: apakah PAPI butuh aturan soal-kosong eksplisit
  terpisah dari IST, mengingat skoringnya ipsative (lihat catatan di
  butir 21).

## Butir yang masih terbuka setelah dokumen ini

- Retensi foto identitas (butir 5d) sudah ditetapkan di butir 15. Butir 3
  (penyimpanan backup) sudah diputuskan pemilik proyek dan tidak lagi
  menunggu konfirmasi.
- Pembagian waktu subtes ME sudah diputuskan (180 detik menghafal + 360
  detik menjawab) dan tercatat di `CLAUDE.md`.
