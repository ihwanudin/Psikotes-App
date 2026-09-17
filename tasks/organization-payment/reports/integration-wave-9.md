# P9a replay GREEN, preview PG dan cakupan lint

Tanggal: 2026-09-01. Heartbeat lanjutkan-task-psikotes-setelah-selesai.
Baseline root c2871c5. Semua worker idle sebelum instruksi berikut dikirim.

## Delta yang direview dan diintegrasikan

- Backend eb53cfd + 5f4bb3b + d0ed7cf + 894f059 diterapkan sebagai satu rangkaian
  yang berakhir GREEN: abcce1f + b24cd56 + 78ab84a + e540983. Commit RED di tengah
  hanya merekam reproduksi; bukan checkpoint siap pakai. Action P9a menyimpan
  profil parsial tanpa placeholder/rights, identitas exact org/source/candidate,
  registry reload, mutex organisasi dan request/logical idempotency. ADR-005
  memisahkan snapshot awal immutable dari funding lifecycle untuk replay.
- Review fix: key snapshot wajib hadir, null eksplisit sah, enum/tipe ketat;
  payload tidak dapat memasok snapshot. Policy baru tetap diperiksa dan keputusan
  awal berubah tetap konflik meski funding lifecycle kebetulan cocok. Payer awal
  selected tidak boleh berganti/NULL; terminal tidak dibuka. Profil/status/hash
  tidak ditulis ulang pada replay. Tes feature/PG dan bukti worker dibaca.
- Portal b8e3f6c -> 0c64314: hanya tes PG dan laporan. Memanggil adapter nyata,
  runtime non-owner NOBYPASSRLS/FORCE RLS, tenant/proyeksi/role persisted, pemulihan
  context sebelum helper dapat memulihkan GUC, semua kolom efek dibandingkan,
  query 10/100 bounded. Hook participant berubah adalah satu koneksi, bukan
  klaim transaksi konfirmasi paralel atau immutabilitas identitas.
- Frontend 723bf2b -> 563fa6e: global ignores hanya tiga direktori generated.
  Tes membuktikan source/tests/tetangga tetap included dan rule curly menolak
  kode invalid. Tidak melemahkan rules atau mengubah source unrelated.

## Verifikasi root

- Full PHP tests/Unit + tests/Feature + tests/Architecture dengan
  phpunit.organization-payment.xml, exclude-group sandbox:
  **880 tes / 4.652 assertions lulus**, sekitar 100 detik.
- PostgreSQL runner disposable: **222 tes / 1.659 assertions lulus**, tanpa skip,
  durasi suite 54,956 detik (bootstrap terpisah). Preview 10 dan 100 item sama-sama
  **14 query**. Run ID ed02da8e7e804464a55d52f54914a2af, cleanup sukses.
- Pint action dan tiga file tes delta: lulus. PHPStan aplikasi: nol error.
- ESLint scope probe **2/2 lulus**; global ESLint root **exit 0**, tidak ada file
  dengan error/warning. JSON ringkasan ignored di
  storage/app/private/verification/eslint-wave-9.json. Worker masih punya 14 error
  import/order baseline, tetapi itu bukan error root dan tidak perlu diperbaiki
  dengan menyalin snapshot worker.
- Seluruh konfigurasi PHP testing eksplisit, SQLite memory/PG tmpfs internal,
  cache/session array dan fake key. Tidak menggunakan .env atau data aktif.
- Browser/SSR/build tidak diulang karena delta frontend kali ini tooling saja;
  bukti browser wave-8 dan SSR wave-7 tetap historis. Tidak mengklaim E2E checkout.

P9a internal diterima lokal. P9 keseluruhan, P8b public gate, P12/P16 dan checkout
end-to-end belum selesai. Tidak ada deploy/push, route/sumber aktif, notifikasi
atau pembayaran nyata; tidak ada proses root test tersisa setelah perintah selesai.

## Kelanjutan tepat satu kali pada task existing

| Lane | Task | Cursor terakhir | Turn aktif |
| --- | --- | --- | --- |
| Backend | 01a05839-3b48-7801-8175-0392e8764c23 | 163af1e1-5f6a-4358-82dd-3e4d2134e4ed:23 | 01a0590d-331f-7dd2-874a-0da5ed75bc6a |
| Frontend | 01a05839-3b39-7d83-b59f-9e7432d7883e | a428d2aa-49a3-4a6e-80ad-288f45f9a2ae:23 | 01a0590b-daf3-7410-a20d-63eb44bdbe01 |
| Portal | 01a05839-3b18-73e0-8fdc-8db3b02f835d | 4a41be93-41bc-4ef3-a96c-6fdb30836acb:21 | 01a0590d-3735-7b61-a28a-32383602915f |

- Backend P9b: controller HTTP **tanpa route produksi**, route test-only memakai
  middleware HMAC dan FormRequest existing. Respons minimal camelCase setara v1,
  201/200, error generik/409, no-store, service context sesudah auth. Tes signature,
  stale/tampered/client disabled, opt-in OFF, scope/key/replay/no side effect.
  Empat file: controller baru, CheckoutProvisioningHttpTest, kontrak checkout
  docs (koreksi intendedField/ADR-005/status internal), laporan. Shared routes,
  middleware/config/schema/v1 tidak diubah; kebutuhan shared dilaporkan dahulu.
- Frontend: varian CheckoutPayment DRAFT payer belum dipilih, readonly. Tanpa
  fallback self/organization atau CTA bayar/handler baru; null/0 bukan gratis.
  Scope type/komponen payment/fixture/SSR/laporan; browser bukti commit terpisah
  jika diperlukan. API/mapper/P15 tidak dibuat; state/access tetap dari server.
- Portal: komponen preview selection Filament/Livewire **di tests/Support**, view
  testing, feature test dan laporan; tidak autodiscovery resource publik. Daftar
  fixture server, checkbox attempt+konsultasi, Tinjau memanggil adapter existing.
  Perubahan pilihan menghapus preview/hash lama, render seluruh pilihan dan
  reason/total invalid, direct-action tampering/auth diuji. Tidak reserve/pay,
  draft resume/session, writer, public route atau server browser baru dahulu.

Heartbeat berikut memakai cursor ini; jangan menduplikasi instruksi saat aktif.
Semua lane stop setelah increment untuk review. Baseline/overlay worker tetap,
tidak ada task/agent baru atau izin mengaktifkan endpoint/gate/sumber.
