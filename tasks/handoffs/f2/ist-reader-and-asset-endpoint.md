# F2 — IST reader Stage 1 + image-asset endpoint (2026-09-21)

Scope: Lead's plan sign-off, 2026-09-21 ("Tahap 1 (kerjakan sekarang):
`IstItemContentReader` untuk SE/WA/AN/GE/RA/ZR, didaftarkan sebagai
`ist_items`, gagal-tertutup lewat `status` tingkat atas ... ditambah disk
`ist-assets`, command sinkronisasi, dan endpoint penerbit URL dengan test
fixture sintetis. Satu PR."). ME (word-list ambiguity, psikolog belum
memutuskan) and FA/WU (PR #73, unmerged) are explicitly OUT of scope here.

## Post-review correction (2026-09-21, same day): RLS/GRANT gap on `assessment_asset_references`

Lead's review of the initial PR caught a real S4-class gap: the migration
created `assessment_asset_references` with no RLS and no GRANT at all. I'd
based "no RLS needed" on reading only `instrument_versions`'s ORIGINAL
creation migration (`2026_08_23_000000_create_instrument_versions_table.php`),
which indeed has none -- but missed that its actual service-only RLS/GRANT
were added later by a separate migration
(`2026_09_09_000100_harden_instrument_versions_history.php`), which I never
read. Without an explicit GRANT, PostgreSQL's default privileges leave
`psikotes_runtime` with broader-than-intended access (confirmed while
fixing this: `has_table_privilege('psikotes_runtime', ..., 'DELETE')` was
`true` until an explicit `REVOKE ALL PRIVILEGES ... FROM psikotes_runtime`
was added before the narrower `GRANT SELECT, INSERT, UPDATE` -- SQLite has
no privilege model at all, so this was invisible to every SQLite test that
had run until this point).

Fixed by folding service-only RLS + GRANT directly into the table's own
creation migration (matching `identity_evidence`'s single-migration shape,
since there's no pre-existing unprotected data here to migrate around, so
no separate harden step is needed): `REVOKE ALL` then `GRANT SELECT,
INSERT, UPDATE` (no DELETE -- nothing in this codebase deletes a row),
`ENABLE`/`FORCE ROW LEVEL SECURITY`, and three `app_private.app_role() =
'service'` policies (select/insert/update). New
`tests/Postgres/AssessmentAssetReferencesSecurityTest.php` (6 tests)
proves: the exact privilege/policy shape; a participant context's direct
`SELECT` is RLS-masked to zero rows and a direct `INSERT` is refused
(SQLSTATE 42501); the real `GetAssessmentSessionAssetUrlController` issues
a URL end-to-end under PostgreSQL for an `in_progress` IST session; a
cross-instrument asset 404s (`ASSET_NOT_FOUND`) under PostgreSQL;
`SyncIstAssets`'s write path succeeds under the exact role `assets:sync-ist`
runs as at deploy (`psikotes_runtime`, confirmed by asserting
`current_user` directly -- see the role clarification below); and a
migration up→down→up cycle round-trips cleanly.

**Which role runs `assets:sync-ist` at deploy** (Lead asked this
explicitly): unlike `migrate` (owner-only, `pgsql_migration` connection),
`assets:sync-ist` is a plain `php artisan` command against the app's
normal `pgsql` connection -- i.e. the runtime role `psikotes_runtime`,
under RLS `app.role='service'` via `RlsContextRunner::runAsService()`,
exactly like every other write this codebase makes. It needs no owner
credential. Documented in `DEPLOYMENT.md`'s bootstrap sequence.

## What shipped

- **`IstItemContentReader`** (new,
  `app/Services/AssessmentSessions/IstItemContentReader.php`) — reads
  `instrument_versions` code=`ist_items` (separate row from code=`ist`,
  which holds scoring norms: `match_strategy`, answer keys). Builds
  `SE/WA/AN/GE/RA/ZR` only; every item field is explicitly whitelisted
  (`item`, optional `text`, `options`), never a decoded-JSON passthrough,
  so a stray scoring field added to `ist_items.json` in the future would be
  dropped, not leaked. Registered in `AppServiceProvider` (`'ist' => new
  IstItemContentReader`) and `InstrumentSeeder::SOURCES`
  (`'ist_items' => 'ist_items.json'`).
- **Fail-closed for the whole instrument comes for free.**
  `ist_items.json`'s top-level `status` is `"draft"` for as long as ANY
  subtest (today: `ME`) is draft — the reader only has to check that one
  field. Because `AllocateAndStartAssessmentSession` already calls
  `contentFor()` at allocation (established in the RMIB work) and a
  non-final reject there blocks session creation before `/items` is ever
  reached, **the whole IST instrument stays unstartable** until ME (and
  FA/WU) are also final. No new start-gate code was needed — this was
  already the documented behaviour (see API_CONTRACT.md's existing
  `ASSESSMENT_ITEM_CONTENT_UNAVAILABLE` paragraph for the generic start
  endpoint).
- **Defense-in-depth beyond the top-level gate**: if `ist_items.json` ever
  claims a subtest this reader does not support (`ME` today; `FA`/`WU`
  once #73 lands) is `"final"`, the reader throws rather than silently
  shipping a response missing that subtest. If a required subtest
  (`SE`/`WA`/`AN`/`GE`/`RA`/`ZR`) is missing entirely, same thing. Both
  proven in `tests/Feature/AssessmentSessions/IstItemContentReaderTest.php`
  against a *modified copy* of the real `ist_items.json` (only the
  top-level `status` flipped to `"final"` — the real seeded row is
  reserved for the "still fails today" regression test).
- **Asset infrastructure** (image assets aren't in `main` yet — FA/WU is
  PR #73 — so everything below is proven with synthetic fixtures per
  Lead's plan review, not real IST images):
  - `assessment_asset_references` table (new migration) — maps an opaque,
    stable `asset_id` (ULID) to `(instrument, disk, object_key,
    checksum_sha256)`. No RLS, same posture as `instrument_versions`
    (versioned reference/catalog content, not participant-scoped). An
    item's `/items` payload is only ever allowed to carry `asset_id` —
    never a disk path — once a reader actually emits image-bearing
    subtests (FA/WU, a follow-up PR).
  - `ist-assets` disk (`config/filesystems.php`) — same local/S3-toggle
    template as `identity`/`payment-proofs`, **but** `serve => false`
    locally (GLM finding, fixed 2026-09-22): Laravel's own signed-URL
    route (`Illuminate\Filesystem\ServeFile`) only sets
    `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` on a
    success response, not on 403/404 -- a gap that let a browser
    heuristically cache a transient 404 (RFC 9111) for a URL that could be
    reissued byte-identical within the same second (`expires` is
    second-precision). `ServeIstAssetController` +
    `AppServiceProvider::configureIstAssetTemporaryUrls()` replace it:
    explicit no-store on every branch, plus a signed `nonce` per
    `temporaryUrl()` call so no two issued URLs are ever identical. See
    `tests/Feature/AssessmentSessions/AssessmentSessionAssetUrlTest.php`'s
    `test_two_urls_issued_in_the_same_second_are_never_byte_identical`,
    `..._actually_serves_the_asset_with_no_store_headers`,
    `..._404s_with_no_store_headers_once_the_file_is_gone`, and
    `test_a_tampered_signature_is_rejected_with_no_store_headers`.
    `identity`/`payment-proofs` still use the framework's own `ServeFile`
    unchanged -- this fix was scoped to the disk GLM actually flagged, not
    a blanket redesign of every private-disk route.
  - **`SyncIstAssets`** (`app/Actions/AssessmentAssets/SyncIstAssets.php`)
    + **`assets:sync-ist`** console command — copies
    `database/seeders/data/assets/ist/**/*.png` (checked-in source,
    reviewed the same way as `ist_items.json`) onto the `ist-assets` disk.
    Idempotent (unchanged source+disk bytes are skipped); a changed source
    file gets its bytes and checksum updated but keeps the SAME `asset_id`
    (stable identity across content revisions). Verifies the just-written
    bytes against the source checksum and throws
    `AssessmentAssetSyncFailed` on mismatch — the console command turns
    that into a non-zero exit, which **must fail the deploy** (Lead's
    explicit requirement; documented in `DEPLOYMENT.md`'s bootstrap
    sequence, run right after `migrate`). Deliberately does NOT handle a
    source file being *removed* (no real removal case exists yet — see the
    class's own doc comment for why that's a deliberate scope cut, not an
    oversight).
  - **`GetAssessmentSessionAssetUrl`** + `GET
    /sessions/{id}/assets/{assetId}/url` — same sealed-controller,
    "readable exactly when writable" gate as `GetAssessmentSessionItems`
    (ownership + `in_progress` + before `ends_at`). GET, not POST — Lead's
    call: issuing a temporary URL changes no persisted state. Expiry is
    `min(now + 10 minutes, ends_at)`, mirroring the existing
    `identity`/`payment-proofs` `Storage::temporaryUrl()` mechanism (15
    minutes there; 10 here per Lead's spec). The JSON response itself adds
    `Cache-Control: no-store, private` — distinct from the header the
    actual asset fetch gets once the client follows the URL.

## Proposal (not implemented): drop or fail-close the `IST_ASSET_FILESYSTEM_DRIVER=s3` branch (2026-09-22)

Follow-up to the cache/nonce fix above (`1f66417`). The nonce mechanism in
`AppServiceProvider::configureIstAssetTemporaryUrls()` is silently inert for
an S3-backed `ist-assets` disk: `FilesystemAdapter::temporaryUrl()` checks
`method_exists($adapter, 'getTemporaryUrl')` before ever consulting
`buildTemporaryUrlsUsing()`, and the S3 adapter has that method, so the
callback never runs — an S3-backed disk would silently reopen the exact
same-second URL-collision gap this PR closes for local. Separately,
`league/flysystem-aws-s3-v3` is not installed in local `vendor/` at all
(untestable here either way).

Lead's proposal (2026-09-22, pending their final decision): in production,
IST assets should stay on the **local** disk. The asset set is small,
static, read-only, deployed alongside the application as instrument data
via `tools/extract/` — S3 buys nothing here but adds a dependency and
reopens this cache bug. Concretely: either remove the
`IST_ASSET_FILESYSTEM_DRIVER=s3` branch in `config/filesystems.php`
entirely, or make it fail-closed at boot in production (reuse
`AppServiceProvider::missingProductionConfiguration()`'s pattern — refuse
to boot if `IST_ASSET_FILESYSTEM_DRIVER=s3` is set, until S3 gets its own
nonce/cache-header design). Not implemented; Lead is deciding.

## Explicitly deferred (see Lead's plan-review reply, 2026-09-21)

- **FA/WU item shape** — PR #73 (`f0/extract-ist-fa-wu`, `73f709b`) has the
  real data, still unmerged (CI outage). Its actual shape (confirmed by
  Lead, reading `origin/f0/extract-ist-fa-wu:database/seeders/data/ist_items.json`
  directly) is **each answer OPTION is its own image**, not one shared
  legend image per subtest as this PR's original plan draft assumed:
  `FA.option_legends[].options.{a..e}` and each item's own `image`, `WU`
  the same with one shared `option_legend`. `IstItemContentReader` needs a
  follow-up PR once #73 merges to build these two subtests' `asset_id`
  resolution against `assessment_asset_references` (55 PNG files expected
  per Lead's count).
- **ME** — word-list content ambiguity is a psychologist decision
  (`draft_reason` in `ist_items.json`), not touched here at all.
- **ME's two-phase timer / `POST /sessions/:id/subtest/next`** — genuine
  architecture gap, not IST-specific glue: no instrument built so far has
  more than one deliverable phase, so "which subtest is active right now"
  doesn't exist as a concept anywhere in `SessionDefinition` or the
  session-http actions yet. Lead is taking one specific question to the
  psychologist first: whether subtest-instruction reading time is inside
  or outside the subtest's own timer (this decides between an
  elapsed-time-derived phase model and an explicit
  participant-triggered-with-a-sweep model — see the plan thread for the
  full tradeoff). Not to be built until that answer comes back.

## Test coverage

- `IstItemContentReaderTest.php` (7 tests): builds all six supported
  subtests correctly from the real extracted text (top-level status
  overridden to `final` for the test only); still fails closed against the
  actual seeded `ist_items.json` (the real regression guard — must keep
  failing until ME/FA/WU resolve); rejects a non-IST instrument; fails
  closed with no active/checksum-mismatched authority; fails closed when
  an unsupported subtest is also marked final; fails closed when a
  required subtest is missing.
- `SyncIstAssetsTest.php` (7 tests): syncs every `.png` and ignores other
  extensions; a second run with no changes is fully unchanged with stable
  `asset_id`s; a changed source file gets a new checksum but keeps its
  `asset_id`; a misconfigured target disk is reported as a failure and
  writes no row; the console command's exit code matches (0 success / 1
  failure) for a bad disk and a missing source directory.
- `AssessmentSessionAssetUrlTest.php` (11 tests, HTTP-level): TTL capped at
  10 minutes when the session has more time left; TTL capped at the
  session's own remaining time when that's shorter; an asset registered
  under a different instrument is rejected; unknown `assetId`;
  `SESSION_NOT_STARTED`/`SESSION_CLOSED` (all four closed statuses)/
  `DEADLINE_EXCEEDED` (with a no-write-on-read proof, same as the items
  endpoint); byte-identical 404 for nonexistent vs. foreign session.
- `AssessmentSessionHttpBoundaryTest.php` extended with the new route —
  proves `GetAssessmentSessionAssetUrlController` has zero DB/Eloquent/
  RlsContextRunner dependency and stays outside the `rls` middleware group,
  same sealed-boundary proof as every other session-http controller.

## Test suite status

- SQLite: `tests/Feature/AssessmentSessions`, `tests/Feature/AssessmentAssets`,
  `tests/Unit/AssessmentSessions`, `tests/Architecture` — green (see PR
  description for exact counts).
- `phpstan` (level 7, `APP_ENV=testing`): 0 errors on every new/changed
  file. `pint --test`: clean. (`AssessmentSessionHttpBoundaryTest.php` has
  6 pre-existing phpstan errors on lines unrelated to this change,
  confirmed present before this PR by running phpstan against the
  unmodified file from `HEAD` — not introduced here, not fixed here,
  out of this PR's scope.)

## CI note

GitHub Actions was down repo-wide (billing issue) earlier in this session;
per Lead, local results stand in until it recovers.
