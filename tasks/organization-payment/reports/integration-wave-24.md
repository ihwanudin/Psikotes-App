# Integration wave 24 — checkpoint P12a dan dispatch P12b

Tanggal: 2026-09-01

## P12a

P12a-prep yang sebelumnya telah diintegrasikan diterima sebagai implementasi
lokal default-off setelah P11c selesai. Resource OrganizationBills memakai query
tenant-scoped untuk BranchAdmin persisted, menolak direct URL/role/tenant salah,
dan hanya memproyeksikan ringkasan bill serta attempt yang aman. Filter paid
menjadi riwayat dari tabel bill yang sama; tidak ada ledger atau writer kedua.

Bukti yang sudah direview mencakup focused root **14 tes / 210 assertions**,
PostgreSQL disposable runtime non-owner, serta browser Laravel/Filament nyata
pada desktop/mobile. Resource tetap tidak ditemukan di environment non-testing.
Tidak ada route/menu produksi, invoice, upload bukti, verifikasi, atau pembayaran
yang diaktifkan.

## P12b

Task portal existing menerima increment P12b core default-off. Scope dibatasi
pada pemilihan attempt eligible milik cabang, preview server-authoritative, dan
satu delegasi ke `ReserveAssessmentBill` canonical. Reauthorization persisted,
stale preview, replay, perubahan harga/status/tenant, duplicate, self/free/
legacy/non-checkout-v2, serta role/tenant denial wajib dibuktikan.

Increment tidak boleh mengaktifkan discovery/route produksi, menambah schema atau
toggle, memanggil Xendit/notifier, mengunggah proof, mengubah reviewer/finalizer,
atau membuat command/job/scheduler. Semua tes memakai fixture sintetis dan DB
disposable sesuai dampak.
