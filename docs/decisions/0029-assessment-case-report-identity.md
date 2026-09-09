# ADR-0029: Identitas kasus asesmen dan laporan

## Status

Accepted

## Date

2026-09-09

## Context

Satu peserta dapat mengikuti lebih dari satu baterai psikotes. Sistem juga
menerima peserta melalui registrasi mandiri, Selection lama, dan integrasi
generik. Identitas yang ada belum menyatukan ketiga jalur itu:

- `participants` adalah identitas orang/profil, bukan satu pelaksanaan baterai;
- `assessment_participants.assessment_attempt_id` hanya tersedia pada alur
  integrasi;
- `test_sessions` adalah sesi per instrumen dan saat ini hanya terikat ke
  peserta;
- belum ada tabel laporan atau agregat persisten yang mengikat hasil F2,
  keputusan eligibility, G6, G7, narasi, tanda tangan, dan publikasi.

SPEC menetapkan hubungan Peserta–Sesi/Hasil/Laporan dan atribut laporan, tetapi
tidak menetapkan primary key, foreign key, atau kardinalitas agregat. PRD
FR13–FR20 dan G6–G8 menetapkan isi, review, versi, audit, serta lifecycle
laporan, tetapi juga tidak menetapkan identitas lintas jalur. Karena itu
`participant_id`, satu `test_sessions.id`, atau identitas integrasi tidak boleh
dipilih diam-diam sebagai akar laporan.

## Decision

Tambahkan `assessment_cases` sebagai akar universal untuk satu pelaksanaan
baterai psikotes. Satu peserta boleh memiliki banyak kasus. Identitas kasus
tidak boleh berubah setelah dibuat.

Kontrak minimum kasus:

- internal `id` dan `public_id` ULID unik;
- `participant_id` wajib;
- `organization_id`/cabang wajib dan harus konsisten dengan tenant peserta;
- `package_id` boleh `NULL` hanya selama alur lama/parsial belum memiliki paket
  yang sah; signing tidak boleh berjalan ketika paket belum terikat;
- `origin` tepat salah satu `DIRECT_PUBLIC`, `LEGACY_SELECTION`, atau
  `INTEGRATED`;
- `intended_field_snapshot` boleh belum tersedia pada profil parsial, tetapi
  setelah ditetapkan tidak dapat diganti; perubahan bidang setelah itu membuat
  kasus baru;
- timestamp pembuatan dan identitas di atas immutable.

Untuk integrasi, setiap `assessment_participants` wajib memiliki satu
`assessment_case_id` yang unik. Saat backfill, `assessment_cases.public_id`
memakai `assessment_attempt_id` yang sudah ada. Provisioning baru menghasilkan
satu ULID untuk keduanya. `assessment_attempt_id` dipertahankan sebagai alias
kompatibilitas sampai migrasi klien selesai dan database wajib menjaga
kesamaannya. Composite foreign key/unique harus membuktikan kesesuaian kasus,
peserta, organisasi, dan paket; validasi aplikasi saja tidak cukup.

Registrasi mandiri membuat kasus `DIRECT_PUBLIC` secara atomik ketika
pelaksanaan baterai dibentuk. Provisioning Selection lama membuat kasus
`LEGACY_SELECTION`. Alur lama tetap boleh hanya mengirim hasil IQ bila bukti
untuk laporan penuh belum lengkap; keberadaan kasus tidak mengarang laporan.

Setiap `test_sessions` wajib menaut ke `assessment_case_id`. Uniqueness sesi dan
attempt berpindah dari lingkup peserta menjadi lingkup
`assessment_case_id + test_type`. `participant_id` boleh dipertahankan selama
migrasi, tetapi composite foreign key harus mencegah sebuah sesi ditautkan ke
kasus milik peserta lain. Retest instrumen tetap berada di kasus yang sama;
pelaksanaan baterai baru membuat kasus baru.

Hasil integrasi generik tetap menaut ke `assessment_participants`, lalu kasus
dibaca melalui relasi satu-ke-satu tersebut. `assessment_case_id` tidak
digandakan ke tabel hasil kecuali composite foreign key database memang
diperlukan oleh reader signing.

Tepat satu stream `reports` dimiliki setiap kasus (`UNIQUE assessment_case_id`).
Perubahan isi disimpan sebagai `report_versions` append-only dengan nomor versi
unik per report, hubungan ke versi sebelumnya, content/snapshot hash, versi
engine/standar/sumber, status, serta evidence review. HPP peserta dan lembar
internal psikolog adalah artefak dari versi laporan bertanda tangan yang sama,
bukan dua identitas laporan berbeda.

Signing command kelak hanya menerima report public ID, expected report version,
expected snapshot hash, principal psikolog terautentikasi, dan opaque
idempotency key. Dalam satu transaksi service command harus:

1. mengunci kasus, report, dan versi terkini;
2. memuat ulang evidence sesi/skor/eligibility/G6/G7/narasi yang tersimpan;
3. menyusun dan memvalidasi snapshot tanpa boolean bukti dari caller;
4. menambah tepat satu versi `SIGNED`, identitas psikolog, waktu, dan audit;
5. menyimpan digest idempotency key serta request hash.

Key dan request hash yang sama mengembalikan versi signed yang sama tanpa audit
atau tanda tangan kedua. Key sama dengan hash berbeda adalah konflik. Expected
version/hash yang stale ditolak. Isi signed immutable dan publikasi selalu
menunjuk versi signed yang tepat.

## Migration order

1. Terima hardening history `instrument_versions`.
2. Tambah `assessment_cases` serta foreign key kasus yang masih nullable,
   berikut RLS fail-closed.
3. Backfill hanya dengan pemetaan deterministik. Hentikan migrasi bila sesi satu
   peserta dapat dipetakan ke lebih dari satu kasus; jangan menebak.
4. Perbarui provisioning direct, legacy, dan integrated, lalu enforce NOT NULL,
   composite FK, uniqueness, dan immutability.
5. Baru tambahkan persistence eligibility, G6, G7, report, dan signing.
6. Routes, UI, dan publikasi mengikuti setelah transactional reader diterima.

Hanya satu migration owner boleh aktif. Rollback pada history populated harus
menolak, bukan menghapus atau melemahkan proteksi.

## Alternatives considered

### Menggunakan `participant_id` sebagai report identity

Ditolak karena satu orang dapat memiliki banyak pelaksanaan baterai dan hasil
historis tidak boleh tertimpa.

### Menggunakan satu `test_sessions.id`

Ditolak karena sesi tersebut hanya mewakili satu instrumen, sedangkan laporan
merangkum seluruh baterai termasuk DASS-21 yang mempunyai lifecycle sendiri.

### Menggunakan `assessment_participants.assessment_attempt_id`

Ditolak sebagai akar universal karena tabel tersebut hanya dimiliki alur
integrasi dan tidak dibuat oleh registrasi mandiri maupun Selection lama.

### Membuat identitas terpisah untuk HPP dan lembar internal

Ditolak karena dapat membuat dua kebenaran tanda tangan/publikasi. Keduanya
harus berasal dari versi laporan signed yang sama dengan projection berbeda.

## Consequences

- Jalur mandiri, Selection lama, dan integrasi mempunyai akar baterai yang sama.
- Sesi per instrumen, hasil, review, dan laporan dapat diverifikasi tenant serta
  pemiliknya pada database.
- Bidang tujuan dan paket menjadi snapshot kasus; perubahan substantif membuat
  kasus baru dan tidak menulis ulang histori.
- Persistence F5 tetap belum boleh dibuat sampai migrasi kasus serta backfill
  ambiguity preflight lolos pada PostgreSQL disposable.
- Legacy Selection memperoleh identitas kasus, tetapi laporan penuh hanya boleh
  dibuat bila seluruh evidence PRD tersedia.
