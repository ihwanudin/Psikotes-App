# Implementation Plan: F1 Fondasi dan Aktivasi Peserta

## Overview

F1 membangun satu aplikasi Laravel dengan dua permukaan: Inertia.js + React untuk peserta dan Filament/Livewire untuk admin, staf, dan psikolog. Hasil F0 dimuat sebagai data read-only. Jalur lengkap yang harus hidup adalah referral first-touch, registrasi dan consent, verifikasi identitas awal, pembayaran Xendit atau transfer manual, aktivasi entitlement, notifikasi, lalu login peserta. PostgreSQL RLS menjadi batas keamanan utama dan wajib diuji dari awal.

## Source-of-truth decisions

- `SPEC.md` v4 menang atas bagian lama `PANDUAN-EKSEKUSI.md` dan `SECURITY.md` yang masih menyebut Supabase, Workers, atau memindahkan Xendit ke fase lain.
- Xendit Invoice termasuk F1; transfer manual tetap tersedia berdampingan.
- Auth peserta memakai nomor tes + tanggal lahir dengan JWT kustom TTL 12 jam. Filament memakai sesi Laravel.
- DASS-21 tetap tersedia sebagai paket mandiri; setiap paket psikotes utama menyertakannya otomatis tanpa pilihan tambah/hapus. Consent B, schema/tabel, dan policy tetap terpisah serta DASS tidak memengaruhi kelayakan.
- Nomor tes, status pembayaran, entitlement, dan atribusi referral ditetapkan server-side.
- Pencocokan wajah memakai interface `IdentityMatcher`. Penyimpanan foto dan alur tinjauan manual dapat dibangun sekarang; implementasi otomatis tidak dipilih sampai provider/algoritme disetujui.
- JSON F0 adalah sumber seed yang sah. Angka instrumen tidak disalin ke kode aplikasi.

## Dependency graph

```text
Toolchain + repository
  -> Laravel shell + test harness
    -> PostgreSQL roles/schema/migrations
      -> RLS context middleware + isolation tests
        -> admin auth + branch scope
        -> referral/registration/consent/identity
          -> participant credentials + entitlements
            -> payment adapters
              -> Xendit webhook/manual verification
                -> notification + status pages
                  -> F1 end-to-end gate
```

## Architecture decisions

- Gunakan role database terpisah untuk migrasi/owner dan aplikasi. Role aplikasi bukan pemilik tabel; tabel bertenant memakai `ENABLE ROW LEVEL SECURITY` dan `FORCE ROW LEVEL SECURITY`.
- Konteks RLS (`role`, `branch_id`, `participant_id`) dipasang dengan `set_config(..., true)` di dalam transaksi request/job agar tidak bocor lewat koneksi yang digunakan ulang.
- Endpoint publik hanya boleh melakukan operasi sempit melalui service/action yang tervalidasi; tidak memperoleh bypass RLS umum.
- Payment gateway berada di balik `PaymentProvider`; webhook dicatat dengan event ID unik dan perubahan order + entitlement + komisi dilakukan dalam satu transaksi.
- Setiap kanal pembayaran memiliki record konfigurasi `is_active`. Kanal nonaktif tidak ditampilkan dan tidak boleh menerima order baru; order yang sudah ada tetap dapat dibaca dan diproses secara idempotent. Semua kanal default nonaktif sampai konfigurasi operasionalnya lengkap.
- Bukti transfer dan foto identitas disimpan private di S3-compatible storage; validasi MIME, magic bytes, ukuran, nama objek acak, dan signed URL berumur pendek.
- Notifikasi WAHA/n8n berjalan lewat queue dan tidak menentukan keberhasilan pembayaran. Outbox/idempotency mencegah pengiriman ganda.
- Nomor tes dibangkitkan dan dikunci database; reset sequence bulanan dijalankan Laravel Scheduler.

## Threat model ringkas

### Assets

- PII peserta, tanggal lahir, foto identitas, dan consent.
- Status pembayaran, entitlement, komisi, serta atribusi cabang.
- Kredensial admin, JWT peserta, callback token Xendit, dan secret layanan.

### Trust boundaries

- Form registrasi/referral publik, upload multipart, dan login peserta.
- Sesi Filament dan setiap route bertenant.
- Webhook Xendit, antrean Redis, WAHA/n8n, S3, dan PostgreSQL.

### Abuse cases yang wajib menjadi test

- Admin cabang membaca atau mengubah peserta cabang lain.
- Route bertenant dijalankan tanpa konteks RLS.
- Webhook palsu/duplikat mengaktifkan entitlement atau komisi lebih dari sekali.
- Klien memaksa kode metode pembayaran yang sedang nonaktif.
- Referral kedua menimpa referral first-touch.
- Brute force nomor tes + tanggal lahir.
- File berkedok gambar/PDF atau file terlalu besar lolos upload.
- Paket DASS mandiri atau komposisi paket psikotes utama dimanipulasi melalui request klien.
- Job queue membawa konteks tenant yang salah atau menulis PII ke log.

## Task list

### Phase A - Reproducible foundation

1. Repository and toolchain baseline.
2. Laravel application shell.
3. Development container topology.
4. F0 instrument seeding.

### Checkpoint A

- Aplikasi boot, test suite berjalan, lockfile tunggal tersedia, dan health check DB/Redis hijau.

### Phase B - Tenant security foundation

5. Core tenant schema.
6. PostgreSQL RLS enforcement.
7. RLS request and job context.
8. Filament identity and roles.

### Checkpoint B

- Tes negatif membuktikan branch admin, peserta, dan request tanpa konteks tidak dapat membaca data di luar scope.

### Phase C - Participant activation slices

9. Referral first-touch.
10. Registration and consent.
11. Private identity evidence.
12. Participant credentials and entitlement gate.
13. Payment provider contract.
14. Payment method activation.
15. Xendit invoice and webhook.
16. Manual transfer verification.
17. Notification outbox and status page.

### Checkpoint C

- Registrasi menghasilkan order locked; Xendit terverifikasi atau verifikasi manual mengubah entitlement tepat sekali; peserta menerima notifikasi dan dapat login.

### Phase D - F1 gate

18. End-to-end security and acceptance suite.
19. F1 operations documentation.

## Verification strategy

- PHPUnit/Pest feature tests untuk HTTP, auth, payment, referral, consent, dan upload.
- PostgreSQL integration tests memakai role aplikasi nyata; SQLite tidak boleh menjadi bukti RLS.
- Contract tests untuk fake payment/notification/storage adapters; sandbox Xendit hanya setelah credential tersedia.
- Browser smoke test untuk registrasi, status pembayaran, Filament verification, dan login peserta.
- `composer audit`, audit package manager frontend, lint, typecheck, dan secret scan sebelum checkpoint akhir.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| RLS salah karena aplikasi memakai table owner | Sangat tinggi | Role migrasi dan aplikasi dipisah; `FORCE RLS`; tes dengan role runtime nyata |
| Konteks tenant tertinggal di koneksi | Sangat tinggi | Transaksi + `set_config(..., true)`; tes request berurutan lintas cabang |
| DOB sebagai faktor login mudah ditebak | Tinggi | Rate limit 5/menit/IP, lockout progresif per nomor tes, nomor tes berentropi cukup, audit gagal login |
| Webhook duplikat atau dipalsukan | Tinggi | Callback-token constant-time check, event ID unik, transaksi, state transition allowlist |
| Foto identitas bocor | Tinggi | Bucket private, object key acak, signed URL, akses teraudit, retensi eksplisit |
| Provider face matching belum dipilih | Sedang | Interface + manual-review flow; provider otomatis menjadi keputusan terpisah |
| Docker belum tersedia di PATH | Sedang | Bootstrap dapat memakai Laragon; checkpoint container menunggu Docker tersedia |
| Teks consent final belum direview hukum | Tinggi untuk go-live | Simpan consent versioned; tandai blokir go-live tanpa mengarang teks legal |

## Open questions requiring human confirmation

- Provider/algoritme pencocokan wajah otomatis dan dasar pemrosesan biometrik. Rekomendasi sementara: capture + tinjauan manual, dengan interface provider tetapi tanpa keputusan otomatis.
- Endpoint yang dipakai untuk notifikasi: WAHA langsung atau webhook n8n.
- Credential sandbox Xendit, S3-compatible storage, dan layanan notifikasi tersedia kapan.

## Rencana implementasi Task 17

1. Tambahkan deduplication key unik pada outbox dan enqueue notifikasi aktivasi di transaksi pertama `pending→paid`; dispatch job hanya setelah commit.
2. Tambahkan kontrak notifier, fake deterministik, adapter webhook n8n fail-closed, worker retry dengan audit/telemetry tanpa PII, serta scheduler pemulihan outbox tertinggal.
3. Tambahkan status order berbasis JWT pada `/api/me/order` dan halaman Inertia berbasis sesi registrasi yang selalu menurunkan participant dari principal/sesi, bukan ID request.
4. Jalankan focused tests per irisan, review keamanan/idempotensi, browser smoke bila runtime lokal tersedia, lalu gerbang PHP/frontend/audit penuh dan sinkronkan dokumentasi.

Keputusan batas provider: n8n menjadi adapter produksi karena webhook dapat diwajibkan menduplikasi `idempotency_key` sebelum memanggil WAHA. Adapter langsung `POST /api/sendText` tidak dipakai pada F1 karena dokumentasi WAHA tidak menjamin idempotensi untuk outcome timeout yang tidak diketahui.
- Docker Desktop/Engine akan dipasang lokal atau development awal dijalankan via Laragon.
- Teks final consent A/B dan masa retensi consent perlu review hukum sebelum go-live.

## Plan approval gate

Implementasi dimulai hanya setelah rencana ini ditinjau. Persetujuan berarti menyetujui bahwa F1 mengikuti `SPEC.md` v4, mencakup Xendit dan transfer manual, serta memakai alur manual-review untuk wajah sampai provider otomatis diputuskan.
