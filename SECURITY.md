# SECURITY.md (v2.1) — implementasi poin A, B, C list arsitektur

## Auth & sesi
- Peserta: login `nomor_tes + tanggal_lahir` → JWT HS256 TTL 12 jam tanpa refresh token (sesi tes pendek; login ulang murah). Header wajib `typ=participant+jwt`; signature, algoritme tetap, `iss`, `aud`, `sub`, `{participant_id, branch_id}`, `iat`, `nbf`, `exp`, serta durasi token divalidasi. Secret wajib random `base64:` minimal 32 byte. Magic link/OTP TIDAK dipakai untuk peserta — menambah dependensi email/HP saat ujian; keputusan sadar, bukan kelalaian.
- Admin: sesi Laravel/Filament email+password; role di `admins` (`super_admin`, `branch_admin`, `staff`, `psychologist`) dan kemampuan verifikasi pembayaran diperiksa server-side.
- Cookies (web admin): httpOnly, Secure, SameSite=Lax. API stateless Bearer.
- Rate limit Laravel memakai cache Redis bersama: login peserta 5/menit/IP. Kegagalan per nomor tes disimpan sebagai key HMAC (bukan nomor mentah) dan mengunci 60 detik mulai kegagalan ketiga, 5 menit pada kegagalan berikutnya, lalu 15 menit; login berhasil menghapus riwayat kegagalan nomor tersebut. Webhook dan registrasi juga dibatasi.

## Data
- Enkripsi: TLS in-transit; at-rest mengikuti volume/layanan PostgreSQL dan object storage. Kontrol utama data terstruktur = role runtime non-owner, RLS ketat, retensi, dan audit akses; object storage tetap private dengan signed URL.
- RLS: lihat DATABASE_SCHEMA.md. Kerahasiaan antar-peserta dijamin di DB, bukan UI.
- Schema DASS terpisah (`dass.*`), retensi dua tahun, dan tidak diberikan kepada admin non-psikolog. Role runtime memiliki `NOBYPASSRLS`; migrasi memakai credential owner terpisah.
- Controller yang membaca/menulis data tenant wajib mengimplementasikan `RequiresRlsContext` dan route-nya wajib memakai middleware `rls`; architecture test menolak kombinasi yang tidak lengkap. Job tenant wajib mengimplementasikan `ProvidesRlsContext` dan memasang `ApplyRlsContextToJob`.
- Konteks `role`, `branch_id`, dan `participant_id` hanya berasal dari principal/job internal, dipasang dengan `set_config(..., true)` dalam transaksi, dan dibersihkan pada sukses maupun exception. Header atau parameter request tidak pernah menjadi sumber konteks RLS.
- Middleware bearer peserta memverifikasi token, keberadaan peserta aktif, dan kecocokan branch claim terhadap database sebelum membentuk principal. Endpoint `/api/me`, entitlement, dan start-session tidak menerima participant ID dari request.
- Panel Filament memakai guard session `admin` dan model `Admin`, bukan guard peserta/web. Kemampuan didefinisikan server-side; policy peserta menolak akses lintas cabang walaupun ID diketahui, dan hanya psikolog yang memperoleh kemampuan membaca DASS.
- Default session admin: cookie `Secure`, `HttpOnly`, `SameSite=Lax`, masa idle 120 menit. Nilai produksi tetap dinyatakan eksplisit melalui environment server.
- Referral first-touch disimpan dalam cookie terenkripsi `Secure`/`HttpOnly`/`SameSite=Lax` selama 30 hari. Payload kedaluwarsa atau tidak valid gagal tertutup dan diresolusi ulang di server; browser tidak menjadi sumber `branch_id`.
- Audit referral menyimpan IP serta maksimal 512 karakter user-agent hanya sampai `expires_at` 30 hari. Route publik menulis audit melalui transaksi berkonteks `service`, bukan dengan memperluas policy RLS publik.
- Signed URL (object storage S3-compatible) TTL 15 menit, diterbitkan hanya setelah cek hak. Tidak ada URL permanen.
- Bukti identitas disimpan pada disk `identity` privat (local private pada development, S3-compatible pada production). Key acak 64 karakter tidak memuat nama peserta/nama file asli. Foto dokumen dan selfie divalidasi dari isi/magic bytes, format JPG/PNG/WebP, ukuran ≤5 MB, dan dimensi 480–8.000 px; upload dibatasi 5/menit per sesi+IP.
- URL bukti identitas diterbitkan setelah policy peserta dan konteks RLS admin lulus, dibatasi 30 permintaan/menit/admin, dan setiap penerbitan menulis audit service-only. Konteks audit hanya memuat jenis bukti dan waktu kedaluwarsa URL, bukan PII, URL, atau object key.
- Bukti transfer manual disimpan pada disk `payment-proofs` privat dengan key acak yang tidak memuat nama asli. Validasi memeriksa isi/MIME sekaligus ekstensi JPG/JPEG/PNG/PDF dan membatasi ukuran ke 5.000 KB; upload dibatasi 5/menit per sesi+IP dan tidak menerima order ID dari browser.
- URL bukti transfer berlaku 15 menit, hanya dapat diterbitkan kepada admin dengan kemampuan verifikasi dalam scope cabang, dan setiap penerbitan diaudit tanpa URL/object key. Approve/reject melakukan policy check ulang di domain action, row lock, dan perbandingan konstan-waktu terhadap key bukti yang dilihat sehingga penggantian file saat review gagal tertutup.
- Hasil matcher adalah marker (`pending|match|mismatch|error`) dengan status manual tetap `pending`; mismatch/error tidak menghapus peserta, tidak mengubah entitlement, dan tidak otomatis menentukan kelayakan.
- Audit log: aktivasi metode pembayaran, verifikasi order, void sesi, penerbitan URL bukti identitas/transfer, akses/unduh laporan oleh admin, perubahan rate fee, keputusan withdraw, perubahan kamus GE. Aktivasi kanal hanya dapat dilakukan super admin dan auditnya ditulis atomik melalui konteks service.
- Event pembayaran dinormalisasi di adapter dan dicocokkan melalui ID invoice (`gateway_ref`), reference order (`external_id`), nominal, dan currency. Callback Xendit memakai perbandingan konstan-waktu `x-callback-token`; token/payload/status salah hanya menerima error generik. Event terautentikasi diklaim atomik oleh unique `(provider,event_id)` dan hanya intent hash yang disimpan—bukan payload mentah. Transisi terminal tidak dapat ditimpa; hanya pending→paid pertama yang membuka entitlement, dengan ledger event, order, dan entitlement diperbarui dalam satu transaksi service-RLS.

## Anti-kecurangan
- Timer server-side (satu-satunya sumber waktu); jawaban di luar `ends_at+grace 10s` ditolak.
- One-attempt lock: partial unique index sesi aktif; mengulang = void admin (teraudit).
- Randomisasi soal: HANYA Kraepelin (seeded). IST/PAPI/RMIB TIDAK diacak — instrumen ternorma dengan urutan baku; mengacak merusak komparabilitas norma. (Koreksi atas poin B10 list.)
- Deteksi: tab-switch (visibilitychange), fullscreen-exit, PrintScreen keydown (terbatas — Snipping Tool/HP lolos, didokumentasikan), perubahan IP, disable copy/paste & klik kanan di halaman soal, watermark nomor tes, soal dirender per halaman.
- Foto webcam maks 5/sesi (mulai + ≤3 sampel acak + submit).

## Infrastruktur
- Secrets hanya via `.env` di server (tak pernah di git); `.env.example` tanpa nilai.
- Service account Drive: scope `drive.file` saja, akses terbatas Shared Drive arsip.
- CORS: allowlist `https://psikotes.oncam.id` (+ staging); metode & header eksplisit; tanpa wildcard.
- Dependabot/`pnpm audit` di CI; header keamanan (CSP dasar, X-Frame-Options DENY kecuali runner sendiri).
