# Prompt Awal Proyek — psikotes.oncam.id
*(Salin-tempel blok di bawah sebagai pesan pertama ke Claude Code / Codex / agen AI lain. Pastikan seluruh berkas dokumen berada di root repo.)*

---

## CARA PAKAI (baca dulu, jangan ikut disalin)
1. Taruh semua berkas `.md` dan `.docx` di root repo. **Ganti nama `SPEC_v4.md` → `SPEC.md`** agar cocok dengan rujukan.
2. Pastikan proyek Laravel kosong (atau `laravel new`) sudah/akan diinisialisasi di repo yang sama — agen akan menambah struktur Laravel standar.
3. Untuk **Claude Code**: `CLAUDE.md` dibaca otomatis. Cukup tempel blok prompt di bawah.
4. Untuk **Codex / agen lain**: awali dengan menyuruhnya membaca `CLAUDE.md` lebih dulu (sudah tercakup di prompt).
5. Jangan minta lebih dari satu fase per sesi. Setelah F0, minta bukti gerbang sebelum lanjut.

---

## ================== SALIN MULAI DARI SINI ==================

Kamu adalah engineer yang mengerjakan **psikotes.oncam.id** — sistem psikotes daring untuk seleksi CPMI tujuan Jepang. Ini proyek dengan spesifikasi lengkap dan matang; tugasmu MENGIKUTI spesifikasi, bukan mereka-reka ulang.

### Stack (FINAL, jangan diubah tanpa persetujuan eksplisit)
- **Backend:** Laravel (PHP), satu aplikasi.
- **Frontend peserta** (5 instrumen tes — kaya interaksi/timer): **Inertia.js + React**.
- **Panel admin/staf/psikolog:** **Filament (Livewire)** — CRUD, verifikasi bayar, layar tinjauan & tanda tangan laporan, modul komisi.
- **Database:** PostgreSQL dengan Row-Level Security, di-host di VPS/managed Postgres (**bukan Supabase**) — RLS **tidak otomatis** di luar BaaS, konteks (branch_id/role) WAJIB disuntik lewat middleware Laravel kustom di setiap request yang relevan.
- **Object storage:** generik S3-compatible (driver Flysystem S3) untuk PDF/foto/bukti — primer. Google Shared Drive sebagai arsip async (bukan jalur kritis).
- **Queue & cache:** Redis, dikonsumsi Laravel Queue worker (render PDF via Browsershot/Puppeteer headless, rakit narasi, sinkron Drive, notifikasi WAHA/n8n).
- **Scheduler:** Laravel Scheduler (cron) untuk expiry order, retensi data, reset sequence nomor tes.
- **Deployment:** Docker Compose di VPS/server tradisional — kontainer terpisah untuk `app` (PHP-FPM+Nginx), `queue`, `redis`, `postgres`; Postgres & Redis privat, tidak menghadap publik.
- **Pembayaran:** Xendit Invoice (webhook `x-callback-token`, idempotent) sebagai kanal utama; transfer manual sebagai kanal permanen berdampingan (bukan fallback sementara).
- **Notifikasi:** WhatsApp via WAHA, disarankan lewat n8n untuk template yang mudah diubah.

### Langkah 0 — Orientasi (lakukan dulu, jangan menulis kode apa pun)
Baca berkas berikut di root repo, berurutan, dan konfirmasikan pemahamanmu secara ringkas sebelum mulai:
1. `CLAUDE.md` — aturan kerja yang MENGIKAT sepanjang proyek. Patuhi tanpa kecuali.
2. `SPEC.md` — sumber kebenaran fungsional (arsitektur, model data, skoring, model Grey Area, DASS, proctoring, uji penerimaan, fase build F0–F9).
3. `PANDUAN-EKSEKUSI.md` — urutan fase & definisi gerbang tiap fase.
4. `SCORING_ALGORITHM.md` — logika skoring kanonik (rujuk saat mengerjakan skoring).
5. Sekilas: `ARCHITECTURE.md`, `DATABASE_SCHEMA.md`, `API_CONTRACT.md`, `TEST_PLAN.md`, `DEPLOYMENT.md`.

Setelah membaca, tuliskan ringkasan 5–8 baris: apa produk ini, apa model penilaiannya (skala 1–5, Grey Area, aspek kritis A1/B2/C4/C5), mengapa DASS-21 terpisah, dan konfirmasi stack (Laravel+Inertia+React+Filament+Postgres, BUKAN Cloudflare/Next.js/Supabase — beberapa dokumen versi lama mungkin masih menyebut stack lama di bagian sejarah/changelog; abaikan itu, ikuti stack di atas). Ini untuk memastikan konteks termuat sebelum mulai.

### Aturan keras (ringkasan dari CLAUDE.md — jangan dilanggar)
- **Kerjakan HANYA F0 di sesi ini.** Jangan membuat struktur aplikasi Laravel/Inertia/Filament penuh sebelum gerbang F0 LULUS. Ini disengaja: kesalahan skoring yang lolos F0 baru ketahuan setelah peserta nyata mengerjakan — terlambat.
- **Jangan menebak logika skoring.** Semua norma, kunci, kamus, ambang, dan tabel ada di berkas master/data. Bila tidak jelas → BERHENTI dan tanya, jangan mengarang.
- **RLS harus eksplisit.** Setiap route/controller yang menyentuh data bertenant WAJIB melalui middleware penyuntik konteks. Jangan asumsikan Postgres otomatis mengisolasi seperti Supabase.
- **Skoring/timer server-authoritative.** (Relevan mulai F2, tapi camkan sejak awal.)
- **Jangan commit data peserta nyata / secrets.** Data uji = sintetis. Secrets hanya di `.env` server, tak pernah di git.
- **Hanker = b×50** (b = slope regresi 50 lajur). Jangan pakai b mentah atau (Panker+Janker)/2.
- **Jangan mengacak** urutan soal MAUPUN opsi jawaban IST/PAPI/RMIB (baterai paten). Hanya Kraepelin memakai angka seeded.
- **DASS-21 tidak pernah** memengaruhi zona/label kelayakan (guardrail G4). Skor subskala DASS tidak dicetak di laporan HPP.
- Paket DASS-21 mandiri tetap tersedia; paket psikotes utama menyertakan DASS-21 otomatis tanpa kontrol tambah/hapus pada pemilihan paket.
- Commit kecil & sering; branch per fitur; jangan push ke `main`.

### TUGAS SESI INI — Fase F0: Ekstraksi & Validasi Data Instrumen
Tujuan: mengubah berkas master (Excel/dokumen norma) menjadi berkas data terstruktur (mis. seeder Laravel / JSON) yang menjadi sumber angka bagi engine — DAN membuktikan hasil ekstraksi benar lewat golden test. **Belum ada struktur aplikasi penuh; belum ada UI.**

Kerjakan:
1. Buat direktori kerja ekstraksi (mis. `tools/extract/` — boleh skrip PHP standalone atau Python untuk tahap ekstraksi murni, output akhirnya jadi `database/seeders/` Laravel). Satu skrip per kelompok data.
2. Ekstrak & strukturkan (rujuk `SPEC.md §4` dan `SCORING_ALGORITHM.md`):
   - **IST**: kunci non-GE; kamus GE 0/1/2; tabel norma RW→SW (perhatikan: berkas punya **dua blok kolom berdampingan** — GE skala RW 0–32 di blok terpisah, 8 subtes lain RW 0–20); tabel ΣRW→IQ; kategori SW & IQ. Catatan: SE pada RW 16 = 131 (nilai terkoreksi).
   - **PAPI**: mapping 90 soal → 20 dimensi; batas zona warna per dimensi (untuk metode "jarak dari zona Putih").
   - **RMIB**: rumus rotasi kategori `MOD(posisi+indeks_kelompok−2,12)+1`; rank→skor.
   - **Kraepelin**: tabel cutoff 6 grup norma (SMA/SMK dst.); ingat Hanker=b×50.
   - **DASS-21**: teks 21 item; mapping subskala D=[3,5,10,13,16,17,21] A=[2,4,7,9,15,19,20] S=[1,6,8,11,12,14,18]; cutoff (skor ×2, ambang DASS-42); narasi.
   - **Standar Grey Area**: 6 bidang (versi GA-2026.08); daftar aspek yang dinaikkan + minat wajib per bidang.
   - **Bank narasi**: narasi per aspek per band; konektor.
3. Bangun harness validasi (golden test) dan JALANKAN — boleh sebagai PHPUnit test atau skrip standalone di tahap ini, yang penting hasilnya bisa ditunjukkan sekarang.

### Gerbang F0 (WAJIB hijau sebelum boleh lanjut ke F1) — tunjukkan buktinya
- **Golden Kraepelin #1 (S1/S2)**: dari 50 lajur → Panker 15,86 · Tianker 7 · Hanker −0,62 · Janker 7 → skor (IPA) 7/6/4/6.
- **Golden Kraepelin #2 (SMA/SMK)**: ΣY 656 → Panker 13,12 · Tianker 5 · Janker 6 · slope b 0,100648 · Hanker 5,032 → level 4/4/4/5 (Baik/Baik/Baik/Baik Sekali).
- **Invarian**: PAPI ΣROLE=45 & ΣNEED=45; RMIB Σrank=702 & tiap kelompok=78; DASS tiap subskala 7 item.
- **Monotonisitas**: semua tabel norma naik monoton (mis. IST SW per RW) — laporkan bila ada anomali.
- **Parser IST dua-blok** terbukti membaca kolom GE (RW 0–32) terpisah dari 8 subtes lain.
- Bila ada sampel skoring historis, skor ulang & cocokkan; jelaskan tiap ketidakcocokan (salah rekap lama vs bug ekstraksi — bug WAJIB diperbaiki).

### Cara melapor di akhir sesi
Tampilkan: (a) struktur berkas data yang dihasilkan; (b) hasil golden test & invarian (lulus/gagal per item, dengan angka); (c) daftar keputusan/asumsi yang kamu ambil; (d) apa pun yang tidak jelas dan kamu butuh keputusan manusia. **Jangan mulai F1 (struktur aplikasi Laravel penuh).** Akhiri dengan meminta konfirmasi bahwa gerbang F0 diterima.

### Bila ragu
Berhenti dan tanya. Untuk proyek ini, "berhenti dan bertanya" selalu lebih baik daripada menebak angka skoring, menebak detail stack, atau menambah lingkup yang tidak diminta.

## ================== SALIN SAMPAI SINI ==================
