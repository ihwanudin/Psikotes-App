# P10c-b0: proposal bounded discovery dan durable reconciliation lease

Tanggal: 2026-09-01
Status: proposal untuk review; tidak ada schema, command, scheduler, provider call,
atau wiring produksi yang diimplementasikan pada increment ini.

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

Tambahkan empat kolom nullable/terpisah pada `outbox_messages`:

- `reconciliation_lease_token` string/UUID nullable;
- `reconciliation_lease_expires_at` timestamp with timezone nullable;
- `reconciliation_next_at` timestamp with timezone nullable;
- `reconciliation_lookup_attempts` unsigned small integer default 0.

Constraint wajib memasangkan token dan expiry (keduanya NULL atau keduanya non-
NULL), melarang nilai lease/counter/cooldown non-default pada topic selain invoice
issuance, dan membatasi counter. Partial index PostgreSQL memilih topic invoice,
attempts1, processed NULL, status processing/failed, lalu mengurutkan
`reconciliation_next_at`, lease expiry, dan id. SQLite boleh memakai index biasa
untuk feature semantics; hanya PostgreSQL menjadi bukti concurrency.

Token dibuat dengan UUID/ULID framework existing, bukan random/crypto buatan
sendiri. Semua perbandingan due/expiry memakai database UTC clock pada statement
conditional, sehingga skew jam antarpod tidak dapat mencuri lease lebih awal.

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
  canonical pair + due + lookup_attempts < max + lease kosong/kedaluwarsa
  └─ atomic acquire → LEASED(token, expires_at, attempts+1)

LEASED
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

`lookup_attempts` menghitung GET rekonsiliasi, bukan permit/create attempts.
Acquisition increment atomik memberikan fencing generation bersama token unik.
Worker lama wajib gagal persist bila token tidak lagi cocok, walaupun response
provider exact. Tidak ada klaim globally single GET: crash/expiry dapat membuat
lookup ulang, tetapi satu lease aktif dan late persistence tetap fenced.

## Bounded discovery dan urutan lock

Discovery dipanggil internal tanpa ambient RLS context/transaksi. Ia menerima
limit tervalidasi, membaca maksimal `scan_limit` kandidat dari query service yang
topic/state/due-nya sempit, dan tidak menerima tenant/message dari browser.
Hint hasil query bukan authority.

Setiap kandidat diakuisisi dalam service transaction terpisah agar satu tenant
besar tidak menahan seluruh batch. Urutan lock mempertahankan P7/P8b/P10b:

1. organization;
2. integration clients dan sources terurut;
3. bill lalu bill items terurut;
4. attempts, participants, packages, charges terurut;
5. payment method;
6. canonical outbox intent dengan `FOR UPDATE SKIP LOCKED`;
7. row lease metadata/field pada outbox yang sama.

Validator canonical harus diekstrak/reuse dari claim; discovery dilarang menyalin
predicate payer/policy/snapshot/linkage. Setelah seluruh scope, policy, channel,
revoke, state, counter, due dan lease diperiksa, update token/expiry/counter
dilakukan conditional dalam transaksi yang sama, dengan syarat lease NULL atau
expired, `next_at` NULL/due, dan lookup count masih di bawah maksimum. Jika row
sedang dikunci, `SKIP LOCKED` menghasilkan skip, bukan wait atau fallback tidak
terotorisasi.
Batch boleh terisi kurang dari limit saat contention.

Transaksi service selesai dan RLS context kembali kosong sebelum DTO lease
diberikan ke caller. GET kemudian memakai P10a strict reference/amount/currency.
Persist membuka transaksi service baru, mengulang canonical/late-state checks,
dan mensyaratkan token pemilik yang belum kedaluwarsa. Tidak ada transaksi atau
RLS context yang melintasi network call.

## API internal yang diusulkan

```php
AcquireAssessmentInvoiceReconciliationLeases::execute(int $limit):
    list<AssessmentInvoiceReconciliationLease>

AssessmentInvoiceReconciliationLease {
    messageId: string,
    leaseToken: string,
    leaseExpiresAt: CarbonImmutable
}

ReconcileAssessmentBillInvoice::executeLeased(
    string $messageId,
    string $leaseToken,
): array{decision: string, messageId: string}
```

Acquirer tidak memanggil provider. `executeLeased` menolak token dari URL/query,
caller/user/admin, ambient context, lease expired/foreign, atau outer transaction.
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

Metrics/counter yang disarankan: candidates scanned, lease acquired, skip-locked,
ineligible per reason-code enum, lookup exact/empty/ambiguous/mismatch/timeout/
error, lease lost/expired, persist conflict, dan exhausted. Histogram: acquisition
latency, GET latency, serta lease age. Structured log hanya operation code,
attempt count, duration, dan hash `message_id`; jangan log payload, participant,
merchant reference, provider reference, invoice URL, credential, SQL bindings,
atau exception body provider.

Lease/cooldown tidak membuat audit bisnis. Audit `invoice_unknown` dan
`invoice_issued` tetap berasal dari persistence boundary dan maksimal sekali per
transisi. Exhausted menghasilkan metric/operational alert; workflow manual dan
retensinya perlu keputusan P11b/runbook terpisah.

## Migration dan rollback

Up migration hanya additive, mempertahankan tipe/index/RLS outbox existing,
menambah columns, CHECK dan index. Existing rows mendapat counter 0 serta lease/
cooldown NULL; tidak ada backfill state bisnis atau perubahan payload.

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
- discovery hanya dua pasangan canonical; pending0/processed/paid/expired/
  rejected/noncanonical/corrupt/foreign/policy OFF/channel OFF/revoked tidak leased;
- batch/scan bounds, deterministic ordering, due/cooldown/max-attempt filtering;
- acquire menulis token+expiry+counter atomik; acquisition rollback tidak
  meninggalkan lease;
- handler menolak ambient role/transaksi, token salah/expired/lost, dan tidak
  memanggil create;
- crash lease dapat diambil ulang setelah clock advance; worker lama tidak dapat
  persist exact atau unknown;
- exact/unknown memakai shared boundary, cleanup/cooldown conditional token,
  repeated unknown tidak audit spam, terminal late response tidak overwrite;
- legacy notification/integration consumers mengabaikan topic dan kolom baru;
- logs/errors generik dan tidak mengandung payload/reference/URL/credential.

SQLite membuktikan state machine/CAS, bukan `SKIP LOCKED` atau RLS concurrency.

### PostgreSQL disposable dua proses

- runtime `psikotes_runtime` non-owner/NOBYPASSRLS;
- dua discovery worker overlap: `FOR UPDATE SKIP LOCKED`, token unik, satu owner
  per intent, total leased <= limit, tidak deadlock dan tenant lain aman;
- dua bill satu organization mengikuti organization-first lock; dua organization
  dapat maju tanpa cross-tenant leakage;
- rollback setelah token update mengembalikan counter/lease seluruhnya;
- crash process meninggalkan durable lease; sebelum expiry worker kedua skip,
  setelah expiry memperoleh token baru dan counter bertambah;
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
- Generic outbox retention/purge belum memberi izin menghapus active lease;
  purge policy harus mengecualikan lease aktif bila kelak dibuat.

## Pembagian implementasi setelah review

Tidak ada bagian berikut yang diizinkan oleh proposal ini. Rekomendasi slice:

1. **P10c-b1 schema contract**: migration additive, model casts/PHPDoc, config,
   feature schema tests, PG migration tests (maksimal 5 file); laporan commit
   terpisah bila menjadi file keenam.
2. **P10c-b2 acquisition**: DTO, acquisition action, ekstraksi validator canonical
   dari claim, feature tests, PG two-process tests (maksimal 5 file). Refactor
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
