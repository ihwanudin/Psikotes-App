# ADR-0028: Prioritas sumber dan normalisasi psikometri F2

## Status

Accepted

## Date

2026-09-08

## Context

SPEC, dokumen algoritme lama, tabel acceptance pada spesifikasi teknis, dan data hasil ekstraksi memuat batas yang berbeda untuk IQ, PAPI, dan RMIB. Kraepelin juga belum menyebut urutan pembulatan secara eksplisit. Konflik ini menahan T-01, T-03, T-04, dan T-05 serta menghalangi F3 memakai hasil normalisasi yang stabil.

Dokumen pendukung memberi bukti yang lebih kuat:

- konfirmasi akhir psikolog menetapkan PAPI dengan jarak dari zona putih;
- Tabel Lookup Skoring Psikotes v1.1 menetapkan skor IQ pada sheet 04 dan skor RMIB pada sheet 11;
- workbook RMIB memakai formula Excel `RANK(...,1)`;
- golden Kraepelin membuktikan Panker memakai seluruh capaian benar+salah dan Hanker memakai `b×50`;
- cutoff Kraepelin tiga desimal membentuk domain diskret tanpa celah hanya jika faktor dibulatkan ke tiga desimal sebelum lookup.

## Decision

Urutan sumber ketika terjadi konflik adalah PRD terbaru, konfirmasi akhir psikolog, Tabel Lookup v1.1, golden test, lalu dokumen teknis lama untuk bagian yang tidak bertentangan.

Kontrak normalisasi F2 ditetapkan sebagai berikut:

- IQ mengikuti sheet 04 dan pasangan skor 1–10 ke level 1–5: `≤90→1`, `91–102→2`, `103–114→3`, `115–126→4`, `≥127→5`.
- PAPI HPP memakai jarak dari zona putih dengan mapping `0→5`, `1→4`, `2→3`, `3→2`, `≥4→1`. Pita warna lama dipertahankan hanya untuk profil historis/visual.
- Dimensi PAPI G, I, X, dan Z tidak masuk agregasi HPP, tetapi tetap dihitung dan tersedia bagi psikolog.
- RMIB mengikuti skor sheet 11; rank 1–2, 3–4, 5–8, 9–10, dan 11–12 masing-masing menjadi level 5, 4, 3, 2, dan 1.
- Total kategori RMIB yang sama memakai competition ranking, sama dengan formula `RANK(...,1)`.
- Panker memakai capaian benar+salah. Faktor Kraepelin dibulatkan half-up ke tiga desimal sebelum lookup; nilai masukan dan nilai lookup disimpan untuk audit.
- Kontrak dan data berubah ke `SCORING-4.3.0` dan `F2-2026.09`.

## Alternatives Considered

### Memakai tabel acceptance lama secara harfiah

Ditolak karena contoh IQ, PAPI W, dan RMIB rank 8 bertentangan dengan tabel lookup atau formula metode yang lebih baru.

### Memakai pita warna PAPI sebagai level HPP

Ditolak karena konfirmasi akhir menetapkan jarak dari zona putih. Pita warna tetap dipertahankan agar data historis tidak hilang.

### Menahan semua rank RMIB ketika ada total sama

Ditolak karena workbook sumber sudah menetapkan competition ranking. Menahan seluruh hasil akan menyimpang dari keluaran Excel yang menjadi referensi operasional.

### Membandingkan faktor Kraepelin tanpa pembulatan

Ditolak karena band tiga desimal menggunakan batas inklusif dengan langkah 0,001; angka di antara dua batas tidak akan cocok dengan band mana pun.

## Consequences

- T-01, T-03, T-04, dan T-05 dapat diimplementasikan dan diuji tanpa angka tertanam di PHP.
- Fixture PAPI W berubah dari contoh lama 2/5/2 menjadi hasil formula yang konsisten 1/5/3.
- RMIB dengan total seri tetap menghasilkan rank auditable dan tidak memerlukan tie-breaker berdasarkan urutan kategori.
- Perubahan aturan berikutnya harus membuat versi data baru; versi lama tidak boleh ditimpa.
- F3 baru boleh memakai normalisasi setelah kontrak ini dan seluruh uji batasnya diterima.
