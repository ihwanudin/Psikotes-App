# Proposal — field identitas peserta opsional (F2)

**Status:** proposal, dokumen saja. **Tidak ada migration di branch ini.**
**Lane:** kanal Codex#2 (infra + dokumen). **Branch:** `codex/f2-identity-fields-proposal`.
**Baseline:** `main` `facb2bb`.
**Tanggal:** 2026-09-20.

Aturan proyek: satu pemilik migration pada satu waktu, dan giliran sekarang
milik lane GLM (`report_documents`). Dokumen ini adalah rancangan yang
menunggu giliran itu selesai — bukan izin untuk mulai menulis DDL.

## 1. Keputusan yang mendasari

Keputusan user 2026-09-20, dikonfirmasi langsung (bukan lewat relay):

> Nomor ID CPMI/SISKOP2MI, tempat lahir, level bahasa Jepang, dan Program
> yang Dituju bersifat **opsional** — kalau diisi tampil, kalau tidak
> dikosongkan — dan **tidak pernah memblokir penerbitan laporan**.

"Tidak pernah memblokir" adalah batasan yang mengikat, dan ternyata bukan
hal yang gratis: lihat §6, di mana jalur render laporan yang ada sekarang
justru akan memblokir kalau field ini ditambahkan secara naif.

## 2. Sumber

`D:\LSI\Psikotes\PSIKOTEST LSI\Update DASS\Template Laporan HPP Psikotes.docx`
(v2.3 — skala 1-5, zona Grey Area, selaras SPEC v4). Catatan jalur: prompt
dispatch sempat menyebut level `PSIKOTEST` kedua; berkasnya tidak ada di sana.

Baris yang relevan, dari Bagian I template:

| Bagian | Label ID | Label JP | Bentuk di template |
|---|---|---|---|
| I.A Identitas Peserta | Nomor ID CPMI / SISKOP2MI | CPMI 識別番号 | isian bebas |
| I.A Identitas Peserta | Tempat & Tanggal Lahir / Usia | 出生地・生年月日／年齢 | isian bebas, **tempat dan tanggal digabung dalam satu baris cetak** |
| I.A Identitas Peserta | Bahasa Jepang (level saat ini) | 日本語能力（現時点） | isian bebas |
| I.B Administrasi | Program yang Dituju | 対象制度 | kotak centang: ☐ TITP (技能実習) ☐ SSW / Tokutei Ginou (特定技能) ☐ Lainnya: ____ |
| I.B Administrasi | Bidang Kerja yang Dituju | 希望職種 | isian bebas |

"Program yang Dituju" muncul **dua kali**: di halaman sampul dan lagi di I.B.
Nilainya sama, dicetak dua kali.

## 3. Program yang Dituju ≠ Bidang Kerja yang Dituju

Template mencetak keduanya sebagai **dua baris terpisah dan berurutan** di
I.B. Keduanya dimensi berbeda dan **tidak boleh digabung ke satu kolom**:

- **Program yang Dituju** (対象制度) = skema penempatan/visa: TITP, SSW, lainnya.
- **Bidang Kerja yang Dituju** (希望職種) = bidang kerja: KAIGO, KENSETSU,
  NOUGYOU, SEIZOU, GAISHOKU, UMUM.

Di kode, bidang kerja sudah ada sebagai `participants.intended_field`, dengan
CHECK constraint atas keenam nilai itu
(`2026_08_25_000100_create_tenant_identity_tables.php:113`) dan dipakai sebagai
`target_field` di `ReportIdentity`. Skema penempatan **belum ada sama sekali**.
Menumpangkan TITP/SSW ke `intended_field` berarti mencampur dua sumbu yang
template sendiri pisahkan, dan akan merusak pemilihan standar per bidang
(T-18), karena standar bidang dipilih dari `field_code`.

Belum ditemukan dokumen psikolog yang menyiratkan sebaliknya. Kalau nanti ada,
laporkan ke Lead untuk keputusan bersama — jangan diputuskan di lane ini.

## 4. Kolom yang diusulkan

### 4.1 `participants` — melekat pada orangnya

| Kolom | Tipe | Null | Catatan |
|---|---|---|---|
| `cpmi_id_number` | `string(64)` | ya | Nomor ID CPMI / SISKOP2MI. Disimpan apa adanya sebagai teks; jangan diberi CHECK format sampai psikolog/operasional memastikan bentuk resminya. Bukan unik — dua peserta bisa sama-sama kosong, dan sepanjang formatnya belum dipastikan, unik adalah janji yang belum bisa ditepati. |
| `birth_place` | `string(120)` | ya | Tempat lahir. **Kolom terpisah dari `birth_date` yang sudah ada** — template menggabungkannya hanya saat dicetak (§5.2), bukan saat disimpan. |
| `japanese_level` | `string(32)` | ya | Level bahasa Jepang saat ini. Teks bebas, bukan enum — lihat §7 butir 2. |
| `intended_program` | `string(16)` | ya | Skema penempatan. CHECK `IN ('TITP','SSW','OTHER')`. |
| `intended_program_other` | `string(120)` | ya | Isi dari "Lainnya: ____". Wajib terisi bila `intended_program = 'OTHER'`, wajib NULL selain itu. |

CHECK yang diusulkan untuk pasangan terakhir (PostgreSQL; ikuti pola
`addPostgresConstraints()` yang sudah ada di migration tenant identity):

```sql
ALTER TABLE participants ADD CONSTRAINT participants_intended_program_check
  CHECK (intended_program IS NULL OR intended_program IN ('TITP','SSW','OTHER'));

ALTER TABLE participants ADD CONSTRAINT participants_intended_program_other_check
  CHECK (
    (intended_program = 'OTHER' AND intended_program_other IS NOT NULL)
    OR (intended_program IS DISTINCT FROM 'OTHER' AND intended_program_other IS NULL)
  );
```

Perhatikan `IS DISTINCT FROM`, bukan `<>`: dengan `<>` baris yang
`intended_program IS NULL` membuat seluruh CHECK bernilai NULL dan lolos,
sehingga `intended_program_other` bisa terisi tanpa program — persis kasus
"kosong" yang paling sering terjadi di sini.

**Kenapa nullable, dan kenapa itu tidak melemahkan apa pun.** Enam kolom
profil yang ada (`full_name`, `gender`, `birth_date`, `education_level`,
`intended_field`, `phone`) **sudah nullable di database** sejak
`2026_08_31_000600_allow_checkout_partial_profiles.php`, yang sengaja
melepas NOT NULL supaya checkout boleh menyimpan profil parsial. Kewajiban
mengisinya ditegakkan di lapisan aplikasi
(`StoreParticipantRegistrationRequest::rules()` menandai keenamnya
`required`). Jadi lima kolom baru ini mengikuti pola yang sudah berlaku:
nullable di DB, dan **tanpa** aturan `required` di Form Request. Tidak ada
constraint lama yang dilonggarkan.

### 4.2 `assessment_cases` — snapshot per kasus

| Kolom | Tipe | Null | Catatan |
|---|---|---|---|
| `intended_program_snapshot` | `string(16)` | ya | Sejajar dengan `intended_field_snapshot` yang sudah ada. |
| `intended_program_other_snapshot` | `string(120)` | ya | Ikut, supaya "Lainnya" tidak kehilangan maknanya. |

Alasannya bukan simetri estetis. `assessment_cases` sudah menyimpan
`intended_field_snapshot` (`2026_09_09_000200_create_assessment_cases.php:23`)
karena bidang tujuan peserta bisa berubah sesudah kasus dibuat, sementara
laporan harus mencetak nilai **yang berlaku saat pemeriksaan**. Program yang
Dituju punya sifat yang sama persis: peserta yang gagal TITP lalu mendaftar
ulang untuk SSW tidak boleh membuat laporan lamanya berubah isi. Preseden ini
sudah ada di repo; usulan ini hanya mengikutinya.

**Peringatan biaya — snapshot itu dijaga trigger, bukan sekadar kolom.**
`intended_field_snapshot` dilindungi trigger imutabilitas di migration yang
sama (baris 127-134 untuk PostgreSQL, 205-212 untuk SQLite): nilainya hanya
boleh berpindah **sekali** dari NULL ke non-NULL, tidak pernah berubah nilai
dan tidak pernah dikosongkan lagi; UPDATE yang melanggar memicu
`RAISE EXCEPTION 'assessment case history is immutable'`. Klausa terakhirnya
juga menolak UPDATE yang tidak mengubah `package_id` maupun snapshot itu.

Jadi menambahkan `intended_program_snapshot` **bukan** sekadar `ADD COLUMN`:
trigger itu harus ikut diperluas, di dua dialek, atau kolom baru akan
menjadi satu-satunya bagian riwayat kasus yang boleh diubah diam-diam
sesudah kasus terbentuk. Ini pekerjaan pemilik migration dan perlu
diperhitungkan saat menaksir ukurannya. Alternatif yang lebih murah dan
harus dipertimbangkan lebih dulu: **jangan di-snapshot sama sekali**, dan
biarkan laporan membaca nilai dari peserta — dengan konsekuensi laporan lama
ikut berubah bila program peserta berubah. Untuk dokumen resmi yang sudah
ditandatangani psikolog (G5), konsekuensi itu kemungkinan besar tidak bisa
diterima, jadi rekomendasi dokumen ini tetap snapshot + perluasan trigger.
Keputusan akhirnya milik Lead bersama pemilik migration.

Tiga kolom lain (`cpmi_id_number`, `birth_place`, `japanese_level`) **tidak**
di-snapshot. Nomor ID dan tempat lahir praktis tidak berubah. Level bahasa
Jepang memang berubah, tapi laporan HPP mencetaknya sebagai "level saat ini"
(現時点) dan tidak ada aspek skoring yang bergantung padanya — snapshot-nya
menambah kolom tanpa menambah jaminan. Kalau psikolog kemudian menginginkan
level bahasa terkunci pada tanggal pemeriksaan, itu perubahan sadar yang
perlu keputusan tersendiri, bukan asumsi diam-diam di sini.

## 5. Tampilan di HPP

### 5.1 Aturan umum saat kosong

Kosongkan **nilainya**, pertahankan **barisnya**. Template adalah formulir
resmi dengan garis isian; baris yang hilang membuat laporan tidak lagi cocok
dengan template dan menyulitkan verifikasi pihak ketiga. Render sebagai
garis kosong / tanda baca netral, bukan `-`, `N/A`, `null`, atau teks yang
bisa terbaca sebagai temuan pemeriksaan.

Yang **tidak boleh** dilakukan: menyembunyikan baris, mengganti dengan
"tidak tersedia", atau memunculkan peringatan. Field ini administratif, bukan
hasil pemeriksaan; ketiadaannya tidak berarti apa-apa secara psikologis dan
tidak boleh dibingkai seolah berarti.

### 5.2 Tempat & tanggal lahir

Template mencetak satu baris "Tempat & Tanggal Lahir / Usia". Perakitannya
di lapisan penyaji, dari dua kolom terpisah:

- keduanya ada → `{birth_place}, {birth_date} ({usia} th)`
- hanya tanggal → `{birth_date} ({usia} th)`, tanpa koma menggantung
- hanya tempat → `{birth_place}`
- keduanya kosong → garis kosong

Koma menggantung adalah cacat yang paling mungkin lolos ke laporan resmi;
ini harus punya test sendiri.

### 5.3 Program yang Dituju

Template memakai kotak centang, bukan teks. Saat dirender, yang tercentang
adalah kotak yang sesuai; bila `intended_program` NULL, **tidak ada** kotak
tercentang dan barisnya tetap tercetak utuh. Bila `OTHER`, kotak "Lainnya"
tercentang dan `intended_program_other` mengisi garisnya. Nilai ini dicetak
di dua tempat (sampul dan I.B) dan keduanya harus berasal dari satu sumber
yang sama agar tidak pernah berbeda.

## 6. Risiko integrasi utama — `ReportIdentity` sekarang fail-closed

Ini bagian yang paling perlu perhatian reviewer, dan alasan dokumen ini ada
sebelum ada kodenya.

`app/Domain/Report/ReportIdentity.php` pada `facb2bb`:

- `fromArray()` menolak input yang jumlah kuncinya **tidak persis** sembilan
  (`count($input) !== count(self::FIELD_KEYS)` → `InvalidArgumentException`);
- setiap field wajib string dan **tidak boleh kosong setelah `trim()`**;
- `target_field` wajib salah satu dari enam bidang.

Artinya, kalau lima field opsional ini ditambahkan ke `FIELD_KEYS`, maka
setiap peserta yang tidak mengisinya akan membuat pembentukan
`ReportIdentity` melempar exception, dan **laporannya tidak bisa terbit** —
kebalikan persis dari keputusan user di §1.

Sifat fail-closed itu sendiri benar dan jangan dilemahkan: sembilan field
yang ada memang wajib, dan mengendurkannya menjadi "boleh kosong" akan
membuat laporan bisa terbit tanpa nama atau tanpa nomor laporan. Jadi
usulannya **bukan** melonggarkan `FIELD_KEYS`, melainkan menambah jalur
kedua yang terpisah:

- `FIELD_KEYS` dan semua pemeriksaannya **tidak berubah**;
- tambahkan wadah terpisah untuk field opsional — misalnya konstanta
  `OPTIONAL_FIELD_KEYS` dengan penyimpanan sendiri, diterima lewat argumen
  kedua yang punya nilai bawaan array kosong, sehingga **seluruh pemanggil
  yang ada sekarang tetap sah tanpa diubah**;
- wadah itu tidak dikenai pemeriksaan jumlah, dan nilai kosong/NULL adalah
  masukan yang sah — bukan error;
- kunci yang tidak dikenal tetap ditolak, supaya jalur opsional tidak
  berubah menjadi kantong data bebas.

Keputusan desain ini milik lane F6/GLM (`app/Domain/Report/**` bukan milik
kanal ini). Yang dokumen ini minta hanya satu: **jangan menyelesaikannya
dengan melonggarkan validasi yang sudah ada.**

Konsekuensi lain untuk pemiliknya:
`resources/views/reports/partials/identity.blade.php` saat ini mencetak
sembilan field itu tanpa penjagaan kosong — wajar, karena semuanya dijamin
terisi. Baris opsional yang baru **harus** punya penjagaan sendiri.

## 7. Dampak ke alur registrasi

Ada **empat** jalur pembuatan peserta di `facb2bb`, dan semuanya harus
dipertimbangkan — bukan hanya formulir publik:

| Jalur | Berkas | Dampak |
|---|---|---|
| Registrasi publik | `StoreParticipantRegistrationRequest`, `RegisterParticipant` | Tambah lima aturan validasi, semuanya `nullable`. Tambah lima field ke formulir Inertia, ditandai opsional. |
| Provisioning assessment | `ProvisionAssessmentParticipantRequest` | Terima bila dikirim, jangan wajibkan. |
| Provisioning checkout | `ProvisionCheckoutParticipantRequest` | Sama; jalur ini memang sengaja mengizinkan profil parsial. |
| Provisioning selection | `ProvisionSelectionParticipantRequest` | Sama. |

Catatan lain:

1. **`Participant::$fillable`** perlu menampung lima kolom baru, jika tidak
   nilai yang dikirim akan hilang diam-diam tanpa error — kegagalan yang
   sulit terlihat justru karena tidak ada yang meledak.
2. **`japanese_level` sebaiknya teks bebas dulu, bukan enum.** Template
   menyediakan isian bebas, dan ekosistemnya memakai beberapa skala yang
   tidak saling memetakan rapi (JLPT N5-N1, JFT-Basic, penilaian internal
   LPK). Mengunci enum sekarang berarti menebak salah satunya. Kalau
   psikolog kemudian menetapkan daftar resmi, enum bisa ditambahkan dengan
   CHECK menyusul — arah itu jauh lebih mudah daripada membongkar enum yang
   sudah telanjur salah.
3. **Kerahasiaan.** Nomor ID CPMI/SISKOP2MI adalah identitas pemerintah dan
   masuk lingkup UU 27/2022 yang sudah dikutip template. Perlu diperiksa
   terhadap `tools/security/repository-content-scan.mjs` (pemindai PII) agar
   tidak ada nilai contoh yang menyerupai nomor asli masuk ke seeder atau
   fixture. Semua data uji harus sintetis.
4. **RLS.** Kolom baru ikut kebijakan RLS `participants` yang sudah ada;
   tidak ada policy baru yang diusulkan. Tapi itu perlu dibuktikan runtime,
   bukan diasumsikan.
5. **Ekspor.** `AssessmentParticipantExportController` perlu ditinjau
   pemiliknya: apakah kolom baru ikut diekspor, dan apakah nomor ID CPMI
   boleh keluar ke penerima ekspor. Ini pertanyaan kebijakan akses, bukan
   pertanyaan teknis, dan bukan milik kanal ini.
6. **`Nomor Laporan`** juga muncul di template (sampul dan I.A) dan **sudah**
   ada di `ReportIdentity` sebagai `report_number`. Itu identitas dokumen,
   bukan identitas peserta, dan sudah wajib. Tidak termasuk usulan ini.

## 8. Yang sengaja TIDAK dilakukan di sini

- Tidak ada migration, tidak ada perubahan kode. Giliran pemilik migration
  ada di lane GLM (`report_documents`).
- Tidak mengubah `intended_field` maupun CHECK-nya.
- Tidak mengubah `ReportIdentity` atau blade laporan — itu milik lane F6/GLM.
- Tidak mengusulkan indeks baru. Tidak satu pun kolom ini diketahui menjadi
  kriteria pencarian, dan indeks tanpa pembaca adalah biaya tulis tanpa
  manfaat. Kalau pencarian berdasarkan nomor ID CPMI nanti dibutuhkan,
  indeksnya diusulkan bersama pembacanya.

## 9. Test yang harus menyertai implementasinya nanti

Bukan daftar keinginan — ini syarat agar janji "tidak pernah memblokir"
benar-benar terbukti, bukan sekadar dinyatakan:

1. Laporan **terbit** untuk peserta yang kelima field opsionalnya kosong.
   Ini test terpenting; tanpa ini keputusan user di §1 tidak terjaga.
2. Laporan terbit untuk peserta yang mengisi sebagian saja.
3. Perakitan "Tempat & Tanggal Lahir" benar di keempat kombinasi §5.2,
   termasuk tidak ada koma menggantung.
4. `intended_program = 'OTHER'` tanpa `intended_program_other` **ditolak**
   database, dan `intended_program_other` terisi tanpa `intended_program`
   juga ditolak (kasus `IS DISTINCT FROM` di §4.1).
5. `intended_field` dan `intended_program` tetap dua kolom independen:
   mengubah salah satunya tidak menyentuh yang lain, dan pemilihan standar
   per bidang (T-18) tetap membaca `intended_field`.
6. Snapshot kasus tidak berubah ketika `participants.intended_program`
   diubah setelah kasus dibuat.
7. Trigger imutabilitas kasus benar-benar menolak UPDATE yang mengubah
   `intended_program_snapshot` dari satu nilai ke nilai lain, dan yang
   mengosongkannya kembali ke NULL — dibuktikan lewat UPDATE sungguhan yang
   gagal, bukan lewat pembacaan definisi trigger. Sekaligus buktikan bahwa
   perluasan trigger tidak melonggarkan penjagaan `intended_field_snapshot`
   yang sudah ada.
8. Dijalankan pada PostgreSQL sungguhan, bukan hanya SQLite — CHECK
   `IS DISTINCT FROM` dan perilaku RLS tidak terbukti di SQLite.

## 10. Pertanyaan terbuka untuk psikolog/user

1. Apakah nomor ID CPMI/SISKOP2MI punya format resmi yang tetap (panjang,
   prefiks)? Selama belum dipastikan, tidak ada CHECK format yang diusulkan.
2. Apakah "Bahasa Jepang (level saat ini)" harus dikunci ke daftar resmi
   (JLPT/JFT) atau tetap teks bebas seperti di template?
3. Apakah nomor ID CPMI boleh tampil ke admin/LPK/kumiai, atau mengikuti
   pembatasan yang sama dengan Lembar Kerja Internal (psikolog + peserta
   saja)? CLAUDE.md membatasi Lembar Kerja Internal dan data DASS, tapi
   tidak menyebut nomor identitas pemerintah secara eksplisit.
