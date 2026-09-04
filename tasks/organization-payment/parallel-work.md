# Koordinasi task paralel organization-payment

## P14c profile facts accepted locally — 2026-09-04

Reviewed `0846e86`/`ff14129`, including validator compatibility and allowlisted
serialization. Root reran CheckoutProfileProjectionTest: 50/200 passed in the
env-free worker. SQL NULL is missing, valid existing data locked, date calendar
preserved, unloaded/invalid data unavailable. Checkout-v2 ingress rules remain
distinct from stricter public registration. DTO is only internal profile facts,
not authorization, an editable form descriptor or complete frontend summary.

Next backend slice: extract canonical accepted-consent reader from
AssessmentAccessPrerequisites for future summary reuse; preserve its exact
participant/type/version/hash/status/time/withdrawal predicate and caller access
semantics. Do not invent attempt-bound consent, grant consent, accept legal draft
or extend DASS requirements to other tests. Characterize negative cases and run
prerequisite/gate/activation regression. No consent writer, summary HTTP or P15.

## SQLite lifecycle correction accepted locally — 2026-09-04

Reviewed `60fa63b`/`5a7a1ff`: finally preserves parent teardown, exceptions and
callbacks while invalidating migrated only for guarded truncation users.
Root reran original failing order: 2/40 passed; regression sequences: 5/81 passed
with PHP 8.3.26 full configuration. An initial 8.3.30 command-line-extension run
failed before assertions because child processes lacked fileinfo; no application
regression was inferred. Worker combined 176/818 remains worker evidence.
Known baseline PHPStan traitsUsedByTest annotation mismatch, external sandbox skip
and absent real frontend manifest remain separate limitations; no broad green claim.

Next backend slice: internal profile projection DTO plus pure mapping and tests
from reviewed P14c field table, without HTTP/session authority entrypoint yet.
Only own already-authorized participant input; caller must later revalidate in
canonical lifecycle transaction. Strict allowlist, locked valid / missing NULL,
optional email, invalid nonnull fails closed, deterministic date/enum formatting.
Do not implement incomplete payment/access/consent placeholders as a final DTO,
modify frontend DRAFT, or expose projector as a controller. Summary integration
and remaining readers stay subsequent increments.

## Combined-run diagnosis reviewed — 2026-09-04

Reviewed report `ac25a87` against installed DatabaseTruncation/RefreshDatabase
and guarded test base. Shared migrated flag outlives truncation PDO and can make
the following RefreshDatabase class skip schema creation. Worker reproduced the
same ordered pair before extraction; reverse pair passes. This is a pre-existing
test isolation defect, separate from the explicit sandbox skip and absent build.
Next backend ownership temporarily includes tests/OrganizationPaymentTestCase.php
and a bounded database lifecycle regression fixture/test. Implement guaranteed
teardown invalidation only for DatabaseTruncation users in the guarded SQLite
base, preserve parent teardown and transaction semantics, test both orders and
failure cleanup. No vendor/application/schema changes or relaxed safety guards.
Sandbox suite separation and actual frontend build remain separate follow-ups;
do not silently exclude sandbox tests and claim the entire suite passed.

## Shared settlement reader integrated — 2026-09-04

Reviewed `90f3aa5`/`c15bdbf` and integrated as `2fb2268`/`dcf00ab` plus the
report's exact gate delta (before SHA256 d9feedeeea8567e023fe813cd2ad53b3009696910839216920decb27caead34f,
after 1c9f033150e0962ed03d50dc15874272a7e8da53a327429b0a145888298517c9).
Root independently reran reader/gate/activation/finalizer in separate processes:
101 tests / 271 assertions, all passed; integrated files match tested worker.
Worker's wider relevant isolated tests report 230/1070 and its disposable PG
suite 343/2523. These are worker evidence, not a root full-suite rerun.
The refactor preserves predicates, context/lock/clock and historical paid rights.

Broad combined Payments/auth run remains failed: missing Vite manifest, missing
branches tables and a skip. Next backend slice is diagnosis only of combined-run
database isolation: minimal ordered reproduction, baseline comparison and exact
root cause. No projector/consent/HTTP implementation or test-harness mutation
until that evidence is reviewed. Do not dismiss all failures as manifest errors.

## P14c preflight review — 2026-09-04

Reviewed and retained proposal `3b20892` as documentation, not a final HTTP/React
contract. Root compared both existing private settlement predicates in gate and
activation: duplication is real and can be extracted without changing policy.
Next bounded backend slice is the shared internal settlement reader first,
before DTO/projector construction. Preserve every predicate, caller validation,
lock order, timestamps and RLS context. A boolean settlement result must never
be interpreted as access entitlement or authority for a client-supplied charge.
Characterization tests and gate/activation regression are required; PostgreSQL
disposable verifies the affected existing paths. No new locking/business writes.

Proposed price provenance, partial-access and generic source labels remain
internal design candidates; do not mutate frontend DRAFT or activate routes.
Do not make current purchasing-policy OFF a new reason to hide historical paid
evidence or revoke acquired access without a separate reviewed requirement.
DTO/consent/lifecycle projection follow only after this shared-reader review.

## Checkpoint 2026-09-04 — P14b2 native logout correction

Worker `04f92fe`, `a940b8d`, `6e0faea`, `be47d4d` reviewed and integrated as
`d88422f`, `d3af006`, `5e11bbb`, `c1963aa`. Root reran 58 P13/P14 tests with
1,794 assertions in the env-free worker, plus Pint and Node syntax. Integrated
adapter/test/harness bytes match worker. Browser evidence reviewed with explicit
opaque-frame LNA/instrumentation limits; see ADR-012 amendment and lane report.
P14 summary projection and page remain open; no active endpoints or DB changes.

Next backend increment: P14c projection-contract preflight only. Map persisted
attempt-owned profile/payment/access/consent sources to frontend DRAFT props,
identify mismatches and fail-closed states, propose typed internal summary and
negative test matrix before coding the multi-domain projection. Keep frontend
waiting for that reviewed contract; no P15, page/public route, source activation
or changes to shared canonical docs from workers.

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

## Penugasan frontend setelah GREEN fixture (2026-09-01)

Pengguna meminta task yang selesai diberi pekerjaan berikutnya. Snapshot backend
cursor :15 dan portal :11 masih aktif, sehingga tidak diberi instruksi duplikat.
Frontend cursor :12 selesai pada 0e727db. Pasangan e7a0fd3 + 0e727db sudah dibaca,
namun belum diintegrasikan root; review/verifikasi pasangan tetap milik koordinator.

Frontend menerima increment independen P16-prep: proposal pemetaan profil nullable
ke kontrak presentasi existing, matriks tujuh field dan kasus uji, serta batas
otoritas server dan dependensi P14/P15. Hanya dokumen baru
reports/frontend-profile-mapping-proposal.md dan pembaruan reports/frontend.md.
Tidak mengimplementasikan mapper, mengubah tipe/endpoint/schema, atau memutuskan
akses dari browser. Data lengkap tetap locked, data kurang menjadi missing,
tanpa placeholder atau key hilang. Commit dokumen lalu review; jangan kirim ulang
instruksi ini selama task aktif. Backend/portal melanjutkan increment sebelumnya.

## Heartbeat kontrak terintegrasi (2026-09-01)

Checkpoint reports/integration-wave-6.md: intendedField, pasangan fixture lobby,
proposal profil DRAFT dan fallback nama portal diintegrasikan. Root 779/3782,
Pint/PHPStan serta typecheck/lint/build fixture lulus; PG tidak diulang karena
schema/query tidak berubah. Patch request/resource untracked worker diterapkan
sebagai delta kecil, bukan baseline snapshot.

Kelanjutan sudah dikirim: backend mengimplementasikan P9a internal sesuai todo;
frontend memperbaiki email opsional pada dua komponen dan tes (bukan mapper);
portal menyusun proposal P12b collective selection (dua dokumen, bukan writer/UI).
Semua aktif pada snapshot terakhir. Scope, guard dan cursor tersimpan pada laporan
wave-6; jangan menduplikasi instruksi. Endpoint/akses produksi tetap tertutup.

## Heartbeat review replay dan penugasan lanjutan (2026-09-01)

Checkpoint [wave-7](reports/integration-wave-7.md): frontend 1650115 diintegrasikan
83e116d (email opsional), portal proposal 6a7aaf7 diintegrasikan 261aecc sebagai
DRAFT. Backend eb53cfd + 5f4bb3b ditahan untuk review replay funding lifecycle;
P9a tidak dicentang selesai. Tidak ada perubahan schema/query PHP di root.

Ketiga task existing sudah diberi tepat satu kelanjutan dan aktif: backend
reproduksi/perbaikan replay P9a; frontend regresi gabungan browser, keyboard dan
reflow; portal adapter preview read-only test-only tanpa memasang bulk action.
Scope, commit, cursor/status dan batas bukti ada di wave-7. Tidak task/agent baru,
reset/merge worker, akses publik, DB aktif, pembayaran atau notifikasi nyata.

## Checkpoint review lanjutan (2026-09-01)

Lihat [wave-8](reports/integration-wave-8.md): portal f4ca0c6 -> 521b49e dan
browser harness 12f777c -> a84d9bb diintegrasikan setelah review. Root 804/4200,
Pint/PHPStan dan lint/syntax helper lulus. PG adapter belum dibuktikan.
Backend RED d0ed7cf tidak diintegrasikan; ADR-005 menetapkan snapshot keputusan
funding awal agar replay dapat mempertahankan lifecycle dan guard policy.

Sudah dispatch satu kali ke ketiga task: backend GREEN ADR-005; portal tes PG
adapter; frontend cakupan ESLint generated output (izin shared config sempit,
patch delta bila baseline untracked). Scope, cursor dan turn aktif ada di wave-8.
Tidak mengirim ulang selama aktif atau menutup acceptance dari klaim task saja.

## Checkpoint P9a GREEN dan PG preview (2026-09-01)

[Wave-9](reports/integration-wave-9.md) merekam rangkaian P9a hingga fix ADR-005
e540983, PG preview 0c64314 dan lint ignore 563fa6e. Root 880/4652 dan PostgreSQL
222/1659 lulus; Pint/PHPStan serta global ESLint bersih. P9a internal diterima,
tetapi P9 endpoint dan seluruh checkout belum end-to-end.

Task existing telah dilanjutkan satu kali: backend P9b controller dengan route
hanya pada tes; frontend presentasi payer belum dipilih readonly; portal komponen
preview selection di tests/Support tanpa writer/route publik. Ownership, cursor,
turn dan batas ada di wave-9. Jangan menjalankan .env/DB aktif/outbound atau
mengaktifkan gate; jangan menggandakan kelanjutan saat task masih aktif.

## Checkpoint HTTP dan preview (2026-09-01)

[Wave-10](reports/integration-wave-10.md): P9b e3d0b74, payer unselected
27cea1f+b7a6fb8 dan komponen preview test-only e1b9ecc direview. Root 927/5200,
26 SSR, typecheck/ESLint/Pint/PHPStan dan build fixture lulus. Kontrak P5/P9
diselaraskan melalui delta dokumen worker, bukan baseline snapshot. PG wave-9
tetap historis; endpoint publik dan P12/P16 belum end-to-end.

Ketiga task existing aktif setelah tepat satu instruksi: backend boundary P9c
no-store untuk seluruh pipeline, frontend tes props payment pada instance sama,
portal browser keyboard/reflow komponen sintetis. Scope/cursor/turn ada di wave-10.
Tidak menduplikasi kelanjutan; review dulu setelah selesai. Tidak ada gate/source
ON, data aktif, deploy/push, pembayaran/notifikasi nyata atau task/agent baru.

## Checkpoint privacy route dan refresh payment (2026-09-01)

[Wave-11](reports/integration-wave-11.md): P9c 5417326 -> 451cc73 dan frontend
51eca92 -> 67e263b direview. Focused root199/1091, SSR26, global tsc, lint focused,
Pint/PHPStan dan build fixture lulus. Full927/5200 serta PG222/1659 tetap historis.
Header P9c hanya downstream route boundary; tidak ada registrasi publik.

Backend menerima P10a lookup/recovery read-only internal; frontend fixture dan
tes profil tujuh field missing. Portal masih memperbaiki keyboard/reflow sesuai
instruksi wave-10, tidak mendapat prompt duplikat. Scope/cursor/turn dicatat di
wave-11; jangan reset/merge baseline atau mengaktifkan sistem nyata.

## Checkpoint profil parsial (2026-09-01)

[Wave-12](reports/integration-wave-12.md): frontend dac8c67 -> a24f11b diterima,
27 SSR, global tsc, lint focused dan build fixture lulus. Sembilan checkpoint
browser worker direview; tidak perubahan produksi/wire P15. Frontend menerima
satu regresi gabungan helper pada fixture terbaru, lalu prep UI menunggu P14/P15.
Backend P10a dan portal keyboard/reflow masih aktif pada snapshot, tanpa prompt
duplikat atau integrasi hasil belum selesai. Cursor/turn/batas ada di wave-12.

## Checkpoint lookup dan konsolidasi frontend (2026-09-01)

[Wave-13](reports/integration-wave-13.md): a1808f1 -> ce9b3a0 lookup internal
dan 3fe0780 -> 12d0fd2 runner frontend diterima. Full root988/5569 lulus;
Pint/PHPStan/syntax/lint sesuai delta bersih. Browser44/12 capture dari worker
direview, bukan run ulang root. Frontend kini idle menunggu P14/P15.

Portal 1c6afba+3c1dfa3 ditahan: build assets belum explicit envDir:false.
Task menerima fix isolasi/probe sintetis saja. Backend menerima proposal P10b
dua dokumen sebelum writer/claim implementation. Cursor/turn dan batas wave-13
mencegah duplikasi; tidak operasi sistem aktif atau baseline reset/merge.

## Checkpoint isolasi portal dan claim invoice (2026-09-01)

[Wave-14](reports/integration-wave-14.md): portal 1c6afba+3c1dfa3+49c0ff4 diterima
sebagai 2e42519+70a424b+20cd529 setelah probe env/PostCSS dan89/995 root lulus.
Proposal backend cf28c72 -> 848a396 direview; ADR006 b1dbda2 menerima claim-only
P10b-a lokal. Backend aktif dengan satu penugasan, tanpa HTTP/job/permit consume.
Frontend menunggu P14/P15, portal menunggu writer P10/P11; tidak prep duplikat.
Scope/cursor/turn dan DB sintetis baru tercatat wave-14; tidak operasi data aktif.

## Checkpoint claim invoice dan permit berikutnya (2026-09-01)

[Wave-15](reports/integration-wave-15.md): backend d72051d+1ccc01c diterima sebagai
1e79f44+fe49304; config durasi exact c642e50. Root claim60/444, regresi489/2692,
dan PostgreSQL disposable231/1954 lulus. Runner readiness socket sementara
diperbaiki terpisah pada2696e7d. P10b-a diterima, P10b keseluruhan tetap terbuka.

ADR007 membatasi P10b-b pada konsumsi permit sekali pakai + provider fake/HTTP
fake, tanpa dispatcher/route/scheduler/credential nyata. Frontend dan portal
tetap idle menunggu kontrak writer; tidak prep duplikat atau sistem aktif.

## Checkpoint issuance dan rekonsiliasi (2026-09-01)

[Wave-16](reports/integration-wave-16.md): backend b387e6e+e59cab5 diterima sebagai
c3de46c+216601d. Root focused82/717, regresi511/2965 dan PostgreSQL235/2025
lulus. Core P10b internal diterima; tidak ada dispatcher, credential, atau wiring.

ADR008 membatasi kelanjutan backend P10c-a pada rekonsiliasi satu intent melalui
strict lookup tanpa create. Discovery/command/scheduler ditunda ke P10c-b.
Frontend/portal tetap menunggu dependensi; tidak ada operasi sistem aktif.

P10c-a `e3066bc`/`4f4b5e3` diterima setelah review-fix fail-closed `8ee792f`,
diintegrasikan sebagai `6facbda`/`89c8732`/`1900b78`. Root lookup sampai
reconciliation 163/1241, PostgreSQL disposable 237/2074, Pint dan PHPStan lulus.
P10c-b berikutnya harus memisahkan discovery bounded/lease dari aktivasi command
atau scheduler; tidak ada provider credential maupun operasi aktif saat review.

Proposal P10c-b0 `306f3a7` direvisi pada `7c96535` setelah review lock-order dan
diintegrasikan sebagai `9d1db26`/`d99ffa4`. ADR009 menerima schema lease additive
serta acquisition dua fase; P10c-b1 berikutnya hanya kontrak schema/model/config
dan tes disposable, tanpa acquisition atau wiring.

P10c-b1 `3dd1c6e`/`dec48ae` diintegrasikan sebagai `256148f`/`3af4372`, dengan
config root `062815e`. Root database37/185 dan PostgreSQL disposable252/2140,
Pint serta PHPStan lulus. P10c-b2 berikutnya dibatasi reservasi provisional
outbox-only; validator canonical dan provider tetap increment terpisah.

P10c-b2a `8ffd771`/`1e4bcbe` diintegrasikan sebagai `fccfedf`/`c54d895`. Root
invoice182/1323 dan PostgreSQL disposable256/2195 lulus. Untuk mencegah replay
token provisional menerbitkan dua permit, ADR009 menetapkan fase 2 mengonsumsi
token dengan UUID permit baru + expiry baru + increment generation atomik.

P10c-b2b `dabc6f4`/`6163bff` diintegrasikan sebagai `56b681c`/`20f376b`. Root
invoice197/1524 dan PG disposable258/2247 lulus. P10c-b3 berikutnya hanya strict
GET + token-fenced outcome/cooldown; race issuance exact wajib membersihkan lease
secara atomik agar constraint processed tidak gagal.

P10c-b3 `75c72d8`/`6faf0df` diintegrasikan sebagai `43a1a3d`/`b511b59` setelah
review independen. Root focused32/476, Pint, PHPStan dan PostgreSQL disposable
260/2292 lulus dengan cleanup sukses. P10c-b4 berikutnya hanya koordinator batch
internal bounded reserve → validate → execute; command/job/scheduler/route dan
wiring P11 tetap dilarang.

P10c-b4 awal `1766c65`/`4940da1` memerlukan perbaikan karena scan tidak mengisi
ulang lookup setelah validation rejected. Fix `8a79d0a`/`ef5f5d8` diterima dan
seluruh rangkaian diintegrasikan sebagai `11cb271`/`afd003b`/`0ad06ad`/`67902c0`.
Root focused46/479, Pint, PHPStan dan PostgreSQL disposable261/2302 lulus dengan
cleanup sukses. P10c internal selesai; kelanjutan backend adalah P11a core
finalizer atomik tanpa webhook/route/scheduler atau layanan nyata.

P11a1 `de7e6f2`/`6d97aa7` diintegrasikan sebagai `2f668b9`/`974ded1`. Setelah
Docker Desktop dipulihkan, root PostgreSQL disposable262/2320 membuktikan dua
finalizer hanya menghasilkan satu settlement/audit/outbox; cleanup sukses.
P11a core diterima. Backend berikutnya mengaudit kontrak P11b lebih dahulu agar
routing bill AB_ dan status check tidak mengubah fallback/order legacy.

P11b0 proposal `61c3310` diterima root sebagai `60489e7` setelah review
correctness, architecture, dan security serta smoke regression 22/98. P11b1
dispatcher/terminal mapping berjalan dengan TDD; raw webhook auth, route,
provider mapping, command/scheduler, dan status reconciler belum berubah.

P11b1 worker `2c94e8e` memerlukan guard paid-only eksplisit; fix `df2a2a6`
diterima dan rangkaian diintegrasikan root sebagai `419de35`/`a2fa256`/
`573b278`/`f389211`. Root dispatcher+finalizer 22/145, legacy 22/98, default
1187/7440, Pint, PHPStan, serta PostgreSQL disposable 262/2320 lulus dan cleanup
sukses. P11b2 berikutnya hanya reconciler assessment internal bounded; belum
command/scheduler atau layanan nyata.

P11b2 `71dc062`/`0f3f623` diintegrasikan root sebagai `f104801`/`c850af3`.
Review memastikan hanya pending Xendit terpilih secara bounded, GET berlangsung
di luar transaksi/RLS, dan hasil kembali ke processor/finalizer yang sama. Root
focused 30/177, legacy 22/98, default 1195/7472, Pint/PHPStan dan PostgreSQL
disposable 263/2332 lulus; cleanup sukses. P11b selesai internal, tetap tanpa
command/scheduler. Kelanjutan backend adalah audit authority P11c.

P11c0 proposal `6566cd7` diintegrasikan root sebagai `5ff488c`; ADR-010 diterima
untuk implementasi lokal bertahap. Keputusan memerlukan proof identity durable
sebelum writer dan entrypoint manual typed pada finalizer tanpa fake event Xendit.
P11c1a berikutnya schema-only; belum upload, review writer, UI, atau migration
database aktif.

P11c1a `1fb1da9`/`27631db` diintegrasikan sebagai `e6a53b3`/`3e36e2b`;
model/historical migration compatibility `41bffb9` dan portal fixture contract
`c72a304` ditambahkan saat review root. Synthetic default 1202/7527, focused 30/162,
Pint/PHPStan, serta PostgreSQL disposable 286/2428 lulus dengan cleanup sukses.
Default PHPUnit kini mengecualikan sandbox eksternal (`d864517`). P11c1b core
typed review/finalizer berikutnya; schema belum diterapkan ke database aktif.

P11c1b `8bc0310`/`235c991` diintegrasikan root sebagai `d8e21b7`/`ca4e21a`.
Review memastikan authority SuperAdmin persisted mendahului lookup bill, replay
terikat actor+proof+decision+reason, reject tidak melakukan settlement, dan
approve memakai settlement/activation/outbox primitive yang sama dengan provider.
Root manual review 22/102, provider terkait 73/710, legacy manual terisolasi 7/38,
default synthetic 1224/7629, Pint/PHPStan, serta PostgreSQL disposable 289/2471
lulus dan cleanup sukses. Kegagalan run campuran hanya berasal dari strategi
reset SQLite berbeda; masing-masing kelompok lulus terisolasi. P11c1b diterima;
P11c2 proof upload/access/policy/UI tetap berikutnya dan schema belum diterapkan
ke database aktif.

Backend turn `01a05c39-f02f-7481-912d-9d194c7ecfe5` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:10`) aktif pada P11c2a core
storage/replacement proof. Scope berhenti sebelum HTTP, reviewer proof access,
policy, route, Filament/UI, purge, dan layanan nyata; frontend/portal tidak diberi
instruksi baru.

P11c2a awal `24c9591`/`ec1e36b` ditahan karena expiry, participant revocation
race, dan canonical existing-proof belum tertutup. Fix `22407ad`/`b951c2e`
diintegrasikan seluruhnya sebagai `b9a819e`/`a8efd18`/`8bf0660`/`a113702`.
Root storage 37/201, P11c1b/provider 32/167, legacy upload 9/94, default
1261/7830, Pint/PHPStan, serta PostgreSQL disposable 292/2508 lulus dan cleanup
sukses. P11c2a diterima; P11c2b private reviewer access/policy berikutnya, tetap
tanpa route/UI aktif atau layanan nyata.

Backend turn `01a05c68-8fed-7990-8901-c9609b08c687` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:13`) aktif pada P11c2b policy dan internal
private-proof issuer. Scope berhenti sebelum controller/route/Filament/UI dan
decision wiring; frontend/portal tidak mendapat instruksi duplikat.

P11c2b `aa98577`/`458f327`/`eb2be9f` diintegrasikan root sebagai `3a605c9`/
`0887725`/`f926fc3`. Run focused paralel pertama terganggu collision direktori
Storage::fake lintas proses; run serial otoritatif lulus issuer 22/92,
storage+manual-review+provider 69/368, legacy access 3/10, Pint/PHPStan, default
1283/7922, dan PostgreSQL disposable 293/2519 dengan cleanup sukses. P11c2b
diterima; controller/route/Filament/UI dan decision wiring masih belum aktif.

Backend turn `01a05c82-124c-7093-a9a4-434c66ae206c` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:15`) aktif pada P11c2c HTTP adapter
test-only. Scope tidak mengizinkan registrasi route produksi, Filament/UI, atau
perubahan writer; frontend/portal tetap menunggu.

P11c2c `e4f82a9`/`db55abc` diintegrasikan root sebagai `3214126`/`9ee8768`.
Focused HTTP 27/196, regresi P11c 91/460, legacy proof 12/104, Pint/PHPStan dan
default synthetic 1310/8118 lulus. PostgreSQL tidak diulang karena query/lock/RLS/
schema P11c2b tidak berubah; bukti terakhir tetap 293/2519. Adapter diterima;
production route dan reviewer Filament/UI tetap belum aktif.

Backend turn `01a05c96-3d2f-75a0-8518-ed6671ee9e34` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:17`) aktif pada P11c3a reviewer
list/detail + open-proof action testing-only/default-off. Decision UI dan seluruh
aktivasi produksi tetap di luar scope; task lain tidak mendapat prompt duplikat.

P11c3a `d4eae99`/`20b0254` diintegrasikan root sebagai `e262714`/`354d7b2`.
Reviewer resource 10/96, portal cabang 14/210, legacy Filament 4/27,
Pint/PHPStan dan default synthetic 1320/8214 lulus. PostgreSQL tidak diulang
karena tidak ada schema/lock/RLS writer baru. P11c3a diterima; approve/reject UI
dan seluruh discovery/route non-testing tetap belum aktif.

Backend turn `01a05cae-fc8a-7c23-8359-25cac78c020e` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:19`) aktif pada P11c3b decision actions
default-off. Fingerprint harus berasal dari audit proof-access reviewer terbaru,
bukan current proof yang belum dilihat; aktivasi produksi tetap dilarang.

P11c3b `3d39987`/`ae58935` diintegrasikan root sebagai `877f0a3`/`fb8fd88`.
Decision UI 26/134, reviewer/finalizer/issuer 54/294, portal+legacy 18/237,
Pint/PHPStan dan default synthetic 1346/8352 lulus. Suite penuh lebih lama tetapi
proses tetap aktif dan berakhir sukses. PostgreSQL tidak diulang karena audit
lookup read-only dan writer/concurrency tetap finalizer yang sudah dibuktikan.
Browser acceptance default-off berikutnya; aktivasi produksi tetap dilarang.

Backend turn `01a05ce5-c61f-7861-a42a-491cde1b4564` (cursor
`67fb332a-ee06-4280-b3fe-55c3b79bcc9c:21`) aktif pada P11c3c browser acceptance
testing-only untuk desktop/mobile/keyboard dan role denial. Production discovery,
route, DB aktif, dan layanan eksternal tetap dilarang.

P11c3c `d00a1ea`/`4b797f0` diintegrasikan root sebagai `d5b7163`/`cbd861a`.
Review memastikan harness mem-boot Laravel/Filament asli dengan environment path,
storage, cache, session dan SQLite disposable; provider/notifier fake, stray HTTP
diblokir, serta origin hanya loopback. Browser Chrome cached lulus lima kelompok
acceptance desktop/mobile/keyboard, approve/reject/replay/replacement fence dan
role denial tanpa kebocoran DOM/URL. Root mengulang syntax Node/PHP dan focused
reviewer **36/36 tes, 234 assertions**. Percobaan reproduksi browser root tidak
dimulai karena orkestrasi cleanup proses/temp ditolak kebijakan command; bukti
browser worker yang sudah direview tetap otoritatif. P11c selesai lokal default-off;
discovery/route produksi, DB aktif dan layanan nyata tetap tidak diaktifkan.

Checkpoint P12a-prep diterima sebagai P12a lokal default-off setelah dependensi
P11c terpenuhi. Implementasi `9decef5`/`3a17d64` dan hardening berikutnya sudah
membuktikan list/detail cabang tenant-scoped, persisted reauthorization,
filter/paginator/riwayat satu sumber, proyeksi aman, runtime PostgreSQL non-owner,
serta browser desktop/mobile; focused root terakhir **14/14 tes, 210 assertions**.
Discovery non-testing tetap OFF sehingga ini bukan aktivasi produksi.

Portal task existing turn `01a05d47-74aa-7e80-ac47-602e911bc189` (cursor
`d086efb9-85a2-4a90-a2e5-0b0ff954212c:2`) aktif pada P12b core default-off:
multi-select attempt eligible → preview server-authoritative → satu reservasi
canonical. Route/discovery produksi, schema/config toggle, Xendit, proof,
reviewer/finalizer, command/scheduler dan layanan nyata tetap dilarang.

P12b awal `addccee`–`97f3774` ditahan karena page menangkap seluruh Throwable,
belum membuktikan reauthorization setelah preview, dan belum menguji lifecycle
checkbox Livewire sampai confirm. Fix `2f9c4ff`/`55672a9` diterima dan seluruh
rangkaian diintegrasikan root sebagai `e872b4b`–`ec86591`. Unexpected writer
failure kini propagate dan rollback, role/branch/deleted setelah preview ditolak,
digit-string checkbox dikanonisasi ketat, perubahan selection/consultation
membersihkan preview, serta redirect ke bill canonical terbukti. Root focused
**19/19 tes, 61 assertions**, Pint dan PHPStan lulus; diff-check bersih. Core P12b
diterima default-off; browser desktop/mobile/keyboard dan UI replay/stale menjadi
increment berikutnya sebelum acceptance P12b ditutup.

Portal task existing turn `01a05d5d-91cb-7111-80ed-d877ed157ae9` (cursor
`d086efb9-85a2-4a90-a2e5-0b0ff954212c:5`) aktif pada browser acceptance P12b
testing-only. Harness wajib mem-boot Laravel/Filament nyata dengan fixture temp,
deny outbound, role/tenant/direct-URL denial, keyboard/mobile/desktop, 10 attempt
→ satu bill canonical, replay/stale mutation dan secrecy; production discovery,
route, provider/notifier, DB aktif serta P12c tetap dilarang.

Browser P12b worker `2e2c788`/`e28d3ee`/`69857b7` diintegrasikan root sebagai
`71fc95a`/`fc8313f`/`697e210`. Setelah run monolitik timeout, harness dipecah tiga
fase bounded dan fresh run lulus selection/preview/confirm: 10 attempt, 20 aksi
native, 488 trusted events, enam geometry check 320/390/1280, stale consultation/
price, double-enter satu bill canonical, reload, secrecy, role/tenant/guest denial,
serta exact DB count 2 bill/11 charge/11 item/2 audit dan side-effect lain nol.
Root mengulang focused **19/19 tes, 61 assertions**, PHP/Node syntax dan Pint.
Bukti diterima parsial: detail existing masih overflow pada 320px dan asset bundle
detail belum lengkap sehingga diagnostic 404/Alpine/avatar muncul setelah redirect;
direct-detail denial browser juga belum lengkap. Acceptance P12b belum ditutup.

Portal task existing melanjutkan hardening P12a-detail/P12b default-off: perbaiki
overflow tanpa menyembunyikan data, sediakan asset/avatar lokal pada harness,
bersihkan console/network sampai detail reload, dan buktikan direct detail denial.
P12c serta seluruh aktivasi/layanan produksi tetap dilarang.

Hardening worker `f76be8d`/`4b92520`/`0a0d774` diintegrasikan root sebagai
`279450f`/`d5cf4d2`/`cd59d1e`. Breadcrumb detail kini pendek sementara referensi
lengkap tetap di ringkasan. Harness hanya melayani asset Filament yang lolos
realpath di public dan avatar data-URI; CSP/deny outbound tetap ketat. Fresh
browser lulus sembilan geometry check selection/preview/detail 320/390/1280,
console/network bersih setelah detail reload, serta direct detail denial guest,
role salah, tenant lama dan bill asing tanpa kebocoran. Exact DB count tetap
2 bill/11 charge/11 item/2 audit dan side-effect lain nol. Root gabungan empat
suite P12a/P12b **86/86 tes, 1.010 assertions**, PHP/Node syntax dan Pint lulus.
P12b diterima lokal default-off; sequential SQLite bukan klaim concurrency PG.

P12c awal `2bf56b6`/`7558915` ditahan karena rejection enum dipetakan lowercase,
subheading bertentangan dengan action upload, serta branch URL issuer belum
menyamai guard config/channel reviewer. Fix `889ed26`/`80f1cf2` diterima dan
seluruh rangkaian diintegrasikan root sebagai `4b8ff72`/`4f051af`/`6a87caa`/
`8fbf3cb`. Upload/replace tetap mendelegasikan writer P11c canonical; branch
issuer memuat ulang persisted role/tenant, manual channel, current fingerprint,
disk+TTL config, URL nonempty, lalu second locked recheck dan audit aman. Storage
failure disanitasi; DB/audit failure propagate dan rollback. Root storage+P12c
**50/50 tes, 278 assertions**, P12a terisolasi **14/14, 212 assertions**, Pint
dan PHPStan lulus. Run campuran nonotoritatif gagal hanya karena reset skema
SQLite antarsuite; tiap kelompok lulus terisolasi. Core P12c diterima default-off;
browser upload/open/replace/history berikutnya.

P12c browser harness `628d6bd`/`27a9bc9` diintegrasikan root sebagai `3f84181`/
`bf82667` sebagai evidence **belum lulus**, bukan acceptance. Fixture private
storage/alias URL lokal/control state selesai dan regresi P12b tetap hijau,
tetapi native activation hanya mengisi Livewire mountedActions `uploadProof`;
modal Filament/Alpine tetap x-show=false sehingga FileUpload tidak actionable.
Tidak ada source workaround atau klaim upload palsu. Port/session dibersihkan.
Kelanjutan task portal adalah reproduksi minimal modal Filament 5 dengan asset/
layout sama untuk membedakan bug harness dari konfigurasi header action; browser
atau driver lain belum dipakai sebelum akar sebab lokal diketahui.

Reproduksi minimal membuktikan akar masalah berada pada assertion harness terhadap
root dialog berukuran nol sementara window modal fixed tetap terlihat. Koreksi
browser `c3e8317`/`b8465bd`/`8e9767b` diintegrasikan root sebagai `da235c7`/
`eb5cd4b`/`5874c0a`; P12c dan regresi P12b lulus pada browser native dengan
desktop/mobile/keyboard, upload/replace/denial, geometry, dan network/console
bersih. Review root menemukan postcondition lama meng-hardcode dua audit sehingga
benar menahan acceptance setelah satu audit akses bukti yang sah.

Perbaikan verifier `c817fa0`/`90bea2a` diintegrasikan root sebagai `47d12e3`/
`a7f70f5`. Verifier sekarang menerima hanya dua profil exact: baseline dengan dua
audit reservasi dan tanpa proof, atau P12c dengan dua audit reservasi, tepat satu
audit akses terikat fixture, dan satu proof kanonik; konteks URL/key/checksum/secret
serta semua side effect tetap ditolak. Probe URL terselubung dan audit duplikat
gagal sebagaimana diharapkan. Root mengulang focused **64/64 tes, 490 assertions**,
PHP lint, Pint, dan PHPStan dengan hasil lulus. P12c diterima lokal default-off;
route/discovery produksi, database aktif, provider/notifier, deploy, dan P13 belum
diaktifkan.

## P13a0 backend — ADR accepted, implementasi belum dimulai

Backend existing menyerahkan proposal `85dcb27`/`e3345bb` dan koreksi hardening
`21d7a5b`/`699d099`, diintegrasikan root sebagai `d29e4fb`/`ef53c9c`/
`19b7077`/`bd464a5`. Root menerima ADR-011 setelah purpose/destination dihapus
dari caller input, raw idempotency key diganti digest durable terpisah, cascade
attempt direkonsiliasi dengan privacy/retention, replay dan race reissue dibuat
eksplisit, serta lock order owner diseragamkan.

Increment implementasi berikutnya tetap lane backend existing dan harus TDD:
migration/model lebih dahulu dengan SQLite + PostgreSQL disposable, kemudian
action issuer internal. Feature default OFF dan tidak boleh ada route/controller,
consume/session, provider/notifier, database aktif, deploy, atau P13b/P14 sampai
checkpoint berikutnya direview.

## P13a1 backend — schema/model accepted

Schema/model worker `67ae1d1` dan laporan `052eefe`, dua gelombang hardening
`e17f7fe`/`a494126` serta `2b886e9`/`90a3c88`, diintegrasikan root sebagai
`4bf20ff`–`a15ca8a`. Shared migration regression dari worker dipindahkan manual
ke baseline root dan dicatat sebagai `b341ab4`; file akhir byte-identik dengan
blob worker `6a10572`.

Review root menutup cross-scope attempt/client/source, client/organization,
source-system, RLS SELECT dengan row nyata, dan populated rollback tanpa ambient
service context. Root lulus focused **6/43**, PHP lint, Pint, PHPStan, dan full
PostgreSQL disposable **320/320 tes, 2.655 assertions** pada runtime non-owner/
NOBYPASSRLS; network/container disposable dibersihkan. P13a1 diterima lokal.
P13a2 action masih belum dimulai; config/route/consume/session/DB aktif/deploy
tetap dilarang.

## P13a2 backend — core issuer accepted

DTO/intent `4da938c`, issuer/test `732d845`, laporan `3e36e54`, serta hardening
clock/rollback `656f45f`/`7de7eb9` diintegrasikan root sebagai `57bef0c`–
`3965260`. Config default-off diterapkan root pada `948df01` karena file config
worker berasal baseline overlay.

Review memastikan clock database final dibaca setelah seluruh lock dan authority
effective client/source diperiksa kembali. Audit failure reissue mengembalikan
old generation byte-identik; package wajib active, source-allowed, memiliki item,
amount non-null/non-negatif, currency IDR, dan consultation amount non-negatif
bila ada. Root related checkout/schema **139/139 tes, 979 assertions**, PHP lint,
Pint, dan PHPStan lulus. P13a2 diterima lokal default-off. P13a3 concurrency PG
masih wajib; route/controller/consume/session/DB aktif/deploy tetap dilarang.

## P13a3 backend — concurrency accepted

Bug clock PostgreSQL dan bukti concurrency worker `f5723b1`/`63b5d6f`/`8d15297`
diintegrasikan root sebagai `ce84b1f`/`59ece6b`/`6f606e2`. Review memastikan
`clock_timestamp()` hanya dipilih untuk PostgreSQL setelah lock, sedangkan
SQLite mempertahankan `CURRENT_TIMESTAMP`; tidak ada input yang masuk ke fragmen
SQL tersebut. Suite dua proses menggunakan koneksi runtime non-owner terpisah,
barrier dan lock wait nyata, timeout bounded, serta tidak membawa raw bearer
melalui IPC atau laporan.

Root mengulang checkout/schema focused **29/29 tes, 274 assertions**, Pint, dan
PHPStan 0 error. PostgreSQL disposable penuh lulus **328/328 tes, 2.768
assertions** dan seluruh container/network milik run dibersihkan. P13a diterima
lokal default-off. Tidak ada route/controller, consume/session, database aktif,
provider/notifier, deploy, push, atau aktivasi publik. Increment berikutnya pada
task backend existing adalah P13b consume atomik saja; P14 tetap dilarang sampai
P13b direview.

## P13b backend — atomic consume accepted

Core consume worker `1641743`/`1453031` diintegrasikan root sebagai `605e54c`/
`30a57f1`. Boundary hanya menerima bearer `och1_` privat, lookup digest bounded,
mengunci dan memvalidasi ulang graph persisted, memakai wall clock database,
serta menukar ISSUED menjadi CONSUMED atau mengamati EXPIRED dalam transaksi
service miliknya. Semua kegagalan bearer menjadi `CHECKOUT_HANDOFF_INVALID` tanpa
fallback atau oracle; hasil hanya DTO scope internal dan belum membuat session,
cookie, controller, route, halaman, atau hak assessment.

Kegagalan Vite pada worktree dikonfirmasi sebagai artifact build yang tidak ada,
bukan regresi: branch root dengan manifest lulus Integrations **192/192 tes,
1.352 assertions**. Pint dan PHPStan penuh lulus. PostgreSQL disposable root
lulus **331/331 tes, 2.811 assertions**, termasuk single-winner, consume versus
reissue, dan revocation waiter pada koneksi runtime non-owner; cleanup sukses.
P13b diterima. P14 berikutnya harus contract-first untuk session privat,
CSRF/header/cookie/IDOR dan tetap default-off; tidak mengaktifkan endpoint publik.

## P14a0 backend — cross-site session ADR accepted

ADR awal worker `8ec66c4` ditahan karena global cookie `SameSite=Lax` tidak ikut
pada POST lintas-site dari dua source seleksi, sehingga response session baru
dapat mengganti pointer cookie login ONCAM yang tidak pernah dibaca request.
Amendment `149a19c` mengganti pilihan menjadi record `checkout_sessions` durable
service-only dengan selector-cookie khusus host-only, `Path=/checkout`, Secure,
HttpOnly, dan SameSite=Lax. Global StartSession/cookie auth serta mutasi config
request/Octane dilarang.

Root mengintegrasikan dokumen sebagai `0290aeb`/`0f6d327` dan menerima ADR-012
untuk implementasi lokal bertahap. Timeout idle 30 menit, absolute 120 menit,
dan terminal retention 30 hari tetap default usulan configurable, bukan nilai
bisnis hardcoded. Increment berikut hanya schema/model, migration additive,
RLS, rollback, dan tes disposable; belum action establish/hydrate, recovery,
HTTP, cookie runtime, route, config aktif, atau deploy.

## P14a1 backend — durable session schema accepted

Schema/model worker `227c6a1`, rollback-order fix `b4dbccd`, dan laporan
`98a5ebf` diintegrasikan root sebagai `7201667`/`b182436`/`dad35fe`. Tabel
`checkout_sessions` mengikat selector/CSRF digest dan seluruh scope ke handoff
CONSUMED/attempt/client/source melalui composite FK, membatasi satu session per
handoff dan satu ACTIVE per attempt, serta memaksa lifecycle/timestamp/terminal
reason di database. Model menyembunyikan kedua digest. PostgreSQL memakai FORCE
RLS service-only; SQLite trigger hanya bukti portabilitas, bukan klaim RLS.

Root mengulang schema SQLite **11/11 tes, 86 assertions**, Pint, PHPStan penuh
0 error, dan PostgreSQL disposable **351/351 tes, 2.900 assertions**; cleanup
sukses. P14a1 diterima lokal. Migration belum diterapkan ke database aktif dan
belum ada selector generation, recovery, establish/hydrate/revoke action,
cookie/CSRF runtime, route, config aktif, cleanup worker, atau deploy. Increment
berikutnya adalah amendment recovery P13 yang bounded sebelum session action.

## P13 recovery backend — terminal restart accepted

Recovery awal worker `eb3e774`/`e7944c8` ditahan karena hanya menerima session
ACTIVE-undued dan membuat EXPIRED/LOGOUT menjadi jalan buntu. Fix
`f16103e`/`8362203` menambah enum internal state: ACTIVE-undued direvoke
`RECOVERY_REISSUED`, ACTIVE-due diterminalkan EXPIRED dengan wall clock database,
sedangkan terminal EXPIRED/LOGOUT dapat memulai tepat satu generation baru tanpa
menulis ulang history. SCOPE_REVOKED, corrupt/foreign/future, serta terminal
recovery tanpa generation konsisten tetap ditolak.

Rangkaian diintegrasikan root sebagai `bf70824`/`4a65fa0`/`deeb34f`/`81c0475`.
Root lulus focused **26/26 tes, 348 assertions**, Pint, PHPStan penuh 0 error,
dan PostgreSQL disposable **360/360 tes, 3.057 assertions** dengan lock wait
nyata; cleanup sukses. Audit recovery hanya menambah prior-session-state
allowlist, tanpa ID session/token/digest/PII. Belum ada P14 establish/hydrate,
selector/cookie/CSRF runtime, HTTP, config aktif, DB aktif, outbound, atau deploy.

## P14a2 backend — atomic session establishment accepted

Refactor konsumsi kanonik dan action internal worker `5c13097`/`c4ff646` serta
laporan `13e2d87` diintegrasikan root sebagai `84c8223`/`0e1a559`/`2425f22`.
Review memastikan bearer P13 dikonsumsi dan record session dibuat dalam satu
transaksi service, selector dan CSRF 256-bit hanya disimpan sebagai digest, serta
rollback insert/audit dan race establishment-versus-recovery tetap fail-closed.
Kontrak publik P13 lama tidak berubah; tidak ada HTTP, cookie runtime, route,
global Laravel session, billing, entitlement, atau outbound yang ditambahkan.

Config session 30/120 menit dan retention 30 hari ditambahkan default OFF pada
root setelah membandingkan hunk worker. Root mengulang focused P13/P14 **32/32
tes, 459 assertions**, Pint, dan PHPStan 0 error. PostgreSQL disposable penuh
lulus **362/362 tes, 3.096 assertions** sebagai runtime non-owner/NOBYPASSRLS dan
cleanup sukses. P14a2 diterima lokal; delivery CSRF/browser, hydrate/revoke,
cleanup, HTTP headers/cookies, IDOR, dan source activation masih terbuka.

## P14a3 backend — private session lifecycle accepted

Lifecycle/DTO/test worker `2e33e7c`/`9f35ddc`/`5e1cacf` ditahan saat review
karena predicate riwayat handoff lebih longgar daripada validator P13 kanonik.
Fix `0422898` dan laporan `3a0ef35` menyatukan invariant ke
`CheckoutHandoffHistoryValidator`, memperketat invariant session, dan diterima
root sebagai `414cffb`–`57367a5`. Hydrate selector-only memperbarui idle expiry
yang dibatasi absolute expiry; logout wajib selector+CSRF; expiry, scope revoke,
audit, dan replay seluruhnya atomik serta fail-closed.

Root mengulang focused P13/P14 **42/42 tes, 538 assertions**, Pint, dan PHPStan
0 error. PostgreSQL disposable penuh lulus **364/364 tes, 3.124 assertions**
sebagai runtime non-owner/NOBYPASSRLS dan cleanup sukses. Projection tidak memuat
PII, detail batch, total, invoice, atau credential. P14a3 diterima lokal
default-OFF; HTTP/controller/route/cookie/header/browser dan cleanup worker belum
diimplementasikan atau diaktifkan.

## P14b0 backend — private HTTP contract accepted

Amendment ADR-012 worker `c62f215` dan laporan `3281c65` diintegrasikan root
sebagai `4faecc6`/`5adcbbf`. Kontrak menetapkan exchange POST body-only dari dua
Origin seleksi exact, selector serta CSRF-delivery cookie privat host-only,
hydration setelah verifikasi digest, CSRF eksplisit untuk semua mutasi, privacy
headers, generic errors, clear/logout/recovery, rate limit, dan route test-only
tanpa grup `web` maupun session/auth Laravel global.

Review menerima mekanisme raw CSRF dari cookie HttpOnly yang dicocokkan ke digest
database lalu diproyeksikan hanya ke hidden field/meta halaman aktif. Cookie
otomatis dan SameSite tidak pernah menjadi authority. `git diff --check` lulus;
increment ini dokumentasi saja sehingga tidak ada tes runtime yang diklaim.
Belum ada route/controller/middleware/config key/browser atau aktivasi endpoint.

## P14b1 backend — test-only HTTP adapter accepted

Adapter awal worker `ac17ffa`–`757ea6c` ditahan saat review karena destination
masih `oncam.id`, raw form duplicate dapat dikolaps framework, dan bukti cookie
login belum memakai sesi autentikasi nyata. Fix `e3ae84e`/`a49354f` menetapkan
`https://psikotes.oncam.id`, parser raw body kanonik bounded, named limiter, serta
probe `web`+`auth` dengan cookie Laravel terenkripsi sebelum/sesudah exchange.
Rangkaian diterima root sebagai `216ec64`–`52e5c72`.

Config HTTP fixed origins dan batas 10/60/10 ditambahkan di bawah session yang
tetap default OFF. Root lulus focused checkout **53/53 tes, 1.041 assertions**,
seluruh Integration **225/225 tes, 2.173 assertions**, Pint, dan PHPStan 0 error.
PostgreSQL disposable penuh tetap lulus **364/364 tes, 3.124 assertions** dan
cleanup sukses. Route hanya diregistrasikan oleh tes; produksi, browser nyata,
source activation, database aktif, outbound, deploy, dan push tetap tidak ada.
