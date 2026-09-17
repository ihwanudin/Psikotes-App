# P16 server payment-action boundary preflight

Date: 2026-09-05

Status: **proposal-only, default OFF, belum implementasi**. Audit membaca root
`59edf658db91b898e207470a7a4d281799a1ff8d`, termasuk lifecycle checkout,
projection pembayaran, policy payer, reservation, invoice claim/issuance,
settlement/activation, route canonical, serta laporan frontend DRAFT. Laporan
frontend hanya menjadi masukan presentasi; ia bukan authority HTTP, payer, harga,
invoice, atau settlement.

## Keputusan kontrak minimum

Tambahkan, setelah review, satu command peserta sendiri:

```http
POST /checkout/payment
Content-Type: application/json
Origin: https://psikotes.oncam.id
X-Checkout-CSRF: ocsrf1_<64 hex>

{"consultationRequested":false}
```

Body harus JSON object exact dengan satu boolean `consultationRequested`; query,
form, duplicate JSON key, tipe lain, dan key tambahan ditolak. Boolean ini adalah
pilihan layanan peserta, bukan harga. Organization, participant, package, attempt,
payer, currency, amount, payment method, bill, idempotency key, provider, dan URL
tidak pernah diterima dari browser. Bila package tidak menawarkan konsultasi,
nilai `true` ditolak dan `false` tetap divalidasi terhadap katalog server.

Input internal action adalah credential selector+CSRF existing dan boolean tadi,
bukan `CheckoutSessionPrincipal`, ID, Eloquent model, atau summary DTO dari caller.
Principal yang ditempel `AuthenticateCheckoutSession` hanya snapshot untuk request;
mutation wajib memuat ulang credential, scope, policy, dan state authoritative.

Respons sukses minimum:

```json
{"data":{"paymentState":"pending","paymentUrl":"https://provider.example/..."}}
```

- HTTP 200 dipakai untuk create maupun replay agar retry tidak perlu menebak apakah
  side effect baru dibuat. `paymentUrl` hanya hadir untuk invoice self yang sudah
  persisted, strict HTTPS, exact own bill, dan status `pending`.
- Paid exact replay mengembalikan
  `{"data":{"paymentState":"paid","paymentUrl":null}}`. Free diselesaikan
  oleh confirmation flow setelah consent, bukan route payment; client kemudian
  GET `/checkout` untuk summary canonical.
- Organization payer, ambiguous/unselected payer, preparing, recovery-required,
  expired, rejected, corrupt, revoked, atau forbidden scope mengembalikan 409
  generik `CHECKOUT_PAYMENT_UNAVAILABLE`, tanpa detail state atau existence.
- Config/implementation unavailable mengembalikan 503 generik; malformed JSON
  422; CSRF 419; invalid/expired session mengikuti redirect unavailable existing;
  throttle tetap 429. Unexpected exception memakai reporting framework dan generic
  500 saat `APP_DEBUG=false`.

Respons tidak memuat bill/attempt/participant/organization ID, public reference,
gateway reference, amount input echo, policy, item count, batch total, credential,
digest, audit, SQL, atau provider body. URL invoice adalah capability privat untuk
peserta self yang sama: response wajib `no-store, private`, `Pragma: no-cache`,
`Referrer-Policy: no-referrer`, frame deny, nosniff, CSP existing, dan tidak boleh
ditulis ke log/telemetry. Organization payer tetap hanya melihat summary status
menunggu dan `actionAvailable=false`; tidak ada tombol atau URL invoice kolektif.

## Middleware dan route order

Route tetap berada pada group checkout tanpa middleware `web`, dengan urutan:

1. `ProtectCheckoutSessionHttpBoundary` sebagai wrapper paling luar agar success,
   redirect, 419/422/429/409/503/500 semuanya mendapat privacy headers;
2. limiter `checkout-session-http`, memakai bucket mutation dan key IP existing;
3. `AuthenticateCheckoutSession` untuk cookie selector/CSRF delivery dan generic
   clear+redirect bila invalid;
4. boundary JSON mutation same-origin dengan exact `Origin`, `Sec-Fetch-*`, header
   CSRF yang cocok cookie, content type exact, body cap, dan strict JSON object;
5. request allowlist dan controller tipis;
6. action internal yang kembali memverifikasi credential/scope dalam transaksi.

`CheckoutSessionHttpContract::limit()` saat ini tidak mengenali `/checkout/payment`
dan `VerifyCheckoutSessionJsonMutation` terikat pada config/body-limit confirmation.
Jangan mendaftarkan route dengan middleware sekarang lalu berharap kedua komponen
itu menerima path baru. Tambahkan payment-specific config validation dan path
mutation secara eksplisit, atau ekstrak strict JSON mechanics tanpa melemahkan
confirmation. Jangan mengubah middleware global, Laravel CSRF global, CORS, cookie
path, atau route legacy.

Config defense-in-depth yang diusulkan:

```php
'checkout_session.http.payment' => [
    'enabled' => false,
    'writer_enabled' => false,
    'max_body_bytes' => 256,
],
```

`checkout_session.enabled`, `payment.enabled`, dan `payment.writer_enabled` harus
strict boolean `true`; missing, string `"true"`, atau invalid body limit menutup
route/action. Tidak ada env baru pada increment awal. Global session dapat dinyalakan
untuk summary/confirmation tanpa otomatis menyalakan pembayaran. Test memakai
`FakePaymentProvider`; binding/provider/credential produksi tidak diubah.

## Authority dan lock/transaction boundary

`CheckoutSessionLifecycle::hydrateWithCsrfDelivery` berakhir sebelum controller
dipanggil. Memanggil `ReserveAssessmentBill` kemudian dengan participant dari
principal snapshot membuka race revoke/recovery/session replacement di antara dua
transaksi. Payment action tidak boleh menganggap hydration middleware sebagai izin
uang.

Sebelum writer, ekstrak seam internal bertipe yang mengunci dan memvalidasi graph
session dengan urutan lifecycle canonical:

`organization -> client -> source -> package/items -> attempt -> participant ->
handoff history -> checkout sessions`.

Seam hanya boleh dipanggil dalam service transaction milik mutation; ia menerima
credential digest, memastikan latest consumed handoff, active exact session,
database-clock expiry, checkout-v2, tenant/source/package linkage, participant
tidak deleted, attempt tidak revoked/finalized, dan policy/current funding yang
sah. Ia tidak boleh berupa callback generik publik atau mengembalikan authority
yang dapat dipakai setelah commit. Reservation/free preparation harus terjadi
sebelum lock ini dilepas.

`ReserveAssessmentBill` dapat dipanggil nested dalam transaksi service yang sama:
ia mengulang organization mutex dan lock selection canonical, reload policy,
preview, participant owner, method, charge, serta unique idempotency. Provider
tidak boleh dipanggil dalam transaksi atau RLS context. Setelah reservation commit,
claim berjalan dalam service transaction sendiri; `IssueAssessmentBillInvoice`
kemudian berjalan dengan context kosong dan melakukan maksimal satu create lalu
strict exact lookup sesuai kontrak P10.

## Payer, price, method, and idempotency

- Funding lifecycle persisted menentukan cabang: `COMMERCIAL_SELF_PAY` saja boleh
  menuju invoice peserta; `INVOICED_TO_ORGANIZATION` hanya read-only waiting;
  null/illegal tidak di-default ke self. `ResolvePayerPolicy` diulang dengan graph
  kini dan requested payer `self`; policy OFF/locked organization menolak.
- `PreviewAssessmentBill` menerima tepat satu attempt own dan boolean consultation.
  `AssessmentPriceSnapshot` menangkap IDR integer dari package/items server. Browser
  tidak menyuplai atau mengonfirmasi nominal.
- Payment method dipilih server dengan exact unique `code=xendit` dan `is_active`.
  Missing, inactive, duplicate/corrupt, atau manual transfer menolak. Browser tidak
  dapat memilih manual transfer pada boundary ini.
- Idempotency key tidak berasal dari request/header/session generation. Gunakan
  stable server purpose key per attempt, misalnya
  `checkout-self-v1:<assessment_attempt_id>`, dan biarkan `ReserveAssessmentBill`
  mengikatnya ke request hash selection, consultation, hash harga, serta method.
  Session/handoff replacement tetap menunjuk intent yang sama.
- Retry exact mengembalikan bill canonical yang sama. Perubahan consultation,
  catalog snapshot, payer, package, method, atau scope dengan key sama adalah 409;
  jangan membuat key baru atau bill kedua.

Sebelum preview baru, action harus mencari dan memvalidasi exact existing own bill
di bawah organization lock. Ini diperlukan agar pending/paid/unknown/terminal bill
tetap dapat direplay meskipun catalog berubah atau preview kini akan melihat charge
sebagai already billed. Existing bill harus cocok attempt melalui satu item/charge,
payer participant, IDR, immutable request hash, dan stable key; mismatch gagal
tertutup tanpa mencoba reservasi baru.

## State machine

| Authoritative state | Command behavior | Provider behavior |
| --- | --- | --- |
| Self, no charge/bill, positive preview | Reserve exactly one own bill; claim; issue after commit | At most one create, then strict lookup |
| Self, existing `reserved` or `issuing/pending/0` intent | Continue canonical claim/issue | Existing P10 permit decides; never parallel POST |
| Self, bill `pending` with valid own URL | Replay same URL | No create; no status mutation |
| `issuing/processing/1` or `unknown/failed/1` | 409 recovery required | Zero create; reconciliation remains separate |
| `paid` and full settlement exact | 200 paid replay | Zero provider calls; activation may be retried separately |
| `expired` or `rejected` | 409 terminal | No release, new bill, or re-invoice |
| Organization payer/unselected/policy denied | 409 generic; summary only | Zero provider calls |
| Zero-price, consent current | Confirmation invokes explicit free writer, then activation in the same outer transaction | Zero provider calls and no bill |
| Zero-price, consent missing/stale | 409; do not mark free | Zero provider calls |
| Revoked/finalized/corrupt/foreign | 409 generic | Zero provider calls and no mutation |

Invoice `pending` is not settlement. Neither bill creation, URL, claim, nor provider
lookup grants entitlement. Paid/free can remain access-locked when identity or any
other prerequisite is incomplete. Late paid status is handled only by canonical
finalizer/reconciliation, never inferred from the browser command.

## Missing free-settlement primitive

`PreviewAssessmentBill` recognizes amount zero, while `ReserveAssessmentBill`
explicitly skips free items and rejects all-free reservation. No production action
currently creates a canonical zero charge and writes `free_settled_at`; existing
free cases are fixture setup. `AssessmentSettlementReader` is read-only and cannot
fill this gap.

Add a separate internal `SettleZeroPriceCheckout` primitive after review. Within
the same session-authorized service transaction it must reload policy/catalog,
capture or validate one zero-valued charge snapshot, require no bill item, require
current psychotest and applicable DASS consent, set database-clock
`free_settled_at` once, and write one sanitized audit. Exact replay preserves the
marker/audit. Conflicting positive charge, allocation, payer, snapshot, future
marker, or terminal attempt fails without mutation. It then calls
`ActivateSettledAssessment` before the outer transaction commits; missing identity
or other prerequisite leaves the attempt settled but locked without blocking or
creating invoice. A failure during activation/audit/outbox rolls back charge,
marker, audit, entitlement, attempt, and outbox together.

Free settlement should be invoked from the successful confirmation flow after
versioned consent is persisted, not exposed as an organization payment button.
This requires a reviewed seam in `ConfirmIntegratedCheckout`; merely reading the
summary must never write settlement.

## Existing primitives that are reusable

- `CheckoutSessionHttpContract`, `ProtectCheckoutSessionHttpBoundary`,
  `AuthenticateCheckoutSession`, and strict JSON CSRF mechanics: transport and
  privacy, after adding an exact payment path/config contract.
- `ResolvePayerPolicy`: current persisted payer decision, never browser authority.
- `PreviewAssessmentBill`: one-attempt server price/policy preview.
- `ReserveAssessmentBill`: atomic stable-key reservation and charge/item snapshot.
- `ClaimAssessmentBillInvoice` and `IssueAssessmentBillInvoice`: durable intent,
  single permit/create, strict lookup, and recovery-required behavior.
- `AssessmentSettlementReader` and `ActivateSettledAssessment`: exact settlement
  evidence and prerequisite-bound activation.
- `CheckoutPaymentFactsReader`/`CheckoutSummaryComposer`: response/status projection,
  but never writer authority.

Missing adapters are the session-authorized payment transaction seam, typed payment
result/controller/request, server Xendit method selector, and zero-price writer.
Do not copy P10 predicates or add a second invoice implementation.

## RED test matrix required before code

| Group | Required failures and successes |
| --- | --- |
| HTTP boundary | Route/config switches independently OFF; wrong host/origin/fetch headers, query, form, empty/list/scalar/oversize JSON, duplicate/unknown key, nonboolean consultation, missing/mismatch CSRF, stale cookie, 429 and unexpected 500 all preserve private headers and generic bodies. |
| Session/scope | Expired/revoked/recovered session, old handoff generation, forged principal attribute, foreign attempt/participant/organization/client/source/package, deleted participant, v1 marker, revoked/finalized attempt, policy change between auth and writer all fail before mutation/provider. |
| Input authority | Attempt/payer/amount/currency/method/bill/reference/URL/idempotency keys in body are rejected; request amount cannot affect captured IDR snapshot; consultation true rejected when unavailable. |
| Self create/replay | No bill -> one bill/item/charge/intent and one fake create+lookup; concurrent retry -> same bill and at most one create; new session exact retry -> same result; changed consultation/catalog/method/payer -> conflict without duplicate. |
| Existing states | Reserved continues; pending returns exact own validated URL with no POST; processing/unknown, expired/rejected return generic conflict; paid returns paid without POST; malformed URL/linkage/sum/count/snapshot/intent fails closed. |
| Organization | Every organization state has `actionAvailable=false`; direct POST gives generic 409, never exposes collective URL/reference/member/total and never calls provider. |
| Free | Current consent + zero catalog creates/validates one zero charge, marker/audit once, no bill/provider; replay no duplicate; missing/stale/withdrawn consent no marker; identity missing leaves locked; later identity activation does not invoice; rollback at audit/activation/outbox leaves no partial state. |
| Privacy | Recursive response/log sentinel excludes PII other than intentional own browser page, IDs, token/digest, references, collective data, SQL/provider exception; URL only appears in successful own pending result and never error/log. |
| PG concurrency | Disposable non-owner/NOBYPASSRLS: two self commands one bill/create, session revoke vs reserve, policy/method/catalog writer vs reservation, free confirmation replay, invoice finalizer vs pending replay; observed locks and no mixed state/context leak. SQLite is not concurrency evidence. |

All provider tests must bind `FakePaymentProvider` or `Http::fake()` with
`preventStrayRequests`; no test may use a real credential or endpoint.

## Reviewable increments, each at most five files

1. **P16-pay-a session mutation seam:** internal typed locked-scope service,
   lifecycle refactor limited to using it, focused lifecycle/PG tests, and lane
   report (4-5 files). No billing write yet.
2. **P16-pay-b self preparation:** typed result + internal action that selects
   server Xendit method and reuses preview/reserve/claim, plus feature and PG race
   tests (4 files). It returns an issuance message after commit; no HTTP.
3. **P16-pay-c issuance orchestration:** invoke existing `IssueAssessmentBillInvoice`
   outside transaction/context, map pending/recovery/terminal replay, and add fake
   provider tests (action adjustment + tests + report, at most 4 files).
4. **P16-pay-d free settlement:** zero-price action, confirmation integration,
   feature test, PostgreSQL rollback/race test, report (5 files). Stop review before
   any HTTP wiring.
5. **P16-pay-e HTTP contract:** request, controller, payment JSON middleware/contract
   extension, route-only feature test (5 files). Route remains test-only.
6. **P16-pay-f default-off production wiring:** additive config, HTTP contract,
   route, production wiring/privacy test, report (5 files). Both switches remain
   false; browser work remains separate.
7. **P16-pay-g presentation capability:** update payment facts/reader so only
   canonical self unpaid or self pending is actionable, add projection/HTTP tests
   and report (at most 5 files). Organization, free, paid, recovery, terminal and
   corrupt states remain false. Browser work remains separate.

If extracting the session validator would touch more shared files, split it before
the billing action rather than duplicating validation. No schema change is expected.
Any need for a new uniqueness key, free-settlement column, payment-method policy,
or invoice state requires a separate ADR/migration review instead of widening an
increment.

## Verification boundary for this preflight

This increment creates only this report. It did not run PHP, browser, SQLite, or
PostgreSQL tests because no executable source changed. It did not touch `.env`,
active data, provider/network, route/config, feature switches, payment, notification,
deployment, or push. `git diff --check` is the required verification before the
single report commit. STOP for contract review before any RED test or implementation.
