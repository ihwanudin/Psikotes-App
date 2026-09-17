# Spec: organization-billing

## Status dan objective

Lingkup dan batas pembayaran kolektif disetujui melalui jawaban "lanjutkan"
setelah tinjauan pada 2026-08-31. Pengguna mengonfirmasi kebutuhan:
cabang dapat memilih, misalnya, 10 peserta dan membayar sekali; bayar mandiri
tetap tersedia. Batas lama yang menunda tagihan gabungan tidak berlaku lagi.
Rencana teknis revisi disusun di tasks/organization-payment/; persetujuan ini
bukan bukti implementasi atau izin migrasi DB aktif/deploy/transaksi nyata.
Bergantung pada `funding-policy` dalam
[peta kapabilitas](CAPABILITY-MAP-organization-payment.md).
Mencatat pembayaran biaya tes kepada ONCAM dengan identitas pembayar yang benar.
Tidak mencatat hubungan utang peserta kepada lembaga.

## Aturan pembayaran dan batas modul

- Tagihan memiliki peserta/attempt penerima layanan dan pembayar yang terpisah:
  peserta sendiri atau lembaga yang ditetapkan kebijakan server.
- Mandiri: satu transaksi untuk biaya satu attempt peserta. Kolektif: satu
  tagihan induk untuk satu atau lebih attempt dari cabang/lembaga yang sama,
  dengan satu transaksi pembayaran untuk seluruh total. Rincian biaya dan
  alokasi pelunasan tetap dapat ditelusuri per attempt; bukan sepuluh invoice
  gateway individual yang sekadar ditampilkan dalam satu halaman.
- Harga paket, konsultasi, dan mata uang diambil dari database; simpan snapshot
  komponen harga saat reservasi biaya/tagihan dibuat. Jika record biaya sudah
  memiliki snapshot sah, penggabungan menggunakan snapshot itu, bukan menagih
  ulang dengan harga baru. Jangan mengubah tagihan lama saat harga
  katalog berubah. IDR menggunakan bilangan bulat, bukan perhitungan float.
- Kanal pembayaran tetap Xendit dan transfer manual sesuai sakelar aktifnya.
  Memilih lembaga sebagai pembayar bukan kanal pembayaran baru.
- Peserta dengan pembayar lembaga melihat ringkasan/status **Menunggu pembayaran
  lembaga**, bukan diminta membayar lagi. Admin lembaga hanya melihat tagihan
  milik lembaganya. Persetujuan lembaga mengambil tagihan tidak berarti lunas.
- Pembayaran diakui hanya dari webhook terautentikasi yang cocok dengan tagihan,
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

## Seleksi peserta dan pencegahan tagihan ganda

1. Admin cabang memilih attempt yang menjadi tanggungan organisasinya sendiri.
   Server memvalidasi ulang izin organisasi/client/sumber/paket untuk setiap
   item melalui funding-policy; satu item valid tidak mengesahkan seluruh batch.
   Sumber atau paket boleh berbeda selama pemetaan organisasinya sama dan
   setiap item memenuhi izin. Penggabungan lintas cabang tidak termasuk.
2. Identitas alokasi adalah attempt, bukan nama/nomor WA peserta. Satu attempt
   hanya muncul sekali. Tampilan harus menyebut paket dan periode/attempt agar
   dua tes sah milik orang yang sama tidak tertukar dengan duplikasi tagihan.
3. Item yang sudah lunas, dibayar mandiri, atau telah terikat tagihan/invoice
   aktif lain tidak dapat dipilih. Tidak memindahkan invoice mandiri yang masih
   hidup ke tagihan kolektif dan tidak mengubah pembayar melalui bulk action.
4. Ringkasan menampilkan peserta, paket, konsultasi bila dipilih, biaya per
   attempt, jumlah peserta, dan total IDR hasil server. Hanya snapshot final
   hasil validasi yang menjadi dasar invoice; total kiriman browser diabaikan.
5. Saat konfirmasi, reservasi seluruh daftar dilakukan atomik. Jika satu item
   berubah/tidak layak, tolak seluruh konfirmasi dan minta tinjau ulang; jangan
   diam-diam mengurangi peserta atau membuat invoice parsial. Replay permintaan
   yang sama mengembalikan tagihan yang sama; payload berbeda dengan key sama
   ditolak. Perebutan attempt antarbatches atau dengan mandiri harus diamankan
   oleh transaksi dan constraint database, bukan hanya checkbox nonaktif.
6. Daftar attempt, pembayar, snapshot biaya, dan total terkunci sejak reservasi
   yang dapat memicu invoice. Tagihan terbit tidak dapat diedit tambah/kurang
   peserta atau diganti pembayarnya. Daftar baru harus melalui proses baru yang
   tidak meninggalkan dua klaim pembayaran pada attempt yang sama.
7. Attempt bertotal nol ditandai gratis dan tidak dimasukkan ke tagihan berbayar.
   Jika semua pilihan gratis, tidak membuat invoice atau transfer kolektif;
   gunakan jalur gratis dengan prasyarat akses tetap berlaku. Tes DASS gratis
   dengan konsultasi berbayar mengikuti total komponennya, bukan nama tes saja.
8. Expired/rejected tidak berarti attempt langsung bebas ditagih ulang.
   Penggantian/cancel/reinvoice belum otomatis; petugas menangani rekonsiliasi
   dan memastikan invoice lama tidak dapat dibayar sebelum melepaskan reservasi.
   Timeout gateway berarti hasil belum pasti, bukan izin membuat invoice baru.

## Pelunasan kolektif dan akses tes

- Xendit menerbitkan satu invoice dengan reference tagihan induk dan total
  seluruh item. Transfer manual memakai satu bukti untuk tagihan induk. Jangan
  memperlakukan total batch sebagai pembayaran salah satu child order atau
  memalsukan sejumlah callback individual.
- Setelah bukti pembayaran terverifikasi, catat paid tagihan induk dan alokasi
  lunas untuk seluruh attempt tepat satu kali dalam transaksi yang konsisten.
  Kegagalan pembaruan satu item tidak boleh meninggalkan sebagian batch lunas.
  Simpan referensi audit dari pembayaran induk ke setiap alokasi.
- Pembayaran sebagian/lebih dari total tidak dialokasikan otomatis. Tidak ada
  saldo kredit, cicilan, atau pemilihan beberapa peserta untuk dilunasi dari
  transfer yang tidak cocok nominal; arahkan ke penanganan petugas ONCAM.
- Hak tes diproses per attempt yang lunas serta sudah memenuhi consent dan
  identitas. Bila seorang peserta belum memenuhi syarat, pembayarannya tetap
  lunas tetapi aksesnya tetap terkunci; peserta lain yang memenuhi syarat tidak
  ikut terhambat. Jangan meminta pembayaran kedua ketika consent dilengkapi.
- Event duplikat, retry worker, atau callback expired terlambat tidak mengubah
  paid ke status lebih rendah, tidak mengalokasikan dua kali, dan tidak mengirim
  aktivasi ganda. Efek eksternal dilakukan melalui outbox yang dapat diulang.
- Pembayaran sah untuk tagihan existing tetap dihormati saat izin pembayar
  dinonaktifkan. OFF menolak reservasi baru, bukan membatalkan transaksi lama.

## Menu cabang dan otorisasi

Menu target ini belum tersedia lengkap; jangan menyamakan spesifikasi dengan
fitur publik. Menu Peserta/undangan existing dipertahankan.

| Menu / aksi | Perilaku yang diperlukan |
| --- | --- |
| Peserta → Bayar terpilih | Filter peserta/attempt milik cabang; pilih beberapa yang layak; alasan tidak dapat dipilih terlihat |
| Ringkasan pembayaran kolektif | Daftar rincian dan total dari server; konfirmasi sebelum satu invoice dibuat |
| Tagihan Cabang | Daftar tagihan sendiri, jumlah peserta, total, jatuh tempo, status, dan aksi bayar sesuai kanal aktif |
| Detail tagihan | Rincian alokasi tiap attempt, status pembayaran, invoice atau unggah bukti; daftar terkunci |
| Riwayat pembayaran | Tampilan transaksi lunas dan bukti/referensinya dari sumber data tagihan yang sama |

- Cabang A tidak dapat melihat/mengubah/membayar tagihan cabang B melalui URL,
  query, ekspor, upload, atau request langsung. Scope batch dan setiap child
  harus sama; menebak ID child tidak boleh melewati pemeriksaan induk/RLS.
- Cabang hanya memilih/membayar/mengunggah bukti, bukan mengesahkan paid atau
  memberi dirinya izin organization. Role verifikator ONCAM harus eksplisit:
  jangan mengandalkan flag legacy can_verify_payments untuk mengizinkan cabang
  pembayar memverifikasi tagihannya sendiri.
- Peserta hanya melihat biaya/status attempt sendiri dan status menunggu
  lembaga, bukan daftar peserta lain, total batch, invoice/bukti cabang, atau
  data pembayaran anggota lain. Pembayaran tidak memberi akses klinis DASS.
- Audit mencakup aktor, organisasi, pemilihan/reservasi, snapshot, reference,
  verifikasi, dan alokasi; tidak mencatat credential atau data klinis.

## Tech stack dan project structure

Laravel 13, PostgreSQL RLS, Redis/outbox, Xendit provider yang sudah ada.
Gunakan `app/Actions/Payments/`, `app/Services/Payments/`, `app/Models/Order.php`,
`app/Models/Entitlement.php`, panel Filament, `database/migrations/`,
`tests/Feature/Payments/`, dan `tests/Feature/Admin/`.
Rencana teknis harus menentukan relasi tagihan induk, rincian biaya per attempt,
reservasi unik, serta alokasi pembayaran secara additive;
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
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
php vendor/bin/pint --parallel --test
php vendor/bin/phpstan analyse --no-progress
npm run lint:check
npm run types:check
```

Tambahkan kasus pembayar lembaga, RLS PostgreSQL, concurrency, timeout gateway,
replay, dan nominal/currency salah. Sandbox end-to-end memerlukan persetujuan
sebelum membuat invoice/notifikasi eksternal; tidak memakai data peserta nyata.
Tes kolektif belum dibuat: perintah suite yang lulus sekarang bukan bukti fitur
ini lulus. Rencana berikut wajib memecah tes schema, reservasi serentak,
pelunasan/alokasi, RLS, dan browser desktop/mobile. Build checkpoint memakai
output verifikasi terpisah sebagaimana docs/ORGANIZATION_PAYMENT_TESTING.md.

## Success criteria

1. Pembayar lembaga tidak menyebabkan checkout kedua kepada peserta.
2. Pending/rejected/expired tidak membuka akses tes berbayar.
3. Pembayaran terverifikasi membuka hanya hak tes milik order/attempt yang tepat.
4. Nominal dan currency salah, callback palsu, dan verifikasi lintas tenant ditolak.
5. Retry atau request paralel tidak membuat tagihan/aktivasi ganda.
6. Harga yang berubah di katalog tidak mengubah nilai invoice terbit.
7. Total nol tidak memanggil Xendit; tidak ada catatan utang peserta.
8. Pilih 10 attempt layak pada cabang yang sama → tepat satu invoice/transaksi;
   total sama dengan jumlah snapshot biaya 10 item, termasuk paket berbeda.
9. Pembayaran induk sah → 10 alokasi lunas tepat sekali. Crash/replay tidak
   menghasilkan sebagian paid atau double allocation; akses tetap per consent.
10. Dua admin memilih attempt yang sama secara bersamaan, atau terjadi race
    dengan mandiri, tidak menciptakan dua invoice aktif untuk attempt itu.
11. Satu item sudah lunas/terikat invoice lain/perizinannya berubah saat submit
    → seluruh batch ditolak tanpa invoice baru; daftar perlu ditinjau kembali.
12. Cabang tidak dapat memverifikasi pembayaran sendiri atau membuka data
    cabang lain; peserta tidak melihat anggota/nominal total/bukti batch.
13. Harga berubah, salah total, pembayaran parsial, timeout, invoice expired,
    callback duplikat/terlambat, dan perubahan daftar setelah terbit diuji.

## Batas disetujui dan tinjauan teknis

- Always: audit verifikasi, pemisahan pembayar/peserta, minimisasi informasi.
- Ask first: rincian skema/alokasi kolektif, konversi entitlement lama, refund,
  tempo, pembatalan/reinvoice otomatis, deploy, transaksi atau notifikasi nyata.
- Never: paid dari redirect browser, dana talang lokal, membuka akses karena
  payload sumber mengaku sudah membayar, akses laporan klinis bagi pembayar.
- Disetujui: satu cabang per tagihan, pelunasan seluruh total, daftar
  terkunci setelah reservasi, dan penanganan expired/reinvoice oleh petugas.
  Pembayaran kolektif termasuk lingkup; tempo/dana talang tetap tidak termasuk.
