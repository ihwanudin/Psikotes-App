# Integrasi core autentikasi assessment

Tanggal: 2026-08-31. Pemilik: Koordinator.

## Review dan lingkup

Commit pekerja ee7ba62 dan fe96239 ditinjau melalui skill Code Review dan Auth &
Tenant Access. Tes token/HTTP dibaca bersama implementasi; perubahan sesuai
persetujuan kontrak gelombang kedua. Tujuh file delta, tanpa konflik penerapan.

- Credential opaque memakai StringEncrypter existing, bukan JWT/HMAC buatan baru.
  Purpose/version/issuer/audience diperiksa setelah dekripsi, TTL maksimum 600
  detik, allowlist/type claims, batas input, exception tersanitasi.
- Middleware menerima Authorization Bearer saja, menolak ambigu/ganda, menghapus
  principal stale dan memuat ulang scope participant/organization/attempt.
- Controller menggunakan gate P8a pada setiap request assessment. Konteks service
  dibatasi lookup/gate, tidak diwariskan ke arbitrary downstream handler.
- Legacy start tetap kompatibel untuk input non-scope. Selector attempt dari
  browser ditolak; malformed assessment principal tidak fallback ke legacy.
- Route produksi tetap memakai autentikasi legacy. Middleware baru hanya dipasang
  pada route test-only; controller tetap **501 SESSION_ENGINE_PENDING**.

Tidak ditemukan blocker untuk integrasi **core lokal terbatas** ini. Issuance
method masih internal; belum ada producer, endpoint publik, consumer notifikasi
atau engine sesi. Enkripsi bukan bukti paid/consent. Replay dalam TTL masih
dimungkinkan; revocation individual token tidak diklaim.

Wiring publik masih memerlukan review ProvidesRlsContext/middleware order dan
bukti PostgreSQL HTTP. Engine nanti wajib lock/reload gate/transition dalam
transaksi yang sama. Read-only gate bukan tiket yang dapat disimpan untuk akses
belakangan. P8b keseluruhan tidak dicentang selesai.

## Verifikasi koordinator

- PHPUnit Unit/Feature/Architecture dengan phpunit.organization-payment.xml,
  sandbox eksternal dikecualikan: **728 tes / 3.367 assertions**, lulus tanpa skip.
  Mencakup 31 tes token dan 27 tes HTTP baru.
- Pint seluruh proyek: lulus. PHPStan seluruh cakupan proyek: nol error.
- Regresi PostgreSQL disposable: **156 tes / 921 assertions**, lulus. Runner
  membersihkan resource uji miliknya, tidak menargetkan container aplikasi.
  Ini regresi DB existing, bukan bukti route HTTP assessment baru pada PostgreSQL.
- Staged diff check lulus. Tidak ada .env, key, database atau artefak runtime
  dalam perubahan yang diintegrasikan.

Tujuh tes render auth yang gagal di worktree backend karena manifest Vite tidak
tersedia berhasil dalam regresi checkout koordinator. Ini bukti integrasi di
induk, bukan klaim worktree pekerja telah dibuild/diperbaiki. Tidak menambahkan
manifest palsu, menonaktifkan Vite atau melonggarkan harness.
Tes frontend/keyboard tidak diulang karena tidak ada perubahan frontend pada
slice ini; bukti sebelumnya tetap pada checkout-keyboard-verification.md.

## Dependensi berikutnya

Skill Planning & Task Breakdown memisahkan core P8b dari integrasi writer agar
dependency tidak melingkar: P11a/P15 membutuhkan core aktivasi, tetapi pemanggilan
dari kedua writer itu belum dapat dibuktikan sebelum writer dibuat. Acceptance
gabungan tetap disimpan di todo.md; tidak dihapus atau dianggap selesai.

P9a dibatasi action internal provisioning + tes: client/sumber/paket persisted,
policy/opt-in existing, profil tersedia, attempt PROVISIONED dengan marker
checkout-v2 dari server, atomic/idempotent, tidak ada ready entitlement/bill/
invoice/credential/notifikasi. Schema/rute/shared config/v1 tidak diubah tanpa
review. P9 publik tetap belum selesai dan semua sumber aktif tetap tidak diubah.

Portal cabang masih mengerjakan uji keyboard/responsive terpisah pada tasknya;
tidak digabung saat turn pekerja masih berjalan. Tidak ada deploy/push, migrasi
database aktif, pembayaran, pengiriman pesan atau perubahan Cloudflare/n8n.
