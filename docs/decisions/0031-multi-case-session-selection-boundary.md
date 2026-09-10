# ADR-0031: Boundary seleksi sesi untuk banyak kasus

## Status

Accepted

## Date

2026-09-10

## Context

ADR-0029 menetapkan bahwa satu peserta dapat memiliki banyak kasus asesmen,
sementara retest instrumen tetap berada pada kasus yang sama. Skema dan runtime
lama masih memilih entitlement, sesi, dan grant terutama dari
`participant_id + test_type`. Cara itu tidak dapat membedakan dua baterai dengan
instrumen yang sama dan dapat membuat start request memilih kasus secara ambigu.

Request peserta juga tidak boleh menerima `case_id`, attempt, origin, grant, atau
selector scope lain. Semua identitas tersebut harus berasal dari credential dan
sumber izin durable di server. PRD belum menetapkan prioritas UX ketika lebih
dari satu baterai sama-sama siap, sehingga memilih yang paling lama atau paling
baru secara diam-diam belum mempunyai authority produk.

## Decision

Gunakan boundary domain murni yang menerima scope tepercaya dan sekumpulan
kandidat hasil query server. Boundary tidak membaca database dan tidak menerima
input request mentah.

Aturan seleksi:

1. Scope selalu mengikat participant, organisasi, origin, dan instrumen generik.
2. Credential integrated juga wajib mengikat tepat satu
   `assessment_participant` dan satu kasus tepercaya.
3. Kandidat dengan tenant, participant, origin, instrumen, assessment participant,
   atau kasus integrated yang tidak cocok membuat seluruh seleksi gagal tertutup.
4. Tepat satu sesi `in_progress` yang masih terikat grant durable diputar ulang.
5. Lebih dari satu sesi `in_progress` menghasilkan `history_ambiguous`.
6. Tanpa replay, tepat satu kandidat dengan grant durable yang eligible dipilih.
7. Lebih dari satu kandidat eligible menghasilkan `selection_ambiguous`; boundary
   tidak memakai FIFO atau pilihan caller.
8. Sesi `created`, `submitted`, `scored`, `expired`, atau `voided` tidak menjadi
   replay maupun izin retest.
9. History identity adalah `assessment_case + instrument`, bukan participant.
10. Kandidat retest hanya dapat eligible bila mempunyai grant retest durable.
11. DASS-21 tidak masuk boundary sesi generik.

Kontrak ini diimplementasikan oleh `AssessmentSessionSelectionPolicy` beserta
value objects bertipe. Wiring database dan HTTP tetap ditunda.

## Migration and wiring order

1. Pertahankan kontrak selection sebagai pure-domain acceptance gate.
2. Setelah migration owner tersedia, expand entitlement generik dengan case
   identity nullable dan backfill hanya dari graph order/Selection yang exact.
3. Tambahkan case-scoped uniqueness, lalu hapus constraint session lama yang
   participant-scoped hanya setelah preflight membuktikan tidak ada ambiguity.
4. Ubah guard grant agar memvalidasi exact selected case/source graph tanpa
   mengharuskan hanya satu kasus global per participant.
5. Perbarui provisioning origin menjadi dual-write per kasus.
6. Baru wire command ADR-0030 ke resolver, selection boundary, definition
   authority, allocator, dan controller untuk empat instrumen generik.
7. Persistence retest dibuat pada slice terpisah setelah aturan bisnisnya lengkap.

Setiap langkah schema harus diuji pada PostgreSQL disposable. Migration down
harus menolak bila data multi-case telah memakai kontrak baru; histori tidak
boleh dipaksa kembali ke uniqueness participant-scoped.

## Authority still required

Keputusan berikut belum dibuat oleh PRD dan tidak boleh diasumsikan:

- prioritas UX ketika beberapa baterai siap bersamaan;
- issuer/actor, alasan minimum, expiry, revocation, pembayaran, dan batas jumlah
  retest;
- apakah jeda pembekalan tiga bulan merupakan constraint mutlak;
- attempt retest mana yang menjadi sumber laporan bila ada beberapa hasil;
- identitas Selection untuk assessment round kedua;
- adjudikasi sesi historis yang belum terikat kasus.

## Alternatives considered

### Caller mengirim `case_id`

Ditolak karena memperluas permukaan IDOR dan memindahkan authority tenant/case ke
request peserta.

### Memilih kandidat siap paling lama secara FIFO

Ditunda. Urutan tersebut deterministik, tetapi belum merupakan keputusan produk
dan dapat membuka baterai yang tidak dimaksud peserta.

### Mempertahankan selection participant-scoped

Ditolak karena bertentangan dengan ADR-0029 dan menghalangi baterai kedua dengan
instrumen yang sama.

## Consequences

- Runtime dapat membedakan replay aman, kandidat tunggal, dan ambiguity tanpa
  membocorkan selector kasus ke API.
- Domain contract dapat diuji sebelum schema dan resolver diubah.
- Multi-case belum aktif sampai schema expansion, provisioning, dan wiring lulus.
- Retest tetap fail-closed sampai grant durable dan authority bisnis tersedia.
