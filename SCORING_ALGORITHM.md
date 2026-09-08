# SCORING ALGORITHM — psikotes.oncam.id

Versi kontrak: **SCORING-4.3.0**
Versi data normalisasi: **F2-2026.09**
Status: FINAL untuk implementasi F2

Dokumen ini adalah kontrak kanonik algoritme skoring. Perubahan metode wajib menaikkan versi kontrak dan data, dicatat di `CHANGELOG.md`, serta mengulang uji unit skoring dan golden F0. Seluruh norma, kunci, cutoff, zona optimal, dan mapping level dibaca dari data berversi; angka psikometri tidak disalin ke kode aplikasi.

## 1. Urutan sumber keputusan

Jika sumber lama bertentangan, gunakan urutan berikut:

1. keputusan produk pada PRD terbaru;
2. konfirmasi akhir psikolog untuk metode;
3. Tabel Lookup Skoring Psikotes v1.1 untuk angka dan band;
4. golden test sebagai bukti reproduksi;
5. dokumen teknis lama hanya untuk bagian yang tidak bertentangan.

Keputusan dan konsekuensi rekonsiliasi dicatat dalam ADR-0028.

## 2. Pipeline final

```text
jawaban mentah
  -> skor mentah instrumen
  -> skor sumber / level 1–5 berversi
  -> agregasi 18 aspek
  -> standar bidang GA-2026.08
  -> zona dan guardrail
  -> draf narasi
  -> tinjau dan tanda tangan psikolog
```

DASS-21 berjalan pada jalur terpisah dan tidak menjadi input zona atau label kelayakan.

## 3. IST

- Sembilan subtes dan 176 item: SE20, WA20, AN20, GE16, RA20, ZR20, FA20, WU20, ME20.
- Non-GE: benar bernilai 1 dari `keys`. GE: jawaban dinormalisasi dan dicocokkan ke `ge_dictionary` dengan nilai 0/1/2; jawaban tidak dikenal bernilai 0 dan dicatat.
- RW per subtes menjadi SW melalui tabel norma berversi. Batas domain mengikuti jumlah item; GE memiliki domain 0–32.
- SW menjadi level melalui kategori sheet 02: `≤80→1`, `81–94→2`, `95–104→3`, `105–118→4`, `≥119→5`.
- IQ: jumlah RW sembilan subtes menjadi IQ melalui sheet 03, kemudian skor 1–10 melalui sheet 04. Pasangan skor `[1,2]`, `[3,4]`, `[5,6]`, `[7,8]`, dan `[9,10]` masing-masing menjadi level 1–5. Mapping eksplisit `iq_level_bands` menghasilkan `IQ≤90→1`, `91–102→2`, `103–114→3`, `115–126→4`, `≥127→5`.
- Semua lookup harus menyimpan band, skor sumber, level, kategori, dan versi yang digunakan untuk audit.

## 4. PAPI Kostick

- 90 forced-choice menghasilkan 20 dimensi pada domain 0–9. Invarian: 45 ROLE, 45 NEED, dan setiap dimensi memiliki sembilan peluang skor.
- Normalisasi HPP memakai metode `distance_from_white_zone` dari konfirmasi akhir psikolog dan SPEC v4.3.
- Untuk zona putih `[lo,hi]`, jarak adalah `lo-raw` bila raw di bawah zona, `raw-hi` bila di atas, dan 0 bila di dalam. Data `distance_to_level` menetapkan `0→5`, `1→4`, `2→3`, `3→2`, dan `≥4→1`.
- Penyimpangan bawah dan atas simetris. Contoh acceptance W dengan zona `[4,7]`: raw 0/5/9 menghasilkan level 1/5/3.
- Dimensi G, I, X, dan Z tetap dihitung dan terlihat oleh psikolog, tetapi tidak masuk agregasi HPP. Enam belas dimensi lain dapat menjadi sumber aspek.
- Pita warna dan `band_scores` dari sheet 08 dipertahankan sebagai profil historis/visual, bukan input level HPP.

## 5. RMIB

- Sembilan kelompok masing-masing memakai rank unik 1–12; jumlah per kelompok 78 dan total seluruh respons 702.
- Skor kategori adalah jumlah sembilan rank. Nilai lebih kecil berarti minat lebih tinggi.
- Rank kategori memakai competition ranking setara formula Excel `RANK(...,1)`: total sama mendapat rank sama dan rank sesudahnya terlewati.
- Sheet 11 memberi rank→skor: `1→10`, `2→9`, `3→8`, `4→7`, `5→6`, `6→6`, `7→5`, `8→5`, `9→4`, `10→3`, `11→2`, `12→1`.
- Mapping `rank_to_level` memadatkan skor berpasangan: rank `1–2→5`, `3–4→4`, `5–8→3`, `9–10→2`, `11–12→1`.
- Lima kategori bidang tujuan menjadi D1–D5; tujuh kategori lain disimpan untuk tinjauan psikolog.

## 6. Kraepelin

- 50 kolom, 27 penjumlahan per kolom, 15 detik per kolom. Capaian adalah jumlah yang dikerjakan: `benar+salah`.
- `Panker = Σcapaian / 50`.
- `Tianker = Σsalah + Σterlewat`.
- `Janker = max(capaian) - min(capaian)`.
- `Hanker = b × 50`, dengan `b = (NΣXY - ΣXΣY) / (NΣX² - (ΣX)²)`.
- Sebelum lookup cutoff, setiap faktor dibulatkan ke tiga desimal dengan midpoint half-up. Nilai masukan dan nilai lookup setelah pembulatan disimpan bersama provenance.
- Capaian di atas 27, capaian yang tidak sama dengan benar+salah, atau capaian+terlewat di atas 27 ditolak. `abs(Hanker)>Janker` menghasilkan penanda tinjauan.
- Fallback S1/S2 IPS ke norma S1/S2 IPA hanya berlaku untuk Tianker dan Hanker, sesuai data.

Golden wajib:

- S1/S2: Σcapaian 793, salah 7, terlewat 0 → Panker 15,86; Tianker 7; Hanker -0,622; Janker 7.
- SMA/SMK: Σcapaian 656, salah 4, terlewat 1 → Panker 13,12; Tianker 5; Hanker 5,032; Janker 6.

## 7. DASS-21

- Item D = 3,5,10,13,16,17,21; A = 2,4,7,9,15,19,20; S = 1,6,8,11,12,14,18.
- Jumlah mentah setiap subskala dikali 2 lalu dibandingkan dengan cutoff DASS-42 yang inklusif.
- Kategori umum adalah kategori terberat dari D/A/S; seri mempertahankan semua subskala dasar.
- Input harus berisi tepat 21 respons integer 0–3. Set tidak lengkap, duplikat, item tidak dikenal, atau nilai di luar domain ditolak.
- Keluaran scorer tidak memuat zona, eligibility, label, diagnosis, atau rekomendasi kerja.

## 8. Agregasi 18 aspek

Setiap sumber telah menjadi level 1–5. Level dikonversi ke jangkar `{1:1,2:3,3:5,4:7,5:9}`, dirata-ratakan tanpa bobot, lalu dibulatkan half-up dan di-clamp ke 1–10. Level aspek akhir adalah `ceil(skor/2)`. Rincian sumber, jangkar, nilai sebelum pembulatan, nilai akhir, dan versi aturan wajib disimpan.

Komposisi aspek mengikuti SPEC v4.3 §5. D1–D5 memakai skor RMIB sheet 11 untuk kategori yang dipetakan. DASS-21 tidak memiliki jalur ke agregasi ini.

## 9. Zona dan rekomendasi

Level aspek dibandingkan dengan standar bidang berversi GA-2026.08:

- `level ≥ standar` → Terpenuhi;
- `level = standar-1` → Grey Area;
- `level ≤ standar-2` → Belum Terpenuhi.

Penetapan label dan guardrail G1–G9 mengikuti SPEC v4.3 §6. Aspek kritis adalah A1, B2, C4, dan C5. Standar, versi standar, level sistem, level final, zona, label sistem, label final, dan alasan override disimpan berdampingan.

## 10. Uji wajib sebelum perubahan diterima

- T-01 menguji seluruh batas IQ 90/91, 102/103, 114/115, dan 126/127.
- T-02 menguji seluruh batas SW.
- T-03 menguji 90 item PAPI, 20 dimensi, domain 0–9, serta invarian ROLE/NEED.
- T-04 menguji semua nilai 0–9 pada seluruh dimensi PAPI dan fixture W 0/5/9→1/5/3.
- T-05 menguji semua rank RMIB 1–12 dan competition tie.
- Kraepelin menguji dua golden, midpoint half-up, batas cutoff, dan penanda `abs(Hanker)>Janker`.
- T-07 membandingkan dua hasil psikotes identik dengan DASS Normal versus Sangat Parah dan mewajibkan zona serta label identik.
- T-08–T-11 menguji mapping, cutoff, kategori terberat, dan input DASS fail-closed.

Perintah minimum:

```powershell
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/Scoring
python -m unittest tools.extract.tests.test_f0 -v
php vendor/bin/pint --test app/Services/Scoring tests/Unit/Scoring
$env:APP_ENV='testing'; php vendor/bin/phpstan analyse --no-progress app/Services/Scoring tests/Unit/Scoring
```
