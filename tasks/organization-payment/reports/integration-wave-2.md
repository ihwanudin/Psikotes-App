# Review gelombang kedua

Tanggal: 2026-08-31. Pemilik: Koordinator.

## Lingkup hasil

- Frontend 6f72711: guard legalReviewPending pada tombol dan handler, dua tes SSR
  tambahan dan harness interaksi. Diterima sebagai P16-prep terisolasi.
- Portal 2e8ae42: tujuh tes PostgreSQL query/proyeksi, snapshot fixture valid,
  pengurangan query rendering dengan tautan detail dan otorisasi ulang tujuan.
  Diterima sebagai P12a-prep test-only, bukan portal publik.
- Backend f505c2f: proposal saja; **29 tes RED bukan implementasi selesai** dan
  tidak diintegrasikan. Kontrak disetujui untuk increment lokal di bawah.

Review Code Review dan Auth & Tenant Access memeriksa tests-first, handler guard,
membership persisted, route binding/action mount, minimisasi proyeksi, non-owner
FORCE RLS, dan keterbatasan bukti. Tidak ada perubahan dependency, schema, route
publik, key, database aktif, harga, scoring atau layanan eksternal.

## Keputusan kontrak backend (review koordinator)

Disetujui untuk implementasi lokal terbatas: credential opaque dengan
StringEncrypter framework existing, encryptString/decryptString dan JSON tanpa
unserialize. Source Laravel terpasang telah diperiksa. Framework menyediakan
authenticated encryption; parser/verifier aplikasi tetap wajib memeriksa klaim.
Rujukan: [Laravel 13 encryption](https://laravel.com/framework/docs/13.x/encryption).

- Version 1, purpose assessment-start, issuer/audience tepercaya terpisah dari
  legacy, participant/organization/attempt integer positif, iat/exp strict dengan
  umur maksimum 600 detik. Batas panjang input dan allowlist klaim; prefix saja
  bukan autentikasi. Issuer/audience tidak berasal dari Host header browser.
- Hanya bearer Authorization, bukan query string/cookie/URL. Tidak mencatat token,
  payload, PII atau secret pada log/error. Tidak memasukkan paid/consent ke klaim.
- Verifikasi token tidak menggantikan gate: reload scope/identity persisted dan
  jalankan gate P8a untuk setiap permintaan. Revoke attempt atau penarikan consent
  mengunci akses. Prinsipnya sesuai [OWASP authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).
- APP_KEY/cipher/previous keys menggunakan binding framework existing, tanpa
  regenerasi key atau config baru. Token dapat dipakai ulang selama TTL; ini bukan
  handoff sekali pakai dan tidak menjanjikan revocation token individual.
- Jalur produksi dan middleware legacy tidak diubah untuk menerima token baru.
  HTTP tests memakai route test-only + controller/middleware nyata. Controller
  tetap 501 SESSION_ENGINE_PENDING; engine sesi tidak dibangun dalam increment ini.
- Token checkout/legacy tidak dapat menjadi credential assessment. Scope request
  tidak boleh menimpa principal. Jangan menyalin settlement predicate ketiga kali.

| Aktor/kredensial | Akses attempt melalui adapter baru |
| --- | --- |
| Anonim, token rusak/expired atau purpose salah | Ditolak |
| Token legacy/checkout | Ditolak, tidak fallback |
| Token assessment valid, tenant/attempt asing atau prasyarat berubah | Ditolak |
| Token assessment valid, scope persisted dan semua prasyarat sah | Gate lolos; engine tetap 501 |

Keputusan ini tidak membuka endpoint publik, tidak mengirim token/notifikasi,
dan tidak menyetujui cutover. Backend sudah menerima instruksi implementasi;
hasilnya menunggu review terpisah, tidak termasuk commit frontend/portal ini.

## Verifikasi koordinator

Regresi gabungan sudah selesai pada checkout koordinator; hasil bukan angka
historis worktree pekerja. Commit proposal e1602d6, frontend 0e299aa dan portal
3a17d64 tersimpan lokal secara terpisah setelah verifikasi terkait lulus.

- PHPUnit Unit/Feature/Architecture dengan phpunit.organization-payment.xml,
  sandbox eksternal dikecualikan: **670 tes / 3.186 assertions**, lulus.
- Runner PostgreSQL disposable: **156 tes / 921 assertions**, lulus, termasuk
  tujuh tes portal baru bersama lima tes aktivasi backend gelombang pertama.
  Runner membersihkan resource uji; container aplikasi tidak ditargetkan.
- Node/SSR: 19 tes lulus, nol gagal/skip.
- TypeScript global dan targeted, ESLint targeted: lulus.
- Build Vite test dan preview terisolasi: lulus (bukan build aplikasi produksi).
- Pint: lulus. PHPStan: lulus, nol error.
- Query budget SQLite Livewire: 7 query pada masing-masing 10/25/50 baris.
- Query paginator resource PG: 3 query pada 10/25/50 baris; proyeksi detail
  sepuluh alokasi: 7 query. Tidak disamakan dengan latency atau load produksi.
- Git diff check: lulus; tidak ada .env, secret, database atau build output
  dalam daftar file integrasi.

Tidak ada pengujian browser ulang koordinator. Laporan frontend membuktikan 10
skenario interaksi melalui harness tetapi native keyboard tetap belum lulus:
event tool trusted=false. RequestSubmit probe bukan bukti keyboard.
Tes PG portal membuktikan query/proyeksi resource pada runtime non-owner dan
reuse koneksi/savepoint; bukan HTTP/Livewire PostgreSQL atau PgBouncer.

Frontend/portal berhenti pada checkpoint persiapan sambil menunggu dependensi
endpoint/settlement; tidak dipaksa mengubah kontrak atau membuka gate demi maju.
P8b/P12/P16 tetap belum selesai end-to-end.
