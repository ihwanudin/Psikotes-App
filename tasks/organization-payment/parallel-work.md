# Koordinasi task paralel organization-payment

## Keputusan dan status

Pengguna menyetujui tiga chat kerja dan koordinator pada 2026-08-31 melalui
"ok silahkan di split dan lanjutkan sesuai rencana". Persetujuan mencakup
kelanjutan P8a ke P8b, bukan pengesahan kesiapan produksi. Rencana ini memperinci
plan.md/todo.md existing tanpa mengganti acceptance criteria bisnis.

Koordinator menyiapkan checkpoint lokal dari hasil kerja existing P1–P8a,
termasuk sumber integrasi yang belum committed. Ini baseline pengembangan,
bukan release atau bukti audit seluruh proyek. Tidak push atau deploy.

## Aturan bersama

- Baca CLAUDE.md, spec terkait, plan.md, todo.md, dan dokumen implementasi sebelum coding.
- Verifikasi skill pada masing-masing task. Baca SKILL.md yang digunakan dan
  referensi wajibnya. Pertahankan Laravel 13, Filament 5, Inertia 3, React 19.
- Kerja di worktree sendiri; jangan menulis ke D:/LSI/Web/Psikotes dari task pekerja.
- Jangan salin .env, secrets, data peserta, SQLite aktif, atau runtime cache dari induk.
- Dependencies vendor/node_modules boleh disalin sebagai salinan independen dari
  induk jika cocok lockfile; jangan memakai junction/symlink writable bersama.
  Jangan jalankan composer setup, migrate, queue worker, atau test tanpa target terisolasi.
- PHPUnit wajib phpunit.organization-payment.xml; PostgreSQL hanya runner disposable
  tools/testing/run-org-postgres.ps1. Tidak ada tes pada database aktif.
- Skill Browser bawaan tersedia; Chrome DevTools MCP tidak tersedia saat audit.
  Setiap task browser memverifikasi koneksi sendiri. Gunakan origin/data test,
  jangan mengubah tab pengguna Cloudflare/n8n/Xendit. Port uji: frontend 8011,
  portal 8012; backend tidak perlu server publik. Periksa port kosong sebelum bind.
- Jangan mengubah dependency lockfiles, shared fixtures/harness, routes,
  config, schema, atau dokumen kanonik tanpa kebutuhan dalam ownership dan
  koordinasi. Laporkan kebutuhan lintas pemilik sebelum mengedit.
- Harga integer IDR berasal dari server/database, bukan literal runtime UI.
  DASS opsional, tidak menentukan kelayakan, dan data klinis tidak untuk cabang.
- Tidak ada invoice/notifikasi nyata, aktivasi flag, cutover, dana talang,
  reinvoice otomatis, perubahan skoring atau deploy.
- Setiap task menulis laporan sendiri di tasks/organization-payment/reports/.
  Hanya koordinator mengedit checklist/status kanonik dan menggabungkan hasil.
- Jangan spawn task/agent tambahan. Selesaikan slice pertama, laporkan tes dan
  commit/diff, lalu tunggu review koordinator. Tidak otomatis lanjut seluruh roadmap.

## Gelombang pertama

### Backend: P8b aktivasi per attempt

Scope: action aktivasi, outbox aktivasi dan token/start yang sungguh diperlukan
oleh acceptance P8b. Baca docs/ASSESSMENT_ACCESS_GATE.md, schema, reservation,
dan jalur token legacy sebelum memilih perubahan. Boleh memecah P8b menjadi
dua increment kecil bila token/start membutuhkan lebih banyak file.

Ownership: app/Actions/Payments/ActivateSettledAssessment.php,
app/Actions/Notifications/EnqueueAssessmentActivation.php, jalur
app/Services/ParticipantAuth/ dan controller/request start terkait, tes baru
tests/Feature/Auth/SettledAssessmentActivationTest.php serta tes token/PG baru
yang diperlukan. Tidak mengedit UI/Filament. Perubahan schema atau route baru
harus diajukan dahulu, jangan memperluas token checkout menjadi token tes.

Acceptance:
- Aktivasi hanya dari settlement tepat + consent/identitas per attempt;
  legacy entitlement tidak memberi akses attempt baru; DASS decline tidak
  memblokir tes utama yang sah.
- Ready/outbox idempotent dan atomik, retry tidak menggandakan notifikasi;
  peserta yang belum memenuhi syarat tidak memblokir peserta lain.
- Scope token/start terverifikasi, gagal tertutup untuk attempt asing/unpaid
  dan token salah tujuan; alur legacy tidak rusak.

Verification: TDD focused PHPUnit, lint Pint pada file perubahan, PHPStan,
tes regression auth relevan; PostgreSQL disposable jika menyentuh transaksi,
RLS atau race. Catat hasil nyata, bukan hasil historis 626/144.

Skills: Laravel Specialist, Auth & Tenant Access, Security & Hardening,
Queues Webhooks & Cache, TDD, Git Workflow; Postgres saat menyentuh DB.
Laporan: reports/backend.md. Dependensi: baseline P8a. Stop setelah P8b/review.

### Frontend peserta: P16-prep (presentasi, belum endpoint)

Scope: tampilan checkout terisolasi dengan typed props dan fixture sintetis.
Ini persiapan P16, bukan melompati P13–P15. Pelajari komponen dan token ONCAM
yang sudah ada. Jangan mengganti form register existing atau menciptakan
API/session/handoff baru. Kontrak props tahap ini internal presentasi/draft,
bukan kontrak HTTP final; tulis usulan mapping untuk ditinjau koordinator.

Ownership: resources/js/components/integrated-checkout/,
resources/js/types/integrated-checkout.ts dan tests/Frontend/IntegratedCheckout/
jika runner existing mendukung; harness preview test-only khusus frontend bila
diperlukan. Jangan edit package.json/routes/controller/global CSS.

Acceptance:
- Ringkasan profil lengkap, field kurang saja, cabang terkunci, consent terpisah,
  self/organization/free/paid serta loading/error/expired ditampilkan jelas.
- Tidak menghitung tagihan atau memberi status paid/akses sendiri; organization
  tidak menampilkan invoice/total/anggota batch, tombol bayar mandiri disembunyikan.
- Gunakan logo/palette ONCAM existing, mobile/keyboard, state data sintetis;
  consent tidak prechecked dan tautan/tombol tanpa handler nyata tidak berpura-pura bekerja.

Verification: typecheck/lint targeted dan build aman, browser pada fixture
test-only tanpa .env aktif; kalau harness belum tersedia laporkan batas
verifikasi, jangan klaim sudah diuji browser. Props hanya menampilkan keputusan
server dengan callback injected; fixture tidak boleh terpasang ke route produksi.

Skills: Frontend UI Engineering, UI/UX Pro Max, React Best Practices, Browser,
TDD bila mengubah behavior, Git Workflow. Laporan: reports/frontend.md.
Dependensi: baseline; wiring final menunggu P14/P15 dan kontrak server review.

### Portal cabang: P12a-prep (baca-saja)

Scope: list/detail tagihan cabang di Filament dengan data model existing,
gated/default tidak terlihat sampai integrasi siap. Jangan membuat invoice,
reservasi, proof upload atau finalizer. P12a tetap terbuka sampai P11c dan
verifikasi end-to-end selesai. Jangan menambah schema/config toggle baru
semata untuk preview; gunakan gate existing bila cocok atau test-only harness.

Ownership: app/Filament/Resources/OrganizationBills/,
tests/Feature/Admin/OrganizationBillAccessTest.php,
resources/views/filament/organization-bills/ bila perlu,
tools/testing/serve-organization-bills-panel.php bila harness perlu.
Jangan mengedit resource AssessmentParticipants (P12b belum dimulai), shared
policies, routes, billing actions atau konfigurasi global.

Acceptance:
- Hanya BranchAdmin organisasi pemilik melihat list/detail; direct URL dan
  role salah/lintas cabang ditolak server, bukan sekadar sembunyikan menu.
- Jumlah peserta/total/status dari server, riwayat dari bill yang sama;
  tidak memuat data klinis atau kontrol verifikasi bayar oleh cabang.
- Filter/pagination/detail dapat diuji dengan fixture sintetis; terminal
  expired/rejected tidak memberi reinvoice otomatis. Jangan tautkan gateway nyata.

Verification: focused PHPUnit + negative role/tenant tests, Pint/PHPStan,
browser test-only jika runtime tersedia; PostgreSQL role runtime bila query
menyentuh RLS. Laporan: reports/branch-portal.md.
Skills: Laravel Specialist, Auth & Tenant Access, Security, Frontend UI,
Browser, TDD, Git Workflow; dokumentasi Filament versi terpasang.

## Checkpoint koordinator

- [x] Tiga task dibuat; baseline lengkap ada di setiap worktree tanpa .env aktif,
  dan masing-masing task melaporkan pengecekan skill sebelum implementasi.
- [x] Setiap task selesai slice awal dengan bukti tes dan daftar batas yang belum diuji.
- [x] Tinjau ownership, kontrak props vs backend, privasi, dan diff sebelum merge.
- [x] Gabungkan satu slice sekali; ulang regression yang terdampak dan build.
- [x] P12/P16 tidak ditandai selesai hanya karena preview UI tersedia.
- [ ] Beri pengguna status dan checkpoint berikutnya; tidak deploy otomatis.

Risiko: konflik shared file ditangani ownership; ketergantungan API ditangani
presentasi terisolasi; resource laptop dibatasi dengan focused tests dahulu
dan full regression oleh koordinator. Chat terpisah tidak otomatis tersinkron
dan tidak berarti pemantauan latar terus-menerus.

## Registri task (2026-08-31)

| Lane | Task ID | Worktree |
| --- | --- | --- |
| Backend | 01a05839-3b48-7801-8175-0392e8764c23 | C:/Users/ThinkPad/.codex/worktrees/14a0/Psikotes |
| Frontend | 01a05839-3b39-7d83-b59f-9e7432d7883e | C:/Users/ThinkPad/.codex/worktrees/d4ea/Psikotes |
| Portal cabang | 01a05839-3b18-73e0-8fdc-8db3b02f835d | C:/Users/ThinkPad/.codex/worktrees/6e61/Psikotes |

Worktree dibuat dari working tree lengkap (base HEAD 58da1de dengan perubahan
P1–P8a), bukan checkout main yang tertinggal. Perubahan baseline awal di worktree
pekerja bukan pekerjaan baru mereka; commit pekerja hanya mencakup lane sendiri.
Koordinator memeriksa status via wait_threads. Bila tool pesan lintas task tidak
tersedia di pekerja, laporan lane menjadi sarana handoff; ini tidak menghalangi
koordinator membaca status dan memberi instruksi.

## Validasi checkpoint sebelum split

Pada giliran split, regresi lokal dijalankan ulang: 626 tes lulus, 2.869
assertions, 327.261 ms, dengan sandbox eksternal dikecualikan. Pint seluruh
proyek dan PHPStan (0 error) lulus; staged diff check lulus. PostgreSQL dan
browser alur aplikasi tidak dijalankan ulang pada giliran koordinasi ini.
Pemeriksaan pola credential pada 132 kandidat file awal tidak menemukan pola
secret yang dicari; ini bukan jaminan audit secret menyeluruh. Tidak ada .env,
private key atau database SQLite yang masuk daftar staged. Commit baseline
menyimpan hasil kerja existing serta rencana split, bukan fitur baru selesai.

## Integrasi dan gelombang kedua (2026-08-31)

Pengguna menyatakan task lain selesai dan meminta kelanjutan. Ketiga slice awal
ditinjau dan diintegrasikan lokal melalui ed8bf7a (backend), c7b9280 (frontend),
dan 9decef5 (portal). Regresi gabungan: 668 tes/3.080 assertions; PostgreSQL
disposable: 149/739; frontend SSR: 17 tes. Pint, PHPStan, typecheck global dan
targeted, ESLint targeted serta build preview lulus. Bukti dan keterbatasan:
[reports/integration-wave-1.md](reports/integration-wave-1.md).

Lanjutkan pada tiga task existing, bukan membuat task tambahan. Worktree pekerja
memiliki baseline snapshot yang berbeda dari commit induk: jangan reset, merge
atau cherry-pick baseline induk secara otomatis. Kirim delta sejak commit lane
terakhir; koordinator mengintegrasikannya. Dokumen ini boleh dibaca dari induk,
tetapi hanya koordinator yang menulis dokumen status kanonik.

### Backend: increment autentikasi attempt P8b

- Prioritas: kredensial bertujuan khusus assessment/attempt dan adapter gate
  untuk permintaan start. Jangan memakai checkout credential sebagai izin tes.
- Pelajari verifier/middleware legacy sebelum memilih integrasi. Gunakan
  primitive/library terpelihara yang sudah tersedia; jangan menambah kriptografi
  buatan sendiri, dependency, schema atau route baru tanpa review.
- Ownership P8b tetap; middleware/request khusus attempt dan tes auth baru
  boleh ditambah. Perubahan shared controller harus minimal dan menjaga legacy.
- Buktikan purpose, expiry, signature, tenant/participant/attempt, unpaid,
  revoked/finalized, consent/identitas terkini, dan penolakan token legacy
  untuk attempt baru. Token tidak mengabadikan status paid/consent.
- StartParticipantSessionController masih 501 SESSION_ENGINE_PENDING: jangan
  menggantinya dengan sukses palsu atau membangun session engine di increment ini.
  Bila integrasi aman memerlukan keputusan kontrak baru, laporkan proposal dahulu.
- Pending outbox bukan notifikasi terkirim. Jangan mengaktifkan consumer nyata.
  Hindari salinan ketiga settlement predicate; usulkan konsolidasi terpisah jika perlu.
- Focused auth tests, Pint/PHPStan, PG hanya bila ada perubahan transaksi/RLS;
  laporkan commit dan sisa acceptance P8b, lalu berhenti untuk review.

### Frontend: penguatan interaksi P16-prep

- Ownership tetap pada komponen, types dan harness checkout terisolasi.
- legalReviewPending saat ini hanya peringatan. Jadikan konfirmasi consent
  fail-closed selama review pending, termasuk guard handler, dengan tes regresi.
  Ini tidak mengesahkan teks legal fixture atau mengganti kebijakan consent server.
- Tambah bukti interaksi nyata: checkbox Space, radio arrow keys, urutan Tab,
  submit/callback, busy, fokus error, dan reset state saat formKey/versi consent
  berganti. SSR saja tidak membuktikan behavior tersebut.
- Gunakan input browser native; jangan mengklaim uji keyboard dari mutasi DOM
  melalui JavaScript. Catat keterbatasan tool bila tidak dapat diuji.
- Tetap tidak ada endpoint/wiring produksi, perhitungan harga atau hak akses UI.
  Focused tests, typecheck/lint/build aman; laporan dan commit, lalu review.

### Portal cabang: bukti PostgreSQL P12a-prep

- Ownership tambahan: tes baru tests/Postgres/OrganizationBillPortalTest.php
  (atau nama khusus setara), tanpa perubahan shared runner/schema/config.
- Jalankan query resource/detail yang baru pada PostgreSQL disposable runtime
  non-owner NOBYPASSRLS, bukan hanya mengulang suite baseline. Buktikan lintas
  tenant/role ditolak, membership berubah, dan context berganti pada koneksi ulang.
- Jika harness PostgreSQL tidak dapat menjalankan Livewire, pisahkan bukti query
  runtime dari HTTP/Livewire SQLite dan tulis batas itu; jangan klaim keduanya sama.
- Gunakan snapshot charge valid. Pertahankan gate test-only dan larangan data
  klinis/invoice/proof/gateway pada proyeksi cabang.
- Catat query count halaman berpaginasi. Perbaikan N+1 hanya dalam ownership,
  tidak menghilangkan pemeriksaan otorisasi persisted saat aksi/hydration.
- Focused tests lalu runner disposable saat backend tidak memakai runner;
  catat bukti, commit, dan batas browser/keyboard. Stop untuk review.

## Review gelombang kedua

Frontend 6f72711 dan portal 2e8ae42 sudah diintegrasikan lokal terbatas.
Verifikasi gabungan: 670 tes aplikasi/3.186 assertions, 156 PostgreSQL/921
assertions, 19 tes SSR; typecheck, lint, preview build, Pint dan PHPStan lulus.
Backend f505c2f hanya proposal; 29 tes RED tidak dihitung sebagai fitur selesai.
Koordinator menyetujui kontrak credential opaque framework untuk implementasi
lokal dan mengirim kelanjutan ke task backend yang sama. Frontend dan portal
menunggu dependensi, tidak membuka endpoint/gate publik. Keputusan, batas dan
hasil verifikasi: [reports/integration-wave-2.md](reports/integration-wave-2.md).

## Pekerjaan independen saat token backend berjalan

Pengguna meminta kelanjutan tanpa menunggu backend. Koordinator melakukan
pengujian keyboard native checkout menggunakan Chrome terpisah melalui Playwright
CLI: Tab/Space/ArrowDown/Enter, validasi required, fokus error dan reflow empat
ukuran lolos pada fixture. Bukti: [reports/checkout-keyboard-verification.md](reports/checkout-keyboard-verification.md).
Gap alat in-app sebelumnya tidak lagi menghalangi bukti keyboard Chrome ini;
audit screen reader/zoom lintas browser dan wiring produksi belum tercakup.

Task portal existing menerima increment verifikasi keyboard/responsive pada
port 8012/session browser tersendiri. Tidak ada overlap backend/token, perubahan
shared routes atau pembukaan gate. Frontend koordinator memakai 8011 hanya selama
pengujian. Tidak ada task/agent baru; seluruh hasil portal tetap perlu review.

## Checkpoint token dan kelanjutan backend

Backend menyerahkan ee7ba62/fe96239: token purpose-bound dan adapter read-only
start. Koordinator meninjau source dan tes; integrasi lulus 728 tes/3.367
assertions serta regresi PostgreSQL 156/921, Pint/PHPStan lulus. Bukti pada
[reports/integration-wave-3.md](reports/integration-wave-3.md).
Controller tetap 501 dan route assessment hanya ada di tes.

Setelah core ini lolos, task backend existing melanjutkan P9a internal pada
todo.md. Ini memisahkan dependensi core dari integrasi writer P11a/P15, bukan
menghapus acceptance P8b. Ownership baru terbatas action provisioning, tes
feature/PG khusus dan laporan backend; tidak menyentuh frontend/portal, route,
schema, shared config, v1 atau sumber aktif tanpa review. Gunakan kontrak request
dan CheckoutContractAdapter existing; tidak menerbitkan invoice/notifikasi/token.
Setiap increment tetap review dan berhenti, tidak lanjut P10 otomatis.

## Prasyarat P9a0 dan audit nullable (2026-08-31)

Preflight backend 6146ef0 dan bukti keyboard portal b7e0504 diintegrasikan sebagai
30e651f/c1c8a02, keduanya laporan saja. Portal membuktikan input native dan reflow
320/390/1280; zoom 200% serta browser-history Back belum terbukti. Tidak mengubah
status portal prep menjadi production-ready. Tidak ada regresi kode baru pada
integrasi laporan ini; angka suite sebelumnya tetap bukti checkpoint sebelumnya.

Keputusan schema: [ADR-004](../../docs/decisions/0004-checkout-partial-profile.md).
Backend existing mengerjakan P9a0 dahulu: migration baru, PHPDoc nullable pada
Participant/AssessmentParticipant, tes feature/PG khusus serta laporan backend.
Koordinator menyetujui ownership shared terbatas itu; tidak mengubah migration
historis, request, writer/action, route, frontend, portal, config atau sumber aktif.
Tes tambahan legacy/gate boleh ditulis di file tes baru khusus increment ini.
Pint/PHPStan wajib; perubahan consumer produksi di luar ownership dilaporkan dulu.
Maksimal dua commit logis untuk schema dan bukti uji, lalu stop untuk review.

Frontend existing melakukan audit read-only dampak nullable pada props dan portal,
commit laporan saja. Tidak ada task/agent baru. Portal selesai pada checkpoint
bukti browser; tidak membangun billing writer atau menyalakan gate publik.
Tidak memakai database aktif, .env, atau layanan pembayaran/notifikasi nyata.

Audit frontend 3105383 ditinjau dan diintegrasikan 793d494. Tindak lanjut yang
disetujui: frontend boleh menyesuaikan tipe dan label null/blank pada
resources/js/pages/participant/lobby.tsx, beserta tes focused/harness terisolasi
dan laporan sendiri. Nomor tes nullable sudah merupakan kondisi schema lama.
Jangan memperlebar union field locked checkout, mengubah API/auth/entitlement,
atau membangun mapper P16. Tes dengan data sintetis, tanpa DB atau login nyata.
Jika file baseline belum tracked di worktree, laporkan sebelum commit agar tidak
memasukkan snapshot baseline. Berhenti setelah increment kecil untuk review.
Perbaikan label tabel Filament dan mapper null-ke-missing dicatat sebagai pekerjaan
berikutnya setelah review schema; belum diimplementasikan oleh laporan audit.

## Kelanjutan otomatis dan checkpoint lobby

Pengguna meminta task aktif dilanjutkan lagi setelah selesai. Heartbeat aplikasi
`lanjutkan-task-psikotes-setelah-selesai` aktif setiap 10 menit pada Koordinator.
Periksa status dahulu; hanya kirim satu increment setelah hasil sebelumnya
direview. Jangan menumpuk instruksi pada task aktif atau menggandakan automation.
Tetap tidak ada izin deploy, DB aktif, pembayaran/notifikasi nyata atau gate publik.

Frontend e46bca4 diintegrasikan 78d7c53: label lobby nullable dan tes mounted-browser.
Task frontend sudah dilanjutkan untuk bukti visual/reflow 320/390/1280 pada fixture
yang sama; ownership hanya browser.test.mjs dan laporan, tidak produksi.
Backend masih menyimpan hasil P9a0 pada snapshot terakhir, perlu review sebelum
request intendedField/action P9a. Detail/cursor: reports/integration-wave-4.md.

## Heartbeat 2026-09-01: schema lulus, koreksi fixture

Backend b6589d5 diintegrasikan bfc0587; root lulus 751/3491 dan PG 196/1070,
Pint/PHPStan. P9a0 schema lulus lokal, bukan provisioning atau cutover. Backend
existing berikutnya hanya request checkout-v2 profile.intendedField opsional
sesuai ADR-004, tes kontrak baru dan laporan; tidak default UMUM, v1/action/route.

Frontend e7a0fd3 visual RED belum diintegrasikan. Kelanjutan yang sudah dikirim
memperbaiki CSS entry fixture, bukan CSS produksi, lalu mengulang guard geometri.
Portal existing berikutnya fallback nama null/blank pada dua tabel resource
AssessmentParticipant/Order dan tes focused; query/scope/akses tidak berubah.
Untuk tes portal, migration 2026_08_31_000600 root boleh disalin identik via
apply_patch sebagai overlay lokal tanpa men-stage atau commit ulang baseline.
Tidak ada perubahan schema aktif atau reset/merge worktree. Bukti, batas dan
checkpoint pengiriman: reports/integration-wave-5.md.
