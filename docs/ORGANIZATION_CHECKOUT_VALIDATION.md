# Verifikasi checkout organisasi

## Checkpoint bukti statis P17c/P18 — 2026-09-06

Rangkaian commit lokal berikut telah diterima sebagai bukti persiapan statis,
bukan sebagai bukti runtime atau izin aktivasi:

- `3f0acc2` menyelaraskan kontrak TypeScript `checkout-summary-v2` sehingga
  komposisi paket harus canonical, unik, memuat DASS-21, dan memuat sedikitnya
  satu tes non-DASS. Kontrak runtime dan type-level menolak DASS-only, duplikat,
  serta urutan noncanonical tanpa menghitung ulang komposisi di browser.
- `0ecb5f1` memperbarui browser harness statis untuk summary v2. Probe Node murni
  mencatat **17 positive / 59 negative probes**, dan verifier PHP mencatat
  **15 static assertions**. Bukti ini tidak menjalankan browser atau service.
- `70c174b` menambah ownership journal dengan **50 pure/mock tests**. Journal
  tetap memerlukan external ownership anchor; hasil mock tidak membuktikan
  standalone crash recovery dan runtime tetap gated.
- `a2aa851` menambah verifier statis runbook P18; hardening accepted `8c73391`
  mengikat state, audit action, dan lima kelompok/tujuh key gate exact pada config
  strict default-false. Bukti terbaru **7 tes / 190 assertions** juga menjaga link
  lokal, action/coordinator, status provisional, larangan auto-release/reinvoice,
  rollback histori/privacy, opt-in tanpa fallback, serta wiring yang belum ada.

Seluruh bukti di atas bersifat lokal dan statis. Browser P17c belum dijalankan;
standalone crash recovery belum dibuktikan; database, aplikasi, service,
scheduler, provider, dan outbound aktif tidak dijalankan atau diaktifkan.
Acceptance P15, P16, P17c, dan P18 tetap terbuka, dan seluruh gate/writer tetap
default OFF.

### Preparation ownership dan anchor coordinator

Rangkaian accepted `94e294d`, `d41844a`, `e15f55e`, `12ce0bb`, dan `231d64d`
mengonsolidasikan preparation tanpa menyalakan runner. Input snapshot dan argumen
browser kini strict; supervisor mem-pin publisher/callable, menjaga lifecycle
spawn/recovery, dan membatasi suffix tool pada komponen path exact. Adapter
coordinator mengikat config, provenance, identitas operasional store, serta raw
anchor; candidate builder menyiapkan copy immutable dengan final marker.
Verifikasi code-only terakhir mencatat:

- input hardening: **79/79 tes**;
- supervisor: **89/89 pure/mock tests**, dengan census host nyata sengaja tidak
  dijalankan;
- coordinator: **12/12 tes**;
- anchor store: **14/14 tes**;
- candidate builder: **17/17 tes**;
- verifier arsitektur/runbook P18 (`8c73391`): **7/7 tes, 190 assertions**;

Primitive penyimpanan lokal adalah deteksi korupsi/rollback, bukan autentikasi
terhadap pihak yang dapat menulis journal dan anchor. Candidate builder belum
dijalankan terhadap source nyata. Runtime runner masih absent/disabled; ACL dan
exclusive single-writer coordinator, opened-handle/reparse/TOCTOU serta
flush/replace/crash durability Windows nyata, historical process lineage, helper
dan cleanup OS, validasi semantik X.509, inventory vendor dan delivery candidate
nyata, standalone recovery, browser, dan service runtime belum diterima. Tidak
ada process census, browser, service, database aktif, provider, outbound, deploy,
atau aktivasi gate dalam bukti ini. Payment tetap default OFF dan checkbox
P15/P16/P17c/P18 tetap terbuka.

### Delivery aset JavaScript test-only

Commit accepted `5b03674` memberikan dua JavaScript melalui route test-only
literal yang fail closed; `3229f82` memperluas review closure builder dan
supervisor ke aset yang sama. Evidence yang diterima terbatas pada asset-pure
lane **210 assertions**, root contract **15 assertions**, self-test **64 checks**,
candidate builder **18/18**, dan supervisor **90/90 pure/mock**. Full mode dengan
junction OS belum dijalankan. Percobaan asset-pure root berakhir timeout dan
tidak dipakai sebagai bukti.

Percobaan Composer parity tidak diterima dan tetap rejected/uncommitted karena
belum mengikat keseluruhan metadata runtime Composer. Parity runtime Composer,
semantik penuh X.509, delivery candidate nyata, browser/service/OS, dan gate
runtime lain tetap residual. Runner absent/disabled, payment default OFF, dan
checkbox P15/P16/P17c/P18 tidak berubah.

### Validasi lokal pasangan sertifikat — `7025c83`

Candidate builder sekarang memvalidasi parseability sertifikat dan kecocokan
cert/key secara lokal. Key terenkripsi ditolak dengan callback noninteraktif;
identity dan hash cert/key diperiksa sebelum serta sesudah pemuatan. Root mencatat
builder **20/20**, parity AST **2/2**, dan scan header private-key fixture bersih.

Scope ini tidak membuktikan SAN, validity, EKU, CA/chain/trust, key strength,
perilaku browser, atau ACL Windows. Candidate nyata belum dibangun dan tidak ada
deploy maupun runtime aktif. P17c/P18 tetap terbuka dan gate tetap default OFF.

### Envelope hasil Playwright strict — `d954b3c`

Supervisor sekarang mensyaratkan tepat satu marker result pada baris sendiri dan
satu objek JSON yang dikonsumsi penuh. Duplicate key, nilai nonfinite, trailing
payload, dan root non-object ditolak. Schema/order smoke serta full, counter,
history, check canonical, dan source return driver diikat exact. Root mencatat
supervisor **93/93**, parity AST **2/2**, dan check Node lulus; wording tanpa
executable application script telah dibatasi ke state checkout default-OFF.

Tidak ada browser, service/aplikasi, database, environment aktif, network, atau
candidate yang dijalankan; hanya proses tes Python/Node lokal. P17c/P18 tetap
terbuka dan gate tetap default OFF.

### Kontrak browser-config strict — `a391612`

Supervisor sekarang menerima hanya schema browser-config exact yang diterbitkan
candidate builder: root `browser`, context offline/service-worker block,
isolasi, launch headless dengan executable dan argumen berurutan exact, serta
timeout action/navigation integer exact. JSON dibatasi ukurannya dan menolak
UTF-8 invalid, duplicate key, nonfinite, trailing payload, tipe salah, serta key
tambahan. Hash, parse, dan pembacaan ulang menggunakan descriptor yang sama
dengan pemeriksaan identity sebelum/sesudah. Validasi berjalan pada preflight
dan segera sebelum browser launch intent. Bukti root aman: supervisor **96/96**
dan parity AST **2/2**.

Evidence ini tidak menutup penggantian direktori run/source maupun TOCTOU akhir
antara penutupan descriptor dan pembukaan path oleh CLI eksternal. ACL,
single-writer, dan lifecycle identity tetap blocker P17c. Tidak ada candidate,
browser, service/aplikasi, database, environment aktif, network, deploy, atau
aktivasi; P17c/P18 tetap terbuka dan seluruh gate tetap default OFF.

### Binding konfigurasi kandidat exact — `23ed855`

Supervisor kini menerima hanya key konfigurasi dan sepuluh pasangan path/hash
exact keluaran builder. Path absolut wajib memakai ejaan canonical builder,
file runtime wajib berada di direktori kandidat dengan nama tetap, dan review
aset ikut dihitung bersama direktori, manifest, session, serta seluruh tool ke
`configBinding`. Recovery menolak config rusak sebelum membaca anchor.
Coordinator memetakan kegagalan nested menjadi `coordinator_config`; parity
builder/supervisor diperiksa statis tanpa mengeksekusi source supervisor.

Bukti root: coordinator+builder **34/34**, supervisor aman **98/98**, AST **5
file**, dan diff-check lulus. Ini belum membuktikan ACL/single-writer,
perlindungan penggantian direktori, lifecycle identity OS, atau TOCTOU terakhir
saat CLI eksternal membuka path. Tidak ada candidate, browser, service, DB,
environment aktif, network, deploy, atau aktivasi; P17c/P18 tetap terbuka dan
seluruh gate/payment tetap default OFF.

### Source identity dan lifecycle façade — `30c78ee`, `bfda8c4`

Builder kini mem-pin identity source root beserta setiap directory ancestor yang
dilalui, lalu memvalidasinya sepanjang inventory, open/hash/copy, dan sebelum
publikasi config final. Clone/swap/reparse gagal tertutup dengan marker
incomplete; cleanup selalu mencoba semua descriptor tanpa menutupi interruption.
Coordinator menyediakan façade import-only yang selalu melakukan assembly dan
binding sebelum mendelegasikan fresh/recovery ke instance supervisor yang sama.

Bukti root gabungan: **42/42 tes** dan AST **4 file**, serta diff-check lulus.
Ini menutup kontrak code-only fase build/delegasi, bukan race namespace terakhir,
identity lintas-crash, lifecycle lease, ACL Windows, atau pembukaan ulang path
oleh PHP/Node/Playwright/CreateProcess. Tidak ada candidate, lifecycle nyata,
browser, service, DB, env aktif, network, deploy, atau aktivasi; P17c/P18 tetap
terbuka dan seluruh gate/payment tetap default OFF.

### Lease lifecycle kandidat — `fe45bb2`, `cec171c`

Satu provenance file run-local yang stabil dan tidak pernah dihapus kini menjadi
namespace lease untuk tiap kandidat, independen dari pilihan direktori anchor.
Kernel/advisory lock nonblocking ditahan pada handle non-inheritable; same-process
dan coordinator A/B untuk run yang sama saling menolak. Config di-snapshot sekali
dan lease diperoleh sebelum assembly maupun anchor I/O. Supervisor menolak bare
claim/recovery tanpa capability yang dipin, sedangkan publisher memvalidasinya
sebelum/sesudah anchor I/O. Cleanup selalu unlock/close tanpa menutupi exception.

Bukti root: lease+coordinator+builder **58/58**, supervisor aman **101/101**, AST
**8 file**, dan diff-check lulus. Bukti ini masih pure/sintetis: kernel lock/crash,
run rename/reparse, NTFS durability, ACL/effective access, dedicated-process
ownership, dan browser acceptance Windows nyata tetap terbuka. Tidak ada runtime,
browser, service, DB, env aktif, network, deploy, atau aktivasi; P17c/P18 dan
seluruh gate/payment tetap default OFF.

## Checkpoint P16 consent subset dan privacy audit — 2026-09-06

Commit `918eb93` menambah policy PostgreSQL restrictive yang membuat audit
`checkout.confirmed` dan `checkout.consent_reaccepted` hanya terbaca role
service, tanpa mengubah akses audit non-sensitif. Commit `ae54cd6` membuat
presenter/request/writer/Blade/transport menerima hanya subset profil dan consent
yang masih wajib, endpoint literal `/checkout/confirm`, serta histori konfirmasi
bergenerasi untuk withdrawal/re-consent dan rotasi dokumen. Commit `0ea1924`
memperpanjang retensi audit establishment sampai dua tahun setelah absolute
session expiry agar bukti actor tetap tersedia selama audit konfirmasi.

Bukti root lokal:

- Blade summary **19 kasus** dan mandatory DASS **7 kasus**;
- transport Node **4/4**;
- PHP/HTTP gabungan **38 tes / 684 assertions**;
- retensi establishment **7 tes / 117 assertions**;
- PostgreSQL privacy disposable **2 tes / 30 assertions**;
- fresh PostgreSQL setelah penyelarasan fixture DASS/v2 **400 tes / 3.950 assertions**;
- PHPStan 0 error, Pint, ESLint, Prettier, dan `git diff --check` lulus.

Audit history menolak actor asing, generation gap/duplikat, context atau waktu
rusak, event di luar interval sesi, profil replay yang kembali kurang, serta
consent bertanggal masa depan. Re-consent menyimpan histori append-only dan
rollback profil/consent/audit diuji atomik. Seluruh feature/writer tetap default
OFF. Tidak ada browser P17c, migrasi database aktif, provider/outbound nyata,
deploy, push, atau aktivasi sumber; acceptance P15/P16 tetap terbuka.

Penyelarasan fresh PostgreSQL bersifat test-only pada `e2d3cdc`, `cfa0308`, dan
`cf1780b`; tidak mengubah aplikasi atau schema. Runner memakai jaringan internal
tanpa port publik dan membersihkan seluruh container/network disposable.

## Checkpoint P5 — 2026-08-31

Status historis checkpoint: P1–P5 selesai lokal; tinjauan pengguna sebelum kelompok P6. Pembayaran
kolektif belum selesai dan tidak diaktifkan. Kontrak:
[ORGANIZATION_CHECKOUT_CONTRACT.md](ORGANIZATION_CHECKOUT_CONTRACT.md).
Bukti tahap sebelumnya: [ORGANIZATION_PAYMENT_TESTING.md](ORGANIZATION_PAYMENT_TESTING.md).

### Lingkup perubahan

1. Adapter, Form Request, config default OFF, tes kontrak, dan dokumen kontrak.
2. Guard sebelum lookup replay pada ProvisionAssessmentParticipant dan
   ProvisionSelectionParticipant, menggunakan adapter bersama; perluasan tes.
3. Tes PostgreSQL guard/context, review dan dokumentasi checkpoint.

Tidak ada migrasi/skema, route publik baru, aset frontend, dependency, harga,
status pembayaran existing, atau perubahan autentikasi HMAC. Input tetap
camelCase, validasi field allow-list, dan kesalahan aman mengikuti pola proyek.
Skill API/interface, keamanan, TDD, dan review digunakan untuk batas kontrak,
tes negatif, dan pemeriksaan tanpa memberi akses dini.

### Bukti RED → GREEN

- Fixture awal perlu memperbaiki enum SelfPay, mengisi allowed_funding_modes
  wajib, serta mengganti closure middleware test yang tidak didukung menjadi
  AuthenticateIntegrationClient asli dengan signature sintetis. Constraint
  schema dan autentikasi tidak dilonggarkan.
- Setelah fixture benar: 15 tes gagal/error karena adapter/request belum ada;
  implementasi awal menghasilkan 15 tes/41 assertions lulus.
- Lima kasus cutover gagal: replay v1 masih 200 dan jalur dedicated masih
  menerima permintaan. Guard sebelum replay menghasilkan 32 tes/156 assertions
  lulus bersama dua suite provisioning legacy.
- Perluasan input menemukan key root literal `profile.fullName` masih diterima.
  Allow-list root dipisahkan dari nama rule bersarang; kasus tersebut lulus.
- Tes tanpa service context gagal pada SQLite dan PostgreSQL. Guard kini
  menolak sebelum query agar RLS tidak menghasilkan izin palsu dari baris tersembunyi.

### Hasil akhir

| Pemeriksaan | Hasil |
| --- | --- |
| CheckoutContractCompatibilityTest | 25 tes, 99 assertions lulus |
| Regresi Unit + Feature + Architecture | 498 tes, 2.531 assertions lulus |
| PostgreSQL disposable runtime RLS | 33 tes, 134 assertions lulus |
| Pint seluruh proyek | Lulus |
| PHPStan seluruh aplikasi | Lulus, 0 error |
| git diff --check | Lulus |

Perintah dari root:

```powershell
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Integrations/CheckoutContractCompatibilityTest.php
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
php vendor/bin/pint --parallel --test
php vendor/bin/phpstan analyse --no-progress
git diff --check
```

Tidak ada skip; grup sandbox eksternal tidak dijalankan. PostgreSQL menggunakan
network internal tanpa published port, database/tmpfs disposable, runtime
psikotes_runtime bukan owner, dengan bootstrap guard dan outbound fake. Kedua
run membersihkan container/network test; container aplikasi tidak ditargetkan.
Tambahan dua tes PostgreSQL membuktikan query marker lintas relasi bekerja dalam
service RLS, scope organisasi/sumber tidak meluas, context kembali, dan tanpa
context ditolak. Ini bukan simulasi race dua proses.

### Skenario dan review

- Kontrak default OFF; self/organization eksplisit dan kebutuhan memilih pembayar.
- Mapping legacy hanya opt-in, SPONSORED/INTERNAL/WAIVED ditolak, dua field
  pembayar sekaligus ditolak walaupun payerType null.
- Profil parsial/null-field valid, supplied field invalid ditolak; harga/paid/
  cabang/metadata asing tidak diterima. Request tanpa signature ditolak.
- Adapter mematuhi lock sumber dan scope organisasi/sumber/paket; tidak membuat
  participant, assessment, order, entitlement, atau outbox.
- Marker v2 menghalangi create/replay v1 walau baris v1 masih ada dan status v2
  ACTIVE/DRAFT/SUSPENDED/RETIRED. Marker berlaku di client lain lembaga sama,
  bukan lembaga berbeda. Jalur dedicated selection tidak melewati cutover.
- Regresi mempertahankan provisioning legacy yang belum dipindahkan. Histori
  dan entitlement lama tidak dihapus/diubah oleh marker.

Review lokal memeriksa lima aspek: kebenaran alur sebelum replay, keterbacaan
guard bersama, kompatibilitas pola exception/kontrak, scope dan input ketat,
serta query exists terbatas tanpa pemuatan daftar tak terbatas. Tidak ada
perubahan dependency atau rahasia baru. Commit/push massal tidak dilakukan:
file bergantung pada registry/model sebelumnya yang sebagian masih untracked.

### Batas bukti dan langkah berikutnya

Belum ada HTTP provisioning checkout publik: route `/_test/p5` hanya hidup
dalam test untuk membuktikan HMAC → Form Request. Adapter dieksekusi dengan
model hasil database test. Ini belum alur peserta → invoice → lunas → akses.
Endpoint dan persistence P9, idempotensi reservasi, schema billing, consent,
harga snapshot, UI checkout, race/cutover saat writer berjalan, dan tagihan
sepuluh peserta belum diklaim selesai.

Tidak ada perubahan UI/aset, sehingga browser/lint/typecheck/build frontend
tidak diulang pada P5; bukti browser P4b tetap historis beserta keterbatasannya.
Tidak ada deploy, migrasi DB aktif, invoice nyata atau WhatsApp. Jangan membuat
marker checkout-v2 pada sumber aktif sebelum P9–P18 siap dan cutover disetujui.
Checkpoint ini diserahkan untuk ditinjau; berikutnya P6a schema bill/charge.

## P6a — penyimpanan bill/charge, 2026-08-31

Status: selesai lokal. Berikutnya P6b; belum pembayaran kolektif end-to-end.

Pengguna menjawab "lanjutkan" setelah checkpoint P5. Increment pertama:
kontrak, migrasi, dua model, dan tes SQLite (lima file). Increment kedua:
tes PostgreSQL, dilanjutkan dokumentasi. Kontrak lengkap:
[ASSESSMENT_BILLING_SCHEMA.md](ASSESSMENT_BILLING_SCHEMA.md).

### RED, diagnosis, dan perlindungan

- Awal SQLite: 10 tes gagal karena model belum ada; setelah implementasi
  10 tes/40 assertions lulus.
- Awal PostgreSQL: 61 tes/165 assertions, 25 gagal. Data invalid belum ditolak
  dan FORCE RLS belum aktif. Migrasi kemudian menambah CHECK dan service-only
  policy, tanpa memberi akses peserta/cabang ke tabel fitur yang belum lengkap.
- Run berikutnya 66 tes/172 assertions, satu gagal. Test memakai transaksi
  luar untuk rollback fixture; SET LOCAL service tetap hidup setelah savepoint
  dilepas. Pembacaan yang disebut "tanpa context" sebenarnya masih service.
  Tes kini membuktikan role service tersebut, mengosongkan tiga GUC test secara
  eksplisit, dan menegaskan role NULL sebelum memeriksa baris tersembunyi.
  Policy tidak dilonggarkan dan assertion tidak dihapus. Ini tidak mengubah
  RlsContextRunner; pemanggil tetap wajib menjaga batas transaksi/context.

### Hasil akhir

| Pemeriksaan | Hasil |
| --- | --- |
| AssessmentBillingSchemaTest (SQLite) | 10 tes, 40 assertions lulus |
| Regresi Unit + Feature + Architecture | 508 tes, 2.571 assertions lulus |
| PostgreSQL disposable runtime | 66 tes, 184 assertions lulus |
| Pint seluruh proyek | Lulus |
| PHPStan | Lulus, 0 error |
| git diff --check | Lulus |

Perintah sama dengan checkpoint P5 di atas, dengan focused test diganti menjadi
`tests/Feature/Database/AssessmentBillingSchemaTest.php`. Tidak ada skip; sandbox
eksternal dikecualikan. Ketiga run PostgreSQL menutup container/network miliknya
sendiri; tidak menargetkan container aplikasi, port publik atau database aktif.

### Cakupan yang dibuktikan

- Hydration model/relasi, integer IDR, status awal reserved dan timestamp NULL;
  update harga katalog tidak mengubah snapshot biaya/tagihan yang disimpan.
- FK komposit charge mengikat attempt, organisasi, peserta, paket; PostgreSQL
  menolak juga ID yang benar-benar ada tetapi milik organisasi berbeda.
- Satu charge per attempt, reference unik, idempotensi kolektif tetap unik
  walaupun payer_participant_id NULL. Key mandiri terpisah dari key lembaga.
- PostgreSQL menolak total/komponen tidak konsisten, nominal negatif, non-IDR,
  pembayar/status/reference/hash invalid, key kosong, JSON bukan object,
  konsultasi tidak konsisten, serta timestamp paid/verifier/free yang invalid.
- Bill self valid dan bill kolektif 10 item valid secara skema; belum membuktikan
  keanggotaan 10 peserta atau penjumlahan item (P6b/P7). Charge nol tidak otomatis
  membuat bill atau membuka entitlement.
- FORCE RLS pada kedua tabel, service dapat menulis/membaca, tanpa context dan
  role peserta/cabang/admin tidak dapat membaca; insert cabang ditolak.
  Kebijakan tenant lengkap untuk empat tabel menyusul P6c.
- SQLite membuktikan populated down/up, order legacy identik, attempt masih
  PROVISIONED, dan upgrade ulang dapat menyimpan charge. CHECK/RLS bukan
  dibuktikan oleh SQLite; populated rollback PostgreSQL belum diuji di P6a.

### Batas dan review

Review lokal mencakup konsistensi FK/pembayar, partial unique untuk NULL,
aritmetika numeric agar penjumlahan bigint tidak overflow, default tanpa harga
operasional, dan operasi rollback. Model hanya persistence internal, bukan
endpoint atau izin menulis nominal dari HTTP. FK memakai RESTRICT agar histori
tidak terhapus lewat cascade; down() destruktif hanya dijalankan di database tes.

Snapshot belum dikunci oleh action reservasi (P7), status belum memiliki state
machine/verifikasi payment event (P10/P11), dan bill-items/entitlement per attempt
belum ada (P6b). Jangan mengaktifkan checkout-v2 dari hasil skema ini saja.
Tidak ada perubahan UI sehingga browser/build frontend tidak diulang.
Tidak ada migrasi DB aktif, deploy, harga aktif berubah, invoice atau WA nyata.
Perubahan tetap working tree bersama dependensi integrasi sebelumnya; tidak
melakukan commit/push massal atau menandai checklist F1 selesai.

## P6b — keanggotaan tagihan dan hak per attempt, 2026-08-31

Status: selesai lokal. Berikutnya P6c; pembayaran kolektif belum end-to-end.

### Hasil akhir

| Pemeriksaan | Hasil |
| --- | --- |
| AssessmentBillItemsSchemaTest (SQLite) | 12 tes, 65 assertions lulus |
| Regresi Unit + Feature + Architecture | 520 tes, 2.636 assertions lulus |
| PostgreSQL disposable runtime | 98 tes, 327 assertions lulus |
| Pint seluruh proyek | Lulus |
| PHPStan | Lulus, 0 error |
| git diff --check | Lulus |

```powershell
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Database/AssessmentBillItemsSchemaTest.php
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
php vendor/bin/pint --parallel --test
php vendor/bin/phpstan analyse --no-progress
git diff --check
```

Tidak ada skip; grup sandbox eksternal tidak dijalankan. Dua run PostgreSQL
P6b memakai jaringan internal tanpa port publik, fixture sintetis, runtime bukan
owner, dan membersihkan container/network test miliknya sendiri.

### Lingkup dan RED/GREEN

Pengguna melanjutkan P6b setelah hasil P6a diserahkan. Increment kontrak/fixture/
tes dipisah dari dua migrasi/dua model, lalu tes PostgreSQL dan penyesuaian
rollback historis P6a; dokumentasi diperbarui terpisah. Tidak ada endpoint baru.

Tes awal memperlihatkan empat error tabel/model belum ada, sedangkan delapan
tes negatif terlalu umum menerima QueryException akibat tabel belum ada.
Prasyarat tabel dipertegas sebelum setiap tes: hasil RED sah 12 tes gagal,
24 assertions. Sesudah schema/model portable, 12 tes/65 assertions lulus.
Gabungan tes P6a/P6b sebelum perluasan PostgreSQL: 22 tes/105 assertions lulus.

PostgreSQL RED: 92 tes/291 assertions, 11 gagal sebelum CHECK P6b ditambahkan.
Kegagalan meliputi tipe/status/waktu entitlement, NULL payer self, dan item nol;
organization dengan payer peserta ditolak FK tetapi belum CHECK yang dituju.
Constraint PostgreSQL kemudian menutup kasus tersebut. SQLSTATE eksplisit
membedakan pelanggaran CHECK (23514), FK (23503), UNIQUE (23505), dan RLS (42501)
agar error unrelated tidak dianggap bukti pengamanan yang benar.

### Skenario dan review

- Child FK komposit menolak bill/charge/organisasi/peserta/attempt silang dengan
  ID existing. Item harus cocok nominal, currency dan payer_type dengan parent.
- Bill mandiri hanya satu charge, termasuk jika dua attempt milik peserta sama.
  Peserta lain dalam cabang sama ditolak, baik memakai payer sendiri maupun
  menyalin payer dari bill untuk memalsukan hubungan.
- Tagihan kolektif menerima dua peserta satu cabang; hak keduanya tetap locked.
  Ini pengujian persistence sintetis, bukan action reservasi/invoice dua peserta.
- UNIQUE charge tetap menghalangi klaim baru setelah expired (SQLite) atau
  rejected (PostgreSQL). UNIQUE attempt/test_type menolak duplikasi namun
  mengizinkan attempt baru peserta sama. Lima jenis tes didukung skema.
- Status locked default dan timestamp NULL; status/timestamp invalid ditolak.
  Urutan ready/in_progress/done valid dapat disimpan oleh service. Ini bukan
  bukti verifikasi pembayaran/consent; gate aktual belum memakai tabel baru.
- FK menolak perubahan harga parent yang merusak snapshot item serta penghapusan
  bill/charge yang dirujuk. CHECK item nol melarang biaya gratis masuk tagihan.
- Service-only FORCE RLS pada dua tabel baru; tanpa konteks dan role peserta/
  cabang/admin tidak membaca baris; insert cabang ditolak. Konteks test direset
  eksplisit karena SET LOCAL tetap hidup di transaksi luar fixture.
- Populated down/up SQLite mempertahankan bill/charge; tes P6a kini melepas
  child dahulu sebelum rollback parent, lalu memasang kembali sesuai dependensi.
  Tidak menggunakan disable FK atau CASCADE. Populated rollback PostgreSQL
  tetap gerbang P6c, bukan diklaim dari tes SQLite.

Review mencakup kebenaran scope/NULL, kosakata legacy yang dipertahankan,
model persistence tanpa side effect, nama constraint eksplisit, dan indeks
pendukung FK. Duplikasi kolom scope pada child adalah keputusan integritas,
bukan data bebas dari request. Fixture bersama hanya di tests/Support dan
memakai nilai sintetis; tidak menambah dependency, rahasia, atau data klinis.

### Batas pelaksanaan

Tidak ada migrasi database aktif, deploy, perubahan harga katalog, gateway,
notifikasi, atau aktivasi checkout-v2. Service masih dapat menjalankan operasi
internal; immutabilitas reservasi, jumlah/total item, validasi paket dan consent,
serta alokasi pelunasan adalah gerbang action P7/P8/P11. Tidak boleh memakai
status atau timestamp baru sebagai bukti pembayaran terverifikasi dengan sendirinya.
UI/frontend tidak berubah sehingga browser/build tidak diulang. Perubahan
masih working tree, tidak melakukan commit/push massal atas dependensi lama.

## Checkpoint P6c — akses tenant dan lifecycle PostgreSQL, 2026-08-31

Status: P1–P6c selesai lokal; checkpoint diserahkan sebelum P7a.

### Hasil akhir

| Pemeriksaan | Hasil |
| --- | --- |
| PostgreSQL disposable (termasuk seluruh tes P6) | 121 tes, 504 assertions lulus |
| Regresi Unit + Feature + Architecture | 520 tes, 2.636 assertions lulus |
| Pint seluruh proyek + focused setelah assertion tambahan | Lulus |
| PHPStan | Lulus, 0 error |
| git diff --check | Lulus |

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox
php vendor/bin/pint --parallel --test
php vendor/bin/pint --test tests/Postgres/AssessmentBillingRlsTest.php
php vendor/bin/phpstan analyse --no-progress
git diff --check
```

Tidak ada skip. Grup sandbox eksternal tidak dijalankan. Ketiga run PostgreSQL
P6c membersihkan container/network miliknya; aplikasi aktif tidak ditargetkan.

### Kontrak dan implementasi

Matriks baca pada [ASSESSMENT_BILLING_SCHEMA.md](ASSESSMENT_BILLING_SCHEMA.md#matriks-akses-p6c)
diterapkan oleh migrasi additive 000500. Empat policy baru hanya FOR SELECT;
policy service P6a/P6b tetap satu-satunya jalan tulis. Tidak ada role/credential
baru pada aplikasi, API, UI, verifikasi paid, atau gate akses baru.

SuperAdmin membaca semua; BranchAdmin membaca organisasi sendiri. Peserta
membaca charge/entitlement dengan organisasi DAN participant_id cocok, tetapi
tidak membaca bill/item, termasuk bill self. Proyeksi invoice self pada checkout
adalah tugas berikutnya. Staff/psychologist/tanpa konteks tetap tidak membaca
data baru. Tidak memberi akses klinis melalui peran pembayar.

Tes service-only historis P6a/P6b diselaraskan ke matriks baca P6c; assertion
FORCE RLS dan larangan tulis tetap ada. Tes P6c terpisah membuktikan matriks
terhadap peserta sendiri, peserta lain dalam cabang sama, dan cabang lain.

### RED/GREEN dan review

- PostgreSQL awal: 115 tes/387 assertions, empat gagal karena izin baca baru
  belum ada. Test mutation yang ada tetap menolak penulisan pengguna.
- Setelah migrasi dan lifecycle test: 121 tes/498 assertions lulus.
- Review menambahkan assertion identitas peserta yang terlihat, bukan hanya
  jumlah baris, serta pemeriksaan izin TRUNCATE tidak dimiliki akun runtime.
- UPDATE/DELETE langsung oleh peserta/cabang/SuperAdmin mengubah nol baris;
  INSERT baris fixture lengkap ditolak dengan SQLSTATE 42501. Tanpa context
  juga ditolak; service tetap dapat menulis. Uji FK/unique P6a/P6b dijalankan
  ulang sebagai runtime untuk menjaga larangan kaitan silang dan klaim ganda.
- Predicate memakai kolom scope berindeks, tanpa join/subquery ke tabel klinis.
  Nama tabel berasal dari daftar statis migrasi, bukan input HTTP. Kebijakan
  baca tidak memakai FOR ALL, sehingga tidak membuka hak menandai paid/ready.

### Pengujian migrasi PostgreSQL berisi data

AssessmentBillingMigrationTest memverifikasi testing, /.dockerenv, host
org-test-db, nama database disposable, current_user runtime, dan marker unik
invokasi runner sebelum membuka koneksi owner khusus DDL. Tidak memakai .env.
Seluruh DDL/fixture dibungkus satu transaksi owner yang selalu di-rollback;
default connection dan facade Schema dikembalikan, koneksi owner ditutup.
Pengujian izin terpisah tetap memakai runtime non-owner/NOBYPASSRLS.

1. Fixture sintetis berisi empat tabel billing, branch, participant, attempt,
   package, order legacy, dan entitlement legacy ready.
2. down/up policy P6c menjaga seluruh row billing identik. Saat down, hanya
   service policy tersisa dan FORCE RLS tetap aktif.
3. down P6c → entitlement → item → bill/charge menghapus tabel baru, tidak
   mengubah enam jenis row legacy tersebut.
4. up berurutan di atas legacy populated berhasil; row billing dapat disimpan
   ulang dengan FK utuh. Entitlement baru tetap locked walau legacy ready.

Ini bukti lifecycle DDL pada database disposable, bukan prosedur menghapus
histori produksi. Rollback policy non-destruktif; rollback tabel tetap destruktif
bagi data tabel baru dan tidak boleh dijalankan setelah transaksi produksi.

### Batas checkpoint

Belum ada reservasi/preview, invoice, alokasi pembayaran, autentikasi checkout
publik, atau UI kolektif. Bukti SQL bukan bukti browser end-to-end atau race
reservasi dua proses; race tetap P7/P17. Tidak ada perubahan frontend sehingga
browser/lint/typecheck/build frontend tidak diulang. Database aktif, harga,
Cloudflare, gateway, n8n dan WhatsApp tidak disentuh. Perubahan tetap working
tree, tidak melakukan commit/push massal. Checkpoint P6 diserahkan untuk
tinjauan pengguna sebelum P7a (snapshot dan preview harga).

## P7a — snapshot harga dan preview read-only, 2026-08-31

Status: selesai lokal. Pengguna melanjutkan setelah checkpoint P6. Kontrak:
[ASSESSMENT_BILL_PREVIEW.md](ASSESSMENT_BILL_PREVIEW.md).

### Hasil akhir

| Pemeriksaan | Hasil |
| --- | --- |
| AssessmentPriceSnapshotTest | 11 tes, 16 assertions lulus |
| AssessmentBillPreviewTest (Feature) | 22 tes, 48 assertions lulus melalui regresi |
| Regresi Unit + Feature + Architecture | 553 tes, 2.700 assertions lulus |
| PostgreSQL disposable runtime | 127 tes, 531 assertions lulus |
| Pint seluruh proyek | Lulus |
| PHPStan | Lulus, 0 error |
| git diff --check | Lulus |

```powershell
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit/Payments/AssessmentPriceSnapshotTest.php
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillPreviewTest.php
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
php vendor/bin/pint --parallel --test
php vendor/bin/phpstan analyse --no-progress
git diff --check
```

Tidak ada skip. Sandbox eksternal dikecualikan; container/network PostgreSQL
disposable dibersihkan setelah run, tanpa menargetkan aplikasi aktif.

### RED/GREEN dan diagnosis

- Kalkulator RED: 11 tes gagal/error sebelum service tersedia; GREEN 11/16.
- Preview RED: 17 tes gagal/error sebelum action tersedia; fixture menemukan
  nama enum harus SelfPay, bukan Self. GREEN awal 17/42.
- Perluasan claim, snapshot invalid, revoke dan limit: gabungan unit/feature
  32 tes/63 assertions. Guard peserta soft-delete menambah satu tes/assertion.
- PHPStan menemukan anotasi relasi peserta existing menganggap non-null,
  padahal soft-delete membuat relasi tidak termuat. Tes soft-delete membuktikan
  perilaku ditolak sudah benar. Guard sekarang memeriksa objek getRelation
  dengan instanceof Participant; tidak menonaktifkan rule atau menambah ignore.

### Skenario dan review

- Harga paket/konsultasi dibaca DB, mata uang IDR, free tanpa konsultasi dan
  free dengan konsultasi berbayar, harga kosong/invalid dan overflow komponen.
- Total sepuluh attempt dengan paket berbeda diuji SQLite dan PostgreSQL.
  PostgreSQL mencakup satu free dan satu konsultasi. Tidak menulis charge,
  bill, item, entitlement, order legacy, atau outbox selama preview.
- Snapshot stored valid tidak mengikuti perubahan katalog; pilihan konsultasi
  existing terkunci; snapshot versi asing tidak dihitung ulang diam-diam.
- Policy OFF dibaca ulang; claim expired dan free-settled tidak ditagih lagi;
  revoked, soft-deleted dan attempt legacy ditolak. Source saja tidak cukup:
  marker metadata checkout_contract_version=checkout-v2 wajib pada attempt.
- Nominal tambahan dari caller, bool/string longgar, duplikasi, empty selection,
  limit+1 dan konfigurasi invalid ditolak. Limit dapat diganti lewat config;
  yang diuji batas konfigurasi 2 dan 3, serta sepuluh item dengan default 100.
- Attempt asing dan tidak ada memakai reason yang sama tanpa snapshot/nama
  peserta. Service query tetap difilter organization; self harus cocok peserta.
  Peran peserta/cabang/SuperAdmin tidak langsung memanggil action internal.
- Hash selection tidak bergantung urutan input dan berubah saat harga berubah;
  snapshot memiliki urutan field/test types canonical. Tidak memasukkan waktu
  preview atau credential. Bila satu item invalid, total/hash NULL dan
  canReserve=false; tidak menyediakan total parsial untuk dibayarkan.
- Query memuat graph/sumber/charge/claim secara batch sesuai limit, bukan lazy
  load per item. Tidak menambah dependency atau mengubah auth/route existing.

### Batas dan langkah berikutnya

Preview ini backend internal, bukan halaman ringkasan checkout. Pemanggil P12/
P13 wajib autentikasi dan memetakan organization/participant dari principal,
bukan meneruskan ID browser sebagai otorisasi. P9 wajib menulis marker attempt
server-owned. Flag checkout tidak diubah pada sumber aktif.

Tidak ada lock/reservasi; data dapat berubah setelah preview. P7b harus reload
seluruh policy/harga/scope, memeriksa hash serta UNIQUE claim dalam transaksi,
dan menolak seluruh daftar bila berubah. Hash bukan izin bayar, bukti lunas,
atau token akses. Race multi-proses dan pipeline invoice belum selesai.
Frontend tidak berubah; browser/build frontend tidak diulang. Database aktif,
harga aktif, Cloudflare, n8n dan gateway tidak disentuh. Perubahan tetap working
tree bersama dependensi lama; tidak commit/push massal.
## P7b — Reservasi atomik mandiri/kolektif (2026-08-31)

Action internal menerima principal Admin/Participant yang telah diautentikasi
caller, bukan organization_id/payer/nominal dari browser. Service context wajib;
role, cabang, peserta dimuat ulang. BranchAdmin membayar organisasi sendiri;
peserta hanya satu attempt sendiri. Role lain tidak otomatis menjadi pembayar.
Kontrak lengkap: [ASSESSMENT_BILL_RESERVATION.md](ASSESSMENT_BILL_RESERVATION.md).

### TDD dan increment

1. Empat tes feature pertama RED karena ReserveAssessmentBill belum ada.
2. Validasi list dipindah ke AssessmentBillSelection dan dipakai preview;
   22 tes preview/48 assertions tetap lulus.
3. Action transaksi dengan lock/replay/snapshot/claim/audit membuat empat tes
   awal GREEN (26 assertions); diperluas menjadi 29 tes/114 assertions.
4. Delapan tes PostgreSQL baru memakai runtime non-owner: tujuh race dengan
   dua proses/koneksi independen, satu injeksi kegagalan setelah item tersimpan.
   Suite bertambah dari 127/531 menjadi 135/675, tanpa skip.
5. Review menghilangkan SELECT charge per item dengan satu batch load. Tes
   terfokus setelah perubahan: 29/114 lulus; regresi penuh diulang setelahnya.

Verifikasi final setelah review: **582 tes regresi/2.814 assertions** dan
**135 tes PostgreSQL/675 assertions**, semuanya lulus tanpa skip. Pint,
PHPStan (0 error), dan git diff --check lulus. Sandbox eksternal tidak dijalankan.

### Bukti perilaku

- Sepuluh attempt menghasilkan satu reserved bill, sepuluh charge/item,
  total DB + konsultasi, audit sekali, tanpa settlement/entitlement/outbox/order.
- Batas default 100 diuji dengan 100 fixture berbeda (bukan sekadar config);
  101 ditolak. Batas override 2/3 juga diuji. Integer overflow ditolak.
- Snapshot charge existing dan policy historis tidak direprice; policy terbaru
  tetap diperiksa. Gratis tidak masuk tagihan, tidak di-settle otomatis.
- Hash mengikat pilihan canonical, scope, preview, dan kanal. Urutan daftar
  maupun urutan key JSON tidak menggandakan bill. Key sama dengan isi/kanal
  berbeda ditolak; replay sah tetap mengembalikan bill setelah policy/kanal OFF.
- Role persisted berubah/soft-deleted menolak replay. Model branch palsu,
  foreign/missing attempt, input tambahan, duplikasi, key invalid ditolak.
- Expired/rejected/paid tidak membuka claim untuk niat baru. Status tidak
  diubah menjadi paid melalui reservasi.
- Injeksi gagal pada item kelima dari sepuluh (SQLite) dan item ketiga dari
  tiga (PostgreSQL) membatalkan seluruh bill, charge, item, dan audit reservasi.

### Bukti concurrency PostgreSQL

Test melakukan fork tanpa mewarisi koneksi PDO, memeriksa current_user setiap
worker sebagai psikotes_runtime, mengoordinasikan barrier melalui socket, dan
memastikan PID berbeda serta kedua backend benar-benar menunggu lock database
sebelum barrier dilepas. pcntl yang tidak tersedia menggagalkan tes, bukan skip.
Urutan antre dibuat eksplisit agar skenario OFF/perubahan harga mendahului
reservasi yang menunggu; timeout test membatasi tunggu. Skenario:

1. Dua admin, key+payload sama → bill sama, satu set items/audit.
2. Dua batch overlap → pemenang utuh, tidak ada sebagian item pihak kalah.
3. Mandiri vs kolektif → satu claim attempt, tidak ada tagihan kedua.
4. OFF melalui UpdateFundingPolicy source → reservasi menunggu ditolak.
5. Key sama, selection berbeda → IDEMPOTENCY_CONFLICT.
6. OFF melalui SetPaymentMethodActivation → reservasi menunggu ditolak.
7. Harga package berubah → hash preview lama ditolak.

Fixture committed hanya untuk race dalam database disposable; teardown
menghapus fixture berdasarkan ID, lalu runner membersihkan container/network
dengan exact name+label. Tidak ada port publik, koneksi data aktif, migrasi
aktif, invoice nyata, WA, n8n, atau Cloudflare yang diubah.

### Perintah dan batas

```powershell
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillReservationTest.php
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
php vendor/bin/pint --parallel --test
php vendor/bin/phpstan analyse --no-progress
git diff --check
```

Tidak ada perubahan UI: browser, lint/typecheck/build frontend tidak diulang.
Sandbox eksternal dikecualikan; bukan dihitung sebagai tes lulus. P7b hanya
reservasi internal: gate akses P8, provisioning P9, invoice/finalisasi dan
panel cabang tetap belum tersambung. Belum membuktikan alur pembayaran publik
end-to-end atau throughput produksi. Lock organisasi/registry sengaja kasar;
durasi/skalabilitas perlu pengukuran sebelum rollout. Caller yang membuka outer
transaction wajib menunda semua efek gateway sampai outer commit.

Review mencakup correctness, scope/RLS, replay, immutability, atomicity,
dan pembacaan batch. Tidak ada dependency baru atau perubahan migrasi/secrets.
Working tree tetap berisi perubahan lama yang saling bergantung; tidak dilakukan
mass staging, commit, push, maupun klaim siap merge/deploy.
## P8a — Gate akses per attempt (2026-08-31)

Kontrak: [ASSESSMENT_ACCESS_GATE.md](ASSESSMENT_ACCESS_GATE.md).
Implementasi additive: AssessmentPrincipal, AssessmentEntitlementGate,
AssessmentAccessPrerequisites. Tiga file produksi dan fixture/tes feature
sebagai satu increment, tes PostgreSQL terpisah, lalu dokumentasi.
Tidak mengubah route/login/JWT/gate legacy atau schema. FormRequest/start
integration berada pada P8b bersama verifier token tujuan assessment;
principal DTO sendiri bukan bukti autentikasi.

### TDD, diagnosis, dan bukti akhir

Empat tes awal RED karena gate belum tersedia; implementasi membuatnya GREEN.
Matriks diperluas menjadi 43 tes. Review menemukan perbandingan string tanggal
ber-offset dengan Carbon now(): instant yang sama dapat dianggap belum berlaku.
Reproduksi minimal PHP dan tes manual_review_timestamp keduanya membuktikan
masalah; tes baru RED sebelum fix. PostgreSQL awal juga menunjukkan satu error
pada batch dengan alokasi sah. Semua perbandingan PHP untuk reviewed_at dan
settled_at kemudian memakai CarbonImmutable, bukan perbandingan string.
Tes PostgreSQL membekukan waktu ke detik sama agar masalah tidak tersamarkan
oleh durasi eksekusi. Setelah fix:

| Verifikasi | Hasil |
| --- | --- |
| Feature AttemptEntitlementGateTest | 44 tes / 55 assertions lulus |
| Regresi Unit/Feature/Architecture | 626 tes / 2.869 assertions lulus |
| PostgreSQL disposable runtime | 144 tes / 690 assertions lulus |
| Pint seluruh proyek | Lulus |
| PHPStan | Lulus, 0 error |
| git diff --check | Lulus |

Tidak ada skip. Sandbox eksternal dikecualikan, bukan diklaim lulus.

```powershell
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Feature/Auth/AttemptEntitlementGateTest.php
php vendor/bin/phpunit --configuration phpunit.organization-payment.xml tests/Unit tests/Feature tests/Architecture --exclude-group sandbox
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
php vendor/bin/pint --parallel --test
php vendor/bin/phpstan analyse --no-progress
git diff --check
```

### Cakupan dan review

- Ready + pembayaran cocok + consent/identitas lengkap diterima tanpa write.
- Attempt lama yang lunas tidak membuka attempt baru unpaid; entitlement
  legacy tidak menggantikan entitlement per attempt. Foreign principal/scope
  ditolak bahkan pada service context; role non-service tidak bisa memakai gate
  untuk membaca bill. PostgreSQL tetap runtime non-owner FORCE RLS.
- Bill paid tanpa item settled, count/total tidak cocok, pending/expired/
  rejected, future paid/settled/ready, revoked/finalized/VOID, entitlement
  locked/in_progress dan snapshot tidak sah ditolak.
- Gratis membutuhkan settlement nol eksplisit dan tetap memerlukan consent;
  diuji di SQLite dan PostgreSQL, bukan memanggil gateway.
- Consent versi/hash saat ini, penarikan, penolakan, future consent diuji.
  Catatan P8a bahwa penolakan DASS tidak menghambat tes utama adalah perilaku
  historis yang telah disupersede ADR-013 dan kontrak checkout-v2 saat ini:
  consent DASS current wajib untuk aktivasi, dan komposisi paket selalu DASS-21
  ditambah minimal satu tes non-DASS. DASS tetap tidak mengubah scoring atau
  hasil psikotes utama. Tes batch PostgreSQL menunjukkan consent anggota lain
  tidak menghalangi peserta yang sudah memenuhi syarat.
- Profil kurang, peserta soft-deleted, verifikasi pending/mismatch/error,
  manual reject, penerimaan tanpa reviewer, timestamp masa depan, bukti hilang
  atau diganti setelah verifikasi ditolak. Manual accepted sah diuji pada PG.
- Katalog berubah/OFF dan policy pembayar OFF tidak membatalkan paid snapshot;
  test_type yang tidak termasuk snapshot tetap ditolak.

Review menjaga pemisahan identitas autentikasi, scope attempt, settlement,
prasyarat, dan mutasi sesi. Gate read-only bukan bukti atomic start: P8b harus
memuat ulang dalam transaksi lock/transisi, tidak memakai hasil pemeriksaan lama.
Consent tetap record participant+versi existing; bukan schema consent per attempt.
Legal review dokumen consent dan kontrak bukti identitas eksternal tetap gerbang
yang belum ditutup. Tidak ada keputusan kelayakan/skoring/DASS klinis yang diubah.

Tidak ada UI berubah sehingga browser/build/typecheck frontend tidak diulang.
Tidak ada invoice/WA/outbox nyata, data aktif, deployment, n8n, atau Cloudflare
diubah. Container/database test dibersihkan oleh runner. Working tree lama
dipertahankan; tidak mass-stage, commit atau push. Checkpoint P8a diserahkan
untuk tinjauan pengguna sebelum aktivasi individual P8b.

## Checkpoint P10a internal — 2026-09-01

Lookup read-only ce9b3a0 diterima setelah full regression root **988 tes/5569
assertions lulus**, tanpa skip, dengan XML testing SQLite memory dan outbound
fake (sandbox dikecualikan). Pint/PHPStan lulus. Unknown tidak mengizinkan create
ulang; hasil lookup bukan settlement. Tidak ada consumer, invoice atau data nyata.
PG tidak diulang karena query/schema/RLS tidak berubah. Detail batas dan cursor:
[wave-13](../tasks/organization-payment/reports/integration-wave-13.md).
P10b baru proposal, public/E2E belum selesai; tidak ada izin aktivasi atau deploy.

## Checkpoint kontrak dan authority ACL Windows — 2026-09-06

Commit `e47406a` menerima kontrak code-only policy dan codec attestation ACL;
commit `8461e03` mengikat authority policy yang sama ke closure candidate builder,
schema/config binding supervisor, serta preflight descriptor yang gagal tertutup
sebelum identity probe atau proses. Policy canonical exact memakai digest final
`a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb`;
caller dan attestor tidak dapat memilih policy lain. Request/evidence memakai
vocabulary exact, encoding canonical bounded, challenge terpisah, serta binding
policy, lease, path, dan identity. Error tetap fixed dan tidak membawa path,
policy, SID, DACL, atau detail privat.

Bukti root/agent yang diterima: policy+codec **13/13 tes**, candidate builder
**26/26**, supervisor aman **102/102** dengan tes real-listener sengaja
dikecualikan, serta `py_compile`, AST, dan diff-check lulus. Bukti ini hanya
parser, closure, binding, dan urutan preflight tanpa proses; bukan attestation ACL
Windows nyata.

ACL seluruh descendant pada source tree belum dibuktikan. Effective access,
cross-process/crash, reparse/rename, durability Windows, candidate/browser P17c,
serta operasi P18 tetap terbuka. Tidak ada candidate, browser, service, database,
network, provider, deploy, atau aktivasi; seluruh gate dan payment tetap default
OFF dan tidak ada acceptance P17c/P18 yang ditutup.

## Checkpoint admission attestation ACL anchor-only — 2026-09-06

Commit `20cf713` menambah jalur admission anchor-only pada supervisor untuk
evidence ACL. Codec, lease, dan attestor dipin exact; load memakai sentinel
`None` satu kali sehingga evidence tidak dapat dipakai ulang. Jalur discard dan
seluruh `BaseException` tetap gagal tertutup serta mempertahankan cleanup.

Bukti root lulus supervisor aman **116/116 tes**, `py_compile`, dan diff-check;
cross-review menyatakan **PASS**. Real-listener tidak dijalankan. Checkpoint ini
belum menyediakan publisher, execution/enforcement attestor, atau wiring
coordinator, dan belum membuktikan descendant source-tree ACL maupun perilaku
Windows nyata saat runtime, crash, reparse, atau rename. Browser P17c dan operasi
P18 tetap terbuka; tidak ada aktivasi dan seluruh gate/payment tetap default OFF.

## Checkpoint admission execution attestation ACL — 2026-09-06

Commit `221ae47` mengikat publisher ke transisi exact `anchor_consumed` lalu
memerlukan challenge kedua dan source pair baru untuk admission execution.
Codec, lease, dan attestor tetap dipin exact; validasi memakai session, config
binding, dan lease dari current context. Token opaque hanya aktif selama
admission, menjadi exhausted setelah dipakai, dan dibersihkan pada seluruh jalur
keluar.

Bukti root lulus supervisor aman **127/127 tes**, `py_compile`, diff-check, dan
review **PASS**; real-listener tidak dijalankan. Wiring operasional
`supervise`/`recover`, preflight, claim, `_command`, launch, coordinator, real
attestor, serta enforcement/runtime Windows tetap terbuka. Browser P17c dan
operasi P18 belum diterima; tidak ada aktivasi dan seluruh gate/payment tetap
default OFF.

## Checkpoint enforcement admission ACL code-only — 2026-09-06

Commit `15a5509` mengaktifkan gate pada wrapper dan boundary direct, dengan
revalidasi admission tepat sebelum setiap `Popen`. Recovery mengikat session dan
anchor exact ke hasil load one-shot dan memeriksanya kembali sebelum ownership
I/O. Coordinator kini merangkai lease, dua boundary attestation, publisher,
gated recovery load, serta error mapping fixed tanpa menutupi `BaseException`.

Bukti root lulus supervisor aman **133/133 tes** dengan real-listener
dikecualikan, coordinator+lease **38/38**, AST **4 file**, `py_compile`,
diff-check, dan review adversarial **PASS**. Real Windows attestor, recursive
descendant source-tree ACL/effective access, cross-process/crash,
reparse/rename/durability, dan browser runtime tetap terbuka. P17c/P18 belum
diterima; tidak ada runtime/deploy dan seluruh gate/payment tetap default OFF.

## Checkpoint kontrak pure ACL source-tree summary — 2026-09-06

Commit `6256dc8` menetapkan summary pure untuk manifest nonempty beserta tepat
seluruh file dan implied directory. Path ASCII Windows-safe serta batas jumlah,
kedalaman, panjang, dan canonical bytes diperiksa; identity record wajib unik,
sedangkan owner SID dan digest DACL memakai bentuk exact. Manifest+records
diurutkan canonical dan diikat oleh digest domain-separated dengan known vector;
boundary anchor/execution harus memiliki summary exact yang sama.

Bukti accepted: **8/8 tes**, `py_compile`, diff-check, dan adversarial review
**PASS**. Kontrak ini belum terhubung ke codec, policy, attestor, atau candidate
builder. Kelengkapan native tree, empty directory yang tidak diimplikasikan
manifest, identity root yang tidak masuk summary, enforcement ACL/effective
access, dan Windows runtime tetap belum terbukti. Tidak ada runtime, browser,
service, database, network, deploy, atau aktivasi. P17c/P18 tetap terbuka dan
seluruh gate/payment tetap default OFF.

## Checkpoint lazy Win32 ACL ABI boundary — 2026-09-06

Commit `2b715e1` menetapkan boundary `ctypes` yang lazy: import tidak menyediakan
class attestor dan tidak memanggil native API; non-Windows menolak sebelum
`WinDLL`. Tabel **28 signature exact** diverifikasi dengan oracle independen,
nama DLL dan `use_last_error` dipin, width/layout struktur diperiksa, dan resolver
sempit memvalidasi ulang identity, signature, serta bundle sehingga mutasi gagal
tertutup. Error tetap fixed/redacted sementara `KeyboardInterrupt` dan
`SystemExit` dipertahankan.

Bukti accepted: **7/7 tes**, `py_compile`, diff-check, dan cross-review **PASS**.
Ini hanya boundary ABI, bukan real attestor, scanner, cache, atau composition
wiring, serta bukan bukti native efficacy/ACL Windows. Tidak ada native call,
runtime, browser, service, database, network, deploy, atau aktivasi. P17c/P18
tetap terbuka dan seluruh gate/payment tetap default OFF.

## Checkpoint private Windows ACL directory handle — 2026-09-06

Commit `038cbdd` menambah primitive private untuk membuka directory handle dengan
akses/share/flag exact, menonaktifkan inheritance handle, serta menolak
non-directory dan reparse point. Final path canonical dan identity harus stabil
pada dua pembacaan. `FILE_ID_128` tetap byte sequence opaque 16-byte dan hanya
diserialisasi menjadi desimal canonical melalui interpretasi big-endian. Cleanup
menutup handle tepat sekali tanpa menutupi exception atau
`KeyboardInterrupt`/`SystemExit` utama.

Bukti root accepted: **16/16 tes**, `py_compile`, diff-check, dan final
cross-review **PASS**. Semua pengujian memakai fake ABI; tidak ada native call
atau filesystem Windows nyata. Belum ada usable attestor/scanner/cache,
composition atau candidate closure, wiring codec/policy/source-tree, pemeriksaan
owner/DACL/token/`AccessCheck`, recursive completeness, empty-dir/root binding,
native ACL efficacy, race/reparse/rename/TOCTOU, crash, maupun browser runtime.
Tidak ada runtime, service, database, network, deploy, atau aktivasi.
P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment tetap default OFF.

## Checkpoint private Windows ACL single-ACE semantics — 2026-09-06

Commit `97d7b33` mengambil ACE melalui live `GetAce`, menyalin bytes, lalu
mem-parsing copy secara strict. Hanya satu allow ACE type 0 dengan flags exact 3
yang diterima. Pointer/alignment/full span harus contained dan ACE wajib
mengonsumsi seluruh `AclBytesInUse`. SID diperiksa revision, count, dan full-span
sebelum `IsValidSid`; authority memakai big-endian dan subauthority little-endian
canonical. Trustee harus exact owner dan dua snapshot semantic immutable harus
identik. ABI tetap **29 signature** karena `GetAce` bukan API baru.

Bukti root accepted: **27/27 tes**, `py_compile`, diff-check, dan adversarial
review **PASS**. Semua bukti masih pure fake ABI. Mask role/policy,
process-token owner/privilege, effective `AccessCheck`, source-tree, cache,
composition/usable attestor, native runtime, serta race belum tersedia atau
terbukti. Tidak ada browser, service, database, network, deploy, atau aktivasi.
P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment tetap default OFF.

## Checkpoint private Windows ACL descriptor snapshot — 2026-09-06

Commit `49d6b87` memperluas ABI menjadi **29 signature** dengan `IsValidAcl` dan
menambah dua snapshot descriptor melalui live directory handle yang sama.
Security descriptor wajib self-relative/revision 1 dan bounded; pointer owner,
group, serta DACL harus interior terhadap allocation. DACL wajib present,
non-NULL, protected, tidak auto-inherited, dan revision 2. Seluruh rentang
`AclSize` harus contained sebelum traversal native; digest memakai exact
`AclSizeInformation.AclBytesInUse`. Hanya pointer base dialokasikan yang di-
`LocalFree` tepat sekali.

Bukti root accepted: **24/24 tes**, `py_compile`, diff-check, dan final
adversarial review **PASS**. Ini tetap pure fake ABI, bukan native Windows.
Konversi SID/process-token owner matching, parsing ACE exact, policy/effective
`AccessCheck`, source-tree/composition/cache/usable-attestor wiring, runtime, dan
race protection belum tersedia. Tidak ada browser, service, database, network,
deploy, atau aktivasi. P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment
tetap default OFF.

## Checkpoint private Windows ACL process-token owner binding — 2026-09-06

Commit `9301369` mengikat owner descriptor ke current process token dengan urutan
token-before → descriptor-1 → descriptor-2 → token-after. `GetCurrentProcess`
menghasilkan pseudo-handle yang tidak ditutup; real `OpenProcessToken` handle
memakai `TOKEN_QUERY`, non-inheritable, dan ditutup exact sekali. Probe bounded
`TokenUser` harus menghasilkan error 122 sebelum fill exact. SID wajib tidak
overlap header dan full-contained sebelum native validation; token harus stabil,
kedua owner wajib exact match, dan `processTokenSid` immutable.

Bukti root accepted: **30/30 tes**, `py_compile`, diff-check, dan adversarial
review **PASS**. Pengujian tetap pure fake ABI. Groups, privileges, LocalSystem,
role mask, impersonation, effective `AccessCheck`, source-tree, cache,
composition/usable attestor, native runtime, serta race belum dibuktikan. Tidak
ada browser, service, database, network, deploy, atau aktivasi. P15/P16/P17c/P18
tetap terbuka dan seluruh gate/payment tetap default OFF.

## Checkpoint private Windows ACL current-process TokenGroups — 2026-09-06

Commit `301a823` membaca `TokenGroups` memakai probe/fill exact dengan batas
**256 KiB/4096 group**. Layout `ANYSIZE_ARRAY`, tabel, dan full span diperiksa;
setiap SID wajib non-overlap terhadap header/tabel dan sesama SID sebelum urutan
`IsValidSid` → `GetLengthSid`. Seluruh group+attributes disimpan berurutan tanpa
filtering; duplikat ditolak, bukan dideduplikasi. Profil current process immutable sebelum/sesudah descriptor
snapshots harus identik pada token handle yang sama, dengan cleanup exact.

Bukti root accepted: **35/35 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Ini tetap pure fake ABI. Ordinary second
principal/provenance, privileges, LocalSystem exclusion untuk ordinary,
impersonation, role/policy mask, effective `AccessCheck`, source-tree, cache,
composition/usable attestor, native Windows/runtime/races belum dibuktikan. Tidak
ada browser, service, database, network, deploy, atau aktivasi. P15/P16/P17c/P18
tetap terbuka dan seluruh gate/payment tetap default OFF.

## Checkpoint private Windows ACL current-process TokenPrivileges — 2026-09-06

Commit `ea9fe9c` menambah probe/fill exact `TokenPrivileges` dengan batas **256
KiB/4096 privilege**. Inline `ANYSIZE_ARRAY` harus mengonsumsi buffer penuh tanpa
trailing byte. Semua entry disimpan berurutan dan immutable sebagai
`(LowPart uint32, HighPart int32, Attributes uint32)`; duplicate LUID ditolak.
Profil sebelum/sesudah descriptor snapshots wajib stabil melalui token handle
yang sama dan cleanup tetap exact.

Bukti root accepted: **39/39 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Bukti hanya pure fake current-process
observation. `LookupPrivilegeValue`/name mapping, enabled-dangerous-privilege
policy, ordinary principal/provenance, LocalSystem, token type/restriction/
impersonation, effective `AccessCheck`, source-tree/cache/composition/attestor,
dan native Windows/runtime tetap terbuka. Tidak ada browser, service, database,
network, aktivasi, atau deploy. P15/P16/P17c/P18 tetap terbuka dan seluruh
gate/payment tetap default OFF.

## Checkpoint observasi Windows sensitive privileges — 2026-09-06

Commit `4f015f3` mengobservasi urutan fixed `SeBackupPrivilege`,
`SeRestorePrivilege`, dan `SeTakeOwnershipPrivilege` pada current process.
`LookupPrivilegeValueW` memakai system `None`; normalisasi LUID signed/unsigned
wajib exact dan duplicate mapping ditolak. Snapshot immutable menyimpan
name/LUID/present/enabled, dengan enabled hanya dari `Attributes & 0x2`, serta
membedakan absent, disabled, dan enabled. Profile sebelum/sesudah harus stabil
dan cleanup token handle tetap exact.

Bukti root accepted: **43/43 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Ini pure fake current-process
observation, bukan rejection policy atau ordinary-principal evidence. Second
ordinary-token provenance, LocalSystem, token type/restriction/impersonation,
effective `AccessCheck`, source-tree/cache/composition/attestor, dan native
Windows/runtime tetap terbuka. Tidak ada browser, service, database, network,
aktivasi, atau deploy. P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment
tetap default OFF.

## Checkpoint fixed Windows token profile — 2026-09-06

Commit `95450a6` mengobservasi `TokenType` sebagai `TOKEN_TYPE` yang wajib exact
`TokenPrimary`, serta `TokenIsAppContainer` sebagai nilai `DWORD` raw dengan
klasifikasi nonzero. Profil current process sebelum/sesudah descriptor snapshots
wajib stabil pada token handle yang sama dan cleanup tetap exact.

Bukti root accepted: **47/47 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Bukti hanya pure fake current-process
observation, bukan ordinary-principal evidence, rejection policy, atau effective
`AccessCheck`. Ordinary second-token provenance, LocalSystem, token restriction/
impersonation, source-tree/cache/composition/usable attestor, dan native Windows/
runtime tetap terbuka. Tidak ada browser, service, database, network, aktivasi,
atau deploy. P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment tetap
default OFF.

## Checkpoint Windows restricting SID profile — 2026-09-06

Commit `69a25c2` menetapkan `TokenRestrictedSids` sebagai authority untuk
snapshot restricting SID current process. Encoding kosong hanya sah sebagai
count nol dengan panjang exact **4 byte** dan bentuk kosong lain gagal tertutup.
Parser nonempty dibatasi **256 KiB/4096 SID**, mewajibkan attributes nol, serta
mempertahankan duplicate SID pada span berbeda tanpa deduplikasi.

Bukti root accepted: **51/51 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Bukti hanya pure fake current-process
observation; bukan `IsTokenRestricted`, general-unrestricted evidence, ordinary
principal/policy, atau effective `AccessCheck`. Ordinary second-token provenance,
LocalSystem, token restriction/impersonation lainnya, source-tree/cache/
composition/usable attestor, dan native Windows/runtime tetap terbuka. Tidak ada
browser, service, database, network, aktivasi, atau deploy. P15/P16/P17c/P18
tetap terbuka dan seluruh gate/payment tetap default OFF.

## Checkpoint corroboration Windows restricting SID — 2026-09-06

Commit `a5e3c98` memakai `IsTokenRestricted` hanya sebagai corroboration atas
authority `TokenRestrictedSids` (class 11). Hasil `FALSE` hanya sah setelah
`SetLastError(0)` dan immediate `GetLastError()==0`; hasil nonzero berarti
`true`. Nilai corroboration wajib parity dengan snapshot class 11.

Bukti root accepted: **53/53 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Bukti tetap pure fake current-process
observation; bukan general-unrestricted evidence, ordinary principal/policy,
atau effective `AccessCheck`. Ordinary second-token provenance, LocalSystem,
token restriction/impersonation lainnya, source-tree/cache/composition/usable
attestor, dan native Windows/runtime tetap terbuka. Tidak ada browser, service,
database, network, aktivasi, atau deploy. P15/P16/P17c/P18 tetap terbuka dan
seluruh gate/payment tetap default OFF.

## Checkpoint penolakan Windows LocalSystem identity — 2026-09-06

Commit `bbcd5b7` menolak current-process `TokenUser` LocalSystem exact
`S-1-5-18` sebelum descriptor snapshots. Pemeriksaan hanya terhadap identity;
group SID dan restricting SID bukan authority untuk identitas `TokenUser`.

Bukti root accepted: **55/55 unittest**, `py_compile`, diff-check, dan final
adversarial review **PASS** tanpa P1/P2. Bukti tetap pure fake dan hanya
mengecualikan LocalSystem sebagai current-process identity; bukan bukti ordinary
second principal/provenance, policy, atau effective `AccessCheck`. Token
restriction/impersonation lainnya, source-tree/cache/composition/usable
attestor, dan native Windows/runtime tetap terbuka. Tidak ada browser, service,
database, network, aktivasi, atau deploy. P15/P16/P17c/P18 tetap terbuka dan
seluruh gate/payment tetap default OFF.

## Checkpoint kontrak ordinary access provider — 2026-09-06

[ADR-018](decisions/0018-windows-ordinary-access-provider.md) menerima kontrak
internal/pure-fake bagi provider independent externally provisioned ordinary
token dan exact native `AccessCheck`. Provider memiliki token, seluruh raw
handle, exact primary-to-impersonation derivation, dan evidence one-shot;
caller tidak dapat memasukkan credentials, username/account/environment choice,
raw handle/SID pointer, atau process handle.

Request/evidence baru pada boundary anchor/execution dalam phase fresh/recovery
mengikat lifecycle serta exact outer request/evidence ADR-017 melalui
`aclRequestDigest`/`aclEvidenceDigest`, policy digest, fresh challenge, current
token identity, ordered descriptor evidence digest, target identity,
`MAXIMUM_ALLOWED`, provenance/profile token, serta exact function-success/
access-denied/granted-zero.
Path dan pasangan `(volumeSerial,fileId)` untuk coordinator/run/source wajib
pairwise distinct; satu object tidak boleh memenuhi beberapa role. ADR-018 codec
menolak segera, sedangkan enforcement outer codec existing masih remediation
terbuka.
Provider membuka target sendiri; kedua descriptor capture diparse independen
terhadap policy ADR-017 dan hanya provider yang menghasilkan descriptor
`policySatisfied`; ordinary denial tetap admission gate terpisah. Mismatch
path/identity/descriptor yang teramati ditolak.
Invariant ordinary menolak same user, LocalSystem, current owner SID dalam user/
groups tanpa memandang attributes, fixed sensitive privilege enabled,
AppContainer raw/nonzero, class-11 nonempty atau `IsTokenRestricted` true,
profile drift, dan target yang tidak exact-denied. `TokenOrigin` dan distinct
`AuthenticationId` hanya observational/corroboration.

Belum ada acquisition authority, provider/cache/`attest`, provisioning,
integration, browser, service, database, network, activation, atau deploy. Hanya
pure codec preparation yang boleh menurunkan binding dari exact outer bytes;
kontrak ini bukan bukti Windows runtime atau effective `AccessCheck`.
P15/P16/P17c/P18 tetap terbuka dan seluruh gate/payment tetap default OFF;
tidak ada checklist/progress yang diubah.

## Checkpoint pure ADR-018 request codec — 2026-09-06

Commit `f011551` menerima pure request codec yang memvalidasi exact canonical
outer ADR-017 request/evidence bytes plus private structural current-token
identity fixture, lalu menurunkan request ADR-018 sendiri. Tiga digest exact
(`aclRequestDigest`, `aclEvidenceDigest`, dan domain-separated
`aclDescriptorEvidenceDigest`) serta pairwise-distinct path dan
`(volumeSerial,fileId)` untuk coordinator/run/source telah ditegakkan.

Bukti root: **11/11 tests**, `py_compile`, dan independent review **PASS** tanpa
P1/P2. Bukti ini tidak mencakup provider, cache, attest, native implementation,
composition, provenance, atau runtime; provisioning authority gate tetap
terbuka. Tidak ada runtime/config/env/deploy/activation. P15/P16/P17c/P18,
active gates, payment, checklist, dan progress tidak berubah; semuanya yang
belum diterima tetap terbuka/default OFF.

## Checkpoint outer target anti-alias — 2026-09-06

Commit `a72727e` membuat outer ADR-017 codec menolak pairwise path aliases
berdasarkan accepted casefold rule dan duplicate evidence identity
`(volumeSerial,fileId)` untuk coordinator/run/source. Bukti root accepted:
**24/24 tests**, `py_compile`, dan independent review **PASS** tanpa P1/P2.

Full 299-test discovery sempat dicoba ketika concurrent edits berlangsung dan
bukan acceptance evidence. Satu transient ordinary fixture error telah
diperbaiki; satu unrelated supervisor real-listener failure berasal dari
environment. Dokumen ini tidak mengklaim full suite green.

Tidak ada provider/native/runtime, config/env/deploy/activation, atau perubahan
provisioning authority. P15/P16/P17c/P18, checklist/progress, active gates, dan
payment tetap terbuka/tidak berubah serta default OFF.

## Checkpoint pure ACL source-tree summary v2 — 2026-09-06

Commit `5edb335` mewajibkan exact `sourceRootIdentity` pada pure source-tree
summary v2 dan memasukkannya ke canonical digest. Descendant tidak boleh memakai
ulang identity root; deterministic order dipertahankan dan boundary drift
ditolak.

Bukti root accepted: **11/11 tests**, `py_compile`, dan independent review
**PASS** tanpa P1/P2. Builder suite yang timeout setelah lima dot bukan
acceptance evidence dan full suite tidak diklaim green. Consumer integration,
native traversal, live identity/provenance, serta runtime evidence tetap belum
ada. Tidak ada config/env/deploy/activation maupun perubahan P15/P16/P17c/P18,
checklist/progress, active gates, atau payment; semuanya tetap terbuka/default
OFF.

## Checkpoint pure ACL role-mask policy — 2026-09-06

Commit `a62f8bd` menerima pure role-mask policy primitive atas
process-token-bound snapshot yang telah direvalidasi penuh. Exact mask
coordinator/run `2032127` dan source `1179817` diturunkan serta parity-checked
terhadap canonical policy artifact digest
`a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb`.
Output hanya immutable private match tanpa `policySatisfied`, evidence, atau
handle.

Bukti root: **60/60 tests**, `py_compile`, dan independent review **PASS**
tanpa P1/P2 setelah forged-snapshot P1 dan artifact-parity P2 diperbaiki. Role
masih internal/test input; future composition wajib mengikat canonical target
order/path/identity serta double descriptor/token stability. Belum ada usable
attestor, native/runtime implementation, atau provider. Tidak ada perubahan
P15/P16/P17c/P18, checklist/progress, active gates, maupun payment; semuanya
tetap terbuka/default OFF tanpa config/env/deploy/activation.

## Checkpoint pure ordered ACL target-policy bundle — 2026-09-06

Commit `ab442d0` menerima exact tuple tiga snapshot dalam internal pinned order
coordinator/run/source. Setiap snapshot wajib lolos full role-policy validation,
semua field `processToken*` wajib exact-equal, dan authority tuples dipin.
Output dibatasi pada deeply immutable limited result.

Bukti root: **65/65 tests**, `py_compile`, dan independent review **PASS** tanpa
P1/P2 setelah P2 rebind hardening oleh root. Bukti belum mencakup
path/filesystem identity binding, capture/double stability,
`policySatisfied`/evidence, source-tree completeness, usable attestor,
native/runtime implementation, atau provider. Tidak ada perubahan
P15/P16/P17c/P18, checklist/progress, active gates, maupun payment; semuanya
tetap terbuka/default OFF tanpa config/env/deploy/activation.

## Checkpoint candidate manifest closure ACL — 2026-09-06

Commit `3460588` menjadikan enam path berikut exact required sekaligus allowed
dalam candidate manifest closure:

- `tools/testing/tests/Browser/checkout-acl-source-tree.py`
- `tools/testing/tests/Browser/test_checkout_acl_source_tree.py`
- `tools/testing/tests/Browser/checkout-windows-acl-attestor.py`
- `tools/testing/tests/Browser/test_checkout_windows_acl_attestor.py`
- `tools/testing/tests/Browser/checkout-ordinary-access-request.py`
- `tools/testing/tests/Browser/test_checkout_ordinary_access_request.py`

Bukti root: focused **1/1 test**, `py_compile`, dan independent review **PASS**
tanpa P1/P2. Bukti hanya mencakup packaging integrity; candidate tidak dibangun
dan full builder suite tidak diklaim. Ini bukan bukti runtime, provider, atau
native Windows. Tidak ada perubahan P15/P16/P17c/P18, checklist/progress,
active gates, payment, config/env/deploy, maupun activation; semuanya tetap
terbuka/default OFF.

## Checkpoint pure ACL source binding — 2026-09-06

Commit `4bfd005` memvalidasi exact canonical outer pair anchor/execution melalui
accepted codec dan `validate_boundary_pair`. Source-root identity diturunkan
hanya dari evidence `target[2]`; source-tree v2 summaries dihitung ulang dari
manifest/records dan wajib exact-equal. Sibling authority dipin, dengan hasil
sempit yang immutable.

Bukti root: **31/31 tests**, `py_compile`, dan independent review **PASS** tanpa
P1/P2 setelah tuple/frozenset identity P2 diperbaiki. Ini hanya membuktikan
konsistensi supplied data, bukan authority `policySatisfied`, live traversal/
completeness, handle/path provenance, DACL efficacy, double capture, provider,
native/runtime, atau usable attestor. P15/P16/P17c/P18, checklist/progress,
active gates, payment, config/env/deploy, dan activation tetap tidak berubah/
default OFF.

## Checkpoint candidate packaging ACL source binding — 2026-09-06

Commit `1f8b22f` menjadikan
`tools/testing/tests/Browser/checkout-acl-source-binding.py` dan
`tools/testing/tests/Browser/test_checkout_acl_source_binding.py` exact required
sekaligus allowed dalam candidate manifest closure. Bukti root: focused **1/1
test**, `py_compile`, dan independent review **PASS** tanpa P1/P2. Ini hanya
packaging integrity; tidak ada candidate build atau bukti runtime. Tidak ada
perubahan P15/P16/P17c/P18, checklist/progress, gates/payment,
config/env/deploy, maupun activation; semuanya tetap terbuka/default OFF.
