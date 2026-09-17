# Lookup internal diterima; harness portal ditahan

2026-09-01, heartbeat lanjutkan-task-psikotes-setelah-selesai. Baseline51732af bersih.

## Review dan bukti

- Backend a1808f1 -> ce9b3a0: interface lookup eksplisit, exact reference/amount/
  currency, positive integer IDR, satu objek valid saja. Empty/ambiguous/malformed/
  mismatch/timeout/config failure tetap unknown generik tanpa previous exception.
  Tidak POST ulang, consumer baru, atau mutasi fake; PaymentInvoice bukan paid.
  Source DTO HTTPS dan parser expiry diperiksa. Parameter external_id/limit dan
  respons array dicocokkan ke [SDK resmi Xendit](https://raw.githubusercontent.com/xendit/xendit-php/master/docs/InvoiceApi.md).
  Tidak request provider nyata atau klaim uniqueness/read-after-write provider.
- Full root Unit/Feature/Architecture, XML organization-payment, exclude sandbox:
  **988 tes/5569 assertions lulus**, tanpa skip,172,279 detik. Enam halaman yang
  gagal pada worker tanpa manifest juga lulus di root; kegagalan worker tetap
  tercatat. Pint empat file PHP dan PHPStan aplikasi bersih. PG tidak diulang,
  karena tidak query/schema/RLS berubah; PG222/1659 tetap historis.
- Frontend 3fe0780 -> 12d0fd2: runner enam session terpisah, close finally,
  guard console/network dan jumlah hasil tepat. Runner/summary dibaca: **44/44
  checkpoint,12 capture geometri** lulus pada worker baseline dac8c67. Root
  syntax+ESLint runner exit0. Tidak browser ulang root; SSR27/typecheck/build
  adalah bukti wave-12 dan worker. Favicon race diperbaiki pada runner tanpa
  melemahkan assertion. Tidak source produksi berubah atau klaim backend E2E.

## Portal: temuan wajib, belum diintegrasikan

1c6afba view fokus/reflow dan 3c1dfa3 harness/helper/laporan sudah dibaca.
Mode assets memakai Vite build configFile:false tanpa envDir:false, sehingga
masih dapat membaca .env root. Worker tanpa .env bukan bukti guard ini aman.
Kedua commit ditahan. Tidak menjalankan harness portal dari root.

Satu instruksi perbaikan sudah dikirim: explicit envDir:false, probe resolved
config memakai .env sintetis temp tanpa membaca .env nyata, ulang asset build/
smoke relevan, koreksi klaim isolasi pada laporan. Fix commit terpisah; tidak
amend/reset baseline. Native20/geometry12/PHP28/319 tetap bukti worker sebelum
fix, bukan acceptance koordinator.

## Cursor dan kelanjutan idempoten

| Lane | Task | Cursor | Turn/status |
| --- | --- | --- | --- |
| Backend | 01a05839-3b48-7801-8175-0392e8764c23 | 163af1e1-5f6a-4358-82dd-3e4d2134e4ed:30 | 01a05948-c167-71d3-94fd-90a73cefc275 aktif |
| Frontend | 01a05839-3b39-7d83-b59f-9e7432d7883e | a428d2aa-49a3-4a6e-80ad-288f45f9a2ae:32 | 01a05939-3081-77f0-acd3-c3feed1adfcd selesai; menunggu P14/P15 |
| Portal | 01a05839-3b18-73e0-8fdc-8db3b02f835d | 4a41be93-41bc-4ef3-a96c-6fdb30836acb:31 | 01a05945-9c33-7472-b87a-9d1fd5da93bd aktif |

Backend menerima satu P10b-prep, hanya proposal issuance+laporan (dua dokumen):
single claim reserved->issuing, outer commit sebelum HTTP, snapshot tetap,
pending/unknown, crash sebelum/sesudah POST/persist, retry tanpa duplicate.
Audit schema/status/lock existing, guard service/scope/kanal/manual/free/terminal,
reuse provider legacy dan pembagian claim/issuance/job serta tes PG race.
Belum implementasi writer/schema sampai proposal direview. Portal fix saja;
frontend sengaja idle dependency setelah konsolidasi, jangan ulang prep.

Semua proses root selesai. Tidak .env/data/DB aktif, deploy/push/migrasi aktif,
gate/source/endpoint ON, payment/notifikasi nyata, reset/merge/stage snapshot
worker atau task/agent baru. P9 publik/P12/P16 belum end-to-end.
