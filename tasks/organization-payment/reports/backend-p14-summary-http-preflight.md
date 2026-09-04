# P14 — preflight integrasi HTTP summary read-only

Status: **proposal untuk review, report-only**, 2026-09-04. Tidak ada renderer,
controller, middleware, route, frontend atau konfigurasi yang diubah oleh laporan
ini. Internal summary telah diterima root sebagai c03f2aa/3032b1e/d849b18;
acceptance itu belum mencakup public P14 atau browser halaman summary.

## Keputusan minimum yang direkomendasikan

Tambahkan method khusus `CheckoutSessionController::summary` dan view baru
`checkout.summary`. Tes baru mendaftarkan **GET /checkout** ke method itu dengan
boundary privacy/flag dan named throttle existing, **tanpa**
`AuthenticateCheckoutSession`. Method baru mengambil pasangan cookie, memanggil
`CheckoutSessionLifecycle::readSummary` tepat sekali, dan merender hasil immutable.
Method ini adalah adapter credential ke lifecycle, bukan bypass authentication.

Jangan menambah `/checkout/summary`, endpoint JSON, parameter mode, content
negotiation summary, atau operasi hydrate baru. Path GET /checkout sudah dikenali
oleh limiter dan destination guard. Tidak diperlukan perubahan shared HTTP
contract, flag, route produksi atau global middleware. Route sintetis existing
di suite lama tetap menguji method `show` beserta perilakunya; suite baru menguji
calon GET summary secara terpisah. Tidak mendaftarkan keduanya sekaligus pada
router yang sama.

Jalur POST /checkout/logout tetap memakai AuthenticateCheckoutSession ->
VerifyCheckoutSessionMutation -> controller logout existing. Method show/private
view lama juga tidak dihapus dalam increment minimum. Optimasi double lifecycle
call logout, migrasi seluruh caller, dan penghapusan adapter lama di luar scope.

## Audit sumber dan gap konkret

Dibaca read-only dari root: plan/todo/parallel-work organization-payment,
ADR-012 termasuk amendment native logout, proposal composition, dan DRAFT
`resources/js/types/integrated-checkout.ts`. Audit juga membaca controller, auth,
mutation middleware, HttpContract, privacy boundary, private view, serta tes HTTP
existing. Lima file PHP adapter tersebut byte-identik root/worker. Private view
memiliki hash byte berbeda, tetapi `git diff --no-index` tidak menunjukkan delta
isi setelah normalisasi Git; tidak ada overwrite/copy baseline yang dilakukan.

Alur GET existing:

1. ProtectCheckoutSessionHttpBoundary memeriksa strict flag/config, exact
   destination dan path/method yang dikenali, lalu memasang privacy headers.
2. Throttle memakai key hydrate + IP existing, tanpa credential atau scope ID.
3. AuthenticateCheckoutSession menolak query, membaca dua cookie, lalu menjalankan
   hydrateWithCsrfDelivery dalam transaksi lifecycle; idle touch sudah commit.
4. Principal ditempel pada request attribute. Controller show membacanya dan
   merender private view dengan CSRF cookie yang dibaca kembali.

Menambah readSummary pada show tanpa mengubah stack GET akan menghasilkan dua
transaksi dan dua idle touch. Menyusun summary dari principal attribute akan
menggunakan graph yang dapat stale setelah commit pertama. Keduanya ditolak.

Alternatif mengubah AuthenticateCheckoutSession untuk memilih hydrate/summary
berdasarkan request juga ditolak untuk increment minimum: ia memperluas shared
auth dan dapat merusak principal yang diperlukan pengecualian native logout.
Alternatif JSON fetch setelah hydrate menambah read kedua, endpoint/limiter baru,
dan persoalan CSRF-delivery yang tidak diperlukan sekarang.

## Alur GET yang diusulkan

Urutan route test-only:

```text
ProtectCheckoutSessionHttpBoundary
  -> throttle:checkout-session-http
    -> CheckoutSessionController::summary
      -> CheckoutSessionLifecycle::readSummary(credentials) tepat sekali
        -> canonical transaction / locked graph / one DB instant / summary / idle touch
      -> render HTML response dari immutable summary, sesudah commit
```

Tidak memakai grup web, StartSession, EncryptCookies, AddQueuedCookiesToResponse,
global CSRF/auth/RLS-from-login, AuthenticateCheckoutSession atau mutation middleware
pada GET summary. Tidak menambah public arbitrary callback atau principal parameter
pada lifecycle/composer. Stack logout dan exchange tidak berubah.

Controller menangkap nilai selector dan CSRF **sekali sebelum** lifecycle call:

- query harus kosong sebagaimana auth GET existing;
- hanya dua nama cookie exact HttpContract; nilai harus string;
- bentuk/purpose/digest/expiry/generation/scope tetap divalidasi lifecycle, bukan
  regex atau query database duplikat pada controller;
- header, body, query atau attribute principal tidak pernah menjadi credential
  pengganti atau selector tenant/attempt;
- request attribute principal yang dipasang caller lain diabaikan;
- sesudah readSummary berhasil, gunakan **CSRF lokal yang sama** dengan input yang
  tadi divalidasi, bukan membaca ulang cookie atau mengambilnya dari DTO;
- jangan memanggil hydrate/readProfile/readPayment/mapper/gate secara terpisah.

readSummary tetap menolak ambient RLS context/outer transaction. Controller tidak
melakukan elevation, pembukaan transaksi, idle update atau session query sendiri.
GET ini read-only untuk bisnis; satu idle refresh lifecycle existing tetap terjadi,
bukan klaim bahwa database sama sekali tidak ditulis oleh GET.

## Respons konkret dan kompatibilitas frontend

**200 text/html; charset=UTF-8**, server-rendered read-only view baru. View memuat:

- own summary dengan label/provenance/state dari CheckoutSummary::toArray, tanpa
  model/principal, descriptor session atau identity verification detail;
- satu blok inert `<script type="application/json" id="checkout-summary-v1">`
  berisi **persis object checkout-summary-v1** (tanpa envelope tambahan), untuk
  adapter frontend additive yang direview kemudian;
- meta `checkout-csrf-token` dan hidden `_checkout_csrf` hanya untuk form logout
  existing, memakai nilai lokal yang sudah diverifikasi;
- form POST fixed /checkout/logout dan tombol Keluar existing sebagai satu-satunya
  mutasi sesi yang tersedia. Tidak ada form profil/consent/payer/payment/start.

Payload JSON tidak berisi CSRF, selector, formKey, callback, input/options mutation
descriptor, internal/public scope IDs atau principal. Tidak ada JSON summary yang
dipilih dengan Accept header, URL query atau flag browser. DRAFT frontend lama tidak
di-cast menjadi v1 dan tidak langsung dipakai sebagai props komponen lama. Frontend
dapat menambah tipe/fixture v1 secara independen; mounting, state management dan
interaksi P15/P16 tetap checkpoint lain. startAvailable/actionAvailable tetap false;
logout tidak mengubah arti flag tindakan assessment/payment tersebut.

Renderer hanya menampilkan data yang diberikan, tidak menurunkan ready dari paid,
menebak unpaid dari unknown, menambah alasan klinis/identity, menyamakan required
consent dengan declined, atau menyediakan checkbox persetujuan pura-pura. Legal
text ditampilkan sebagai text escaped, tanpa perubahan isi atau raw HTML.

Encoding inert JSON memakai primitive PHP `json_encode` dengan
JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR.
Raw Blade output hanya untuk hasil encoder itu, bukan string/model/doc arbitrer.
Jangan pakai encoder custom, `eval`, innerHTML, atau interpolasi JSON manual.
Installed Illuminate\Support\Js::from menghasilkan ekspresi JavaScript, bukan
JSON inert; Js::encode menambahkan JSON_INVALID_UTF8_SUBSTITUTE. Untuk menjaga
teks dokumen tanpa substitusi diam-diam, usulan ini memilih native strict encoder
dan generic failure pada UTF-8 invalid. Encoding bukan sanitasi data bisnis.

Template HTML memakai escaping Blade biasa untuk label/profil/dokumen/attribute
CSRF. Jangan memuat data-checkout-session atau assessment ID seperti view descriptor
lama. CSP existing tetap, tanpa unsafe-inline workaround. View minimum tidak
memerlukan Vite/React/inertia assets atau asset manifest palsu; frontend mounting
dan browser/CSP acceptance diuji terpisah, tidak diklaim dari HTML/JSON unit tests.

## Cookie, CSRF, error dan batas atomik

Exchange masih menjadi satu-satunya pembuat dua cookie khusus: selector dan CSRF
delivery, Secure/HttpOnly/SameSite=Lax/host-only/Path=/checkout, absolute expiry
existing. Summary sukses tidak merotasi atau Set-Cookie ulang, tidak memperpanjang
absolute cookie expiry, dan tidak menyentuh cookie/session login Laravel.

Invalid pair, missing/non-string cookie, query, stale/recovery/expired/revoked/scope
invalid: fixed **303 /checkout/unavailable**, clear hanya kedua cookie dengan scope
exact yang sama. Tidak mengirim profile, CSRF meta/hidden, summary parsial atau
credential di Location. Expiry/revoke lifecycle tetap boleh commit terminal state
sebelum InvalidCheckoutSession keluar.

Flag/config/destination/path tidak tersedia tetap private 404; throttle tetap 429.
Controller hanya menangkap InvalidCheckoutSession untuk clear/redirect. Invalid
component/config summary dan unexpected programming/DB/render failure tetap masuk
report/render exception framework sebagai **generic 500 dengan APP_DEBUG=false**;
tidak menambah status 503, custom logging atau handler global. Jangan menangkap
semua Throwable lalu berpura-pura credential invalid. Cookie valid tidak dibersihkan
karena component failure semata; response error tidak memiliki summary atau CSRF.

Installed Illuminate\Routing\Pipeline::handleException melaporkan dan merender
exception pada pipeline slice, sehingga boundary terluar dapat memasang headers
pada response error downstream. Tes tetap wajib membuktikan error controller dan
view, bukan hanya menganggap seluruh kegagalan otomatis terbungkus. Jangan memakai
streaming/chunked summary atau shared view composer yang mengeluarkan data sebelum
response berhasil dibentuk.

Semua outcome memakai Cache-Control no-store, private; Pragma no-cache;
Referrer-Policy no-referrer; X-Frame-Options DENY; nosniff; CSP existing. Success
memuat own authorized profile sesuai v1; larangan PII mencakup denial/error dan data
peserta lain, bukan menghilangkan own profile yang memang tujuan summary. Raw CSRF
hanya pengecualian terbatas pada meta/hidden HTML setelah digest check, bukan data
JSON, header, log, URL atau document title.

Kegagalan komponen di dalam readSummary membatalkan idle touch. Kegagalan rendering
atau encoding **sesudah lifecycle commit** tidak dapat membatalkan idle touch itu;
response tetap generic tanpa partial body. Jangan menambahkan transaksi kedua,
compensating idle update atau callback renderer di dalam lifecycle untuk menutupi
batas ini. Pengujian harus membedakan kedua failure window tersebut. Revocation
sesudah commit pembacaan juga tidak dapat menarik response yang sedang dikirim;
summary adalah snapshot pada operasi authorized yang selesai, bukan authority bagi
mutasi berikutnya. Tidak ada ready token/start URL yang dapat dipakai ulang.

## Logout native dan auth cookie isolation tetap

Jangan menghapus AuthenticateCheckoutSession dari POST /checkout/logout: principal
verified pada request masih menjadi prasyarat exception literal Origin:null.
Exception tetap hanya exact HTTPS destination + exact logout POST + canonical
form-only explicit CSRF yang cocok dengan delivery cookie dan persisted digest,
dengan Fetch Metadata valid bila ada. Null origin bukan trusted origin untuk
exchange, GET summary atau mutasi lain; tidak ada generalisasi dari laporan ini.
Header-only/dual-channel/null-origin form yang salah tetap 419/denial existing.

Form baru memakai action fixed /checkout/logout, nama field, raw format dan method
yang sama. No-referrer tetap aktif. Logout controller tetap melakukan revalidation
credential sendiri; dua lifecycle operasi pada pipeline logout existing tidak
diklaim hilang oleh optimasi khusus GET ini. Login guard/global auth cookie tetap
berfungsi sebelum dan sesudah exchange, GET summary dan logout checkout.

## Ownership usulan implementasi setelah review

| File | Delta yang diminta, belum dilakukan |
| --- | --- |
| app/Http/Controllers/CheckoutSessionController.php | Method summary baru, capture cookie pair, satu readSummary, invalid clear/redirect, view-only DTO + separate CSRF; existing methods tetap |
| resources/views/checkout/summary.blade.php | View read-only baru, escaped semantic HTML/inert v1 JSON, meta/hidden logout saja; view private lama tidak ditimpa |
| tests/Feature/Integrations/CheckoutSummaryHttpTest.php | Route GET sintetis alternatif + exchange/logout stack nyata, authority/privacy/transaction/cookie/render tests |
| tasks/organization-payment/reports/backend.md | Bukti implementasi/tes dan batas setelah increment diizinkan |

Target empat file untuk satu increment atomic. Tidak ada auth/mutation/privacy
middleware atau HttpContract/config change, route/bootstrap/scheduler, schema,
composer/lifecycle atau frontend ownership. Jika implementasi membutuhkan shared
change di luar tabel ini, laporkan gap sebelum memperluasnya. Penambahan TS contract
dan fixtures dilakukan lane frontend; kontrak v1 disinkronkan melalui coordinator.

## Matriks TDD sebelum kandidat HTTP boleh direview

| Area | Bukti wajib pada route-only tests |
| --- | --- |
| Route/stack | Route tidak ada sebelum tes; GET calon memakai boundary+throttle+summary tanpa hydration middleware/global web/auth; logout stack tidak berubah |
| Single call | Cookie exchange asli -> GET; tepat satu canonical DB clock/readSummary/idle update, bukan readProfile/readPayment/hydrate; context/transaction pulih |
| Authority | Missing/invalid pair, stale/recovery/logout/expiry, foreign pair, scope/deleted participant, ambient roles; forged principal attribute/login cookie/header tidak mengotorisasi atau memilih attempt |
| CSRF delivery | Hanya nilai cookie pair yang tervalidasi muncul pada meta+hidden; tiada raw/digest credential di JSON/URL/header/error; mismatch tidak menghasilkan HTML summary |
| Immutable shape | Parse inert JSON dan bandingkan dengan exact v1 own facts; tiada formKey/IDs/invoice/batch/clinical/callback; locked/partial/ready dan paid/free/unknown tetap benar, flags false |
| Catalog/own scope | No charge null amount vs frozen snapshot ketika catalog berubah; own collective amount; foreign participant marker tidak muncul |
| Escaping | Synthetic </script>, quotes, ampersand, Unicode dan markup pada label/profile/legal text tidak membentuk tag/attribute executable; JSON roundtrip persis; invalid UTF-8 -> generic error tanpa partial payload |
| Failure windows | Component exception membatalkan idle touch; postcommit renderer/encoder exception generic 500 tanpa partial body, dengan idle touch yang sudah commit dicatat; unexpected exception tetap dilaporkan framework |
| Privacy | Success, clear303, 404, throttle429, component/view500 dan negotiated framework error seluruhnya private/no-referrer/CSP; APP_DEBUG=false, tidak ada SQL/PII/token pada error |
| Cookies/auth | Exact two-cookie attrs/clear; success tanpa Set-Cookie; cookie login Laravel terenkripsi nyata tetap mengotorisasi probe web+auth sebelum/sesudah GET/logout; tiada global cookie rewrite |
| Logout regression | Native null-Origin form dengan raw CSRF nyata sukses; header-only, dual-channel, contradictory Fetch Metadata dan unauthenticated/null cases tetap gagal; exception tidak meluas |
| Frontend boundary | No summary JSON negotiation/new API or DRAFT cast; new read-only payload matches separately reviewed additive v1 fixtures; CSRF tidak menjadi field frontend summary |

Jalankan focused CheckoutSummaryHttpTest, existing CheckoutSessionHttpTest,
summary composer/lifecycle serta regresi credential terkait dengan konfigurasi
testing SQLite memory dan fake services. Pint/PHPStan/diff-check setelah code.
PG internal composition 368/3109 adalah bukti terdahulu yang sudah direview, bukan
run HTTP baru atau browser proof. Jika adapter mengubah lifecycle/lock contract,
scope harus ditinjau ulang dan tidak cukup dengan bukti lama. Browser nyata perlu
menguji escaping/CSP, cookie auth preservation, CSRF native logout, refresh/back/
multi-tab dan history/no-referrer sebelum public acceptance. Tidak ada klaim browser
atau manifest/build baru pada preflight ini.

## Hasil increment ini

Report-only. Audit kode installed dan perbandingan root/worker dilakukan; tidak
menjalankan PHPUnit, PostgreSQL, browser atau provider untuk laporan ini. Verifikasi
hanya git diff --check dan staged-path review. Tidak mengubah legal text, canonical
docs, baseline overlays atau ignored build copy. Tidak ada P15, gate/source ON,
.env/active DB, real outbound, new task/agent, deploy atau push.
STOP untuk review strategi response dan ownership sebelum implementasi HTTP.
