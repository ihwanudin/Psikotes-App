# Proposal P11c0: authority dan verifikasi transfer manual assessment bill

## Status dan batas

**Proposed untuk review.** Audit ini tidak mengimplementasikan approval/rejection,
upload/access proof bill, policy, route, controller, Filament action, migration,
provider call, atau wiring. Rujukan keputusan ringkas ada di ADR-010 proposed.

Source yang diaudit: plan/todo/parallel-work dan SPEC organization billing terbaru;
ADR-002 serta ADR-006–009; `AssessmentBill`, item, charge, entitlement, schema dan
RLS; `FinalizeAssessmentBill`; order legacy `StoreManualPaymentProof`,
`ManualPaymentProofUrlIssuer`, `VerifyManualTransfer`, `OrderPolicy`, dan
`OrderResource`; request upload, private filesystem, tests upload/access/review;
serta resource P12a-prep read-only di root.

## Temuan existing dan gap

| Area | Existing yang dapat dipakai | Gap untuk assessment bill |
| --- | --- | --- |
| Settlement | `FinalizeAssessmentBill` mengunci organization→bill→seluruh allocation, memvalidasi total/currency/reference, settle atomik, audit, P8b activation/outbox | Hanya menerima `PaymentEvent` provider dan mengharuskan `gateway_ref`; manual tidak boleh menyamar sebagai Xendit |
| Schema bill | Ada `proof_object_key`, verifier/time, rejection reason, status pending/paid/rejected | Tidak ada checksum, MIME, byte size, uploadedAt atau proof version durable |
| Upload order | Session bound, File::types magic/MIME + extension, max 5.000 KiB, random private key, checksum, replacement cleanup | Memilih order per participant; tidak scope bill/payer/organization dan menyimpan metadata di JSON order yang tidak ada pada bill |
| Proof access order | Temporary URL 15 menit, policy, RLS context, access audit | Policy mengizinkan BranchAdmin/Staff ber-flag; dilarang untuk reviewer bill |
| Review order | Row lock, expected object key, replay/terminal state machine, verifier/audit | Writer order membuka entitlement legacy langsung dan memakai branch verifier; tidak boleh dipakai bill |
| RLS bill | SuperAdmin baca semua; BranchAdmin baca bill own; semua write hanya service | RLS read saja tidak cukup untuk raw proof/decision; policy exact masih perlu |
| Portal P12a-prep | BranchAdmin own read-only, minimal select, menyembunyikan proof/gateway/reason, testing-only | Sengaja tidak menerima SuperAdmin dan tidak punya action; jangan dilebarkan menjadi reviewer |
| Retention | Audit payment/proof access existing dua tahun | Tidak ada lifecycle purge proof; jangan menghapus otomatis sebelum keputusan terpisah |

Kesimpulan audit: provider interface tidak perlu diubah. Schema proof bill perlu
increment additive yang direview; setelah itu finalizer dapat diperluas dengan
manual authority typed tanpa fake callback.

## Actor/action/resource matrix

`Own` berarti payer yang authoritative dan scope persisted cocok. Flag
`can_verify_payments` tidak pernah mengubah sel P11c.

| Actor persisted | Ringkasan bill own | Submit/replace proof payer | Lihat raw proof reviewer | Approve/reject | Bill/proof organisasi lain |
| --- | --- | --- | --- | --- | --- |
| SuperAdmin ONCAM aktif | reviewer list seluruh bill manual | Tidak; separation of duties | Ya, lintas cabang, URL privat singkat | Ya | Ya, hanya reviewer surface |
| BranchAdmin owner, flag false/true | Ya lewat portal own setelah P12 aktif | Ya untuk organization bill pending manual | Tidak | Tidak | Tidak |
| BranchAdmin foreign | Tidak | Tidak | Tidak | Tidak | Tidak |
| Staff, flag false/true | Tidak untuk P12/P11c | Tidak | Tidak | Tidak | Tidak |
| Psychologist | Tidak | Tidak | Tidak | Tidak | Tidak |
| Participant owner | Hanya status projection attempt sendiri | Ya hanya untuk self bill pending manual setelah principal P8/P13 tersedia | Tidak | Tidak | Tidak |
| Participant lain | Tidak | Tidak | Tidak | Tidak | Tidak |
| Guest | Tidak | Tidak | Tidak | Tidak | Tidak |
| Admin deleted/role berubah/session stale | Tidak | Tidak | Tidak | Tidak | Tidak |

Reviewer policy wajib memeriksa `role === SuperAdmin` pada Admin yang direload dan
belum soft-deleted. Jangan memanggil `canPerform(VerifyPayments)` atau
`OrderPolicy`. Upload payer merupakan surface lain dan tidak memberi hak view raw
atau decision.

### Missing versus forbidden

| Surface | Guest | Admin non-SuperAdmin | SuperAdmin + record hilang | SuperAdmin + proof hilang/corrupt | SuperAdmin + fingerprint stale |
| --- | --- | --- | --- | --- | --- |
| Reviewer collection | redirect login/unauthenticated | 403 | n/a | n/a | n/a |
| Direct bill/proof URL | redirect login/unauthenticated | 404 | 404 | 404 generik | 409/422 generik |
| Decision request | unauthenticated | authorize sebelum lookup; 403 internal dan UI tidak mengekspos record | 404 | 409/422 `proof unavailable` | 409/422 `proof changed` |

Record-level surface memakai 404 bagi admin terautentikasi yang bukan reviewer
agar valid/invalid ID lintas tenant tidak dapat dibedakan. Internal action harus
menolak actor persisted sebelum bill hint query. Exception/message tidak memuat
bill reference, organization, object key, participant, atau alasan database.

## Lifecycle proof dan keputusan

| State awal authoritative | Input | Hasil |
| --- | --- | --- |
| pending manual, proof NULL | upload valid dari payer | simpan object private lalu metadata atomik; status tetap pending; reviewer melihat `submitted` |
| pending manual, proof lengkap, verifier NULL | replacement payer sebelum review | row lock bill; ganti seluruh proof identity; delete old setelah commit; review fingerprint lama stale |
| pending manual, proof lengkap | buka reviewer | policy SuperAdmin; object exists; URL singkat private; audit access tanpa path |
| pending manual, proof lengkap | approve | finalizer manual settle seluruh item + activation per attempt; bill paid; verifier/time; satu bill audit |
| pending manual, proof lengkap | reject reason code | bill rejected; no settlement/access/outbox; verifier/time; satu bill audit |
| paid manual canonical | exact approve replay actor/proof sama | `replayed`, no mutation/audit/outbox |
| rejected manual canonical | exact reject replay actor/proof/reason sama | `replayed`, no mutation/audit |
| paid/rejected | opposite decision atau actor/proof berbeda | conflict; terminal tidak berubah |
| expired/rejected/provider-paid | manual approve | conflict; tidak revive/downgrade |
| reserved/issuing/unknown | upload/review | fail closed; state bukan pending manual review |
| pending Xendit | upload/review manual | fail closed; method/channel mismatch |
| proof metadata/key berubah setelah view | decision fingerprint lama | conflict tanpa mutation/audit |
| object hilang saat proof access preflight | view/decision | not-found atau proof-unavailable; tidak mutate |
| admin soft-deleted/role berubah sebelum commit | view/decision/replay | authorize persisted gagal sebelum bill lookup/mutation |
| dua reviewer paralel | keputusan sama/opposite | organization/bill lock membuat satu winner; second exact replay atau conflict |

Tidak ada status `pending_review` baru: pending + metadata proof lengkap adalah
submitted/pending review. Terminal proof immutable. Rejected tidak release charge,
tidak menghapus item, tidak membuat reinvoice, dan tidak mengaktifkan attempt.
Recovery luar biasa setelah terminal memerlukan keputusan baru; P11c tidak
menyediakan tombol reset.

## Kontrak proof storage

### Schema prerequisite yang direkomendasikan

Migration additive baru pada `assessment_bills`:

- `proof_checksum_sha256 CHAR(64) NULL`;
- `proof_mime_type VARCHAR(32) NULL`;
- `proof_size_bytes BIGINT NULL`;
- `proof_uploaded_at TIMESTAMPTZ NULL`.

PostgreSQL CHECK untuk proof identity:

1. `proof_object_key` dan empat metadata seluruhnya NULL atau seluruhnya non-NULL;
2. checksum regex `[0-9a-f]{64}`;
3. MIME exact JPEG/PNG/PDF;
4. size 1..5.120.000 byte;
5. key cocok namespace `assessment-bills/` dan tidak mengandung `..`, slash awal,
   backslash, karakter kontrol, atau nama asli.

Verified fields tetap memakai CHECK berpasangan existing. Invariant bahwa manual
paid/rejected wajib verifier + safe reason code membutuhkan channel-aware state
yang tidak dapat diungkap hanya dari status karena provider/manual sama-sama
memakai status itu. Rekomendasi minimal: jangan
menambah source column pada P11c1a; tegakkan melalui finalizer dan audit source,
lalu uji direct corrupt state fail-closed. Bila root menghendaki CHECK penuh,
tambahkan `settlement_source` enum nullable sebagai ADR/migration terpisah,
bukan menyelipkan scope.

Down migration wajib preflight menolak bila salah satu metadata proof non-NULL,
sebelum drop kolom apa pun. SQLite membuktikan kolom/default/model cast; CHECK dan
roundtrip/refusal dibuktikan PostgreSQL disposable non-owner.

### Upload dan access

Reuse rule `File::types(['jpg','jpeg','png','pdf'])`, extension allowlist, dan max
5.000 KiB. MIME berasal dari content detection, ekstensi output berasal dari MIME,
dan checksum dihitung dari byte upload sebelum metadata commit. Object key random
purpose-bound, private visibility, `throw/report` aktif. DB association gagal →
delete object baru; replacement DB commit → delete old best-effort. Cleanup error
dilaporkan tanpa key.

Reviewer temporary URL maksimal 15 menit, tidak lewat query aplikasi permanen,
tidak disimpan, tidak dikirim ke log, dan hanya diterbitkan setelah policy serta
object existence. Access audit menyimpan actor, bill subject, expiry, dan proof
fingerprint; tidak menyimpan URL/path/PII.

Fingerprint adalah version identity, bukan credential. Ia boleh berada pada
hidden Livewire state, tetapi tidak pada URL/log. Server menghitung ulang dari
lima field persisted saat decision. Browser tidak pernah menerima object key.

Pending proof dan terminal proof tidak dipurge pada P11c. Retain proof terminal
sekurangnya sampai audit dua tahun berakhir. Purge mendatang harus default-off,
melewati pending/active review, menangani legal hold bila ada, dan mempunyai audit
sendiri; belum termasuk P11c.

## Typed authority dan reuse finalizer

Jangan mengubah `PaymentEvent`. Tambahkan tipe internal, nama usulan:

```text
ManualAssessmentBillReview
  actorAdminId: positive int
  billReference: AB_ + ULID
  expectedProofFingerprint: 64 lowercase hex
  decision: APPROVE | REJECT
  rejectionCode: null untuk APPROVE; enum untuk REJECT
```

Rejection code allowlist, misalnya `AMOUNT_MISMATCH`, `UNREADABLE_PROOF`,
`WRONG_BENEFICIARY`, `DUPLICATE_PROOF`, `OTHER_UNVERIFIABLE`. Tidak ada free-text
pada increment awal. UI memetakan code ke label aman. Audit menyimpan code; tidak
menyimpan isi bukti atau keterangan PII.

Entrypoint `FinalizeAssessmentBill::executeManual(review)` menjadi sibling
`execute(PaymentEvent)` dan berkumpul pada private settlement/terminal primitive
yang sama setelah authority-specific validation:

- provider: aturan gateway/reference existing dan audit v1 tidak berubah;
- manual: method persisted tepat `manual_transfer` (aktivasi kanal saat ini tidak
  membatalkan review bill historis), gateway_ref/invoice_url NULL, proof
  identity lengkap/fingerprint exact, actor persisted SuperAdmin;
- amount/currency/reference/item count/total/charge/attempt selalu berasal dari
  bill dan allocation persisted, lalu divalidasi finalizer; browser tidak
  mengirim nilai itu;
- review time memakai database/application clock server pada transaksi, bukan
  tanggal dari browser atau OCR bukti.

Audit manual paid/rejected memakai actor_type `admin`, actor ID persisted,
subject bill, source `manual_transfer`, proof fingerprint, canonical money,
review time, item IDs, dan rejection code bila ada. Provider audit v1 tetap
byte-compatible. Replay manual membaca audit canonical dan tidak membuat audit
kedua.

## Transaction dan lock order

1. Validasi bentuk DTO tanpa DB; tolak ambient non-service context/transaction.
2. Masuk service transaction.
3. Lock/reload Admin, require non-deleted SuperAdmin; ini mencegah revocation race
   dan mengikuti funding/reservation admin→organization order.
4. Gunakan bill reference hanya sebagai unlocked organization hint setelah actor
   sah; lock Branch organization.
5. Lock bill by organization + public reference.
6. Lock items order ID; derive charge/attempt hints.
7. Lock attempts, participants, packages, charges, payment method dalam urutan
   P11a existing.
8. Recompute proof fingerprint, validate channel/state/scope/snapshot/count/sum.
9. APPROVE: update bill paid/verifier, settle item satu-per-satu, one bill audit,
   P8b activation/outbox semua dalam outer transaction.
10. REJECT: update rejected/verifier/reason, one bill audit; no settlement/access.
11. Commit; notification tetap outbox dan tidak dikirim oleh action.

Tidak ada filesystem/S3 read selama row locks. Proof access preflight memastikan
object exists dan ditinjau; transaction hanya mempercayai immutable persisted
fingerprint. Upload/replacement memakai organization→bill lock yang sama setelah
pihak payer direload. Concurrent update proof/reviewer akan serialize dan stale
fingerprint gagal.

Rollback setelah item kelima, audit insert, activation, atau outbox wajib
membatalkan verifier, status, paidAt, seluruh settledAt, audit, entitlement, dan
outbox. Storage object tidak dimutasi oleh decision transaction.

## Threat model STRIDE

| Threat | Contoh | Control proposed |
| --- | --- | --- |
| Spoofing | stale Admin object/flag cabang mengaku reviewer | reload+lock Admin; exact SuperAdmin; ignore legacy flag; auth before lookup |
| Tampering | browser mengganti org, total, status, proof key/reason | browser hanya decision/fingerprint; server derives scope/money; strict enum; fingerprint fence |
| Repudiation | reviewer menyangkal proof/decision | audited temporary access + one actor-bound decision audit + proof fingerprint/replay identity |
| Information disclosure | IDOR proof foreign, public URL/path/log | reviewer-only policy, record 404, private short URL, no key/URL/PII in response/log/audit |
| Denial of service | oversized/polyglot upload, repeated decisions, long storage under lock | magic/MIME+extension, 5MB, throttle, idempotent terminal, no I/O in transaction, row lock |
| Elevation of privilege | BranchAdmin owner atau Staff flag menyetujui sendiri | separate policy/resource/action; service write after persisted SuperAdmin; PostgreSQL tests |

Residual: deletion/corruption object storage setelah audited review tetapi sebelum
DB decision tidak dapat dilock atomik dengan PostgreSQL. Fingerprint mencegah app
replacement; bucket immutability/versioning dan retention monitoring perlu
operational hardening terpisah. P11c tidak mengklaim object store transactional.

## Matriks TDD

### P11c1 schema/core

- migration populated/default/roundtrip/down refusal; direct SQL negative untuk
  partial metadata, MIME, checksum, size, key traversal; model casts;
- SuperAdmin active dengan flag false diizinkan; BranchAdmin owner/foreign flag
  true, Staff flag true, Psychologist, participant, guest, deleted/revoked admin
  ditolak; unauthorized lookup tidak membedakan missing/foreign;
- manual pending approve 1 dan 10 items; consent/identity campuran; total/item/
  charge/attempt/snapshot/currency/reference/method/proof corrupt fail closed;
- partial/over amount tidak berasal browser; finalizer selalu memakai total
  canonical dan menolak allocation mismatch;
- reject reason enum, no settlement/activation/outbox/reinvoice/release;
- exact approve/reject replay; actor/proof/reason changed dan opposite decision;
  paid/expired/rejected/issuing/unknown late states;
- synthetic crash item kelima dan activation/outbox rollback;
- PostgreSQL two processes: reviewer winner/replay-or-conflict, upload-vs-review
  fingerprint fence, admin revocation serialization, runtime non-owner RLS.

### P11c2 upload/access/UI

- organization payer BranchAdmin own dan self payer participant principal upload;
  foreign/wrong payer/Staff/terminal/Xendit denied;
- JPG/PNG/PDF content valid; spoof MIME/extension, polyglot fixture, zero/oversize,
  storage failure, DB rollback, replacement cleanup failure;
- purpose-bound random private key and full metadata; no original name/PII;
- only active SuperAdmin lists reviewer records, opens URL, approves/rejects;
  branch resource remains read-only/minimal and never hydrates proof fields;
- raw proof direct URL guest/non-SA/foreign/missing all contract status; URL TTL,
  no-store/private response boundary, access throttle/audit;
- Livewire stale mounted actor/proof/record refresh; no object key, invoice URL,
  gateway ref, participant PII, rejection free-text, credential, or SQL in errors;
- order legacy upload/access/review/Filament suites unchanged.

## Pembagian increment setelah review

1. **P11c1a schema proof identity** — migration additive, model PHPDoc/casts,
   SQLite + PostgreSQL migration tests. Tidak ada upload/writer.
2. **P11c1b core review/finalizer** — decision/reason DTO, manual entrypoint pada
   finalizer, internal review action, feature + PostgreSQL concurrency tests.
   Tidak ada route/UI/storage write.
3. **P11c2a upload dan proof access boundary** — shared storage primitive bila
   reuse dapat dilakukan tanpa mengubah order behavior, bill upload/access action,
   policy, tests; endpoints masih test-only sampai review.
4. **P11c2b reviewer Filament/wiring** — resource ONCAM terpisah dan payer upload
   surface setelah portal/principal dependency siap; default-off sampai review.

Setiap increment target sekitar lima file per commit dan mempertahankan order
legacy. P11c1a memerlukan persetujuan migration baru; P11c1b memerlukan keputusan
ADR-010. **STOP: jangan implementasikan writer/UI sebelum dua keputusan itu
diterima.**
