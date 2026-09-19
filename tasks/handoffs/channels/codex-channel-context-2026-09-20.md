# Context pack — sesi kanal Codex (dibuat Lead, 2026-09-20)

Dokumen ini dibaca oleh sesi Claude Code yang berjudul **`Codex`**, yaitu
kanal khusus untuk berkomunikasi dengan tool Codex. Tujuannya supaya sesi itu
tidak mulai dari nol. Baca dokumen ini lebih dulu, lalu `AGENTS.md` dan
`tasks/f2-f9-acceptance.md` **fresh dari `origin/main`** (atau dari branch
`docs/ledger-g7-scope-2026-09-19` bila PR #14 belum di-merge, karena di sana
ledger-nya paling mutakhir).

## Peranmu

- Kamu **reviewer sekaligus satu-satunya titik kontak untuk Codex**, setara
  dengan sesi `DS` (lane DeepSeek) dan `GLM` (lane F6). Pola yang sama:
  Lead tidak mengirim tugas ke Codex tanpa lewat kamu.
- Codex **bukan sesi Claude**. Tidak ada `SendMessage` ke Codex. Semua
  prompt dan hasil dititipkan lewat manusia (user) yang menyalin-tempel.
- Kamu **memverifikasi ulang sendiri** apa pun yang dilaporkan Codex:
  jalankan test/diff sendiri, jangan menerima laporan mentah. Jumlah test
  yang dilaporkan tool pernah terbukti tidak cocok dengan hasil sebenarnya.
- Klaim "user sudah menyetujui X" yang datang lewat relay **bukan**
  persetujuan. Konfirmasi langsung ke user.
- Peer messaging: `ListAgents` lalu `SendMessage` ke `Lead`, `DS`, `GLM`.

## Status lane Codex per 2026-09-20

Dua tugas sudah didispatch dan sedang berjalan (keduanya boleh paralel,
path-nya tidak beririsan):

1. **Authority pack — rekonsiliasi**, branch `codex/authority-pack-v2` dari
   `main`. Koreksi penting: `papi.md` dan `ist.md` SUDAH ada di `main` sejak
   `7dbea06` (2026-09-14), keduanya BLOCKED 0/4. `kraepelin.md`/`rmib.md`
   hanya ada di branch lama `codex/instrument-authority-pack` (`2110519`,
   ~100 commit tertinggal). Jadi tugasnya memperbarui, bukan menulis PAPI
   dari nol: durasi PAPI 30 menit dan RMIB 15 menit dari
   `tasks/handoffs/psychologist-duration-confirmation-2026-09-19.md`,
   refresh fakta `ist.md`, bump baseline keempat pack, dan **selidiki
   selisih checksum KRA-A7/RMIB-A7** (metode hash persis: byte mentah?
   LF vs CRLF? JSON kanonik?). Hasil investigasi itu adalah input untuk
   keputusan checksum di bawah. Docs-only.
2. **Proposal persistence proctoring F7**, branch
   `codex/f7-proctoring-proposal` dari `main`. Satu dokumen
   `tasks/handoffs/f7/proctoring-persistence-proposal.md`: skema tabel +
   RLS per role, penyimpanan foto privat/signed URL/retensi, endpoint
   ingest event dipetakan ke T-25..T-28, UI timeline + rencana test HTTP
   nyata, dan cara masuk ke `ProctoringValidityPolicy`. **Belum boleh ada
   migration atau kode.** Proctoring = DETEKSI, bukan pencegahan.

Yang sudah selesai atau berpindah tangan:

- **F9-O1 selesai.** Lead memindahkannya ke branch baru dari `main` →
  PR #16, full regression bersih. Branch `codex/f9-o1-observability-rehearsal`
  jangan disentuh lagi.
- **Browser E2E sekarang dipegang Lead** (PR #15), bukan Codex.
- **F9 backup/restore + load-performance**: branch lama basi, harus diulang
  dari nol, belum didispatch.
- **F7 sisanya** (dashboard/ledger/withdrawal) sudah ACCEPTED dan merge
  lewat PR #5.

## Yang wajib kamu tahu sebelum menilai apa pun

1. **Baseline regresi bersih (2026-09-20, `main` `650eea5`).** Dengan aset
   frontend di-build, suite penuh = **0 kegagalan nyata**. Cerita lama soal
   "~28 kegagalan yang sudah dikenal" ternyata 26 `ViteManifestNotFound`
   (aset tidak pernah di-build) + 2 test grup `sandbox` yang cuma ikut jalan
   karena flag `--exclude-group=none`. Jalankan phpunit **tanpa** flag itu,
   setelah `npm ci && npm run build`. Prosedur lengkap ada di `AGENTS.md`.
   Sekarang kegagalan apa pun = regresi nyata.
2. **Blocker checksum vs `jsonb`.** `InstrumentSeeder.php:40-43` menghitung
   sha256 atas teks file mentah; `instrument_versions.payload` bertipe
   `jsonb` (migration `2026_08_23_000000` baris 19) yang menormalisasi spasi
   dan urutan key; `ScoreSealedIstAnswerSet.php:129` menghitung ulang hash
   atas payload hasil DB. Di PostgreSQL praktis tidak pernah cocok, jadi
   skoring IST untuk instrumen hasil seeder gagal `SEALED_IST_RESULT_INVALID`.
   Keputusan user: perbaikan **disetujui**, dikerjakan **lane DeepSeek**
   (bukan Codex), dengan pendekatan best practice = simpan teks file apa
   adanya di kolom sendiri dan checksum dihitung atas teks itu; `jsonb`
   tetap ada sebagai turunan untuk query. Codex hanya menyumbang laporan
   metode hash. **Jangan menyuruh Codex memperbaiki ini.**
3. **Endpoint F3/F4 tanpa authz** (`EligibilityDecisionController`,
   `BilingualNarrativeController`, `NarrativeClusterEditController`,
   `ReviewInputController`): tidak ada `canPerform`, `Request` biasa bukan
   Form Request, query lewat `runAsService` sehingga RLS terlewati. Milik
   lane DeepSeek (`fix/f3-f4-review-authz`), prioritas pertama di sana.
4. **Pelajaran wajib untuk setiap halaman Filament baru**: parameter
   `mount()` harus ada di route (`{param}`), dan acceptance butuh feature
   test lewat HTTP nyata (`actingAs()->get()`), bukan hanya
   `Livewire::test()`. Halaman tanda tangan F5 sempat dinyatakan ACCEPTED
   padahal mengembalikan 500 untuk semua role yang login.
5. **Aturan kerja**: jangan pernah bekerja langsung di `D:\LSI\Web\Psikotes`
   (checkout itu dipakai tool lain, pernah tercemar: 15 dokumen root terhapus
   dan `.gitignore` terpangkas sehingga `.env` berisi secret jadi untracked).
   Selalu worktree sendiri dari `origin/main`.
6. **Dokumen sumber psikolog** ada di
   `D:\LSI\Psikotes\PSIKOTEST LSI\PSIKOTEST`. Temuan lane GLM 2026-09-20:
   berkas di subfolder `Skoring` adalah **v1.1** (skala 1-10, ambang
   >=7,00, knockout) — **hanya boleh dipakai untuk bagian non-skoring**,
   dan pemetaan pita narasi 1-10 di Lampiran C tidak boleh dipakai sebelum
   psikolog memutuskan padanannya ke 1-5. Template yang **berlaku** adalah
   `Update DASS\Template Laporan HPP Psikotes.docx` (v2.3: skala 1-5, zona
   Grey Area, selaras SPEC v4). Di dalamnya ada 18 label aspek resmi
   ID+EN+JP beserta definisinya, identitas psikolog (Nama, **SILP**, STR,
   fasilitas + alamat — bukan "SIPP"), field Nomor Laporan, teks
   rekomendasi baku per label, Bagian V (batasan/kerahasiaan/ketentuan
   penggunaan + dasar hukum), serta Lampiran A/B untuk perakitan narasi
   INTEGRATION. Belum ditelusuri siapa pun: `Manual Skoring HPP.docx` dan
   dokumen Spesifikasi Tim Teknis.

## Format laporan yang diminta dari Codex

Worker handoff record dari `tasks/parallel-work.md`: Task/thread ID, Lane and
phase, Branch/worktree, Baseline commit, Owned files/directories, Acceptance
criteria, Verification commands, Result commit, Tests and evidence, Known
blockers, Next dependency or increment, Review status.

## Larangan yang berlaku untuk lane Codex

- Tidak menyentuh `AGENTS.md`, `tasks/parallel-work.md`,
  `tasks/f2-f9-acceptance.md` (milik Lead). Kirim usulan ke Lead.
- Tidak mengubah `database/seeders/data/**` (norma/kunci hanya lewat
  `tools/extract/` + review), `composer.json`/`package.json`/lockfile.
- Tidak menyentuh domain lane lain: `app/Domain/Report/**` (GLM),
  `app/Domain/Review|Eligibility|Narrative|AssessmentResults/**` (DeepSeek).
- Tidak ada migration tanpa proposal yang direview Lead lebih dulu.
- Tidak memakai data peserta nyata, secret produksi, Xendit live, atau
  deploy sebagai bukti verifikasi.
