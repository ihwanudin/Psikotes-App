# ADR-009: Durable lease untuk rekonsiliasi invoice

## Status

Accepted untuk kontrak schema lokal P10c-b1 dan pengujian saja. Command,
scheduler, provider credential, dan wiring operasional tetap tidak aktif.

## Date

2026-09-01

## Context

P10c-a merekonsiliasi satu intent dengan strict GET tanpa create ulang. P10c-b
membutuhkan discovery bounded yang dapat pulih setelah crash dan menolak hasil
worker lama. Field outbox existing tidak dapat dipakai: `available_at` adalah
bagian invariant claim, `attempts` adalah fence create, dan status/timestamp lain
sudah memiliki arti bisnis atau dipakai consumer legacy. Advisory/cache lock
tidak menjadi state durable dan tidak aman sebagai fence persistence.

Acquisition satu transaksi dengan outbox-first akan membalik urutan lock claim;
organization-first akan menunggu sebelum mencapai `SKIP LOCKED`. Karena itu
discovery dan validasi harus dipisah.

## Decision

- Tambahkan metadata additive pada `outbox_messages`: UUID nullable
  `reconciliation_lease_token`, `timestampTz` nullable
  `reconciliation_lease_expires_at`, `timestampTz` nullable
  `reconciliation_next_at`, dan `unsignedSmallInteger`
  `reconciliation_lookup_attempts` default 0 dengan CHECK PostgreSQL 0..100.
- Token dan expiry wajib sama-sama NULL atau non-NULL. Topic selain
  `assessment.bill.invoice-issuance` wajib memiliki token/expiry/next NULL dan
  counter 0. Lease aktif hanya sah untuk intent attempts=1, processed_at NULL,
  aggregate bill, dan pasangan message canonical processing/null-error atau
  failed/`INVOICE_OUTCOME_UNKNOWN`.
- Index discovery PostgreSQL bersifat partial hanya pada perbandingan konstanta
  topic/status/attempts/processed NULL. Waktu due/expiry tetap diperiksa pada
  query dengan database clock; predicate index tidak boleh memuat fungsi waktu
  volatil. SQLite membuktikan kolom, default, cast, config, dan preflight rollback;
  constraint serta concurrency database dibuktikan pada PostgreSQL disposable.
- Fase 1 memakai transaksi service outbox-only yang singkat: `FOR UPDATE SKIP
  LOCKED`, pasang UUID+expiry provisional, jangan increment counter, lalu commit.
  Token provisional bukan izin provider.
- Fase 2 memakai transaksi baru dengan urutan lock canonical organization-first.
  Setelah seluruh scope/policy/snapshot/state valid, outbox dikunci terakhir dan
  token/expiry provisional diverifikasi. Update conditional mengonsumsi token
  provisional dengan menggantinya memakai UUID permit baru, menyegarkan expiry
  dari database clock, serta menaikkan counter tepat satu sebelum permit GET
  diterbitkan. Token provisional yang direplay tidak dapat menghasilkan permit
  kedua. Hint invalid dibersihkan secara token-fenced tanpa provider,
  counter, atau audit; crash boleh menunggu expiry.
- GET selalu di luar transaksi dan RLS context. Persist exact/unknown membuka
  transaksi baru dan wajib cocok UUID permit baru, generation counter, lease belum expired,
  serta late state canonical. Worker lama tidak boleh menulis hasil.
- Config server-side awal: batch 25 (1..100), scan 100 (batch..400), lease 60
  detik (30..300), cooldown 300 detik (60..86400), maksimum lookup 12 (1..100).
  Nilai ini tidak mengaktifkan command/scheduler.
- Rolling order adalah schema nullable/default0, code default-OFF, baru wiring
  setelah P11b. Down migration menolak sebelum mutasi bila metadata lease/cooldown
  atau counter masih aktif; tidak ada penghapusan state diam-diam.

## Consequences

Worker paralel dapat memilih row berbeda tanpa menahan lock selama network call.
Crash setelah permit mengonsumsi satu lookup generation tetapi tidak pernah
membuka POST kedua. Empat kolom menambah beban pada outbox generic, sehingga
constraint topic-isolation, index sempit, kompatibilitas consumer legacy, serta
up/down pada database populated wajib diuji di PostgreSQL disposable.

P10c-b1 hanya boleh menambah schema/model/config dan bukti migrasi. Acquisition,
leased execution, command, scheduler, settlement, dan operasi nyata tetap tahap
terpisah setelah review.
