# ADR-014: Command pembayaran checkout privat

## Status

Accepted untuk implementasi lokal bertahap dan default OFF. Keputusan ini tidak
mengizinkan aktivasi route, provider nyata, migrasi database aktif, deploy, atau
cutover sumber.

## Date

2026-09-05

Amended 2026-09-05: the zero-price writer is invoked by the authenticated
payment command, not by confirmation. The payment command is the only accepted
contract carrying the explicit consultation choice; confirmation continues to
own profile completion and both mandatory consents.

## Context

ADR-012 menyediakan sesi checkout privat yang terikat satu attempt. Ringkasan
P14/P16 saat ini read-only dan selalu memproyeksikan `actionAvailable=false`.
Peserta bayar sendiri belum mempunyai command HTTP untuk membuat atau melanjutkan
tagihan canonical. Payer organisasi harus tetap menunggu pembayaran cabang dan
tidak boleh menerima URL invoice kolektif. Jalur harga nol juga belum mempunyai
writer produksi; preview mengenali item gratis, tetapi reservasi sengaja tidak
membuat bill untuk selection yang seluruhnya nol.

Command uang tidak boleh memakai principal hasil hydration sebagai authority
yang berlaku setelah transaksi selesai. Revocation, recovery, perubahan policy,
harga, metode, atau graph session dapat terjadi setelah middleware membaca sesi.

## Decision

### HTTP contract

Tambahkan secara bertahap satu endpoint fixed:

```text
POST /checkout/payment
Content-Type: application/json
Body exact: {"consultationRequested": boolean}
```

Request tidak menerima organization, participant, package, attempt, payer,
currency, amount, payment method, bill/reference, URL, atau idempotency key.
Semua fakta tersebut dimuat dari graph persisted dan katalog server. Pilihan
konsultasi adalah satu-satunya input produk dan tetap divalidasi terhadap paket.

Sukses create maupun replay memakai HTTP 200 dengan bentuk tetap:

```json
{"data":{"paymentState":"pending|paid","paymentUrl":"https://...|null"}}
```

URL hanya boleh berupa HTTPS persisted milik bill self yang sama dan berstatus
pending. Organization, unselected, recovery-required, expired, rejected, corrupt,
revoked, dan konflik immutable mengembalikan 409 generik tanpa detail existence.
Transport invalid tetap mengikuti boundary checkout: 419 untuk origin/CSRF/fetch
dan 422 untuk JSON/body. Implementasi/config unavailable memberi 503 generik;
unexpected failure tetap framework 500 terlapor dan tersanitasi.

### Authentication, transaction, and locks

Route tetap di luar middleware `web` dan memakai urutan privacy boundary,
feature gate, named mutation throttle, checkout-session authentication, lalu
strict same-origin JSON+CSRF. Payment mempunyai `enabled`, `writer_enabled`, dan
body limit typed sendiri; semuanya default false dan tidak ikut menyala ketika
summary atau confirmation diaktifkan.

Writer menerima credential/session selector internal dan memuat ulang serta
mengunci graph canonical dalam service transaction:

```text
organization -> client -> source -> package/items -> attempt -> participant
-> handoff history -> checkout sessions
```

Reservation terjadi sebelum authority transaction dilepas. Provider tidak pernah
dipanggil di dalam transaction atau RLS context. Sesudah commit, writer memakai
primitive claim/issuance/reconciliation P10 yang sudah ada.

### Self payment and idempotency

Hanya funding persisted `COMMERCIAL_SELF_PAY` dan policy server current yang boleh
membuat bill self. Metode `xendit` dipilih server secara exact dan harus aktif serta
tidak ambigu. Preview dan reservation canonical menangkap harga integer IDR dari
database. Stable purpose key diturunkan dari immutable attempt, bukan browser atau
retry, lalu unique constraint dan request hash membedakan replay exact dari payload
berubah. Existing bill divalidasi sebelum preview baru agar pending/paid/unknown/
terminal tidak pernah menghasilkan bill kedua.

### Organization and free paths

Funding `INVOICED_TO_ORGANIZATION` tetap read-only pada checkout peserta:
`actionAvailable=false`, tanpa command, reference, batch total, member, proof,
atau payment URL.

Harga total nol tidak membuat bill dan tidak memanggil provider. Tambahkan primitive
internal `SettleZeroPriceCheckout` yang hanya dipanggil command pembayaran setelah
consent psychotest dan DASS current tersimpan. Command membawa pilihan konsultasi
eksplisit dan, dalam transaction authority yang sama, memuat ulang kedua consent,
policy/katalog, lalu menulis atau memvalidasi charge snapshot nol,
`free_settled_at`, dan audit secara idempotent, lalu mencoba aktivasi. Identity yang
belum lengkap membiarkan attempt settled tetapi locked. Kegagalan audit, aktivasi,
atau outbox menggulung seluruh perubahan gratis; tidak ada partial entitlement.

### Privacy

Seluruh response memakai header privat ADR-012. Response/error/log tidak memuat
ID internal, batch, total kolektif, gateway reference/body, credential/digest,
policy, SQL, atau PII. Payment URL hanya muncul pada sukses pending milik peserta
self yang sama dan tidak dicatat ke log/telemetry.

## Alternatives Considered

### Controller menyusun preview, reservation, dan issuance langsung

Ditolak karena principal middleware adalah snapshot dan membuka race antara
hydration dengan mutation uang. Predicate lifecycle juga akan terduplikasi.

### Browser mengirim nominal, metode pembayaran, payer, atau idempotency key

Ditolak karena menjadikan client authority atas harga/pembayar dan memungkinkan
retry memakai intent baru sehingga bill atau charge dapat tergandakan.

### Item gratis dibuatkan invoice Rp0

Ditolak karena menambah provider side effect tanpa nilai, mengaburkan settlement,
dan bertentangan dengan reservation existing yang memisahkan item gratis.

### Organization menerima URL invoice kolektif dari checkout peserta

Ditolak karena membocorkan capability bill cabang dan memperluas hak peserta ke
resource batch yang bukan miliknya.

## Consequences

- Implementasi dimulai dari seam mutation session bertipe dan RED tests, bukan
  route/controller.
- Jalur self memakai primitive billing P7/P10 yang ada; tidak ada invoice engine
  kedua.
- Jalur gratis membutuhkan primitive baru dan routing dari command pembayaran,
  tetapi tidak membutuhkan schema baru atau perluasan payload confirmation.
- PostgreSQL disposable wajib membuktikan replay/concurrency, revocation, policy
  change, dan atomic rollback. SQLite/feature test tidak dianggap bukti race.
- Route produksi baru boleh diregistrasikan default OFF setelah internal actions,
  security tests, dan adapter HTTP lolos review terpisah.
