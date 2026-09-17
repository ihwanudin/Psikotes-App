# Preview biaya asesmen — P7a

## Kontrak internal

PreviewAssessmentBill::execute(organizationId, selection, payerType, participantId)
memerlukan service RLS context yang sudah dibentuk pemanggil terautentikasi.
organizationId dan participantId berasal dari principal server, bukan dipercaya
dari HTTP. Belum ada route/controller/UI baru; autentikasi panel/checkout kelak
harus memetakan principal sebelum memanggil layanan ini. Action tidak menaikkan
izin pengguna sendiri dan tidak mengaktifkan flag checkout.

selection adalah list 1..config assessment_billing.max_items (default 100),
setiap item tepat assessmentParticipantId integer positif dan
consultationRequested boolean. Duplikasi, field nominal/status tambahan, tipe
longgar, atau self dengan lebih dari satu item ditolak InvalidArgumentException.
Organization payer memakai participantId NULL; self wajib participantId positif.
Konfigurasi limit invalid gagal tertutup. Tidak ada pemecahan batch diam-diam.

Attempt harus PROVISIONED, tidak revoked/finalized, peserta masih ada dalam
organisasi itu, dan metadata.checkout_contract_version = checkout-v2.
Source registry client/source_system harus checkout-v2 dan funding-policy
P3 mengizinkan payer/paket pada waktu preview. Marker attempt ditetapkan server
pada provisioning P9, bukan diisi browser; registry v2 saja tidak mengonversi
attempt legacy menjadi tagihan baru. Attempt asing/tidak ada/peserta asing
dikembalikan sebagai ASSESSMENT_NOT_AVAILABLE tanpa identitas orang lain.

## Snapshot dan harga

AssessmentPriceSnapshot::capture membaca paket DB yang dimuat pemanggil,
termasuk package_items. Format version=1: packageId, packageCode, packageName,
testTypes terurut, baseAmount, consultationRequested, consultationAmount,
amount, currency=IDR. Semua nominal integer nonnegatif, konsultasi yang diminta
harus tersedia dengan nominal positif; tidak diminta selalu nol. Penjumlahan
memeriksa PHP_INT_MAX sebelum operasi, tanpa float. Paket kosong/nonaktif,
mata uang lain, atau harga invalid menghasilkan DomainException berkode.

Charge existing tanpa bill dapat memakai snapshot version=1 yang valid, cocok
dengan kolom charge dan pilihan konsultasi. Harga katalog baru tidak mengganti
snapshot existing. Snapshot rusak/versi asing ditolak, bukan diisi ulang dari
harga terbaru. Policy tetap dievaluasi ulang; paid/claimed/free-settled tidak
boleh dipesan lagi. Detail policy snapshot/hash menyertakan keputusan payer
dan scope sumber, tidak credential atau metadata klinis.

## Keluaran dan batas

Hasil memuat items terurut ID, masing-masing status payable/free/unavailable,
reason, snapshot/snapshotHash jika tersedia, dan policySnapshot; totalAmount,
paidCount, freeCount, canReserve, selectionHash. Bila ada item unavailable,
totalAmount dan selectionHash NULL serta canReserve=false. Semua gratis:
totalAmount=0, canReserve=false, tidak membuat bill. Hash SHA-256 deterministic
untuk scope/payer/selection/harga/policy yang sama, bukan token otorisasi.

Preview tidak membuat charge, bill, item, entitlement, outbox, invoice, atau
menandai gratis/lunas. Tidak mengambil lock reservasi. P7b wajib reload seluruh
data/policy dan membandingkan hash dalam transaksi sebelum reservasi; preview
bukan janji harga tetap atau bukti aman dari race. Penjumlahan total overflow
menolak seluruh preview dengan DomainException TOTAL_OVERFLOW.

## Verifikasi lokal

11 tes kalkulator/16 assertions dan 22 tes feature preview/48 assertions;
regresi 553 tes/2.700 assertions; suite PostgreSQL runtime disposable 127 tes/
531 assertions lulus. Pint/PHPStan bersih. Bukti, perintah dan review:
[ORGANIZATION_CHECKOUT_VALIDATION.md](ORGANIZATION_CHECKOUT_VALIDATION.md).
Preview tetap tidak memiliki route/UI dan tidak mereservasi. Writer internal
P7b memakai validasi canonical bersama; kontraknya ada di
[ASSESSMENT_BILL_RESERVATION.md](ASSESSMENT_BILL_RESERVATION.md).
Tidak ada invoice atau perubahan harga/database aktif.
