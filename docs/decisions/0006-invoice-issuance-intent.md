# ADR-006: Intent penerbitan invoice dan izin create sekali pakai

## Status

Accepted for local P10b-a implementation and review only. No dispatcher, provider
request, public wiring, active data migration or production acceptance.

## Date

2026-09-01

## Context

P10a lookup read-only telah lolos full988/5569 pada wave-13. Proposal backend
cf28c72 memisahkan claim dari izin HTTP untuk mencegah dua invoice ketika queue
mengirim ulang job atau worker berhenti setelah provider menerima request.
Bill telah memiliki status reserved/issuing/unknown/pending dan outbox mempunyai
deduplication_key unik, attempts serta status. Tidak perlu schema baru untuk
increment claim internal ini. Queue/cache lock saja bukan sumber kebenaran.

## Decision

- P10b-a hanya claim internal service-only: lock organisasi -> scope bill dan
  item/referensi terkait sesuai urutan P7/P8b, validasi persisted snapshot, lalu
  atomik reserved -> issuing + satu outbox + audit. Tidak dispatch, HTTP atau
  konsumsi izin. Return message ID/keputusan bukan otorisasi pembayaran browser.
- Topic assessment.bill.invoice-issuance memakai dedup key purpose-version +
  organisasi + bill, snapshot versi1 server-only dengan reference AB_, nominal,
  currency, item linkage terurut dan hash, requested expiry serta description
  tanpa PII. Retry harus memeriksa konsistensi penuh, bukan insertOrIgnore yang
  dianggap sukses. Snapshot dan initial funding ADR-005 tidak ditulis ulang.
- Claim awal membuat pending/attempts=0. P10b-b kelak perlu review terpisah untuk
  konsumsi atomik processing/attempts=1. Izin consumed tidak pernah dikembalikan
  ke0, termasuk lease habis, timeout, lookup kosong atau crash sebelum POST.
  In-flight/unknown bukan claim baru. Intent hilang/corrupt gagal tertutup.
- Requested expiry awal memakai konfigurasi server baru
  assessment_billing.invoice_duration_hours, default24 untuk pengujian lokal,
  mengikuti CreateRegistrationInvoice existing. Validasi konfigurasi positif
  dan tanggal yang dapat direpresentasikan. Tetapkan sekali saat claim, tidak
  diperpanjang replay; ini bukan perubahan harga atau konfigurasi aktif.
- Outbox expires_at wajib terisi oleh schema. Gunakan horizon lokal dua tahun
  seperti intent aktivasi existing, BUKAN izin purge atau rearm. Retensi intent
  unresolved dan monotonic counter adalah gate operasional sebelum produksi.
  Hilangnya intent ketika bill sudah issuing/unknown tidak boleh membuat baru.
- Hanya Xendit aktif dapat claim invoice; manual_transfer not-applicable, free
  tidak ke provider. Total/count/relasi/payer/tenant/snapshot harus konsisten.
  Policy/kanal/revoke diperiksa lagi sebelum konsumsi izin kelak; invoice bukan
  settlement, consent, identity atau entitlement.

## Alternatives Considered

Ledger terpisah memberi isolasi skema lebih kuat, tetapi memerlukan migration
dan RLS baru tanpa kebutuhan pada slice claim yang memakai outbox service-only.
Menunggu lease lalu POST ulang ditolak karena tidak membuktikan request lama
belum diterima. Untuk P10b-b, reuse create legacy sekali lalu strict lookup
pilihan A adalah arah proposal, bukan izin implementasi/network pada ADR ini.

## Consequences

Crash setelah konsumsi izin tetapi sebelum POST dapat meninggalkan unknown
tanpa invoice: dipilih sebagai perilaku gagal tertutup untuk pengujian lokal,
bukan janji exactly-once atau kesiapan operasional. Recovery/operator dan
retensi perlu review sebelum aktivasi. P10b-a harus membuktikan dua proses PG,
rollback outer transaction, corrupt replay/role/scope denial dan consumer lama
tidak mengambil topic ini. P10b-b, expiry operasional, timeout job dan dispatcher
tetap checkpoint terpisah setelah bukti claim diterima; P10b belum selesai.
