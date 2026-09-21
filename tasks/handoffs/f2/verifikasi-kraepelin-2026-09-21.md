# Verifikasi independen angka Kraepelin — 2026-09-21

Dicatat oleh Lead. Dokumen ini adalah bukti verifikasi yang diwajibkan
`tasks/handoffs/decisions/owner-decisions-2026-09-21.md` §1 ("ekstraksi WAJIB
disertai verifikasi ulang independen ... kolom per kolom") sebelum angka
Kraepelin boleh dipakai.

## Berkas

| Peran | Berkas | SHA-256 |
|---|---|---|
| Hasil ekstraksi (diverifikasi) | `outputs/kraepelin/Soal_LJK_Kraepelin_sel_editable.xlsx` | `f539602725a2cc6ca7ddfb5da71500d5edbe637183b62d1ca45158a47d74396e` |
| Citra lembar asli (pembanding) | `outputs/kraepelin/Soal_LJK_Kraepelin_per_halaman.xlsx`, sheet `Halaman 2`, gambar tertanam | — |
| Sumber hulu keduanya | `Soal _ Ljk Kraeplin (1).pdf`, halaman 2 | — |

SHA-256 grid angka saja (JSON 28×50, tanpa metadata berkas):
`6df51224c36bea665b9f8b0f8792139489f3c3204476089134657e3ae6ff9ee0`

Hash grid inilah yang harus dipakai sebagai patokan integritas saat ekstraksi
masuk ke data instrumen — bukan hash berkas xlsx, karena xlsx berubah hash-nya
oleh perubahan format yang tidak mengubah satu angka pun.

## Status asal-usul — PENTING, jangan dihapus dari catatan

Pemilik proyek menyatakan (2026-09-21) bahwa xlsx itu **hasil konversi otomatis
dari PDF**, bukan ketikan/verifikasi psikolog, dan memutuskan **"langsung
digunakan dulu"**. Jadi otoritas angka ini bertumpu pada verifikasi di bawah,
bukan pada tanda tangan psikolog.

## Metode verifikasi

Pembanding yang dipakai adalah **citra pindaian lembar asli** yang tertanam di
`Soal_LJK_Kraepelin_per_halaman.xlsx` (3863×1531 px), diekstrak lalu dipotong
per kelompok kolom dan diperbesar. Lead membaca angkanya langsung dari citra
itu, lalu hasilnya dibandingkan secara terprogram terhadap isi sel xlsx.

Ini independen terhadap alat yang membuat xlsx: sumber bacaannya citra, bukan
teks hasil ekstraksi. Batasnya: pembacaan Lead juga pembacaan citra oleh model,
bukan mata manusia. Kesalahan yang sama-sama dilakukan dua pembaca berbeda
mekanisme tidak mustahil, hanya sangat kecil kemungkinannya.

## Hasil

| Kelompok kolom | Sel dibandingkan | Selisih |
|---|---|---|
| 1–5 | 140 | 0 |
| 6–15 | 280 | 0 |
| 16–25 | 280 | 0 |
| 26–35 | 280 | 0 |
| 36–45 | 280 | 0 |
| 46–50 | 140 | 0 |
| **Total** | **1400** | **0** |

Seluruh 50 kolom × 28 angka cocok, tanpa kecuali.

Pemeriksaan struktur tambahan: 1400 sel terisi semua, tidak ada sel kosong,
semua nilai bilangan bulat 1–9, setiap kolom tepat 28 angka.

## Temuan struktur yang menyelesaikan pertanyaan 27 vs 28

Citra lembar memperlihatkan label baris berwarna merah bernomor **27 sampai 1**
yang posisinya **di antara** angka-angka, bukan sejajar dengannya, dan label
kolom **1 sampai 50** di bawah grid.

Jadi: **28 angka soal per kolom → 27 slot jawaban per kolom.** Keduanya benar
dan tidak bertentangan; peserta menjumlahkan tiap pasang angka bersebelahan.
`CLAUDE.md` yang menulis "27 baris jawaban" tetap benar dan tidak perlu diubah.

## Batas yang tetap berlaku

- Angka ini **data instrumen**. Masuknya hanya lewat `tools/extract/` + review
  (CLAUDE.md), tidak boleh ditulis tangan ke kode atau ke seeder.
- `outputs/` tidak terlacak git. Berkas sumber tidak dikomit apa adanya;
  yang dikomit adalah keluaran `tools/extract/` beserta hash grid di atas.
- Verifikasi ini menggantikan syarat "verifikasi ulang independen" pada
  keputusan §1. Ia TIDAK menggantikan tinjauan psikolog atas kesesuaian
  administrasi tes (50 kolom, 15 detik/kolom, jumlah dari bawah ke atas).
- Kalau suatu saat hash grid berubah, seluruh verifikasi ini batal dan harus
  diulang. Satu digit berbeda = skor berbeda untuk semua peserta, dan tidak
  ada test yang bisa menangkapnya.
