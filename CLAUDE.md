# CLAUDE.md — Aturan Main AI (Claude Code) untuk repo psikotes.oncam.id

File ini dibaca otomatis oleh Claude Code. Aturan di sini MENGIKAT untuk setiap sesi.

## Hierarki dokumen
1. `SPEC_v4.md` — sumber kebenaran fungsional (gabungan HPP CPMI v2.3 psikolog + infra). `Spesifikasi_Tim_Teknis_HPP_v2.3.docx` dari psikolog adalah rujukan otoritatif untuk skoring/laporan/etika bila ada keraguan.
2. `SCORING_ALGORITHM.md` — kanonik untuk semua logika skor. JANGAN mengubah rumus/norma tanpa instruksi eksplisit + bump engine_version + CHANGELOG.
3. `PANDUAN-EKSEKUSI.md` — urutan fase & gerbang.
4. Dokumen teknis lain (ARCHITECTURE, DATABASE_SCHEMA, API_CONTRACT, SECURITY, TEST_PLAN, DEPLOYMENT).
Bila bertentangan: SPEC menang; laporkan konfliknya, jangan diam-diam memilih.

## Larangan keras
- JANGAN menghapus/menulis-ulang file atau fungsi yang ada tanpa izin eksplisit di prompt. Refactor besar = usulkan dulu.
- JANGAN menyentuh isi tabel norma/kamus/kunci hasil F0 dari kode aplikasi. Perubahan data instrumen hanya lewat script `tools/extract/` + review.
- JANGAN menaruh logika timer atau skoring di frontend. Client hanya menampilkan state server.
- JANGAN commit data peserta nyata, secrets, atau file `.env`. Data uji = sintetis.
- JANGAN mengacak urutan soal MAUPUN opsi jawaban IST/PAPI/RMIB/Kraepelin (psikolog: seluruh baterai paten, mengacak memengaruhi skoring). **Kraepelin: angka TETAP, sama untuk semua peserta, dari lembar soal resmi — BUKAN dibangkitkan/seeded** (keputusan pemilik proyek 2026-09-21, menggantikan aturan "angka seeded" sebelumnya). Administrasi Kraepelin: 50 kolom, 27 baris jawaban per kolom, 15 detik per kolom, jumlahkan dari bawah ke atas, tulis digit terakhir, pindah kolom otomatis saat waktu habis. Angka lembar soal adalah data instrumen — hanya boleh masuk lewat `tools/extract/` + review, jangan ditulis tangan ke kode.
- Durasi resmi instrumen (keputusan psikolog/pemilik proyek 2026-09-21): PAPI 30 menit; RMIB 15 menit; IST subtes ME total 540 detik dengan pembagian **180 detik menghafal + 360 detik menjawab** (dua fase timer, bukan satu).
- Hanker = b×50 (FINAL, 2 golden test). Jangan pakai b mentah atau (P+J)/2.
- JANGAN menanam bobot/ambang/daftar-knockout/pita di kode — SEMUA dibaca dari Tabel Lookup (data). Psikolog merevisi tanpa rilis.
- JANGAN memakai sheet 09 (keadaptifan PAPI) sebagai input HPP — itu arsip; HPP pakai per-dimensi tabel warna.
- Model kelayakan = **Grey Area terhadap standar bidang**, BUKAN knockout+ambang. Aspek kritis HANYA A1,B2,C4,C5. Jangan pakai model lama.
- **DASS-21 TIDAK PERNAH** masuk ekspresi zona/label (G4, uji T-07 mutlak). Skor subskala DASS TIDAK dicetak di HPP — hanya kategori umum.
- Skala laporan **1–5**, bukan 1–10. PAPI pakai jarak-dari-Putih (optimal), bukan warna tetap.
- **Wajib tinjau+ttd psikolog** sebelum terbit (G5). Tak boleh ada jalur DRAFT→PUBLISHED. Hapus jalur pintas sebelum rilis.
- Lembar Kerja Internal & data DASS: akses HANYA psikolog+peserta. Jangan render ke admin/LPK/kumiai.
- `level_sistem` & `level_final` disimpan berdampingan — JANGAN timpa keluaran sistem saat psikolog override.
- Proctoring web = DETEKSI, bukan CEGAH. JANGAN menulis kode/teks yang mengklaim mencegah pindah window atau menjamin anti-curang. Fullscreen & anti-copy adalah deterrence; keputusan validitas (V1/V2/V3) ada di psikolog.
- Di HP, kamera berhenti saat app-switch/lock — WAJIB deteksi stream-mati + coba aktif ulang; jangan asумsikan kamera hidup terus.
- Teks INTEGRATION tersunting psikolog TIDAK boleh tertimpa saat regenerate tanpa perubahan data.
- JANGAN menandai task selesai bila test gagal/di-skip.

## Kewajiban tiap sesi
- Baca file terkait SEBELUM mengedit (grep/view dulu, jangan menebak isi).
- Scope terbatas: kerjakan hanya yang diminta prompt; temuan lain → laporkan sebagai catatan, jangan langsung dikerjakan.
- Setiap perubahan logika skoring/sesi WAJIB disertai/menjalankan unit test terkait (`pnpm test:scoring` dsb.) — test adalah gerbang, bukan opsional.
- Commit kecil & sering, pesan berpola `F{n}: ringkas apa & mengapa`; branch per fitur `f{n}/{fitur}`; jangan push langsung ke `main`.
- Sebelum operasi berisiko (migrasi destruktif, penghapusan massal): buat git tag snapshot `pre-{aksi}` dan minta konfirmasi.
- Definition of Done per task: kode + test hijau + dokumentasi tersentuh bila perilaku berubah + tidak ada TODO tersembunyi tanpa dicatat.

## Konvensi teknis singkat
- Repo Laravel: `app/` (Inertia+React di `resources/js`, Filament di `app/Filament`), `app/Services/Scoring` (murni, testable tanpa I/O), `database/migrations` + `database/seeders`, `tools/extract` (skrip ekstraksi F0).
- PHP strict types; Laravel Form Request untuk validasi input server-side di setiap endpoint/route.
- Env: dev/staging/production terpisah (Docker Compose per env, lihat DEPLOYMENT.md); secrets hanya via `.env` di server (tak pernah di git), contoh di `.env.example`.
