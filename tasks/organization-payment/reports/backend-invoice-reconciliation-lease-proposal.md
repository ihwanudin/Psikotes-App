# P10c-b0: proposal bounded discovery dan durable reconciliation lease

Tanggal: 2026-09-01
Status: proposal untuk review; tidak ada schema, command, scheduler, provider call,
atau wiring produksi yang diimplementasikan pada increment ini.

Revisi review: acquisition dipisah menjadi reservasi provisional outbox-only dan
validasi canonical organization-first. Tidak ada transaksi yang memegang outbox
lalu meminta organization.

## Tujuan dan batas

P10c-a sudah dapat merekonsiliasi satu `message_id` persisted tanpa pernah
memanggil `createInvoice`. P10c-b membutuhkan discovery terbatas dan kepemilikan
lookup yang dapat pulih setelah worker crash. Proposal ini hanya menetapkan
kontrak teknis. Scheduler/command tetap tidak terdaftar dan nonaktif sampai P11b
serta finalizer sudah teruji.

Kandidat hanya boleh berasal dari topic
`assessment.bill.invoice-issuance`, aggregate `AssessmentBill`, attempts tepat 1,
`processed_at` NULL, dan salah satu pasangan canonical berikut:

1. bill `issuing`, message `processing`, `last_error` NULL; atau
2. bill `unknown`, message `failed`, `last_error=INVOICE_OUTCOME_UNKNOWN`.

State pending/0, processed, paid, expired, rejected, linkage/snapshot corrupt,
scope asing, policy/channel OFF, revoked/finalized, atau error lain tidak boleh
memperoleh lease maupun mencapai provider. Lease tidak pernah menjadi izin
create, rearm, settlement, entitlement, consent, identity verification, atau
aktivasi.

## Audit field outbox existing

Schema sekarang menyediakan `status`, `attempts`, `available_at`, `processed_at`,
`expires_at`, dan timestamps. Tidak ada field existing yang aman untuk lease:

| Field | Invariant existing | Mengapa tidak boleh dijadikan lease |
| --- | --- | --- |
| `available_at` | Pada invoice intent wajib persis `claimedAt` dan `created_at`; divalidasi oleh replay P10b | Menggesernya untuk lease/cooldown membuat intent canonical menjadi corrupt. |
| `attempts` | Pending permit 0, setelah izin network tepat 1 dan tidak boleh reset/increment | Counter lease akan merusak fence “maksimal satu create”. |
| `status` | `processing` berarti permit create sudah dikonsumsi; `failed` berarti outcome invoice unknown | Tidak ada state status tersisa yang membedakan owner lookup; constraint juga hanya mengizinkan empat nilai. |
| `processed_at` | Hanya terminal exact result | Menulisnya sebagai expiry lease akan membuat replay terlihat processed. |
| `expires_at` | Retensi intent dua tahun | Bukan deadline worker dan tidak boleh diperpanjang/rearm. |
| `updated_at` | Dipakai stale recovery consumer notifikasi legacy; tidak memiliki owner/fencing token | Timestamp tunggal tidak membuktikan kepemilikan, dapat ditimpa writer lain, dan mengubah semantik legacy. |

Kesimpulan: reuse field existing ditolak. Khusus `available_at`, perubahan sekecil
apa pun akan gagal pada pemeriksaan canonical di `ClaimAssessmentBillInvoice`.

## Perbandingan opsi

### Opsi 1 — overload field existing

Kelebihan: tanpa migration. Kekurangan: merusak invariant di atas, tidak memiliki
owner token, tidak dapat membedakan response worker lama setelah lease dicuri,
dan berisiko mengubah consumer legacy semua topic. **Ditolak.**

### Opsi 2 — PostgreSQL advisory lock atau cache lock

Advisory transaction lock mengharuskan transaksi/koneksi RLS tetap terbuka selama
GET, bertentangan dengan ADR-007/008. Session advisory lock memang dapat melepas
saat koneksi mati, tetapi tidak durable/terlihat sebagai state aplikasi, rawan
pooling, dan tidak portabel ke harness SQLite. Redis/cache lock bergantung pada
TTL dan kesehatan cache di luar transaksi canonical; eviction, failover, atau
partition dapat menghasilkan dua owner. Keduanya tidak dapat menjadi fence saat
persist tanpa sumber kebenaran database. Boleh dipakai kelak sebagai optimasi
best-effort, tetapi bukan authority. **Ditolak sebagai lease canonical.**

### Opsi 3 — migration additive metadata lease

Tambahkan empat kolom terpisah pada `outbox_messages` dengan tipe konkret:

- `reconciliation_lease_token`: Laravel `uuid`, PostgreSQL native `uuid` nullable,
  dan SQLite text nullable sesuai grammar schema builder;
- `reconciliation_lease_expires_at`: `timestampTz` nullable;
- `reconciliation_next_at`: `timestampTz` nullable;
- `reconciliation_lookup_attempts`: `unsignedSmallInteger` default 0, dengan
  CHECK eksplisit `0 <= value AND value <= 100` pada semua engine uji.

Constraint wajib memasangkan token dan expiry (keduanya NULL atau keduanya non-
NULL), melarang nilai lease/counter/cooldown non-default pada topic selain invoice
issuance, dan membatasi counter. Index PostgreSQL memakai kolom
`topic,status,attempts,processed_at,reconciliation_next_at,
reconciliation_lease_expires_at,id`. Predicate partial, bila dipakai, hanya
memuat perbandingan kolom/konstanta untuk topic/status/attempts/processed NULL;
ia tidak boleh memuat `CURRENT_TIMESTAMP`/`now()` yang volatil. Kondisi nullable
`next_at IS NULL OR next_at <= database_now` dan lease kosong/kedaluwarsa tetap
berada di query. SQLite memakai index biasa; hanya PostgreSQL menjadi bukti
`SKIP LOCKED` dan concurrency.

Token konkret adalah UUID dari primitive Laravel/framework existing, bukan
random/crypto buatan sendiri. Semua perbandingan due/expiry memakai database UTC
clock pada statement conditional, sehingga skew jam antarpod tidak dapat mencuri
lease lebih awal.

Kolom baru tidak ikut payload canonical dan tidak mengubah `available_at`,
`attempts`, status bisnis, expiry retensi, atau timestamps klaim. Query builder
untuk metadata lease tidak mengubah `updated_at`, sehingga cooldown operasional
tidak menyamar sebagai perubahan outcome. **Direkomendasikan**, tetapi memerlukan
ADR/migration review terpisah sebelum implementasi.

## State machine yang diusulkan

```text
INELIGIBLE
  └─ tidak pernah provider

ELIGIBLE
  outbox hint topic/status/attempts/due + lookup_attempts < max
  + lease kosong/kedaluwarsa
  └─ fase 1 SKIP LOCKED → PROVISIONAL(token, expires_at), counter tetap

PROVISIONAL
  ├─ hint invalid saat full validation
  │    → clear token secara token-fenced; counter/audit/provider tetap nol
  ├─ crash → token dibiarkan expire → ELIGIBLE
  └─ fase 2 canonical valid + token/expiry masih cocok
       → VALIDATED(token, expires_at, lookup_attempts+1) + permit

VALIDATED
  ├─ crash sebelum/selama GET → tetap leased sampai expiry → ELIGIBLE
  ├─ exact + token masih owner + late state canonical
  │    → bill pending, message processed/1, lease cleared, audit issued
  ├─ unknown/error + token masih owner + issuing/processing canonical
  │    → bill unknown, message failed/1/canonical error,
  │      lease cleared, next_at=cooldown, satu audit unknown
  ├─ unknown/error + token masih owner + unknown/failed canonical
  │    → business state/audit tidak berubah; lease cleared,
  │      next_at=cooldown
  ├─ token hilang/kedaluwarsa, terminal, atau late state noncanonical
  │    → response dibuang, recovery_required; tidak overwrite/audit
  └─ max lookup tercapai → EXHAUSTED/manual review, tanpa create/rearm
```

`lookup_attempts` menghitung permit GET rekonsiliasi yang sudah lolos validasi,
bukan hint provisional, provider response, atau permit/create attempts P10b.
Crash setelah fase 2 commit tetapi sebelum GET tetap mengonsumsi satu hitungan;
ini fail-closed dan membatasi retry. Token UUID menjadi fencing owner. Worker lama
wajib gagal persist bila token tidak lagi cocok, walaupun response provider exact.
Tidak ada klaim globally single GET: crash/expiry dapat membuat lookup ulang,
tetapi satu token aktif dan late persistence tetap fenced.

## Bounded discovery dan urutan lock

Discovery dipanggil internal tanpa ambient RLS context/transaksi, menerima limit
tervalidasi, dan tidak menerima tenant/message dari browser. Acquisition wajib
dua fase dengan transaksi terpisah:

### Fase 1 — reservasi provisional outbox-only

Satu service transaction sangat pendek memilih maksimal
`min(remaining_batch, remaining_scan)` row
`outbox_messages` dengan topic invoice, aggregate type bill, attempts1,
processed NULL, status processing/failed, last-error hint yang sesuai status,
counter di bawah maksimum, cooldown due, dan lease kosong/kedaluwarsa. Query
mengunci row memakai `FOR UPDATE SKIP LOCKED`, mengurutkan due/id, memasang UUID +
expiry secara conditional, **tidak** mengunci organization/bill/registry, **tidak**
menambah lookup counter, dan segera commit.

Coordinator memvalidasi provisional tersebut pada fase 2 sebelum meminta chunk
berikutnya. Hint invalid menambah scanned count lalu dibersihkan; loop berhenti
saat permit valid mencapai batch size atau total hint mencapai scan limit. Dengan
demikian satu invocation tidak menahan provisional melebihi batch size dan tidak
memindai tanpa batas.

Inilah satu-satunya tempat yang diklaim non-blocking karena `SKIP LOCKED`.
Discovery worker kedua tidak menunggu organization lock sebelum mencapainya.
Mengunci outbox lebih dulu aman hanya karena transaksi fase 1 sudah selesai
sebelum fase 2 mulai; tidak ada transaksi yang memegang outbox lalu meminta
organization. Token provisional boleh mengenai payload, bill pair, tenant,
policy, channel, atau revoke yang kemudian terbukti invalid. Hal itu aman karena
token bukan authority dan tidak dapat menghasilkan provider permit.

### Fase 2 — validasi canonical dan permit

Setiap token provisional divalidasi dalam service transaction baru. Urutan lock
persis P7/P8b/P10b:

1. organization;
2. integration clients dan sources terurut;
3. bill lalu bill items terurut;
4. attempts, participants, packages, charges terurut;
5. payment method;
6. outbox intent terakhir.

Validator canonical harus diekstrak/reuse dari claim; dilarang menyalin predicate
payer/policy/snapshot/linkage. Setelah full tenant/scope, exact state pair,
policy/channel/revoke, payload, count/sum dan linkage valid, outbox terakhir harus
masih mempunyai UUID provisional yang sama dan expiry menurut database clock
belum lewat. Baru pada titik itu update conditional menambah lookup counter tepat
satu dan menghasilkan DTO permit. Commit dan pelepasan RLS context terjadi
sebelum GET.

Jika kandidat invalid, fase 2 membersihkan token/expiry hanya dengan kondisi
token masih sama; tidak mengubah counter, cooldown, state bisnis, atau audit, dan
tidak memanggil provider. Jika proses crash sebelum cleanup, token aman dibiarkan
expire. Jika validator melempar, cleanup boleh dilakukan setelah transaksi
validator selesai/rollback melalui transaksi outbox-only yang token-fenced;
cleanup tidak boleh menahan organization lock sambil menunggu outbox asing.

GET memakai P10a strict reference/amount/currency di luar transaksi/context.
Persist membuka transaksi service baru, mengulang canonical/late-state checks,
dan mensyaratkan token UUID serta lookup generation permit masih sama dan lease
belum kedaluwarsa. Worker lama setelah expiry/steal selalu gagal. Tidak ada
transaksi atau RLS context yang melintasi network call.

## API internal yang diusulkan

```php
ReserveAssessmentInvoiceReconciliationHints::execute(int $scanLimit):
    list<ProvisionalAssessmentInvoiceLease>

ValidateAssessmentInvoiceReconciliationLease::execute(
    ProvisionalAssessmentInvoiceLease $lease
): AssessmentInvoiceReconciliationPermit|null

ProvisionalAssessmentInvoiceLease {
    messageId: string,
    leaseToken: string,
    leaseExpiresAt: CarbonImmutable
}

AssessmentInvoiceReconciliationPermit {
    messageId: string,
    leaseToken: string,
    lookupAttempt: int,
    merchantReference: string,
    amount: int,
    currency: string
}

ReconcileAssessmentBillInvoice::executeLeased(
    AssessmentInvoiceReconciliationPermit $permit,
): array{decision: string, messageId: string}
```

Reservasi maupun validator tidak memanggil provider. Hanya permit hasil fase 2
boleh diteruskan ke `executeLeased`; UUID provisional mentah bukan authority.
`executeLeased` menolak token dari URL/query, caller/user/admin, ambient context,
lease expired/foreign, generation berbeda, atau outer transaction.
Method single-intent P10c-a tidak boleh menjadi bypass scheduler; sebelum wiring,
tetap internal/test-only. Persistence boundary bersama perlu entrypoint
reconciliation yang membawa fence token, sementara jalur issuance P10b tetap
tanpa lease dan tidak dilonggarkan.

## Konfigurasi yang diusulkan

Semua key server-side di `assessment_billing.php`, tervalidasi positive integer
dan bounded:

| Key | Default usulan | Batas usulan | Fungsi |
| --- | ---: | ---: | --- |
| `invoice_reconciliation_batch_size` | 25 | 1–100 | Lease maksimum per invocation. |
| `invoice_reconciliation_scan_limit` | 100 | batch–400 | Membatasi hint scan saat contention/ineligible. |
| `invoice_reconciliation_lease_seconds` | 60 | 30–300 | Harus melebihi timeout provider + margin. |
| `invoice_reconciliation_cooldown_seconds` | 300 | 60–86.400 | Jeda unknown berikutnya. |
| `invoice_reconciliation_max_lookups` | 12 | 1–100 | Setelah itu manual review, tanpa POST. |

Command/scheduler nanti harus no-op bila feature/source belum diaktifkan dan tetap
tidak didaftarkan sampai P11b. Config bukan izin endpoint, credential, atau
outbound.

## Observability tanpa PII

Metrics/counter yang disarankan: hints scanned, provisional reserved,
phase-1 skip-locked, provisional invalid/cleared/expired, validation success dan
reason-code failure, lookup permit issued, lookup exact/empty/ambiguous/mismatch/
timeout/error, lease lost/stolen, persist conflict, dan exhausted. Pisahkan jumlah
provisional dari lookup counter agar hint invalid tidak terlihat sebagai provider
attempt. Histogram: durasi fase 1, validasi fase 2, GET, serta lease age.
Structured log hanya phase/operation code, lookup generation, duration, dan hash
`message_id`; jangan log UUID lease, payload, participant, merchant/provider
reference, invoice URL, credential, SQL bindings, atau exception body provider.

Lease/cooldown tidak membuat audit bisnis. Audit `invoice_unknown` dan
`invoice_issued` tetap berasal dari persistence boundary dan maksimal sekali per
transisi. Exhausted menghasilkan metric/operational alert; workflow manual dan
retensinya perlu keputusan P11b/runbook terpisah.

## Migration dan rollback

Up migration hanya additive, mempertahankan tipe/index/RLS outbox existing,
menambah columns, CHECK dan index. Existing rows mendapat counter 0 serta lease/
cooldown NULL; tidak ada backfill state bisnis atau perubahan payload.

Rolling order: deploy migration dengan kolom nullable/default0 terlebih dahulu,
lalu code yang masih default OFF, dan baru aktifkan wiring pada tahap P11b setelah
semua node memahami schema. Code lama tetap kompatibel karena writer existing
tidak menyentuh kolom baru dan canonical hash tidak memuatnya. Rollback berjalan
terbalik: nonaktifkan wiring, drain/expire lease, rollback code, lalu schema.

Down migration wajib preflight dan menolak rollback bila ada token/expiry aktif,
counter nonzero, atau `next_at` non-NULL. Ia tidak boleh menghapus/mengosongkan
metadata secara diam-diam karena itu dapat menghidupkan lookup yang sudah
exhausted. Operator harus menghentikan wiring, memastikan tidak ada worker,
menyelesaikan/mengekspor state operasional, lalu menjalankan rollback eksplisit.
Kegagalan preflight harus terjadi sebelum constraint/index/column apa pun dihapus.

## Matriks TDD

### Feature/SQLite memory

- schema defaults, pair constraint, topic isolation, config bounds dan rollback
  preflight tanpa mutasi parsial;
- fase 1 hanya topic/status/counter/due hints, batch/scan bounds, ordering
  deterministic, dan tidak mengklaim hint sebagai canonical;
- reservasi provisional menulis UUID+expiry tanpa menaikkan lookup counter;
  rollback fase 1 tidak meninggalkan token;
- hint yang full state-nya invalid boleh mendapat provisional, lalu fase 2
  membersihkannya tanpa counter/provider/audit;
- fase 2 hanya menerbitkan permit untuk dua pasangan canonical; pending0/
  processed/paid/expired/rejected/noncanonical/corrupt/foreign/policy OFF/channel
  OFF/revoked dibersihkan secara token-fenced tanpa provider/counter/audit;
- token berubah/expired antara fase 1 dan 2 menolak permit; cleanup tidak
  menghapus token owner baru;
- handler menolak ambient role/transaksi, token salah/expired/lost, dan tidak
  memanggil create;
- crash provisional sebelum validasi dapat diambil ulang setelah clock advance
  tanpa increment; crash setelah permit dapat diambil ulang dengan generation
  berikutnya; worker lama tidak dapat persist exact atau unknown;
- exact/unknown memakai shared boundary, cleanup/cooldown conditional token,
  repeated unknown tidak audit spam, terminal late response tidak overwrite;
- legacy notification/integration consumers mengabaikan topic dan kolom baru;
- logs/errors generik dan tidak mengandung payload/reference/URL/credential.

SQLite membuktikan state machine/CAS, bukan `SKIP LOCKED` atau RLS concurrency.

### PostgreSQL disposable dua proses

- runtime `psikotes_runtime` non-owner/NOBYPASSRLS;
- dua worker fase 1 overlap langsung pada outbox `FOR UPDATE SKIP LOCKED`: tidak
  menunggu organization, token provisional unik, total provisional aktif per
  invocation <= batch size dan total hint diperiksa <= scan limit;
- saat process ketiga sengaja menahan organization lock, fase 1 tetap selesai;
  fase 2 yang sesuai baru menunggu/serialize setelah provisional commit;
- bukti query/lock menunjukkan tidak ada transaksi memegang outbox sambil meminta
  organization; fase 2 baru mulai setelah fase 1 commit;
- race dengan `ClaimAssessmentBillInvoice` membuktikan claim organization-first
  dapat menunggu transaksi provisional yang pendek, tetapi tidak membentuk cycle:
  provisional tidak pernah meminta organization dan fase 2 belum dimulai;
- dua fase 2 untuk kandidat/organization sama boleh serialize pada organization
  lock existing, tetapi hanya owner token valid yang increment/menerima permit;
- dua bill satu organization mengikuti organization-first; dua organization dapat
  maju tanpa cross-tenant leakage atau deadlock dengan Claim P10b;
- rollback fase 1 menghapus seluruh provisional; rollback fase 2 mempertahankan
  token provisional dan counter lama, lalu cleanup token-fenced dapat berjalan;
- crash provisional meninggalkan durable token: sebelum expiry worker kedua skip,
  setelah expiry memperoleh token baru tanpa increment lama; crash setelah permit
  membuat generation berikutnya increment setelah validasi ulang;
- response worker lama setelah steal gagal conditional persist; hanya token baru
  dapat menulis satu outcome/audit;
- unknown concurrent/cooldown tidak menghasilkan rewrite atau audit spam;
- migration up/down roundtrip dan down menolak metadata aktif tanpa perubahan
  parsial;
- tidak ada POST/provider nyata; provider fake/HTTP fake tetap GET-only.

## Risiko kompatibilitas legacy

- Model `OutboxMessage` bersifat unguarded dan casts payload/timestamps; kolom baru
  harus diberi casts eksplisit tetapi tidak dimasukkan ke payload serialization.
- Consumer notification menggunakan `updated_at` untuk stale processing. Lease
  harus memakai query builder tanpa menyentuh `updated_at`, topic-filtered, agar
  tidak mengubah retry legacy.
- Consumer integration memilih pending/failed topic lain. CHECK/index baru wajib
  mengizinkan semua row legacy dengan metadata default dan tidak mengubah query/
  status mereka.
- Claim P10b membandingkan `available_at`, payload dan expiry; kolom additive tidak
  boleh masuk canonical hash atau replay comparison.
- UUID native PostgreSQL dibaca sebagai string oleh model; cast SQLite tetap text.
  Query/index tidak boleh bergantung pada predicate waktu volatil.
- Generic outbox retention/purge belum memberi izin menghapus active lease;
  purge policy harus mengecualikan lease aktif bila kelak dibuat.

## Pembagian implementasi setelah review

Tidak ada bagian berikut yang diizinkan oleh proposal ini. Rekomendasi slice:

1. **P10c-b1 schema contract**: migration additive, model casts/PHPDoc, config,
   feature schema tests, PG migration tests (maksimal 5 file); laporan commit
   terpisah bila menjadi file keenam.
2. **P10c-b2 acquisition**: DTO provisional/permit, reservasi fase 1, validator
   fase 2 + ekstraksi canonical dari claim, feature tests, PG two-process tests.
   Bila melewati 5 file, pisah reservasi schema-aware dari validator; refactor
   claim wajib membuktikan P10b tidak berubah.
3. **P10c-b3 leased execution**: reconciliation action, shared persistence fence,
   feature/PG tests dan laporan (pecah commit bila lebih dari 5 file).
4. **P11b operational wiring** setelah finalizer siap: command, scheduler/lock,
   metrics/runbook dan activation config. Default tetap OFF; registration tidak
   boleh masuk slice P10c-b1–b3.

Keputusan yang diminta sebelum implementasi: ADR menerima migration additive,
nama/constraint empat kolom, nilai config, strategi ekstraksi validator, dan
rollback refusal. Sampai keputusan itu ada, P10c-b tetap proposal-only dan
nonaktif.
