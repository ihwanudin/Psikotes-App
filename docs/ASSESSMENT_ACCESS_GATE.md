# Gate akses per attempt — P8a

## Kontrak internal

`AssessmentEntitlementGate::assertReady(AssessmentPrincipal, testType)`
mengembalikan AssessmentEntitlement yang siap dimulai atau melempar
EntitlementLocked tanpa rincian pembayaran/identitas. Gate wajib dipanggil
dalam service RLS context; ia tidak menaikkan privilege sendiri.

AssessmentPrincipal berisi participantId, organizationId, assessmentParticipantId
positif. Caller membentuknya hanya dari autentikasi token tujuan assessment
yang sudah diverifikasi, bukan dari body, query, token checkout, atau JWT legacy
tanpa scope attempt. Tipe ini **bukan** verifier token. Penerbitan/verifikasi
token serta pemasangan controller/FormRequest start adalah pekerjaan P8b;
tidak menambahkan endpoint yang belum memiliki autentikasi tersebut di P8a.

Gate lama ParticipantEntitlementGate, login/JWT legacy, route start, sesi, dan
UI tidak berubah. Gate baru belum melindungi endpoint publik karena belum
dipasang. Return model internal tidak boleh langsung dijadikan respons publik.

## Syarat akses

1. Attempt, peserta persisted yang tidak soft-deleted, organisasi, package,
   charge dan entitlement harus cocok. Attempt wajib marker server
   checkout_contract_version=checkout-v2, status READY/IN_PROGRESS, tidak
   revoked/finalized. Hak ready per test lain dalam attempt IN_PROGRESS dapat
   diperiksa, tetapi entitlement yang sudah in_progress/done tidak boleh
   dimulai ulang melalui assertReady.
2. Price snapshot charge v1 harus sah dan cocok kolom biaya. Test type wajib
   termasuk snapshot yang dibeli, bukan daftar katalog terbaru. Perubahan
   harga/aktivasi package/policy pembayar tidak membatalkan pembayaran sah.
3. Entitlement per attempt+test_type berstatus ready, ready_at sudah berlaku,
   started_at/completed_at masih NULL. Tidak fallback ke entitlement legacy.
4. Berbayar: charge memiliki item yang cocok organisasi, peserta, payer,
   nominal IDR, dengan settled_at sudah berlaku. Bill paid dengan paid_at sudah
   berlaku, payer self cocok peserta atau organization tanpa payer peserta.
   Jumlah/total item harus cocok induk dan semua item telah dialokasikan lunas.
   Ini pemeriksaan konsistensi pembayaran, bukan pemeriksaan consent anggota lain.
5. Gratis: charge amount=0 dengan free_settled_at eksplisit yang sudah berlaku,
   tanpa bill-item berbayar. Nol saja tidak cukup; tetap wajib seluruh prasyarat.

Bill reserved/pending/expired/rejected, bill paid tanpa alokasi, atau total
yang tidak konsisten tidak membuka tes. Gate hanya membaca bukti settlement;
keaslian webhook/verifikasi transfer tetap tanggung jawab writer P10/P11.
Tidak menambah status paid, entitlement, session, token, audit atau outbox.

## Consent dan identitas

AssessmentAccessPrerequisites memeriksa prasyarat yang kelak dipakai ulang oleh
aktivasi P8b. Consent berasal dari consent_records persisted per peserta,
jenis dan versi dokumen saat ini. Status accepted, hash teks cocok,
consented_at tidak di masa depan dan withdrawn_at NULL wajib. Consent bukan
flag dari integrasi atau konsekuensi otomatis dari pembayaran.

Consent psychotest wajib; DASS-21 juga memerlukan consent dass terpisah.
Menolak/menarik DASS hanya mengunci DASS, tidak mengunci IST/PAPI/RMIB/Kraepelin.
Record consent masih per peserta+versi sesuai schema existing, bukan record
baru per attempt. Dokumen draft dan legal_review_pending existing tidak diubah;
tinjauan legal tetap gerbang rilis, bukan dinyatakan selesai oleh tes gate.

Profil harus memiliki nama, pendidikan, bidang, telepon, gender yang dikenal,
dan tanggal lahir sebelum hari ini. Ini kelengkapan data, bukan pembuktian
identitas. Verifikasi persisted harus sudah checked_at dan memenuhi salah satu:

- outcome match dengan manual_status pending; atau
- manual_status accepted, reviewed_by_admin_id terisi, reviewed_at tidak
  mendahului checked_at dan tidak di masa depan.

Manual rejected mengalahkan match. Pending/mismatch/error tanpa penerimaan
manual tetap terkunci. Dua record bukti identity_document dan initial_selfie
wajib, dengan updated_at tidak melebihi checked_at; penggantian bukti
mengharuskan verifikasi baru. Gate tidak membaca file privat atau membandingkan
wajah sendiri. Tidak mengubah logika penanda proctoring selama sesi.
Identitas terverifikasi dari sistem eksternal belum menjadi bypass di sini;
P9 harus memetakan bukti tepercaya dengan kontrak yang ditinjau, bukan metadata
browser yang mengaku verified.

## Waktu, transaksi, dan integrasi berikutnya

Timestamp ber-offset diparse sebagai waktu Carbon untuk perbandingan PHP,
bukan dibandingkan sebagai string terhadap now(). Timestamp database pada
query tetap dibandingkan oleh database. Tes PostgreSQL membekukan waktu ke
detik yang sama agar bug offset tidak tersembunyi oleh selang eksekusi.

Gate ini read-only tanpa lock dan tidak memulai sesi. Hasilnya bukan tiket
akses yang boleh disimpan lalu dipakai belakangan. P8b/start-session harus
mengunci state terkait, memuat ulang gate dan melakukan transisi mulai dalam
transaksi yang sama; jangan mengandalkan hasil check sebelum lock. Aktivasi
juga tetap membutuhkan settlement dan prasyarat per peserta, sehingga anggota
batch yang consent-nya kurang tidak menghambat anggota lain yang lengkap.

P8a diuji pada SQLite memory dan PostgreSQL disposable non-owner runtime.
Lihat [bukti verifikasi](ORGANIZATION_CHECKOUT_VALIDATION.md).
Tidak ada migrasi data aktif, invoice, WA, deploy, atau perubahan sistem eksternal.
