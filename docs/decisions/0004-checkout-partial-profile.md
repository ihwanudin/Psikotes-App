# ADR-004: Profil parsial dan pembayar belum dipilih pada checkout-v2

## Status

Accepted for local implementation and review; not applied to an active database.

## Date

2026-08-31

## Context

Kontrak checkout-v2 sudah menerima profil parsial dan pilihan pembayar yang belum
ditentukan. Schema existing masih mewajibkan enam kolom profil serta funding_mode.
intended_field juga wajib, tetapi belum tersedia pada allowlist request checkout.
Temuan dan probe: tasks/organization-payment/reports/backend.md, bagian P9a.
Menyalin fallback UMUM dari v1 akan menganggap data peserta tanpa bukti.

## Decision

- Tambahkan migration prasyarat P9a0 terpisah, jangan mengubah migration historis.
  participants.full_name, gender, birth_date, education_level, intended_field,
  dan phone boleh NULL. Email sudah nullable. Nilai yang ada tidak diubah;
  tipe, panjang, enum CHECK, FK, unique/index dan RLS tetap dipertahankan.
- NULL berarti belum diketahui, bukan profil sah. Validasi registrasi publik
  dan integrasi v1 tetap mewajibkan data seperti sebelumnya. Gate assessment
  tetap menolak profil kurang lengkap; jangan memberikan entitlement legacy,
  nomor tes, credential atau notifikasi sebagai akibat penyimpanan profil parsial.
- funding_mode boleh NULL hanya jika metadata server mencatat
  checkout_contract_version=checkout-v2 dan assessment_status PROVISIONED,
  REVOKED atau VOID. Dua status terminal tersebut memungkinkan pembatalan sebelum
  pembayar dipilih tanpa memaksakan pilihan palsu. NULL dilarang pada READY,
  IN_PROGRESS, COMPLETED, UNDER_REVIEW, FINALIZED serta seluruh attempt legacy.
  Ini bukan izin membuat action pembatalan atau perubahan lifecycle baru.
- Constraint funding harus menolak metadata NULL, key hilang, versi salah dan
  JSON null; gunakan predicate yang menghasilkan false, bukan SQL UNKNOWN.
  Server tetap memverifikasi sumber dan menulis marker, bukan meneruskan metadata
  browser. CHECK ini bukan pengganti otorisasi atau bukti settlement.
- Tahap kontrak setelah review schema boleh menambah profile.intendedField
  opsional/nullable pada checkout-v2, memakai enum bidang existing. Field yang
  tidak dikirim tetap NULL, tidak diisi UMUM. V1 tidak berubah. P15 melengkapi
  hanya data yang kurang sebelum akses; data lengkap tidak diminta ulang.
- P9a selanjutnya harus membuat attempt PROVISIONED atomik/idempotent tanpa
  rights/charge/bill/invoice/credential/consent/identity verification/outbox.
  Tidak menggabungkan identitas lintas organisasi lewat email atau telepon;
  replay tidak boleh mengosongkan profil existing yang sudah terisi.
- Down migration harus menolak secara eksplisit sebelum DDL bila ada nilai NULL
  yang tidak kompatibel. Tidak menghapus baris atau mengisi data palsu agar
  rollback lolos. Uji rollback pada data lengkap dan penolakan tanpa perubahan
  data/schema pada data parsial. Operasi aktif memerlukan rencana terpisah.

## Alternatives Considered

Profil lengkap dan payer wajib pada P9a menghilangkan kontrak parsial yang sudah
disetujui, sehingga ditolak sebagai pengganti diam-diam. Placeholder nama/tanggal,
UMUM otomatis, atau payer self/organization/sentinel tanpa pilihan sah juga ditolak.
Penyimpanan staging profil kedua menambah sumber identitas dan proses pemindahan;
tidak dipilih untuk increment ini karena gate sudah memvalidasi kelengkapan.

## Consequences

Schema tidak lagi membuktikan kelengkapan profil dengan NOT NULL. Semua writer
legacy harus tetap divalidasi dan reader harus diaudit. Model PHPDoc, tipe props,
label kosong dan format tanggal perlu diperiksa sebelum route checkout dibuka.
Audit frontend berjalan terpisah; perbaikannya memerlukan review lane sendiri.
Tidak membuka gate, mengaktifkan sumber, atau menjalankan migrasi database aktif.

Acceptance P9a0: tes migration up/down dan nilai existing; profil parsial ditolak
gate; validasi legacy tetap ketat; constraint funding termasuk metadata hilang,
terminal tanpa payer dan transisi READY yang ditolak; PostgreSQL disposable
non-owner/NOBYPASSRLS tetap terisolasi antar tenant. SQLite bukan pengganti bukti PG.
Action P9a dan endpoint publik tetap belum selesai hanya karena migration lulus.

PostgreSQL menerima CHECK yang bernilai NULL, sehingga guard funding harus
menangani three-valued logic secara eksplisit.
[PostgreSQL 17 constraints](https://www.postgresql.org/docs/17/ddl-constraints.html).

## Follow-up (2026-09-21)

Diverifikasi (F2, penyelidikan gender vs start RMIB): hari ini tidak ada
jalur produksi yang membuat peserta sampai ke start RMIB dengan `gender`
null -- tapi murni karena `CheckoutParticipantProvisioningController`
(satu-satunya penulis `gender` yang boleh NULL, sesuai keputusan di atas)
belum dirute ke produksi, dan gerbang kelengkapan profil
`AssessmentAccessPrerequisites` yang seharusnya menolaknya juga belum
dirute. Bukan karena ada gerbang aktif di jalur yang sungguh dirute
(`StartParticipantSessionController`).

**Syarat untuk pekerjaan yang merutekan checkout-v2 ke produksi**: saat itu
terjadi, gerbang `AssessmentAccessPrerequisites` (atau yang setara) wajib
ikut dipasang di jalur start generik. Pembaca item RMIB yang gagal-tertutup
pada gender null (`app/Services/AssessmentSessions/RmibItemContentReader.php`,
menyusul) bukan pengganti gerbang kelengkapan profil -- ia hanya mencegah
RMIB spesifik, bukan seluruh kelengkapan profil yang dijanjikan keputusan
ini.
