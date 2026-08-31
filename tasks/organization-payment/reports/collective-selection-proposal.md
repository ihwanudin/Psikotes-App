# P12b-prep — proposal seleksi pembayaran kolektif

Status: **usulan kontrak/UX untuk review, belum implementasi atau izin aktivasi**.
Tanggal 2026-09-01; delta dokumen dari commit lane 5d625e6. Induk dibaca
read-only di D:/LSI/Web/Psikotes; HEAD yang teramati ed2e100. Integrasi fallback
nama c22515b sudah dikonfirmasi koordinator. Tidak reset/merge snapshot worker.

Tujuan: satu BranchAdmin memilih beberapa attempt organisasinya, meninjau
preview server, mengonfirmasi satu niat reservasi, lalu membuka bill yang
dihasilkan atau bill yang sama saat replay. Reservasi tidak berarti lunas.
Tidak ada dana talang, tempo, pinjaman, ledger utang peserta, atau perubahan
pembayar otomatis. Pembayar organization hanya sah jika policy server
mengizinkan dan admin mengonfirmasi pilihan; consent peserta tetap terpisah.

## Acuan dan batas implementasi existing

| Acuan yang dibaca | Dasar proposal |
| --- | --- |
| [SPEC-organization-billing](../../../SPEC-organization-billing.md), [plan](../plan.md), [todo P10–P12](../todo.md), [parallel-work](../parallel-work.md) | Satu cabang/bill, seluruh total, tidak ada pelepasan claim/reinvoice otomatis; P12b bergantung P12a → P11c → P11b → P11a → P10c. |
| [AssessmentBillSelection](../../../app/Data/Payments/AssessmentBillSelection.php) | Input ketat, canonical, limit dari config; identitas seleksi adalah ID row assessment_participants, bukan participant ID atau nama. |
| [PreviewAssessmentBill](../../../app/Actions/Payments/PreviewAssessmentBill.php), [kontrak preview](../../../docs/ASSESSMENT_BILL_PREVIEW.md) | Preview read-only service context; status item/reason, snapshot, total dan hash. Tidak mengautentikasi admin sendiri. |
| [ReserveAssessmentBill](../../../app/Actions/Payments/ReserveAssessmentBill.php), [kontrak reservasi](../../../docs/ASSESSMENT_BILL_RESERVATION.md) | Principal persisted, replay, lock/reload/compare, bill reserved + item + audit atomik; tanpa invoice/entitlement/outbox. |
| [ResolvePayerPolicy](../../../app/Services/Payments/ResolvePayerPolicy.php), [AssessmentPriceSnapshot](../../../app/Services/Payments/AssessmentPriceSnapshot.php) | Izin per source/client/paket dan harga integer IDR, snapshot charge valid tetap dipakai. |
| [Skema/RLS](../../../docs/ASSESSMENT_BILLING_SCHEMA.md), [AssessmentCharge](../../../app/Models/AssessmentCharge.php), [AssessmentBill](../../../app/Models/AssessmentBill.php), [AssessmentBillItem](../../../app/Models/AssessmentBillItem.php) | Claim charge permanen pada tahap ini; batas parent/child/payer dan status bill. |
| [FundingPolicyPolicy](../../../app/Policies/FundingPolicyPolicy.php), [OrganizationBillResource](../../../app/Filament/Resources/OrganizationBills/OrganizationBillResource.php), [detail](../../../app/Filament/Resources/OrganizationBills/Pages/ViewOrganizationBill.php) | Pengelola policy berbeda dari pembayar; portal existing hanya testing, BranchAdmin pemilik, proyeksi terbatas. |
| [ActivateSettledAssessment](../../../app/Actions/Payments/ActivateSettledAssessment.php) | Aktivasi membutuhkan settlement sah dan prasyarat per attempt; bukan finalizer pembayaran. |

Temuan keberadaan file: IssueAssessmentBillInvoice, ReconcileAssessmentBill,
FinalizeAssessmentBill, VerifyAssessmentBillTransfer, AssessmentBillPolicy,
CreateCollectiveBillAction, dan ProvisionCheckoutParticipant belum tersedia pada
path rencana di induk saat dibaca. Nama tersebut adalah dependency/usulan,
bukan layanan yang bisa dipanggil sekarang. OrderResource/verifier legacy
tidak boleh dipakai sebagai pengganti bill kolektif.

## Alur UX yang diusulkan

1. **Peserta → pilih attempt.** Tabel mempertahankan query/search/sort/aksi
   existing. Bulk action kelak bernama “Tinjau pembayaran terpilih”, hanya untuk
   BranchAdmin yang lolos pemeriksaan persisted. Tampilkan nama (fallback
   “Nama belum dilengkapi”), ID kandidat, ID attempt, periode dan paket agar
   dua attempt orang yang sama dapat dibedakan. Tidak memakai nama/WA sebagai key.
   Eligibility berasal dari server, bukan status assessment di browser saja.
2. **Preview server.** Satu permintaan untuk daftar eksplisit dan pilihan
   konsultasi; tidak satu request harga per row. Saat loading, total lama dan
   tombol konfirmasi tidak berlaku. Daftar review menampilkan semua pilihan,
   alasan item tidak tersedia, rincian biaya, “N attempt dipilih / P berbayar /
   F gratis”, total server, dan identitas cabang pembayar yang terkunci.
   `paidCount` existing berarti **jumlah item berbiaya positif, bukan sudah lunas**.
   Bila perlu jumlah orang unik, hitung server secara terpisah; bukan item_count.
3. **Tinjau dan konfirmasi.** Pilih kanal dari daftar aktif server xendit/manual_transfer.
   Konfirmasi menyebut bahwa lembaga akan membayar total yang ditampilkan kepada
   ONCAM, tidak membuat utang peserta. Beri tahu item gratis tidak masuk tagihan
   berbayar dan belum diselesaikan oleh proses ini. Tidak precheck persetujuan,
   mengubah consent, atau menganggap pilihan konsultasi sebagai consent klinis.
   Tombol hanya dapat dipakai jika preview valid dan integration gate disetujui;
   saat ini **belum boleh aktif publik** meski canReserve=true.
4. **Reservasi sekali.** Setelah wiring diotorisasi, handler memanggil
   ReserveAssessmentBill existing dengan principal sesi yang diperiksa ulang.
   Saat request berlangsung, cegah submit UI berulang; proteksi sebenarnya
   tetap idempotensi/transaksi/constraint server. Gagal satu item berarti seluruh
   konfirmasi gagal. Jangan menghapus item invalid diam-diam lalu mengirim subset.
5. **Lanjut ke bill yang sama.** Sukses/replay menuju detail OrganizationBillResource
   melalui route resource existing setelah otorisasi pemilik. Reference, total,
   jumlah alokasi dan status dari bill persisted, bukan angka optimistis browser.
   Daftar terkunci; tidak ada tambah/kurang item, ganti payer, “tandai lunas”, atau
   invoice baru saat reload. Invoice/aksi bayar tetap menunggu P10/P11 dan gate.

Pagination: usulkan set ID eksplisit yang dipertahankan saat pindah halaman,
sort dan filter; tampilkan “N dipilih, M di luar halaman/filter ini” dan tombol
hapus seluruh pilihan. “Pilih halaman ini” hanya menambah row layak yang terlihat,
bukan “semua hasil” tersembunyi atau query filter yang baru dieksekusi saat submit.
Review selalu menampilkan seluruh set bounded, termasuk row yang baru invalid.
Mengubah pilihan/konsultasi membatalkan preview sebelumnya. Batas berasal dari
assessment_billing.max_items (saat ini 100), tidak melakukan split otomatis.
Perpindahan organisasi/logout/revokasi menghapus state tampilan/draft lama;
server tetap menolak ID lama. Jangan mengandalkan state checkbox Livewire sebagai
bukti akses. Kontrol alasan disabled harus terbaca tanpa hover; target pengujian
berikutnya Tab/Space checkbox, Enter tombol, fokus error, 320/390/desktop.

## Input minimal dan proyeksi aman

Kontrak PHP internal sudah ada; proposal ini tidak menetapkan endpoint/route HTTP
baru. Usulan adapter Filament kelak memakai bentuk berikut:

| Tahap | Input dari UI | Diambil/ditetapkan server |
| --- | --- | --- |
| Preview | `selection: list<{assessmentParticipantId: int, consultationRequested: bool}>` | Admin sesi persisted; organizationId = branch_id; payer = organization; participantId = null. |
| Konfirmasi | Selection yang sama, `selectionHash`, `paymentMethodId`, `idempotencyKey` | Reload principal/scope, validasi kanal, lock/reload preview, satu bill existing/new dari action. Tidak menerima total/status/payer dari UI. |

Setiap item tepat dua field; integer positif (string numerik ditolak), boolean
asli, list nonkosong 1..limit, tanpa duplikasi. ID bukan assessment_attempt_id
ULID yang ditampilkan; adapter harus memakai row ID yang sudah dipetakan server.
Field nominal/status ekstra di item ditolak, bukan dipakai menghitung. Adapter
kelak juga menolak field top-level tidak dikenal; validasi transport ini belum ada.
IDs saja belum memenuhi action: consultationRequested wajib. Pilihan charge
existing mengikuti nilai tersimpan dan tidak boleh diubah (`CONSULTATION_LOCKED`).
Untuk charge baru, konsultasi tidak dicentang otomatis dan harga hanya dari server.

Hash preview harus lowercase SHA-256 64 karakter. Key niat konfirmasi menerima
1..128 karakter ASCII `[A-Za-z0-9:_-]`. paymentMethodId integer positif. ID metode
bukan izin kanal aktif; server memeriksanya lagi. canReserve dari preview belum
memeriksa kanal, sehingga bukan izin submit tanpa kanal aktif. Tidak menambah field version
atau expected-price ke action backend pada tahap proposal ini.

Proyeksi preview yang diusulkan: assessmentParticipantId, label nama, ID kandidat,
ID attempt/periode, status item dan reason aman, packageCode/packageName,
baseAmount, consultationRequested, consultationAmount, amount, currency,
paidCount/freeCount, totalAmount, canReserve, selectionHash. Label identitas
belum dikembalikan PreviewAssessmentBill; adapter perlu memuatnya dengan scope
persisted yang sama, tidak menserialisasikan model/relations mentah. Untuk ID
tidak tersedia, kembalikan hanya ID kiriman dan alasan generik tanpa label asing.

Snapshot internal version=1 juga berisi packageId dan testTypes serta hash,
sedangkan policySnapshot memuat source/client/payer. Tidak perlu mengekspos
objek internal tersebut seluruhnya ke browser. Jangan mengirim recommendation,
nilai tes/DASS, hasil klinis, metadata peserta, bukti identitas, nomor WA, credential,
URL/path proof, invoice_url/gateway_ref, policy mentah, request_hash atau model bill
mentah. SelectionHash hanya fingerprint, **bukan token autentikasi/otorisasi**.
Keputusan consent/kelayakan akses tidak diturunkan dari pembayaran pada UI ini.

## Eligibility exact dan alasan disabled

Kosakata item dari PreviewAssessmentBill hanya `payable`, `free`, `unavailable`.
Tidak ada state item `paid`, `pending_payment`, atau enum assessment baru.
`payable`: snapshot valid amount > 0; `free`: snapshot valid amount = 0.
Keduanya tetap harus lolos seluruh pemeriksaan. Checkbox `payable`/`free` boleh
dipilih untuk review; `unavailable` dinonaktifkan dengan alasan terlihat. `free`
berlabel “Gratis — tidak masuk tagihan”, bukan alokasi tagihan berbayar. Semua gratis:
totalAmount=0, canReserve=false; tidak membuat invoice/bill transfer.
Satu `unavailable`: totalAmount=null, selectionHash=null, canReserve=false;
angka item lain bukan total batch sah. Jangan menampilkan total null sebagai Rp0.

Berikut kondisi berdasarkan urutan pemeriksaan existing. Teks Indonesia adalah
usulan label UI, bukan kode exception baru. Jika beberapa kondisi gagal, hanya
reason pertama yang teramati; jangan menyimpulkan status paid dari reason itu.

| Reason existing | Kondisi kode | Label/tindakan UI yang diusulkan |
| --- | --- | --- |
| ASSESSMENT_NOT_AVAILABLE | Attempt tidak ditemukan dalam organization; peserta hilang/soft-deleted atau branch tidak cocok; pada self participant tidak cocok. | “Attempt tidak tersedia.” Jangan membedakan ID asing dan ID hilang. |
| CHECKOUT_ATTEMPT_REQUIRED | metadata.checkout_contract_version bukan checkout-v2. | “Attempt belum tersedia untuk checkout ini.” Tidak mengonversi legacy dari checkbox. |
| ASSESSMENT_NOT_BILLABLE | assessment_status bukan PROVISIONED, revoked_at terisi, atau finalized_at terisi. Semua READY/IN_PROGRESS/COMPLETED/UNDER_REVIEW/FINALIZED/REVOKED/VOID ditolak. | “Attempt tidak dapat ditagihkan.” Bukan otomatis “sudah lunas”. |
| CHECKOUT_SOURCE_REQUIRED | Source pada client+source_system tidak ada atau contract_version bukan checkout-v2. | “Sumber belum tersedia untuk checkout ini.” |
| INTEGRATION_CONTEXT_INVALID | ID scope registry tidak valid atau client/source tidak terikat organisasi/client yang benar. | “Konfigurasi sumber tidak sesuai.” Hubungi ONCAM. |
| ORGANIZATION_NOT_ALLOWED | Branch !is_active atau status bukan ACTIVE. | “Organisasi tidak diizinkan untuk tagihan baru.” |
| INTEGRATION_NOT_ALLOWED | Client !enabled atau di luar effective_from <= now < effective_until; null tidak membatasi sisi waktu itu. | “Integrasi tidak aktif.” |
| SOURCE_NOT_ALLOWED | Source status bukan ACTIVE atau di luar jendela efektif yang sama. | “Sumber tidak aktif.” |
| PACKAGE_NOT_ALLOWED | Paket invalid/nonaktif, allowed_assessment_packages bukan list atau tidak memuat kode; saat capture juga package_items kosong. | “Paket tidak tersedia untuk sumber ini.” |
| PAYER_POLICY_UNCONFIGURED | allowed_payer_types organisasi atau sumber null. | “Kebijakan pembayar belum dikonfigurasi.” |
| PAYER_POLICY_INVALID | List payer/lock tidak valid atau locked payer tidak termasuk irisan yang diizinkan. | “Kebijakan pembayar tidak valid.” Jangan fallback ke self/organization. |
| INVALID_PAYER_TYPE | String requested payer tidak dikenal pada resolver. | Error kontrak; adapter selalu menetapkan organization, bukan field bebas pengguna. |
| PAYER_NOT_ALLOWED | Irisan payer kosong atau organization tidak termasuk pilihan yang diizinkan. | “Pembayaran lembaga tidak diizinkan.” Tidak menawarkan override policy. |
| PAYER_LOCKED | Payer yang diminta berbeda dari locked_payer_type sumber. | “Pembayar dikunci oleh kebijakan sumber.” |
| CHARGE_ALREADY_BILLED | Charge mempunyai assessment_bill_items, tanpa filter status bill. | “Biaya sudah terikat tagihan.” Tautan hanya ke bill organization milik cabang setelah otorisasi terpisah; reason sendiri tidak berisi bill ID. |
| CHARGE_ALREADY_SETTLED | Charge tanpa claim mempunyai free_settled_at non-null. | “Biaya gratis sudah diselesaikan.” Pemeriksaan preview tidak menilai timestamp masa depan; ini tetap bukan bukti akses siap. |
| CHARGE_PAYER_LOCKED | Charge belum diklaim/diselesaikan tetapi payer_type berbeda dari payer permintaan. | “Pembayar biaya sudah terkunci.” Tidak bulk-convert charge self. |
| CONSULTATION_LOCKED | Bool konsultasi berbeda dari charge existing. | “Pilihan konsultasi biaya ini sudah terkunci.” Pulihkan pilihan tersimpan, preview lagi. |
| PRICE_INVALID | Capture baru: base bukan integer/nonnegatif atau currency bukan IDR. | “Harga belum valid.” Jangan menghitung/fallback di UI. |
| CONSULTATION_NOT_AVAILABLE | Capture baru: nominal konsultasi yang digunakan invalid, atau konsultasi diminta dengan nominal nol. | “Konsultasi berbayar tidak tersedia.” |
| PRICE_OVERFLOW | Capture baru: base + konsultasi melebihi PHP_INT_MAX. | “Harga tidak dapat diproses.” Hubungi ONCAM. |
| PRICE_SNAPSHOT_INVALID | Snapshot shape/version/tipe/komponen/testTypes/mata uang/penjumlahan tidak sah, atau tidak cocok kolom charge. | “Snapshot biaya tidak valid.” Jangan repricing diam-diam. |

Harga menggunakan AssessmentPriceSnapshot::capture bila charge belum ada;
fromCharge bila ada. Snapshot lama sah tetap dipakai ketika harga katalog berubah,
tetapi policy/paket aktif tetap dievaluasi ulang. Nama kosong/profil parsial,
consent belum lengkap, atau belum lolos identitas **bukan reason penolakan biaya
dalam preview existing**; jangan membuat larangan billing baru dari kondisi itu.
funding_mode bukan enum pembayaran paid dan tidak dibaca sebagai bukti pelunasan.

Error di tingkat permintaan tidak dijadikan reason row buatan:

| Error existing | Respons UX yang diusulkan |
| --- | --- |
| INVALID_BILL_SELECTION / INVALID_BILL_REQUEST | Input tidak sah/duplikat/lebih dari limit/key atau hash salah; hentikan, tanpa split atau koreksi diam-diam. |
| ORGANIZATION_NOT_AVAILABLE | Preview organisasi tidak ditemukan; invalidasi konteks, jangan ungkap organisasi lain. |
| BILL_PAYER_NOT_AUTHORIZED | Hentikan alur, buang data review yang tidak lagi boleh dilihat; tidak retry sebagai role lain. |
| PAYMENT_METHOD_NOT_AVAILABLE | Batalkan konfirmasi baru; muat kanal aktif dan minta tinjau ulang. |
| PREVIEW_CHANGED | Seluruh konfirmasi ditolak; refresh preview lengkap dan minta konfirmasi baru. |
| FREE_CHECKOUT_REQUIRED | Seluruh pilihan gratis; bukan kegagalan gateway, tidak buat bill. Jalur gratis terpisah masih dependency. |
| IDEMPOTENCY_CONFLICT | Key lama tidak cocok payload; jangan retry otomatis dengan key baru. Temukan hasil niat lama dahulu. |
| TOTAL_OVERFLOW | Penjumlahan seluruh preview ditolak; tidak tampilkan total parsial. |
| LogicException context/limit, exception DB atau error tak dikenal | Gagal tertutup; pesan generik dan penanganan petugas, tanpa SQL/stack trace, sukses palsu atau invoice cadangan. |

Mapping HTTP belum ada; proposal ini tidak menjanjikan status HTTP baru.

## Revalidation, stale, replay, dan bill existing

Reservasi baru mengunci admin → organisasi → registry/client/source → attempt →
peserta → package/items → charge secara terurut, kemudian kanal; menjalankan
preview ulang dan membandingkan selectionHash dalam transaksi yang sama.
Harga baru tanpa charge, pilihan/anggota, policy, revokasi, claim atau scope yang
berubah dapat menghasilkan PREVIEW_CHANGED; kanal OFF menghasilkan error kanal.
Satu item gagal menolak seluruh batch tanpa invoice/charge parsial. Identitas
aktor/cabang salah bisa ditolak lebih awal sebagai BILL_PAYER_NOT_AUTHORIZED.

Hash mengikat scope/payer dan seluruh item canonical beserta snapshot/policy,
termasuk item gratis. Urutan item/object key berbeda tidak mengubah niat. Hash
preview **tidak mencakup nama peserta, external_candidate_id, periode, label UI
atau kanal**; kanal diikat request_hash reservasi. Jangan menjanjikan setiap edit
metadata tampilan pasti menimbulkan PREVIEW_CHANGED. Label harus dimuat ulang
server. Perubahan penerima pada attempt uncharged dalam cabang yang sama tidak
diikat participantId per item oleh hash organization; kontrak immutabilitas
identitas attempt/penanganan edit semacam itu perlu konfirmasi backend sebelum
wiring publik. Tidak menambal hash/action/schema pada lane portal ini.

Satu key dibuat per niat konfirmasi, bukan per klik/retry. Simpan payload canonical,
hash dan kanal asli selama hasil belum diketahui. Usulkan draft intent pada sesi
admin server yang terikat principal+organisasi, disimpan sebelum panggilan action,
agar reload dapat mengulang payload/key yang sama; jangan menyimpan profil/hasil
klinis di browser. Mekanisme resume/draft ini belum ada dan perlu review integrasi.
Jika hasil request tidak diketahui, tampilkan pesan UI “Hasil konfirmasi belum
diketahui; periksa tagihan”, bukan menulis status bill `unknown` dari browser.
Tidak boleh otomatis refresh hash lalu retry dengan key lama, karena itu konflik.
Jika key/payload lama hilang, buka daftar tagihan terotorisasi/penanganan petugas;
jangan membuat niat baru untuk menebak apakah reservasi pertama berhasil.

Replay memeriksa principal persisted dan organisasi, lalu cocokkan request_hash
sebelum policy/kanal/preview baru. Key+payload sama mengembalikan bill yang sama,
termasuk saat kanal/policy OFF atau bill paid/expired/rejected, tanpa audit/item
kedua. Dua admin cabang dengan key dan payload sama berbagi hasil dalam scope
organisasi; key sama payload berbeda adalah konflik. Role/cabang dalam memori
tidak dapat mengganti scope persisted. Setelah PREVIEW_CHANGED definitif tanpa
reservasi, tinjau preview baru lalu bentuk niat baru yang dikonfirmasi pengguna.

Claim bukan sekadar “invoice aktif”: keberadaan bill_item menolak semua key baru
meski bill reserved/issuing/unknown/pending/paid/expired/rejected. Tidak ada
status cancelled pada AssessmentBill existing. Paid tidak diturunkan; expired,
rejected, unknown diarahkan ke rekonsiliasi ONCAM, tidak auto-release/reinvoice.
Self dan collective memakai charge unik yang sama: bila self menang, batch kalah
seluruhnya. UI cabang tidak menampilkan invoice/bukti self dan tidak mengganti
payer. Order legacy tidak memiliki pemetaan attempt untuk deduplikasi ini;
jaminan race existing berlaku jalur ReserveAssessmentBill, bukan klaim bahwa
invoice legacy sudah tercakup. Cutover P9/kontrak sumber harus menutup ambiguitas itu.

## Aktor dan batas organisasi

| Aktor | Seleksi/preview/konfirmasi kolektif yang diusulkan | Detail bill kolektif / verifikasi |
| --- | --- | --- |
| BranchAdmin persisted, branch non-null | Hanya attempt cabang sendiri, tiap source/client/paket/payer lolos; bukan berdasarkan can_verify_payments. | Resource prep hanya organization bill miliknya; tidak boleh verifikasi paid sendiri. |
| BranchAdmin cabang lain / membership berubah | Tolak data/draft lama; setiap ID asing mendapat penolakan aman. | Direct URL, nested allocation, action/hydration harus scope ulang. |
| Staff / Psychologist | Ditolak meski menu Peserta existing dapat dilihat. | Ditolak oleh portal bill existing; flag legacy bukan izin. |
| SuperAdmin | ReserveAssessmentBill menolak sebagai pembayar kolektif; tidak ada bypass. | Portal cabang prep menolak. RLS SELECT luas/policy admin bukan izin action pembayar; verifikasi ONCAM melalui P11c kelak. |
| Participant | Tidak masuk bulk/panel. Action self terpisah hanya satu attempt sendiri. | Tidak menerima batch/anggota/total/invoice/proof organisasi. |
| Guest, principal hilang/soft-deleted | Ditolak sebelum masuk service context. | Tidak ada data. |

Preview existing menerima scope integer, bukan principal: adapter wajib
mengautentikasi, reload membership dan membatasi proyeksi sebelum runAsService.
Service context adalah boundary internal, bukan role browser. Tidak menerima
organizationId/payer dari hidden input. Cache/draft/count/filter/label/URL harus
mengikuti scope yang sama, dan CSRF/session framework dipertahankan. Read-only
eligibility pada halaman tidak mengganti validasi ulang saat konfirmasi.

## Matriks konkret dan rencana bukti

Data berikut **rancangan fixture sintetis, belum dibuat/dijalankan**. Sepuluh
peserta berbeda K01–K10, attempt A01–A10 (alias untuk ID row integer yang dibuat
fixture), satu cabang aktif. Semua attempt PROVISIONED bermarker checkout-v2,
tanpa revoked/finalized/charge awal; registry v2 aktif dengan izin organization,
paket aktif dan package_items valid. Harga dummy IDR di DB fixture: SYN-A=100,
konsultasi 30; SYN-B=200, konsultasi 50; SYN-C=0 (dass21), konsultasi 30.
Bukan harga operasional atau literal perhitungan UI. Tidak memuat hasil tes.

| Peserta / attempt | Paket | Konsultasi | Snapshot amount IDR | Status preview |
| --- | --- | --- | ---: | --- |
| K01 / A01 | SYN-A | false | 100 | payable |
| K02 / A02 | SYN-A | true | 130 | payable |
| K03 / A03 | SYN-B | false | 200 | payable |
| K04 / A04 | SYN-B | true | 250 | payable |
| K05 / A05 | SYN-C | false | 0 | free |
| K06 / A06 | SYN-C | true | 30 | payable |
| K07 / A07 | SYN-A | false | 100 | payable |
| K08 / A08 | SYN-B | false | 200 | payable |
| K09 / A09 | SYN-C | false | 0 | free |
| K10 / A10 | SYN-A | true | 130 | payable |

Expected preview: 10 attempt, paidCount=8, freeCount=2, totalAmount=1140,
currency=IDR, canReserve=true. Reservasi: satu bill 1140/item_count=8, delapan
charge/item berbayar; A05/A09 masuk hash tetapi tidak diklaim/diselesaikan.
Sesudah P10 kelak satu invoice 1140, bukan sepuluh invoice. Varian konsultasi
A05/A09=true: sepuluh payable, satu bill 1200/item_count=10; ini skenario tepat
“10 peserta → satu pembayaran”. Tidak mengubah pilihan konsultasi existing charge.

| Kasus | Perubahan/aksi konkret | Ekspektasi dan lapisan bukti berikutnya |
| --- | --- | --- |
| Mixed package 10 | Matriks di atas; varian 10 payable | Preview angka/label tepat; konfirmasi tidak menyebut delapan sudah lunas. Feature action sekarang punya analog; UI dan invoice fake P10 belum dibuktikan. |
| Semua gratis | Pilih A05+A09 tanpa konsultasi | total=0, canReserve=false; konfirmasi tidak memanggil reservasi/invoice. Forced internal reserve → FREE_CHECKOUT_REQUIRED. Jalur gratis tidak pura-pura sukses. |
| Nama parsial / orang sama | Nama K05 null, K09 whitespace; varian A01 dan A10 milik satu orang | Fallback label dan ID membedakan row; tetap dua attempt, jumlah orang unik berbeda. Tidak dedup berdasarkan nama/participant ID. |
| Double click / reload | Key K sama + selection/hash/channel sama dikirim dua kali; respons pertama hilang | Bill/reference sama, satu audit, bukan create invoice kedua; resume payload asli. Livewire/session test belum ada. |
| Concurrent bill | Admin satu A01–A06, admin dua A04–A10, key berbeda | Satu pemenang; loser PREVIEW_CHANGED, tidak membuat bill dari sisa nonoverlap. PG action memiliki tes overlap serupa; UI perlu pesan dan refresh aman. |
| Parallel self-pay | Peserta K01 mereservasi A01 self bersamaan batch 10 | Satu claim; jika self menang, batch seluruhnya gagal. Jangan membuka invoice self kepada cabang. PG action existing mencakup race ini. |
| Existing claim / terminal | A03 sudah bill pending, lalu varian paid/expired/rejected/unknown | Preview unavailable (umumnya CHARGE_ALREADY_BILLED jika pemeriksaan sebelumnya lolos); semua status mempertahankan claim. Key awal replay bill sama; key baru tidak reinvoice. |
| Item hilang/berubah scope | Setelah preview, A04 hilang/participant soft-deleted atau pindah cabang; varian admin dipindah/dicabut role | Seluruh konfirmasi ditolak, tidak ada label asing; domain PREVIEW_CHANGED atau auth denial sesuai tahap. UI tidak menghapus A04 otomatis. FK bisa lebih dulu mencegah edit parent pada charge existing. |
| Cross-tenant forged | Tambah satu ID cabang B ke sembilan ID cabang A; bandingkan ID tidak ada | ASSESSMENT_NOT_AVAILABLE tanpa identitas; total/hash null; direct action/resume/detail harus ditolak tanpa leak. |
| Harga/catalog stale | SYN-A naik 100→150 sebelum reserve, belum ada charge | PREVIEW_CHANGED; preview baru untuk matriks =1340, konfirmasi ulang. Kasus terpisah: pilih hanya A01 dengan charge snapshot100 existing; kenaikan katalog SYN-A tidak repricing charge itu, preview tetap100. |
| Policy/channel stale | Organization OFF / source expired / paket dicabut / kanal OFF di antara preview dan submit | Seluruh reservasi baru gagal sesuai reason; replay bill lama tetap mengembalikan hasil setelah auth. Jangan tafsir OFF sebagai pembatalan pembayaran existing. |
| Selection lintas pagination | Pilih A01–A05 di page1, A06–A10 page2; sort/filter menyembunyikan page1 | Set tetap 10, ringkasan mengungkap pilihan tersembunyi; preview semua10. Hapus A02 → hash lama invalid; exact ID duplikat/limit+1 ditolak, tidak silent split. |
| Key sama payload berbeda | Ubah A06 konsultasi, kanal, atau hash memakai K lama | IDEMPOTENCY_CONFLICT bila K sudah punya bill; cari hasil niat awal, jangan otomatis key baru. |
| Snapshot rusak / overflow | Charge A01 versi asing; atau jumlah valid melampaui integer | PRICE_SNAPSHOT_INVALID per item → total null; TOTAL_OVERFLOW menolak preview keseluruhan. Tidak harga fallback/browser sum. |
| Pelunasan/akses (dependency) | Invoice cocok; injeksi gagal alokasi kelima, duplikat event, expired terlambat, K05 belum consent | Target P11 atomik seluruh alokasi, paid tidak turun; akses per prasyarat, tidak menagih kedua kali. Bukan kemampuan reservasi atau bukti proposal ini. |

Source tes yang ditinjau, **tidak dijalankan ulang**: [preview feature](../../../tests/Feature/Payments/AssessmentBillPreviewTest.php),
[reservation feature](../../../tests/Feature/Payments/AssessmentBillReservationTest.php),
[snapshot unit](../../../tests/Unit/Payments/AssessmentPriceSnapshotTest.php),
[reservation PG](../../../tests/Postgres/AssessmentBillReservationTest.php),
serta proyeksi/role pada [portal PG](../../../tests/Postgres/OrganizationBillPortalTest.php).
Tes existing memiliki assertion 10 reservasi, gratis, snapshot lama, stale,
replay, rollback dan overlap/self race; ini bukan bukti UI mixed-package atau
invoice/finalizer selesai. Angka regresi root 779/3782 adalah laporan koordinator
sebelumnya, bukan run pada increment dokumen ini.

## Dependency, lokasi UI, dan increment terkecil berikutnya

P9 harus menyediakan attempt v2 dari provisioning yang benar, tidak mengubah
marker legacy dari portal. P10a/b/c harus menyediakan lookup/recovery reference
tetap, penerbitan satu invoice sesudah commit dan rekonsiliasi unknown. P11a
finalizer harus melunasi induk+semua alokasi secara atomik, menghubungkan aktivasi
dan outbox; P11b merutekan webhook/status ke finalizer yang sama; P11c memberi
verifikasi transfer hanya kepada ONCAM/SuperAdmin, bukan flag verifier cabang.
P11 harus mencocokkan reference, nominal penuh dan IDR dari event terautentikasi;
kurang/lebih bayar tidak dialokasikan otomatis dan expired terlambat tidak
menurunkan paid. Tidak ada akses tes dari redirect atau persetujuan admin cabang.
P12a masih prep sampai integrasi itu selesai. P12c proof upload di luar P12b.
Settlement gratis eksplisit juga belum ditemukan writer-nya: model/activation
yang membaca free_settled_at tidak menyelesaikan biaya gratis sendiri.

Lokasi target setelah review: bulk action pada AssessmentParticipantResource;
orchestrator presentasi `app/Filament/Actions/CreateCollectiveBillAction.php`,
tes `tests/Feature/Admin/CollectiveBillSelectionTest.php`; tujuan sukses memakai
OrganizationBillResource existing. Jangan menaruh writer kedua pada Filament,
menyalin policy/harga, mengubah OrderResource, shared routes atau schema.

Usulan **satu increment implementasi berikutnya**: adapter preview read-only
test-only `app/Filament/Actions/PreviewCollectiveBillSelection.php` + tes baru
`tests/Feature/Admin/CollectiveBillPreviewTest.php` dan laporan. Belum memasang
bulk action pada resource publik, belum konfirmasi/reservasi, belum session draft.
Adapter mengautentikasi/reload BranchAdmin, menurunkan organisasi, memanggil
PreviewAssessmentBill existing dan memproyeksikan hanya label/biaya aman. Tolak
di luar testing mengikuti batas prep yang sudah ada, tanpa config flag baru.
Acceptance: fixture valid 10 mixed-package di atas; null/whitespace label;
reason/total null; role/tenant/IDOR dan membership berubah; whitelist output;
jumlah row charge/bill/item/entitlement/audit/outbox tidak bertambah. Kalkulator,
lock/reservasi, finalizer dan policy backend tetap milik backend. Tes focused
SQLite untuk adapter; bukti query PG menyusul jika integrasi query/RLS diotorisasi,
bukan digantikan dengan klaim SQLite. Stop review sebelum UI selection/writer.

Keputusan review yang masih diperlukan sebelum wiring: persetujuan proyeksi dan
resume intent, kontrak immutabilitas identitas attempt, gating/middleware service
caller, jalur gratis, serta readiness P9–P11. Proposal tidak memberikan izin
implementasi otomatis. Pemeriksaan increment ini hanya pembacaan kode/dokumen,
kecocokan reason/field dan diff; tidak browser, DB, PHPUnit, Pint/PHPStan atau
suite lain karena tidak ada perubahan executable. Pemeriksaan statis menemukan
seluruh 31 kode reason/error literal dari lima class input/preview/reservasi/
policy/snapshot tercakup di dokumen; 24 tautan sumber tersedia di induk.
Ini pemeriksaan kelengkapan dokumentasi, bukan eksekusi perilaku. Hanya dua dokumen lane yang
di-commit; baseline/overlay sebelumnya tetap tidak disertakan.
