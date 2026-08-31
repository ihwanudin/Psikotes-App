# Heartbeat review P9a0 dan tindak lanjut

Tanggal: 2026-09-01.

## Backend schema: lulus integrasi lokal

Backend b6589d5 diintegrasikan bfc0587 setelah review migration, PHPDoc dan
seluruh tes feature/PG baru. Delta PHPDoc funding_mode pada model
AssessmentParticipant diterapkan satu baris di root, bukan menyalin baseline
untracked worker. Tidak ada perubahan writer, endpoint atau konfigurasi aktif.

Review: NULL funding dibatasi marker checkout-v2 dan PROVISIONED/REVOKED/VOID;
COALESCE mencegah metadata hilang lolos SQL UNKNOWN. PG mempertahankan enum,
length, FK/index/RLS; rollback mengunci kedua tabel sebelum scan dan menolak
data NULL serta scan terfilter RLS. DDL hanya dites di database disposable.
SQLite memakai rebuild transactional dan trigger setara; transaksi luar ditolak
agar PRAGMA FK efektif, foreign_key_check dan pemulihan PRAGMA diperiksa.
Lock ACCESS EXCLUSIVE berarti bukan migrasi tanpa downtime pada database aktif.

Verifikasi root:

- PHPUnit tests/Unit, tests/Feature, tests/Architecture, exclude-group sandbox,
  konfigurasi phpunit.organization-payment.xml: **751 tes/3.491 assertions lulus**.
- Runner tools/testing/run-org-postgres.ps1: **196 tes/1.070 assertions lulus**,
  role runtime non-owner/NOBYPASSRLS. Container/network/tmpfs milik runner dibersihkan.
  Jumlah berbeda dari worker karena root sudah memuat delta lane portal/token lain.
- Pint lima file PHP perubahan dan PHPStan seluruh aplikasi: lulus, nol error.
- Tidak menjalankan migrasi DB aktif, gateway, notifikasi nyata atau deploy.

## Frontend: bukti visual ditolak, koreksi telah dikirim

e7a0fd3 menambah guard visual RED: utility CSS lobby tidak tersertakan pada
fixture. Source app.css dan fixture checkout pembanding mendukung usulan @source
eksplisit. Ini tidak membatalkan sembilan tes perilaku teks/fetch, tetapi hasil
geometri fixture polos tidak dianggap bukti UI produksi. Commit RED belum
diintegrasikan root; tunggu pasangan GREEN setelah perbaikan CSS khusus fixture.

Task frontend sudah menerima kelanjutan: tambah CSS entry test-only dan import
preview, pertahankan guard, ulang 9 kasus perilaku dan 12 capture 320/390/1280.
Tidak boleh mengubah CSS/komponen produksi untuk menutupi masalah harness.
Cursor setelah dispatch: a428d2aa-49a3-4a6e-80ad-288f45f9a2ae:11 (aktif).

## Kelanjutan yang disetujui

Backend: request profile.intendedField opsional/nullable checkout-v2 dengan enum
existing serta tes valid/invalid/omitted/NULL/unknown-field/v1 unchanged. Tidak
menambah default UMUM atau action/route. Ownership request shared tersebut, tes
kontrak khusus, laporan. Review sebelum provisioning action.

Portal: fallback tampilan nama null/blank pada tabel AssessmentParticipant dan
Order saja; pertahankan identifier existing, scope, query/search/sort dan akses.
Ownership dua resource, tes focused baru, laporan. Tidak menambah data sensitif,
writer atau membuka gate. Migration P9a0 root boleh dijadikan overlay identik
untuk tes worker saja tanpa commit ulang; model/schema/config lain tidak diubah.

Cursor backend sebelum kelanjutan: 163af1e1-5f6a-4358-82dd-3e4d2134e4ed:13,
portal: 4a41be93-41bc-4ef3-a96c-6fdb30836acb:9. Kelanjutan dikirim satu kali
pada checkpoint ini; cek status terbaru sebelum mengirim instruksi lagi.
Heartbeat existing tetap aktif; tidak membuat automation, task atau agent baru.
