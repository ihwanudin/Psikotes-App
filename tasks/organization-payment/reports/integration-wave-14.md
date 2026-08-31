# Isolasi portal diterima dan claim invoice dimulai

2026-09-01; heartbeat lanjutkan-task-psikotes-setelah-selesai. Baseline3ab1e30
bersih. Ketiga task idle pada snapshot, frontend tetap menunggu P14/P15.

## Portal: rangkaian fix diterima

- Review terdahulu 1c6afba + 3c1dfa3 ditahan karena build dapat membaca env.
  Fix49c0ff4 menambahkan envDir:false dan PostCSS inline kosong secara eksplisit,
  configFile:false tetap; konfigurasi yang sama dipakai assets dan probe.
- Probe positif memuat empat marker env dari child temp sintetis; build guarded
  wajib tidak memuatnya. Trap vite.config.cjs/postcss.config.cjs tidak dieksekusi.
  Tidak membaca .env nyata. Environment proses yang diwariskan bukan cakupan
  isolasi file env; tidak mencetak secret. Source/report fix dibaca lengkap.
- Rangkaian diintegrasikan sebagai 2e42519 + 70a424b + 20cd529. View tetap hanya
  tests/Support; form association native mempertahankan fokus tombol, min-w-0
  dan shrink-0 menjaga reflow/ukuran checkbox. Tidak writer atau route publik.
- Root focused komponen/adapter/preview/portal: **89 tes/995 assertions lulus**,
  tanpa skip,7,471 detik. Pint harness, PHPStan harness+component+adapter,
  ESLint/syntax helper lulus.
- Root init SQLite baru, probe-assets dan assets build lulus. Probe membuktikan
  empat env serta dua trap config; CSS622,51kB/gzip64,22 (fixture Filament saja).
  Warning plugin timings ~98% tercatat, bukan kegagalan. Sepuluh tabel efek nol.
  DB/artefak sintetis dipertahankan untuk inspeksi, bukan data aktif:
  C:/Users/ThinkPad/AppData/Local/Temp/oncam-collective-253c5d3c02a044d38f893ad43fd9399f.
- Bukti worker sesudah fix: report.json20 native/12geometry, error/blocked/failure
  kosong; screenshot empty320 dilihat langsung. Tidak browser ulang root.
  Root tidak membuka server, browser, port atau menjalankan PG; full988/5569 dan
  PG222/1659 tetap historis. P12 preview prep diterima, bukan P12b transaksi/E2E.

## Backend proposal dan keputusan lokal

cf28c72 -> 848a396 hanya proposal+laporan, bukan runtime implementasi. Seluruh
proposal dibaca termasuk risiko crash pre-POST dan reuse legacy+strict lookup.
Skill queues/interface dan dokumentasi dipakai untuk memperjelas batas:
[ADR-006](../../../docs/decisions/0006-invoice-issuance-intent.md), commitb1dbda2.

ADR menerima P10b-a claim internal saja dengan outbox pending/attempts0, dedup
dan snapshot immutable, service/tenant/lock/kanal guard serta audit atomik.
Konfigurasi expiry lokal default24jam mengikuti registration existing; key
config baru harus divalidasi. Outbox expires_at lokal2tahun mengikuti activation,
bukan izin purge/rearm. Retensi operasional tetap gate sebelum produksi.
Consume permit, HTTP/job/dispatcher dan P10b-b belum diizinkan pada increment ini.
P10b keseluruhan tetap unchecked; proposal bukan janji exactly-once.

## Cursor dan kelanjutan tepat sekali

| Lane | Task | Cursor | Turn/status |
| --- | --- | --- | --- |
| Backend | 01a05839-3b48-7801-8175-0392e8764c23 | 163af1e1-5f6a-4358-82dd-3e4d2134e4ed:32 | 01a05957-df85-7652-9e05-4c5e70e2a324 aktif |
| Frontend | 01a05839-3b39-7d83-b59f-9e7432d7883e | a428d2aa-49a3-4a6e-80ad-288f45f9a2ae:32 | 01a05939-3081-77f0-acd3-c3feed1adfcd selesai; menunggu P14/P15 |
| Portal | 01a05839-3b18-73e0-8fdc-8db3b02f835d | 4a41be93-41bc-4ef3-a96c-6fdb30836acb:32 | 01a05945-9c33-7472-b87a-9d1fd5da93bd selesai; menunggu writer P10/P11 |

Backend menerima tepat satu instruksi claim P10b-a: action/DTO bila perlu,
feature+PG dua proses commit/rollback, corrupt replay/sum/linkage/expiry/scope,
consumer lama menolak topic. Config shared hanya invoice_duration_hours; bila
untracked kirim patch+hash. Maksimal sekitar lima file per commit, no schema/job/
HTTP/permit consumption/rights. Stop GREEN untuk review. Snapshot aktif terkonfirmasi.

Frontend dan portal tidak diberi prep berulang atau writer prematur. Heartbeat
tetap berguna karena backend berjalan. Tidak .env/DB aktif, reset/merge worker,
task/agent baru, deploy/push, gate/source ON atau pembayaran/notifikasi nyata.
Semua proses verifikasi koordinator selesai; tidak ada perubahan produksi aktif.
