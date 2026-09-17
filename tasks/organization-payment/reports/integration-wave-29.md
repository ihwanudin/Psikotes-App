# Integration wave 29 — blocker browser P12c

Tanggal: 2026-09-02

Commit worker `628d6bd` dan `27a9bc9` diintegrasikan sebagai `3f84181` dan
`bf82667` untuk mempertahankan harness serta bukti blocker yang jujur. Fixture
menambah bill manual pending, private payment-proofs temp, berkas sintetis,
alias URL lokal no-store/no-referrer, dan control role/tenant/status/replacement.
Tidak ada route/config/schema produksi.

P12c browser belum lulus. Keyboard native mencapai tombol upload dan Livewire
memasang action `uploadProof` dengan record benar, tetapi dialog Filament/Alpine
tetap tersembunyi (`x-show=false`) sehingga FileUpload tidak actionable. Tidak
ada manipulasi DOM atau event palsu yang diterima sebagai bukti.

Tes PHP tetap lulus: storage+P12c **50/278**, P12a **14/212**, dan P12b
**72/798** pada worker. Regresi browser P12b tetap hijau. Root memverifikasi PHP
syntax, Pint, dan diff-check harness. Server/session berhenti dan port 8012 bebas.

Langkah berikutnya adalah reproduksi minimal action modal Filament 5 menggunakan
asset/layout yang sama. Jika modal minimal gagal, harness diperbaiki; jika modal
minimal lulus, konfigurasi header action P12c diperbaiki dengan TDD. Acceptance
P12c tetap terbuka dan P13 belum dimulai.
