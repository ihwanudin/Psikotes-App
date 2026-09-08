# SPEC v4.3 — Sistem Psikotes Daring CPMI · psikotes.oncam.id

Status: FINAL — acuan pembangunan
Menggabungkan: **Spesifikasi Teknis HPP CPMI v2.3** (Rizqi Ulin Nuha, S.Psi. — psikometri, skoring, laporan, tata kelola data) + kerangka infrastruktur (alur bayar, fee cabang, proctoring teknis). **Stack final: Laravel + Inertia.js/React + Filament + PostgreSQL** — menggantikan draf arsitektur Cloudflare/Supabase dari versi SPEC paling awal (lihat CHANGELOG).
Prinsip rekonsiliasi: kebutuhan produk mengikuti PRD terbaru; metode psikometri mengikuti konfirmasi akhir psikolog, lalu tabel lookup v1.1 dan golden test sebagai bukti numerik. HPP v2.3 tetap menjadi sumber laporan dan etika. Dokumen atau tabel acceptance lama yang bertentangan dianggap disupersesi oleh urutan ini dan konfliknya dicatat dalam ADR.

> Perubahan besar dari v3.0 (WAJIB dibaca): skala laporan **1–5** (bukan 1–10); kelayakan memakai **model Grey Area terhadap standar bidang kerja** (bukan knockout+ambang total); aspek kritis **A1/B2/C4/C5** (bukan 13 aspek); **dua dokumen keluaran** (HPP + Lembar Kerja Internal); **DASS-21 jalur terpisah mutlak**; **wajib tinjau+tanda tangan psikolog** sebelum terbit. Rincian di §5–§9.

---

## 1. Ringkasan & Ruang Lingkup

Pemeriksaan psikologis CPMI 100% daring, menghasilkan **Laporan Hasil Pemeriksaan Psikologis (HPP)** dwibahasa ID–JP untuk LPK dan organisasi penerima (kumiai) di Jepang, plus **Lembar Kerja Internal Psikolog** sebagai arsip pertanggungjawaban. Peserta membayar (B2C) via satu gateway pusat; setiap peserta beratribut cabang/LPK.

Baterai tes & keluaran yang dibutuhkan sistem:

| Instrumen | Fungsi di HPP | Keluaran | Masuk penilaian zona? |
|---|---|---|---|
| IST | Intelektual + sebagian cara kerja | IQ total + SW 9 subtes (SE,WA,AN,GE,RA,ZR,FA,WU,ME) | Ya |
| PAPI Kostick | Kepribadian & karakter kerja | Skor mentah 0–9 untuk 20 skala | Ya (16 skala) |
| Kraepelin | Kecepatan, ketelitian, keajegan, ketahanan | Benar/salah/terlewat per kolom, 50 kolom | Ya |
| RMIB | Minat kerja | Skor total + rank 1–12 untuk 12 kategori | Hanya kategori bidang tujuan |
| DASS-21 | Skrining kesehatan mental (**terpisah**) | Respons 0–3 tiap item + waktu | **TIDAK — mutlak (G4)** |

Dua keluaran dokumen:
- **HPP** → peserta, LPK, kumiai. Isi: I. Identitas & Administrasi · II. Hasil (IQ + 18 aspek berarsiran zona + Uraian per klaster) · III. Skrining Kesehatan Mental (**kategori umum saja**) · IV. Kesimpulan & Rekomendasi · V. Batasan & Kerahasiaan.
- **Lembar Kerja Internal** → psikolog saja. Isi: validitas sesi (10 butir) · ringkasan zona · draf integrasi 7 slot · hasil rinci DASS (skor subskala) · tinjauan & penyesuaian profesional.

Yang sistem **TIDAK boleh** lakukan: menerbitkan laporan bertanda tangan tanpa tinjauan psikolog (G5); menjadikan DASS penentu kelayakan (G4); menerbitkan dari sesi TIDAK VALID (G3); mencetak skor subskala DASS / skor mentah / kode aspek / daftar periksa validitas pada HPP yang dikirim ke kumiai; menerapkan perubahan standar Grey Area secara surut (G8).

## 2. Arsitektur (dari SPEC infra — tetap berlaku)

```
┌─────────────────────────── Docker Compose (VPS) ───────────────────────────┐
│  app (PHP-FPM + Nginx) — Laravel                                           │
│    ├─ Peserta (5 instrumen): Inertia.js + React                           │
│    └─ Admin/Staf/Psikolog: Filament (Livewire)                            │
│  queue worker (Laravel Queue, Redis-backed)                                │
│    → rakit narasi · render PDF (Browsershot/Puppeteer headless)           │
│      · sinkron Drive · notif WAHA/n8n · webhook bayar (idempotent)        │
│  Laravel Scheduler (cron): expiry order, retensi, reset sequence           │
│  Redis (privat)  ·  Postgres (privat, +RLS)  ·  S3-compatible object store │
│  (PDF/foto/bukti)  ·  Google Shared Drive (arsip, via service account)     │
└──────────────────────────────────────────────────────────────────────────┘
```

Keputusan arsitektur (FINAL — menggantikan draf Cloudflare/Next.js/Supabase sebelumnya): Laravel sebagai backend tunggal; dua permukaan frontend dalam satu codebase (Inertia+React untuk peserta, Filament+Livewire untuk admin/staf/psikolog — dipilih demi kecepatan CRUD & alur tinjauan bawaan Filament); PostgreSQL dengan RLS di-host di VPS/managed Postgres (bukan Supabase) — **RLS TIDAK otomatis di luar BaaS**, konteks (branch_id/role) WAJIB disuntik via middleware Laravel kustom di tiap request; object storage generik S3-compatible (driver Flysystem S3) sebagai primer, Google Shared Drive sebagai arsip async (bukan jalur kritis); auth peserta via JWT kustom (no_tes+tgl_lahir), auth admin/psikolog via Laravel (Filament auth di atas Postgres, bukan Supabase Auth); norma/kamus/ambang = **data, bukan kode** (§4 HPP menegaskan aplikasi TIDAK BOLEH menyimpan salinan angka di kode); PaymentProvider adapter (**implementasi awal: Xendit Invoice**); RLS dua level pusat/cabang; **atribusi cabang via referral link (first-touch)**. **Tambahan v4.0 dari HPP:** skema/ruang penyimpanan DASS **terpisah** dari data psikotes umum (kewajiban hukum, §11); `versi_standar` wajib disimpan di tiap hasil kelayakan.

## 3. Alur End-to-End

```
1 PRA-SESI   buka link referral cabang (?ref=KODE) → atribusi cabang first-touch
             → registrasi → verifikasi identitas (foto vs KTP/paspor)
             → consent A (psikotes, wajib) → consent B (DASS-21, wajib-terpisah)
             → PENETAPAN BIDANG KERJA TUJUAN (menentukan tabel standar Grey Area)
             → BAYAR (Xendit Invoice; transfer manual sbg cadangan)
             → webhook 'paid' → entitlement locked→ready (GATING: tes tak bisa
               dimulai sebelum ready) → uji perangkat → penjadwalan
2 SESI PROCTORED   IST 72′ → PAPI → Kraepelin 12,5′ → RMIB → DASS-21
             rekam: kamera berkala 12–20 dtk + pencocokan wajah, deteksi pindah tab (visibilitychange), suara/wajah kedua, putus-sambung, waktu/subtes (detail §8A)
3 VALIDITAS (10 butir → V1/V2/V3)   V3 → BERHENTI, laporan tidak dibuat, jadwalkan ulang
4 SKORING & ZONA   skor mentah → baku per instrumen → level 1–5 per aspek (18)
             → tabel standar bidang → zona per aspek (OK/GREY/BELUM) → label + guardrail
             DASS-21 di jalur terpisah (tak bertemu jalur zona)
5 RAKIT NARASI (draf)   bank narasi + konektor → Uraian per klaster (HPP)
             → integrasi slot S1–S7 (internal) → narasi kategori umum DASS (HPP) + subskala (internal)
6 TINJAU & TANDA TANGAN ◀ WAJIB (G5)   psikolog baca → boleh ubah level/label (G6, wajib alasan)
             → ringkas draf integrasi jadi Uraian → tanda tangan elektronik
7 TERBIT & DISTRIBUSI   HPP (.docx+.pdf) → LPK/kumiai · Lembar Internal (.pdf) → psikolog saja
             jejak audit di tiap langkah
```

## 4. Skoring per Instrumen → Level 1–5

Sumber semua angka: empat berkas master psikolog (Tabel Lookup, Bank Narasi, Contoh Skoring, Formula Drafting). Alur pemeliharaan: master diubah psikolog → ekstrak ulang jadi berkas data → aplikasi memuat. **Aplikasi tidak menyimpan salinan angka di kode.**

### 4.1 IST
9 subtes, 176 item, 72 menit. RW → SW via tabel norma per subtes (GE punya tabel sendiri RW 0–32; 8 subtes lain RW 0–20; SE RW16=131 terkoreksi). Parser F0 wajib menangani dua blok kolom berdampingan.

Kategori 5 taraf (berlaku sama untuk 9 subtes) → level aspek:
`SW ≥119 Baik(5) · 105–118 Cukup Baik(4) · 95–104 Sedang(3) · 81–94 Agak Kurang(2) · ≤80 Kurang(1)`

**IQ (aspek A1):** ΣRW 9 subtes → IQ (sheet 03) → skor 1–10 (sheet 04) → level 1–5 dengan pasangan skor `[1,2]→1 · [3,4]→2 · [5,6]→3 · [7,8]→4 · [9,10]→5`. Batas final pada nilai IQ: `≤90→1 · 91–102→2 · 103–114→3 · 115–126→4 · ≥127→5`. Mapping eksplisit dan versinya disimpan sebagai data; aplikasi tidak menghitungnya dari angka yang ditanam di kode.

### 4.2 PAPI Kostick — 20 dimensi, metode OPTIMAL (jarak dari zona Putih)
Konversi memakai **jarak langkah dari tepi zona PUTIH** (optimal/adaptif), bukan nilai warna tetap:
```
jarak = skor<lo ? lo−skor : (skor>hi ? skor−hi : 0)
level = max(1, 5 − min(jarak, 4))   // dalam Putih→5 · 1→4 · 2→3 · 3→2 · ≥4→1
```
Penyimpangan ke atas & ke bawah setara. Batas zona Putih BERBEDA tiap dimensi (tabel 20 dimensi di berkas data). **16 skala dipakai** (A,B,C,D,E,F,K,L,N,O,P,R,S,T,V,W); **4 tidak** (G,I,X,Z) — tetap diskor & tampil ke psikolog sebagai bahan kualitatif. (Uji T-04: zona W `[4,7]`, sehingga skor 0/5/9 → level 1/5/3.)
Catatan kalibrasi: zona Putih ≈39% rentang → Klaster C cenderung berkumpul di level 4–5; bila setelah 100 kasus Klaster C hampir seluruhnya Terpenuhi, yang ditinjau adalah **letak standar minimum**, bukan tabel PAPI (§13 Tahap 1).

### 4.3 Kraepelin — 4 faktor
50 kolom × 15 dtk = 12,5 mnt; 28 angka/kolom (27 penjumlahan); input digit satuan; auto-advance; penjumlahan bawah→atas.
```
Panker  = rata-rata capaian (benar+salah) per lajur atas 50 lajur   [besar=baik]
Tianker = Σsalah + Σterlewat                                          [kecil=baik]
Hanker  = kemiringan b regresi linear capaian per lajur ×50             [besar=baik]
Janker  = max(capaian) − min(capaian) antarlajur                      [kecil=baik]
```
> **DIKONFIRMASI PSIKOLOG (20 Agu 2026): Hanker = b×50.** Terverifikasi pada DUA golden test independen: peserta S1/S2 (b×50 = −0,62) dan peserta SMA/SMK (b×50 = 5,032, slope 0,100648) — keduanya cocok sampai desimal terakhir terhadap data mentah 50 lajur dan tabel cutoff. Panker memakai seluruh capaian yang dikerjakan (`benar+salah`). Faktor dibulatkan half-up ke 3 desimal sebelum lookup cutoff; nilai sebelum dan sesudah pembulatan disimpan untuk audit.

Cutoff per grup norma (6 grup; CPMI SLTA → SMA/SMK). Contoh SMA/SMK:
`Panker: ≤7,871→1 · –9,994→2 · –13,055→3 · –15,484→4 · ≥15,485→5`
`Tianker: ≥28→1 · 14–27→2 · 7–13→3 · 3–6→4 · 0–2→5`
`Hanker: ≤−2,758→1 · –−0,762→2 · –2,259→3 · –4,221→4 · ≥4,222→5`
`Janker: ≥15→1 · 12–14→2 · 8–11→3 · 4–7→4 · 0–3→5`
S1/S2 IPS memakai norma S1/S2 IPA untuk Hanker & Tianker.
Golden test (fixture F0 wajib, DUA peserta terverifikasi independen):
  • S1/S2: Panker 15,86 · Tianker 7 · Hanker −0,62 · Janker 7 → skor 7/6/4/6 (IPA)
  • SMA/SMK: ΣY 656 · Panker 13,12 · Tianker 5 · Janker 6 · slope b 0,100648 · Hanker 5,032 → Baik/Baik/Baik/Baik Sekali (4/4/4/5)

### 4.4 RMIB
9 kelompok × 12 pekerjaan; kategori posisi p kelompok j = `MOD(p+j−2,12)+1`. Skor kategori = Σ rank pada 9 sel; makin kecil makin diminati. Validasi wajib: Σ rank=702, tiap kelompok=78, 108 baris input. Rank kategori memakai competition ranking seperti rumus Excel `RANK(...,1)`: total sama mendapat rank sama dan rank berikutnya terlewati. Rank → skor 1–10 mengikuti sheet 11, lalu level: `1–2→5 · 3–4→4 · 5–8→3 · 9–10→2 · 11–12→1`. 5 kategori dipakai di HPP (Out/Mech/Prac/Med/SocSvc → D1–D5); 7 lain tersimpan untuk psikolog.

## 5. Agregasi Sub-Aspek & Skala Formula

13 sub-aspek kemampuan (A1–A2, B1–B4, C1–C7) dihitung dari sumber alat tes; 5 sub-aspek minat (D1–D5) langsung dari RMIB. Sumber per sub-aspek (Formula Drafting; **bobot setara, jangan tambah bobot di kode**):

```
A1 Inteligensi Umum        IST 9 subtes (via IQ)
A2 Analisis–Sintesis       IST AN,RA,ZR + PAPI R
B1 Konsentrasi&Ingat       IST ME + Kraepelin Panker,Janker
B2 Kecepatan&Ketelitian    Kraepelin Panker,Tianker
B3 Daya Tangkap            IST GE,SE,WA
B4 Sistematika             Kraepelin Janker + PAPI C,D
C1 Kematangan&PD           PAPI L,E
C2 Komunikasi&TggJwb       PAPI N,F,S
C3 Inisiatif&Sosial        PAPI P,A,B,O
C4 Stres&Stabilitas        PAPI E,K + Kraepelin Hanker
C5 Ketahanan Kerja         Kraepelin Hanker + PAPI V
C6 Keuletan                PAPI T,V,N
C7 Arah&Gaya Kerja         PAPI W,F,R + Kraepelin Janker
D1–D5 Minat                RMIB (masing-masing 1 kategori)
```

Perhitungan (skala jangkar): tiap sumber → level 1–5 → nilai jangkar `{1:1,2:3,3:5,4:7,5:9}` → **rata-rata setara** → skor 1–10 (half-up, clamp 1–10) → level `ceil(skor/2)`. Untuk D (RMIB) skor HPP 1–10 langsung dari rank. Lima band skala 1–10 = lima kolom HPP: `1–2 Rendah · 3–4 Kurang · 5–6 Cukup · 7–8 Baik · 9–10 Tinggi`. **Skor 1–10 tidak dicetak di HPP** (hanya penanda di salah satu 5 kolom); nilai 1–10 dicatat di Lembar Internal agar tertelusur.

Rincian level & jangkar tiap sumber WAJIB disimpan & tampil di layar tinjauan (psikolog perlu tahu *mengapa* aspek bernilai X).

## 6. Grey Area & Penetapan Rekomendasi

Menggantikan model SKK/knockout+ambang. Kelayakan = perbandingan level tiap aspek vs **standar minimum bidang kerja tujuan**.

### 6.1 Tiga zona
`OK (Terpenuhi) level≥standar · GREY (Grey Area) level=standar−1 · BELUM level≤standar−2`. Arsiran (#E7F1EA / #FBF0D9 / #F7E6E6) diterapkan ke seluruh sel kolom 1–5, bukan hanya sel skor.

### 6.2 Standar minimum per bidang
Standar dasar 13 aspek A/B/C = level 3. Klaster D tanpa standar KECUALI kategori minat bidang tujuan (=3).

| Bidang | Minat wajib | Aspek dinaikkan ke level 4 |
|---|---|---|
| KAIGO (perawatan) | D4 ≥3 | C2, C3, C4 |
| KENSETSU (konstruksi) | D3 ≥3 | B2, C5 |
| NOUGYOU (pertanian) | D1 ≥3 | C5, C6 |
| SEIZOU (manufaktur) | D2 ≥3 | B1, B2, B4 |
| GAISHOKU (jasa) | D5 ≥3 | B2, C2, C3 |
| UMUM | — | standar dasar saja |

Versi standar berlaku: **GA-2026.08** (ditetapkan psikolog, final). Seluruh baris di atas final; bidang baru harus menetapkan standar & menaikkan versi sebelum dibuka (G8).

### 6.3 Penetapan label (urutan WAJIB, knockout-dulu)
```
ASPEK_KRITIS = [A1, B2, C4, C5];  MAKS_BELUM = 2
1. validitas V3 → hentikan (G3)
2. ada aspek kritis di BELUM → TIDAK DISARANKAN (G1)
3. >2 aspek (non-kritis) di BELUM → TIDAK DISARANKAN
4. ada BELUM atau GREY → DIPERTIMBANGKAN (syarat pendampingan WAJIB ditulis, G9)
5. semua OK → DISARANKAN
6. IQ<70 & DISARANKAN → turun DIPERTIMBANGKAN, wajib tinjau (G2)
7. TIDAK ADA pemeriksaan DASS di sini — tidak sekarang, tidak nanti (G4)
```
Minat bidang tujuan bukan aspek kritis (tak bisa sendirian memicu Tidak Disarankan) tapi bila GREY/BELUM wajib disebut di Uraian D + catatan rekomendasi (minat rendah = peramal kuat ketidakbetahan).

### 6.4 Aturan penjaga G1–G9
G1 aspek kritis BELUM→Tidak Disarankan · G2 IQ<70 tak bisa Disarankan, wajib tinjau · G3 sesi V3→tak terbit · **G4 DASS tak pernah mengubah zona/label** · G5 wajib tinjau+ttd sebelum terbit (tak ada jalur pintas ke publish) · G6 psikolog boleh override level/label dengan alasan tertulis (tercatat audit) · G7 selisih ≥2 level antar sumber pada 1 aspek → "perlu tinjauan", tak dinarasikan otomatis · G8 tabel standar berversi, tak berlaku surut · G9 DIPERTIMBANGKAN tanpa syarat tertulis tak bisa ditandatangani.

## 7. DASS-21 — Jalur Terpisah Mutlak

Dihitung & dinarasikan tapi terpisah penuh dari zona/label. **Skor subskala TIDAK dicetak di HPP** (hanya kategori umum + narasinya); skor rinci di Lembar Internal. Skoring: skor mentah subskala ×2 → ambang DASS-42 → 5 taraf. Item mapping baku: D=[3,5,10,13,16,17,21] · A=[2,4,7,9,15,19,20] · S=[1,6,8,11,12,14,18]. Kategori umum = taraf terberat dari 3 subskala. Tindak lanjut: kat≥4→rujukan, =3→pemantauan. Penanda validitas: respons seragam / waktu <90 dtk. Kategori Parah/Sangat Parah → **tawaran dukungan, bukan penghentian proses**; narasi tak menghakimi. DASS-21 tersedia sebagai paket mandiri gratis. Setiap paket psikotes utama menyertakan DASS-21 secara otomatis dan tidak menyediakan kontrol peserta untuk menambah atau menghapusnya. Consent B tetap dicatat terpisah sebelum pendaftaran dilanjutkan.

Larangan mutlak (uji T-07): nilai DASS tak boleh muncul di ekspresi apa pun yang menghasilkan zona/label; tak boleh jadi penyaring/urutan/penanda peringkat; tak dicetak/dikirim ke LPK/kumiai.

## 8. Validitas Sesi Daring (V1/V2/V3)

Dicetak di Lembar Internal, bukan HPP. Mekanisme deteksi tiap butir dijabarkan di §8A (Integritas & Proctoring). 10 butir: verifikasi identitas · kamera aktif · pindah tab/jendela · suara/orang kedua · kelengkapan respons · waktu per subtes wajar · pola tak seragam (straight-lining) · kestabilan koneksi · social desirability PAPI · tempo Kraepelin manusiawi.
```
V3 (tak terbit): identitas gagal | indikasi bantuan pihak lain | subtes tak lengkap | pola tak sahih
V2 (perlu catatan prosedur): putus-sambung>0 | pindah tab>0 | waktu di luar wajar | kamera pernah mati
V1: bersih
```
V2 tak boleh ditandatangani dengan catatan prosedur kosong.

## 8A. Integritas Ujian & Proctoring 試験の公正性・遠隔監視

Konteks: peserta **mayoritas memakai HP**; kebijakan integritas **seimbang** — deteksi & tandai, keputusan akhir di psikolog lewat status validitas (§8). Bagian ini menetapkan perilaku sistem sampai tingkat yang dapat dibangun.

### 8A.1 Prinsip: cegah vs deteksi (jujur, tanpa janji berlebih)
Di web tanpa aplikasi lockdown, kecurangan **tidak dapat dicegah** — hanya dapat **ditakut-takuti (deterrence), dideteksi, dan dijadikan bukti**. Spesifikasi Fullscreen API sengaja menjamin pengguna selalu bisa keluar dari fullscreen; browser di dalam sandbox tidak dapat membaca tab lain, riwayat, atau mengambil tangkapan layar OS; HP kedua di luar perangkat ujian tidak terdeteksi sama sekali. Sistem karena itu bekerja pada tiga lapis: **(a) deterrence** (peringatan, watermark, fullscreen), **(b) evidence** (kamera, log peristiwa) yang mengalir ke status validitas V1/V2/V3, **(c) keputusan psikolog**. Materi pemasaran ke LPK/kumiai TIDAK boleh mengklaim "mustahil curang"; klaim yang benar: "terpantau, tercatat, dan ditinjau psikolog".

### 8A.2 Kebijakan kamera (mobile-first)
- **Model perekaman:** capture berkala **tiap 12–20 detik** (acak dalam rentang) sepanjang sesi + wajib saat mulai & submit, JPEG ~480–640px ke R2. (Bukan "5 foto/sesi" v3.0 — itu terlalu jarang untuk sesi ~2 jam; angka final configurable per cabang.) Streaming video kontinu TIDAK diwajibkan karena beban data/baterai HP; capture rapat sudah cukup sebagai bukti.
- **Izin ditolak / kamera tak tersedia / pernah mati:** **→ V2** (ketetapan psikolog: kamera pernah tidak aktif, berapa pun durasinya, = V2). Sesi tetap berjalan; V2 hanya mewajibkan catatan prosedur sebelum tanda tangan. Mode "wajib kamera" (tak boleh mulai tanpa izin) tersedia per cabang.
- **Keterbatasan mobile yang WAJIB ditangani (bukan diabaikan):** saat peserta beralih aplikasi / layar terkunci, browser HP **menghentikan aliran kamera**. Sistem mendeteksi `track.onended`/stream mati, mencatatnya sebagai peristiwa, mencoba **mengaktifkan ulang kamera saat peserta kembali**, dan bila gagal menandai sesi. Kamera pernah tidak aktif — berapa pun durasinya — → **V2** (durasi tiap jeda tetap dicatat). [Ketetapan psikolog, lebih ketat dari usulan 60 dtk.] Ini menjelaskan mengapa capture berkala + deteksi stream-mati lebih jujur daripada menjanjikan "kamera hidup terus" di HP.
- **Pencocokan wajah:** (1) saat mulai — foto wajah vs foto identitas (KTP/paspor), otomatis + verifikasi manual pengawas; (2) **berkala selama sesi** — sampel wajah dibandingkan dengan foto awal untuk mendeteksi pergantian orang di tengah ujian (risiko joki). Ketidakcocokan → penanda, bukan penghentian otomatis; psikolog memutuskan.

### 8A.3 Fullscreen & deteksi fokus (yang bisa & tak bisa)
- **Saat mulai tiap subtes:** minta `requestFullscreen()` (menghapus UI browser & status bar Android). Ini **deterrence**, bukan kunci — peserta tetap bisa keluar.
- **Deteksi kepergian:** pakai **Page Visibility API (`visibilitychange`)** sebagai sinyal utama (menangkap pindah tab, minimize, tertutup app lain, split-screen) + `blur`/`focus` sebagai cadangan + event keluar-fullscreen. Setiap peristiwa dicatat dengan timestamp & durasi.
- **Reaksi (kebijakan final psikolog):** setiap kepergian menampilkan banner non-blokir + timer TIDAK berhenti (server-authoritative). **Perpindahan tab / keluar fullscreen satu kali saja → V2** (mengacu Spesifikasi v2.3 §9, lebih ketat dari usulan longgar). V2 tidak membatalkan sesi — hanya mewajibkan catatan prosedur terisi sebelum psikolog menandatangani.
- **Yang TIDAK diklaim:** sistem tak tahu peserta pergi ke mana, tak mendeteksi HP kedua, tak mendeteksi Snipping Tool/kamera eksternal. Dinyatakan terbuka di lembar internal.

### 8A.4 Hardening ringan (deterrence)
Nonaktifkan klik-kanan, seleksi teks, copy/paste, dan drag pada halaman soal; watermark **nomor tes + timestamp** samar di latar soal (menjejak bila difoto layar); render soal **per halaman/pertanyaan** (bank soal tak pernah utuh di DOM); nonaktifkan autofill; blokir shortcut umum (Ctrl/Cmd+C/P/S) sebagai isyarat, bukan jaminan.

### 8A.5 Mitigasi soal statis (IST/PAPI/RMIB tak diacak)
Karena instrumen ternorma tak boleh diacak urutannya, kebocoran antar-peserta dimitigasi dengan: (a) render per halaman + anti-copy di atas; (b) ~~acak urutan opsi~~ **DITETAPKAN TIDAK BOLEH** (psikolog: seluruh baterai paten, mengacak opsi memengaruhi pengukuran skoring) — mitigasi kebocoran bergantung pada (a),(d),(e) + proctoring; (c) rotasi bila kelak tersedia paket paralel; (d) watermark penjejak; (e) deteksi pola respons identik antar-peserta sebagai sinyal audit. Kraepelin tetap seeded per peserta (aman, bukan bank soal baku).

### 8A.6 Matriks Ancaman → Sinyal → Konsekuensi
Konsekuensi memakai status validitas §8 (V1 bersih · V2 perlu catatan prosedur · V3 tak terbit). "Cegah?" menyatakan jujur apakah dapat dicegah atau hanya terdeteksi.

| Ancaman | Sinyal sistem | Cegah? | Konsekuensi |
|---|---|---|---|
| Cari jawaban di tab/app lain | visibilitychange/blur + durasi | Tidak (deteksi) | Banner; **1× → V2** |
| Keluar fullscreen / pindah tab | fullscreenchange / visibilitychange | Tidak (deteksi) | **1× → V2** (catatan prosedur wajib) |
| Joki mengganti orang di tengah | pencocokan wajah berkala gagal | Tidak (deteksi) | Tinjau manual pengawas dulu → terbukti diganti/dibantu = V3; artefak (cahaya/sudut) = catatan |
| Orang lain membantu di ruangan | deteksi suara/ wajah kedua di frame | Tidak (deteksi) | Penanda → V2/V3 |
| HP kedua untuk mencari jawaban | — | **Tidak terdeteksi** | Dinyatakan sebagai batas; mitigasi via pengawasan LPK bila ada |
| Foto layar soal | PrintScreen keydown (desktop saja) | Tidak (parsial) | Catat; watermark menjejak |
| Salin teks soal | anti-copy + clipboard event | Deterrence | Diblokir di UI; dicatat bila terdeteksi |
| Jawaban asal (straight-lining) | uji keseragaman PAPI/DASS | Deteksi | Penanda validitas |
| Pengerjaan tak manusiawi (bot/hafalan) | tempo Kraepelin, waktu per subtes | Deteksi | Penanda → tinjau |
| Identitas berbeda dari pendaftar | pencocokan wajah awal vs identitas | Deteksi | Gagal → V3 (tak terbit) |
| Kamera dimatikan sepanjang sesi | stream mati / izin ditolak | Tidak (deteksi) | V2; mode wajib-kamera → tak boleh mulai |

### 8A.7 Yang dibutuhkan peserta (transparansi & consent)
Sebelum mulai, peserta diberi tahu jelas: kamera akan mengambil gambar berkala, kepergian dari layar dicatat, dan data proctoring disimpan sementara (video/foto proctoring retensi 90 hari, hanya ringkasan peristiwa yang bertahan — §12). Persetujuan proctoring menjadi bagian consent A. Peringatan ditulis manusiawi, bukan mengancam. Peserta dengan keterbatasan perangkat (kamera rusak) punya jalur ke pengawasan alternatif via LPK, bukan langsung gugur.

## 9. Tinjau, Tanda Tangan & State Machine

Layar tinjauan (satu layar): data mentah tiap instrumen · level tiap aspek + rincian sumber/jangkar · standar & zona berwarna · nama bidang + versi standar · penanda G7 menonjol · daftar guardrail aktif · draf narasi bisa disunting (bertanda hasil rakitan) · 4 skala PAPI tak-terpakai (G,I,X,Z) · DASS lengkap di panel terpisah visual · catatan prosedur + validitas.

Override G6: `PATCH /laporan/{id}/level/{aspek}` & `/rekomendasi` — alasan wajib ≥20 karakter; simpan `level_sistem` & `level_final` berdampingan (jangan timpa); ubah level → hitung ulang zona & label.

State machine: `DRAFT_SCORED → DRAFT_NARRATED → UNDER_REVIEW → (REVISED ⇄ UNDER_REVIEW) → SIGNED → PUBLISHED → REVOKED`; VOID dari mana pun bila tak valid. **Tidak ada jalur langsung DRAFT→PUBLISHED**; jalur pintas uji coba harus dihapus sebelum peluncuran.

Prasyarat tanda tangan: bukan V3 · (bila V2) catatan prosedur terisi · (bila DIPERTIMBANGKAN) syarat pendampingan terisi (G9) · aspek G7 sudah ditetapkan psikolog · perubahan level/label ada alasan · bidang tujuan sudah ditetapkan · Uraian 4 klaster terisi.

## 10. Perakitan Narasi Otomatis

Semua teks dari Bank Narasi (.xlsx/.json), bukan di kode. Uraian per klaster: rangkai narasi tiap aspek dengan konektor (aditif bila level naik/sama, kontras bila turun; bergilir tak berulang); kalimat penutup sebut aspek non-OK. Kolom JP: kalimat mandiri tanpa konektor kontrastif (hindari kalimat Jepang tak wajar). Draf integrasi 7 slot (S1 Gambaran Umum · S2 Sikap&Cara Kerja · S3 Kepribadian · S4 Kekuatan Utama · S5 Area Pengembangan · S6 Kesesuaian Bidang · S7 Saran Penempatan) — **hanya di Lembar Internal**, psikolog meringkasnya jadi Uraian. Semua seri deterministik (proses ulang → teks identik, uji T-20). PRIORITAS_KLASTER {A:.3,B:.3,C:.4,D:0} hanya pemecah seri S4/S5, bukan bobot skor.

## 11. Model Data (gabungan)

Dari HPP v2.3: `Peserta`(+bidang_kerja_tujuan∈{KAIGO|KENSETSU|NOUGYOU|SEIZOU|GAISHOKU|UMUM}, consent_psikotes, consent_dass) · `Sesi`(status_validitas V1/V2/V3, catatan_prosedur) · `SesiSubtes` · `SkorMentah` · `DassRespons`(**tabel terpisah, akses terbatas, retensi 2 th**) · `LevelAspek`(level_sistem, level_final, diubah_psikolog, alasan, perlu_tinjauan) · `ZonaAspek`(standar, zona) · `HasilKelayakan`(versi_standar WAJIB, jml_ok/grey/belum, kritis_belum[], label_sistem, label_final, guardrail[]) · `HasilDass`(raw/x2/kat per subskala, kat_umum, tindak_lanjut, penanda[]) · `Narasi`(slot, draf id/jp, final id/jp, disunting) · `Laporan`(no_laporan, status, hash, psikolog_id, versi) · `JejakAudit`.
Dari SPEC infra (tetap): `branches`(+`ref_code` unik, `is_default` utk pusat), `admins`, `orders`(+`gateway`, `gateway_ref`=external_id, `invoice_url`)+webhook idempotent, `entitlements`(status locked→ready→in_progress→done; **GATING sesi**), `participants`(+`referral_branch_id`, `referral_source`∈{link|manual|default}), `referral_visits`(ref_code, branch_id, ip, ua, first_seen, participant_id nullable — audit atribusi first-touch), `test_sessions`+seed, `answers`/`kraepelin_events`, `reports`(r2_key, drive_file_id), `proctor_photos`/`proctor_logs`, fee: `branch_fee_rules`/`commission_entries`(period_month=tgl paid)/`withdrawal_requests`(bulanan). Tabel instrumen/config (norma, kamus, ambang, standar bidang, bank narasi, konektor) read-only, dimuat dari berkas data.

**Aturan gating akses tes (WAJIB):** `POST /sesi/{tipe}/start` menolak (403) bila entitlement peserta untuk tipe tsb bukan `ready`. Entitlement berubah `locked→ready` HANYA oleh: (a) webhook Xendit status PAID/SETTLED terverifikasi, atau (b) verifikasi transfer manual oleh admin, atau (c) aktivasi manual (super_admin, teraudit). Tak ada jalur lain. Order kedaluwarsa (Xendit invoice expiry / cron) mengembalikan entitlement ke locked.

**Referral (first-touch):** kunjungan link `?ref=KODE` mencatat `referral_visits` + set cookie 30 hari; ref pertama menang bila peserta membuka beberapa link. Saat registrasi, `participants.referral_branch_id` = cabang dari ref; bila ref kosong/tak dikenal → cabang `is_default` (pusat), `referral_source='default'`. `branch_id` peserta = referral_branch_id → modul komisi menyambung otomatis tanpa perubahan ledger. Admin cabang TIDAK boleh mengubah atribusi peserta cabang lain (audit).

Dua penekanan HPP: DassRespons & kolom DASS di skema/ruang terpisah (hukum, bukan kerapian); `versi_standar` bukan hiasan (tanpanya laporan lama terbaca ulang dgn standar baru).

## 12. Kontrol Akses, Retensi & UU PDP

Matriks akses: Peserta (HPP penuh, internal ringkasan bila diminta, DASS penuh atas dirinya) · Psikolog (semua penuh) · Admin pusat/cabang/LPK/staf berkas/kumiai (**HPP saja**; tak ada Lembar Internal, skor subskala DASS, respons DASS, data mentah) · Tim pengembang produksi (tak ada; data sintetis untuk dev/test; akses data nyata hanya sementara atas izin psikolog, tercatat).

Consent A (psikotes, wajib) + Consent B (DASS, wajib-terpisah; memuat penegasan hasil tak menentukan kelulusan). Retensi: HPP & Lembar Internal & data mentah psikotes 5 th · **respons item DASS 2 th** · skor/kategori DASS 2 th (bagian kesehatan mental di arsip laporan ikut disunting) · rekaman video 90 hari (hanya ringkasan peristiwa bertahan) · jejak audit 5 th tanpa PII. Hak peserta di antarmuka: penjelasan lisan gratis, koreksi identitas, tarik consent DASS + hapus datanya tanpa memengaruhi hasil psikotes utama, salinan laporan, tahu siapa mengakses.

## 13. Uji Penerimaan (28 uji; T-07 mutlak)

T-01 IQ→level sesuai sheet 04 · T-02 SW→level · T-03 PAPI raw (90 item, 20 dimensi, ROLE/NEED seimbang) · T-04 PAPI optimal (zona W `[4,7]`; 0/5/9→1/5/3; seluruh nilai 0–9 pada 20 dimensi) · T-05 RMIB→level + competition tie · T-06 zona · **T-07 DASS tak memengaruhi kelayakan (dua sesi psikotes identik, DASS Normal vs Sangat Parah → zona & label IDENTIK; MUTLAK)** · T-08..T-11 DASS skoring/ambang/umum/tak-lengkap · T-12..T-17 guardrail G1/G2/G3/G5/G7/G8 · T-18 standar per bidang · T-19 batas aspek non-kritis · T-20 determinisme narasi · T-21 konektor tak berulang · T-22 DIPERTIMBANGKAN wajib bersyarat · T-23 matriks akses · T-24 paket DASS mandiri tersedia dan paket psikotes utama selalu memuat entitlement DASS · T-25 izin kamera ditolak → V2 (mode wajib → tak boleh mulai) · T-26 stream kamera mati di tengah (mobile app-switch) → dicatat, dicoba aktif ulang, gap>ambang → V2 · T-27 visibilitychange terekam dengan durasi; akumulasi menaikkan keparahan validitas · T-28 pencocokan wajah berkala gagal → penanda, bukan penghentian otomatis.

## 14. Fase Build

```
F0  Ekstraksi data instrumen + config (Tabel Lookup, Bank Narasi, standar bidang,
    ambang DASS, konektor) → berkas data. Gerbang: golden Kraepelin cocok; invarian
    PAPI/RMIB/DASS; monotonisitas norma; parser IST dua-blok; T-01..T-05 hijau.
F1  Fondasi: monorepo Laravel (app+queue+scheduler), migrasi Postgres+RLS (termasuk skema DASS terpisah), auth,
    entitas cabang (+ref_code, default) & bidang kerja, **referral link first-touch**,
    registrasi + consent A/B (termasuk consent proctoring) + verifikasi identitas
    (pencocokan wajah awal), nomor tes, entitlement gating, notif WAHA/n8n,
    **Xendit Invoice + webhook (x-callback-token, idempotent) + cek-status fallback**.
    Transfer manual tetap hidup sebagai cadangan.
F2  Engine tes + scoring per instrumen → level 1–5 (mesin sesi generik → Kraepelin →
    PAPI → RMIB → IST) + DASS jalur terpisah + **klien proctoring (§8A): kamera
    berkala, deteksi stream-mati mobile, visibilitychange/fullscreen, hardening,
    pencocokan wajah berkala**. Unit test vs golden & T-01..T-11, T-25..T-28.
F3  Grey Area & rekomendasi: standar bidang, zona, guardrail G1–G9, label. T-12..T-19.
F4  Perakit narasi (Uraian + integrasi 7 slot) deterministik; bank narasi. T-20,T-21.
F5  Layar tinjauan psikolog + state machine + override G6 + prasyarat ttd. T-22.
F6  Dokumen: HPP dwibahasa (template v2.3) + Lembar Internal; Browser Rendering; R2;
    signed URL; arsip Drive. Dua template terpisah, akses per matriks §12.
F7  Dashboard admin/cabang + fee cabang + proctoring view. Matriks akses T-23.
F8  (Opsional) gateway tambahan / Midtrans via adapter yang sama, bila diperlukan.
F9  Hardening: uji beban Kraepelin, retensi cron, backup, validasi psikometrik Tahap 0,
    review terjemahan JP, seluruh 28 uji penerimaan hijau termasuk T-07.
```

## 15. Butir Terbuka (dari HPP §14 + rekonsiliasi)

| # | Butir | Pemilik |
|---|---|---|
| 1 | ✅ SELESAI — Hanker = b×50 dikonfirmasi, 2 golden test terverifikasi | — |
| 2 | Norma lokal Kraepelin (persentil populasi CPMI unit) — sementara pakai norma berjalan + penanda | Psikolog |
| 3 | Norma IST = tabel internal dari Master Kamus; provenans manual/sampel TIDAK terdokumentasi (dicatat di lampiran metodologi sbg batasan) | Selesai (dgn catatan) |
| 4 | ✅ SELESAI — teks 21 item DASS diterima, mapping subskala terverifikasi cocok; narasi final di Bank Narasi lembar 8 | — |
| 5 | ✅ SELESAI — 6 bidang final, versi GA-2026.08; bidang baru tetapkan standar dulu | — |
| 6 | ✅ SELESAI — identitas + SILP + STR ditetapkan | — |
| 7 | Review terjemahan Jepang oleh penutur/penerjemah tersertifikasi | Psikolog + penerjemah |
| 8 | ✅ SELESAI — golden SMA/SMK terverifikasi (grup mayoritas CPMI) | — |
| 9 | **Gateway: DIPUTUSKAN Xendit** (F1). Sisa: hak pakai instrumen; retensi consent final | LPK |
| 10 | Atribusi referral: first-touch vs last-touch, masa cookie — DEFAULT first-touch 30 hari | LSI |
| 11 | Transfer manual tetap hidup berdampingan dgn Xendit? (default: ya) | LSI |
| 12 | **DITETAPKAN**: cabang = entitas referral. Komisi mengalir ke cabang via atribusi link. LPK & kumiai = penerima laporan, BUKAN penerima komisi. `commission_entries` tanpa dimensi tambahan. | — |

**Tidak ada butir yang memblokir pembangunan** — seluruh butir psikometri terjawab & terkunci (Hanker b×50 terverifikasi 2 golden test). Sisa yang menyusul: satu berkas peserta SMA/SMK riil tambahan (opsional, sudah ada 1 terverifikasi), dan validasi norma kertas↔digital setelah ~200 sesi (tidak menghambat rilis, G8).
