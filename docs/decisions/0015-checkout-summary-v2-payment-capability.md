# ADR-015: Capability pembayaran pada checkout-summary-v2

## Status

Accepted untuk implementasi lokal bertahap dan default OFF. Keputusan ini tidak
mengaktifkan route, provider, sumber integrasi, atau deployment.

## Date

2026-09-05

## Context

`checkout-summary-v1` sengaja menetapkan `payment.actionAvailable=false` dan
masih mengizinkan presentasi DASS `not_applicable`. Command privat pada ADR-014
sudah tersedia default OFF, sedangkan UI memerlukan pilihan konsultasi dan total
yang berasal dari katalog server. Memperlebar v1 diam-diam akan merusak kontrak
exact-key dan membuat browser berpotensi menghitung harga sendiri.

## Decision

Kontrak aktif menjadi `checkout-summary-v2`. Enam fakta pembayaran lama tetap
ada. Tambahkan pasangan invariant:

```text
actionAvailable === (action !== null)
```

`action`, bila ada, berbentuk exact:

```json
{
  "path": "/checkout/payment",
  "mode": "select|continue",
  "currency": "IDR",
  "choices": [{
    "consultationRequested": false,
    "baseAmountIdr": 99000,
    "consultationAmountIdr": 0,
    "amountIdr": 99000
  }]
}
```

Pilihan tidak kosong, unik, berurutan `false` lalu `true`, dan seluruh nominal
adalah integer aman nonnegatif dari snapshot katalog server. Browser tidak
menghitung base, addon, atau total. Mode `continue` tepat satu pilihan immutable.

Capability hanya diproyeksikan di dalam transaksi lifecycle yang telah mengunci
graph sesi, organisasi, client, source, paket/item, attempt, policy, consent, dan
bukti charge/bill terkait. `CheckoutPaymentFacts` serta `readPayment()` tetap
historis dan bukan authority. Command POST selalu memuat ulang authority walaupun
summary baru saja diterima.

Payer organisasi dengan total positif selalu `action=null` dan tidak menerima
URL, reference, total batch, anggota, bukti, atau capability invoice. Pengecualian
organisasi total tepat nol hanya boleh menawarkan pilihan tanpa konsultasi setelah
kedua consent current. DASS-21 wajib menjadi bagian komposisi psikotes; v2 tidak
memiliki state DASS `not_applicable`.

Semua gate payment tetap literal false secara default. Saat OFF, malformed, stale,
terminal, recovery, policy/catalog drift, atau evidence korup, summary menampilkan
`actionAvailable=false` dan `action=null` tanpa memperbaiki data.

## Consequences

- PHP dan TypeScript mempunyai kontrak v2 exact-key terpisah; v1 tidak diubah
  menjadi aktif secara diam-diam.
- Blade hanya merender pilihan yang diberikan server dan enhancer hanya mengirim
  boolean terpilih ke endpoint fixed ADR-014.
- Browser acceptance baru boleh dilakukan setelah v2, Blade, dan enhancer lulus
  tes tanpa menyalakan konfigurasi publik.
