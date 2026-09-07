# P17c — audit readiness browser dan gap acceptance

Tanggal audit: 2026-09-07. Status: **read-only/static audit; P17c BLOCKED**.

Audit ini tidak menjalankan browser, server, database, migrasi, provider,
notifier, atau command acceptance. Tidak ada `.env`, secret, token, atau data
nyata yang dibaca. Satu-satunya perubahan adalah report ini; checkbox dan gate
tetap tidak berubah/default OFF.

## Dasar penilaian

Acceptance kanonik di `tasks/organization-payment/todo.md` meminta satu alur
desktop/mobile/keyboard: multi-select 10 → satu invoice fake → semua item paid,
serta disabled reason, stale preview, reload, expired, consent tertunda, IDOR,
origin test-only, data sintetis, dan bukti tanpa token. P17b sudah menjadi
dependency backend, tetapi dokumen kanonik eksplisit menyatakan bukti backend
tidak menggantikan browser P17c.

Status dalam report ini berarti:

- **PASS**: runner, fixture, dan assertion statis untuk skenario itu sudah
  lengkap dan berada dalam satu boundary browser yang ada. Ini bukan klaim
  runtime lulus.
- **PARTIAL**: bagian skenario ada, tetapi tersebar pada boundary berbeda atau
  belum membuktikan rantai acceptance yang sama.
- **BLOCKED**: tidak ada runner browser existing yang dapat membuktikan skenario
  end-to-end sesuai acceptance, atau runtime authority-nya belum tersedia.

ADR-021 dan ADR-022 berstatus Accepted-for-contract, bukan implementation atau
runtime authority. Keduanya masih menahan candidate/browser launch: preparation
ACL, handle-relative lease, TLS material capability/consumer, exact acquisition,
native Windows evidence, final composition admission, dan final ADR-017 belum
diterima sebagai jalur runtime. `checkout-supervisor.py` juga sengaja inert bila
dijalankan langsung; bahkan hasil full yang valid tetap `accepted=false` sampai
review root. Karena itu tidak ada command full-browser yang aman/berwenang untuk
dijalankan pada increment ini.

## Matriks readiness

| Skenario acceptance | Status | Runner/fixture/test existing | Bukti statis dan gap |
| --- | --- | --- | --- |
| Desktop/mobile/keyboard | **PARTIAL** | `tools/testing/verify-collective-bill-page-browser.mjs` + `tools/testing/serve-collective-bill-page.php`; `tools/testing/verify-organization-bill-proof-browser.mjs`; `tools/testing/tests/Browser/checkout-session.browser.mjs` + `serve-checkout-session.php`; `tests/Frontend/IntegratedCheckout/keyboard-reflow.mjs` | Runner cabang mengukur 320/390/1280, memakai Tab/Space/Enter/ArrowDown dan memeriksa event trusted; runner proof mengukur reflow serta keyboard; driver checkout melaporkan desktop 1280/mobile 390/320/keyboard. Namun ketiganya alur terpisah, bukti historis bukan run P17c terpadu, hanya Chromium/Chrome headless, dan candidate authority saat ini masih blocked. |
| Multi-select 10 → satu invoice fake → semua paid | **BLOCKED** | Seleksi/reservasi browser: `verify-collective-bill-page-browser.mjs` + fixture collective page. Komposisi backend: `tests/Feature/Payments/CollectiveBillLifecycleCompositionTest.php::test_ten_attempts_share_one_invoice_and_exact_settlement_without_bypassing_consent`. | Browser memilih 10 dan hanya mengonfirmasi satu **bill/reservasi**, bukan menerbitkan invoice atau settlement. Test backend membuktikan satu fake `createInvoice`, satu lookup, 10 item settled dan 10 attempt ready pada consent-complete; tetapi bukan browser. Driver checkout terpadu mengharuskan `paymentPosts === 0`. Tidak ada runner existing yang menghubungkan ketiganya pada satu fixture/state. |
| Alasan disabled | **PASS** | `verify-collective-bill-page-browser.mjs` phase `selection-preview`; `serve-collective-bill-page.php`; `tests/Feature/Admin/CollectiveBillPreviewTest.php`; `tests/Feature/Admin/CollectiveBillSelectionTest.php`. | Runner menuntut tepat empat row disabled (legacy/free/self/claimed) dan empat alasan generik `Tidak tersedia untuk tagihan kolektif.`; test feature mencakup reason canonical dan boundary role/tenant/status. Runtime P17c tetap belum dijalankan. |
| Stale preview | **PASS** | `verify-collective-bill-page-browser.mjs` phase `confirm`; control `price-up`/`price-restore` pada `serve-collective-bill-page.php`; `CollectiveBillSelectionTest::test_price_or_status_change_after_preview_fails_closed_without_partial_bill` dan `test_livewire_selection_change_clears_preview_and_blocks_confirmation`. | Perubahan consultation menghapus preview; perubahan harga setelah review menghasilkan pesan generik; server me-review ulang sebelum confirm. Assertion berada pada satu boundary browser cabang existing. |
| Reload | **PASS** | `verify-collective-bill-page-browser.mjs`; `verify-collective-preview-browser.mjs`; `verify-organization-bill-proof-browser.mjs`; `checkout-session.browser.mjs`. | Runner cabang memastikan detail canonical tetap sama setelah reload; preview lama/selection tidak dipulihkan; proof action tidak terduplikasi; checkout menguji persistensi confirmation serta fencing stale session. Belum ada reload sesudah settlement 10-item karena rantai itu belum ada. |
| Expired | **PARTIAL** | `verify-organization-bill-proof-browser.mjs` memakai control `bill-expired`; `checkout-session.browser.mjs` mencakup state/session expired; `tests/Feature/Admin/OrganizationBillAccessTest.php`; `tests/Feature/Payments/AssessmentBillStatusReconciliationTest.php`. | Browser proof memastikan upload hilang pada bill expired dan checkout menangani expiry privat. Status bill pada fixture diubah langsung oleh control; tidak ada alur browser satu invoice fake yang benar-benar expire/reconcile lalu reload. Tidak boleh dianggap auto-release/reinvoice. |
| Consent tertunda setelah paid | **PARTIAL** | `CollectiveBillLifecycleCompositionTest` (data provider consent pending); `checkout-session.browser.mjs` summary own allocation paid/locked dengan DASS required; `tests/Frontend/IntegratedCheckout/fixtures.ts` fixture `Lembaga · lunas, consent belum`. | Backend membuktikan 10 allocation settled tetapi hanya 9 attempt aktif saat consent terakhir pending; checkout driver membuktikan proyeksi milik sendiri paid namun locked/partial dan consent DASS required. Kedua bukti memakai fixture berbeda; runner cabang tidak membawa hasil bill 10-item ke session peserta yang sama. |
| IDOR role/tenant/direct URL | **PASS** | `verify-collective-bill-page-browser.mjs` phase `authorization`; `verify-organization-bill-proof-browser.mjs`; `tests/Feature/Admin/OrganizationBillAccessTest.php`; `tests/Feature/Admin/OrganizationBillProofTest.php`; `tests/Postgres/OrganizationBillPortalTest.php`. | Guest, wrong role, membership/tenant stale, foreign bill, missing bill, dan direct detail ditolak 302/403/404 tanpa reference/private marker. PG/feature menambah RLS dan forged-record coverage. Ini readiness statis; runtime P17c belum diulang. |
| Origin test-only dan outbound tertutup | **PARTIAL** | Legacy cabang: harness `serve-collective-bill-page.php` pada literal `127.0.0.1:8012` dengan `APP_ENV=testing`, SQLite temp, `Http::preventStrayRequests`, fake provider/notifier. Checkout candidate: `serve-checkout-session.php` + `checkout-session.browser.mjs`, canonical HTTPS origins diproksi ke loopback dan network allowlist. | Isolasi fixture legacy cukup untuk P12 evidence, tetapi current secure candidate memerlukan ADR-021/022/native authority yang belum tersedia. Tidak boleh jatuh kembali ke HTTP legacy atau broad TLS bypass untuk menutup P17c. |
| Data sintetis | **PASS** | `serve-collective-bill-page.php`, `serve-checkout-session.php`, `tests/Support/AssessmentPreviewFixture.php`, `tests/Support/AssessmentAccessFixture.php`, dan `CollectiveBillLifecycleCompositionTest`. | Fixture memakai akun/nama/reference/password uji, temp directory, SQLite disposable, fake provider/notifier, mail/bus/queue fake/deny, dan tanpa workspace `.env`. Nilai private sentinel hanya negative-leak marker. |
| Bukti tanpa token/credential | **PARTIAL** | `checkout-session.browser.mjs`; `verify-collective-bill-page-browser.mjs`; `verify-organization-bill-proof-browser.mjs`; postcondition pada kedua PHP harness. | Driver checkout menyimpan bearer hanya in-memory, menolak credential pada URL/Referer, dan mengembalikan `screenshotsContainingCredentials: 0`; runner cabang menolak control/private marker di DOM dan hanya mengembalikan detail URL; runner proof mengembalikan jumlah proof URL, bukan nilainya. Namun belum ada satu evidence envelope P17c terpadu yang mengikat screenshot/check/result tanpa token, dan candidate output authority masih blocked. |

## Command aman untuk audit statis

Command berikut read-only atau syntax-only; semuanya boleh dijalankan tanpa
browser/server/database dan tidak membuktikan acceptance runtime:

```powershell
node --check tools/testing/verify-collective-bill-page-browser.mjs
node --check tools/testing/verify-organization-bill-proof-browser.mjs
node --check tools/testing/tests/Browser/checkout-session.browser.mjs
php -l tools/testing/serve-collective-bill-page.php
php -l tools/testing/tests/Browser/serve-checkout-session.php
rg -n "test_ten_attempts_share_one_invoice_and_exact_settlement_without_bypassing_consent|paymentPosts === 0|Expected legacy/free/self/claimed disabled rows|bill-expired|Foreign bill detail leaked|screenshotsContainingCredentials" tests tools/testing
git diff --check -- tasks/organization-payment/reports/browser-p17c-readiness-gap.md
```

Command historis `verify-collective-*`, `verify-organization-bill-proof-browser`,
PHPUnit composition, PostgreSQL runner, server loopback, dan supervisor full
**tidak aman untuk increment ini** karena masing-masing memulai browser, server,
atau database. Jangan menjalankannya dari report ini. Tidak ada direct invocation
baru yang boleh dibuat untuk melewati supervisor/candidate gate.

## Gap minimum sebelum P17c dapat dijalankan

1. Sediakan satu fixture/runner P17c yang memakai graph yang sama dari seleksi 10
   berbiaya, reservasi, fake issuance, fake paid event/finalizer, sampai proyeksi
   cabang dan peserta; jangan menggabungkan hasil runner terpisah sebagai E2E.
2. Pertahankan dua varian consent: 10/10 ready dan 9/10 ready + satu paid/locked;
   assert seluruh 10 allocation settled tepat sekali pada keduanya.
3. Dalam runner yang sama, jalankan desktop/mobile/keyboard, disabled reason,
   stale preview, reload, expired terminal, dan IDOR; bukti harus memuat fixed
   status/counter/check names saja, tanpa bearer, CSRF, cookie, control secret,
   path host, invoice URL, gateway reference, atau data anggota lain.
4. Gunakan hanya candidate test-only yang lolos seluruh authority ADR-021/022,
   native preparation/TLS/ACL/lifecycle gates, dan supervisor full. HTTP legacy,
   `ignoreHTTPSErrors=true`, global certificate bypass, ambient package/browser,
   atau activation payment bukan fallback.
5. Setelah run fresh yang berwenang selesai dan cleanup/census/postcondition
   lulus, review root tetap diperlukan sebelum checkbox P17c/P15/P16 diubah.

Verdict akhir: **BLOCKED untuk acceptance runtime**. Static readiness cukup kuat
untuk beberapa sub-skenario, tetapi jalur 10 → satu fake invoice → semua settled
dan evidence envelope terpadu belum ada, sementara candidate runtime authority
masih fail-closed sesuai ADR-021/022.
