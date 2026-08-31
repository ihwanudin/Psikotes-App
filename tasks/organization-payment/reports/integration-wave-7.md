# Review replay provisioning dan kelanjutan tiga lane

Tanggal: 2026-09-01. Heartbeat `lanjutkan-task-psikotes-setelah-selesai`.
Baseline root ae3a791; tidak mengubah baseline worktree worker.

## Review dan integrasi

- Frontend 1650115 diintegrasikan sebagai 83e116d: field email opsional kosong
  tidak memaksa konfirmasi; nilai kosong tidak dikirim ketika field wajib atau
  consent tetap perlu konfirmasi. Guard legal pending, busy, callback dan consent
  tetap berlaku. Dua komponen, fixture, SSR dan browser helper telah dibaca.
- Portal 6a7aaf7 diintegrasikan sebagai 261aecc: proposal seleksi kolektif,
  **DRAFT**, bukan UI/writer atau acceptance P12b. Reason/error, scope organisasi,
  claim permanen dan matriks 10 peserta konsisten dengan batas internal existing.
- Backend eb53cfd + 5f4bb3b **belum diintegrasikan**. Action, feature tests dan
  PG concurrency tests telah dibaca. Review menemukan replay membandingkan
  funding_mode lifecycle dengan funding hasil payload provisioning awal.
  Provision tanpa payer lalu pilihan payer sah dapat membuat retry payload/key
  identik konflik. Belum direproduksi root; backend diminta membuktikan dahulu,
  membedakan keputusan awal dari lifecycle tanpa menghapus guard policy.
  Kontrak yang belum jelas wajib dilaporkan sebelum perubahan semantik.

## Verifikasi root dan batas

- Build SSR fixture dan `node --test` checkout: **22 tes lulus**, nol skip/fail.
- `node node_modules/typescript/bin/tsc --noEmit`: lulus.
- ESLint empat file TS/TSX delta, browser helper dan build preview fixture: lulus. Output JS
  247,37 kB (gzip 76,99), CSS 73,98 kB (gzip 12,49), privat/ignored.
- `npm run lint:check` global gagal (44.154 errors): cakupan `eslint .` turut
  memeriksa bundle generated di storage/app/private/verification. Tidak mengubah
  bundle atau konfigurasi global untuk menutupi hasil. Lint scoped di atas
  adalah bukti delta saja, bukan klaim seluruh repository lint bersih.
- Angka browser 9 kelompok berasal dari worker, bukan run root. Gabungan 10
  kelompok existing + 9 kelompok baru, keyboard native dan reflow sedang diuji
  ulang pada task frontend; belum dinyatakan selesai.
- PHP/PG tidak diulang: tidak ada delta executable PHP yang diintegrasikan.
  Bukti root sebelumnya 779/3782 dan PG 196/1070 tetap historis, bukan run baru.
- Tidak menyalakan endpoint/gate/sumber, menggunakan .env/DB aktif, membuat
  invoice/WA nyata, deploy atau push. Backend acceptance P9a tetap unchecked.

## Penugasan yang sudah dikirim tepat satu kali

Semua task idle sebelum dispatch dan aktif pada snapshot sesudah dispatch.

| Lane / task | Cursor terakhir | Turn baru |
| --- | --- | --- |
| Backend 01a05839-3b48-7801-8175-0392e8764c23 | 163af1e1-5f6a-4358-82dd-3e4d2134e4ed:19 | 01a058ed-a234-7aa1-ad21-aa12750635c1 |
| Frontend 01a05839-3b39-7d83-b59f-9e7432d7883e | a428d2aa-49a3-4a6e-80ad-288f45f9a2ae:17 | 01a058ee-104c-7bc0-826e-d9227445aa8a |
| Portal 01a05839-3b18-73e0-8fdc-8db3b02f835d | 4a41be93-41bc-4ef3-a96c-6fdb30836acb:15 | 01a058ed-a41b-79b2-a7a1-30b5e6a30a00 |

- Backend: reproduksi/regresi replay setelah payer/profil/status lifecycle sah;
  tetap tolak payload berbeda, policy tidak sah dan revoked. Scope action P9a,
  tes feature/PG P9a dan laporan; tidak schema/request/routes/P10. Bila kontrak
  belum tegas, serahkan bukti/opsi sempit dahulu. Stop setelah satu increment.
- Frontend: gabungan interaksi lama/baru, Tab/Space/Enter native, fokus invalid,
  consent/guard/reset, styled reflow 320/390/1280 optional-only dan mixed fields.
  Maksimal dua file harness + laporan, tanpa perubahan produksi. Bug produksi
  dilaporkan dahulu. Data sintetis test-only tanpa backend; stop setelah bukti.
- Portal: adapter PreviewCollectiveBillSelection test-only read-only, tes
  CollectiveBillPreviewTest dan laporan. Principal BranchAdmin persisted,
  organisasi dari membership, reuse preview backend, whitelist label/biaya,
  foreign ID tidak bocor. Tes 10 mixed-package, all-free, invalid, role/tenant/
  membership berubah dan tidak ada side effect. Tidak memasang bulk action,
  reserve, writer, flag atau public routes; PG disposable sebagai increment
  bukti terpisah bila diperlukan. P10/P11/resume intent tetap dependensi.

Heartbeat berikut membaca cursor ini sebelum dispatch; jangan menggandakan tugas
selama aktif. Review hasil baru sebelum integrasi dan penugasan selanjutnya.
