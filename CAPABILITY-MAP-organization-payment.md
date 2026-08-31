# Pembayaran peserta integrasi dan lembaga

## Status dan keputusan pengguna

Lingkup fungsional dikonfirmasi dalam percakapan: ONCAM hanya menangani **bayar
sendiri** dan **dibayar lembaga**. Dana talang, persetujuan pinjaman, pencairan,
cicilan, pengembalian, dan sisa utang peserta menjadi urusan sistem lembaga.
Modul `advance-repayment` dari usulan awal dibatalkan, bukan pekerjaan tertunda.
Spesifikasi teknis di bawah masih **DRAFT — menunggu tinjauan pengguna**.
Dokumen ini tidak membuktikan fitur sudah diterapkan atau diaktifkan.

Revisi lingkup 2026-08-31: pengguna menegaskan pembayaran kolektif cabang
(contoh 10 peserta, satu transaksi) serta menu cabang pendukung masuk lingkup.
Revisi ini tetap milik organization-billing; tidak menambah modul atau mengubah
arah dependensi. P1–P3 sudah diuji lokal, tetapi billing kolektif belum dibangun.

| Module id | Tanggung jawab | Bergantung pada | Spesifikasi |
|---|---|---|---|
| funding-policy | Pilihan pembayar yang diizinkan lembaga dan sumber integrasi | — | [SPEC-funding-policy.md](SPEC-funding-policy.md) |
| organization-billing | Tagihan mandiri/kolektif, alokasi per attempt, dan portal tagihan cabang | funding-policy | [SPEC-organization-billing.md](SPEC-organization-billing.md) |
| integrated-checkout | Handoff data seleksi, ringkasan, checkout, dan cabang terkunci | funding-policy, organization-billing | [SPEC-integrated-checkout.md](SPEC-integrated-checkout.md) |

Urutan: `funding-policy` → `organization-billing` → `integrated-checkout`.
Billing menyediakan keputusan pembayaran; tidak bergantung pada UI checkout.

## Landasan yang sudah ada

- `ProvisionAssessmentParticipant` memvalidasi allow-list sumber/paket/funding,
  tetapi saat ini membuat entitlement `ready` tanpa order pembayaran.
- `ProvisionSelectionParticipant` juga membuat entitlement `ready` tanpa order.
- `RegisterParticipant` sudah mengambil harga paket/konsultasi dari database;
  alur komersial memiliki order dan entitlement locked/ready.
- `ReferralAttribution` memakai first-touch cookie; branch peserta disimpan saat
  registrasi. Cookie bukan bukti identitas dan bukan kunci lintas browser.
- Verifikasi Xendit dan transfer manual sudah tersedia; pakai ulang aturan
  autentikasi, pencocokan nominal, idempotensi, serta auditnya.

Temuan ini berasal dari working tree, termasuk perubahan integrasi yang belum
di-commit. Jangan menyimpulkan image Docker publik sudah memuat kode tersebut.

## Usulan batas tahap awal untuk ditinjau

- Pembayaran mandiri per attempt atau satu tagihan kolektif milik satu cabang
  untuk beberapa attempt. Rincian dan hak tes tetap terpisah per attempt.
- Bayar sebelum tes; fasilitas tempo tidak diaktifkan dan tidak dibangun pada
  tahap awal. Pengaktifan tempo nanti membutuhkan spesifikasi tersendiri.
- Tidak mengubah harga saat ini, skoring, norma, atau akses laporan psikologis.
- Persetujuan dokumen ini tidak memberikan izin migrasi data aktif, deploy,
  mengirim tagihan/notifikasi nyata, atau mengubah Xendit menjadi Live Mode.

## Gerbang selanjutnya

Tinjau spesifikasi per modul, lalu susun rencana dan tugas teknis. Implementasi
dimulai setelah gerbang tersebut diterima. Uji integrasi menggunakan data
sintetis; tidak membangun ulang image dari perubahan lain yang belum ditinjau.
