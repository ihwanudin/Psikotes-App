# Integration wave 25 — P12b core dan review fix

Tanggal: 2026-09-01

Rangkaian worker `addccee`–`97f3774` ditahan pada review pertama. Page menangkap
seluruh `Throwable`, sehingga kegagalan database atau programmer dapat disamarkan
sebagai stale preview. Bukti reauthorization setelah preview dan lifecycle
checkbox Livewire sampai konfirmasi juga belum ada.

Fix worker `2f9c4ff`/`55672a9` membatasi pemetaan UI pada exception domain,
authorization, dan input invalid. Runtime/DB failure sekarang diteruskan ke
handler. Fault injection pada pembuatan AssessmentBill membuktikan transaksi
meninggalkan bill, item, charge, dan audit sebanyak nol. Perubahan role, cabang,
atau deleted membership setelah preview ditolak tanpa write.

Lifecycle Livewire menerima nilai checkbox digit-string seperti browser,
mengkanonisasi ID positif, memproses consultation dan payment method, lalu
preview→confirm→redirect ke bill canonical. Perubahan selection/consultation
menghapus preview dan mencegah confirm lama. Harga/policy/writer tetap milik
PreviewAssessmentBill dan ReserveAssessmentBill existing.

Seluruh commit diintegrasikan root sebagai `e872b4b`–`ec86591`. Verifikasi root:

- focused P12b: **19 tes, 61 assertions**;
- Pint scoped: lulus;
- PHPStan scoped: 0 error;
- `git diff --check`: bersih.

Tidak ada route/discovery produksi, schema/toggle, Xendit/notifier, proof,
reviewer/finalizer, command/job/scheduler, DB aktif, migrasi, deploy, atau push.
Browser acceptance P12b tetap diperlukan sebelum acceptance P12b ditutup.
