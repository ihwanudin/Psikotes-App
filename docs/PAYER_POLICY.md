# Kebijakan pembayar: kontrak internal

## Lingkup

`ResolvePayerPolicy::resolve` mengevaluasi konfigurasi registry yang diberikan
server dan menghasilkan `PayerDecision` immutable. Ini bukan endpoint publik,
autentikator, pembuat order, validator harga, atau bukti pembayaran/consent/akses.
Resolver tidak melakukan query, mutasi model, penulisan database, atau I/O lain.
Jalur registrasi dan provisioning v1 existing tidak memanggil resolver ini.

## Tanggung jawab pemanggil

Pemanggil memberikan `Branch`, `IntegrationClient`, `IntegrationSource`,
`TestPackage`, waktu evaluasi server (`CarbonInterface`), dan pilihan pembayar
opsional (`self` atau `organization`). Model harus berasal dari pemetaan server
yang sudah diautentikasi dan diotorisasi; jangan mengisinya dari request browser.

Saat reservasi order nanti, reload konfigurasi terkait di dalam transaksi/RLS
yang sesuai. Keputusan ini tidak membaca ulang database dan tidak menjamin
bahwa objek model lama masih mencerminkan izin terkini. Jangan menggunakan hasil
preview/cached decision sebagai otorisasi membuat order. Simpan snapshot pada
order setelah validasi transaksi; tangani replay order lama dari snapshotnya,
bukan menghitung ulang harga/pembayar berdasarkan konfigurasi baru.

Gate kontrak checkout opt-in, kelengkapan paket/harga, identitas peserta,
persetujuan, entitlement per attempt, dan reservasi idempotent merupakan tahap
berikutnya. Pemanggil baru harus memenuhi gerbang tersebut sebelum tersambung
ke jalur peserta. P3 tidak mengubah arti kontrak v1 atau memberi fallback v1.

## Aturan keputusan

- ID organisasi, client, dan sumber harus tersedia. Organisasi harus cocok
  dengan `client.organization_id`; sumber harus milik client tersebut.
- Organisasi wajib ACTIVE dan is_active; client enabled; sumber ACTIVE.
  Periode efektif client/sumber meliputi awal, tetapi tidak meliputi akhir
  (`effective_from <= waktu < effective_until`); batas NULL tidak membatasi.
- Paket wajib aktif, memiliki ID, dan kodenya terdaftar persis pada allow-list
  sumber. Huruf besar/kecil kode tidak dinormalisasi diam-diam.
- NULL pada daftar pembayar berarti belum dikonfigurasi. Array kosong berarti
  tidak ada pilihan. Bentuk bukan list atau nilai selain dua enum ditolak,
  termasuk konfigurasi campuran nilai valid dan tidak dikenal.
- Pilihan efektif adalah irisan daftar organisasi dan sumber; urutan selalu
  self lalu organization, tanpa duplikasi. Sumber tidak dapat memperluas hak
  organisasi, begitu juga sebaliknya.
- Lock sumber harus valid dan termasuk irisan tersebut. Lock mempersempit
  pilihan menjadi satu dan menolak permintaan untuk menggantinya. Lock yang
  tidak lagi diizinkan tidak dialihkan diam-diam ke pembayar lain.
- Tanpa lock, satu pilihan efektif dipilih otomatis. Jika ada dua pilihan dan
  belum dipilih, keputusan meminta pilihan; jangan otomatis memilih pembayar.
- ID lembaga pembayar hanya ada ketika pembayar terpilih organization, dan
  berasal dari `client.organization_id`. Untuk self/belum dipilih/ditolak: NULL.
- Nilai legacy COMMERCIAL_SELF_PAY, INVOICED_TO_ORGANIZATION, SPONSORED,
  INTERNAL, dan WAIVED tidak diterjemahkan di resolver. Adapter eksplisit P5
  diperlukan; label legacy bukan bukti paid atau akses gratis.

## Keluaran

`allowedPayerTypes`, `selectedPayerType`, dan `lockedPayerType` memakai enum
`PayerType`. `payerOrganizationId` adalah ID internal nullable.
`rejectionReason` NULL berarti konfigurasi tersedia, **bukan berarti order dapat
langsung dibuat**: periksa pilihan sudah lengkap dan seluruh gerbang lain.
`requiresSelection()` true hanya jika tidak ditolak dan belum ada pembayar
terpilih. Penolakan selalu mengosongkan pilihan, pembayar, lock, dan ID lembaga.

| Alasan penolakan | Makna |
| --- | --- |
| INTEGRATION_CONTEXT_INVALID | Identitas registry tidak lengkap atau pasangan organisasi/client/sumber tidak cocok |
| ORGANIZATION_NOT_ALLOWED | Organisasi tidak aktif |
| INTEGRATION_NOT_ALLOWED | Client nonaktif atau di luar periode efektif |
| SOURCE_NOT_ALLOWED | Sumber nonaktif atau di luar periode efektif |
| PACKAGE_NOT_ALLOWED | Paket tidak tersedia atau tidak diizinkan sumber |
| PAYER_POLICY_UNCONFIGURED | Daftar pembayar belum dipetakan |
| PAYER_POLICY_INVALID | Konfigurasi pembayar/lock tidak valid atau lock di luar irisan |
| INVALID_PAYER_TYPE | Pilihan bukan self/organization; tidak ada normalisasi atau mapping legacy |
| PAYER_NOT_ALLOWED | Tidak ada pilihan efektif atau pilihan diminta tidak diizinkan |
| PAYER_LOCKED | Pilihan mencoba mengganti lock sumber |

Kode tidak berisi data peserta, kredensial, atau payload mentah. Pemanggil HTTP
nantinya memetakan kode ke respons aman setelah autentikasi; jangan membuka
detail registry kepada caller anonim.

## Verifikasi dan batas

Tes unit meliputi keputusan dan kasus penolakan. Tes integrasi SQLite membuktikan
hydration model, tanpa query/mutasi saat evaluasi, tanpa order/outbox/entitlement
baru, serta perubahan policy tidak mengubah paid/pending/akses locked existing.
Tes PostgreSQL menjalankan registry → resolver → keputusan dengan role runtime
non-owner, termasuk pemalsuan pasangan organisasi dan perubahan konfigurasi.
Bukti eksekusi berada di `ORGANIZATION_PAYMENT_TESTING.md`.

Resolver ini slice internal P3, bukan checkout browser end-to-end atau bukti
concurrency reservasi pembayaran. Endpoint baru dan deploy masih memerlukan
tahap serta checkpoint berikutnya.

## Pengelolaan oleh ONCAM (P4a)

`UpdateFundingPolicy::forOrganization(Admin, organizationId, input)` mengubah
izin pembayar organisasi. `forSource(Admin, organizationId, sourceId, input)`
mengubah izin/lock sumber yang dipetakan ke organisasi tersebut. Keduanya
merupakan action internal yang kini dipanggil kontrol panel P4b. Tidak ada
endpoint publik pengubahan policy.

Pemanggil wajib mengambil Admin dari autentikasi guard admin, bukan payload.
`FundingPolicyPolicy::update` membatasi SuperAdmin tersimpan dan tidak terhapus.
Action memuat ulang serta mengunci admin dalam transaksi sebelum menulis agar
objek sesi lama tidak mempertahankan hak yang telah dicabut. Flag verifikasi
pembayaran legacy tidak memberikan izin mengubah policy.

Input organisasi berisi tepat `allowed_payer_types`; input sumber juga wajib
menyertakan `locked_payer_type` (string atau NULL). Daftar harus list unik berisi
self/organization, maksimal dua; NULL, scalar, nilai legacy, duplikasi, atau
field tambahan ditolak. Daftar kosong sengaja mematikan seluruh pilihan. Lock
harus termasuk daftar sumber; izin efektif tetap irisan dengan organisasi pada
resolver. Perubahan organisasi tidak mengubah sumber secara massal.

Urutan lock: admin aktor → organisasi → client → sumber. Reservasi nanti harus
memakai urutan registry yang sama, dilanjutkan attempt terurut. Mapping sumber
diambil ulang dari database dan pasangan organisasi/sumber salah ditolak.
Action tidak mengubah mapping, harga, kanal pembayaran, order, entitlement,
atau mengirim notifikasi. Menonaktifkan policy hanya mempengaruhi keputusan baru.

Penulisan policy dan audit memiliki transaksi/savepoint sendiri di dalam RLS
service context. Ini diperlukan karena pemanggil panel dapat menangkap error
di transaksi luarnya: kegagalan audit tetap harus membatalkan perubahan policy.
Context RLS pemanggil dikembalikan setelah selesai maupun gagal.

Audit `funding_policy.updated` menyimpan ID aktor/target/cabang, nilai policy
sebelum/sesudah, waktu, serta retensi dua tahun mengikuti pola audit existing.
Tidak menyimpan kredensial client, payload mentah, atau data peserta. Urutan
input dinormalisasi self lalu organization; pengiriman ulang nilai sama no-op.
Pengujian PostgreSQL runtime membuktikan transaksi/RLS, bukan simulasi race
dua proses; race dengan reservasi masuk pengujian P7/P17.

## Kontrol panel ONCAM (P4b)

SuperAdmin membuka **Integrasi → Klien Integrasi → Atur pembayar** untuk
mengatur izin lembaga. Pengaturan ini melekat pada organisasi, bukan hanya
baris client tersebut, sehingga berlaku bagi seluruh client/sumber lembaga.
**Integrasi → Sumber Integrasi → Atur pembayar** mengatur izin dan lock sumber;
action yang sama juga tersedia pada header halaman edit sumber.

Form menampilkan **Bayar sendiri** dan **Dibayar lembaga**. Centang berarti ON;
kosong berarti OFF. Jika semuanya OFF, checkout baru ditolak. Lock hanya boleh
memilih pembayar yang diizinkan sumber; keputusan checkout tetap memeriksa
irisan dengan lembaga. Hapus kunci sebelum mematikan pembayar yang terkunci.
Jika terlanjur dimatikan, aktifkan kembali pilihannya, pilih **Tidak dikunci**,
lalu matikan pilihan dan simpan. Kesalahan ditampilkan di dekat field terkait.

NULL diberi keterangan **Belum dikonfigurasi**, berbeda dari konfigurasi semua
OFF. **Batal** tidak menyimpan apa pun. **Simpan pengaturan** memanggil action
P4a, memuat ulang target dari server, memvalidasi, dan mencatat audit. Form edit
registry umum tidak menerima field policy; izin cabang/staff/psikolog tidak
diperluas. Pembayar tidak sama dengan kanal Xendit/transfer manual.

Input daftar mentah divalidasi sebelum cast opsi Filament, agar bentuk array
bersarang tidak diam-diam dibuang menjadi daftar kosong/OFF. Validasi backend
tetap berlaku, termasuk daftar duplikat dan otorisasi admin terkini.
P4b hanya pengelolaan konfigurasi: tidak membuat invoice, membuka tes,
mengubah harga, atau mengaktifkan kontrak checkout baru dengan sendirinya.
