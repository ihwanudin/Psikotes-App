# ADR-0013: DASS-21 menjadi komponen wajib paket psikotes

## Status

Accepted

## Date

2026-09-05

## Context

Katalog sebelumnya menyediakan DASS-21 sebagai layanan gratis mandiri dan memberi peserta pilihan ikut atau menolak ketika mendaftar. Keputusan produk terbaru menetapkan DASS-21 sebagai bagian dari psikotes, bukan produk yang dipilih terpisah.

## Decision

- Setiap paket yang tersedia untuk pendaftaran harus memuat sedikitnya satu instrumen psikotes utama dan DASS-21.
- Paket DASS-21 mandiri dihapus dari katalog awal dan paket lama berkode `DASS21` dinonaktifkan saat seeder dijalankan.
- Pendaftaran meminta satu persetujuan DASS-21 wajib, tanpa opsi ikut/tolak.
- Penyimpanan, akses, retensi, pelaporan, dan skoring DASS tetap terpisah. DASS tidak boleh memengaruhi zona atau label kelayakan.
- Hak penarikan persetujuan dan penghapusan data DASS setelah pendaftaran tetap dipertahankan tanpa mengubah hasil psikotes utama.

## Alternatives Considered

### Mempertahankan DASS-21 sebagai paket gratis mandiri

Ditolak karena membuat DASS tampak sebagai produk terpisah dan memungkinkan rangkaian psikotes berjalan tanpa komponen yang kini diwajibkan.

### Menambahkan DASS otomatis tetapi mempertahankan pilihan penolakan saat registrasi

Ditolak karena masih menjadikan pelaksanaan DASS opsional pada alur yang ditetapkan sebagai satu rangkaian.

## Consequences

- Katalog lama perlu diselaraskan dengan menjalankan `TestPackageSeeder`; harga yang telah diubah admin tidak ditimpa.
- Paket custom atau integrasi yang tidak memuat DASS tidak dapat digunakan pada registrasi publik sampai komposisinya diperbaiki.
- Perubahan naskah consent menghasilkan versi dan hash baru sehingga penerimaan selalu merujuk pada naskah terbaru.
