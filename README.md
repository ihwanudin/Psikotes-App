# psikotes.oncam.id

Sistem psikotes daring multi-cabang LSI untuk CPMI tujuan Jepang. Instrumen: IST, PAPI Kostick, RMIB, Kraepelin, dan DASS-21 pada jalur terpisah. Hasil psikotes memakai skala aspek 1–5 dan model Grey Area terhadap standar bidang; laporan wajib ditinjau psikolog sebelum terbit.

Stack: Laravel · Inertia.js+React (peserta) · Filament/Livewire (admin/staf/psikolog) · PostgreSQL+RLS · Docker Compose (VPS) · object storage S3-compatible · Shared Drive (arsip) · WAHA/n8n · Xendit.

## Peta dokumen
| Baca | Untuk |
|---|---|
| SPEC.md | Spesifikasi fungsional (sumber kebenaran) |
| PANDUAN-EKSEKUSI.md | Urutan fase dan gerbang; SPEC.md menang bila ada konflik versi |
| CLAUDE.md | Aturan kerja AI — dibaca otomatis Claude Code |
| SCORING_ALGORITHM.md | Semua logika skor (kanonik) — status draft/final per bagian |
| ARCHITECTURE / DATABASE_SCHEMA / API_CONTRACT / SECURITY | Teknis |
| DEPLOYMENT / PRIVACY_POLICY / CHANGELOG | Operasional |

## Status implementasi

- F0 selesai: ekstraksi instrumen dan 11 gate otomatis lulus. Lihat `F0_VALIDATION.md`.
- F1 berjalan: baseline repository, shell Laravel React, topologi container statis, dan seeder konfigurasi F0 telah lulus gerbang yang tersedia. Verifikasi runtime container menunggu Docker. Rencana dan checklist berada di `tasks/plan.md` dan `tasks/todo.md`.

## Toolchain F1

- PHP 8.3.26
- Composer 2.9.4 melalui `php D:\laragon\bin\composer\composer.phar`
- Node.js 24.11.0 dan npm 11.6.1
- Target: Laravel 13, Inertia 3 + React 19 + TypeScript + Tailwind 4, Filament 5
- Docker belum terdeteksi di `PATH`; bootstrap awal dapat berjalan melalui Laragon, tetapi checkpoint container tetap wajib sebelum F1 ditutup.

Bootstrap dependency dilakukan dari branch utama starter kit React resmi Laravel karena rilis Packagist `v1.0.1` masih memakai Laravel 12. Dependensi dikunci di `composer.lock` dan `package-lock.json`; instalasi awal dilakukan tanpa lifecycle scripts, kemudian package discovery, test, lint, typecheck, build, dan audit dijalankan eksplisit.

Semua metode pembayaran dikendalikan dengan status aktif/nonaktif. Kanal yang nonaktif tidak ditampilkan dan tidak menerima order baru; order historis tetap dipertahankan.

Secret JWT peserta harus berupa random key dan tidak boleh disalin dari contoh:

```powershell
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
```

Simpan hasilnya hanya sebagai `PARTICIPANT_JWT_SECRET` di environment deployment. Keputusan kredensial dan batas entitlement gate dicatat di `docs/decisions/0001-participant-credentials.md`.
