# Verifikasi checkout organisasi

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
  DASS yang ditolak tidak menghambat tes utama. Tes batch PostgreSQL menunjukkan
  consent anggota lain tidak menghalangi peserta yang sudah memenuhi syarat.
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
