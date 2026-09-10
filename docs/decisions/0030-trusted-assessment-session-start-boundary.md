# ADR-0030: Boundary tepercaya untuk memulai sesi asesmen

## Status

Accepted

## Date

2026-09-10

## Context

Endpoint peserta `POST /api/sessions/:test_type/start` harus mengalokasikan atau
memutar ulang satu sesi yang terikat pada kasus, entitlement, dan grant asal yang
tepat. Tiga kontrak yang sudah ada tidak dapat dipenuhi sekaligus oleh alur saat
ini:

- route `participant.jwt` dilanjutkan oleh middleware `rls`, sehingga controller
  berjalan di dalam transaksi dengan context `participant`;
- `CaseAuthorizationResolver` hanya boleh dipanggil di dalam transaksi service;
- `RlsContextRunner::runAsService` menolak peningkatan context bersarang dari
  participant ke service.

Membuka callback generik participant-ke-service akan mengubah setiap kesalahan
controller mendatang menjadi peluang elevation of privilege. Membungkus resolver
dengan service transaction buatan di test juga hanya menyembunyikan gap produksi.

## Decision

Gunakan satu application command tertutup sebagai satu-satunya boundary tepercaya
untuk start sesi generik peserta.

Kontrak command adalah:

```php
execute(
    ParticipantPrincipal $principal,
    GenericAssessmentInstrument $instrument,
): AssessmentSessionStartResult
```

Aturan boundary:

- route start tetap memakai `participant.jwt`, tetapi khusus route ini tidak
  memakai middleware `rls` dan controllernya tidak mengimplementasikan
  `RequiresRlsContext`;
- controller hanya memvalidasi `test_type`, mengambil `ParticipantPrincipal`,
  lalu memanggil command; controller tidak boleh memakai DB, Eloquent,
  `RlsContextRunner`, atau membaca entitlement;
- command menolak bila sudah ada RLS context atau transaksi aktif, lalu memiliki
  tepat satu outer `runAsService` transaction;
- di dalam transaksi yang sama command memanggil `CaseAuthorizationResolver`,
  memperoleh definisi sesi dari authority server-side, dan melakukan
  session+grant dual-write serta transisi sumber secara atomik;
- participant, branch, case, origin, grant, authorization/allocation ID,
  definition, duration, seed, dan timestamp tidak pernah diterima dari request;
- DASS-21 tidak masuk command generik karena memiliki lifecycle terisolasi;
- missing, foreign, revoked, dan stale authority dipetakan ke respons aman tanpa
  membocorkan keberadaan record lintas tenant;
- tidak ada API callback/closure generik yang dapat mengubah participant context
  menjadi service context.

Endpoint sesi lain yang membaca atau memutasi sesi yang sudah terbentuk tetap
menggunakan participant bearer dan middleware `rls` sesuai matriks akses.

## Required proof before production wiring

- architecture test membuktikan stack route start yang tepat dan controller bebas
  dari DB/Eloquent/runner;
- command menolak context/transaksi yang sudah aktif dan membersihkan context
  setelah sukses maupun exception;
- request menolak semua selector scope selain instrumen generik;
- revoke atau perubahan entitlement setelah JWT check tetap ditolak oleh resolver
  tanpa partial row;
- rollback injection setelah setiap fase meninggalkan nol session/grant parsial;
- PostgreSQL `NOBYPASSRLS` membuktikan direct participant/no-context insert ditolak;
- race same-key dan opposing-origin menghasilkan replay/winner atau penolakan yang
  deterministik sesuai kontrak;
- test arsitektur negatif menolak boundary participant-ke-service berbentuk
  callback generik.

## Alternatives considered

### Fungsi PostgreSQL `SECURITY DEFINER` yang sempit

Secara prinsip dapat mempertahankan outer participant RLS, tetapi akan
menduplikasi resolver PHP dan definition loading di SQL. Risiko drift dan beban
review lebih besar, serta bertentangan dengan checkpoint yang mewajibkan resolver
yang sudah diterima. Ditolak untuk implementasi saat ini.

### Mengizinkan nested `runAsService`

Ditolak karena merupakan primitive elevation generik dan memperbesar dampak IDOR
atau confused-deputy di controller mana pun.

### Menggunakan koneksi database kedua

Ditolak karena memecah atomicity, menyulitkan accounting context, dan resolver
serta Eloquent saat ini terikat pada koneksi utama.

## Consequences

- Start sesi menjadi pengecualian sempit pada middleware RLS, bukan pelemahan
  policy tabel; seluruh akses database tetap terjadi di command service tepercaya.
- Core allocator boleh tetap dikembangkan tanpa wiring karena signature dan
  atomicity internalnya tidak berubah.
- Wiring route/controller, architecture marker, error mapping, dan concurrency
  acceptance baru boleh dilanjutkan setelah command boundary ini tersedia.
- Jalur `AssessmentPrincipal` terintegrasi tetap belum menjadi route produksi dan
  membutuhkan keputusan autentikasi/middleware terpisah.
