# CHANGELOG

## [Unreleased] — F1 fondasi
- Rekonsiliasi kontrak psikometri ke SCORING-4.3.0/F2-2026.09: IQ dan RMIB mengikuti tabel lookup v1.1, PAPI memakai jarak dari zona putih, RMIB memakai competition ranking, serta faktor Kraepelin dibulatkan half-up tiga desimal sebelum lookup.
- Ganti halaman awal bawaan Laravel dengan landing page ONCAM Psikotes yang responsif, berfokus pada alur asesmen CPMI Jepang, tinjauan psikolog, privasi, dan pendaftaran peserta.
- Pertahankan paket DASS-21 mandiri gratis; setiap paket psikotes utama menyertakan DASS-21 secara otomatis tanpa kontrol tambah/hapus pada pilihan paket. Narasi pilihan paket disederhanakan, sementara isolasi hasil DASS dari kelayakan tetap berlaku.
- Bootstrap Laravel 13 + Inertia 3/React 19 dengan lockfile PHP/frontend dan quality gate otomatis.
- Tambah topologi Compose untuk app, queue, scheduler, PostgreSQL, dan Redis; data service berada di jaringan internal.
- Tambah seeder immutable untuk enam artefak JSON F0 dengan versi dan checksum SHA-256.
- Tambah fondasi schema tenant, consent versioned, metode pembayaran default-off, order/entitlement, audit/outbox, schema DASS terpisah, dan pemisahan role runtime dari owner migrasi.
- Tambah definisi PostgreSQL RLS yang dipaksa dan fail-closed untuk data tenant, finansial, audit, outbox, dan schema DASS, beserta rollback dan kontrak statisnya; uji negatif PostgreSQL nyata masih menjadi gerbang Task 6.
- Tambah runner konteks RLS transaction-local, middleware fail-closed untuk HTTP dan queue, serta architecture test yang mewajibkan middleware pada controller tenant.
- Pasang Filament 5 dan panel `/admin` dengan guard tersendiri, kemampuan empat role yang diverifikasi server-side, policy IDOR peserta lintas cabang, konteks RLS otomatis, serta default cookie session aman.
- Tambah resolver `/r/{refCode}` dengan atribusi first-touch 30 hari, fallback cabang pusat, cookie terenkripsi, audit PII terbatas-retensi, dan penulisan melalui konteks RLS service.
- Tambah registrasi peserta mobile-first dengan atribusi cabang server-side, validasi dan throttle, idempotensi pengiriman, serta consent A/B berversi; perilaku penolakan DASS pada fondasi awal disupersesi ADR-0013.
- Tambah katalog paket berdasarkan jenis tes IST, PAPI Kostick, RMIB, Kraepelin, dan DASS-21. Harga disimpan sebagai integer Rupiah (IDR), seluruh template awal berstatus nonaktif tanpa harga, dan registrasi fail-closed sampai paket berharga diaktifkan.
- Tambah panel konfigurasi paket khusus `super_admin` untuk mengisi harga Rupiah dan mengatur status ON/OFF. Jenis paket kanonis tidak dapat dibuat atau dihapus dari panel, dan aktivasi tanpa harga positif ditolak.

## [4.2.1] — 21 Agustus 2026 (koreksi stack — bukan fungsional)
- **Perbaikan menyeluruh:** seluruh dokumen (SPEC, ARCHITECTURE, DATABASE_SCHEMA, API_CONTRACT, DEPLOYMENT, CLAUDE, PANDUAN-EKSEKUSI, README, SECURITY, PRIVACY_POLICY, KICKOFF_PROMPT) diperbaiki dari draf arsitektur Cloudflare Pages/Workers/Hono/Supabase/Next.js/R2 (sisa dari SPEC v1.0 paling awal) ke **stack final yang sebelumnya sudah diputuskan**: Laravel + Inertia.js/React (peserta) + Filament/Livewire (admin/staf/psikolog) + PostgreSQL dengan RLS (bukan Supabase — konteks WAJIB disuntik middleware kustom) + Docker Compose di VPS + object storage S3-compatible + Xendit (tak berubah) + WAHA/n8n (tak berubah).
- Tidak ada perubahan fungsional/psikometrik — murni koreksi lapisan infrastruktur agar dokumen konsisten dengan keputusan stack yang berlaku.

## [4.2.0] — 20 Agustus 2026 (jawaban psikolog dikunci — TIDAK ADA butir memblokir)
- **Hanker = b×50 DIKONFIRMASI**; terverifikasi 2 golden test independen (S1/S2 dan SMA/SMK, cocok sampai desimal). Butir #1 tidak lagi memblokir F2.
- Golden test kedua (SMA/SMK, grup mayoritas CPMI) ditambah sbg fixture F0: ΣY 656, Panker 13,12, Tianker 5, Janker 6, Hanker 5,032 → 4/4/4/5.
- **Pengacakan opsi jawaban DITOLAK** psikolog (seluruh baterai paten); mitigasi kebocoran via anti-copy/watermark/render-per-halaman/proctoring.
- **Ambang validitas diperketat** (ketetapan psikolog, > usulan): kamera pernah mati (berapa pun durasi) → V2; pindah tab/keluar fullscreen 1× → V2; ketidakcocokan wajah → tinjau manual dulu (terbukti diganti/dibantu = V3, artefak = catatan).
- Standar Grey Area seluruh 6 bidang FINAL, versi **GA-2026.08**.
- DASS-21: teks 21 item Indonesia diterima; mapping subskala terverifikasi cocok dengan baku (D/A/S 7 item masing-masing); narasi final di Bank Narasi.
- Identitas laporan final: Unit Layanan Psikologi — PT Online Career Mentor, Salatiga; Rizqi Ulin Nuha S.Psi. SILP-D8A35113BB4D, STR20241347-2026-0695.
- Norma IST: tabel internal dari Master Kamus; provenans manual/jumlah sampel tak terdokumentasi → dicatat sbg batasan di lampiran metodologi.
- Rencana validasi Kraepelin kertas↔digital: ~200 sesi V1 (dominan SMA/SMK), fokus Panker & Hanker, divalidasi psikolog, rilis via penggantian cutoff tanpa rilis kode (G8).

## [4.1.1] — 20 Agustus 2026
- Ditetapkan: cabang = entitas referral (komisi ke cabang via atribusi link first-touch). LPK/kumiai penerima laporan, bukan penerima komisi — model komisi tidak berubah.

## [4.1.0] — 20 Agustus 2026 (Xendit + referral cabang)
- **Xendit Invoice** sebagai gateway utama (naik ke F1, bukan ditunda): buat invoice `external_id=order_id`+metadata cabang; webhook verifikasi `x-callback-token`, balas 200 ≤30 dtk, idempotent, retry-aware; halaman cek-status fallback. Transfer manual tetap hidup sebagai cadangan.
- **Gating akses tes dipertegas**: `start` sesi 403 bila entitlement≠ready; locked→ready hanya via webhook terverifikasi / verifikasi manual / aktivasi super_admin; order kedaluwarsa → kembali locked.
- **Referral cabang berbasis link** (`?ref=KODE`, first-touch, cookie 30 hari): `referral_visits` untuk audit; `participants.referral_branch_id`; ref kosong/tak dikenal → cabang default (pusat); `branch_id` menyambung ke modul komisi tanpa ubah ledger; admin cabang tak boleh ubah atribusi cabang lain.
- Dok diperbarui: SPEC §2/§3/§11/§14, API_CONTRACT, DATABASE_SCHEMA, PRD. Butir terbuka: gateway ditutup (Xendit); tersisa keputusan first/last-touch (default first) & manual hidup berdampingan (default ya).

## [4.0.1] — 19 Agustus 2026 (proctoring dispesifikasi)
- Tambah §8A Integritas Ujian & Proctoring: prinsip cegah-vs-deteksi (jujur, tanpa janji berlebih); kebijakan kamera mobile-first (capture berkala 12–20 dtk, penanganan stream-mati saat app-switch/lock, izin ditolak→V2, pencocokan wajah awal+berkala); fullscreen+visibilitychange dengan reaksi berjenjang; hardening; mitigasi soal statis; **matriks ancaman→sinyal→konsekuensi** dengan kolom jujur "dapat dicegah?"; transparansi/consent proctoring.
- Uji penerimaan T-25..T-28 (kamera ditolak, stream mati mobile, visibilitychange, pencocokan wajah). Klien proctoring naik ke F2 (mobile-critical).
- Konteks ditetapkan: peserta mayoritas HP; kebijakan integritas "seimbang" (deteksi+tandai, psikolog putuskan) — bukan cegah-dan-tolak (mustahil di web mobile).

## [4.0.0] — 18 Agustus 2026 (gabung blueprint psikometri Rizqi HPP v2.3 + infra)
### PERUBAHAN BESAR (mengganti v3.0)
- Skala laporan **1–5** (Rendah/Kurang/Cukup/Baik/Tinggi), bukan 1–10. Skor 1–10 internal saja.
- Kelayakan via **model Grey Area** terhadap standar bidang kerja (KAIGO/KENSETSU/NOUGYOU/SEIZOU/GAISHOKU/UMUM), bukan knockout+ambang total.
- Aspek kritis **A1,B2,C4,C5** (bukan 13 aspek); MAKS 2 aspek non-kritis boleh BELUM.
- **Dua dokumen keluaran**: HPP (LPK/kumiai, tanpa skor mentah/subskala DASS) + Lembar Kerja Internal Psikolog (semua angka).
- **9 aturan penjaga G1–G9**; wajib tinjau+ttd psikolog (G5), state machine DRAFT→…→SIGNED→PUBLISHED, tak ada jalur pintas.
- PAPI: metode **jarak dari zona Putih** (optimal), bukan warna tetap 8/7/3/5; 16 skala dipakai, 4 (G,I,X,Z) kualitatif; 5 (K,O,P,R,X) tampil ke psikolog.
- **DASS-21 jalur terpisah mutlak (G4)**: ×2, cutoff DASS-42, item baku, hanya kategori umum di HPP, subskala di internal, Parah→tawaran dukungan bukan penghentian. Aturan consent opsional pada versi ini disupersesi ADR-0013. Uji T-07 mutlak.
- Validitas sesi V1/V2/V3; 24 uji penerimaan; retensi DASS 2 th terpisah, video 90 hari.
- Perakit narasi deterministik (Uraian + 7 slot integrasi internal), teks dari Bank Narasi.
### KONFLIK TERCATAT (perlu konfirmasi psikolog)
- Hanker: HPP pseudocode = b mentah; golden test & cutoff = b×50. v4.0 pakai **b×50** (terverifikasi), minta konfirmasi 1 baris (blokir F2 Kraepelin).


## [3.0.0] — FINAL (metode skoring disahkan psikolog)
### Diputuskan final
- Struktur **18 sub-aspek** (bukan 26); skor 26 alat mentah → lampiran rinci.
- Bobot semua 1,0 (General Intelligence 2→1); ambang 7,00/5,00 inklusif.
- **Knockout** di 13 sub-aspek A/B/C; Job Interest (RMIB) dikecualikan (forced-ranking, cegah ~42% gugur palsu). A/B/C=1→Tidak Disarankan; =2→maks Dipertimbangkan. Evaluasi knockout-dulu-baru-ambang.
- **PAPI→HPP** per-dimensi tabel warna (8/7/3/5); sheet 09 keadaptifan turun jadi arsip profil (draf lama dibatalkan).
- IST belah-dua kategori (bukan interpolasi); norma GE terkonfirmasi ADA (sheet 01 blok RW 0–32); SE RW16=131.
- Kraepelin: Hanker=regresi b×50; grid 50×15 bawah→atas; norma 6 grup lengkap; **golden test peserta S1/S2 terverifikasi 12/12** (fixture F0).
- Aturan tepi sheet 14: clamp IQ, status TIDAK SAH, CURIGA HITUNG (|Hanker|>Janker).
- Laporan: PURPOSE baku, INTEGRATION 5 paragraf + aturan pemadatan (≤4/≥7 penuh, 5–6 digabung, konektor tertutup), 3 kalimat penutup per label, **penyunting psikolog wajib** (status draft→reviewed→final).
- Perbaikan tik Formula Drafting (14 butir, Lampiran D psikolog) diterapkan saat ekstraksi narasi.
### Sisa (tak memblokir): golden Kraepelin ke-2, uji kesetaraan kertas↔digital, gateway, review label JP.


## [2.1.0] — 2026-07-07
- Agregasi aspek: draft internal DIGANTI rumus resmi `Buku_Panduan_Skoring_HPP` (13 rumus berbobot, PAPI ±1/terbalik Z-K, tabel konversi SW/persentil/rank, pembulatan tunggal). V2-1 & V2-2 selesai bersyarat.
- Kategori Kraepelin: band persentil HPP (asumsi P=SS).
- Celah baru tercatat: konflik sumber HPP vs Pembagian_Alat_Tes; ambiguitas titik-dalam-band (dipakai interpolasi linear); pembacaan rumus B4/C7. V2-3 (norma GE) naik prioritas — memblokir 3 aspek.

## [2.0.0] — 2026-07-07
### Berubah (BREAKING terhadap desain v1.1 — belum ada kode terdampak)
- Output utama: laporan Format Serbaindo — 18 aspek skor 1–10 (Poor…Excellent) + IQ + INTEGRATION, bilingual ID–JP; format LSI lama menjadi lampiran internal.
- Kraepelin: grid klasik 50 kolom × 15 dtk × 27 penjumlahan (menggantikan stream teskoran); normalisasi lajur dihapus; kategori 5 level (menggantikan 7 desil).
- IST: norma tunggal RW→SW + ΣRW→IQ + kamus 5/7 kategori dari Master Kamus (menggantikan norma per usia).
- PAPI: kamus interpretasi + zona warna + 7 dimensi dari Master Kamus.
### Ditambah
- §6.5 agregasi aspek (DRAFT — butuh sign-off psikolog, V2-1); tabel `aspect_formulas`, `aspect_narratives`.
- Set dokumen: ARCHITECTURE, CLAUDE, DATABASE_SCHEMA, API_CONTRACT, SECURITY, SCORING_ALGORITHM, PRIVACY_POLICY, TEST_PLAN, DEPLOYMENT, ADMIN_GUIDE, CHANGELOG, .env.example.
### Celah tercatat
V2-1 rumus aspek · V2-2 ambang Kraepelin/pendidikan kosong · V2-3 norma GE kosong · V2-4 kontradiksi 28/50 angka · V2-5 norma tanpa usia · V2-6 header RW vs IQ.

## [1.1.0] — 2026-06-13
Fee cabang + pencairan bulanan; alur transfer manual (WAHA/n8n); bilingual label; blok psikolog; keputusan item terbuka.

## [1.0.0] — 2026-06-12
Spesifikasi awal: arsitektur CF+Supabase, 4 engine tes terverifikasi data historis, fase F0–F7.
