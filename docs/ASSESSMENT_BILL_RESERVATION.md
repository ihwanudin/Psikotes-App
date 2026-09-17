# Reservasi tagihan asesmen — P7b

## Batas layanan

`ReserveAssessmentBill::execute(principal, selection, paymentMethodId,
selectionHash, idempotencyKey)` adalah action internal, bukan endpoint publik.
Caller harus mengautentikasi principal sebelum masuk service RLS context.
Action tidak menaikkan role sendiri. Model yang dikirim harus berasal dari
identitas sesi/handoff terverifikasi, tidak dibuat dari ID/body browser.
Return berupa model bill internal; jangan langsung serialisasikan model/batch
ke peserta. Proyeksi privat dan integrasi controller/UI tetap tahap berikutnya.

Principal didukung:

- `Admin` dengan role persisted `branch_admin`: organisasi dari branch_id,
  payer organization, payer_participant_id NULL.
- `Participant` persisted: organisasi dari branch_id, payer self, hanya satu
  attempt miliknya. Model Participant bukan bukti autentikasi dengan sendirinya.

Staff, psychologist, SuperAdmin, principal hilang/soft-deleted, serta cabang
NULL ditolak. SuperAdmin tetap mengelola policy/verifikasi, bukan otomatis
bertindak sebagai cabang pembayar. Role dan cabang dimuat ulang; perubahan
atribut model dalam memori tidak mengubah scope. Replay juga memeriksa principal.

Tidak ada perubahan izin login, middleware, route, RLS, atau kanal publik.
Checkout-v2 tetap default OFF; database aktif tidak dimigrasi.

## Input dan idempotensi

`AssessmentBillSelection` digunakan bersama oleh preview dan reservasi.
Daftar harus 1..assessment_billing.max_items, tanpa duplikasi, dan setiap item
hanya berisi assessmentParticipantId integer positif serta
consultationRequested boolean. ID attempt dan urutan key object dikanonisasi.
Scope/payer/harga tidak diterima dari field item.

Hash preview adalah SHA-256 lowercase 64 karakter. Idempotency key wajib
1..128 karakter ASCII huruf/angka/colon/underscore/hyphen. Client membuat
satu key per niat konfirmasi dan mempertahankannya saat retry.

Hash request mengikat organisasi, payer, peserta pembayar jika self, seluruh
selection canonical (termasuk item gratis), hash preview, dan paymentMethodId.
Scope key mengikuti indeks P6: organisasi untuk kolektif; organisasi+peserta
untuk self. Dua admin cabang dengan niat/key/payload sama mendapat bill sama.
Key sama dengan selection, konsultasi, hash, atau kanal berbeda ditolak.

Replay mengembalikan bill existing tanpa menulis ulang harga, policy, item,
audit, reference, atau status, termasuk saat policy/kanal sudah OFF. Replay
tidak menerbitkan invoice; tahap invoice wajib mengevaluasi status bill.
Expired/rejected/paid tidak melepas claim untuk key baru. Tidak ada reinvoice,
pembatalan otomatis, atau penghapusan histori/idempotency key.

## Transaksi dan lock

Urutan untuk reservasi baru:

1. Principal admin dikunci sebelum organisasi, konsisten dengan
   UpdateFundingPolicy. Principal peserta dimuat ulang dan dikunci setelah
   organisasi; perpindahan cabang ketika menunggu ditolak.
2. Organisasi FOR UPDATE menjadi mutex transaksi untuk reservasi cabang itu,
   termasuk idempotency key atau charge yang belum ada. Cari replay di scope
   ini sebelum memeriksa policy/kanal baru.
3. Client organisasi dan source dikunci terurut ID, lalu attempt terpilih
   terurut ID, peserta terkait, package terurut ID dan package_items, kemudian
   charge terurut assessment_participant_id.
4. Kanal pembayaran dikunci. Hanya xendit/manual_transfer yang aktif diterima.
5. Preview memuat ulang policy, attempt, harga/snapshot setelah lock. Hash
   berubah atau item tidak tersedia menolak seluruh transaksi.
6. Buat satu bill reserved, charge baru untuk item berbayar yang belum punya
   charge, seluruh bill_items, dan satu audit assessment_bill.reserved.
   Seluruhnya satu transaksi; tidak ada request gateway di dalam lock.

Charge existing memakai price_snapshot/nominal/policy_snapshot asli. Evaluasi
policy terkini tetap wajib dan terikat selection_hash; tidak memperbarui
snapshot historis charge. Bill reference memakai AB_ + ULID.

Lock cabang sengaja kasar untuk menjamin transaksi dan OFF tidak berlomba,
bukan optimasi throughput maksimum. Client/source registry cabang ikut dikunci;
jumlah registry dan durasi lock perlu diukur sebelum skala besar. Package yang
sama lintas cabang juga dapat membuat reservasi saling menunggu. Writer masa
depan harus mengikuti urutan parent/child; jangan memanggil layanan ini setelah
memegang lock yang terbalik. Constraint UNIQUE charge/item/key dan FK P6 tetap
menjadi pertahanan terakhir. Error database tidak diterjemahkan menjadi sukses
atau fallback invoice baru.

Integrasi memakai transaksi PostgreSQL READ COMMITTED yang diuji. Saat menunggu
FOR UPDATE, data dibaca ulang setelah lock didapat; lock dilepas pada akhir
transaksi. Rujukan: [row-level locking PostgreSQL 17](https://www.postgresql.org/docs/17/explicit-locking.html#LOCKING-ROWS)
dan [transaction isolation](https://www.postgresql.org/docs/17/transaction-iso.html).
Jika caller sudah memiliki transaksi, commit final adalah milik caller: invoice
kelak harus dijadwalkan setelah outer commit, bukan segera setelah execute.

## Gratis, akses, dan audit

Item gratis tetap divalidasi dan masuk hash konfirmasi, tetapi tidak dibuatkan
charge/item/settlement oleh action ini. Semua item gratis menghasilkan
FREE_CHECKOUT_REQUIRED; jalur settlement gratis tetap tahap tersendiri.
Bill item_count hanya jumlah item berbayar. Konsultasi berbayar pada paket
gratis termasuk item berbayar berdasarkan total snapshot.

Tidak membuat entitlement, membuka akses, mencatat paid/free_settled_at,
membuat order legacy/outbox, atau mengirim notifikasi. Audit berisi actor,
organisasi, reference, hash, jumlah item, nominal IDR, payer dan kanal, tanpa
credential, data klinis, atau profil peserta. Retensi audit mengikuti pola
existing dua tahun. Kegagalan audit juga membatalkan reservasi.

## Kesalahan internal

- LogicException: context bukan service atau konfigurasi limit tidak valid.
- AuthorizationException BILL_PAYER_NOT_AUTHORIZED: principal tidak berhak.
- InvalidArgumentException INVALID_BILL_REQUEST / INVALID_BILL_SELECTION:
  input malformed atau melebihi batas.
- DomainException IDEMPOTENCY_CONFLICT: key dipakai untuk isi berbeda.
- DomainException PAYMENT_METHOD_NOT_AVAILABLE: kanal tidak dikenal/nonaktif.
- DomainException PREVIEW_CHANGED: harga, policy, anggota, claim atau prasyarat
  berubah/tidak layak. Tidak mengungkap identitas anggota lintas tenant.
- DomainException FREE_CHECKOUT_REQUIRED: tidak ada item berbayar.
- DomainException TOTAL_OVERFLOW: penjumlahan melampaui integer.

Controller kelak memetakan exception ke respons aman; jangan mengekspos SQL,
stack trace atau model bill kolektif ke peserta.

## Verifikasi dan tahap berikutnya

Tes feature memakai SQLite memory; tes race memakai dua proses PHP dengan
koneksi PostgreSQL runtime terpisah dan barrier socket. Sebelum melepas lock,
tes membuktikan kedua backend benar-benar wait_event_type=Lock. Ketiadaan pcntl
adalah kegagalan, bukan skip. Tidak berbagi PDO antar-fork atau memakai sleep
sebagai satu-satunya bukti overlap. Fixture sintetis di-commit khusus race dan
dibersihkan berdasarkan ID pada database disposable tanpa port publik.

Bukti perintah/jumlah tes: [ORGANIZATION_CHECKOUT_VALIDATION.md](ORGANIZATION_CHECKOUT_VALIDATION.md).
Tahap berikutnya P8a adalah gate akses per attempt, bukan aktivasi invoice.
