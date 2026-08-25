# SECURITY.md (v2.0) — implementasi poin A, B, C list arsitektur

## Auth & sesi
- Peserta: login `nomor_tes + tanggal_lahir` → JWT HS256 TTL 12 jam, claim `{participant_id, branch_id}`; tanpa refresh token (sesi tes pendek; login ulang murah). Magic link/OTP TIDAK dipakai untuk peserta — menambah dependensi email/HP saat ujian; keputusan sadar, bukan kelalaian.
- Admin: sesi Laravel/Filament email+password; role di `admins` (`super_admin`, `branch_admin`, `staff`, `psychologist`) dan kemampuan verifikasi pembayaran diperiksa server-side.
- Cookies (web admin): httpOnly, Secure, SameSite=Lax. API stateless Bearer.
- Rate limit (Workers KV/DO): login peserta 5/menit/IP + lockout progresif per nomor_tes; webhook & registrasi juga dibatasi.

## Data
- Enkripsi: TLS in-transit; at-rest mengikuti volume/layanan PostgreSQL dan object storage. Kontrol utama data terstruktur = role runtime non-owner, RLS ketat, retensi, dan audit akses; object storage tetap private dengan signed URL.
- RLS: lihat DATABASE_SCHEMA.md. Kerahasiaan antar-peserta dijamin di DB, bukan UI.
- Schema DASS terpisah (`dass.*`), retensi dua tahun, dan tidak diberikan kepada admin non-psikolog. Role runtime memiliki `NOBYPASSRLS`; migrasi memakai credential owner terpisah.
- Signed URL (object storage S3-compatible) TTL 15 menit, diterbitkan hanya setelah cek hak. Tidak ada URL permanen.
- Validasi input server-side: zod di setiap endpoint; file upload dibatasi tipe/ukuran (bukti transfer ≤5 MB jpg/png/pdf; foto proctor ≤200 KB).
- Audit log: verifikasi order, void sesi, akses/unduh laporan oleh admin, perubahan rate fee, keputusan withdraw, perubahan kamus GE.

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
