# Preflight UI read-only checkout-summary-v1

Tanggal: 2026-09-04. **REPORT ONLY — usulan untuk review, belum implementasi.**
Worker HEAD saat preflight: `303f0e6`. Integrasi tipe root `f0f2af1` dan delivery
HTTP `b06935f` mengikuti handoff koordinator; view root juga tercatat pada commit
`b06935f`. Tidak reset, merge atau menyalin baseline backend ke worker.

## Sumber dan batas aktual

Dibaca read-only dari `D:/LSI/Web/Psikotes`: CLAUDE.md,
tasks/organization-payment/{parallel-work.md,plan.md,todo.md}, ADR-012 termasuk
amendment native logout, app/Http/Controllers/CheckoutSessionController.php,
resources/views/checkout/summary.blade.php, resources/js/types/checkout-summary-v1.ts,
app/Http/Middleware/ProtectCheckoutSessionHttpBoundary.php,
app/Services/Integrations/CheckoutSessionHttpContract.php dan bagian delivery/privacy
tests/Feature/Integrations/CheckoutSummaryHttpTest.php. Skill frontend-ui-engineering
dan API/interface design yang telah dibaca menjadi rujukan; tidak ada UI dibuat.
Status ringkas plan/todo/parallel masih memuat checkpoint lama; handoff terbaru dan
actual source dipakai untuk preflight, tanpa mengubah checklist kanonik.

- `summary()` mengambil pasangan cookie sekali, menolak query, memanggil
  lifecycle `readSummary()` tunggal, lalu menyusun array dan JSON dari hasil yang
  sama. Invalid session membersihkan cookie dan redirect generik. Jangan ganti
  dengan `show()`, hydrate kedua, ID dari browser, atau fetch tambahan.
- View `checkout.summary` sudah HTML `lang=id`, viewport, main/h1, section/dl,
  daftar akses per tes, teks dokumen dan form logout native. Profil/pembayaran/
  consent/start belum mempunyai kontrol mutasi. Belum ada CSS/logo atau JS executable.
- View masih menampilkan enum payer/payment/provenance/access/test secara mentah.
  Amount null sudah `Belum tersedia`; integer diformat `Rp ` + number_format IDR.
  Field profile memakai displayValue atau fallback tanpa input. Ini fondasi no-JS,
  bukan alasan menggantinya dengan DRAFT interaktif.
- JSON inert `script#checkout-summary-v1[type=application/json]` memakai encoding
  JSON_HEX_TAG/AMP/APOS/QUOT/THROW_ON_ERROR di controller. CSRF hanya di meta dan
  hidden logout field; bukan di summary. HTML tetap dikirim untuk Accept JSON.
- Route `/checkout` hanya diregistrasikan tes dengan boundary privat dan throttle;
  bukan route produksi. Logout tetap melalui middleware credential/CSRF existing.
- TS v1 cocok dengan fakta ini: tujuh field, required termasuk locked, provenance
  nominal nullable, akses partial/test list, consent accepted|required dan DASS
  not_applicable. actionAvailable/startAvailable literal false. Tidak ada formKey,
  input/options, callback, ID atau invoice. Tipe DRAFT lama tidak kompatibel dan
  tidak boleh dicast atau diganti diam-diam.

## Usulan satu slice berikut: Blade read-only, maksimal lima file

Pilih perbaikan renderer server existing, **tanpa React mount, adapter network,
parser JSON baru, atau duplikasi renderer no-JS**. JSON inert dipertahankan sebagai
kontrak; tidak dibaca/disalin ke cache browser pada slice ini.

| File yang diusulkan untuk ownership baru | Perubahan terbatas |
| --- | --- |
| resources/views/checkout/summary.blade.php | Label Indonesia, semantik/read-only copy, logo same-origin, link CSS lokal; tetap escaped, satu summary dan form logout existing. |
| public/css/checkout-summary-v1.css (baru) | CSS statis khusus halaman, tanpa import/font/network luar, tanpa PII; gunakan ukuran/warna ONCAM existing, wrapping dan focus-visible. Tidak mengubah app.css, Vite/config atau dependensi. |
| tests/Feature/Integrations/CheckoutSummaryHttpTest.php | Assertion label/nominal/semantik dan aset lokal, mempertahankan exact payload, credential/privacy, error dan no executable script. |
| tests/Frontend/IntegratedCheckout/summary-readonly-browser.mjs (baru) | Helper browser native untuk halaman sintetis dari view aktual, no-JS/keyboard/reflow/CSP; memakai harness P14 milik backend setelah endpoint test-only siap. Tidak membuat route/server baru sendiri. |
| tasks/organization-payment/reports/frontend.md | Bukti, hasil dan keterbatasan slice implementasi kelak. |

Daftar ini **usulan**, belum perluasan ownership implementasi. Logo existing
`public/brand/oncam-logo-full-color.png` dipakai read-only, tidak dibuat ulang.
CSS statis dipilih agar CSP self dan no-JS tidak membutuhkan perubahan build/shared
config. Jika policy aset/review tidak menyetujui lokasi tersebut, berhenti sebelum
implementasi styling; jangan beralih ke inline style/unsafe-inline. Bila harness P14
belum dapat menyajikan view aktual, jangan menyalin HTML fixture dan mengklaim parity;
pisahkan bukti geometri sintetis dari acceptance delivery yang masih terbuka.

## Pemetaan presentasi yang diusulkan

Label adalah tampilan atas state server; tidak menetapkan state baru atau izin.

| Fakta | Label Indonesia |
| --- | --- |
| payer unselected | Pembayar belum dipilih |
| payer self | Bayar sendiri |
| payer organization | Dibayar lembaga; tampilkan organizationName server |
| payment unselected | Pembayar belum dipilih |
| unpaid | Belum dibayar |
| unbilled | Menunggu penagihan oleh lembaga |
| preparing | Pembayaran sedang disiapkan |
| pending | Menunggu pembayaran |
| recovery_required | Status pembayaran perlu diperiksa |
| expired | Pembayaran kedaluwarsa |
| rejected | Pembayaran ditolak |
| paid | Pembayaran lunas |
| free | Gratis — tercatat oleh server |
| packageSource catalog | Informasi paket dari katalog |
| packageSource charge_snapshot | Informasi paket saat biaya ditetapkan |
| amountSource unavailable | Nominal belum tersedia |
| amountSource charge_snapshot | Nominal biaya yang tercatat |
| consultationRequested null / false / true | Belum tersedia / Tidak / Ya |
| access locked / partial / ready | Prasyarat tes belum terpenuhi / Sebagian prasyarat tes belum terpenuhi / Prasyarat tes terpenuhi |
| test locked / ready | Prasyarat belum terpenuhi / Prasyarat terpenuhi |
| testType ist/papi/rmib/kraepelin/dass21 | IST / PAPI / RMIB / Kraepelin / DASS-21 |
| consent accepted | Persetujuan tercatat — versi dari server |
| consent required | Persetujuan untuk dokumen ini belum tercatat |
| DASS not_applicable | Tidak berlaku untuk paket ini |

Pertahankan access.message dan identityMessage server sebagai teks. Bahkan ready
harus menjelaskan mesin sesi/mulai tes belum tersedia; jangan beri badge "Siap mulai".
Urutan/list tes tetap server, jangan menambah tes dari katalog atau mengurut ulang
berdasarkan status. DASS hanya fakta applicability/consent/access, tanpa data klinis.
Required tidak membuktikan bahwa peserta belum pernah menolak; jangan tampilkan
riwayat "ditolak" yang tidak ada. legalReviewPending tetap warning teks, bukan
pengubahan status consent atau syarat tes lain. Teks/version dokumen tidak ditulis ulang.

**Nominal:** gunakan hanya amountIdr server pada render request, bukan harga fixture,
katalog, paket, cabang atau total batch. Pertahankan format existing `Rp 175.000`
untuk contoh sintetis 175000 dan `Rp 0` untuk nol; null tidak menjadi Rp 0/gratis.
Nol unpaid tetap "Belum dibayar"; paid/free dengan akses locked tetap locked.
Tidak menghitung konsultasi, diskon, total, kurs atau invoice. Bila kelak JS diperlukan,
formatter id-ID/IDR harus diuji setara dengan renderer server, bukan sumber harga baru.

**Profil kosong:** tujuh label tetap terlihat sesuai urutan/key dan required server.
Missing wajib: "Belum dilengkapi"; missing email: "Belum tersedia (opsional)".
Jika hanya email kosong, copy "Data wajib sudah lengkap; email belum tersedia";
jika required missing, "Sebagian data wajib belum lengkap; pengisian belum tersedia
di halaman ini". Semua locked: "Data profil sudah tersedia" dan tidak meminta
registrasi ulang. Nilai locked tetap displayValue server, termasuk tanggal string;
tidak parse Date, konversi timezone, enum ulang atau placeholder yang disimpan.
Blank/whitespace locked defensif boleh diberi fallback tampilan "Belum tersedia",
tetapi tidak direklasifikasi missing atau dianggap izin edit. Label cabang/paket atau
test list kosong bukan default cabang/paket; itu pelanggaran kontrak untuk ditangani
boundary server generik, bukan dibuatkan data oleh UI. Jangan memuat ulang data lama
ketika request baru invalid/expired/error. View server tidak memerlukan spinner
atau state loading sintetis yang menampilkan PII request sebelumnya.

## Acceptance dan tes konkret sebelum diterima

1. **Semantik/no-JS:** HTML dari view actual tetap memuat profil, nominal, status,
   daftar tes dan dokumen ketika JavaScript dinonaktifkan. Satu main/h1, heading
   berurutan, dl berpasangan, teks status bukan warna saja, logo alt bermakna.
   Tidak ada input profile, radio/checkbox consent, tombol bayar/start, link invoice,
   callback atau form selain logout existing. Disabled CTA tidak diperlukan.
2. **Matrix data:** 10 state payment; tiga payer; catalog/null versus snapshot;
   null, 0 unpaid, 0 free locked, nonzero paid locked/partial/ready, batas integer
   safe, consultation null/false/true. Tujuh missing, mixed, lengkap, email-only
   missing, teks panjang/Unicode/whitespace. Tiga access states dan semua test types;
   accepted|required/not_applicable dan warning legal true/false. Assert copy,
   nilai lengkap, tidak ada nominal/field yang disimpulkan dari label/state lain.
3. **Keamanan rendering:** ulangi exact recursive JSON keys dan equality payload
   sebelum/sesudah styling; satu inert script, nol script src/event handler,
   hostile `</script>`/HTML di profil/cabang/dokumen tetap teks. Tes existing yang
   sekarang menghitung nol img perlu membedakan satu logo lokal yang diizinkan
   dari img hostile; jangan menghapus assertion XSS atau melonggarkan menjadi
   "ada gambar boleh". CSS/logo tidak mengandung request participant identifier.
4. **Keyboard:** native Tab/Shift+Tab hanya menuju kontrol/tautan sah; fokus visible,
   tidak trapped atau tertutup; tidak memberi tabindex pada fakta pasif. Space/Enter
   pada logout diuji hanya dengan credential sintetis di harness P14 yang disetujui.
   Pertahankan exact action/method/hidden field, no-JS native POST dan responsnya.
   Screenshot/SSR tidak membuktikan keyboard/logout berhasil.
5. **Mobile:** screenshot dan geometri styled 320/390/1280, plus zoom 200% dan teks
   panjang. Tidak overflow horizontal, label/nominal/dokumen terpotong atau kontrol
   logout tak terjangkau; stacking satu kolom mobile, line wrapping dan target
   sentuh minimum 44px. Warna/spacing/logo ONCAM existing, kontras normal 4.5:1.
6. **Browser privacy:** actual CSP tetap `default-src 'self'; base-uri 'none';
   frame-ancestors 'none'`, tanpa unsafe-inline/eval/CDN/font luar. CSS/logo lokal
   harus termuat; console/CSP/network diperiksa, kegagalan dihitung. Pertahankan
   no-store/private/no-referrer/nosniff/frame deny. Tidak ada fetch/poll, storage,
   service worker, telemetry, URL/query/hash/history.state/Inertia cache berisi
   summary atau credential. JSON tidak berisi credential; CSRF tetap hanya pada
   meta dan hidden field existing, bukan disalin ke props/log/screenshot bukti.
7. **Lifecycle browser P14:** back/refresh/logout/expiry/recovery/multi-tab dan login
   existing harus diperiksa oleh harness delivery dengan server revalidation; jangan
   mengklaim no-store otomatis menjamin bfcache/history aman. Bila PII lama terlihat
   sesudah invalidation, laporkan reproducer untuk review backend/privacy sebelum
   menambah JS lifecycle. Amendment Origin:null hanya berlaku pada native logout
   dengan seluruh proof server, bukan izin melonggarkan Origin untuk P15.

Focused HTTP tests menggunakan phpunit.organization-payment.xml dan harness
disposable tanpa .env aktif; frontend menggunakan helper browser terisolasi.
Koordinator mengatur agar test yang memigrasi tidak pernah menunjuk DB aktif.
Tidak ada penambahan route produksi, login/data nyata atau transaksi pembayaran.
PHP/Blade lint, test HTTP focused, typecheck v1 existing dan browser native dicatat
sesuai file yang benar-benar diubah; build hanya jika pipeline aset berubah, bukan
dijadikan bukti browser. Artifact screenshot/log harus ignored dan disanitasi.

## Dependencies dan serah-terima

P14 internal/HTTP delivery diterima menurut handoff; **browser acceptance P14 masih
terbuka**. Slice presentasi dapat diusulkan tanpa P15, tetapi release/wiring publik
tetap memerlukan review lifecycle/privacy browser P14. **P15 mutasi belum tersedia**:
server harus menetapkan whitelist missing-field, validasi, consent/version/CSRF,
error/replay dan kewenangan payer/payment sebelum UI editable dipertimbangkan.
**Tombol bayar, mulai tes dan konfirmasi consent belum boleh diaktifkan**, termasuk
ketika nominal nol atau status paid/ready. Tidak menambah pemilihan payer otomatis.
P16 tetap belum selesai end-to-end; setelah laporan ini worker menunggu review.

Validasi increment ini hanya pembacaan source dan `git diff --check` laporan.
Tidak ada implementasi, typecheck/build/SSR/browser/HTTP/PG yang dijalankan atau
diklaim lulus pada turn ini. Tidak mengubah reports/frontend.md maupun dokumen
kanonik. Commit hanya laporan ini, lalu kirim hasil ke koordinator.
