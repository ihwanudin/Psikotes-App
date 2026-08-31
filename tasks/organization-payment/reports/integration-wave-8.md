# Review preview kolektif, browser checkout, dan keputusan replay

Tanggal: 2026-09-01. Baseline root e618f92.

## Delta dan keputusan

- Portal f4ca0c6 -> 521b49e: adapter preview read-only, test-only. Tes ditinjau
  sebelum source; principal dari sesi Filament dimuat ulang, hanya BranchAdmin
  dengan cabang persisted. Scope/proyeksi terbatas, label batch bukan N+1,
  foreign/nonexistent ID tanpa label dan seluruh total invalid. Bukan bulk UI,
  reservasi atau pembayaran. PG untuk query baru belum dibuktikan.
- Frontend 12f777c -> a84d9bb: dua helper dan laporan saja. Review mempertahankan
  10 kelompok lama, 9 optional dan 6 keyboard native; evaluate hanya membaca.
  Root melihat screenshot 320-optional dan 1280-mixed: logo, copy, form dan
  layout utuh. Worker menguji enam capture; root tidak menjalankan browser ulang.
- Backend d0ed7cf ditinjau, tidak diintegrasikan: delapan tes positif RED
  membuktikan konflik replay setelah payer dipilih. eb53cfd/5f4bb3b juga tetap
  ditahan. Tidak memasukkan tes gagal atau mengklaim action selesai.
- [ADR-005](../../../docs/decisions/0005-checkout-initial-funding-snapshot.md)
  menyetujui snapshot keputusan awal metadata server untuk implementasi lokal.
  Keputusan awal dibandingkan saat replay terpisah dari funding lifecycle;
  policy/scope/hash/terminal guard tetap berlaku. Snapshot hilang/invalid gagal
  tertutup, tidak backfill. Bukan perubahan pilihan bayar atau izin akses baru.

## Verifikasi root

- Focused portal/preview/access: **61 tes, 676 assertions lulus**.
- Regresi tests/Unit + tests/Feature + tests/Architecture memakai
  phpunit.organization-payment.xml, exclude-group sandbox:
  **804 tes, 4.200 assertions lulus**, durasi sekitar 101 detik.
- Pint adapter dan tes portal: lulus. PHPStan aplikasi: nol error, environment
  testing/SQLite memory dan cache/session array; tidak menggunakan .env.
- Kedua helper browser: node --check dan ESLint scoped lulus.
- Tidak mengulang SSR/build yang tidak berubah dalam increment helper; bukti
  sebelumnya 22 SSR lulus tetap historis. Bukti browser worker 10+9+6 kelompok,
  6 capture, 104 request lokal tanpa error/warning, bukan run root.
- Tidak mengulang PG root; hasil PG lama bukan bukti query adapter baru.
  Task portal menerima increment pembuktian PG khusus sebelum UI/wiring.
- Global lint wave-7 belum hijau: generated verification output ikut eslint .;
  bukan disamarkan sebagai lulus. Frontend diberi scope tooling sempit di bawah.

## Kelanjutan existing tasks — jangan dispatch ulang saat aktif

| Lane | Task | Cursor terakhir | Turn aktif |
| --- | --- | --- | --- |
| Backend | 01a05839-3b48-7801-8175-0392e8764c23 | 163af1e1-5f6a-4358-82dd-3e4d2134e4ed:21 | 01a058fb-74d4-7b93-bd19-16a2864690c7 |
| Frontend | 01a05839-3b39-7d83-b59f-9e7432d7883e | a428d2aa-49a3-4a6e-80ad-288f45f9a2ae:21 | 01a058fd-d426-7f42-9218-d9983d6836f6 |
| Portal | 01a05839-3b18-73e0-8fdc-8db3b02f835d | 4a41be93-41bc-4ef3-a96c-6fdb30836acb:18 | 01a058fb-e8d9-7751-9f9d-c45e882cc6bf |

- Backend: GREEN opsi A ADR-005, action/feature/PG P9a dan laporan. Key
  checkout_initial_funding_mode wajib hadir dan bertipe ketat, create-only.
  Tambahkan policy-history berbeda, absent/invalid snapshot, lifecycle preserved,
  invalid payer dan PG retry menunggu lifecycle commit. Tanpa schema/request/P10.
- Portal: tests/Postgres/CollectiveBillPreviewTest.php + laporan. Non-owner
  NOBYPASSRLS disposable, scope/role/tenant, context restored, read-only, query
  10 vs limit. Hilang/berubah scope di antara preview dan label diuji jika hook
  sintetis memungkinkan. Temuan defect dilaporkan sebelum mengubah adapter.
- Frontend: ownership sementara eslint.config.js, satu regression probe tooling
  bila diperlukan, laporan. Ignore hanya output generated yang ditetapkan repo;
  source/tests tetap diperiksa dan rules tidak dilemahkan. Jika config untracked
  worker, kirim patch delta, jangan commit snapshot. Error source unrelated hanya
  dilaporkan; tidak dependency upgrade atau perubahan produksi UI.

Tidak ada task/agent baru, reset/merge worker baseline, DB aktif, deploy/push,
gate/sumber/endpoint aktif, .env, pembayaran/notifikasi nyata. Semua lane berhenti
setelah increment berikut untuk review. P9a/P12/P16 tetap belum end-to-end.
