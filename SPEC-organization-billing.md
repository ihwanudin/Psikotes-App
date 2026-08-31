# Spec: organization-billing

## Status dan objective

DRAFT untuk ditinjau; bergantung pada `funding-policy` dalam
[peta kapabilitas](CAPABILITY-MAP-organization-payment.md).
Mencatat pembayaran biaya tes kepada ONCAM dengan identitas pembayar yang benar.
Tidak mencatat hubungan utang peserta kepada lembaga.

## Aturan pembayaran dan batas modul

- Tagihan memiliki peserta/attempt penerima layanan dan pembayar yang terpisah:
  peserta sendiri atau lembaga yang ditetapkan kebijakan server.
- Usulan tahap awal: satu tagihan per attempt, termasuk konsultasi bila dipilih
  dan diizinkan. Tagihan gabungan lintas peserta belum masuk tahap awal.
- Harga paket, konsultasi, dan mata uang diambil dari database; simpan snapshot
  komponen harga saat order dibuat. Jangan mengubah tagihan lama saat harga
  katalog berubah. IDR menggunakan bilangan bulat, bukan perhitungan float.
- Kanal pembayaran tetap Xendit dan transfer manual sesuai sakelar aktifnya.
  Memilih lembaga sebagai pembayar bukan kanal pembayaran baru.
- Peserta dengan pembayar lembaga melihat ringkasan/status **Menunggu pembayaran
  lembaga**, bukan diminta membayar lagi. Admin lembaga hanya melihat tagihan
  milik lembaganya. Persetujuan lembaga mengambil tagihan tidak berarti lunas.
- Pembayaran diakui hanya dari webhook terautentikasi yang cocok dengan order,
  nominal, mata uang, dan referensi; atau verifikasi transfer oleh petugas ONCAM
  berwenang. Lembaga pembayar tidak dapat mengesahkan pembayarannya sendiri.
- Keluaran untuk checkout/akses: referensi order, pembayar, rincian tagihan,
  status pembayaran, dan keputusan kelayakan akses yang ditentukan server.
  UI tidak dapat menulis status paid/ready.
- Entitlement berbayar tetap locked sampai pembayaran sah. Transisi pembayaran,
  pembukaan akses, dan outbox aktivasi harus konsisten dan idempotent.
- Pembayaran sudah sah tidak diturunkan menjadi expired oleh callback terlambat.
  Callback duplikat tidak menggandakan entitlement, tagihan, atau notifikasi.
- Paket dengan total nol melewati gateway melalui jalur gratis yang eksplisit;
  tidak direkayasa sebagai transfer lembaga. Syarat persetujuan tetap berlaku.
- Hak tes dari attempt lama tidak boleh membuka attempt baru yang belum dibayar.
  Pemetaan order/entitlement per attempt wajib diuji sebelum rilis.
- Tempo tidak tersedia pada tahap ini. Tidak ada pinjaman, cicilan, denda,
  pengembalian peserta, atau pencairan uang dalam model data baru.

## Tech stack dan project structure

Laravel 13, PostgreSQL RLS, Redis/outbox, Xendit provider yang sudah ada.
Gunakan `app/Actions/Payments/`, `app/Services/Payments/`, `app/Models/Order.php`,
`app/Models/Entitlement.php`, panel Filament, `database/migrations/`,
`tests/Feature/Payments/`, dan `tests/Feature/Admin/`.
Rencana teknis harus menentukan relasi pembayar dan attempt secara additive;
jangan hanya menaruh identitas pembayar dalam metadata tanpa foreign key/otorisasi.

## Code style

Strict PHP, transaksi database, enum status yang eksplisit, Policy/Gate, dan
tipe uang integer. Pola pemeriksaan yang sudah dipakai proyek:

```php
Gate::forUser($admin)->authorize('verifyPayment', $order);
```

Panggilan gateway tidak dilakukan dalam transaksi panjang; hasil tidak pasti
direkonsiliasi dengan referensi tetap, bukan membuat invoice kedua sembarangan.

## Commands dan testing strategy

Gunakan data sintetis dan gateway fake untuk Unit/Feature:

```powershell
php artisan config:clear --ansi
php artisan test --filter=XenditWebhookTest
php artisan test --filter=Payment
.\vendor\bin\pint.bat --parallel --test
.\vendor\bin\phpstan.bat analyse
php artisan test
```

Tambahkan kasus pembayar lembaga, RLS PostgreSQL, concurrency, timeout gateway,
replay, dan nominal/currency salah. Sandbox end-to-end memerlukan persetujuan
sebelum membuat invoice/notifikasi eksternal; tidak memakai data peserta nyata.

## Success criteria

1. Pembayar lembaga tidak menyebabkan checkout kedua kepada peserta.
2. Pending/rejected/expired tidak membuka akses tes berbayar.
3. Pembayaran terverifikasi membuka hanya hak tes milik order/attempt yang tepat.
4. Nominal dan currency salah, callback palsu, dan verifikasi lintas tenant ditolak.
5. Retry atau request paralel tidak membuat tagihan/aktivasi ganda.
6. Harga yang berubah di katalog tidak mengubah nilai invoice terbit.
7. Total nol tidak memanggil Xendit; tidak ada catatan utang peserta.

## Boundaries dan open review

- Always: audit verifikasi, pemisahan pembayar/peserta, minimisasi informasi.
- Ask first: skema/alokasi attempt, konversi entitlement lama, refund, tempo,
  tagihan gabungan, deploy, transaksi atau notifikasi nyata.
- Never: paid dari redirect browser, dana talang lokal, membuka akses karena
  payload sumber mengaku sudah membayar, akses laporan klinis bagi pembayar.
- Untuk ditinjau: batas tahap awal tagihan per peserta; fasilitas gabungan/tempo
  tidak termasuk implementasi awal. Ketentuan penagihan bukan nasihat hukum.
