# ADR-010: Verifikasi transfer manual assessment bill

## Status

Accepted untuk implementasi lokal bertahap P11c1a/P11c1b. Keputusan ini belum
mengizinkan upload, proof-access endpoint, resource Filament, route, deployment,
atau wiring produksi.

## Date

2026-09-01

## Context

`assessment_bills` sudah mempunyai `proof_object_key`, `verified_at`,
`verified_by_admin_id`, dan `rejection_reason`, tetapi belum mempunyai metadata
bukti durable seperti checksum, MIME, ukuran, dan waktu upload. Jalur order
legacy menyimpan metadata itu di JSON order; schema bill tidak mempunyai kolom
metadata. Karena itu object key saja belum cukup untuk membuktikan bukti mana
yang ditinjau atau mempertahankan kontrak upload setelah request selesai.

`FinalizeAssessmentBill` adalah satu-satunya writer settlement bill dan
memvalidasi reference, nominal, currency, seluruh item/charge/attempt, audit,
activation, dan outbox secara atomik. Input `PaymentEvent` miliknya adalah event
provider: ia mengharuskan `gateway_ref` yang cocok. Transfer manual tidak
mempunyai gateway reference dan tidak boleh menyamar sebagai callback Xendit.

Kemampuan legacy `AdminAbility::VerifyPayments` sengaja mengizinkan
BranchAdmin/Staff tertentu melalui `can_verify_payments`. Acceptance P11c lebih
sempit: bukti assessment bill hanya boleh dilihat dan diputus oleh SuperAdmin
ONCAM yang masih aktif. Resource tagihan cabang P12a-prep bersifat baca-saja,
khusus BranchAdmin pemilik, tersembunyi di luar testing, dan tidak boleh dipakai
sebagai resource reviewer.

## Proposed Decision

- Buat policy dan resource reviewer assessment bill terpisah. Authority adalah
  persisted, non-deleted `AdminRole::SuperAdmin` saja. Flag
  `can_verify_payments`, kepemilikan cabang, dan ability order legacy tidak
  mempunyai efek. SuperAdmin dapat melihat bukti manual lintas cabang; semua
  BranchAdmin, Staff, Psychologist, participant, dan guest tidak dapat melihat
  object atau memutus review.
- List reviewer yang ditolak mengembalikan forbidden; lookup record/proof/decision
  yang tidak berwenang atau tidak ada mengembalikan not-found agar keberadaan
  bill lintas tenant tidak menjadi oracle. Stale proof fingerprint bagi
  SuperAdmin yang sah menjadi conflict/validation generik, bukan not-found.
- Tambahkan migration additive sebelum writer: checksum SHA-256, MIME canonical,
  ukuran byte, dan waktu upload nullable pada `assessment_bills`. Kelima atribut
  proof termasuk `proof_object_key` harus seluruhnya NULL atau seluruhnya
  non-NULL. MIME hanya `image/jpeg`, `image/png`, atau `application/pdf`; checksum
  64 hex lowercase; ukuran 1 sampai 5.120.000 byte. Down migration menolak
  sebelum mutasi bila metadata masih terisi. Migration historis tidak diubah.
- Object key memakai namespace purpose-bound `assessment-bills/`, shard acak,
  nama acak, dan ekstensi dari MIME yang dideteksi server. Nama file, nama
  peserta, organisasi, reference bill, dan ID database tidak masuk key. Disk
  `payment-proofs` private existing dapat dipakai; URL publik dan path di log,
  response, audit, atau Livewire state dilarang.
- Pengunggah hanya pihak pembayar yang authoritative: BranchAdmin persisted untuk
  organization bill miliknya atau principal participant persisted untuk self
  bill miliknya. Mereka hanya dapat submit/replace ketika bill manual masih
  pending dan belum diverifikasi; mereka tetap tidak dapat membuka raw proof
  melalui reviewer surface atau memutus paid/rejected. Upload/public wiring
  berada di P11c2, bukan P11c1.
- Bukti yang dibuka reviewer menghasilkan fingerprint server
  `sha256(object_key + NUL + checksum + NUL + mime + NUL + size + NUL +
  uploaded_at)`. UI hanya membawa fingerprint ini kembali; object key tidak
  pernah dikirim. Keputusan mengunci dan reload row, menghitung fingerprint
  persisted lagi, dan menolak bila berbeda. Issuer temporary URL tetap private,
  pendek, diaudit, memeriksa object tersedia, dan tidak menaruh token/path di log.
- Tambahkan DTO manual yang terpisah dari `PaymentEvent`, berisi actor admin ID,
  public bill reference `AB_`, expected proof fingerprint, decision typed
  approve/reject, serta rejection code typed bila reject. Browser tidak memasok
  amount, currency, organization, paidAt, item, status, atau payment channel.
- Perluas `FinalizeAssessmentBill` dengan entrypoint manual typed. Provider entry
  existing dan bentuk audit/replay version 1 tetap kompatibel. Entrypoint manual
  memvalidasi payment method persisted tepat `manual_transfer`, proof metadata
  canonical, gateway/invoice fields NULL, lalu memakai lock, allocation checks,
  settlement, activation, dan outbox yang sama. Tidak dibuat writer paid kedua
  dan tidak dibuat provider/gateway reference palsu.
- Manual approval memakai database review time sebagai `paid_at`/`verified_at`,
  mengisi `verified_by_admin_id`, melunasi semua item, dan menulis satu audit bill
  `assessment_bill.paid` beraktor admin. Context manual versioned berisi source,
  proof fingerprint, amount/currency persisted, review time, dan item IDs; tidak
  berisi path atau PII. Audit activation P8b tetap dibuat hanya untuk attempt
  yang benar-benar aktif.
- Manual rejection memakai finalizer boundary yang sama untuk validasi/lock tetapi
  tidak menyentuh settlement, entitlement, activation, charge, atau outbox. Bill
  menjadi rejected, verifier/time dan rejection code tersimpan, dan satu audit
  `assessment_bill.rejected` beraktor admin dibuat. Rejection menggunakan enum
  aman, bukan free text, agar bounded dan tidak menyimpan PII.
- Exact replay berarti actor, proof fingerprint, decision, dan rejection code
  sama dengan audit canonical. Ia no-op tanpa audit/outbox baru. Actor lain,
  opposite decision, proof lain, atau payload replay berbeda adalah conflict.
  Paid/rejected/expired tidak dapat dihidupkan kembali atau diturunkan.
- Urutan lock manual adalah persisted admin, organization, bill, items, attempts,
  participants, packages, charges, payment method, lalu activation dependencies.
  Ini mengikuti urutan admin→organization yang sudah dipakai funding/reservation
  dan urutan organization→bill/allocation dari finalizer. Storage I/O dilakukan
  sebelum transaction permit; tidak ada S3/local read selama row lock ditahan.
- Bukti pending atau terminal tidak dihapus otomatis pada P11c. Replacement
  pending menghapus object lama setelah commit secara best-effort. Bukti terminal
  dipertahankan setidaknya sampai audit dua tahun berakhir; purge terpisah harus
  default-off, mengecualikan pending/active review, serta diaudit. Tidak ada izin
  membuat purge pada P11c.

## Compatibility

`VerifyManualTransfer`, `OrderPolicy`, `OrderResource`, upload/access route order,
`AdminAbility::VerifyPayments`, dan state machine order legacy tidak diubah.
Provider/webhook/reconciliation Xendit juga tidak berubah. Namespace object,
policy, audit source, dan typed review assessment bill terpisah sehingga flag
legacy tidak dapat menjadi authority bill.

## Alternatives Considered

### Membentuk `PaymentEvent` manual dengan gateway reference sintetis

Ditolak. Ini memalsukan provider identity, mengharuskan menulis `gateway_ref`
yang tidak pernah diterbitkan Xendit, dan merusak replay/provider reconciliation.

### Action manual menulis paid lalu memanggil activation sendiri

Ditolak. Ini menggandakan predicate total/allocation/late-state dan membuka jalur
settlement kedua di luar finalizer P11a.

### Memakai `proof_object_key` saja

Ditolak. Object key tidak membuktikan MIME, ukuran, checksum, atau versi object
yang ditinjau; mengekspornya ke browser juga membocorkan path privat.

### Memakai `can_verify_payments` atau policy order

Ditolak. Ability itu kompatibilitas order legacy dan mengizinkan role cabang;
P11c membutuhkan pemisahan tugas dengan SuperAdmin ONCAM saja.

## Consequences

P11c1 memerlukan review migration additive sebelum core writer. P11c2 memerlukan
upload/access boundary assessment khusus dan reviewer UI yang tetap default-off
sampai review wiring. PostgreSQL harus membuktikan RLS non-owner, lock race dua
reviewer, rollback item tengah, replay, dan actor revocation. Retention purge,
route publik, dan deployment tetap keputusan terpisah.
