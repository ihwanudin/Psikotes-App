> **PEMBARUAN v4.0 (18 Agt 2026, FINAL):** SPEC utama kini `SPEC_v4.md` — gabungan blueprint psikometri psikolog (HPP CPMI v2.3) + infrastruktur. Baca itu, bukan spec lama. Perubahan besar: skala 1–5; model Grey Area vs standar bidang; aspek kritis A1/B2/C4/C5; dua dokumen keluaran (HPP + Lembar Internal); DASS terpisah mutlak (uji T-07); wajib tinjau+ttd psikolog; 24 uji penerimaan. Fase build F0–F9 ada di SPEC_v4 §14. Satu butir memblokir F2 Kraepelin: konfirmasi Hanker=b×50.

# PANDUAN EKSEKUSI — psikotes.oncam.id

Dokumen ini adalah instruksi kerja untuk Claude Code. `SPEC.md` adalah sumber kebenaran *apa* yang dibangun; dokumen ini mengatur *bagaimana* dan *urutan* membangunnya. Bila keduanya bertentangan, ikuti `SPEC.md` dan laporkan konfliknya.

> **PEMBARUAN v3.0 (FINAL):** metode skoring disahkan psikolog — SCORING_ALGORITHM.md v3.0 kanonik. F0 wajib: (1) ekstrak Tabel Lookup v1.1 (18 sub-aspek, norma 6 grup Kraepelin, pita warna PAPI, ambang, daftar knockout) ke tabel config; (2) parser IST sheet 01 tangani dua blok kolom berdampingan (GE RW 0–32 di kiri); (3) DUA fixture golden Kraepelin wajib: S1/S2 (Panker 15,86/Tianker 7/Hanker −0,62/Janker 7) DAN SMA/SMK (Panker 13,12/Tianker 5/Janker 6/Hanker 5,032 → 4/4/4/5); (4) cek monotonisitas semua tabel norma; (5) terapkan 14 perbaikan tik Formula Drafting saat ekstrak narasi. Gerbang F0 lulus HANYA bila golden + invarian (PAPI 45/45, RMIB 702/78) hijau.\n\n> **PEMBARUAN v2.1:** rumus aspek kini resmi dari `Buku_Panduan_Skoring_HPP_Seleksi_Kerja_SO_Serbaindo.pdf` — F0 wajib mentranskrip Tabel 1.1/1.2 + 13 rumus ke `aspect_formulas` dan unit-test-nya memakai contoh hitung manual; gerbang sign-off psikolog = 6 butir checklist SCORING_ALGORITHM.md §6.6.

> **PEMBARUAN v2.0 (7 Juli 2026).** Sistem penilaian & output berubah — baca SPEC.md v2.0 + SCORING_ALGORITHM.md sebelum sesi apa pun. Ringkas: (1) F0 kini mengekstraksi dari file **Master_Kamus_Tes_IST / Master_Kamus_Tes_PAPI_Kostick / Master_Norma_Tes_Kraeplin / Formula_Drafting / Pembagian_Alat_Tes** — file Penilaian_* lama hanya untuk validasi silang & fallback GE; (2) Kraepelin = grid 50 kolom × 15 dtk × 27 penjumlahan (bukan stream); (3) laporan utama = **Format Mentahan Serbaindo, 18 aspek skor 1–10**, template `Format_Mentahan_Psikotest_Serbaindo_-_Final.docx`; (4) gerbang F0 bertambah: ekstraksi narasi band (18×5) + kamus PAPI (normalisasi sel-datetime) + narasi Kraepelin 5 level; (5) **gerbang go-live baru: sign-off psikolog atas SCORING_ALGORITHM.md §6 (rumus aspek, V2-1) + ambang Kraepelin (V2-2) + norma GE (V2-3)** — kode boleh jalan duluan dengan draft, laporan produksi TIDAK; (6) aturan disiplin AI pindah/diperluas ke **CLAUDE.md** di root repo — Claude Code membacanya otomatis; bila konflik dengan dokumen ini, CLAUDE.md menang untuk urusan tata kerja, SPEC untuk urusan fungsional.


## Aturan main (baca dulu, patuhi sepanjang proyek)

1. **Kerjakan satu fase per sesi.** Jangan lompat ke fase berikut sebelum gerbang verifikasi fase berjalan LULUS. Setiap fase punya bagian "Gerbang" — itu definisi selesai, bukan sugesti.
2. **F0 mutlak pertama.** Tidak ada kode aplikasi ditulis sebelum data instrumen terekstraksi dan tervalidasi. Kesalahan kunci/norma yang lolos F0 baru ketahuan setelah peserta nyata mengerjakan — terlambat dan merusak kepercayaan.
3. **Jangan menebak logika skoring.** Semua rumus, tabel konversi, kunci, norma, dan mapping sudah ada di `SPEC.md §6` dan file Excel. Bila ada yang tidak jelas, berhenti dan tanya — jangan mengarang.
4. **Data peserta asli tidak masuk repo.** File Excel historis berisi email, WhatsApp, tanggal lahir orang nyata. Ekstrak hanya struktur instrumen (kunci, norma, mapping) + data sintetis untuk test. Jangan commit data pribadi.
5. **Server-authoritative untuk waktu dan skor.** Client tidak pernah menghitung sisa waktu atau skor. Bila tergoda menaruh logika timer/scoring di front-end demi "responsif", jangan — tampilkan saja state dari server.
6. **Tulis test bersama kode, bukan sesudah.** Khusus scoring engine: setiap tes wajib punya unit test yang dijalankan terhadap data validasi F0 sebelum dianggap selesai.
7. **Commit kecil per langkah**, pesan jelas, referensikan fase (`F2: kraepelin scoring engine + tests`).
8. **Secrets lewat environment**, tidak pernah di kode: kredensial Postgres, service account Drive, token WAHA, key Xendit, object storage S3-compatible.

## Prasyarat sebelum mulai (siapkan di luar Claude Code)

- Server VPS/cloud dengan Docker + Docker Compose terpasang; domain `psikotes.oncam.id` mengarah ke server ini.
- Project PostgreSQL (VPS/managed Postgres — bukan Supabase), simpan connection string.
- Object storage S3-compatible (bucket + access key/secret) untuk PDF/foto/bukti.
- Akun Redis (bisa satu kontainer di Docker Compose yang sama) untuk queue & cache.
- Google Workspace + Shared Drive: buat service account, aktifkan Drive API, bagikan Shared Drive ke email service account. Catat `drive_folder_id` root.
- WAHA instance (atau n8n yang sudah ada) untuk notifikasi WhatsApp — siapkan endpoint webhook keluar.
- File Excel instrumen ada di folder project: `Penilaian_IST.xlsx`, `Penilaian_Papikostik.xlsx`, `Penilaian_RMIB.xlsx`, `Hasil_Tes_Koran.xlsx`, plus PDF soal RMIB & Kraepelin, dan contoh laporan.
- Aset soal IST bergambar (subtes FA & WU) — pastikan tersedia; ini yang paling sering tertinggal.

Jika salah satu prasyarat belum ada saat fase yang membutuhkannya, kerjakan bagian lain fase itu dan tandai TODO — jangan blokir seluruh fase.

---

## F0 — Ekstraksi & Validasi Data Instrumen

**Tujuan:** mengubah keempat file Excel menjadi seed SQL yang benar, terbukti dengan men-skor ulang peserta lama.

Langkah:
1. Tulis script Python (`tools/extract/`) — satu file per instrumen. Output: file `.sql` seed + `.json` untuk inspeksi manual.
   - **IST**: kunci non-GE (baris kunci sheet Proses), kamus GE 0/1/2 (dari formula array sheet Proses — perhatikan ini ArrayFormula, bukan nilai sel), norma RW→SW per kelompok usia (sheet Master, 13 thn s/d >45), norma IQ, aturan dominasi.
   - **PAPI**: mapping 90 soal × 2 pernyataan → 20 aspek (sheet PROSES/DATA), 89 baris uraian per (aspek, skor) (sheet URAIAN).
   - **RMIB**: daftar pekerjaan L & P per kelompok A–I + mapping ke 12 kategori, teks interpretasi per kategori.
   - **Kraepelin**: keempat tabel konversi sudah tertulis lengkap di `SPEC.md §6.1` — transkrip ke seed, jangan ekstrak dari `Kraepelin.xls` (file itu template 50-lajur yang berbeda; diabaikan sesuai keputusan).
2. Bangun **harness validasi**: ambil beberapa peserta dari tiap file Excel, skor ulang dengan engine hasil ekstraksi, bandingkan dengan hasil di Excel.

**Gerbang F0 (semua harus terpenuhi):**
- IST: IQ & SW hasil hitung ulang == nilai Excel untuk ≥5 peserta sampel (termasuk peserta dengan jawaban GE non-trivial).
- PAPI: total 20 aspek == 90 untuk tiap sampel; skor aspek == Excel.
- RMIB: total semua kategori == 702 per peserta; ranking kategori == rekap Excel.
- Kraepelin: Panker & Hanker hasil rumus == data historis untuk ≥48/50 baris (dua outlier yang sudah diidentifikasi sebagai salah-rekap boleh berbeda — dokumentasikan keduanya).
- Setiap ketidakcocokan dijelaskan (salah rekap lama vs bug ekstraksi). Bug ekstraksi WAJIB diperbaiki; salah rekap lama dicatat.

Jangan lanjut ke F1 sebelum laporan validasi ini hijau.

---

## F1 — Fondasi + Alur Transfer Manual

**Tujuan:** kerangka jalan + registrasi-sampai-aktivasi via transfer manual (paritas dengan sistem berjalan, minus pengerjaan tes).

Langkah:
1. Proyek Laravel: Inertia.js+React (`resources/js`) untuk peserta, Filament untuk admin/staf/psikolog, `app/Services/Scoring`, `tools/` (skrip ekstraksi), `database/` (migrasi + seed F0). Docker Compose untuk dev.
2. Migrasi Laravel semua tabel `SPEC.md §3` + RLS Postgres + middleware kustom penyuntik konteks (branch_id/role). Uji RLS: branch_admin tidak bisa membaca peserta cabang lain — termasuk uji bahwa middleware benar-benar terpasang di SETIAP route yang relevan (RLS di Postgres biasa tidak otomatis seperti BaaS).
3. Auth: Filament/Laravel auth (sesi, di atas Postgres) untuk admin/staf/psikolog; endpoint login peserta (nomor tes + tgl lahir → JWT custom) dengan rate-limit. Fungsi sequence nomor tes + scheduler Laravel reset bulanan.
4. Registrasi web sesuai field form lama (`SPEC.md §4.1`) + upload bukti transfer ke R2.
5. Antrean verifikasi admin → set `paid` → aktifkan entitlements → trigger notifikasi WhatsApp (WAHA/n8n) berisi nomor tes + kredensial + petunjuk.
6. Kerangka dashboard (shell + auth + nav dua level), halaman login bergaya referensi sumopod.

**Gerbang F1:** seorang admin bisa: lihat pendaftar → verifikasi bukti → peserta menerima WhatsApp → peserta login berhasil. RLS lulus test. Belum ada pengerjaan tes (itu F2).

---

## F2 — Engine Tes + Scoring

**Tujuan:** keempat tes bisa dikerjakan dan terskor otomatis.

Urutan dalam fase (dari paling sederhana ke paling kompleks):
1. **Mesin sesi generik** (`SPEC.md §5`): start/resume/submit, timer server, penolakan jawaban lewat waktu.
2. **Kraepelin**: generator soal seeded, UI keypad, ingest event batch + antrean offline, scoring (4 tabel), grafik per segmen.
3. **PAPI**: UI forced-choice 90 item, scoring 20 aspek, diagram profil.
4. **RMIB**: UI drag-and-drop ranking (wajib — bukan input angka), scoring 12 kategori + interpretasi.
5. **IST** (terakhir, paling kompleks): subtest-state berurutan, dua-timer ME (hafal+jawab), aset gambar FA/WU, scoring + konversi norma usia + IQ.

Setiap tes: scoring di `packages/scoring` (murni, tanpa I/O) + unit test terhadap fixture data F0.

**Gerbang F2:** keempat tes dikerjakan end-to-end di staging; skor setiap tes cocok dengan harness F0; refresh di tengah tes tidak mereset timer; jawaban Kraepelin yang dikirim setelah koneksi putus-sambung tetap masuk dan tidak merusak Janker/Hanker. Setelah gerbang ini, sistem **sudah layak produksi terbatas** dengan aktivasi manual.

---

## F3 — Laporan PDF + Arsip Drive

Langkah:
1. Template HTML laporan mengikuti tata letak contoh LSI (`SPEC.md §8`): identitas, blok IST/Kraepelin/RMIB/PAPI, diagram PAPI, log+foto proctoring.
2. Label bilingual ID–JP via `labels.id-jp.json` (toggle per cabang); blok psikolog opsional (terisi → tanda tangan+SIPP, kosong → format lama).
3. Browser Rendering → PDF → R2; Queue konsumen async; signed URL 15 menit auth-gated.
4. Queue terpisah salin ke Shared Drive `LSI/{kode_cabang}/{YYYY-MM}/`; gagal → retry, status di dashboard; Drive bukan jalur kritis.

**Gerbang F3:** PDF satu peserta lengkap (4 tes) ter-render benar, cocok visual dengan contoh laporan, tersimpan di R2, tersalin ke Drive, dan hanya bisa diunduh setelah login. Bilingual & blok psikolog tampil sesuai toggle.

---

## F4 — Dashboard Admin + Modul Fee Cabang

Langkah:
1. Dashboard pusat: kelola cabang/paket/harga/admin/psikolog, antrean verifikasi transfer, rekap pendapatan, kelola rate fee, antrean withdraw, kamus GE, void/aktivasi, monitor antrean PDF/Drive, audit log.
2. Dashboard cabang: daftar peserta + status, detail peserta dengan timeline proctoring + galeri foto (format lampiran), unduh PDF, verifikasi transfer (bila diizinkan), ekspor CSV.
3. **Modul fee cabang** (`SPEC.md §4.4, §9`): ledger dibekukan per entri; saldo terbagi "Akan datang" vs "Tersedia dicairkan"; **pencairan bulanan** (fee bulan N eligible mulai N+1), satu request per cabang per bulan, nominal terkunci penuh; alur `requested→approved/rejected→paid` + unggah bukti; notifikasi WA tiap perubahan status.

**Gerbang F4:** admin pusat & cabang menjalankan seluruh tugas hariannya dari dashboard; siklus fee satu bulan penuh bisa disimulasikan (peserta paid → fee terbentuk → bulan berganti → cabang request → pusat approve → paid + bukti) dengan saldo yang konsisten.

---

## F5 — Proctoring Client

Paralel dengan F4. Webcam (maks 5 foto/sesi: mulai + ≤3 sampel + submit → R2), log `visibilitychange`/fullscreen-exit/PrintScreen/perubahan IP, hardening ringan (disable klik kanan/seleksi, watermark nomor tes, render soal per halaman).

**Gerbang F5:** foto & log muncul di timeline peserta seperti format lampiran; jumlah foto tidak melebihi batas; keterbatasan deteksi screenshot terdokumentasi untuk admin.

---

## F6 — Payment Gateway

Transfer manual sudah hidup sejak F1; ini penambahan kanal. **Putuskan Midtrans vs Xendit saat fase ini dimulai** (berdasarkan fee & onboarding terkini — verifikasi langsung, jangan pakai angka lama).

`PaymentProvider` adapter, integrasi sandbox, webhook idempotent + verifikasi signature, halaman cek-status fallback. Transfer manual tetap hidup berdampingan.

**Gerbang F6:** pembayaran sandbox sukses → webhook → entitlement aktif otomatis; webhook ganda tidak menggandakan; webhook tanpa signature valid ditolak; fee cabang tetap terbentuk benar lewat jalur gateway.

---

## F7 — Hardening & Kalibrasi

- UAT dengan peserta uji nyata (bukan sintetis).
- Uji beban: 50 peserta Kraepelin serentak (~25 req/dtk ingest) — pastikan PHP-FPM (worker count) & Postgres (connection pool) aman.
- Verifikasi kategori Kraepelin & norma bersama psikolog LSI dengan data UAT.
- Backup Postgres (PITR) + runbook (restore, rotasi secret, prosedur incident).
- Review retensi data berjalan (cron penghapusan).

**Gerbang F7 / go-live:** UAT lolos, beban aman, psikolog menyetujui hasil, backup & runbook ada, hak pakai instrumen & psikolog penanggung jawab terkonfirmasi.

---

## Yang masih perlu keputusan LSI (tidak memblokir kode, tapi blokir go-live)

- Midtrans vs Xendit (sebelum F6).
- Penunjukan psikolog penanggung jawab + konfirmasi hak pakai IST/PAPI (sebelum laporan beredar).
- Review terjemahan label Jepang oleh penutur (sebelum cabang CPMI menyalakan toggle).
- Basis "bulan" fee = tanggal **paid** (verifikasi bukti) — DIPUTUSKAN final, tidak ada item terbuka tersisa di sini.

## Cara memberi perintah ke Claude Code

Mulai tiap sesi dengan menyebut fase dan melampirkan/menunjuk `SPEC.md` + file Excel terkait. Contoh:

> "Kerjakan F0 sesuai SPEC.md dan PANDUAN-EKSEKUSI.md. File instrumen ada di folder project. Berhenti di gerbang F0 dan tunjukkan laporan validasi sebelum lanjut."

Jangan minta beberapa fase sekaligus. Setelah satu fase, minta Claude Code menampilkan bukti gerbang terpenuhi sebelum melanjutkan.
