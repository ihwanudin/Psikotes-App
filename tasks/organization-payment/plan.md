# Rencana: pembayaran lembaga dan checkout terintegrasi

Tanggal: 2026-08-31
Status: **DRAFT PLAN — menunggu tinjauan; belum izin implementasi atau deploy.**

## Lingkup dan status persetujuan

Pengguna meminta melanjutkan setelah usulan lokasi terpisah. Rencana ini berada
di `tasks/organization-payment/`; `tasks/plan.md` dan `tasks/todo.md` F1 tidak
diubah. Persetujuan lingkup fungsional menjadi dasar perencanaan, bukan bukti
fitur selesai. Rincian skema/kompatibilitas di bawah masih usulan untuk disetujui.

Sumber: `CAPABILITY-MAP-organization-payment.md`, `SPEC-funding-policy.md`,
`SPEC-organization-billing.md`, dan `SPEC-integrated-checkout.md`.
Checklist calon tugas berada di [todo.md](todo.md). Setelah plan diterima,
validasi rincian tugas sebelum implementasi per modul.

Hanya dua pilihan: bayar sendiri atau dibayar lembaga. Harga paket/konsultasi
berasal dari database dalam IDR. Dana talang, cicilan, tempo, tagihan gabungan,
refund, skoring dan perubahan sistem seleksi eksternal tidak termasuk.
Lembaga pembayar tahap awal adalah organisasi sumber yang terautentikasi;
sponsor lintas organisasi memerlukan perluasan spesifikasi.

## Bukti kondisi kode saat penyusunan

- `ProvisionAssessmentParticipant` membuat entitlement ready tanpa order.
- `ProvisionSelectionParticipant` memiliki jalur akses ready legacy.
- Constraint entitlement lama unik pada participant + test_type; gate
  `ParticipantEntitlementGate::assertReady` mencari hanya dua atribut itu.
  Akses lama tidak boleh menjadi bukti lunas attempt baru.
- `Order` belum memiliki pembayar lembaga atau foreign key attempt.
- Status assessment yang diizinkan PostgreSQL tidak memuat WAITING_PAYMENT.
- Undangan assessment sekarang untuk mulai tes, bukan checkout sebelum bayar.
- Banyak file integrasi masih untracked/modified. Ini temuan working tree,
  bukan bukti image Docker publik menjalankan implementasi yang sama.

## Urutan dan dependensi

```text
funding-policy: konfigurasi -> keputusan server -> kontrol admin
  -> organization-billing: isolasi attempt -> order -> pembayaran -> panel lembaga
    -> integrated-checkout: handoff terbatas -> ringkasan/consent -> checkout
      -> regresi lintas tenant, browser, dokumentasi dan review
```

Kerjakan berurutan. Schema, gate akses, dan billing berbagi data sehingga tidak
dikerjakan paralel. Tidak membuat agent/task terpisah. Setiap checkpoint perlu
bukti focused tests, build, alur slice, dan tinjauan pengguna.

## Usulan teknis untuk ditinjau

### 1. funding-policy: pisahkan pembayar dari kanal pembayaran

Tambahkan konfigurasi payer pada Branch dan IntegrationSource secara additive:
allowed_payer_types (self/organization), serta locked_payer_type pada sumber.
Gunakan null untuk konfigurasi existing yang belum dipetakan; null tidak memberi
izin checkout baru. Organisasi baru default self saja; organization OFF.
Sumber baru jalur checkout tetap nonaktif sampai dikonfigurasi admin ONCAM.

Keputusan server adalah irisan organisasi, sumber aktif, dan paket yang diizinkan.
Simpan snapshot keputusan saat order dibuat. Menonaktifkan opsi menolak order
baru; pembayaran sah dan penyelesaian order pending existing tetap dihormati.
Unknown/forged mode ditolak; konfigurasi diaudit. Otorisasi bukan sekadar UI.

Usulkan kontrak checkout versi baru yang opt-in, terpisah dari v1.
COMMERCIAL_SELF_PAY dapat dipetakan eksplisit ke self dan
INVOICED_TO_ORGANIZATION ke organization pada adapter yang disetujui.
SPONSORED/INTERNAL/WAIVED tidak dipetakan otomatis dan tidak membuktikan paid.
Existing v1 tetap tercatat sebagai legacy, tidak diklaim memenuhi aturan baru.
Cutover sumber memerlukan koordinasi; tidak menyisakan fallback v1 bagi sumber
yang sudah dipindahkan. Tidak mengubah arti atau data historis massal.

### 2. organization-billing: hak tes khusus per attempt

Usulan paling aman: tabel baru assessment_entitlements, bukan mengubah unique
constraint entitlement legacy. Kolom utama: assessment_participant_id FK,
order_id FK, test_type, status, ready_at/started_at/completed_at.
Unique pada assessment_participant_id + test_type. RLS mengikuti organisasi dan
peserta assessment, tanpa memberikan akses DASS klinis kepada pembayar.

Tambahkan order.assessment_participant_id nullable FK unik (satu order per attempt
tahap awal), payer_type nullable untuk data legacy, payer_organization_id nullable
FK ke branches. Untuk order baru: self berarti payer_organization_id null;
organization wajib FK organisasi sumber yang sama. Penerima layanan tetap
participant_id. Validasi kecocokan participant/attempt/organisasi pada transaksi
dan constraint yang dapat ditegakkan database. Harga integer dan snapshot
komponen disimpan saat order dibuat; tidak dihitung ulang setelah invoice terbit.

Order expired/rejected ditampilkan sebagai terminal dan diarahkan ke petugas;
penggantian order/cancel/reinvoice belum otomatis. Jangan membuat order kedua
atau mengganti pembayar/paket saat order aktif. Penanganan perubahan ini perlu
rancangan tersendiri jika diminta.

Gunakan status assessment PROVISIONED selama prasyarat belum lengkap; status
pembayaran dibaca dari order, bukan menambah enum assessment secara spontan.
Akses ready membutuhkan pembayaran sah atau total nol, consent yang berlaku,
dan prasyarat identitas. Lunas tidak berarti consent otomatis.
Gate jalur baru wajib context attempt terautentikasi, tidak fallback ke gate
participant + test_type. Token checkout tidak bisa dipakai sebagai token tes.

Invoice dibuat setelah transaksi reservasi order selesai; gateway fake dahulu.
Gunakan reference stabil untuk retry/reconciliation dan cegah invoice ganda.
Pembayaran, aktivasi entitlement attempt, dan outbox harus atomik/idempotent.
Webhook Xendit tetap cocok token, nominal, currency, dan reference. Callback
expired terlambat tidak menurunkan paid. Jalur total nol tidak memanggil gateway.

Transfer manual diverifikasi petugas ONCAM yang berwenang, bukan lembaga pembayar.
Panel lembaga hanya menampilkan ringkasan tagihan sendiri, kanal aktif, invoice/
unggah bukti sesuai izin; tidak memberi akses hasil klinis. Role ONCAM dan
lembaga harus dapat dibedakan secara eksplisit sebelum fitur diaktifkan.

### 3. integrated-checkout: ringkasan tanpa registrasi ulang

Handoff server-to-server memetakan identitas eksternal dalam scope sumber/
organisasi. Tidak menggabungkan orang lintas tenant dari email atau nomor WA.
Token checkout disimpan sebagai hash, berumur pendek, sekali konsumsi, tujuan
terbatas. Konsumsi menghasilkan sesi checkout dengan CSRF dan scope attempt.
No-store/no-referrer, token tidak dicatat di log atau diteruskan ke gateway.
Reissue terkontrol mencabut token lama dan tidak menggandakan order.

Profil lengkap langsung ke ringkasan + persetujuan. Profil kurang hanya meminta
field yang kurang; sumber/cabang/identitas terkunci tidak dapat diganti peserta.
Kontrak baru harus mengakomodasi profil parsial tanpa melonggarkan validasi v1.
DASS consent terpisah, bukan otomatis disetujui dan bukan syarat kelayakan kerja.

Self menuju kanal aktif; organization melihat menunggu pembayaran lembaga;
paid/gratis tidak membuat invoice ulang. Reload melanjutkan sesi/order yang sama.
Referral publik tetap first-touch; undangan peserta invalid tidak fallback
ke registrasi default. Kedua situs seleksi diuji dengan fake client dahulu.

## Verification dan lingkungan

Semua perintah berikut dijalankan dari root pada lingkungan development/test
terisolasi, bukan container publik. Pastikan PHP/dependency tersedia, config cache
test bersih, database test eksplisit, dan outbound gateway/notifier fake.
Jangan menjalankan composer setup atau migrasi reset terhadap database aktif.

```powershell
php artisan test --filter=GenericAssessmentProvisioningTest
php artisan test --filter=SelectionParticipantProvisioningTest
php artisan test --filter=OrganizationInvitationTest
php artisan test --filter=Payment
php artisan test --filter=Referral
php artisan test
.\vendor\bin\pint.bat --parallel --test
.\vendor\bin\phpstan.bat analyse
npm run lint:check
npm run types:check
npm run build
```

Perintah focused tests baru tercantum dalam calon tugas; sebelum tes dibuat,
filter kosong bukan bukti lulus. Catat jumlah tes/assertion serta skip.
PostgreSQL RLS dan concurrency harus dibuktikan pada database khusus test:
role runtime bukan owner/BYPASSRLS, FORCE RLS, lintas tenant dan tanpa context
ditolak. Harness aman disiapkan sebelum migrasi uji; SQLite tidak menggantikannya.
Browser mobile/desktop: lengkap/parsial, self/organization/free, reload, token
expired/replay, consent ditolak, cabang terkunci, dan akses unpaid ditolak.

## Risiko dan mitigasi

| Risiko | Mitigasi / gerbang |
|---|---|
| Hak lama membuka attempt unpaid | Entitlement attempt terpisah, gate tanpa fallback, tes dua attempt |
| v1 memberi ready tanpa order | Versi opt-in, cutover eksplisit dan larangan fallback sumber v2 |
| Lembaga mengesahkan bayar sendiri | Policy ONCAM verifier + tes role/IDOR/RLS |
| Timeout/replay menagih dua kali | Reservasi unik, reference stabil, reconcile sebelum retry |
| Consent terlewati karena sudah lunas | Gate gabungan, tes paid tetapi consent belum lengkap |
| Data aktif terkena test/migrasi | Harness test terpisah, guard target, tanpa deploy otomatis |
| Checklist F1 belum seluruhnya tertutup | Gerbang F1 tetap berdiri sendiri; tidak dicentang dari rencana ini |

## Review yang diminta

Setujui/ubah: versi kontrak opt-in; hak tes per attempt terpisah; satu order
per attempt; organisasi sumber sebagai pembayar; tidak ada reinvoice otomatis.
Persetujuan rencana mengizinkan pendetailan tugas dan proposal migrasi lokal,
bukan menjalankan migrasi data aktif, deploy, Xendit Live, invoice atau WA nyata.

## Bukti penyusunan

Hanya dokumentasi. Kode aplikasi, konfigurasi layanan, database, harga, dan
checklist F1 tidak diubah. Pemeriksaan struktur dokumen dan diff dilakukan;
test fitur/build belum dijalankan karena belum ada implementasi.
