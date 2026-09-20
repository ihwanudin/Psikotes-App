# Diagnosis — deadlock pada review transfer manual bersamaan

**Tanggal:** 2026-09-20. **Diagnosis oleh:** kanal Codex#2 (infra + dokumen).
**Status:** mekanisme teridentifikasi dan terbukti. **Perbaikan BELUM dipilih.**
**Pemilik perbaikan:** lane DeepSeek. Dokumen ini tidak memilih salah satu opsi.

Baseline: `main` `facb2bb`. Semua bukti di bawah dihasilkan ulang sendiri oleh
penulis dokumen, bukan disalin dari laporan pihak lain.

## 1. Gejala

Dua test gagal di `tests/Postgres/AssessmentBillManualReviewTest.php`:

| Test | Baris | Pesan |
|---|---|---|
| `test_same_review_in_two_runtime_processes_has_one_settlement_audit_and_outbox` | 106 | `Failed asserting that two arrays are identical` |
| `test_opposite_reviews_in_two_processes_commit_only_one_terminal_decision` | 122 | `Failed asserting that 0 is identical to 1` |

Diff test pertama:

```
 Array &0 [
-    0 => 'replayed',
+    0 => null,
     1 => 'settled',
 ]
```

Deterministik, dan reproduksi dengan pesan **identik** di dua lingkungan yang
berbeda jauh: runner Linux GitHub Actions (run `35487920258`) dan Docker di mesin
Windows. Bukan flaky.

## 2. Penyebab — string error sungguhan dari log server PostgreSQL

Bukan inferensi dari bentuk assertion. Diambil dari `docker logs` container
PostgreSQL yang sengaja tidak dihapus setelah run:

```
ERROR:  deadlock detected
DETAIL:  Process 96 waits for ShareLock on transaction 899; blocked by process 95.
         Process 95 waits for ShareLock on transaction 897; blocked by process 96.
         Process 96: select * from "assessment_bills"
                     where "organization_id" = $1 and "public_reference" = $2 limit 1 for update
         Process 95: insert into "commission_ledger_gaps" ("source_type", "source_id", "branch_id",
                     "reason_code", "paid_at", "currency", "amount", "context", "status",
                     "resolved_at", "created_at", "updated_at") values (...)
CONTEXT:  while locking tuple (0,16) in relation "assessment_bills"
ERROR:  current transaction is aborted, commands ignored until end of transaction block
```

Muncul dua kali, satu per test yang gagal, dengan bentuk siklus yang sama.

**Karena itu pihak yang kalah tidak pernah mengembalikan `ASSESSMENT_BILL_REVIEW_CONFLICT`
maupun `'replayed'`:** transaksinya dibatalkan PostgreSQL sebagai korban deadlock
**sebelum** logika konflik aplikasi sempat berjalan. Assertion test mengharapkan
kekalahan yang rapi; yang datang adalah pembatalan paksa.

## 3. Mekanisme — inversi urutan penguncian

Dua sumber daya, dikunci dalam urutan berlawanan oleh dua transaksi:

1. **Baris `assessment_bills`** — dikunci `FOR UPDATE` di
   `app/Actions/Payments/FinalizeAssessmentBill.php:202`.
2. **Kunci unik `commission_ledger_gaps`** — `INSERT` di
   `app/Services/Commissions/RecordBranchCommissionLedger.php`. Tabelnya punya
   `unique(['source_type','source_id'])`
   (`database/migrations/2026_09_17_000200_create_commission_ledger_gaps.php:28`).

Kedua review bersamaan menyasar tagihan yang sama, sehingga keduanya menghasilkan
`(source_type, source_id)` yang sama. `INSERT` kedua tidak gagal langsung — ia
**menunggu transaksi pertama selesai** (`ShareLock` pada transaction id), karena
PostgreSQL belum tahu apakah baris pertama akan di-commit atau di-rollback.

Siklusnya, sesuai log:

- Proses 96 sudah menyisipkan baris gap (belum commit), lalu meminta lock baris
  `assessment_bills` — yang dipegang proses 95.
- Proses 95 memegang lock baris tagihan, lalu menyisipkan baris gap duplikat —
  sehingga menunggu proses 96.

Kedua transaksi menjalankan **kode yang sama**, tetapi mencapai kedua sumber daya
dalam urutan berbeda, sehingga disiplin `orderBy('id')` yang sudah dipakai konsisten
di `FinalizeAssessmentBill` tidak menolong: disiplin itu menyeragamkan urutan **antar
baris dalam satu tabel**, bukan urutan **antar tabel** terhadap kunci unik di tabel
lain.

## 4. Jawaban atas pertanyaan penentu: PRODUKSI, bukan fixture

Pertanyaan yang diajukan Lead: apakah deadlock menyentuh jalur kode produksi, atau
hanya jalur fixture `setUp`/`tearDown` milik test?

**Jalur produksi, dan dua tabel produksi.** Tidak ada tabel fixture dalam siklus.
Kedua statement dalam `DETAIL` berasal dari kode aplikasi:
`FinalizeAssessmentBill` (dipanggil `ReviewAssessmentBillTransfer`) dan
`RecordBranchCommissionLedger`.

**Implikasi operasional:** dua admin yang mereview tagihan transfer manual yang sama
secara bersamaan dapat membuat salah satunya gagal dengan error deadlock, alih-alih
menerima pesan konflik yang wajar. Ini bukan kondisi yang hanya bisa terjadi di test.

**Yang TIDAK terjadi**, dan ini membatasi keparahannya:

- tetap **tepat satu** keputusan ter-commit — serialisasi tidak bocor;
- transaksi yang kalah di-rollback bersih oleh PostgreSQL, tidak ada tulisan separuh jalan;
- tidak ada review yang hilang tanpa keputusan.

Jadi: bukan kehilangan uang atau kerusakan data. Yang nyata adalah **kegagalan
bermutu buruk pada jalur tagihan produksi**, yang akan muncul ke pengguna sebagai
error teknis.

## 5. Reproduksi — satu baris

```
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1 -Filter '(test_ambient_service_and_user_contexts_cannot_enter_handler|AssessmentBillManualReviewTest)'
```

Hasil: `Tests: 4, Assertions: 52, Failures: 2` — kedua kegagalan di §1.

Untuk melihat error sisi server, replikasi langkah harness secara manual dan jangan
hapus container database, lalu `docker logs <container>`. Harness resmi menghapus
containernya, sehingga log servernya ikut hilang — itu sebabnya penyebab ini tidak
pernah terlihat sebelumnya.

## 6. Penyempitan: dari 610 test menjadi 4

Setiap langkah dicocokkan aritmetikanya agar tidak ada test yang tersaring diam-diam.

| Kombinasi | Test | Assertion | Hasil |
|---|---|---|---|
| Suite penuh | 610 | 6042 | 2 failure (+2 error rollback, +2 failure lease-validation di lokal) |
| `AssessmentBillInvoiceIssuanceTest` + `AssessmentBillItemsSchemaTest` + `AssessmentBillManualReviewTest` | 41 | 299 | 2 failure sama |
| `AssessmentBillInvoiceIssuanceTest` + `AssessmentBillManualReviewTest` | 9 | 156 | 2 failure sama |
| **1 test dari `AssessmentBillInvoiceIssuanceTest` + `AssessmentBillManualReviewTest`** | **4** | **52** | **2 failure sama** |
| `AssessmentBillManualReviewTest` saja | 3 | 43 | **OK** |

Cek aritmetika: 6+32+3 = 41 ✓, 6+3 = 9 ✓, 1+3 = 4 ✓.

## 7. Dua cabang yang sudah tertutup

1. **Bukan test tertentu.** Dua test berbeda dari `AssessmentBillInvoiceIssuanceTest`
   sama-sama memicu (`test_two_runtime_processes_have_one_permit_winner_and_one_create`
   dan `test_ambient_service_and_user_contexts_cannot_enter_handler`). Pemicunya ada di
   jalur `setUp`/`tearDown` yang dipakai bersama seluruh kelas.
2. **Fork bukan bahan yang diperlukan di sisi pemicu.** Test kedua di atas tidak
   mem-fork dan tetap memicu. Yang mem-fork hanya korbannya.

Satu hipotesis lain juga sudah **digugurkan dengan percobaan**, dicatat di sini agar
tidak diulang: dua error rollback `assessment_cases` (`cannot drop table ... because
other objects depend on it`) **bukan** penyebabnya. Kombinasi dua kelas migrasi yang
error itu + `AssessmentBillManualReviewTest` menghasilkan `Tests: 10, Assertions: 143,
Errors: 2, Failures: 0` — kedua error terjadi, manual review tetap lulus.

## 8. Yang masih terbuka

**Kenapa deadlock ini hanya muncul setelah `AssessmentBillInvoiceIssuanceTest`
berjalan lebih dulu, dan tidak muncul saat `AssessmentBillManualReviewTest` berjalan
sendiri?**

Belum diuji, jadi **tidak diajukan sebagai jawaban**. Satu pengamatan yang bisa jadi
titik awal bagi yang melanjutkan: `setUp` kelas pemicu membuat 10 fixture, sementara
`tearDown`-nya menghapus bills, items, charges, audit_logs, admins, dan payment_methods
— tetapi **tidak** menghapus peserta, attempt, entitlement, maupun organisasinya. Itu
satu-satunya keadaan yang jelas tidak dipulihkan; jam uji, config, `Http`, dan
`PaymentProvider` semuanya dikembalikan dengan benar di `tearDown`.

Dugaan yang perlu diuji, bukan diasumsikan: apakah sisa data itu yang membuat jalur
`RecordBranchCommissionLedger` menyisipkan baris gap (sehingga sumber daya kedua dalam
siklus baru ada), padahal tanpa sisa itu jalur gap tidak pernah tersentuh.

Catatan: pertanyaan ini menyangkut **kapan deadlock terlihat**, bukan **apakah deadlock
mungkin**. Inversi urutan di §3 ada di kode produksi tanpa bergantung pada test apa pun.

## 9. Kemungkinan arah perbaikan — TIDAK dipilih di dokumen ini

Pemilihan milik lane DeepSeek. Didaftar tanpa urutan preferensi, dengan konsekuensi
masing-masing:

1. **Samakan urutan akuisisi.** Pastikan baris `assessment_bills` selalu dikunci
   sebelum apa pun yang menyentuh kunci unik `commission_ledger_gaps`, di semua jalur.
   Menghilangkan siklusnya di akar; menuntut audit semua pemanggil, bukan satu berkas.
2. **Jadikan penyisipan gap idempoten tanpa menunggu.** Misalnya `INSERT ... ON
   CONFLICT DO NOTHING`, sehingga penyisip kedua tidak pernah menunggu transaksi
   pertama. Perlu dipastikan lebih dulu bahwa "tidak melakukan apa-apa" memang benar
   secara akuntansi untuk gap yang sama.
3. **Retry pada kegagalan deadlock.** Tangkap SQLSTATE `40P01` dan ulangi transaksi.
   Menyembuhkan gejala tanpa menghilangkan inversinya, dan menambah jalur yang sendiri
   perlu diuji.
4. **Pindahkan pencatatan gap keluar dari transaksi review**, misalnya lewat outbox
   yang sudah ada. Menghilangkan sumber daya kedua dari transaksi; mengubah jaminan
   keserentakan pencatatan komisi.
5. **Ubah ekspektasi test saja.** Menerima kekalahan berupa deadlock sebagai hasil yang
   sah. **Perlu ditandai jelas:** ini menyembunyikan cacat produksi di §4, bukan
   memperbaikinya, dan tidak boleh dipilih hanya untuk menghijaukan CI.

Apa pun yang dipilih, dua hal sebaiknya dipertahankan: assertion yang ada **tidak
dilonggarkan** tanpa alasan tertulis, dan perbaikannya dibuktikan dengan menjalankan
ulang resep §5 hingga lulus, lalu suite utuh.

## 10. Temuan sampingan yang ikut tercatat

- **Lokal menghasilkan 6 failure, CI 4.** Dua tambahan ada di
  `AssessmentInvoiceReconciliationLeaseValidationTest`, dengan sebab berbeda:
  `Failed asserting that false is identical to 'lookup\n'` — `false` dari pembacaan
  kanal berarti EOF, yaitu anak proses mati tanpa menulis. **Bentuk gejala berbeda**
  dari kasus manual review (di sana anak hidup dan mengirim payload error). Belum
  didiagnosis; jangan disatukan dengan dokumen ini tanpa bukti.
- **Job CI PostgreSQL tidak menyimpan detail kegagalan.** `--debug` menggantikan
  ringkasan PHPUnit, dan XML JUnit yang sudah dihasilkan tidak diunggah
  (`artifacts.total_count: 0` pada run `35487920258`). Seluruh diagnosis ini menuntut
  reproduksi lokal karena itu. Sedang ditangani terpisah.
