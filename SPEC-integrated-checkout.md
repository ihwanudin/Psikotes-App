# Spec: integrated-checkout

## Status dan objective

DRAFT untuk ditinjau; bergantung pada `funding-policy` dan `organization-billing`
dalam [peta kapabilitas](CAPABILITY-MAP-organization-payment.md).
Peserta dari `seleksi.beasiswajepang.id` dan `seleksi.serbaindo.com` tidak mengisi
ulang identitas yang sudah lengkap dan terverifikasi dari integrasi server.
Ini sasaran implementasi, bukan klaim kedua situs sudah terhubung end-to-end.

## Alur dan penguncian data

1. Server sumber mengirim data melalui kontrak integrasi yang terautentikasi,
   terikat ke organisasi/sumber/paket. Domain pengunjung bukan bukti keaslian.
2. Handoff memakai tautan ber-token dengan masa berlaku dan perlindungan replay.
   Data pribadi/credential tidak dimasukkan ke query string atau log.
3. Peserta melihat ringkasan identitas, cabang, paket, pembayar, dan nominal.
   Lengkapi hanya field wajib yang benar-benar kurang; data sumber yang terkunci
   dikoreksi melalui sumber/admin berwenang, bukan mengganti identitas lokal.
4. Persetujuan psikotes ditampilkan/dicatat bila belum ada bukti persetujuan yang
   berlaku. DASS tetap pilihan terpisah; persetujuan tidak dianggap otomatis
   hanya karena identitas lengkap. Tidak mengesahkan naskah legal draft.
5. Bayar sendiri menuju metode pembayaran aktif. Dibayar lembaga menuju status
   tagihan lembaga. Gratis/sudah lunas tidak membuat invoice baru.
6. Pembukaan akses mengikuti keputusan pembayaran server dan prasyarat
   identitas/persetujuan yang sudah berlaku; tidak melewati tahapan wajib lain.

## Cabang dan undangan

- Integrasi menetapkan cabang dari pemetaan server. Cookie referral tidak boleh
  menimpa cabang peserta yang sudah terikat pada organisasi sumber.
- Tautan umum `/r/{kode-cabang}` tetap untuk pendaftaran publik first-touch;
  setelah peserta tersimpan, `branch_id` bukan field yang boleh diedit peserta.
- Untuk peserta tertentu gunakan undangan terikat peserta/organisasi. Link
  cabang umum tidak menjamin penguncian orang yang belum dikenal lintas browser.
- Link invalid/expired/terpakai ditolak dengan pesan aman, bukan membuat akun
  baru di cabang default. Undangan checkout harus dapat dipakai sebelum paid;
  jangan menggunakan undangan mulai-tes yang hanya menerima status READY tanpa
  membedakan tujuan dan hak akses token.
- Peserta dengan order pending bisa melanjutkan order yang sama melalui sesi
  sah. Reissue handoff terkontrol tidak menciptakan participant/order kedua.
- Ada data order aktif, jangan mengizinkan ganti pembayar atau paket tanpa
  penanganan pembatalan/reconciliation eksplisit pada modul billing.

## Tech stack dan project structure

Inertia 3 + React 19 untuk peserta, Filament untuk staf, Laravel/PostgreSQL RLS.
Rujukan: `app/Http/Controllers/SelectionLaunchController.php`,
`app/Http/Controllers/AssessmentInvitationController.php`,
`app/Actions/Integrations/`, `app/Http/Controllers/ParticipantRegistrationController.php`,
`app/Services/Referral/`, `resources/js/pages/`, dan `tests/Feature/`.
Tidak mengubah form publik menjadi portal untuk semua peserta secara otomatis.

## Code style

Validasi dan otorisasi di server; React menampilkan state, bukan menghitung
nominal tagihan atau memutuskan lunas. Strict types dan respons privat seperti
pola yang sudah dipakai controller handoff:

```php
return response()->json(['participantToken' => $participantToken])
    ->header('Cache-Control', 'no-store, private');
```

Contoh bukan izin memperluas hak token; token checkout dan mulai-tes wajib
memiliki tujuan/hak yang dibatasi dan diuji.

## Commands dan testing strategy

```powershell
php artisan config:clear --ansi
php artisan test --filter=Selection
php artisan test --filter=OrganizationInvitationTest
php artisan test --filter=Referral
php artisan test
.\vendor\bin\pint.bat --parallel --test
.\vendor\bin\phpstan.bat analyse
npm run lint:check
npm run types:check
npm run build
```

Unit/Feature menggunakan data sintetis. Uji browser: profil lengkap/tidak lengkap,
checkout pending/paid/gratis, navigasi ulang, mobile, error token, dan consent.
Uji PostgreSQL terpisah untuk RLS; jangan menjalankan migrasi test pada DB aktif.

## Success criteria

1. Peserta integrasi valid dengan profil lengkap tidak melihat form identitas ulang.
2. Profil kurang lengkap hanya diminta melengkapi kekurangannya, tanpa akun ganda.
3. Manipulasi branch/organization/payer/package/nominal di browser ditolak.
4. Sumber lain atau cookie referral tidak mengalihkan cabang peserta terikat.
5. Invalid/replay/expired token tidak memberi akses peserta lain maupun tes unpaid.
6. Paid/gratis melewati gateway, pending melanjutkan order yang sama.
7. Dibayar lembaga menampilkan status lembaga, tanpa tagihan pengembalian peserta.
8. Persetujuan dan privasi DASS tidak dilewati atau dibagikan kepada pembayar.

## Boundaries dan open review

- Always: HMAC/identitas sumber terverifikasi, RLS, idempotensi, data minimum.
- Ask first: perubahan sistem seleksi eksternal, kontrak handoff legacy, migrasi
  peserta existing, aktivasi sumber, deploy publik dan notifikasi nyata.
- Never: percaya `Referer`, auto-merge orang lintas lembaga dari email/telepon,
  hardcode harga, menerima flag paid dari browser, menonaktifkan guard consent.
- Untuk ditinjau: ringkasan dan persetujuan tetap ada sebelum checkout; tidak
  langsung melempar peserta ke gateway tanpa mengetahui paket/pembayarnya.
