# Proposal P11b0: routing event pembayaran bill dan legacy

## Status dan batas

**Proposed untuk review.** Dokumen ini mengaudit kontrak P11b setelah P11a
diterima pada root `c027140`. Tidak ada dispatcher, reconciliation action,
route, command, scheduler, provider call, credential, atau source produksi yang
ditambah pada increment ini.

Rujukan utama: SPEC organization billing, todo/plan/parallel-work terbaru,
ADR-002, ADR-006 sampai ADR-009, `PaymentWebhookProcessor`,
`XenditWebhookController`, `XenditProvider`,
`ReconcilePendingXenditPayments`, jalur order legacy, dan
`FinalizeAssessmentBill` P11a.

## Temuan audit existing

1. `XenditWebhookController` menyerahkan header dan JSON mentah ke
   `PaymentProvider::normalizeWebhook`. `XenditProvider` menolak konfigurasi
   token kosong, header hilang/salah, dan memakai `hash_equals` sebelum parsing
   payload. Payload atau status raw yang tidak sah ditolak generik. Boundary ini
   sudah fail-closed dan tidak perlu dipindahkan ke dispatcher.
2. `XenditProvider` menormalisasi `PENDING`, `PAID`/`SETTLED`, dan `EXPIRED` ke
   `PaymentEvent`. `PAID` dan `SETTLED` memakai event ID logis yang sama.
   `PaymentStatus::Cancelled` ada dalam domain tetapi belum berasal dari status
   raw Xendit; P11b tidak boleh mengarang mapping raw Xendit baru tanpa kontrak
   provider terpisah.
3. `PaymentWebhookProcessor` mengklaim `(provider,event_id)` melalui unique
   constraint, mengunci row claim, lalu menerapkan transisi order dalam transaksi
   service yang sama. Failure programming/DB yang tidak diklasifikasikan rollback
   claim dan dapat diretry; conflict/rejection bisnis disimpan durable.
4. Intent hash existing mencakup provider reference, status, amount, dan currency,
   tetapi tidak merchant reference. Row persisted sudah memiliki
   `merchant_reference`. Mengubah formula hash langsung akan membuat replay row
   historis terlihat konflik setelah rolling update.
5. `ReconcilePendingXenditPayments` memilih order legacy pending, menutup service
   transaction sebelum GET, lalu mengirim hasil `checkStatus` ke processor yang
   sama seperti webhook. Command legacy sudah terdaftar; P11b tidak boleh mengubah
   selection/count/log/error contract legacy atau mendaftarkan command assessment
   baru pada increment routing.
6. `FinalizeAssessmentBill` menerima `PaymentEvent`, mendukung transaksi service
   induk, dan mengunci organization lebih dulu. Panggilan dari processor dapat
   membuat claim event, settlement seluruh allocation, audit, activation, dan
   outbox commit/rollback sebagai satu unit. Ia mengembalikan `settled`,
   `replayed`, atau `ignored` dan melempar kode DomainException fail-closed.

## Boundary dispatcher yang direkomendasikan

Tambahkan internal `PaymentEventDispatcher` di antara
`PaymentWebhookProcessor` dan handler domain. Processor tetap memiliki claim
idempotensi dan transaksi service; controller serta adapter autentikasi tidak
berubah.

```text
raw webhook
  -> XenditWebhookController
  -> XenditProvider.normalizeWebhook  [auth + untrusted payload validation]
  -> PaymentWebhookProcessor          [durable event claim]
  -> PaymentEventDispatcher           [namespace only]
       merchantReference starts "AB_" -> FinalizeAssessmentBill
       otherwise                      -> OrderPaymentEventHandler

trusted status GET
  -> PaymentProvider.checkStatus      [response normalization/validation]
  -> PaymentWebhookProcessor
  -> dispatcher yang sama
```

Keputusan routing hanya memakai `str_starts_with($merchantReference, 'AB_')`.
Prefix adalah namespace, bukan bukti bahwa bill ada atau sah. Semua string dengan
prefix tersebut, termasuk `AB_foo`, wajib masuk jalur bill lalu ditolak oleh regex
dan authoritative reload finalizer bila tidak valid. Dispatcher tidak boleh
mencoba order legacy setelah exception, bill tidak ditemukan, scope korup, atau
money mismatch. Referensi tanpa prefix mempertahankan handler dan state machine
legacy persis seperti sekarang.

Pemilihan berdasarkan existence database ditolak: pola “cari bill, jika tidak
ada cari order” membuat unknown/spoofed `AB_` menjadi downgrade/fallback. Pemilihan
berdasarkan provider reference juga ditolak karena reference gateway bukan
namespace domain dan tidak unik lintas tabel melalui satu constraint bersama.

Untuk bill, dispatcher wajib menerima provider tepat `xendit`. Jalur manual P11c
memanggil finalizer melalui authority SuperAdmin tersendiri dan tidak menyamar
sebagai webhook. Provider selain Xendit dengan `AB_` ditolak sebelum finalizer;
restriction ini tidak diterapkan pada referensi legacy agar compatibility provider
legacy tidak berubah.

### Hasil internal dan exception

Dispatcher cukup mengembalikan keputusan typed internal `applied|ignored`:

- order legacy: `OrderTransition::changed=true` menjadi `applied`, selain itu
  `ignored`;
- bill `settled` menjadi `applied`;
- bill `replayed` atau `ignored` menjadi `ignored`;
- string keputusan lain adalah programming error dan harus propagate/rollback.

Kode finalizer harus dipetakan dengan allowlist, bukan catch seluruh
`DomainException`:

| Kode finalizer | Outcome processor | HTTP existing |
| --- | --- | --- |
| reference invalid/mismatch atau provider AB_ bukan Xendit | rejected `reference_mismatch` | 422 generik |
| amount/currency mismatch | rejected `money_mismatch` | 422 generik |
| state/time/scope/snapshot/total/allocation/replay invalid | rejected `bill_invalid` | 422 generik |
| exception DB/programming yang tidak dikenal | propagate; claim rollback | 500 generik framework |

`invalid_transition` legacy tetap mempertahankan acknowledgement HTTP 200 yang
ada untuk terminal event terlambat. Jangan memakai reason ini untuk bill korup,
karena controller existing sengaja hanya mengembalikan 200 pada reason legacy
tersebut.

## Idempotensi dan rolling compatibility

Formula `intent_hash` existing tidak diubah pada P11b. Pada duplicate row,
processor perlu membandingkan `merchant_reference` persisted secara exact selain
hash existing sebelum mengembalikan Duplicate. Dengan demikian spoofed event ID
yang sama dan merchant reference berbeda menjadi Conflict tanpa memutus replay
row historis yang memakai formula hash lama.

`occurred_at` tidak ditambahkan ke intent hash: status PENDING yang sama dapat
memiliki timestamp update berbeda dan event ID logical tetap sama. Untuk paid
bill, timestamp pertama yang berhasil settle tetap tersimpan di audit P11a;
event logical yang sama berikutnya berhenti pada unique claim. PAID dan SETTLED
dari Xendit sengaja berkonvergensi ke event ID paid yang sama.

Race webhook dan status GET untuk invoice yang sama mempunyai dua fence:

1. unique `(provider,event_id)` membuat satu event claim yang dapat menerapkan
   domain effect; loser menjadi Duplicate;
2. bill lock dan audit canonical P11a menolak settlement/audit/outbox kedua bila
   event delivery berbeda tetap mencapai finalizer.

Claim event dan finalizer harus tetap berada dalam transaksi service yang sama.
Crash item kelima, audit, activation, atau outbox wajib rollback juga menghapus
claim `payment_webhook_events`; retry event kemudian dapat mencoba transaksi
lengkap lagi.

## Mapping status bill yang diusulkan

P11a sudah menangani paid dan menjamin terminal terlambat tidak menurunkan paid.
P11b implementation perlu memperluas state handling **di finalizer yang sama**,
bukan membuat writer status bill kedua:

| Bill authoritative | Event normalized | Keputusan/mutasi |
| --- | --- | --- |
| pending | pending | ignored; tidak ada business write |
| pending | paid | `paid`, seluruh item settled, audit payment, activation P8b |
| pending | expired | `expired`, paid/item settlement tetap NULL, satu audit status |
| pending | cancelled | `rejected`, paid/item settlement tetap NULL, satu audit status |
| paid canonical | paid exact | replayed/no-op |
| paid canonical | pending/expired/cancelled | ignored/no-op; tidak downgrade |
| expired | expired replay | ignored/no-op |
| rejected | cancelled replay | ignored/no-op |
| expired/rejected | paid atau terminal lain | reject `bill_invalid`; tidak revive/overwrite |
| reserved/issuing/unknown/corrupt | status apa pun | reject `bill_invalid` |

Mapping `cancelled -> rejected` memakai enum schema existing; jangan menulis raw
payload atau pesan provider ke `rejection_reason`. Audit status menyimpan hash
event/provider dan status normalized tanpa PII. Tidak ada release charge,
reinvoice, create, settlement, entitlement, atau activation pada expired/rejected.

Perubahan terminal ini membutuhkan review eksplisit bersama dispatcher karena
`FinalizeAssessmentBill` P11a saat ini mengembalikan ignored untuk non-paid saat
pending. Membiarkannya demikian aman terhadap akses dini tetapi membuat invoice
expired tetap tampil pending dan tidak memenuhi lifecycle portal yang sudah
direncanakan. Writer terminal terpisah ditolak karena akan menyalin scope, lock,
late-paid, dan replay predicates finalizer.

## Reconciliation status assessment

Tambahkan action internal terpisah, belum command/scheduler, untuk memilih bill
assessment `pending` yang memiliki `gateway_ref` dan payment method persisted
Xendit. Selection dilakukan bounded/deterministic dalam service transaction
singkat; context dan transaction harus selesai sebelum provider GET. Setiap hasil
`checkStatus` dikirim ke `PaymentWebhookProcessor`, sehingga tidak ada settlement
path kedua.

Jangan memasukkan reserved/issuing/unknown/paid/expired/rejected atau bill manual.
Issuing/unknown tetap milik P10c lookup invoice, bukan status payment. Satu GET
gagal dihitung generik dan tidak menghentikan kandidat berikutnya. Action tidak
melakukan POST/create/expire dan tidak didaftarkan ke command/scheduler sampai
review wiring operasional berikutnya.

Reconciler legacy tidak diperluas menjadi union order+bill karena itu mengubah
arti limit, ordering, counters, logging, dan command existing. Koordinator
operasional kelak boleh memanggil dua reconciler secara eksplisit setelah review.

## Authentication dan trust boundary

- Route/controller Xendit existing tetap satu-satunya raw webhook entrypoint.
  Header callback diverifikasi sebelum normalisasi dan sebelum claim database.
- Dispatcher tidak menerima Request, header, raw payload, atau token. Ia hanya
  menerima provider code trusted dan `PaymentEvent` typed.
- Caller kedua yang sah hanya status checker yang memperoleh event dari
  `PaymentProvider::checkStatus` pada base URL HTTPS allowlisted dengan Basic Auth,
  timeout, no redirect, dan response validation existing.
- `PaymentWebhookProcessor::process` tidak boleh dipanggil dari route/browser baru.
  Method internal tidak menjadikan DTO bukti autentikasi; call graph dan DI
  boundary wajib diuji.
- Error response tetap generik. Token, raw payload, invoice URL, participant,
  allocation, SQL, dan credential tidak masuk log/error baru.

## Risiko dan compatibility concern

| Risiko | Guard/keputusan |
| --- | --- |
| Unknown AB_ jatuh ke order legacy | Prefix routing satu arah; tidak ada catch-and-fallback |
| Malformed AB_ dianggap non-bill karena regex gagal | Routing pakai prefix dahulu; regex hanya validasi finalizer |
| Merchant reference hilang dari intent hash | Bandingkan kolom persisted pada duplicate; jangan ubah hash historis |
| P11b menangkap semua DomainException | Allowlist kode finalizer; unexpected rollback/500 |
| PENDING timestamp berbeda memicu conflict | `occurred_at` tetap di luar logical intent hash |
| Terminal assessment memakai state machine order | Mapping berada di finalizer bill; handler legacy tidak diubah |
| Cancelled raw Xendit belum ada | Jangan tambah mapping adapter tanpa kontrak provider; uji DTO synthetic saja |
| Status checker menahan RLS transaction saat GET | Snapshot refs bounded lalu commit/context clear sebelum network |
| Reconciler assessment mengubah command legacy | Action terpisah, belum diregistrasi |
| Policy/channel OFF sesudah invoice | Finalizer memakai bill/snapshot existing; tidak membatalkan pembayaran sah |
| Terminal memicu reinvoice/release | Tidak ada release/rearm; unique bill item tetap mengunci charge |
| Race webhook/status | Unique event claim + bill lock/audit P11a |
| payment_webhook_events menjadi audit settlement kedua | Row adalah delivery/idempotency record; audit bill P11a tetap business audit |

## Matriks TDD untuk increment implementasi berikutnya

### Auth dan payload

- token callback hilang/salah/config kosong: 401, nol event row/bill/order write;
- token benar tetapi JSON/status/ID/reference/money/timestamp malformed: 422;
- PAID dan SETTLED menghasilkan satu logical event ID;
- tidak ada raw status/provider payload dalam error/log.

### Namespace dan fail-closed

- well-formed AB_ paid hanya menyentuh bill finalizer;
- malformed `AB_foo` ditolak dan tidak mencari order walau gateway_ref order cocok;
- well-formed AB_ tidak dikenal ditolak tanpa fallback;
- spoofed AB_ dengan provider selain Xendit ditolak;
- reference non-AB_ tetap melalui order handler dengan hasil/status HTTP existing;
- merchant reference berbeda pada duplicate event ID menjadi Conflict;
- provider reference, amount, currency, tenant/item/snapshot korup ditolak tanpa
  event claim parsial, settlement, audit, activation, atau outbox.

### Status dan replay

- pending assessment event ignored;
- paid membuat satu settlement/allocation/audit/activation set;
- expired membuat bill expired tanpa paid/access/release;
- cancelled synthetic membuat bill rejected tanpa paid/access/release;
- late expired/cancelled/pending setelah paid no-op;
- paid setelah expired/rejected ditolak dan tidak revive;
- exact paid replay/PAID-vs-SETTLED/webhook-vs-GET race tidak menggandakan effect;
- crash pada item kelima/outbox rollback payment event claim dan seluruh P11a.

### Reconciliation dan legacy

- selection assessment bounded hanya pending Xendit dengan gateway_ref;
- GET terjadi saat RLS context NULL dan transactionLevel 0; assert no POST;
- response paid/expired melalui processor/dispatcher/finalizer yang sama;
- timeout/HTTP/malformed satu bill tidak menghentikan kandidat berikutnya;
- reserved/issuing/unknown/paid/expired/rejected/manual tidak dipilih;
- seluruh `PaymentWebhookProcessorTest`, `PaymentEventApplierTest`,
  `XenditStatusReconciliationTest`, dan controller webhook legacy tetap lulus
  tanpa perubahan body/status/logical transition.

### PostgreSQL

- runtime non-owner/NOBYPASSRLS; no-context/admin/participant tidak dapat
  memanggil dispatcher/finalizer sebagai service;
- dua proses webhook/status event sama menghasilkan satu event claim dan satu
  settlement/audit/outbox set;
- rollback finalizer membatalkan claim event;
- AB_ unknown/foreign scope tidak dapat membaca atau mengubah tenant lain.

## Pembagian increment yang direkomendasikan

1. **P11b1 dispatcher + terminal mapping:** dispatcher/result kecil,
   `PaymentWebhookProcessor` injection dan duplicate merchant check,
   `FinalizeAssessmentBill` terminal handling, satu feature contract file.
   Target maksimal lima file kode/tes; controller/route/provider adapter tetap.
2. **P11b2 status reconciliation internal:** action assessment bounded + feature
   dan PostgreSQL tests. Tidak mendaftarkan command/scheduler.
3. **Wiring operasional terpisah:** hanya setelah review P11b1/P11b2 dan P11c;
   tentukan command/scheduler/overlap/metrics default-OFF. Tidak termasuk proposal
   ini.

Tidak dibuat RED tests pada P11b0 karena mapping terminal dan bentuk dispatcher
masih menunggu keputusan review. Menambahkan test gagal ke suite tracked akan
melanggar gerbang repository; matriks di atas menjadi kontrak TDD untuk increment
yang disetujui berikutnya.
