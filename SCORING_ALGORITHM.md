# SCORING_ALGORITHM.md — psikotes.oncam.id (v3.0 FINAL)

Dokumen kanonik logika skoring. Setiap perubahan di sini WAJIB: bump `engine_version`, entri CHANGELOG.md, dan lulus ulang TEST_PLAN.md §unit-scoring. Instrumen yang dipakai: **IST, PAPI Kostick, RMIB, Kraepelin** (bukan MBTI/DISC/Big Five — koreksi atas draft list arsitektur).

Status legenda: ✅ FINAL — semua metode disahkan psikolog (Rizqi Ulin Nuha, S.Psi.) via balasan + Tabel Lookup v1.1. Tidak ada lagi butir menggantung yang memblokir skoring.

## 1. Pipeline

```
jawaban mentah → skor alat tes (per instrumen, §2–5)
             → normalisasi ke skala 1–10 per sumber (§6.2)
             → agregasi per aspek (§6.3, tabel aspect_formulas)
             → band narasi Poor…Excellent (§6.7)
             → laporan Format Serbaindo (18 aspek + IQ + INTEGRATION)
```

Semua tabel norma/kamus adalah **data** (hasil ekstraksi F0), bukan kode. Engine murni-fungsional di `packages/scoring`, tanpa I/O.

## 2. IST ✅ FINAL
- 9 subtes, 176 item: SE20 WA20 AN20 GE16 RA20 ZR20 FA20 WU20 ME20. Durasi: SE6′ WA6′ AN7′ GE8′ RA10′ ZR10′ FA7′ WU9′ ME 3′+6′.
- Non-GE: benar=1 via `ist_keys`. GE: normalisasi (trim/lowercase) → `ist_ge_keys` 0/1/2; miss=0; miss dicatat ke `ist_ge_unknown`.
- RW subtes → SW: **sheet 01 Tabel Lookup** (tabel tunggal tanpa usia; kolom GE terpisah, skala RW 0–32). SE RW16=131 (koreksi terverifikasi). Parser F0 wajib menangani dua blok kolom berdampingan (GE di kolom A–B, 8 subtes lain di kolom C+).
- RW subtes → skor 1–10: sheet 02 (belah-dua kategori).
- IQ: ΣRW → IQ (sheet 03) → skor 1–10 (sheet 04). Clamp: RW<28→IQ77, RW>151→IQ132.

## 3. PAPI Kostick ✅ FINAL
- 90 forced-choice (45 ROLE + 45 NEED) → 20 dimensi via `Dimensi Mapping`. Invarian: ΣROLE=45, ΣNEED=45; skor dimensi 0–9.
- **Untuk HPP**: tiap dimensi → pita warna (sheet 08) → skor 1–10 (Putih=8, Biru=7, Kuning-bawah=3, Kuning-atas=5) → rata-rata ke sub-aspek. Sheet 08 menutup 0–9 penuh, tanpa celah/overlap (terverifikasi 20 dimensi).
- Sheet 09 (keadaptifan 7 aspek) = arsip profil wawancara, BUKAN input HPP.
- 4 dimensi (G, I, X, Z) tak masuk sub-aspek HPP.

## 4. RMIB ✅

- 9 kelompok (A–I) × 12 pekerjaan, daftar L/P terpisah; UI drag-and-drop (ranking unik by design). Skor kategori = Σ ranking lintas kelompok (9–108, kecil = diminati). Invarian: Σ 12 kategori = 702.
- Ranking kategori 1–12 → minat tertinggi 1–3 + interpretasi.

## 5. Kraepelin ✅ FINAL
Format: 50 kolom × 15 dtk = 12,5 mnt, 27 penjumlahan/kolom (28 angka), penjumlahan bawah→atas, auto-advance, tak bisa kembali. "Dilewati" = sel dilompati di tengah (bukan sisa kolom karena waktu habis).

```
Panker : RS = ΣY / 50               (Y = capaian benar per lajur)
Tianker: RS = Σsalah + Σdilewati
Janker : RS = max(Y) − min(Y)
Hanker : b × 50, b = (N·ΣXY − ΣX·ΣY)/(N·ΣX² − (ΣX)²)   ← FINAL, 2 golden test (S1/S2 & SMA/SMK)
```
Tiap RS → kategori & skor 1–10 per **grup norma** (sheet 06; pemetaan grup sheet 07, cadangan SMA/SMK bila profil tak lengkap). Aturan tepi: capaian lajur>27 atau benar+salah≠capaian → TOLAK; |Hanker|>Janker → CURIGA HITUNG (tahan laporan). Golden test §6.6.

Norma disusun dari peserta tulis-tangan; sistem mengetik → perlu uji kesetaraan (≥100 peserta digital/grup; tinjau Panker & Tianker; psikolog memvalidasi; cutoff dapat diganti tanpa rilis). Laporan sementara pakai cutoff kertas; keterbatasan dicatat di lampiran metodologi.

## 6. Agregasi 18 Sub-Aspek 1–10 — ✅ FINAL v3.0 (sumber: balasan psikolog + Tabel Lookup v1.1 + Manual Skoring HPP)

Menggantikan seluruh draf sebelumnya. Struktur final **18 sub-aspek** yang dinilai & dinarasikan (bukan 26). "26" versi lama mencampur skor alat mentah dengan sub-aspek agregat — diluruskan: skor alat mentah tetap dihitung namun turun status jadi **lampiran skor rinci**, bukan tubuh laporan. Semua parameter (bobot, ambang, daftar knockout, pita) dibaca dari **Tabel Lookup v1.1** sebagai data, bukan hardcode.

### 6.1 Tiga lapis
```
Lapis 1  jawaban → skor alat (kunci/kamus dari Penilaian_*.xlsx)
Lapis 2  skor alat → norma → skor 1–10 per SUMBER (tabel Lookup)
Lapis 3  rata-rata sederhana → 18 sub-aspek → Total HPP → rekomendasi
```

### 6.2 Konversi tiap sumber ke 1–10
- **IST subtes**: RW→SW (sheet 01, tabel tunggal tanpa usia; GE skala 0–32) → skor 1–10 (sheet 02, **belah-dua kategori**, BUKAN interpolasi — SW berjenjang, bukan kontinu; koreksi atas usul interpolasi saya).
- **IST General Intelligence**: ΣRW 9 subtes → IQ (sheet 03; skala mean 100 SD 15, beda dari SW SD 10 — jangan dipertukarkan) → skor 1–10 (sheet 04).
- **Kraepelin**: nilai faktor → kategori & skor per **grup norma** (sheet 06; grup dari sheet 07). Hanker = regresi b×50 (FINAL, bukan (P+J)/2).
- **PAPI (untuk HPP)**: tiap dimensi → pita warna (sheet 08) → skor 1–10 via tabel warna Manual: **Putih=8, Biru=7, Kuning ujung-bawah=3, Kuning ujung-atas=5**; skor per dimensi dirata-ratakan ke sub-aspek. **Sheet 09 (keadaptifan 7 aspek) TIDAK dipakai HPP** — arsip profil wawancara. Draf lama berbasis sheet 09 DIBATALKAN atas ketentuan psikolog.
- **RMIB**: rank kategori 1–12 → skor 1–10 (sheet 11: 1→10 … 12→1).

### 6.3 18 sub-aspek
```
A. Intellectual (2)      A1 General Intelligence · A2 Analysis-Synthesis
B. Special Ability (4)   B1 Concentration&Memory · B2 Speed&Accuracy · B3 Comprehension · B4 Systematic
C. Personality (7)       C1 Maturity&Self-Confidence · C2 Communication&Responsibility ·
                         C3 Initiative-Social Adjusted · C4 Stress Resistance&Stability ·
                         C5 Endurance · C6 Persistency · C7 Direction&Work Style
D. Job Interest (5)      D1 Outdoor · D2 Mechanical · D3 Practical · D4 Medical · D5 Social Service
```
Tiap sub-aspek = **rata-rata sederhana** skor sumbernya (tanpa bobot), dibulatkan (≥0,5 ke atas) ke 1–10. Komposisi sumber dibaca dari Manual/Tabel Lookup. Empat dimensi PAPI (G, I, X, Z) tak masuk sub-aspek mana pun — konsekuensi rancangan; tetap dihitung untuk arsip.

### 6.4 Total HPP & rekomendasi (sheet 12–13 FINAL v1.1)
- **Bobot semua 1,0** (General Intelligence dikembalikan 2→1). `Total HPP = Σ(skor×bobot)÷Σbobot` = rata-rata 18 sub-aspek. Bobot dibaca dari kolom, bukan hardcode.
- **Ambang resmi:** Disarankan ≥7,00 · Dipertimbangkan 5,00–6,99 · Tidak Disarankan <5,00. Batas **inklusif** (7,00→Disarankan; 5,00→Dipertimbangkan).
- **Urutan evaluasi WAJIB:** (1) knockout dulu, (2) baru ambang total.

### 6.5 Knockout — lapis agregat, 13 sub-aspek (KRITIS)
Dievaluasi pada **18 sub-aspek hasil rata-rata**, BUKAN skor alat mentah. Cakupan **hanya 13 sub-aspek A+B+C**; **kelima D (Job Interest) DIKECUALIKAN** dari knockout & dari syarat Disarankan.
- Alasan psikolog: RMIB forced-ranking — tiap peserta pasti taruh satu bidang di urutan 12 (skor 1). Tanpa pengecualian ~42% ter-knockout & ~68% mustahil Disarankan, semata minat bukan kemampuan.
- A/B/C berskor **1** → override Tidak Disarankan, apa pun total.
- A/B/C berskor **2** → gugurkan label Disarankan (turun Dipertimbangkan), tidak knockout.
- Job Interest berskor 1 → skor rendah biasa; tak pengaruhi label.
- Daftar tunduk-knockout dibaca dari kolom sheet 12.

### 6.6 Golden test Kraepelin (fixture WAJIB F0) ✅ terverifikasi
File `Contoh_Skoring_Kraeplin_by_Rizqi_v1_1.xlsx`, peserta S1/S2, 50 lajur: ΣY=793, salah=7, dilewati=0.
```
Panker 15,86 → S1/S2 IPA=7 (Baik) · S1/S2 IPS=8 (Baik)
Tianker 7    → 6 (Sedang)
Hanker −0,62 → 4 (Kurang)
Janker 7     → 6 (Sedang)
```
Diverifikasi cocok terhadap tabel + percabangan grup. Engine WAJIB reproduksi persis. Golden kedua (non-S1/S2) menyusul; sementara pakai sintetis, jangan tahan build.

### 6.7 Band narasi (Lampiran C psikolog)
`1–2 Poor · 3–4 Marginal · 5–6 Average · 7–8 Good · 9–10 Excellent`. Narasi 5 tingkat untuk skor 10 tingkat → peserta skor 7 & 8 dapat kalimat sama pada sub-aspek itu; pembeda hanya letak centang. Wajar, bukan bug.

## 7. Validasi (ringkas — detail di TEST_PLAN.md)

Regresi wajib: (a) IST/PAPI/RMIB skor ulang sampel historis == sumber; (b) Kraepelin faktor == golden §6.6; (c) invarian PAPI ROLE=45/NEED=45, RMIB=702, per-kelompok=78; (d) agregasi selalu 1–10; (e) golden laporan per band; (f) **knockout**: A/B/C=1 → Tidak Disarankan meski total ≥7; Job Interest=1 → label tak berubah; sub-aspek=2 → maksimal Dipertimbangkan; (g) **urutan** knockout-dulu-baru-ambang; (h) **aturan tepi sheet 14**: clamp IQ 77/132; TOLAK TIDAK SAH bila RW subtes>butir, capaian lajur>27, benar+salah≠capaian, ΣROLE≠45, ΣNEED≠45, rank≠702, rank/kelompok≠78; **CURIGA HITUNG** bila |Hanker|>Janker → tahan laporan; (i) batas kategori inklusif dua sisi.
