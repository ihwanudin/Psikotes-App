# ADR-0027: Paket DASS-21 mandiri dan komposisi paket psikotes utama

## Status

Accepted

## Date

2026-09-08

## Context

ADR-0013 menghapus paket DASS-21 mandiri ketika DASS-21 ditetapkan sebagai bagian dari rangkaian psikotes. Keputusan produk terbaru mempertahankan paket mandiri tersebut, tetapi tetap menghendaki agar peserta paket psikotes utama menjalani komposisi yang sudah ditentukan server tanpa pilihan tambah atau hapus DASS-21.

## Decision

- Paket berkode `DASS21` tetap berada dalam katalog sebagai paket mandiri gratis dan dapat diaktifkan atau dinonaktifkan oleh `super_admin`.
- Setiap paket psikotes utama memuat DASS-21 serta sedikitnya satu instrumen psikotes utama. Komposisi ini ditentukan server dan tidak dapat diubah oleh request peserta.
- Nama paket dan antarmuka peserta tidak menampilkan narasi yang menekankan DASS-21 sebagai pilihan yang dipaksakan.
- Consent DASS-21 tetap dicatat terpisah. Penyimpanan, akses, retensi, pelaporan, dan skoring DASS tetap terisolasi serta tidak memengaruhi zona atau label kelayakan.
- Seeder menyelaraskan nama dan komposisi kanonis tanpa menimpa harga atau status aktivasi yang telah diatur admin.

## Alternatives Considered

### Menghapus paket DASS-21 mandiri

Tidak dipilih karena DASS-21 tetap dibutuhkan sebagai layanan skrining yang dapat diambil tanpa paket psikotes utama.

### Menjadikan DASS-21 add-on yang dapat dilepas

Tidak dipilih karena komposisi paket psikotes utama harus konsisten dan tidak boleh dimanipulasi oleh klien.

## Consequences

- Katalog publik dapat menampilkan DASS-21 mandiri sebagai layanan gratis ketika statusnya aktif.
- Paket utama dapat memakai nama yang ringkas karena komposisi instrumen berasal dari katalog server, bukan dari narasi atau kontrol klien.
- Pengujian harus membuktikan paket DASS-21 mandiri berfungsi dan semua paket utama tetap menghasilkan entitlement DASS-21.
- ADR-0013 dipertahankan sebagai riwayat dan ditandai telah disupersesi.
