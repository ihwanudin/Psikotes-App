# Integration wave 27 — P12b browser acceptance

Tanggal: 2026-09-02

Hardening worker `f76be8d`, `4b92520`, dan `0a0d774` diintegrasikan sebagai
`279450f`, `d5cf4d2`, dan `cd59d1e`. Breadcrumb detail memakai label pendek
`Tagihan terpilih`; referensi lengkap tetap tersedia di ringkasan sehingga tidak
ada informasi penting yang disembunyikan.

Harness hanya melayani asset Filament lokal yang realpath-nya tetap di bawah
public dan mengganti avatar dengan data URI sintetis. CSP dan deny outbound tidak
dilonggarkan. Browser fresh fixture membuktikan selection/preview/confirm lama,
sembilan geometry check pada 320/390/1280 termasuk detail, reload canonical,
console/network bersih, dan direct-detail denial untuk guest, role salah, tenant
lama, serta bill asing tanpa kebocoran referensi/data.

DB fixture berakhir tepat pada 2 bill termasuk baseline claimed, 11 charge,
11 item, 2 audit, dan seluruh entitlement/outbox/order/consent/identity side
effect nol. Root menjalankan empat suite terkait P12a/P12b: **86 tes, 1.010
assertions**. PHP/Node syntax, Pint, dan diff-check lulus.

P12b diterima sebagai implementasi lokal default-off. Ini browser sequential
SQLite, bukan bukti race PostgreSQL. Tidak ada route/discovery produksi,
schema/toggle, Xendit/notifier, proof, reviewer/finalizer, command/job/scheduler,
DB aktif, migrasi, deploy, atau push. Tahap berikutnya P12c.
