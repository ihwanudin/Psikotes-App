# Spec: funding-policy

## Status dan objective

DRAFT untuk ditinjau. Modul pertama pada
[peta kapabilitas](CAPABILITY-MAP-organization-payment.md).
Tujuan: admin ONCAM menentukan pembayar yang diperbolehkan per lembaga, tanpa
menyamakan jenis pembayar dengan metode pembayaran maupun status lunas.

## Aturan dan keluaran untuk modul pemakai

- Dua label produk: **Bayar sendiri** dan **Dibayar lembaga**. Tidak ada menu,
  field, API baru, atau pencatatan untuk dana talang/pengembalian peserta.
- Konfigurasi tersimpan di database dan dikelola admin ONCAM yang berwenang;
  admin lembaga tidak boleh memberi lembaganya sendiri hak fasilitas pembiayaan.
- Pada lembaga baru, usulan default: bayar sendiri aktif, dibayar lembaga
  nonaktif. Tidak otomatis mengubah konfigurasi lembaga yang sudah ada.
- Untuk integrasi, pilihan efektif adalah irisan izin lembaga, izin sumber
  integrasi aktif, dan paket yang diizinkan. Konfigurasi tidak dikenali ditolak.
- Jika sumber menetapkan pembayar, peserta tidak dapat menggantinya lewat form.
  Jika sumber mengizinkan pilihan, tampilkan hanya pilihan efektif.
- Identitas pembayar lembaga berasal dari pemetaan server yang terautentikasi,
  bukan `Referer`, nama domain, cookie umum, atau `organization_id` kiriman browser.
- Keluaran kebijakan: pilihan yang diperbolehkan, pembayar terpilih/terkunci,
  identitas lembaga pembayar, serta alasan penolakan yang aman. Tidak menerbitkan
  entitlement dan tidak mengubah order menjadi paid.
- `COMMERCIAL_SELF_PAY`, `SPONSORED`, `INVOICED_TO_ORGANIZATION`, `INTERNAL`,
  dan `WAIVED` sudah ada dalam kode/kontrak. Jangan mengganti arti historisnya
  secara massal. Rencana implementasi harus menyediakan adapter/versi kontrak
  eksplisit; tidak ada nilai legacy yang otomatis membuktikan pembayaran.
- Menonaktifkan pilihan mencegah order baru, bukan membatalkan pembayaran yang
  sudah sah. Perubahan konfigurasi dicatat dengan aktor dan waktu.
- Pembayaran kolektif cabang memakai payer organization yang sama, bukan enum
  pembayar ketiga. Billing memvalidasi setiap attempt dengan policy server
  terbaru saat reservasi batch. Memilih sepuluh peserta tidak boleh mengabaikan
  lock/izin salah satu sumber; satu item tidak layak menolak seluruh konfirmasi.
  Snapshot tagihan existing yang sah tetap berlaku saat policy kemudian OFF.

## Tech stack dan project structure

Tetap Laravel 13/PHP 8.3+, Filament 5, PostgreSQL RLS. Tidak menambah dependency.
Rujukan: `app/Models/Branch.php`, `app/Models/IntegrationSource.php`,
`app/Filament/Resources/IntegrationSources/`, `app/Actions/Integrations/`,
`tests/Feature/Admin/`, dan `tests/Feature/Integrations/`.
Perubahan skema bersifat additive dan dirinci pada tahap rencana, bukan di sini.

## Code style

PHP strict types, tipe eksplisit, Form Request untuk input dan Policy/Gate untuk
otorisasi. Contoh pola yang sudah dipakai proyek:

```php
declare(strict_types=1);

if (! in_array($input['fundingMode'], $organizationFunding, true)) {
    throw new IntegrationContractViolation('FUNDING_MODE_NOT_ALLOWED');
}
```

## Commands dan testing strategy

Jalankan dari root pada lingkungan test terisolasi dengan vendor development:

```powershell
php artisan config:clear --ansi
php artisan test --filter=GenericAssessmentProvisioningTest
php artisan test --filter=IntegrationRegistryManagementTest
.\vendor\bin\pint.bat --parallel --test
.\vendor\bin\phpstan.bat analyse
php artisan test
```

Tambahkan pengujian kebijakan di Unit dan Feature. Tes SQLite tidak cukup untuk
membuktikan RLS; verifikasi lintas tenant juga pada PostgreSQL test terpisah.
Tidak menjalankan RefreshDatabase terhadap database peserta aktif.

## Success criteria

1. Opsi nonaktif tidak tampil dan permintaan yang dipalsukan ditolak server.
2. Admin lembaga A tidak dapat mengubah/membaca kebijakan privat lembaga B.
3. Mengubah pembayar, sumber, atau cabang lewat browser tidak melewati pemetaan.
4. Tidak ada opsi dana talang maupun akses otomatis dari label sponsored.
5. Request replay tetap idempotent; perubahan kebijakan tidak menggandakan order.
6. Kontrak lama memiliki pengujian kompatibilitas dan jalur migrasi eksplisit.

## Boundaries dan open review

- Always: validasi server, RLS eksplisit, audit, data uji sintetis.
- Ask first: perubahan kontrak legacy/skema, migrasi data existing, deploy publik.
- Never: hardcode harga, simpan secret dalam dokumen, aktifkan tempo terselubung.
- Untuk ditinjau: default lembaga baru dan strategi mempertahankan kontrak lama.
