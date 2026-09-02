# P12a-prep — portal cabang baca-saja

## P12c browser acceptance — koreksi probe dan lulus

Tanggal 2026-09-02. Commit `c3e8317` menambah reproduksi testing-only dengan
`Action::make()->schema([TextInput, FileUpload])`, modal action resmi Filament 5,
dan instrumentasi Alpine/Livewire. Reproduksi menemukan bahwa laporan
`27a9bc9` salah mengartikan `dialog.isVisible()`: elemen luar `role=dialog`
memiliki tinggi layout nol karena overlay dan window diposisikan fixed, tetapi
`.fi-modal-window` terlihat, state Alpine `isOpen=true`, nesting action bernilai
0, serta event `sync-action-modals` dan `open-modal` benar-benar terjadi.
Space native membuka modal dan Enter native mengirim form minimal; dua response
Livewire 200, seluruh input keyboard/click/change `isTrusted=true`, tanpa console,
network failure, atau outbound. Tidak ada perubahan pada resource produksi.

Commit `b8465bd` memperbaiki harness saja: server memakai document root
`public`, temporary upload Livewire `tmp-for-tests` menunjuk storage disposable,
CSP mengizinkan worker blob FilePond, JPEG sintetis dapat didekode, dan attempt
baseline memiliki funding mode serta metadata organisasi yang valid untuk
snapshot charge P11. Environment/cache/session/SQLite/temp upload/proof tetap di
direktori temp tanpa `.env`; origin non-loopback dan route di luar allowlist
tetap ditolak. Tidak ada payment provider, notifier, mail, atau HTTP nyata.

Acceptance fresh Chrome CLI pada fixture
`oncam-collective-page-baa9a34d47454c5982a56a158eb5c084` **lulus**:

- unggah JPEG, ganti PNG, dan ganti PDF melalui FileUpload UI; status bill tetap
  `pending`, satu file proof kanonik, dan ringkasan server mencerminkan MIME;
- URL proof pertama merespons 200 dengan `no-store/private`, `no-referrer`, dan
  `nosniff`; setelah replacement URL lama 404 dan audit akses hanya satu;
- replacement eksternal di antara modal dan submit membuat modal stale gagal
  tertutup, mempertahankan PNG eksternal; invalid MIME dan 5.120.001-byte PDF
  juga gagal tanpa file parsial;
- Enter ganda pada submit, Buka bukti, dan reload tidak menggandakan file/action;
  hasil akhir tetap satu proof JPEG;
- `rejected`, `expired`, `paid`, dan kanal nonmanual menyembunyikan upload;
  mutasi persisted role, tenant, dan soft-delete setelah modal terbuka menolak
  action dengan 403, 403, dan 302. URL bill asing dan ID tidak ada tetap ditolak;
- detail dan modal masing-masing lulus pada 320/390/1280: enam pemeriksaan,
  `scrollWidth` sama dengan viewport dan nol elemen terlihat terpotong;
- Escape dan Cancel menutup modal; total 378 dari 378 event keyboard/click/change
  yang dicatat adalah trusted. Pemilihan file menggunakan API browser
  `setInputFiles`; aktivasi action, submit, cancel, dan open memakai Tab,
  Space/Enter/Escape native;
- 346 response diamati. Enam response 302/403/404 adalah denial yang memang
  diharapkan; tidak ada failure/outbound atau console error di luar denial itu.
  DOM/URL tidak memuat private metadata, object key, checksum, gateway, invoice,
  atau control secret. Audit context juga tidak memuat URL/key/checksum.

Summary sebelum regresi P12b: 1 charge, 1 bill item, 1 proof file, 1 audit akses;
entitlement, outbox, order, dan item settled tetap nol. Regresi browser P12b pada
fixture yang sama lulus: 20 aksi native, 484 trusted events, sembilan geometry
check 320/390/1280, 85 response, dan nol console/blocked/failure. Focused PHPUnit
`OrganizationBillAccessTest`, `OrganizationBillProofTest`, dan
`AssessmentBillProofStorageTest` lulus **64 tes / 490 assertions**. Pint,
Prettier, Node syntax, PHP lint, dan PHPStan harness/minimal fixture lulus.

PostgreSQL/race tidak diulang dalam increment browser ini; bukti PG sebelumnya
tidak diklaim ulang. Driver hanya Chrome installed/headless. Tidak ada screen
reader atau browser engine lain. P12c tetap testing-only/default-off, tidak ada
writer invoice/settlement/entitlement baru, dan pekerjaan berhenti sebelum P13
atau aktivasi publik.

## P12c browser acceptance probe — belum lulus

Tanggal 2026-09-02. Commit `628d6bd` memperluas harness loopback P12b yang
sudah diterima dengan fixture P12c saja: bill baseline manual dipindahkan ke
pending dengan expiry future, JPEG/PNG/PDF serta MIME/size invalid dibuat di
direktori temp, disk `payment-proofs` menunjuk hanya ke storage fixture, dan URL
proof memakai alias angka lokal dengan pemeriksaan actor/tenant/current key.
Alias lama menjadi 404 setelah key berubah. Response fixture proof menetapkan
`no-store, private`, `no-referrer`, dan `nosniff`. Tidak ada path object, checksum,
atau credential dalam URL. Control header sintetis dapat mengubah status,
role/tenant/deleted, melakukan replacement kanonik, dan membaca summary counts
tanpa mengembalikan key/checksum/fingerprint.

Harness tetap menolak seluruh origin non-loopback dan route yang tidak di-
allowlist. Livewire temporary upload hanya diizinkan pada path signed fixture;
preview blob lokal ditambahkan ke CSP agar FileUpload tidak menghasilkan false
positive. Environment path, cache, SQLite, session, storage, file proof, dan
alias semuanya berada di direktori temp baru tanpa `.env`. Payment provider,
notifier, HTTP, dan mail tetap fake/deny. Tidak ada route/config/schema produksi.

Acceptance P12c **belum dapat dinyatakan lulus**. Reproduksi fresh Chrome CLI
menemukan batas Filament/Alpine pada action modal halaman resource nyata:

- Tab native mencapai tombol `Unggah bukti` dengan `:focus-visible`;
- Space/Enter menghasilkan click native `isTrusted=true` dan state Livewire
  `mountedActions` berisi `uploadProof` dengan record key yang benar;
- elemen dialog kemudian tetap `x-show=false`/hidden selama wait 30 detik di
  `run-code`, sehingga input FileUpload tidak dapat diuji sebagai pengguna;
- aktivasi mouse dalam boundary `run-code` menunjukkan hasil hidden yang sama.
  Snapshot CLI dapat melihat subtree dialog, tetapi visibility/actionability
  tetap false; subtree hidden tidak dipakai untuk mengarang bukti upload.

Eksperimen workaround key handler/DOM dispatch dibatalkan dan tidak masuk diff.
Tidak ada perubahan resource produksi pada increment ini. Karena modal tidak
actionable, laporan ini tidak mengklaim upload JPEG/PNG/PDF, replace/stale modal,
open-proof headers/audit, invalid upload, atau denial direct action telah lulus
di browser. Behavior tersebut tetap dibuktikan pada focused PHP tests, bukan
disetarakan dengan acceptance browser.

Bukti yang lulus:

- storage kanonik + P12c feature: **50 tes / 278 assertions**;
- P12a access: **14 tes / 212 assertions**, query tetap 7 untuk page size
  10/25/50;
- P12b selection/preview/component: **72 tes / 798 assertions**;
- regresi browser fresh P12b pada fixture
  `oncam-collective-page-d85a9546c4894e83a4fbba73af38ffe5` lulus: 20 aksi
  native, 488 trusted events, 9 geometry check 320/390/1280, 85 response, dan
  nol console error/warning, blocked outbound, atau request failure;
- verify database fixture lulus: 11 charge, 2 bill, 11 item, 2 audit; seluruh
  entitlement/outbox/order/consent/identity tetap nol;
- PHP lint dan Pint harness lulus; Node syntax, Prettier, ESLint helper P12b
  lulus; PHPStan resource/issuer **0 error**.

PostgreSQL/race tidak dijalankan dan tidak diklaim. Server, browser session, dan
port 8012 sudah ditutup. P12c tetap default-off dan berhenti untuk review sebelum
P13/aktivasi. Langkah berikutnya memerlukan keputusan apakah modal harus diuji
dengan driver/browser lain yang mampu menyelesaikan state Alpine, atau apakah
bug integrasi Filament 5 ini perlu reproduksi minimal terpisah sebelum source fix.

## P12c review hardening — koreksi 2bf56b6/7558915

Tanggal 2026-09-02. Commit `889ed26` memperbaiki kontrak internal setelah
review dan menggantikan dua klaim lama di bagian P12c core di bawah: expiry URL
tidak lagi hardcoded 15 menit, dan payment method kini benar-benar dimuat serta
dikunci ulang pada fase kedua. Disk harus tepat `payment-proofs`; TTL harus
integer 1–60 dari konfigurasi kanonik. Konfigurasi salah berhenti sebelum akses
storage. Object hilang tetap not-found, sedangkan exception `exists`, exception
temporary URL, dan URL kosong menjadi error unavailable yang tidak membawa path,
credential, atau URL privat.

Kedua fase memuat PaymentMethod secara persisted; fase audit memakai
`lockForUpdate` untuk admin, bill, dan method. Bill dengan `gateway_ref` atau
`invoice_url` tidak boleh diperlakukan sebagai manual transfer. Proof identity
dan expected fingerprint tetap diperiksa ulang. Kegagalan insert audit tidak
disanitasi menjadi storage error: exception database sintetis merambat dan
transaksi tidak meninggalkan baris audit.

UI kini memetakan nilai enum kanonik uppercase ke lima label aman. Nilai unknown
tidak ditampilkan dan tidak dikirim sebagai fallback mentah. Subheading juga
menjelaskan bahwa halaman tidak melakukan pembayaran/verifikasi dan upload bukti
tidak berarti tagihan lunas atau peserta memperoleh akses tes.

Bukti terfokus dengan overlay P11c identik yang tidak di-stage:

- storage kanonik P11c + portal P12c: **50 tes / 278 assertions lulus**;
- regresi akses P12a: **14 tes / 212 assertions lulus**, termasuk query list
  tetap 7 untuk page size 10/25/50;
- Pint lulus, PHPStan dua class production **0 error**, PHP lint dan diff check
  lulus. Percobaan PHPStan pertama berhenti pada guard bootstrap production
  karena environment proses tidak lengkap; pengulangan eksplisit dengan
  `APP_ENV=testing` lulus tanpa membaca atau mengubah `.env`.

Tidak ada PostgreSQL atau browser baru pada review fix ini. Bukti race/RLS tetap
belum diklaim. Resource tetap testing-only; tidak ada aktivasi gate, route,
reviewer/finalizer, pembayaran, settlement, entitlement, outbox, atau outbound.

## P12c core default-off — delta dari 0a0d774

Tanggal 2026-09-02. Commit `2bf56b6` menambah boundary internal P12c pada
resource OrganizationBills yang tetap hanya discovered dalam environment
testing. Tidak ada route publik, config toggle, reviewer/finalizer, provider,
notifier, command, job, atau perubahan schema dalam commit lane ini.

Upload header action menerima berkas temporer tanpa menduplikasi daftar MIME,
ukuran, checksum, namespace key, state bill, allocation, atau replacement fence.
Semua keputusan itu tetap dilakukan `StoreAssessmentBillProof` kanonik melalui
`AssessmentBillProofUpload` dengan fingerprint current proof dari state Locked.
BranchAdmin, membership, bill organization, manual-transfer pending, expiry,
dan proof fingerprint dimuat ulang server. Upload/replace tidak mengubah paid,
settlement, entitlement, audit pembayaran, atau outbox.

Detail hanya memproyeksikan waktu upload UTC, label MIME JPEG/PNG/PDF, ukuran,
status bill, dan rejection code yang dipetakan ke lima label bounded. Object key,
checksum, gateway/invoice, URL privat, dan context audit tidak masuk Livewire/
HTML. Partial legacy/corrupt proof identity ditampilkan fail-closed tanpa tombol
open; writer kanonik tetap menolak penggantian ambigu.

`OrganizationBillProofUrlIssuer` khusus cabang tidak memakai authority reviewer
SuperAdmin. Ia hanya menerima BranchAdmin persisted dan bill organization milik
branch persisted, memvalidasi fingerprint current proof, memastikan object
private tersedia, membuat URL 15 menit di luar transaksi, lalu mengulang actor,
tenant, bill, method, dan fingerprint di transaksi terpisah sebelum audit.
Audit hanya berisi versi, fingerprint opaque, dan expiry; URL, key, checksum,
PII, serta token tidak dicatat. Role/deleted/cross-tenant, proof replaced/cleared,
foreign/nonmanual/missing proof gagal generik tanpa audit.

Untuk menjalankan focused test pada snapshot worker lama, file P11c canonical
Store/DTO/error/identity, model AssessmentBill, migration proof identity, dan
`AssessmentBillProofStorageTest` disalin identik dari root sebagai overlay lokal;
hash diverifikasi dan seluruh overlay tidak di-stage/commit. Bukti:

- P11c storage canonical + P12c portal: **43 tes / 254 assertions lulus**,
  mencakup MIME/size, cleanup, rollback, replacement/stale fence dan failure I/O;
- P12a access regression terpisah: **14 tes / 212 assertions lulus**, query
  pagination tetap 7 untuk ukuran 10/25/50;
- Pint lulus, PHPStan dua class production **0 error**, PHP lint dan diff check
  lulus.

Tidak ada PostgreSQL atau browser baru pada increment core ini. Issuer memakai
lock untuk mengikat recheck dan audit, tetapi hasil ini tidak mengklaim race atau
RLS PostgreSQL; pembuktian concurrency harus memakai runner disposable pada
increment terpisah bila diminta. Storage memakai fake private disk dan file
sintetis. Gate tetap default-off dan pekerjaan berhenti sebelum browser/P12c
activation.

## P12a-detail/P12b browser hardening — delta dari 69857b7

Tanggal 2026-09-02. Root menerima tiga commit browser sebelumnya sebagai bukti
parsial. Fix source `f76be8d` mengganti breadcrumb detail yang memuat referensi
panjang dengan label `Tagihan terpilih`; referensi lengkap tetap tampil di
Ringkasan tagihan, sehingga informasi tidak disembunyikan. Focused regression
memastikan breadcrumb tidak mengulang public reference.

Harness `4b92520` hanya melayani asset Filament yang benar-benar ada di bawah
`public/{js,css,fonts}/filament`, dengan pemeriksaan realpath tetap di dalam
public. Provider avatar sintetis mengembalikan data URI. CSP dan deny outbound
tidak dilonggarkan. Browser menguji direct detail sebagai guest, role salah,
membership lama, dan foreign bill melalui request tanpa redirect otomatis;
status generik 302/403/404 diterima hanya bila response tidak memuat referensi
atau label asing.

Run fresh fixture `oncam-collective-page-697f0500db3c497abcfb147d649d401c`
lulus tiga fase. Selection/preview/confirm lama tetap lulus; total 20 aksi native
dan 488 trusted events. Geometry detail baru lulus pada 320/390/1280, sehingga
seluruh sembilan check mempunyai document width tepat viewport dan nol elemen
critical terpotong. Setelah detail reload dan seluruh denial check: 85 response,
nol console error/warning, request failure, atau outbound block.

Verify DB tetap tepat: 2 bill termasuk baseline claimed, 11 charge, 11 item,
2 audit; entitlement, outbox, order, consent, dan identity verification nol.
Focused P12a+P12b 61 tes/592 assertions lulus; Pint, PHPStan 0 error, PHP/Node
syntax, ESLint, Prettier, dan diff check lulus. Server dan session ditutup, port
8012 kembali bebas. Browser ini tetap sequential SQLite, bukan pembuktian race
atau PostgreSQL. Gate tetap default-off dan tidak ada P12c.

## P12b browser page — delta dari 55672a9

Tanggal 2026-09-02. Increment ini menguji `CreateCollectiveBill` yang tetap
default-off melalui harness loopback khusus. Fix view `2e2c788` memberi label/id
native dan membandingkan ID hasil hidrasi browser secara type-stable tanpa
mengubah harga, query, policy, atau writer. Harness `e28d3ee` memakai SQLite,
storage/session/cache disposable, `envDir: false`, `configFile: false`, seluruh
provider outbound fake/deny, dan hanya menerima `127.0.0.1:8012`.

Playwright dibagi menjadi tiga `run-code` bounded pada page/session yang sama;
masing-masing menulis `phase-selection-preview.txt`, `phase-confirm.txt`, dan
`phase-authorization.txt` ke artifact ignored. Fase pertama membuktikan 10
pilihan sintetis, empat disabled reason aman, Tab/Space/Enter native, total
server IDR 2.040, dan enam geometry check selection/preview pada 320/390/1280.
Fase kedua membuktikan perubahan konsultasi menghapus preview, mutasi harga
ditolak generik, restore + double Enter menuju satu canonical bill, dan reload
mempertahankan URL. Fase ketiga membuktikan secrecy serta denial guest, role yang
dicabut, dan membership tenant yang berubah. Total 20 aksi native dan 488 trusted
events.

Run final memakai fixture
`oncam-collective-page-7abba5dbf8e947a5a3eb8b9374b2bf3d`. Verify database:
2 bill (termasuk baseline claimed), 11 charge, 11 item, 2 audit; entitlement,
outbox, order, consent, dan identity verification tetap nol. PHPUnit focused
19/61, Pint, PHPStan 0 error, PHP lint, Node check, ESLint, Prettier, dan diff
check lulus. Server/session ditutup dan port 8012 bebas.

Batas: detail `OrganizationBill` existing melebar pada 320px dan tidak diubah
karena di luar ownership; ini temuan untuk P12a UI hardening. Harness tidak
mempublikasikan bundle asset Filament halaman detail, sehingga detail mencatat
diagnostic 404/Alpine dan avatar eksternal diblokir CSP. Console/network yang
disahkan bersih adalah selection/preview sebelum redirect; diagnostic detail dan
denial tetap direkam. Ini bukti SQLite berurutan, bukan race/PostgreSQL, dan
bukan acceptance P12b/P12c.

## P12b core review fix — delta dari 97f3774

Review root menahan rangkaian awal. Fix ini tidak memperluas scope. Page kini
hanya memetakan AuthorizationException, DomainException, dan InvalidArgumentException
ke pesan generik; RuntimeException/QueryException/error programmer tidak ditelan.
Fault injection pada event creating AssessmentBill membuktikan error sintetis
keluar dari Livewire dan transaksi meninggalkan bill/item/charge/audit seluruhnya 0.

Confirm setelah preview memuat ulang membership persisted: perubahan role,
branch, atau deleted_at ditolak tanpa bill/charge. Perubahan branch menghasilkan
PREVIEW_CHANGED generik karena admin masih sah pada organisasi barunya; role dan
deleted ditolak authorization. Tidak ada scope lama yang dipakai.

Tes Livewire penuh mengirim nilai checkbox attempt sebagai string seperti browser,
konsultasi boolean, payment method persisted, memanggil review lalu confirm,
dan membuktikan redirect ke bill canonical serta total IDR130. Boundary hanya
mengubah digit-string positif menjadi ID integer; nilai lain tetap invalid.
Perubahan consultation setelah review menghapus preview/reviewed selection dan
confirm tidak menulis. Focused final: **19 tes/61 assertions lulus**; Pint lulus.
PHPStan application scope dan diff-check dijalankan setelah perubahan. Browser
tetap ditunda sesuai instruksi review. Default-off/testing-only tetap sama.

## P12b core default-off — delta dari 49c0ff4

Tanggal 2026-09-01. P11c dan P12a telah diterima root sebagai implementasi lokal
default-off (integration-wave-24). Increment ini menambah boundary Filament
testing-only: pilih attempt cabang, preview server-authoritative, lalu konfirmasi
tepat satu delegasi ke ReserveAssessmentBill. Harga, policy, predicate, lock,
writer, stale hash dan idempotency tetap milik action pembayaran existing.

`CreateCollectiveBillAction` memuat ulang Admin persisted pada choices, preview,
metode, dan confirm; hanya BranchAdmin bercabang pada environment testing.
Attempt query selalu memakai organisasi persisted. Choices memproyeksikan label
allowlist melalui PreviewCollectiveBillSelection; legacy/status/self/free/claimed
disabled dengan alasan generik. Preview kolektif menolak setiap item non-payable.
Confirm memakai hash preview dan idempotency canonical `p12b:{admin}:{hash}`;
Reserve mengunci dan re-preview sehingga harga/status/scope berubah menghasilkan
PREVIEW_CHANGED, sedangkan replay selection yang sama kembali ke bill yang sama.

Page resource testing-only memakai checkbox native, konsultasi, total IDR, metode
aktif existing dan tombol preview terpisah dari konfirmasi. Perubahan selection/
konsultasi menghapus preview; state preview dan reviewed selection Locked.
Error UI generik dan tidak memuat data klinis. Tidak ada invoice/provider,
notifikasi, entitlement, proof, reviewer/finalizer atau route produksi.

TDD: RED awal gagal karena class belum ada. GREEN final
`php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Admin/CollectiveBillSelectionTest.php`:
**13 tes/36 assertions lulus**. Kasus mencakup 2 attempt→1 bill, replay, stale
harga, role/tenant/deleted/non-testing, legacy/status/free/self/claimed, foreign,
duplicate, dan confirm tanpa preview. Pint lulus. PHPStan scoped dijalankan dengan
testing/SQLite memory/cache-array; hasil dicatat pada handoff final. Tidak PG:
boundary tidak menambah query lock/RLS; concurrency canonical Reserve sudah diuji.
Browser ditunda sampai review UI stabil, jadi keyboard/mobile baru dari struktur
native dan belum diklaim sebagai browser acceptance P12b.

Catatan integrasi: AssessmentParticipantResource dan ListAssessmentParticipants
adalah baseline untracked di worktree. Jangan cherry-pick snapshot kedua file itu.
Terapkan hanya dua delta berikut pada baseline root: import CreateCollectiveBill,
ubah getPages agar menambah `collective-bill => CreateCollectiveBill::route('/collective-bill')`
hanya saat `app()->environment('testing')`; lalu pada List page tambahkan header
Action `collectiveBill` yang visible testing dan URL resource page tersebut.
File baru Page/action/view/test dapat diambil langsung. Tidak ada baseline lain
yang distage. P12b belum acceptance dan seluruh discovery non-testing tetap OFF.

## Koreksi isolasi Vite — delta dari 3c1dfa3

Tanggal 2026-09-01. Koordinator **belum menerima/mengintegrasikan 1c6afba dan
3c1dfa3**. Klaim isolasi build pada laporan sebelumnya perlu dibatasi: run lama
di worker memang tidak memakai .env karena file itu tidak ada, tetapi
`configFile: false` sendiri masih memungkinkan Vite membaca file env dari root.
Run tersebut tidak membuktikan build aman pada workspace yang mempunyai .env.
Guard PHP terpisah tidak melindungi proses build Node.

Perubahan hanya helper `tools/testing/verify-collective-preview-browser.mjs`
dan laporan ini; commit baru di atas dua commit sebelumnya, tanpa amend/reset.
Skills TDD, Playwright dan Git workflow digunakan. Source Vite 8.2.2 terpasang
diperiksa: penonaktifan config Vite, env files, dan pencarian PostCSS adalah
kontrol berbeda. `fixtureAssetConfig()` kini digunakan bersama oleh mode assets
dan probe-assets, dengan:

- `configFile: false`: tidak mengimpor vite.config project.
- **`envDir: false`**: tidak memuat .env/.env.local/.env.production/
  .env.production.local dari workspace pada build production.
- `css: { postcss: { plugins: [] } }`: tidak mencari/mengimpor konfigurasi
  PostCSS project secara implisit. Plugin eksplisit tetap hanya Tailwind;
  tidak ada Laravel plugin atau impor vite.config project. CSS Filament yang
  dipakai tidak memiliki directive @config.
- Output tetap di direktori bukti ignored dan temp fixture; tidak ada install,
  perubahan dependency/config project atau perubahan browser deny outbound.

Probe sintetis dan bukti RED → GREEN:

1. Mode `probe-assets` membuat child directory temp unik, bukan file di root
   worker/induk. Empat file env berisi marker VITE sintetis berbeda. Kontrol
   positif resolveConfig pada root sintetis dengan configFile false membaca
   keempat marker, sehingga probe tidak sekadar menguji direktori tanpa env.
2. Probe memakai konfigurasi build yang sama dengan mode assets dan mengamati
   configResolved: semua marker harus tidak ada dalam resolved.env,
   resolved.envDir false, configFile undefined, tanpa plugin Laravel. Build
   sebelum fix gagal dengan **Fixture build loaded synthetic workspace env**.
3. `vite.config.cjs` dan `postcss.config.cjs` sintetis sengaja melempar error
   bila dieksekusi. Setelah hanya envDir diperbaiki, build masih gagal pada
   trap PostCSS. Setelah konfigurasi PostCSS inline ditambahkan, probe build
   CSS selesai dan kedua trap tidak dieksekusi. Empat marker env tidak dimuat.
4. Probe GREEN dan build assets nyata di fixture **lulus**. Artifact probe:
   `output/playwright/oncam-collective-a447822e414f4aa59dc1268a77be8649/asset-isolation-probe.json`.
   Tidak membaca, menyalin, menulis atau memakai .env nyata untuk pembuktian.
   Kontrol positif hanya memuat file sintetis. envDir false bukan penghapusan
   environment proses Node yang diwariskan; probe tidak mencetak nilainya.

Build ulang menghasilkan `fixture-Di1XTtKC.css`, SHA256
`3DC78D35F40BE50A6CC6036BFCBD432F74DDB7CEFD52BA77725B9CC87E7CCEE1`.
Nama hash output sama dengan build sebelumnya. View, komponen, adapter dan
harness PHP tetap sama. Browser **diulang penuh**, bukan hanya bukti historis:
20 aksi native / 211 event tercatat, 12 reflow pada 320/390/1280, checkbox minimum
16×16px, tidak ada clipping/overflow. Total 10 item IDR 1.140 (8 berbiaya/2 gratis),
perubahan konsultasi menjadi IDR 1.110, invalid/empty/refresh tidak menampilkan
hasil lama. Seluruh 26 respons <400, nol console warning/error, nol request gagal
atau percobaan request eksternal. Bukti report.json/screenshot/CLI diperbarui
di direktori ignored yang sama dengan increment sebelumnya.

ESLint helper, Prettier check, node --check dan diff --check lulus. PHPUnit,
Pint/PHPStan dan PG **tidak diulang** pada fix Node ini; 28/319 dan PHP lint pada
bagian sebelumnya adalah hasil historis source PHP/view yang tidak berubah.
SQLite fixture sintetis existing dipakai ulang; sepuluh tabel side effect tetap
0. Tidak menjalankan migrasi/DB aktif/outbound atau mengubah gate/source.
Port 8012 diperiksa kosong sebelum start; server PID59420 bind loopback saja,
command line diverifikasi lalu dihentikan. Port kembali bebas dan CLI memastikan
session oncam-collective-77be8649 sudah tertutup. Temp/probe sintetis tetap ada
untuk inspeksi, tidak masuk commit. Batas race/PG/zoom dan belum acceptance P12b
tetap berlaku. Menunggu review koreksi ini sebelum kelanjutan P12.

## Browser native collective preview — delta dari 7ead376

Tanggal 2026-09-01. Root telah mengintegrasikan 7ead376 sebagai e1b9ecc.
Increment ini hanya bukti browser P12b-prep, bukan acceptance atau wiring
publik. Plan/todo, parallel-work dan ADR-004/005 terbaru induk dibaca read-only;
skill Playwright beserta referensi CLI/workflow dibaca sebelum digunakan.
Tidak ada perubahan komponen PHP, adapter, backend, schema, policy, route atau
resource produksi. View testing diperbaiki dalam commit terpisah **1c6afba**.

File increment:

- `tools/testing/serve-collective-preview.php`: harness baru init/serve/verify,
  tidak mengubah harness portal existing. Memuat komponen tests/Support dan
  route Livewire hanya dalam proses fixture; endpoint aplikasi lain ditolak.
- `tools/testing/verify-collective-preview-browser.mjs`: build CSS Filament
  lokal dan driver CLI Playwright cached, tanpa install browser/dependency.
  Asset build dapat diulang tanpa menghapus direktori atau memilih hash lama.
  Catatan review: pada commit 3c1dfa3 guard env build belum eksplisit; lihat
  koreksi isolasi Vite di atas sebelum mereproduksi pada workspace lain.
- `tests/Support/views/collective-bill-preview.blade.php`: perbaikan fokus dan
  reflow test-only yang dijelaskan di bawah, sudah commit terpisah.
- Laporan ini; tidak mengedit checklist atau dokumen kanonik koordinator.

Isolasi dan fixture:

- Port 8012 diperiksa kosong sebelum server dimulai; bind hanya
  `127.0.0.1:8012`. Harness memeriksa alamat remote, nama server, port, Host dan
  Origin. Pengujian `/admin` mendapat 404 dan Host asing mendapat 403.
- Direktori baru
  `C:/Users/ThinkPad/AppData/Local/Temp/oncam-collective-a447822e414f4aa59dc1268a77be8649`
  berisi SQLite baru, manifest sintetis, CSS, storage/session/cache tersendiri.
  Init menolak DB/manifest existing; .env workspace tidak ada dan harness
  memakai environment path temp tanpa .env. Migrasi hanya DB disposable itu.
  Koneksi DB selain SQLite dihapus dari config runtime, Redis tidak tersedia;
  payment/notifier fake, Mail fake, Laravel HTTP preventStrayRequests.
- Browser headless Chrome installed memakai session baru
  `oncam-collective-77be8649`, tanpa attach tab/profile user. Browser menolak
  request selain prefix loopback tersebut; CSP connect-src self. Tidak ada
  permintaan keluar selama alur. Login hanya admin BranchAdmin sintetis dari
  manifest, bukan akun nyata. Ini isolasi harness aplikasi/browser, bukan
  klaim sandbox egress OS untuk kode PHP arbitrer.
- Sepuluh attempt paket A/A/B/B/C/C/A/B/C/A, harga A100/B200/C0 IDR;
  konsultasi A/C30 dan B50. Konsultasi dipilih untuk ID2/4/6/10. ID2 nama null,
  ID3 kandidat panjang untuk menguji wrapping. Attempt ke-11 sengaja memiliki
  payer policy null; server memproyeksikan alasan aman tanpa label peserta.
- node_modules disalin independen dengan /XJ, cache dikecualikan, setelah
  package-lock root/worker cocok SHA256
  `E8A50F8A14992153085D621E8C2DFCA8DC8708F59D0FC40F8FDFFA7F9D3A1253`.
  Tidak ada junction/shared dependency atau instalasi. Overlay nullable lokal
  2026_08_31_000600 tetap identik root SHA256
  `B0AB61731EA1C1CFC49507FAF2C4FB5251F56BE87DEDC49752DB0DCA9A70F027`,
  tidak diedit/stage/commit.

Bukti red → fix → green pada browser:

- Sebelum fix, Space memicu loading disabled pada fieldset dan fokus pindah
  ke BODY. Pada submit, Livewire juga otomatis men-disable kontrol di dalam
  form. Fieldset kini memakai aria-busy; tombol Tinjau berada di luar form
  dengan atribut `form` native menuju ID unik komponen. Fokus tetap pada
  kontrol setelah respons, tanpa script focus atau dispatchEvent.
- Pada 320px fieldset awal melebar sampai scrollWidth 682. `min-w-0` pada
  fieldset mengizinkan wrapping; `shrink-0` mencegah checkbox tertekan oleh ID
  kandidat panjang. Harga dan policy tidak berubah. Tidak ada overflow-hidden
  untuk menyembunyikan masalah.
- Run final memakai Tab untuk mencapai kontrol, Space untuk attempt dan
  konsultasi, Enter untuk Tinjau. **20 aksi native** lulus beserta pemeriksaan
  activeElement dan `:focus-visible` setelah respons. Event log sebelum
  refresh berisi **211 event**; semua keydown trusted dan perubahan checkbox
  trusted teramati. evaluate hanya membaca DOM/geometri atau memasang pencatat
  event, tidak mengubah pilihan, fokus atau hasil. Ini bukan simulasi event DOM.
- Hasil 10 pilihan: **IDR 1.140, 8 berbiaya / 2 gratis, 10 baris hasil**.
  Konsultasi ID2 dimatikan dengan Space: hasil lama hilang; Tinjau baru memberi
  IDR 1.110. Menambah ID11 menghapus hasil lama; Tinjau menampilkan
  PAYER_POLICY_UNCONFIGURED dan total belum tersedia, tanpa total sebelumnya.
  Refresh tidak memulihkan selection/hasil. Tinjau kosong memberi error pilihan
  tanpa hasil stale.
- **12 pemeriksaan reflow**: empty/mixed/invalid/empty-error masing-masing pada
  320, 390, 1280px. Document scrollWidth sama dengan viewport, tidak ada elemen
  main terpotong/melebar, checkbox minimum **16×16px**. Screenshot full-page
  diperiksa, termasuk kandidat panjang dan hasil campuran di layar kecil.
- **26 respons HTTP** dalam run browser, semuanya <400; nol request gagal,
  nol request eksternal yang diblokir, nol console warning/error atau pageerror.
  CLI cached memakai perintah `requests`, bukan `network` yang tidak didukung.

Verifikasi akhir:

- `php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Admin/CollectiveBillPreviewComponentTest.php`:
  **28 tes / 319 assertions lulus** setelah perbaikan view.
- Pint harness lulus; PHPStan harness **0 error**; ESLint helper, Prettier check,
  dan `node --check` lulus. PHPStan menggunakan APP_ENV=testing, SQLite :memory:,
  cache/session array dan path bootstrap cache khusus ignored. Dua percobaan
  ulang awal berhenti pada bootstrap (env testing belum diisi, lalu path
  Windows absolut tidak dikenali Laravel); setelah env dan path relatif
  isolated benar, analisis lulus. Tidak melonggarkan guard aplikasi.
- Mode verify setelah browser: assessment_charges, assessment_bills,
  assessment_bill_items, assessment_entitlements, audit_logs, outbox_messages,
  orders, entitlements, consent_records, identity_verifications seluruhnya **0**.
- PID server sendiri 62268 diverifikasi command line sebelum dihentikan;
  port 8012 dipastikan bebas. Helper menutup session di finally; pemeriksaan
  ulang CLI menyatakan session tidak terbuka. Tidak menghentikan server/tab lain.
- Bukti lokal ignored di
  `output/playwright/oncam-collective-a447822e414f4aa59dc1268a77be8649/`:
  report.json (native/events/geometri/network), 12 screenshot final dan log CLI.
  Screenshot red disimpan terpisah. Temp DB sintetis tetap ada untuk inspeksi,
  tidak disajikan setelah server ditutup; tidak masuk commit.

Reproduksi: buat direktori temp unik oncam-collective-{32hex}, set
`ONCAM_COLLECTIVE_PREVIEW_DIRECTORY`, jalankan PHP harness `init`, lalu Node
helper `assets`. Setelah memastikan 8012 kosong, start PHP hidden dengan
`-d opcache.enable_cli=0 -S 127.0.0.1:8012 -t public tools/testing/serve-collective-preview.php`.
Set `ONCAM_PLAYWRIGHT_CLI` ke CLI cached yang sudah ada dan jalankan Node helper
`verify`; jalankan PHP harness `verify`, hentikan PID server sendiri dan cek
port kembali. Helper tidak memasang browser, tidak menyalakan server otomatis.

Batas: hanya Chromium/Chrome headless dan CSS Filament lokal, bukan audit
aksesibilitas penuh, screen reader, browser lain atau browser zoom 200%.
Input/response berurutan; tidak membuktikan race concurrent request, snapshot
konfirmasi, invoice/finalizer P11, intent resume atau immutabilitas identitas.
Tidak menjalankan PG/full regression baru; bukti RLS/query adapter tetap pada
increment b8e3f6c yang sudah direview root, bukan klaim dari SQLite/browser ini.
Tidak ada tombol bayar/reservasi, writer, transaksi nyata, flag publik atau
acceptance P12b. Stop setelah commit bukti ini untuk review koordinator.

## Komponen Livewire preview sintetis — delta dari b8e3f6c

Tanggal 2026-09-01. Koordinator telah mengintegrasikan bukti PG b8e3f6c sebagai
0c64314. Kelanjutan ini hanya P12b-prep UI baca-saja; bukan wiring publik atau
acceptance P12b. Parallel-work/todo dan ADR-004 induk dibaca read-only. Skills
Laravel Specialist (local implementation, Livewire/testing), Auth and Tenant
Access, TDD dan Frontend UI Engineering dipakai. API lifecycle/Locked serta
Blade button dicocokkan dengan source Livewire/Filament installed; contoh skill
Livewire lama tidak disalin sebagai API aplikasi baru.

Empat file increment, tanpa fixture/helper bersama tambahan:

- `tests/Support/CollectiveBillPreviewComponent.php`: Livewire component khusus
  tes. Mount menerima daftar ID attempt fixture dari server, dikunci Locked;
  tidak menerima scope/payer/harga atau mencari daftar publik. Render memuat
  label ulang melalui PreviewCollectiveBillSelection, sehingga membership atau
  role lama tidak cukup untuk mempertahankan label dalam snapshot browser.
- `tests/Support/views/collective-bill-preview.blade.php`: view testing dengan
  checkbox native attempt/konsultasi dan satu button Filament **Tinjau**.
  Menampilkan semua pilihan fixture, ID kandidat/attempt/periode, fallback
  nama, komponen nominal IDR server, jumlah berbiaya/gratis, alasan item aman
  serta total belum tersedia saat null. Tidak ada input nominal/payer, tombol
  bayar/konfirmasi, upload, invoice link atau kontrol reservasi.
- `tests/Feature/Admin/CollectiveBillPreviewComponentTest.php`: 28 kasus baru,
  memakai fixture existing dan penyesuaian sintetis privat dalam setup tes.
  Komponen diregistrasikan hanya dalam test setup, bukan provider/resource
  app/Filament. Tidak ada route/server/harness HTTP baru.
- Laporan ini. Tidak mengubah adapter/backend/auth, schema, shared fixture,
  runner, konfigurasi, route, gate produksi atau checklist kanonik.

Perilaku state dan batas otoritas:

- Selection berisi attempt ID eksplisit + boolean konsultasi. Toggle membentuk
  input, sedangkan validasi empty/duplicate/limit+1/tipe/field asing tetap milik
  adapter. Baris malformed tidak dibuang untuk mengubah request invalid menjadi
  valid. Harga, policy, klasifikasi free/payable dan hash tidak dihitung UI.
- Preview merupakan property Locked dan selalu dibuang sebelum review, ketika
  selection/konsultasi diperbarui, dan pada setiap hydration. Refresh tidak
  melanjutkan hasil/hash sebelumnya, bahkan bila selection tidak berubah.
  Data selection hanya state Livewire; tidak membuat draft DB/session/intent.
  View menyembunyikan hasil saat request berlangsung dengan wire:loading.remove.
- Hanya request Tinjau menghasilkan hasil sementara baru. Policy source yang
  menjadi null menghasilkan PAYER_POLICY_UNCONFIGURED, total/hash null; harga
  yang berubah dimuat ulang dari server. Profile nama null/blank memakai fallback
  existing tanpa menulis placeholder. Refresh menghilangkan label lama setelah
  nama berubah null atau membership berpindah cabang.
- Tinjau dan render memakai adapter asli, termasuk auth persisted. Guest,
  deleted admin, Staff/Psychologist/SuperAdmin dan environment production ditolak
  pada mount serta direct action meski session sebelumnya BranchAdmin dan flag
  verifier true. ID foreign/nonexistent menghasilkan reason yang sama tanpa
  label; perubahan browser pada daftar fixture dan preview ditolak Locked.

Verifikasi nyata:

- RED: setelah nama helper tes diperbaiki agar tidak bertabrakan dengan
  TestCase::component, 25 kasus gagal karena komponen belum tersedia.
- GREEN awal: 25/199; setelah tambahan pemeriksaan mount denial, query read-only,
  nullable profile berubah saat refresh dan malformed row, regresi akhir
  **89 tes / 995 assertions lulus**, tanpa skip (26.357 detik), termasuk 28 kasus
  komponen serta adapter/preview backend/portal list-detail existing.
- Fixture 10 attempt/3 paket: IDR 1140, berbiaya 8/gratis 2. Free tanpa konsultasi
  tetap IDR 0; konsultasi menjadikannya IDR 30 menurut server. Semua nama/attempt
  berbeda terlihat; fallback null/empty/whitespace tidak mengubah nilai DB.
- Semua tes membandingkan seluruh rows 10 tabel efek sebelum/sesudah: charge,
  bill/item, entitlement assessment/legacy, order, audit, outbox, consent dan
  identity verification. Query log alur mount→toggle→review→konsultasi→review→refresh
  juga tidak mengandung DML/DDL. Tidak ada efek billing/outbound nyata.
- Pint kedua file PHP lulus. PHPStan component + adapter lulus, 0 error. Pemeriksaan
  awal mendeteksi guard offset array bertentangan dengan shape PHPDoc; accessor
  data_get dipakai agar input malformed tetap utuh dan dapat ditolak adapter,
  tanpa ignore/baseline atau perubahan signature backend. Kasus itu diuji.

Perintah utama:

```powershell
php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/CollectiveBillPreviewComponentTest.php tests/Feature/Admin/CollectiveBillPreviewTest.php tests/Feature/Payments/AssessmentBillPreviewTest.php tests/Feature/Admin/OrganizationBillAccessTest.php
php -d opcache.enable_cli=0 vendor/bin/pint --test tests/Support/CollectiveBillPreviewComponent.php tests/Feature/Admin/CollectiveBillPreviewComponentTest.php
# PHPStan: APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, DB_URL kosong,
# CACHE_STORE/SESSION_DRIVER=array, QUEUE_CONNECTION=sync; .env worktree tidak ada.
php -d opcache.enable_cli=0 vendor/bin/phpstan analyse --no-progress tests/Support/CollectiveBillPreviewComponent.php app/Filament/Actions/PreviewCollectiveBillSelection.php
```

Batas: pengujian ini memanggil lifecycle/state/render Livewire pada SQLite
:memory:, bukan keyboard native, screenshot/reflow, race request browser atau
HTTP publik end-to-end. Tidak memulai browser/server baru sesuai instruksi;
native/reflow menunggu review increment ini. Tidak mengulang PG karena query
adapter/schema tidak berubah; bukti PG sebelumnya bukan uji komponen browser.
Label pilihan dimuat batch ulang saat render melalui adapter, termasuk saat
review; belum dioptimasi sebagai daftar produksi. Preview bukan snapshot
transaksi konfirmasi atau intent resume. P10/P11, identitas dan wiring produksi
tetap dependency. Hanya empat file lane di-commit, tanpa snapshot baseline atau
overlay migration nullable. Berhenti untuk review, tidak melanjutkan billing.

## Bukti PostgreSQL adapter kolektif — delta dari f4ca0c6

Tanggal 2026-09-01. Adapter f4ca0c6 telah diintegrasikan koordinator sebagai
521b49e. Increment ini hanya menambah `tests/Postgres/CollectiveBillPreviewTest.php`
dan laporan ini; tidak mengubah adapter, backend, resource, query bersama,
schema, route, flag, atau harness. Parallel-work, plan/todo dan ADR-004 induk
dibaca read-only. Skill PostgreSQL/RLS (termasuk role runtime, FORCE RLS dan
koneksi reuse), Laravel, auth/tenant, TDD serta panduan Git/review digunakan.

Empat belas kasus baru memanggil **adapter sebenarnya**, bukan salinan query:

- Role koneksi `psikotes_runtime`, non-superuser, NOBYPASSRLS dan bukan owner;
  FORCE RLS diperiksa pada participant, attempt, charge, bill, item dan entitlement.
  Query cabang biasa hanya melihat A, dan preview service tetap menyaring B.
- Item A mendapat whitelist 14 field; B dan ID tidak ada mendapat tiga field
  ID/status/reason identik (`ASSESSMENT_NOT_AVAILABLE`), total/hash null dan
  canReserve false. Proyeksi tidak memuat sentinel privat, metadata, policy,
  testTypes, invoice/proof/gateway. ID kandidat tetap berbeda per attempt.
- Branch session yang dipalsukan dalam memori tidak mengganti membership DB.
  Sesudah membership A→B, context A ditolak oleh `admins_read` RLS sebelum
  elevation; context B pada PDO/backend PID yang sama hanya memproyeksikan B.
  Ini perbedaan penting terhadap SQLite: context lama tidak boleh otomatis
  menerobos RLS untuk memuat principal baru. Request berikutnya perlu context
  yang disiapkan ulang sesuai kontrak autentikasi existing.
- Guest, GenericUser non-Admin, admin soft-deleted/branchless, role persisted
  SuperAdmin/Staff/Psychologist ditolak meskipun session stale BranchAdmin,
  flag verifier legacy true dan pemanggil sudah berada di service context.
- Sesudah sukses maupun InvalidArgumentException, instance context PHP dan
  ketiga GUC PG role/branch/participant dipulihkan. Foreign row tetap tersembunyi.
  Setelah transaksi fixture diakhiri, ketiga GUC kosong pada PDO yang sama.
  Pemeriksaan dilakukan langsung setelah adapter, sebelum helper pembanding
  rows melakukan elevation sendiri, agar helper tidak menutupi kebocoran GUC.
- Helper read-only membandingkan **semua kolom/baris** 10 tabel dalam service
  context sebelum/sesudah setiap panggilan biasa, termasuk error; query adapter
  hanya SELECT (termasuk set_config). Tabel: charge, bill, bill_item, entitlement
  assessment/legacy, order, audit, outbox, consent dan identity verification.
  Kasus khusus mengisi charge dengan snapshot valid serta bill/item/entitlement
  sentinel sehingga bukan hanya membandingkan tabel kosong. Harga katalog
  berubah 100→999 tetapi preview charge existing tetap 100 dan rows identik.
- Batch 10 versus batas konfigurasi existing 100 item diukur pada seluruh
  adapter: principal reload, preview, label, service elevation/restoration;
  tidak termasuk setup fixture dan query pembanding rows. Batas tes ≤16 query
  dan jumlah keduanya harus sama, sehingga lookup label per baris akan gagal.
- Hook `QueryExecuted` sekali jalan setelah read terakhir backend preview
  mengubah participant yang sudah dimuat: soft-delete atau pindah branch.
  Query label berikutnya menolak seluruh hasil dengan BILL_PAYER_NOT_AUTHORIZED,
  lalu context PHP/PG dipulihkan. Dispatcher koneksi diklon sementara dan
  dipulihkan di finally; action tidak diberi hook atau mock. Mutasi berasal dari
  tes ini saja, sehingga dua kasus hook **bukan** bukti adapter read-only.

Verifikasi dan koreksi tes:

- Run pertama `fa8da2c733284de68782e4f81e51217b`: 165 tes / 1145 assertions,
  dua error setup/ekspektasi tes. Asumsi context cabang lama dapat memuat admin
  baru ternyata ditolak aman oleh admins_read; tes diperbaiki untuk mengharapkan
  denial sebelum berpindah context. Fixture policy_snapshot `[]` ditolak CHECK
  object PG, diganti object sintetis; paket sentinel juga diaktifkan sebelum
  capture snapshot sesuai kontrak existing. Tidak mengubah kode produksi.
  Run ini sudah mengukur 14 query pada 10 maupun 100 item dan hook keduanya lulus.
- Run kedua `5c41cbd54bd04e8ebffd4f4073dad875`: **165 tes / 1239 assertions
  lulus**, 35.041 detik waktu suite (tidak termasuk bootstrap). Review berikutnya
  memperketat urutan assertion context agar helper pembanding rows tidak dapat
  memulihkan GUC terlebih dahulu.
- Run akhir `b23d1574dd8c44abb40ae1e397d7c3f4`: **165 tes / 1291 assertions
  lulus**, tanpa error/skip, 53.830 detik waktu suite, 62.50 MB. Termasuk 14
  kasus baru adapter. Batch 10 dan 100 tetap masing-masing **14 query**.
  Angka ini milik snapshot worktree portal, bukan regresi seluruh baseline
  terbaru induk. Ketiga run selesai cleanup; lookup exact label container dan
  network sesudah run kosong.
- Pint pada file tes lulus. PHPStan targeted pada adapter unchanged lulus,
  0 error, memakai APP_ENV=testing/SQLite :memory:/cache-session array; ini
  bukan klaim analisis statis seluruh suite tes atau proyek induk.

Perintah PG tetap `powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1`.
Runner existing tidak menyediakan filter, sehingga suite PG worktree dijalankan
seluruhnya. DB baru memakai tmpfs/network internal berlabel run ID, tanpa port
publik; source worktree bind read-only dan storage/cache tmpfs. Tidak membaca
.env (file worktree juga tidak ada), DB aktif, atau memanggil outbound nyata.
Overlay migration nullable yang telah diizinkan tetap lokal/unstaged dan identik
dengan root (SHA256 B0AB61731EA1C1CFC49507FAF2C4FB5251F56BE87DEDC49752DB0DCA9A70F027).
Adapter juga identik root (SHA256 6EABA63CB016CF7FB00E3973E13C75D08A4D96FF64F59D0B408161BD78899B03).

Batas: ini bukti query/proyeksi PG runtime dan context reuse, bukan HTTP/Livewire,
browser, PgBouncer, EXPLAIN/load test, atau race dua koneksi. Hook membuktikan
fail-closed antar-query pada satu koneksi, **bukan snapshot transaksi konfirmasi**
atau immutabilitas identitas. Tidak ada komponen/UI/action publik, reserve,
invoice, settlement atau intent resume. P10/P11 dan keputusan identitas tetap
dependensi; P12b belum accepted. Hanya dua file lane akan di-commit; baseline
snapshot dan overlay tidak ikut. Berhenti setelah bukti untuk review koordinator.

## Adapter preview kolektif test-only — delta dari 6a7aaf7

Tanggal 2026-09-01. Proposal diterima koordinator sebagai DRAFT prep, bukan
acceptance P12b/wiring publik. Plan/todo/parallel-work terbaru dan ADR-004 induk
dibaca read-only. Skills Laravel Specialist (termasuk local implementation dan
testing), Auth and Tenant Access, TDD, Security and Hardening, serta panduan
Git/review yang sudah dibaca dipakai tanpa mengubah batas ownership.

Tiga file increment:

- `app/Filament/Actions/PreviewCollectiveBillSelection.php`: adapter internal
  `execute(selection)` tanpa parameter principal/organisasi/payer/total. Gate
  menolak selain environment testing; aktor dari guard panel Filament harus
  Admin persisted, role BranchAdmin dan branch non-null dimuat ulang dari DB.
  Flag can_verify_payments bukan izin. Baru setelah auth, jalankan preview
  existing di RlsContextRunner service context; tidak menduplikasi policy/harga.
- `tests/Feature/Admin/CollectiveBillPreviewTest.php`: 25 kasus sintetis untuk
  total mixed-package, all-free, field whitelist, null/blank/nonblank name,
  foreign/missing ID, policy berubah, snapshot charge existing, role/membership
  stale, scope palsu dalam memori/input, guest/non-Admin/deleted/branchless,
  environment local/production ditolak, dan pemulihan context setelah error.
- Laporan ini. Tidak memasang Filament action/resource, route, flag, config,
  schema atau writer; shared fixture/backend/auth tetap tidak berubah.

Proyeksi top-level hanya items, currency, totalAmount, paidCount, freeCount,
canReserve, selectionHash. Item tersedia memiliki 14 field: ID/status/reason,
nama peserta, ID kandidat, ID attempt, periode, kode/nama paket snapshot dan
komponen harga/konsultasi/IDR. Item unavailable hanya ID/status/reason, tanpa
label atau nominal. Snapshot/policy mentah, metadata, testTypes, proof/invoice/
gateway, data klinis dan request hash tidak diteruskan. Nama nonblank tetap;
fallback null/empty/whitespace hanya proyeksi. SelectionHash tetap fingerprint,
bukan token atau konfirmasi; canReserve bukan tanda akses publik/kanal aktif.

Label dimuat secara batch dengan scope organization dan participant.branch_id,
kolom terbatas serta eager load; tidak lookup per row. Jika label/peserta yang
sebelumnya tersedia hilang/berubah scope saat pemuatan label, adapter menolak
seluruh keluaran. Ini bukan kontrak snapshot transaksi konfirmasi: perubahan
identitas dalam organisasi yang sama dan immutabilitas attempt tetap dependency.

Verifikasi nyata:

- RED pertama: 25 tes gagal/error, adapter belum tersedia; satu setup negatif
  juga diperbaiki karena Participant bukan Authenticatable guard admin. Kasus
  tersebut memakai GenericUser non-Admin (tanpa mengubah guard/model shared).
  Implementasi awal menemukan callback eager load menerima relation, bukan
  Builder; tipe callback disesuaikan dengan source Laravel installed/pola portal.
  Pembandingan snapshot charge dibetulkan memakai raw DB pada kedua sisi agar
  perbedaan hydration bool/int dan kolom default tidak dianggap write aplikasi.
- GREEN focused: **61 tes / 676 assertions lulus**, termasuk seluruh 25 tes baru.
  Perintah: `php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/CollectiveBillPreviewTest.php tests/Feature/Payments/AssessmentBillPreviewTest.php tests/Feature/Admin/OrganizationBillAccessTest.php`.
- Fixture 10 attempt/3 paket menghasilkan total IDR 1140 (8 berbayar, 2 gratis);
  konsultasi pada dua item gratis menghasilkan IDR 1200 (10 berbayar). Urutan
  selection terbalik menghasilkan proyeksi/hash identik. Fixture privat mengatur
  tiga paket/sumber terkait tanpa mengubah helper bersama atau harga aktif.
- Setiap panggilan adapter pada tes memeriksa jumlah row 10 tabel efek tetap
  sama dan query log tidak mengandung INSERT/UPDATE/DELETE/DDL; mencakup charge,
  bill, bill_item, entitlement baru/legacy, order, audit, outbox, consent/identity.
  Charge snapshot existing juga dibandingkan sebelum/sesudah. Tidak reserve,
  settlement, invoice, aktivasi, audit atau outbox dari adapter.
- Pint dua file PHP lulus; PHPStan adapter lulus **0 error**, environment proses
  testing/SQLite :memory:/array cache+session. Tidak membuat .env.
- PreviewAssessmentBill lokal identik dengan induk (SHA256
  `27088E41199F7B26CC72A62FB1C99E953A3A6177AC5BEF3165D70828303C67A1`).
  Overlay migration nullable dari increment sebelumnya tetap identik SHA256
  `B0AB61731EA1C1CFC49507FAF2C4FB5251F56BE87DEDC49752DB0DCA9A70F027`,
  hanya untuk tes dan tetap tidak di-stage/commit; tidak menyalin baseline lagi.

Batas: query adapter baru hanya diuji SQLite terisolasi; context restoration
di atas membuktikan state runner PHP, **bukan PostgreSQL FORCE RLS**. Tidak
menjalankan PG baru/full regression/browser/server pada increment tiga file ini.
Bukti PG baru dapat menjadi increment terpisah sebelum integrasi query publik;
hasil PG portal lama tidak diklaim sebagai bukti adapter ini. Belum ada UI
selection/keyboard, draft/resume intent, invoice P10, finalizer/verifier P11 atau
keputusan immutabilitas identitas attempt. Gate tetap tertutup publik. Tidak
menyentuh DB aktif, outbound nyata, dana talang atau deploy. Commit terbatas
tiga file milik lane, kemudian stop untuk review koordinator.

## Proposal P12b kolektif — delta dari 5d625e6

Tanggal 2026-09-01. Koordinator sudah mengintegrasikan fallback nama melalui
c22515b dan patch AssessmentParticipant satu baris; regresi gabungan root
779 tes/3782 assertions dan Pint dilaporkan lulus. Angka itu bukan run baru lane.

Deliverable: [collective-selection-proposal.md](collective-selection-proposal.md).
Membaca spec, P12b/dependency P9–P11, preview/reservasi, policy/snapshot, schema/RLS,
resource dan tes existing dari induk read-only (HEAD teramati ed2e100). Proposal
memuat alur selection → preview server → konfirmasi → bill existing; exact reason,
input minimal ID attempt + bool konsultasi, matriks 10 peserta mixed-package,
replay/race/self-pay, pagination, scope/role dan proyeksi tanpa data klinis.

Temuan utama: paidCount berarti item berbayar, bukan sudah lunas; semua claim
bill tetap menghalangi reservasi baru termasuk expired/rejected. Gratis masuk
hash tetapi tidak diklaim/diselesaikan oleh ReserveAssessmentBill. Hash bukan
otorisasi dan tidak mencakup seluruh metadata identitas tampilan. Invoice P10,
finalizer/verifikasi P11, jalur gratis, resume intent dan kontrak perubahan
identitas attempt tetap dependency. Button tidak boleh aktif publik; tidak
ada dana talang atau kewajiban utang peserta otomatis.

Usulan berikutnya hanya adapter preview Filament read-only test-only + tes
focused, tanpa registrasi bulk action publik atau writer baru; masih perlu
review. Increment sekarang **hanya dua dokumen**: proposal dan laporan ini.
Tidak mengubah aplikasi/model/config/routes/schema/fixture atau gate, tidak
browser/DB/test suite/Pint/PHPStan, tidak menyalin ulang migration/baseline.
Pemeriksaan statis mencocokkan kode/reason/field, dependency, diff dan daftar
file commit; berhenti menunggu review setelah commit dokumen.

## Increment nama profil parsial — delta dari b7e0504

Tanggal 2026-09-01. ADR-004, parallel-work.md dan integration-wave-5.md induk
dibaca read-only setelah integrasi schema P9a0 bfc0587. Increment ini menambahkan
`placeholder('Nama belum dilengkapi')` hanya pada kolom `participant.full_name`
AssessmentParticipantResource dan OrderResource. Tidak mengubah nilai tersimpan,
query/search/sort, scope/role, identifier kandidat/order, atau aksi existing.
Tidak mengubah model, schema, config, shared fixture atau gate publik.

### Delta dan cara integrasi

- `app/Filament/Resources/Orders/OrderResource.php`: satu baris placeholder;
  resource ini tracked sehingga delta biasa masuk commit lane.
- `app/Filament/Resources/AssessmentParticipants/AssessmentParticipantResource.php`:
  satu baris lokal berubah, tetapi file merupakan **baseline untracked**, bukan
  file baru milik increment. Snapshot resource **tidak di-stage/commit**.
  Delta eksplisit diserahkan melalui
  `tasks/organization-payment/reports/participant-name-display.patch`.
  Koordinator perlu meninjau lalu menerapkan patch tersebut selain delta commit
  Order/test/laporan. `git apply --reverse --check` pada hasil lokal lulus.
- `tests/Feature/Admin/ParticipantNameDisplayTest.php`: tujuh tes baru dengan
  fixture privat sintetis; tidak mengubah fixture bersama.
- Laporan ini dan patch di atas merupakan artefak handoff lane.

SHA256 AssessmentParticipantResource sebelum edit sama persis dengan induk:
`7454E36668961F4DF21A42F0E0F0D0CB0304974719EF67A9BED1FEC17030A502`.
Migration `2026_08_31_000600_allow_checkout_partial_profiles.php` disalin melalui
apply_patch sebagai overlay lokal untuk tes nullable. SHA256 kedua salinan sama:
`B0AB61731EA1C1CFC49507FAF2C4FB5251F56BE87DEDC49752DB0DCA9A70F027`.
Migration ini **tidak di-stage/commit**, dan tidak dijalankan terhadap DB aktif.
Integrasi tes memerlukan migration P9a0 yang sudah tersedia di induk.

### Bukti pengujian

- TDD: run setup pertama gagal pada mass-assignment PaymentMethod di fixture
  baru; diperbaiki mengikuti pola forceFill tes existing, tanpa edit model.
  Run RED berikutnya: **7 tes, 2 lulus, 5 gagal, 94 assertions**. Kelima dataset
  gagal karena sel AssessmentParticipant kosong tanpa placeholder (termasuk row
  pembanding null pada dataset nama lengkap). Itu bukti RED rendering, bukan
  kegagalan akses/schema. Kedua resource lalu mendapat placeholder.
- GREEN focused dengan `phpunit.organization-payment.xml`:
  `ParticipantNameDisplayTest.php`, `ManualTransferFilamentTest.php`, dan
  `OrganizationPortalIsolationTest.php`: **12 tes / 239 assertions lulus**.
  Perintah: `php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/ParticipantNameDisplayTest.php tests/Feature/Admin/ManualTransferFilamentTest.php tests/Feature/Admin/OrganizationPortalIsolationTest.php`.
- Tes baru merender kolom kedua resource untuk null, string kosong, whitespace
  spasi/tab/CR/LF, nama lengkap, serta nama nonblank dengan spasi tepi. Nama
  nonblank dan raw state tetap sama; seluruh row peserta di DB sebelum/sesudah
  render identik. Dua row tetap dapat dibedakan lewat ID kandidat/order existing.
- Sort nama, search nama, dan search teks placeholder diuji: placeholder bukan
  nilai database yang dapat dicari. Row lintas cabang tidak tampil dan resolve
  record asing mengembalikan null pada kedua resource. Order menolak BranchAdmin
  dan Staff tanpa hak verifikasi serta Psychologist; guest ditolak kedua daftar.
  AssessmentParticipant memang mengizinkan role staff terautentikasi menurut
  matriks existing; increment tidak mengarang pembatasan role baru.
- Pint tiga file PHP perubahan lulus. PHPStan dua resource dijalankan dengan
  environment proses testing, SQLite `:memory:`, cache/session array: **lulus,
  nol error**. Percobaan awal tanpa environment testing ditolak
  guard production (worktree tidak memiliki `.env`), tanpa mengubah config.

Implementasi memakai placeholder native Filament 5.7.6: renderer TextColumn
memeriksa blank state sebelum format dan menghasilkan placeholder ter-escape.
Rujukan: [Filament 5 column placeholders](https://filamentphp.com/docs/5.x/tables/columns/overview#adding-placeholder-text-if-a-column-is-empty)
dan source installed `vendor/filament/tables/src/Columns/TextColumn.php`.
Tidak menambahkan formatter/model accessor atau default data baru.

Batas: bukti increment ini adalah render komponen Livewire/HTTP SQLite sintetis,
bukan pemeriksaan visual browser atau PG. Tidak menjalankan PG/full regression,
server/browser, DB aktif, invoice/gateway/notifikasi nyata atau deploy. Review
mandiri memakai skill code-review-and-quality; review/integrasi akhir milik
koordinator. Berhenti setelah commit delta dan menunggu review slice berikutnya.

## Increment verifikasi keyboard/responsif — delta dari 2e8ae42

Tanggal 2026-08-31. Gelombang kedua diintegrasikan induk sebagai 3a17d64.
Instruksi review parallel-work.md dan reports/integration-wave-2.md induk dibaca
read-only; tidak reset/merge baseline. **Increment ini hanya mengubah laporan
ini**, tanpa perubahan resource/page, otorisasi, harness, fitur pembayaran atau
gate. Tidak menjalankan PG, full regression, PHPUnit/Pint/PHPStan ulang karena
tidak ada edit kode aplikasi/test; angka suite sebelumnya tetap bukti historis.

### Lingkungan dan metode

- Skill Playwright dibaca beserta referensi CLI/workflows. npx tersedia; memakai
  CLI cached yang ditunjuk koordinator, bukan install dependency/browser:
  `C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js`.
- Sesi bernama `oncam-portal-keyboard`, `open --browser chrome`, default headless
  dan profil terpisah; **tidak attach** sesi/tab pengguna maupun sesi koordinator.
  Versi browser teramati 151.0.7922.171, tema terang default profil baru.
- Origin hanya `http://127.0.0.1:8012`; port diperiksa kosong sebelum bind.
  Tidak menggunakan port 8011. Init harness existing sukses pada direktori baru
  tanpa .env, snapshot capture/fromCharge valid, 14 bill cabang + satu bill asing.
  Tidak membuat invoice/notifikasi nyata atau menampilkan data klinis.
- Keyboard dikirim lewat CLI `press` / `page.keyboard.press/type`. Listener
  keydown hanya mengamati Tab/Enter/Space/Escape/arrow serta isTrusted, tidak
  dispatchEvent, focus(), click DOM, mengubah value, atau requestSubmit.
  Evaluate dipakai untuk membaca fokus, ukuran, nilai hasil, jumlah baris dan
  event; bukan untuk menjalankan interaksi aplikasi. Resize memakai viewport
  browser; mouse wheel native hanya mereset posisi scroll untuk screenshot.

### Hasil aktual

| Alur | Bukti |
| --- | --- |
| Login keyboard | Type email/password sintetis, Tab lewat toggle password ke Remember me, Space mencentang (assert isChecked), Space kembali kosong, Tab/Enter Sign in menuju dashboard. Screenshot fokus tombol tersimpan. |
| Menu → daftar | Tab mencapai Tagihan Cabang dengan :focus-visible=true; Enter membuka daftar 14 bill. |
| Filter desktop | Tab ke Filter, Space membuka; Tab melewati Reset ke select; Home + ArrowDown memilih Lunas. Enter membuka native select, sehingga Tab pertama menutup popup dan Tab berikutnya mencapai Apply. Space Apply menghasilkan dua row Lunas (id 9 dan 2). |
| Filter 320 px | Ulang tanpa Enter pada select: Home + lima ArrowDown → paid, Tab ke Apply, Space menghasilkan dua row. Tab/ArrowDown/Space yang diamati semuanya isTrusted=true; ring fokus Filter/Apply terlihat. |
| Daftar → detail | Tab melewati kolom row sampai Detail, Enter membuka bill 9; peserta sintetis 9, snapshot paket, IDR 100 dan konsultasi IDR 0 sesuai. |
| Detail → daftar | Tab/Enter pada breadcrumb Tagihan Cabang mengembalikan daftar tanpa filter (14 bill). Ini bukti breadcrumb, bukan history back. |
| Pagination 320 px | Shift+Tab mencapai Next; Space membuka page=2 dengan empat row. Shift+Tab mencapai Previous; Enter kembali ke halaman pertama. Fokus tombol terlihat. |
| Per page | Tab mencapai select Per page, ArrowDown dari 10 ke 25 lalu Tab; kedua select responsif mencerminkan 25, seluruh 14 row tampil. |
| Tabel horizontal | Tab ke Detail pada 320 px menggulir kontainer sampai tautan tampak utuh; rect tautan x=245.61–303.78 di dalam kontainer x=16–304. Fokus memakai underline dan :focus-visible=true, tidak terjebak di luar viewport. |

Listener membuktikan native keydown `isTrusted=true` pada Tab/Enter select,
Space Filter/Apply/Next dan ArrowDown. Bukti akhir filter mobile menyimpan nol
event untrusted. Enter navigasi dinilai dari input Playwright dan halaman tujuan,
bukan klaim bahwa log keydown bertahan melewati pergantian dokumen.

### Responsif dan batas verifikasi

| Viewport CSS px (tinggi 800) | Root/body list & detail | Kontainer/tabel list | Batas panel filter |
| --- | --- | --- | --- |
| 320 | 320 / 320, tanpa overflow halaman | 288 / 1050, scroll internal | x=0–320 |
| 390 | 390 / 390, tanpa overflow halaman | 358 / 1050, scroll internal | x=38–358 |
| 1280 | 1280 / 1280, tanpa overflow halaman | 896 / 1066, scroll internal | x=904–1224 |

Screenshot list/detail/filter diperiksa pada tiga lebar tersebut; referensi dan
attempt detail membungkus pada mobile, nominal/riwayat tetap terbaca. Tabel lebar
memerlukan scroll horizontal; tidak diklaim seluruh kolom terlihat bersamaan.
Screenshot awal resize/fokus menangkap animasi sebelum selesai; bukti akhir
diulang setelah transisi 400–450 ms. Tidak ada perbaikan aplikasi yang diperlukan.

**Zoom browser 200% belum terverifikasi.** Lima Control+Equal tidak mengubah
innerWidth=1280, DPR=1 maupun visualViewport.scale=1 pada Chrome headless ini;
Control+0 dikirim setelah probe. Tidak menggantinya dengan CSS zoom/pinch lalu
mengklaim zoom browser. Alt+ArrowLeft juga tidak mengubah halaman; navigasi
kembali yang terbukti memakai breadcrumb. Tidak mengklaim audit WCAG lengkap,
screen reader, seluruh state/role, mobile hardware, touch, dark theme ulang,
atau full end-to-end pembayaran.

Login sempat melewati waitForURL 30 detik dan snapshot/eval ikut timeout/context
destroyed selama dashboard memuat. Dashboard kemudian teramati selesai dan
pengujian dilanjutkan setelah snapshot baru. Perintah CLI --help mengeluarkan
Node UV_HANDLE_CLOSING saat exit; perintah browser berikutnya tetap berhasil.
Ini dicatat sebagai kendala alat/runtime, bukan bukti alur gagal tertutup atau
hasil tes aplikasi hijau. Console sesi akhir: **0 error, 0 warning**.

### Artefak dan cleanup

Artefak lokal tidak di-commit di
`C:/Users/ThinkPad/.codex/worktrees/6e61/Psikotes/output/playwright/`:
`keyboard-evidence.txt`, `list-return.txt`, `page2.txt`, script probe `.js`,
`list-{320,390,1280}.png`, `detail-{320,390,1280}.png`,
`filter-{320,390,1280}.png`, `apply-focus-320.png`,
`detail-link-focus-320.png`, `pagination-focus-320.png`, `back-focus.png`,
`menu-focus.png`, `login-focus.png`. Snapshot sementara CLI di `.playwright-cli/`
juga tidak commit. Jalankan probe lewat `node <CLI> -s=oncam-portal-keyboard
run-code --filename <script>`; script merupakan langkah berurutan yang bergantung
pada state, bukan suite replay mandiri atau tes CI.

CLI `close` mengonfirmasi sesi oncam-portal-keyboard ditutup; server dihentikan
dan lookup listener 8012 kosong. Tidak memakai close-all/kill-all. Folder SQLite
sintetis tetap lokal di
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-bills-1e94aa4e484942e8b4eb334159c819d2`
(pointer output/playwright/preview-directory.txt), tidak commit atau diklaim
sudah dihapus. Tidak menyentuh folder/proses induk, tidak push/deploy.

Siap review sebagai increment verifikasi saja. P12a tetap menunggu P11c dan
integrasi end-to-end; gate test-only tidak dibuka.

## Gelombang kedua — delta dari 55ffc29 (2026-08-31)

**Siap review sebagai P12a-prep, bukan aktivasi P12a.** Gelombang pertama sudah
diintegrasikan koordinator sebagai 9decef5. Instruksi gelombang kedua pada
parallel-work.md dan reports/integration-wave-1.md dibaca dari induk secara
read-only. Worktree tidak reset/merge/cherry-pick baseline induk. Bagian setelah
gelombang kedua ini mempertahankan bukti historis gelombang pertama.

Skill Laravel, Auth & Tenant Access, Security, TDD, Git Workflow, PostgreSQL dan
Browser dipakai kembali; acuan API ialah source Filament 5.7.6 terpasang serta
[otorisasi resource Filament 5](https://filamentphp.com/docs/5.x/resources/overview#authorization)
dan [RLS PostgreSQL 17](https://www.postgresql.org/docs/17/ddl-rowsecurity.html).

### Delta file milik lane

- `tests/Postgres/OrganizationBillPortalTest.php`: tujuh tes baru, menjalankan
  `getEloquentQuery()`, `resolveRecordRouteBinding()`, authorization resource,
  dan metode alokasi detail existing melalui reflection (tidak menyalin query).
- `app/Filament/Resources/OrganizationBills/OrganizationBillResource.php`:
  tautan navigasi detail eksplisit menggantikan ViewAction yang melakukan
  authorization DB berulang ketika dirender. Action hanya berisi URL dan guard
  mount persisted; tidak memiliki form, modal data, atau handler mutasi.
- `tests/Feature/Admin/OrganizationBillAccessTest.php`: pengukuran rendering
  Livewire pada 10/25/50 baris; regresi URL/action lama setelah membership/role
  berubah; snapshot fixture dibuat dan divalidasi melalui layanan existing.
- `tools/testing/serve-organization-bills-panel.php`: snapshot preview lengkap
  dari katalog sintetis melalui capture/fromCharge, bukan object packageName saja.
- `tasks/organization-payment/reports/branch-portal.md`: laporan delta ini.

Tidak mengubah shared runner/fixtures/schema/config/routes/policies, halaman
detail/list, AssessmentParticipants, dependency atau checklist kanonik. Gate
test-only masih sama. Tidak ada cache otorisasi lintas request atau pelemahan
pemeriksaan persisted pada query, URL detail, action mount, atau hydration.

### Bukti runtime dan batasnya

PG memverifikasi login `psikotes_runtime`, `rolsuper=false`, `rolbypassrls=false`,
bukan pemilik tabel, serta ENABLE/FORCE RLS pada lima tabel proyeksi. BranchAdmin
hanya memperoleh bill organisasi sendiri; self-payer, cabang lain, ID tidak ada,
dan record ID asing dengan organization_id dipalsukan ditolak. Admin stale setelah
role dicabut tetap ditolak meski context DB super_admin dapat membaca luas.
Guest, branch null, soft-deleted admin dan environment produksi juga ditolak.

Perubahan membership A→B diuji dengan auth object lama: context A tidak dapat
membaca A maupun B, context B hanya memperoleh B, detail A yang sudah terambil
ditolak saat proyeksi dipanggil ulang. PDO dan pg_backend_pid tetap sama. Context
staff/psychologist/participant menolak query walau auth object BranchAdmin masih
ada; exception context dan tanpa context juga diperiksa.

Batas koneksi: fixture berada dalam outer transaction, sehingga pergantian
context berjalan melalui savepoint runner pada satu backend. Context kosong
disetel eksplisit untuk menguji fail-closed; rollback outer transaction kemudian
dibuktikan menghapus GUC pada PDO yang sama. Ini bukan uji PgBouncer, beberapa
worker HTTP, atau race perubahan membership selama satu statement berjalan.

Snapshot charge valid dibuktikan `capture()` dan `fromCharge()` sebelum alokasi.
Perubahan katalog setelah snapshot tidak mengubah nama/nominal detail. Allowlist
field hasil dan sentinel membuktikan proyeksi tidak membawa metadata klinis,
invoice, proof, gateway, review privat, atau peserta cabang lain. SQL detail
memakai eager-load existing; tidak membuat invoice atau reservasi action.

PG bootstrap memakai PHPUnit biasa. Tes ini **bukan HTTP/Livewire PostgreSQL**:
middleware, render, hydration, request action dan status HTTP tetap dibuktikan
oleh tes HTTP/Livewire SQLite terpisah. Reflection hanya menjangkau proyeksi
privat existing; tidak memperluas API produksi demi tes.

### Query count terukur

| Pengukuran | 10 baris | 25 baris | 50 baris |
| --- | ---: | ---: | ---: |
| SQLite Livewire render sebelum optimasi | 97 | 232 | 457 |
| SQLite Livewire render sesudah optimasi | 7 | 7 | 7 |
| PG paginator resource (tanpa render) | 3 | 3 | 3 |

Dataset 50 bill organisasi sendiri, ditambah bill asing dan self untuk PG.
SQLite menghitung query pada update tableRecordsPerPage setelah initial mount,
termasuk hydration/render. PG menghitung reload admin + count + page select;
set_config/setup fixture tidak masuk pengukuran. Detail PG sepuluh alokasi
memakai tujuh query, termasuk persisted authorization dan eager-load; resolve
record awal di luar hitungan detail. Bukan klaim latency/load-test produksi.

RED budget rendering (maksimum 20 query dengan ruang untuk overhead framework)
gagal pada 97 query sebelum perubahan. GREEN menjadi konstan tujuh query;
query budget kini dijaga oleh tes. Tidak mengoptimasi dengan cached membership.

### Verifikasi gelombang kedua

- Focused SQLite akhir: **14 tes / 210 assertions lulus**, tanpa skip.
- Pint targeted resource/pages, dua test lane dan harness: lulus.
- PHPStan targeted seluruh resource/pages: **0 error**, lingkungan proses
  testing + SQLite memory + DB_URL kosong/cache-session array seperti perintah
  gelombang pertama. Tests tidak termasuk cakupan PHPStan aplikasi existing.
- PG run awal: **151 tes / 871 assertions lulus**, termasuk tujuh tes portal,
  bukan hanya baseline 144. Run final: **151 tes / 872 assertions lulus**, tanpa
  skip, setelah penguatan assertion record ID palsu (forceFill memastikan id
  tidak dibuang mass-assignment). Delta di atas baseline worktree 144/690 ialah
  **7 tes / 182 assertions portal**; hasil induk 149/739 tidak dipakai sebagai
  denominator karena worktree tidak memuat integrasi backend gelombang pertama.
- Browser 127.0.0.1:8012 dengan DB baru: login sintetis, daftar 14 tagihan,
  klik Detail pada row id 14, lalu DOM detail menunjukkan referensi/attempt,
  peserta 14, snapshot paket, IDR 100 dan konsultasi IDR 0 yang sesuai.
  Tautan tetap native link ke halaman detail. Bukan bukti keyboard penuh,
  screen reader, semua breakpoint, atau browser negatif lintas cabang.
- Preview init berhasil dengan snapshot valid. Tab uji ditutup (tab list kosong),
  server 8012 dihentikan. Tidak membuka tab/data pengguna.

Perintah utama: focused PHPUnit dan PHPStan seperti gelombang pertama; Pint
menambah `tests/Postgres/OrganizationBillPortalTest.php`; PostgreSQL tetap
`powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1`.
Tidak menjalankan full regression gabungan induk atau mengklaim angka 668/3080
sebagai hasil worktree ini. Tidak mengedit runner untuk memilih/filter suite.

Run PG pertama `c55b03fde2f340308ace4f65c7d11d17` dibersihkan runner dan lookup
container/network berlabel persis kosong. Run final
`2777427fdf674b7596483a44c8330289` juga dibersihkan runner. Preview browser baru disimpan lokal di
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-bills-d2ac4c63c4ec4516a265d655e2168e98`
dengan pointer `storage/bill-preview-wave2-directory.txt`; keduanya tidak commit.
Folder sintetis dipertahankan, bukan DB aktif; tidak mencoba jalan penghapusan
alternatif setelah blok tool gelombang pertama. Artefak lama tetap tidak commit.

Delta ini berhenti untuk review. Gate produksi, P11c, HTTP/Livewire PG dan
verifikasi end-to-end pembayaran tetap pekerjaan integrasi berikutnya.

## Catatan gelombang pertama (historis)

Status: **slice persiapan siap review; P12a belum selesai dan tetap menunggu
P11c. Tidak lanjut ke slice berikutnya sebelum review koordinator.**

## Preflight

- Worktree: `C:/Users/ThinkPad/.codex/worktrees/6e61/Psikotes`; HEAD awal
  `58da1de`, dengan snapshot baseline modified/untracked. Koordinator menyatakan
  checkpoint ekuivalen kini `3112e1524292cebe4fbde19e1c24eaa3befc8d0e`.
- CLAUDE.md dan parallel-work.md dibaca; empat file baseline wajib tersedia.
  Tidak reset/switch/merge baseline, tidak commit ulang snapshot awal.
- Skill Laravel Specialist (termasuk local implementation, Eloquent, testing,
  Livewire), Auth & Tenant Access, Security & Hardening, Frontend UI Engineering,
  Browser, TDD, Git Workflow, dan Postgres tersedia dan dibaca.
- Composer lock cocok SHA256 dengan induk. Vendor disalin independen memakai
  robocopy `/E /XJ`; tidak menyalin .env, runtime cache, DB aktif, atau node_modules.
  Direktori storage/cache lokal kosong dibuat agar Laravel dapat boot.
- Filament terpasang 5.7.6. Shared routes/config/policies/schema tidak diubah.
- Tool `send_message_to_thread` sempat tercantum tetapi pemanggilan gagal
  `not a function`; discovery berikutnya tidak menawarkannya. Sesuai arahan
  koordinator, laporan lane ini menjadi kanal status.

## Keputusan scope

Gate checkout existing bukan kontrak kesiapan portal. Resource tetap test-only:
discovery, navigasi, authorization dan query menolak di luar environment testing.
Tidak menambah config toggle atau mengaktifkan flag existing. Integrasi gate
produksi dan P12a tetap menunggu P11c/review koordinator.

Matriks actor: hanya BranchAdmin persisted, tidak terhapus, dengan branch_id
yang sama boleh membaca bill `payer_type=organization`. Guest, participant,
Staff, Psychologist, SuperAdmin, admin tanpa cabang dan lintas cabang ditolak.
Flag legacy can_verify_payments tidak memberi kemampuan tulis pada resource ini. Pembayar mandiri
tidak dimasukkan ke menu tanggungan cabang ini.

Ancaman utama: IDOR list/detail/child, state Livewire setelah role/tenant dicabut,
metadata klinis dan referensi/proof privat ikut terhidrasi, UI memberi kemampuan
tulis atau menyiratkan terminal bill boleh ditagih ulang. Tes negatif ditulis
sebelum implementasi. Jumlah diberi label attempt sesuai item_count server;
dua attempt orang yang sama tidak diklaim sebagai dua peserta unik.

## Bukti verifikasi

- RED lingkungan: direktori compiled views belum ada; dibuat kosong lokal.
- RED perilaku: 10 tes error karena resource/page belum diimplementasikan.
- GREEN awal: 9/10 lulus, satu kesalahan nama tabel outbox di assertion diperbaiki
  menjadi outbox_messages; tidak mengubah schema. Ditambah kasus dua attempt
  orang yang sama dan rejected.
- Focused PHPUnit terakhir: **12 tes / 104 assertions lulus**, tanpa skip, XML
  phpunit.organization-payment.xml dan path tests/Feature/Admin/OrganizationBillAccessTest.php.
- Pint kelima file PHP lulus; PHPStan targeted resource/pages **0 error**.
- Runner tools/testing/run-org-postgres.ps1 selesai: **144 tes / 690 assertions
  lulus**, tanpa skip. Runtime non-owner memakai FORCE RLS. Container/network
  disposable dibersihkan runner; tidak menargetkan container aplikasi.
- Batas PG: ini regression RLS/schema baseline existing, bukan tes HTTP/Livewire
  resource baru pada PostgreSQL. Tidak menambah file tests/Postgres atau mengubah
  runner/XML shared karena di luar ownership; integrasi ini perlu koordinator.
- Browser lokal 127.0.0.1:8012: login sintetis berhasil, daftar 14 tagihan sendiri
  tampil; filter Lunas menampilkan tepat dua bill paid dari sumber yang sama.
  Next menampilkan 11–14 dari 14 hasil. Detail paid menampilkan peserta/attempt,
  snapshot paket, nominal, dan settled_at; detail rejected memberi arahan
  petugas tanpa tombol reinvoice/verifikasi/upload.
- Desktop list diperiksa pada 1440 px; tabel lebar menggunakan scroll horizontal
  bawaan Filament. Detail diperiksa pada 320, 768, dan 1024 px. Judul bawaan
  dengan ULID terpotong di mobile: diganti judul Indonesia pendek, referensi
  lengkap tetap di ringkasan. Setelah reload, 320 px tidak overflow halaman.
- Console sebelum navigasi negatif tidak memiliki error/warning teramati.
  Percobaan keyboard Enter hanya memfokuskan link; navigasi detail akhirnya
  diverifikasi dengan klik. **Bukan bukti keyboard penuh, screen reader, WCAG,
  tema terang, browser lain, atau semua breakpoint untuk tabel.**
- Percobaan URL asing id 15 di browser tidak menghasilkan bukti yang dapat
  dibaca karena error/policy halaman internal browser. Bukti 404 lintas cabang,
  bill self, dan ID tidak ada berasal dari focused HTTP tests, bukan browser.
- Awal server lambat menyebabkan connection-refused/navigation timeout dan
  reset koneksi browser. Server kemudian dijalankan dengan opcache.enable_cli=0;
  ini opsi proses, bukan perubahan php.ini. Screenshot full-page sempat memiliki
  artefak stitching (DOM memastikan hanya satu alokasi), sehingga bukti akhir
  diganti screenshot viewport setelah resize+reload.
- Tidak menjalankan full regression PHP/JS sekaligus. Tidak ada perubahan
  frontend asset source; tidak menjalankan npm build/lint/typecheck. Asset
  Filament vendor dipublikasikan lokal oleh harness terisolasi untuk preview.

Perintah verifikasi akhir:

```powershell
php -d opcache.enable_cli=0 vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Admin/OrganizationBillAccessTest.php
php -d opcache.enable_cli=0 vendor/bin/pint --test app/Filament/Resources/OrganizationBills tests/Feature/Admin/OrganizationBillAccessTest.php tools/testing/serve-organization-bills-panel.php
# PHPStan dijalankan dengan APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:,
# DB_URL kosong, CACHE_STORE/SESSION_DRIVER=array dan QUEUE_CONNECTION=sync.
php -d opcache.enable_cli=0 vendor/bin/phpstan analyse --no-progress app/Filament/Resources/OrganizationBills
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
```

## File lane dan commit lokal

Commit inti **547e74a** (hanya empat file, 476 baris additive):

- app/Filament/Resources/OrganizationBills/OrganizationBillResource.php
- app/Filament/Resources/OrganizationBills/Pages/ListOrganizationBills.php
- app/Filament/Resources/OrganizationBills/Pages/ViewOrganizationBill.php
- tests/Feature/Admin/OrganizationBillAccessTest.php

Increment pendukung terpisah: tools/testing/serve-organization-bills-panel.php
dan laporan ini. Index diperiksa sebelum commit; tidak git add -A atau commit
baseline. Commit dibuat pada detached HEAD worktree, tidak reset/merge/switch,
tidak push. Koordinator dapat meninjau dua commit lane terhadap 3112e15.

Harness menerima direktori temp baru bernama oncam-bills-{32 hex}; mode `init`
mengisi DB sintetis baru, `assets` menyiapkan asset Filament, lalu serve hanya
127.0.0.1:8012. Init menolak DB yang sudah ada. .env workspace tidak dibaca,
cache/storage/session/DB terpisah; koneksi DB lain dihapus, provider/notifier
fake, Mail fake, Laravel HTTP menolak request tak terduga. Tidak ada invoice,
reservasi action, writer, proof-upload, finalizer atau notifikasi nyata.

Contoh reproduksi dari worktree ini (periksa port 8012 kosong sebelum serve):

```powershell
$env:ONCAM_BILL_PREVIEW_DIRECTORY = Join-Path ([System.IO.Path]::GetTempPath()) ('oncam-bills-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory $env:ONCAM_BILL_PREVIEW_DIRECTORY
php -d opcache.enable_cli=0 tools/testing/serve-organization-bills-panel.php init
php -d opcache.enable_cli=0 tools/testing/serve-organization-bills-panel.php assets
php -d opcache.enable_cli=0 -S 127.0.0.1:8012 -t public tools/testing/serve-organization-bills-panel.php
```

Login sintetis ditampilkan oleh init. Hentikan proses serve setelah review.
Jangan gunakan harness ini sebagai endpoint publik atau pada data aktif.

## Batas integrasi dan cleanup

- Gate test-only harus diganti kontrak produksi yang ditinjau setelah P11c;
  jangan sekadar mengaktifkan APP_ENV=testing pada deployment.
- Diperlukan tes resource list/detail/eager-load dengan runtime PostgreSQL,
  termasuk pooled context dan performa query pada batch besar; regresi RLS
  baseline tidak menggantikannya. Detail memakai eager loading, tetapi daftar
  masih memeriksa persisted membership/record pada authorization tiap baris.
- Belum ada create invoice, pembayaran, proof, verifier atau integrasi P12b/c.
  P12a tidak dicentang selesai. Shared policies/routes/config/schema,
  AssessmentParticipants resource dan checklist kanonik tidak diedit.
- Sidebar Transfer Manual legacy masih mengikuti aturan existing; lane ini
  tidak mengubahnya. Flag legacy tidak mengizinkan mutasi bill baru.
- Server 8012 dihentikan; viewport dikembalikan. Tab uji utama ditutup; tab
  error sementara browser tidak dapat ditutup lewat API karena URL policy.
- Runner PostgreSQL membersihkan container/network; lookup label run
  2821c1beb7b34a3b8f33d6172a43afe6 sesudahnya kosong.
- Penghapusan folder browser sintetis ditolak kebijakan tool meskipun path
  sudah diperiksa. Folder **masih ada** dan bukan DB aktif:
  C:/Users/ThinkPad/AppData/Local/Temp/oncam-bills-81d2dbe9a3254a258ab324dfc0823cee.
  Pointer lokal storage/bill-preview-directory.txt juga belum terhapus;
  keduanya tidak di-commit. Tidak mencoba melewati blok penghapusan.
- Screenshot sintetis tersimpan lokal (tidak di-commit) di
  C:/Users/ThinkPad/.codex/worktrees/6e61/Psikotes/storage/app/private/verification/branch-portal/
  sebagai detail-320.png, detail-768.png dan detail-1024.png.

## Sumber implementasi

API disesuaikan dengan source terpasang Laravel 13.26.1, Filament 5.7.6,
Livewire 4.4.2. Resource memakai getAuthorizationResponse untuk menolak semua
aksi selain viewAny/view, serta query scope dan hydration check. Acuan:
[Filament resource authorization](https://filamentphp.com/docs/5.x/resources/overview#authorization),
[view records](https://filamentphp.com/docs/5.x/resources/viewing-records),
[testing tables](https://filamentphp.com/docs/5.x/testing/testing-tables),
[Laravel authorization](https://laravel.com/framework/docs/13.x/authorization),
dan [OWASP authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).
