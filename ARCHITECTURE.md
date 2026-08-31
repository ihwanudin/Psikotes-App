# ARCHITECTURE.md — psikotes.oncam.id (v4.2 — Laravel)

Ringkasan sistem untuk AI/developer baru. Detail fungsional di SPEC.md.

## Stack & alur data
```
Peserta ──HTTPS──▶ Laravel (Inertia.js + React, mobile-first)
Admin/Staf/Psikolog ──HTTPS──▶ Laravel (Filament / Livewire)
                              │
                        Laravel (PHP-FPM + Nginx) — backend tunggal
                        ├── Auth: JWT kustom peserta (no_tes+tgl_lahir) / Filament auth admin
                        ├── Middleware kustom: suntik konteks RLS (branch_id, role) tiap request
                        ├── Sesi tes server-authoritative + ingest jawaban (batch, idempotent)
                        ├── Scoring engine (package murni, testable tanpa I/O)
                        ├── Webhook Xendit (idempotent, verifikasi x-callback-token) + transfer manual
                        ├── Queue worker (Redis): render PDF (Browsershot/Puppeteer headless),
                        │     rakit narasi, sinkron Drive, notifikasi WAHA/n8n
                        └── Scheduler (cron Laravel): expiry order, retensi data, reset sequence bulanan
        Postgres (+RLS, privat)   Object storage S3-compatible (PDF/foto/bukti)   Google Shared Drive (arsip)
        Redis (privat, queue+cache)
```

Deployment: Docker Compose di VPS/server tradisional — kontainer terpisah untuk `app` (PHP-FPM+Nginx), `queue worker`, `Redis`, `Postgres`; Postgres & Redis di jaringan Docker privat, tidak menghadap publik.

## Keputusan desain & alasan (ringkas)
- **Laravel sebagai backend tunggal**, dua permukaan frontend satu codebase: Inertia.js+React untuk peserta (5 instrumen — kaya interaksi: timer, drag-drop RMIB, grid Kraepelin), Filament+Livewire untuk admin/staf/psikolog (kecepatan CRUD & alur tinjauan bawaan Filament). Trade-off yang disadari: dua paradigma frontend dalam satu repo, diterima demi kecepatan bangun panel admin.
- **PostgreSQL + RLS, bukan Supabase.** Di VPS/managed Postgres biasa, **RLS tidak otomatis aktif seperti di BaaS** — konteks (branch_id, role, participant_id) WAJIB disuntik lewat middleware Laravel kustom di setiap request. Ini bukan detail kecil; lupa memasangnya di satu endpoint = kebocoran data lintas cabang.
- **Server-authoritative timer & skor** — anti-cheat dan konsistensi; client hanya render state.
- **Event-based answers (Kraepelin per sel + timestamp)** — auditable, skor bisa dihitung ulang saat norma direvisi.
- **Object storage S3-compatible primer, Drive arsip async** — signed URL berbatas waktu utk data sensitif (via Flysystem S3 driver); Drive bukan jalur kritis (retry via queue).
- **Norma/kamus = data, bukan kode** — revisi psikometrik tanpa deploy; `engine_version` dicatat di setiap `scores`.
- **Adapter PaymentProvider** — domain menerima event provider-neutral; hanya transisi pertama pending→paid yang membuka entitlement dalam transaksi ber-row-lock. Xendit Invoice dan transfer manual default OFF serta diaktifkan terpisah oleh super admin; status OFF hanya memblokir order baru, bukan order historis. Lihat `docs/decisions/0002-payment-provider-boundary.md`.
- **Dua level admin + RLS** — pusat vs cabang; isolasi data antar cabang di database (RLS+middleware), bukan hanya di UI.
- **Referral cabang first-touch** — atribusi `branch_id` peserta dari link `?ref=KODE`, cookie 30 hari; kosong/tak dikenal → cabang default (pusat).
- **Participating organization additive di atas `branches`.** Nama tabel dan seluruh foreign key lama dipertahankan; arti domainnya diperluas menjadi organization yang berpartisipasi. `organization_type` membedakan pusat, cabang, operator beasiswa, LPK eksternal, partner, dan direct public. `branch_id` tetap nama kolom compatibility, bukan konsep universal pada kontrak baru.
- **Empat identitas integrasi dipisahkan:** organization menguasai akses operasional; integration client mengautentikasi aplikasi pemanggil; source system mengidentifikasi asal proses; attribution source tetap first-touch referral dan tidak ditimpa provisioning.
- **Registry allow-listed dan berversi** menyimpan client/source/package/funding/delivery policy serta credential reference. Material secret hanya ada pada secret store runtime. Generic API dan Selection v1 hidup berdampingan.
- **Transactional assessment outbox** memisahkan finalisasi hasil dari delivery callback. Timeout adalah outcome `UNKNOWN`, sehingga rekonsiliasi remote wajib terjadi sebelum retry. Pull-result tetap tersedia untuk client yang dikontrak dan selalu tenant-scoped.
- **PDF via Browsershot (Spatie, wrapper Puppeteer headless)** dipanggil dari queue worker, bukan dalam request — laporan HPP dwibahasa + Lembar Kerja Internal dirender dari template HTML/Blade.
- **Laporan v4.0 = model Grey Area, skala 1–5, dua dokumen keluaran** (HPP publik + Lembar Internal psikolog); lihat SPEC.md §5–§9.

## Modul frontend
**Peserta (Inertia+React):** registrasi + referral → status bayar (Xendit/manual) → lobby tes (entitlements) → runner per tes (Kraepelin grid / IST subtes / PAPI pair / RMIB drag-drop) → selesai → unduh laporan setelah tinjauan psikolog. Auto-save: IST/PAPI/RMIB upsert per item saat berpindah soal; Kraepelin flush per kolom; progress indicator per tes & per subtes; error handling manusiawi (offline banner + antre ulang, bukan stack trace).

**Admin/Staf/Psikolog (Filament+Livewire):** dashboard pusat/cabang, verifikasi pembayaran, daftar asesmen organisasi tenant-scoped (periode/paket/status/rekomendasi), pembuatan ulang undangan satu kali, serta ekspor CSV dengan allow-list kolom. Super-admin mengelola registry client/source tanpa melihat atau menyimpan material secret. Layar tinjauan & tanda tangan laporan (state machine DRAFT→…→SIGNED→PUBLISHED), modul komisi cabang, dan kamus/norma/standar bidang tetap capability lanjutan.

**Undangan tanpa token di access log:** path hanya membawa public ULID. Secret undangan berada pada browser fragment, ditukar satu kali melalui POST ber-CSRF, lalu URL dibersihkan dan JWT peserta disimpan pada `sessionStorage`. Database hanya menyimpan HMAC token; reissue mencabut link aktif sebelumnya secara atomik.

## Skalabilitas
Target >1000 peserta/bulan itu ringan (~50/hari); bottleneck riil = ingest Kraepelin serentak (≈50 request batch/15 dtk per 50 peserta) — aman untuk PHP-FPM dengan queue worker terpisah menangani beban berat (render PDF, sinkron Drive) di luar request path. Caching: bank soal statis di cache (Redis/HTTP cache header, immutable per versi soal); lazy-load runner tes per jenis; indeks DB di DATABASE_SCHEMA.md.
