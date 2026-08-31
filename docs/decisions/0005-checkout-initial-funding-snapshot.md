# ADR-005: Pisahkan keputusan funding awal dari lifecycle checkout

## Status

Accepted for local implementation and review; no public wiring or active data migration.

## Date

2026-09-01

## Context

ADR-004 mengizinkan provisioning tanpa payer. Review action P9a eb53cfd menemukan
replay payload/key sama ditolak setelah funding lifecycle dipilih, karena action
membandingkan funding mutable dengan hasil resolver payload awal. Reproduksi
d0ed7cf menghasilkan delapan kegagalan positif. Input yang sama dapat menghasilkan
keputusan awal berbeda akibat lock/satu pilihan policy; request_hash saja tidak
merekam keputusan itu. Dua histori dapat mempunyai funding/hash/policy akhir sama.

## Decision

- Pada create P9a saja, server menulis metadata.checkout_initial_funding_mode.
  Key wajib hadir; nilai sah hanya null, COMMERCIAL_SELF_PAY atau
  INVOICED_TO_ORGANIZATION, dari hasil resolver awal. Bedakan key hilang dari
  nilai null eksplisit. Payload tidak boleh memasok field ini.
- Snapshot awal bersifat immutable bagi writer aplikasi dan tidak ditulis ulang
  saat retry atau pemilihan payer selanjutnya. Ini kontrak aplikasi, bukan klaim
  bahwa constraint database baru telah menegakkan immutability.
- Replay tetap memeriksa scope, request hash, versi, registry dan policy terkini.
  Hasil keputusan resolver atas input awal harus cocok snapshot awal, bukan
  dibandingkan langsung dengan funding lifecycle. Perubahan keputusan otomatis
  akibat policy tetap konflik; policy tidak sah tetap ditolak.
- Periksa funding lifecycle secara terpisah memakai PayerDecision existing:
  initial null boleh tetap null atau dipilih menjadi payer yang masih diizinkan;
  initial selected harus tetap payer yang sama, tidak berganti atau menjadi null.
  Pemeriksaan ini tidak menetapkan pembayaran/consent/identitas/akses sebagai sah.
- Retry yang sah mengembalikan attempt yang sama tanpa mengubah profil, funding,
  status, snapshot, hash atau menghasilkan side effect. Revoked/void tetap ditolak.
- Snapshot absent/invalid ditolak tanpa menebak dari funding mutable, tanpa
  backfill atau mutasi pada retry. Action belum live/terintegrasi; tidak ada izin
  migration data aktif. V1, schema, request dan endpoint tidak diperluas.

## Alternatives Considered

Membandingkan langsung funding mutable merusak retry normal. Menghapus seluruh
perbandingan keputusan awal memang lebih kecil tetapi melemahkan guard policy
yang sudah disepakati. Merekonstruksi histori dari request_hash tidak mungkin
ketika input payer kosong dan policy lama tidak tersedia. Snapshot server kecil
dipilih untuk mempertahankan dua invariant tersebut tanpa writer/schema baru.

## Consequences

P9a tetap belum diterima sampai tes RED menjadi GREEN, termasuk snapshot hilang/
invalid, riwayat keputusan berbeda, policy berubah, payer lifecycle tidak sah,
profil/status preserved, revoked/void dan PG retry menunggu commit lifecycle.
Tes memakai state sintetis, bukan bukti alur settlement atau akses end-to-end.
Writer lifecycle berikutnya harus mempertahankan key ini. Tidak ada hak untuk
mengaktifkan route/gate, menjalankan pembayaran/notifikasi atau deploy.
