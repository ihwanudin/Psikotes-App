# Baseline performa

Tanggal observasi awal: 2026-09-07 16:34 ICT

Revisi sumber awal: `35a8ed9`; checkpoint kode terverifikasi terakhir: `290a687`.
Lingkup: audit read-only atas kode/config, build sintetis lokal, tes SQLite
terisolasi, EXPLAIN fungsional pada PostgreSQL disposable, analyzer snapshot queue
sintetis, dan request pasif terhadap header publik. Tidak ada optimasi, migrasi,
load test, perubahan DNS/Cloudflare, penggunaan secret/data nyata, worker/outbound,
akses cache runtime, atau transaksi provider.

## Cara membaca status

- **READY**: mekanisme ada dan bukti yang relevan berhasil dihasilkan pada kondisi
  baseline ini.
- **PARTIAL**: sebagian mekanisme/bukti ada, tetapi belum cukup untuk menyatakan
  siap produksi atau menjaga regresi.
- **MISSING**: bukti atau kontrol yang diperlukan tidak ditemukan/dihasilkan.

Status di bawah menilai kesiapan _baseline yang dapat dibandingkan_, bukan menilai
fitur bisnis selesai. Hasil publik juga tidak diasumsikan menjalankan revisi Git
yang sama dengan worktree ini.

## Ringkasan

| Area                                    | Status      | Bukti angka utama                                                                                                                               | Risiko utama                                                                                                                           | Eksperimen berikutnya                                                                                       |
| --------------------------------------- | ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| N+1 dan query budget                    | **PARTIAL** | Portal SQLite 10/25/50 masing-masing **7 query**; guard testing `4d1cd35` lulus full suite **75 tes / 414 assertions** tanpa violation          | Guard lazy-load belum aktif pada local/production dan query count bukan latency/plan                                                   | Perluas coverage route sintetis, lalu pertimbangkan guard local setelah smoke test                          |
| PostgreSQL indexing dan EXPLAIN         | **PARTIAL** | List 10/50/500 (`649ceb9`/`0309aae`) dan collective preview 10/100 (`290a687`) punya functional SELECT-only EXPLAIN sebagai role runtime+RLS    | Statistik planner belum di-refresh; hasil bukan klaim index, latency, atau produksi                                                    | Tambahkan `ANALYZE` disposable yang terkontrol lalu ulangi plan dan simpan metrik per shape                 |
| Cache dan invalidation                  | **MISSING** | `c248366` + `360f84d`: inventory statis menemukan 1 `Cache::add` TTL 70 serta 3 unique-job lock TTL 300/900/900                                 | Hanya source allowlist pada working tree tepercaya/quiescent; tidak ada hit/miss, invalidation, Redis runtime, atau bukti lock efektif | Ukur Redis runtime sintetis dan definisikan inventory key/invalidation terpisah                             |
| Queue, jobs, backlog observability      | **PARTIAL** | `1b8da17` + `260ee18`: analyzer snapshot sintetis menghitung depth, oldest eligible age, leased/stale, retry/terminal, dan status SLO per queue | Tidak membaca Redis/DB, tidak menjalankan worker/outbound, dan belum membuktikan throughput/alert runtime                              | Ambil snapshot teredaksi melalui collector read-only yang direview, lalu korelasikan dengan worker sintetis |
| Route dan image lazy loading            | **PARTIAL** | `ffc7205` membuktikan 19 modul page/component dynamic dan tidak masuk initial static graph pada manifest; image terbesar 53.281 B               | Bukti hanya manifest; belum ada Lighthouse/RUM, avatar metadata, atau browser waterfall                                                | Jalankan Lighthouse terkontrol per route dan waterfall untuk welcome/registration/lobby                     |
| Vite minification dan bundle size       | **PARTIAL** | Guard `d0909d7`: initial JS **166.533 B gzip**, CSS **17.429 B gzip**, raw warning **520.428 B**, dan 19 dynamic imports                        | Minification teramati, tetapi variasi build dan enforcement package/CI belum dibuktikan                                                | Jalankan guard terhadap tiga build identik dan ukur variasi sebelum optimasi                                |
| Cloudflare/CDN headers dan cache policy | **PARTIAL** | HTTPS 200; HTTP→HTTPS 308; Brotli aktif; asset JS `HIT`, age 1.390 s, `max-age=14400`                                                           | Preload dari HTML HTTPS memakai URL `http://`; HSTS/immutable tidak terlihat; policy tidak versioned di repo                           | Reproduksi origin-vs-edge header matrix dan telusuri forwarding scheme/config sebelum perubahan             |

## Kondisi pengukuran dan reproducibility

- Host lokal: Windows, PHP CLI 8.3.26, Node 24.11.0, npm 11.6.1,
  Docker 29.7.2.
- Dependency aktual dari lock saat build: Laravel 13.26.1, Vite 8.2.2,
  `@inertiajs/vite` 3.7.0, `@inertiajs/react` 3.7.0, React 19.2.8, dan
  `laravel-vite-plugin` 3.2.0.
- `composer.lock` SHA-256:
  `44aa7ea181ecf0accdd18a05ae5da39bc8d9016c88431bfe9aebfcb536720e16`;
  content hash `b21bdd37abad257ab5658931659cdc4a`.
- `package-lock.json` SHA-256:
  `e8a50f8a14992153085d621e8c2dfca8dc8708f59d0fc40f8fdffa7f9d3a1253`;
  lockfile version 3.
- `docker compose config --quiet` lulus dengan nilai sintetis langsung di process
  environment; tidak ada `.env` yang dibaca atau dicetak.
- `npm ci --ignore-scripts` memasang 533 paket dan melaporkan 0 vulnerability.
- Composer memasang 175 paket dari lock tanpa scripts/plugins. CLI lokal tidak
  memuat `ext-zip`, sehingga instalasi hanya untuk build memakai
  `--ignore-platform-req=ext-zip`. Image `docker/app/Dockerfile` memasang `zip`,
  jadi hasil ini bukan parity runtime image.
- Percobaan build pertama gagal sebelum transformasi karena `vendor/` belum ada.
  Setelah autoload, package discovery, dan Wayfinder generation lokal, build
  berhasil. Direktori `vendor`, `node_modules`, generated Wayfinder, dan
  `public/build` adalah artefak ignored dan bukan perubahan sumber.

## 1. N+1 dan query budget — PARTIAL

### Bukti yang ada

- `tests/Feature/Admin/OrganizationBillAccessTest.php` menetapkan budget render
  Livewire `<=20` query untuk page size 10, 25, dan 50. Tujuannya eksplisit:
  mencegah authorization query per row. Setelah fixture DASS-21 wajib dipulihkan
  pada `f4b4cbe`, ketiga ukuran masing-masing menghasilkan **7 query**.
- `tests/Postgres/CollectiveBillPreviewTest.php` menetapkan `<=16` query untuk
  10 dan jumlah maksimum item, serta mewajibkan jumlah query sama pada kedua
  ukuran. Ini guard kompleksitas konstan.
- `tests/Postgres/OrganizationBillPortalTest.php` menetapkan list terpaginasikan
  `<=3` query pada 10/25/50 record dan detail 10 allocation `<=7` query.
- `tests/Feature/Admin/AssessmentBillReviewerFilamentTest.php` membatasi lookup
  bill terkait menjadi `<=4` query.
- `tests/Feature/Auth/AcceptedConsentReaderTest.php` berhasil lokal: **20/20 tes,
  41 assertions**; kontrak query reader tertentu memeriksa tepat satu
  `select exists`.
- Pemanggilan berkelompok/eager loading terlihat pada jalur pembayaran utama,
  misalnya `ClaimAssessmentBillInvoice`, `FinalizeAssessmentBill`,
  `PreviewAssessmentBill`, `AssessmentParticipantResource`, dan
  `ViewOrganizationBill`.
- `4d1cd35` mengaktifkan `Model::preventLazyLoading()` khusus environment
  `testing`. Tes memakai sedikitnya dua model persisted agar mekanisme Laravel
  benar-benar aktif, membuktikan lazy relation ditolak dan eager load diterima;
  regresi billing lulus **28 tes / 409 assertions** dengan query portal tetap 7.
  Full suite organization-payment kemudian lulus **75 tes / 414 assertions**
  tanpa lazy-loading violation tak terduga.

### Gap dan risiko

- Bukti 7 query berasal dari SQLite dan satu jalur portal; nilai itu tidak boleh
  diproyeksikan sebagai durasi atau jumlah query PostgreSQL untuk route lain.
- Guard lazy-loading sengaja baru aktif pada testing; local dan production tetap
  tidak berubah sampai full suite sintetis serta smoke test local tersedia.
  Belum ada budget umum untuk seluruh route/API.
- Query count tidak mengukur durasi, bytes/rows returned, lock wait, ataupun
  rencana eksekusi. Nilai SQLite tidak boleh dipakai sebagai bukti PostgreSQL.
- Beberapa operasi billing sengaja melakukan banyak lock/query terurut untuk
  konsistensi. Mengurangi count tanpa mempertahankan urutan lock dapat merusak
  correctness.

### Eksperimen berikutnya

1. Perbaiki hanya fixture/harness yang membuat tes budget berhenti, tanpa
   mengubah query produksi, lalu ulangi 10/25/50 pada tiga run.
2. Jalankan budget PostgreSQL yang sudah ada dengan dataset sintetis tetap dan
   catat query count, total DB time, rows, serta p50/p95 tiap shape.
3. Tambahkan capture khusus route welcome, registrasi, lobby, portal list/detail,
   dan dispatch outbox; tetapkan budget setelah distribusi baseline tersedia.

## 2. PostgreSQL indexing dan gap EXPLAIN — PARTIAL

### Bukti yang ada

- Terdapat **38** migration, **55** deklarasi index non-unik eksplisit, dan
  **79** deklarasi unique constraint/index. Angka adalah census source dan tidak
  menyatakan index benar-benar terpasang pada database mana pun.
- Shape penting sudah memiliki index yang searah, antara lain:
  `assessment_bills (organization_id, status, created_at)`,
  `assessment_participants (organization_id, assessment_status, created_at)`,
  `generic_assessment_result_callback_schedules
(state, next_dispatch_at, outbox_id)`, dan partial index
  `outbox_invoice_reconciliation_discovery_idx`.
- Queue outbox umum memiliki `(status, available_at)`, sementara aggregate lookup
  memiliki `(aggregate_type, aggregate_id)`.
- `649ceb9` menambahkan bukti fungsional `EXPLAIN (ANALYZE, BUFFERS, FORMAT
JSON)` untuk list bill tenant pada 10/50/500 row dan page 10/25/50. Tes memakai
  role `psikotes_runtime` non-superuser/non-bypass RLS, memastikan node tetap
  SELECT-only, row hasil tetap tenant-scoped, dan jumlah row tidak berubah.
- `290a687` menerapkan batas yang sama pada actual collective preview untuk 10
  dan maksimum 100 pilihan. Masing-masing menangkap 12 plan SELECT; statement
  context RLS divalidasi exact, output tidak memuat SQL/binding/identifier, dan
  full runner disposable lulus **405 tes / 4.459 assertions**.

### Gap dan risiko

- Bukti `649ceb9` belum menjalankan `ANALYZE` setelah seed sintetis, sehingga
  estimasi/cardinality planner dapat memakai statistik yang belum diperbarui.
  Ini bukti jalur EXPLAIN fungsional, bukan bukti pemilihan index, performa
  produksi, atau rekomendasi index.
- Tidak ada evidence `pg_stat_statements`, cardinality, selectivity, buffer hit,
  sort/spill, dead tuple, atau write amplification. Dengan RLS, plan juga harus
  diukur memakai role/konteks runtime, bukan owner/bypass role.
- Query dispatch notification memfilter `topic`, `attempts`, `expires_at`, status
  bercabang, `available_at`, dan stale `updated_at`; index umum
  `(status, available_at)` belum terbukti cukup untuk shape itu.

### Eksperimen berikutnya

Gunakan database staging/sintetis dengan distribusi kecil dan besar. Untuk setiap
shape di bawah, ambil plan cold/warm memakai role runtime dan konteks RLS yang sah:

1. portal organization bill filter + sort + pagination;
2. collective preview 10 dan maksimum item;
3. notification/integration outbox discovery;
4. generic callback due schedule;
5. assessment invoice reconciliation partial-index lookup.

Catat `Planning Time`, `Execution Time`, actual/estimated rows, shared hit/read,
sort, temp spill, lock wait, dan WAL. Jangan menambah index sebelum plan ini ada.

## 3. Cache dan invalidation — MISSING

### Bukti yang ada

- `compose.yaml` memaksa `CACHE_STORE=redis`; production boot juga menolak cache
  selain Redis. Redis memakai DB cache terpisah (`REDIS_CACHE_DB=1` default).
- `config/cache.php` menyediakan prefix per aplikasi dan menolak unserialisasi
  class cache secara default.
- Pemakaian facade cache eksplisit pada kode aplikasi yang ditemukan hanya
  `Cache::add` dengan TTL 70 detik di
  `AuthenticateSelectionResultPoll`, sebagai deduplikasi log/rate signal.
- Unique queued jobs memakai TTL lock 300/900 detik, tetapi itu mekanisme queue
  uniqueness, bukan baseline cache data/domain.
- Inventory statis `c248366`, lalu hardening lexer `360f84d`, memverifikasi
  allowlist source yang sama tanpa membaca cache runtime. Parser membedakan kode,
  komentar, dan string decoy; output juga mencatat batas
  `trusted_quiescent_working_tree_only` serta race mutasi/reparse yang tidak
  dikecualikan.

### Gap dan risiko

- Pernyataan arsitektur bahwa bank soal statis berada di Redis/HTTP cache belum
  memiliki implementasi/inventory yang dapat ditemukan pada increment ini.
- Tidak ada hit/miss/eviction/latency/memory baseline, key-cardinality budget,
  stampede protection, atau dokumentasi owner untuk invalidation.
- Tidak ada strategy TTL/event/tag/versioned-key untuk data domain. Menerapkan
  cache sebelum key memasukkan tenant, locale, viewer/permission, dan versi
  instrumen berisiko cross-tenant leak atau data psikometrik stale.
- Status tetap **MISSING** untuk observability cache runtime: inventory statis
  tidak menghasilkan hit/miss, RTT, eviction, cardinality, atau efektivitas lock.

### Eksperimen berikutnya

Mulai dari observability: inventory semua cache/lock key beserta key dimensions,
TTL, freshness contract, owner writer, dan invalidation. Ukur query/serialization
cost tanpa cache serta Redis RTT/hit rate pada data sintetis. Hanya kandidat yang
mahal dan read-heavy boleh lanjut ke eksperimen cache dengan stampede test.

## 4. Queue, jobs, dan backlog observability — PARTIAL

### Bukti yang ada

- Compose memisahkan dua worker: `notifications,default` dan `integrations`.
  Keduanya memakai `--tries=5`, `--timeout=120`, `--max-time=3600`; integrations
  juga membatasi memory 256 MiB. Retry-after Compose default 150 detik, lebih
  besar dari timeout worker.
- Ada **3** job class. Semua unique; TTL unique 900/900/300 detik.
  `DeliverOutboxMessage` mempunyai backoff 30/120/600/1.800 detik.
  Generic result job mempunyai timeout 45 detik, satu try, fail-on-timeout,
  encrypted payload, `afterCommit`, dan `failed()` recovery hook.
- Scheduler menjalankan notification/integration dispatcher tiap 1 menit;
  reconciliation dan generic callback tiap 5 menit. Dispatcher membatasi batch
  notification/integration ke 1–500 (default 100) dan generic result maksimal
  100 (schedule command memakai 25).
- Outbox serta callback schedule mempunyai status/attempt/lease/index dan jalur
  recovery; ini menyediakan state yang dapat diobservasi secara query.
- Analyzer report-only `1b8da17`, diperketat oleh `260ee18`, menerima hanya
  snapshot dan threshold sintetis bounded. Laporannya deterministik dan
  mengecualikan identifier: depth, oldest eligible age, leased/stale,
  retry/terminal, serta status SLO per queue; tenant/topic/message ID tidak
  dikeluarkan.

### Gap dan risiko

- **MISSING:** tidak ditemukan Horizon, Pulse, `queue:monitor`, QueueBusy/event
  metrics, atau implementasi alert queue depth/oldest age. `DEPLOYMENT.md` baru
  menyatakan intent agar queue depth dipantau.
- Tidak ada baseline backlog per queue, enqueue-to-start latency, processing
  duration, success/retry/failure rate, worker saturation, Redis memory, atau
  oldest eligible outbox age.
- Analyzer tidak mengakses Redis/DB dan tidak menjalankan worker maupun outbound.
  Karena itu hasilnya adalah validasi perhitungan snapshot, bukan telemetry,
  throughput, recovery, atau alert runtime.
- Service queue tidak memiliki healthcheck; status container hidup tidak
  membuktikan worker masih mengonsumsi queue. Log rotation/central aggregation
  juga hanya didokumentasikan sebagai target.

### Eksperimen berikutnya

Seed pekerjaan sintetis tanpa outbound nyata ke `notifications`, `integrations`,
dan `default`. Ukur pada batch 1/25/100: depth, oldest age, enqueue-to-start,
duration, throughput, retry, terminal failure, unique-lock residue, dan recovery
setelah worker restart. Tetapkan SLO/alert hanya dari hasil itu.

## 5. Route dan image lazy loading — PARTIAL

### Bukti yang ada

- `@inertiajs/vite` 3.7.0 default `lazy: true`; build manifest membuktikan
  **19 dynamic imports** untuk page/component Inertia. Build menghasilkan
  **45 JS chunks**, bukan satu bundle semua halaman.
- Guard `ffc7205` memastikan 19 module di `resources/js/pages/**` merupakan
  dynamic entry yang reachable melalui dynamic edge dan tidak masuk initial
  static graph. Missing/orphan, eager import, duplikat casefold, dan path escape
  ditolak. Ini hanya evidence manifest; `browserRuntimeObserved` tetap false.
- Source mempunyai 19 file di `resources/js/pages`; manifest memisahkan welcome,
  registration, auth, settings, dashboard, dan participant lobby.
- Aset raster statis terbesar hanya logo PNG **53.281 byte**; seluruh gambar
  statis lain: favicon ICO 4.286 B, favicon SVG 3.545 B, apple icon 1.662 B.
- Logo `<img>` yang ditemukan mempunyai width/height eksplisit. Logo welcome,
  registration, checkout berada above-the-fold, sehingga tidak memakai lazy
  loading adalah masuk akal pada source saat ini.

### Gap dan risiko

- Tidak ada Lighthouse trace, network waterfall, LCP/INP/CLS, atau RUM. Karena itu
  efektivitas splitting dan prioritas image belum terukur.
- Avatar user memakai URL dinamis dan ukuran CSS, tetapi tidak menyatakan
  `loading`, `decoding`, atau intrinsic width/height pada primitive image.
- Logo di-import melalui dua cara (path publik dan module import); belum ada
  regression guard untuk duplikasi/request behavior. Tidak ada responsive
  `srcset`, meskipun ukuran file saat ini kecil.

### Eksperimen berikutnya

Jalankan Lighthouse mobile terkontrol dan waterfall pada welcome, registration,
lobby, serta satu route settings. Catat request count, transferred JS/CSS/image,
LCP element, CLS attribution, long tasks, dan unused JS. Uji avatar dengan URL
sintetis lambat/fail; jangan menambah lazy loading pada elemen LCP tanpa bukti.

## 6. Vite minification dan bundle size — PARTIAL

### Bukti build lokal

Perintah: `npm run build`, sesudah dependency generation yang dicatat pada bagian
reproducibility. Hasil:

| Metrik                                        |                            Nilai |
| --------------------------------------------- | -------------------------------: |
| Durasi build                                  |                          49,66 s |
| Modul transformed                             |                            2.317 |
| JS chunks                                     |                               45 |
| Seluruh JS                                    |   711.959 B raw / 230.924 B gzip |
| Initial JS graph (`app.tsx` + static imports) | 166.533 B gzip (guard `d0909d7`) |
| Largest raw chunk                             |   520.428 B; warning `>=512.000` |
| Entry CSS                                     |  17.429 B gzip (guard `d0909d7`) |
| Seluruh CSS                                   |    109.700 B raw / 17.968 B gzip |
| Total 3 WOFF2                                 |                         51.490 B |
| Dynamic imports dari entry                    |                               19 |

Guard `d0909d7` membaca build existing secara report-only dan fail-closed. Output
yang diukur sudah minified, tetapi minification tetap **PARTIAL** sebagai baseline:
initial JS berada di bawah budget 200 KiB gzip dan CSS di bawah 50 KiB gzip,
sementara largest raw chunk melampaui warning 500 KiB dan enforcement pada
package scripts/CI serta variasi antarrun belum dibuktikan.

Plugin time terbesar adalah React Babel transform: 17,0 s (34% build), disusul
Wayfinder buildStart 2,7 s dan Tailwind generate 2,3 s. Ini angka build-time,
bukan bukti user-runtime bottleneck.

### Eksperimen berikutnya

Ulangi build tiga kali pada host yang sama dan simpan median/variasi manifest.
Tambahkan report-only budget untuk initial JS gzip, entry CSS gzip, largest chunk,
dan unexpected eager imports. Setelah baseline stabil, gunakan visualizer untuk
menentukan isi main chunk; jangan memecah dependency hanya berdasarkan ukuran raw.

## 7. Cloudflare/CDN headers dan cache policy — PARTIAL

Observasi dilakukan dengan satu HEAD per URL dan satu GET yang body-nya dibuang
untuk verifikasi content encoding. Ini bukan load test.

| Target                       | Bukti response 2026-09-07                                                                                       | Penilaian                                                            |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| `http://psikotes.oncam.id/`  | 308 ke HTTPS                                                                                                    | **READY** untuk redirect yang diamati                                |
| `https://psikotes.oncam.id/` | 200; `Cache-Control: no-cache, private`; `cf-cache-status: DYNAMIC`; `Vary: X-Inertia, accept-encoding`; Brotli | **READY** untuk tidak meng-edge-cache HTML session pada sample       |
| Logo PNG                     | 200; 53.281 B; `max-age=14400`; `cf-cache-status: REVALIDATED`; ETag/Last-Modified                              | **PARTIAL**, cacheable tetapi hanya 4 jam dan tidak immutable        |
| JS fingerprinted publik      | 200; `max-age=14400`; `cf-cache-status: HIT`; age 1.390 s; Brotli                                               | **PARTIAL**, edge bekerja tetapi policy immutable/long TTL tidak ada |
| HSTS                         | Header tidak terlihat pada sample HTTPS                                                                         | **MISSING**                                                          |
| Scheme preload               | Semua font/CSS/JS dalam header `Link` halaman HTTPS memakai `http://psikotes.oncam.id/...`                      | **MISSING/berisiko tinggi**                                          |

`docker/nginx/default.conf` tidak mendefinisikan cache header static assets,
compression, HSTS, atau forwarding `X-Forwarded-Proto` ke PHP. Laravel hanya
memercayai `HEADER_X_FORWARDED_PROTO` dari proxy private di `bootstrap/app.php`.
Karena respons publik membentuk preload HTTP dari request HTTPS, hipotesis utama
adalah scheme tidak sampai/diterima pada boundary Cloudflare tunnel → Nginx →
FastCGI, atau konfigurasi public `APP_URL` tidak sesuai. Ini inferensi, bukan
root-cause proof.

Risikonya bukan hanya redirect tambahan: mixed-scheme preload dapat diblokir,
tidak dipakai ulang sebagai resource HTTPS yang diharapkan, atau mengekspos
konfigurasi proxy yang salah. Header publik juga memuat cookie session pada
landing page dan `x-inertia-devtools-*`; dampak performa/konfigurasi perlu
ditelusuri terpisah, tanpa menyimpulkan environment deployment dari header saja.

### Eksperimen berikutnya

1. Pada staging identik, capture header di tiga titik: PHP/Laravel langsung,
   Nginx lokal, dan Cloudflare edge. Catat `scheme`, `Host`, dan forwarded header
   tanpa mencetak cookie/secret.
2. Verifikasi asset fingerprinted dan non-fingerprinted: cold MISS, warm HIT,
   TTL/age, ETag/304, Brotli/gzip, `Vary`, dan purge behavior.
3. Definisikan policy versioned: HTML private/no-cache; fingerprinted assets
   public + long TTL + immutable; upload privat dikecualikan; HSTS hanya setelah
   seluruh subdomain/HTTPS tervalidasi.
4. Setelah scheme diperbaiki, ulangi HEAD/GET dan browser waterfall; acceptance
   mensyaratkan semua preload HTTPS dan tidak ada mixed-content warning.

## Urutan baseline berikutnya

1. Ulangi query budget dan EXPLAIN PostgreSQL setelah statistik planner disposable
   diperbarui, tanpa mengubah query/index produksi.
2. Tambahkan collector telemetry read-only yang teredaksi untuk cache dan queue;
   inventory/analyzer murni saat ini tidak menyentuh runtime.
3. Jalankan guard bundle pada tiga build identik dan catat variasinya.
4. Reproduksi serta lokalisasi mixed-scheme header pada staging.
5. Ambil Lighthouse/waterfall terkontrol.

Baru setelah lima langkah ini tersedia, setiap optimasi harus diperlakukan sebagai
hipotesis tunggal: ukur kondisi sama, ubah satu hal, ukur ulang, pertahankan hanya
jika peningkatan melampaui noise dan seluruh correctness test tetap hijau.
