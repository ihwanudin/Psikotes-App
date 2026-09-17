# P14 — preflight browser summary privat

Status: proposal untuk review, report-only, 2026-09-04. Baseline worker tetap
`9087047b7214b8568d9645b0f1eb6243abdc2859`; root telah menerima sebagai `b06935f`.
Tidak menjalankan browser/server, init/migration, provider, build atau tes runtime
dalam increment ini. Tidak mengubah aplikasi, harness, konfigurasi, route maupun
laporan backend lama. Hanya dokumen ini yang dimiliki dan di-commit.

## Rekomendasi dan lingkup minimum

Gunakan harness checkout existing dengan dua file delta setelah persetujuan:

1. `tools/testing/tests/Browser/serve-checkout-session.php`: GET /checkout memakai
   method `summary` dan hanya boundary + named throttle; exchange/logout tetap.
   Tambah guard/fake eksplisit, fixture summary terbatas dan verifier postcondition.
2. `tools/testing/tests/Browser/checkout-session.browser.mjs`: ganti assertion
   descriptor lama dengan exact v1, tambah skenario history/multi-tab/own-price
   serta observasi aman. Pertahankan transport/cookie/native logout negatif.

`https-loopback-proxy.py` tidak perlu berubah untuk minimum ini. Laporan hasil
terpisah menjadi file ketiga increment pelaksanaan. Jangan membuat endpoint
summary JSON, halaman frontend, arbitrary fixture mutation API, runner operasi,
route produksi atau perubahan middleware bersama. Jika dua file harness ternyata
tidak cukup, minta review delta spesifik sebelum memperluasnya.

Dibaca root read-only: parallel-work.md (dispatch terbaru dan acceptance summary),
plan.md, todo.md serta ADR-012 `0012-private-integrated-checkout-session.md`,
termasuk amendment P14b0 dan native Origin:null. Status historis pada plan tidak
menggantikan instruksi terbaru: internal summary dan HTTP sintetis diterima,
P14 browser/public dan P15 masih terbuka. Proposal HTTP 1c62d51, controller/view,
tes summary, harness browser dan laporan P14b2 juga menjadi acuan.

## Audit harness existing: yang dapat dipakai dan gap

| Sumber | Temuan konkret | Delta minimum / batas bukti |
| --- | --- | --- |
| serve-checkout-session.php | Direktori harus anak langsung OS temp, basename oncam-checkout- + 32 hex, tanpa .env; init menolak database existing. DB/browser storage/cache ditempatkan disposable. | Pertahankan; tambahkan penolakan source-copy .env/config cache, symlink/junction/reparse keluar workspace disposable, mode/port salah sebelum boot. Komentar lama menyebut workspace .env, tetapi pengecekan eksplisitnya hanya direktori temp. |
| Serving | cli-server, REMOTE_ADDR 127.0.0.1, Host psikotes.oncam.id/oncam.id; trusted proxy 127.0.0.1. | Port env hanya divalidasi pola 8xxx, bukan bukti port listen. Operator harus memverifikasi bind/PID/command line exact. Jangan mengklaim guard itu melindungi server yang dijalankan dengan bind salah. |
| Bootstrap | Testing, debug false, SQLite path exact, DB_URL kosong, koneksi lain/Redis dibuang, storage/public/cache terisolasi; Http::preventStrayRequests. | AppServiceProvider masih bind XenditProvider/N8nNotifier. Tambahkan instance FakePaymentProvider/FakeNotifier dan Mail::fake sebelum boot, seperti harness reviewer; assert binding aktual. Testing/secret kosong saja bukan fake binding. |
| GET route | show + AuthenticateCheckoutSession, atribut data-checkout-session dipakai perbandingan tab. | Ganti ke summary + boundary/throttle saja, tanpa auth hydrate kedua. Tidak menambahkan atribut principal ke view. Logout tetap AuthenticateCheckoutSession + VerifyCheckoutSessionMutation. |
| Fixtures | issue membuat PROVISIONED, self, profil parsial, package 100, tanpa charge. | Cocok untuk no-charge/catalog, bukan bukti own-price/paid/partial. Tambah fixture cohort explicit terbatas; tidak menjadikan nominal katalog harga final. |
| Control | issue/recover/revoke/expire/state memakai alias regex dan header synthetic-only. state hanya sesi/terminal/LOGOUT count. | Header ini marker lokal, bukan auth production. Tolak control dari navigasi browser melalui interception; hanya driver loopback yang boleh memanggilnya. Tambah verifier CLI dan fixed fixture profiles, bukan arbitrary SQL/field/ID input. |
| Browser network | Sources difulfill in-memory; destination asli diteruskan melalui DNS override ke TLS loopback; browser Cookie/Origin/Referer tidak ditulis ulang. | Pertahankan real request transport dan allowlist fail-closed pada setiap context/tab. Stop sebelum navigasi pertama jika interception/resolver belum terpasang. |
| Browser assertions | Dua source, real encrypted login, fixation, replay, native logout, expiry/recovery; history hanya URL aman, dua tab memakai descriptor ID. | Perlu exact v1/own facts dan record server-read versus DOM restore; old check tidak membuktikan logout/recovery history atau summary privacy. |
| Verification | PHP control/state tidak membuktikan billing/consent/identity unchanged. | Persist baseline sintetis terurut sebelum browser, lalu compare exact business state pada akhir. Count saja tidak mendeteksi update. |

Hash sumber worker yang diaudit (SHA-256):

- serve-checkout-session.php: `65896fed8261e6bb723ecbd07df4e65004f8e25172a6aa96035bcab34d217554`
- https-loopback-proxy.py: `cd66a224abe8c10c3e5e5742a5f2e354a3fe717cc0c429c083b66e6f0dc71fa7`
- checkout-session.browser.mjs: `8461de71e0ede54c55016423f93b1278bd91e3a4adecfd82816c56fdac9d23cd`

## Disposable source/runtime and exact serving boundary

Prepare a NEW run directory `<OS-temp>/oncam-checkout-<32 lowercase hex>` only
after implementation review. Its database, file sessions, compiled views, logs,
certificate/key, driver config and fixtures must all belong to that run. Do not
reuse old browser scratch or the ignored identity build artifact: their PHP/view
snapshot predates 9087047. Never copy .env, active data/storage/cache, old fixture
registry, hot file, or cached config/routes/services/packages. Preserve the old
artifact and baseline overlays; no cleanup of unrelated historical scratch.

Use a fresh independent source copy from THIS worker's reviewed current files,
including accepted overlays, under the run directory (e.g. source/). A plain git
archive of HEAD is insufficient because required accepted prerequisites remain
untracked in this worker. Use an explicit source allowlist: app/bootstrap/config,
database migrations/factories, routes, resources/views, lang if present, composer
files, required tests/Support and the two harness files. Exclude bootstrap/cache
contents, dependencies and storage from generic copying. Copy vendor independently
only after composer.lock equality; verify no shared junction/symlink. Capture a
relative-path/hash manifest without file contents and compare critical controller,
view, lifecycle/composer/DTO, middleware and harness hashes before launch.

No Vite build or node_modules is required to render summary or the harness's
literal login/auth probes. Summary is Blade without assets. Use the already
installed Playwright CLI/cached Chromium; verify executable/version before any
run. Do not create fake manifests or disable Vite globally. If an unexpected route
needs assets, treat that as a scope gap instead of borrowing the stale build.

Exact proposed listeners remain the established pair:

- PHP: `127.0.0.1:8126`, ONCAM_CHECKOUT_BROWSER_PORT=8126; explicit temporary ini,
  router in the fresh source copy, document root `<run>/storage/public`.
- TLS proxy: `127.0.0.1:443`, upstream only `127.0.0.1:8126`; one-day synthetic
  self-signed SAN certificate for psikotes.oncam.id and oncam.id inside the run.
- Driver control: `http://127.0.0.1:8126/__browser/control/<fixed-operation>`, exact
  Host psikotes.oncam.id and existing synthetic-only marker, never remote URLs.

Before launch inspect both ports, including IPv6/wildcard listeners; if occupied,
STOP without killing an unrelated process or silently changing the destination
origin/port contract. Start hidden with exact captured PIDs, verify executable,
command line and listening addresses. Test process configuration must use only
synthetic APP_KEY, APP_ENV=testing, APP_DEBUG=false, exact disposable SQLite,
empty DB_URL/provider secrets, isolated file sessions, array cache/mail and fake
contracts. No real .env read and no global/session config change in application.

Dedicated isolated headless Chromium context, ignoreHTTPSErrors for this synthetic
certificate only, serviceWorkers=block; no existing browser profile. Keep launch
rules `MAP psikotes.oncam.id 127.0.0.1, MAP oncam.id 127.0.0.1, MAP * ~NOTFOUND`,
no-proxy-server and disable-background-networking. Fulfill only fixed source
documents at https://seleksi.beasiswajepang.id/handoff and
https://seleksi.serbaindo.com/handoff (plus fixed hostile test documents).
Destination requests must reach the PHP handler, not be fulfilled with mock HTML.
Unknown requests abort. This proves controlled HTTPS browser semantics, not live
DNS, production TLS, real source-site configuration or network-wide confinement.

The source forms retain token-free strict-origin-when-cross-origin policy so
exchange receives the exact source Origin. Checkout retains no-referrer. The
native logout exception is only the accepted literal-null, verified-secret form
case; never broaden exchange allowlists or rewrite browser Origin/Cookie headers.

## Fixture and postcondition design

Seed fixed, allowlisted synthetic profiles before measurement, using existing
AssessmentAccessFixture/snapshot helpers where applicable. Maintain issue/recovery
through real IssueCheckoutHandoff, never insert an authorized checkout session.
Select fixture profile by fixed alias on the driver control path only; it is not a
participant-supplied package/payer selector. Recommended smallest cohort:

- A: partial profile, no charge, catalog label, null amount/consultation, locked.
- B and C: two own attempts in a two-participant organization bill with distinct
  synthetic profile labels, own amounts and a larger parent total; valid frozen
  snapshot. B paid with IST ready/DASS pending, C has separate own identity.
- D: second attempt of A's participant, with distinguishable own package facts,
  to prove browser cookie replacement is attempt-scoped rather than person-scoped.

Use harmless synthetic markers for foreign profile/invoice/proof and escaped
closing-script/quotes/Unicode legal/profile text. No true identities, provider
invoice call, finalizer run, identity upload or consent mutation during browser
read tests. Paid/ready fixture data is preseeded synthetic setup, not evidence
that browser summary performs settlement. Two members is minimum privacy proof;
do not call it ten-item/full P17 acceptance.

Record ordered full-row baseline (local only) for participants, attempts, packages/
items, charges, bills/items, entitlements, consent, identity evidence/verifications,
assessment engine sessions, invitations, orders and outbox. Existing actual table
names must be resolved from the current schema during implementation; do not
invent missing tables or silently skip mandatory ones. Preseed audit baseline
separately. Test-owned client-disable/expiry controls are explicit scoped expected
deltas, not broad exclusions. Keep paid business cohort separate from revoke and
expiry fixtures to simplify exact comparison. Any lazy control fixture creation
must be recorded as setup before that scenario's baseline, not ignored at the end.

Add CLI verify mode to the same guarded PHP harness, reading its private registry
and baseline. It returns booleans/counts only: unchanged business rows, allowed
handoff/session lifecycle and audit deltas exact, no duplicate LOGOUT on replay,
no new orders/notifications/outbox/assessment sessions. Observe request SQL writes
without bindings in the harness for scenario-specific allowed-table checks and
ensure context/transaction return empty after each request. Provider/notifier
resolution/calls during measured reads should trip a harness assertion rather
than silently succeeding on a fake; Mail/HTTP assertions are request-local and
must not be claimed as cross-process counters. Any receipt aggregation must stay
in this run and be bounded. No log of SQL bindings or secret-bearing payloads.

## Browser matrix and pass criteria

| Case | Required observation |
| --- | --- |
| Two source sites | Actual top-level form POST from each exact origin, canonical body-only bearer, Lax auth cookie absent on POST; exchange 303 then actual summary GET 200. URLs, Location and Referer remain tokenless. |
| Auth preservation | Real harness web login; compare encrypted cookie bytes around checkout-only requests, inspect no Set-Cookie global, then probe exact synthetic auth principal before/after. Refresh baseline cookie after a deliberate web probe because it can re-encrypt itself. Login alone cannot read checkout. |
| Real summary | Exactly one inert application/json script with id checkout-summary-v1; parse as JSON, recursively assert exact accepted v1 keys/types and expected own values, flags false. No descriptor ID, formKey, credential, batch count/total/invoice or foreign marker. No content negotiation endpoint. |
| Escaping/CSP | Hostile synthetic text roundtrips in inert JSON and appears only as text, no injected nodes/event attributes, no executable script or asset requests. Exact CSP/no-store/private/no-referrer/nosniff/frame deny on success and observed denials. Native JS-disabled context must render and logout. Driver evaluation is instrumentation, not an application script. |
| Own payment | A remains catalog/null/locked; B uses frozen own snapshot despite changed catalog and parent total, paid remains distinct from partial access/DASS required. C's marker absent in B HTML/JSON. Compare both DOM and payload, not screenshot alone. |
| Refresh | Observe an actual GET response and current own facts; server read/idle once per HTTP request. No business writes. Cookie pair unchanged and no refreshed cookie absolute expiry. |
| Logout history | Keep a second tab open on delivered summary. Native logout in first tab: literal-null Origin, accepted CSRF, 303, two-cookie clear and one LOGOUT audit. Record stale tab DOM before reload, then force actual GET: unavailable/no payload. Back/forward outcome recorded separately from server request result. |
| Recovery history | Issue recovery through real action, before exchange force old-cookie GET -> clear303. New generation exchange succeeds. Old delivered form/CSRF submitted with new shared pair must fail419 without revoking new owner; reinstalled stale pair must fail303 and never revive old generation. Restore new pair only through legitimate fresh exchange for subsequent scenarios. |
| Shared tabs/attempts | Tab A initially sees attempt A; exchange D in tab B replaces same host/path cookie pair. Already-delivered A DOM may remain; A reload must show D's current own facts, never a mix. Do not compare absent data-checkout-session or add internal IDs to JSON. Stale A logout form with D cookies fails419; fresh D logout clears shared browser cookies. |
| Cross-attempt orphan | Switching attempts need not revoke every other server row; old A record can remain active until expiry because cross-site Lax request omits its selector. Assert actual per-attempt/session history, not global one-session-per-browser. No claim that cookie replacement revokes an unrelated attempt. |
| Denial | Missing/mismatched pair, query injection, expiry/revoke, wrong host/source and stale replay fail closed. Preserve native hostile/dual/header/Fetch Metadata negatives; distinguish browser-blocked request from HTTP denial. |
| Layout/keyboard | 1280x800, 390x844, 320x720; headings/labels/read order, no horizontal overflow for defined fixture strings, keyboard reaches sole logout. If unbreakable hostile text overflows, report actual layout defect; do not edit application/view inside harness scope. |

## History/BFCache is not current authority

Record every back/forward navigation with safe path, visible fixture alias,
whether a new document response was observed, and optional pageshow.persisted
instrumentation with no credential reads. Absence of a response does not alone
prove BFCache; distinguish retained DOM, browser restoration and unknown behavior.
Request interception, serviceWorkers=block and automation can affect caching, so
do not claim general BFCache eligibility from this controlled harness. Never
disable fences or caching controls merely to manufacture a restoration result.

No-store and logout cannot retroactively remove HTML already delivered to an open
tab or user. A stale page may remain visible; this is an observation/UX limitation,
not proof that it can authorize another request. Required security invariant:
explicit reload/new GET/current mutation revalidates the persisted credential
pair and generation. If a tab still holds an old CSRF while cookies point to a new
attempt, mutation fails; do not silently act on the new attempt. Browser history
may internally retain POST/form data: assert no credential in navigated URLs,
not an impossible claim that bearer never existed in browser memory. Resubmission
must remain replay-denied. No automatic page-hide script or history rewrite is
proposed to conceal stale DOM.

## Evidence, cleanup and review gate

Keep raw handoff/selector/CSRF/login cookie only in transient driver/protocol
memory as needed for comparisons; no trace/HAR/storageState export, request dump,
full page source, screenshot of source forms, clipboard or printed exceptions.
Screenshots may show only approved synthetic visible labels on summary/unavailable;
hidden/meta secrets must not appear in attachment captures. Parse/assert JSON in
memory and return boolean shape/privacy results, not raw HTML or JSON payload.
Sanitize console/network records to fixed method/path/status and assertion codes;
unknown errors must not print their full text/URL before redaction. Check all local
PHP/proxy/app/CLI logs for credential prefixes/digests and prohibited fixture
markers before exporting evidence; report hit counts, never matches.

Drain async observations before evaluating results/closing contexts. Retain exact
counts for expected HTTP errors and any opaque-frame instrumentation/local-network
blocks; the earlier two opaque browser blocks were not server-denial evidence.
Do not inherit old counts as new passes. Record browser/runtime/source hashes,
actual case results and limitation flags. New summary needs no asset/build claim.

After driver assertions run CLI verifier and persist only redacted outcomes. Close
only this named isolated browser and all its test contexts. Stop only recorded
PHP/proxy process identities, verify no owned child/listener remains on 443/8126.
Validate resolved run path remains a direct correctly named OS-temp child with
no reparse escape before native PowerShell removal of its files/directory. Never
cross shells to bypass deletion restrictions. If cleanup is denied, report exact
remaining synthetic scratch and stopped-port/process status; do not repeat the
old cleanup workaround or claim full cleanup. Preserve old scratch/build artifacts.
Check owned file hashes/git diff afterward: no application/config/routes or root
project mutation, no .env, no production activation. Report fixture teardown
separately from code rollback; never run migration down on an active database.

This proposal establishes a feasible bounded harness delta, not browser GREEN.
The current harness is insufficient unchanged due to descriptor assertions,
missing explicit fake bindings and incomplete business postconditions. It does
not require changing application semantics. If real summary rendering reveals a
defect or guarded launch cannot satisfy isolation/ports, STOP with precise evidence
for review. No public endpoint/P15, provider/notifier, deploy/push, new agent/task
or baseline reset is authorized. This increment verifies only report diff hygiene
and single-file staging, then STOP for the coordinator's implementation decision.
