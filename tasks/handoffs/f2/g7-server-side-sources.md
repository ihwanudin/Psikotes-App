# F2 G7 server-side sources — design record and Phase 2 requirements (2026-09-21)

Status: Phase 1 accepted (coordinator-approved plan, 2026-09-21). Phase 2 is
blocked on `deepseek/f5-resign-and-rls` merging — it touches
`app/Filament/Pages/ReportSigning.php` and
`app/Services/Review/ReportSigningService.php`, both off-limits to this lane
until that branch lands.

## The gap this closes (Phase 1 only makes the closing possible — see below)

`app/Domain/Eligibility/AspectSourceDiscrepancyPolicy.php`'s `evaluate()`
receives `$sources` from whoever calls it. Before this work, the only
production call site with real consequences —
`ReportSigningService::sign()` — fed it straight from client-submitted HTTP
input (`$input['g7_resolutions'][*]['sources']`), with no cross-check
against `generic_instrument_result_sources`. A client could submit a
self-consistent but fabricated low-spread `sources` list and make
`review_required` false when the real ledger data would make it true,
hiding a G7 warning signal from the psychologist. `G7AspectResolution`'s
`authoritativeDiscrepancy()` only proves the discrepancy is internally
self-consistent (re-derivable by re-running the policy on the same
`sources`) — it never proves the `sources` themselves are true.

**A second, separate gap in the same call site, found while reading it for
this plan and confirmed by the coordinator as a Phase 2 requirement below**:
`ReportSigningService.php:174-183` silently defaults any aspect the client's
`g7_resolutions` *omits entirely* to a single synthetic `CANONICAL` source at
the system level — spread=0, never review-required. A client doesn't even
need to lie; it can just not send an aspect.

## Phase 1 (this PR): what it does and what it explicitly does not do

**Does not close the gap in production.** `ReportSigningService.php` is
untouched and still trusts client-submitted `sources` exactly as before.
Phase 1 delivers a tested, ready-to-wire replacement; Phase 2 is the actual
swap. Say this explicitly in the Phase 1 PR description — claiming otherwise
would be a false claim, the same reasoning that kept the TODO comment
in place rather than removing it.

**Does deliver, fully tested:**

1. `AspectSourceDiscrepancyPolicy::evaluate()` extended (backward
   compatible): `sources[].level` is now `int|null`. `null` means "this
   configured source has no trustworthy ledger reading yet." Any `null`
   source forces `review_required=true`, `reason_code='SOURCE_INCOMPLETE'`
   (takes priority over a simultaneous `SOURCE_LEVEL_SPREAD` — a spread
   computed from a partial set is not trustworthy either), and
   `minimum_level`/`maximum_level`/`spread` are all set to `null` — not
   computed from the partial set. This was an explicit design choice
   (coordinator decision, 2026-09-21): a summary number computed from
   incomplete data (`spread: 1`) reads as "the sources nearly agree" to any
   downstream consumer that only looks at the number, even though one
   source is entirely missing — that is more dangerous than no number at
   all. Every *known* level still appears individually in
   `provenance.sources`, so nothing knowable is hidden; only the summary
   is withheld. Existing callers (`ReportSigning.php`'s live UI estimate,
   `PsychologistReviewFixture.php`'s demo scenario) are unaffected: they
   never pass `null`, so their output is byte-identical to before.

2. New `App\Services\Eligibility\LoadLedgerAspectDiscrepancy` — takes
   `(int $assessmentCaseId, string $aspect)`, no `sources` parameter at all.
   Composes the existing `LoadGenericInstrumentResultSourcesForAspect`
   reader with the (now-extended) policy: every configured source whose
   `AspectSourceReadingStatus` is not `Found` (i.e. `NotFound`, `Ambiguous`,
   or `SourceMissingFromResult`) maps to `level: null`, uniformly — no
   Kraepelin-specific branch, see below. Requires an already-open service
   RLS context, exactly like the reader it wraps (does not open its own
   transaction, so it cannot stack a new context boundary on an existing
   one).

3. New `App\Services\Eligibility\ResolveG7AspectFromLedger` — mirrors
   `ReportSigningService::sign()`'s existing per-aspect dispatch
   (`review_required` + presence of `final_level` decides
   `notRequired`/`unresolved`/`resolved`) exactly, so Phase 2's swap is one
   call replacing the client-trusting block, not a re-implementation of the
   dispatch logic. Its only caller-supplied values are `finalLevel`/`reason`
   — a psychologist's legitimate professional-override input, never a
   source level. **Note on the approved plan**: the plan described this as
   a new `G7AspectResolution::fromLedger()` constructor; implementing it
   surfaced that giving the pure `Domain\Review\G7AspectResolution` value
   object I/O access (calling a database-backed aggregator) would violate
   this codebase's Domain/Services layering. The *existing*
   `G7AspectResolution::notRequired`/`unresolved`/`resolved` methods already
   do exactly what's needed once fed a trustworthy discrepancy array — the
   fix is entirely about *where that array comes from*, not about changing
   `G7AspectResolution`. `ResolveG7AspectFromLedger` (a Service, not a
   Domain addition) does the orchestration instead, calling the existing,
   unmodified constructors. `G7AspectResolution.php` itself only gained a
   PHPDoc type update (nullable summary fields, the new reason code) — no
   behavioural change.

4. `AspectSourceDiscrepancyPolicy.php`'s TODO comment updated, not removed
   (removing it now would misstate that the gap is closed) — it now
   describes the current state and points here.

**Consumer audit** (coordinator required checking every consumer of the
widened shape, not just callers of `evaluate()`), before extending the
type:
- `G7AspectResolution::authoritativeDiscrepancy()` — only checks key
  *presence* (`hasExactKeys`) and full-array equality; neither depends on
  `int` vs `null`. Safe, PHPDoc updated.
- `ReportSigningSnapshotComposer::projectG7Evidence()` — stores
  `$resolution->discrepancy()` opaquely (`'discrepancy' => ...`), never
  reads `minimum_level`/`maximum_level`/`spread` individually. Safe.
- `ReportSigning.php` (lines 365-374, 478-490) and
  `PsychologistReviewFixture.php:461` — call `evaluate()` directly with
  caller-supplied, always-non-null sources (live UI estimate / hardcoded
  demo data respectively). Never trigger the null path; output unchanged.
  Neither file's own Blade template reads `minimum_level`/`maximum_level`
  by name (grepped `resources/views`); only the demo fixture's blade
  template reads `spread`, and only ever receives a real int from that
  call site.
- `G7ReviewSet.php`, `ReportSigningTransitionPolicy.php` — grepped, no
  reference to `minimum_level`/`maximum_level`/`spread`/`discrepancy` at
  all.

## Phase 2 requirements (do not lose these between PRs)

Everything below applies to `ReportSigningService.php`/`ReportSigning.php`
once F5 merges and this lane is allowed to edit them again.

1. **Swap the source of `sources`, not the dispatch logic.** Replace
   `ReportSigningService::sign()`'s per-aspect block (currently:
   `$discrepancyPolicy->evaluate(['aspect' => ..., 'sources' =>
   $resolution['sources']])` then manual `notRequired`/`unresolved`/
   `resolved` dispatch) with one call to
   `ResolveG7AspectFromLedger::execute($assessmentCaseId, $aspect,
   $systemLevel, $finalLevel, $reason)`. `$finalLevel`/`$reason` still come
   from client input (legitimate psychologist override data); `sources`
   never does again.
2. **Evaluate all 18 aspects from the server's own list, not the client's.**
   Coordinator-flagged, non-negotiable: the current code iterates
   `$input['g7_resolutions']` (client-supplied) and only synthesizes a
   trivial `CANONICAL`/spread-0 default for aspects the client omitted
   (`ReportSigningService.php:146-183`). This is a second gap, independent
   of the fake-level one this PR addresses: a client doesn't need to lie,
   it can just not send an aspect. Phase 2 must iterate the server's own
   `self::ASPECTS` constant (or `aspect_sources.json`'s aspect list) for
   *every* aspect, calling `ResolveG7AspectFromLedger` for each one
   unconditionally. The client's `g7_resolutions` should only ever supply
   `final_level`/`reason` per aspect (matched by aspect code), never drive
   which aspects get evaluated at all.
3. **`ReportSigning.php`'s live UI estimate should eventually read from the
   ledger too**, for display consistency with what submission will
   actually decide — out of scope to specify further here since it depends
   on decisions F5 makes; flagging so the live estimate doesn't stay
   silently out of sync with the authoritative Phase 2 result after this
   lands.
4. Do not remove the `AspectSourceDiscrepancyPolicy.php` TODO comment
   without first confirming `ReportSigningService.php` no longer calls
   `evaluate()` with client-supplied `sources` anywhere.

## Kraepelin — real, expected product impact once Phase 2 lands

No code anywhere grades raw Kraepelin digit answers yet (blocked pending
psychologist authority, separate from the extraction-grid work), so
`generic_instrument_results` has zero Kraepelin rows for every case today.
Once Phase 2 wires `ResolveG7AspectFromLedger` into report signing, **every
aspect with a Kraepelin-sourced configured source
(`B1`, `B2`, `B4`, `C4`, `C5`, `C7` per `aspect_sources.json`) will read
`SOURCE_INCOMPLETE` and require psychologist review at every signing**,
until the Kraepelin scoring path exists. This is correct, honest behaviour
— not a bug, and not something Phase 2 should work around — but it is a
real workflow-visible change for every psychologist signing every report in
the meantime, and the coordinator is carrying this to the project owner.
Mention it explicitly in the Phase 2 PR description too, not just here.

## Files touched, Phase 1

- `app/Domain/Eligibility/AspectSourceDiscrepancyPolicy.php` (extended,
  backward compatible; TODO comment updated not removed)
- `app/Domain/Review/G7AspectResolution.php` (PHPDoc only, no behaviour
  change)
- `app/Services/Eligibility/LoadLedgerAspectDiscrepancy.php` (new)
- `app/Services/Eligibility/ResolveG7AspectFromLedger.php` (new)
- Tests: extended
  `tests/Unit/Eligibility/AspectSourceDiscrepancyPolicyTest.php`; new
  `tests/Feature/Eligibility/LoadLedgerAspectDiscrepancyTest.php` and
  `tests/Feature/Eligibility/ResolveG7AspectFromLedgerTest.php`.

**Not touched, per the file lock**: `app/Filament/Pages/ReportSigning.php`,
`app/Services/Review/ReportSigningService.php`,
`app/Filament/Pages/PsychologistReviewFixture.php` (demo fixture, left
alone — not a real call site).
