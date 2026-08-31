# ADR-008: Rekonsiliasi invoice tanpa create ulang

## Status

Accepted for local P10c-a single-intent implementation and review only. No
scheduler, command registration, dispatcher, provider credential, or production
recovery is enabled.

## Date

2026-09-01

## Context

P10b dapat meninggalkan intent `processing/attempts=1` dengan bill `issuing`
ketika proses berhenti sebelum persist, atau `failed/attempts=1` dengan bill
`unknown` ketika lookup belum memberi hasil pasti. Keduanya tidak boleh menjadi
izin create kedua. P10a menyediakan lookup exact read-only.

## Decision

- P10c-a menerima satu `message_id` persisted dan hanya menangani pasangan state
  `issuing/processing/1` atau `unknown/failed/1` yang canonical. Pending/0,
  processed, paid, expired, rejected, missing/corrupt, dan scope asing gagal
  tertutup tanpa provider call.
- Entry point wajib tanpa ambient RLS context/transaksi. Lock/reload service
  memvalidasi tenant, bill/items, snapshot, payer, policy/channel/revoke, intent,
  dan counter; transaksi commit sebelum satu lookup P10a. Tidak pernah memanggil
  `createInvoice`, mereset attempts, atau memperpanjang expiry.
- Exact lookup reference/amount/currency dapat menempelkan invoice melalui satu
  persistence boundary bersama P10b agar late/terminal guards tidak diduplikasi.
  Hasil tetap bill pending + intent processed/1 + audit, tanpa settlement/akses.
- Empty, ambiguous, mismatch, timeout, dan error mempertahankan unknown/failed/1.
  State issuing/processing dipindahkan atomik ke unknown/failed dengan kode umum;
  state unknown tidak ditulis ulang berulang atau membuat audit spam.
- P10c-a belum menemukan batch dan belum memiliki command/scheduler. Discovery
  bounded, lease operasional, interval, observability, dan aktivasi scheduler
  adalah P10c-b terpisah setelah single-intent diterima.

## Consequences

Recovery dapat menemukan invoice yang sebenarnya sudah dibuat tanpa menagih ulang.
Lookup kosong tidak membuktikan create aman; kasus tetap membutuhkan petugas atau
kebijakan operasional berikutnya. Uji memakai fake/HTTP fake dan PostgreSQL dua
proses, tanpa outbound atau data aktif.
