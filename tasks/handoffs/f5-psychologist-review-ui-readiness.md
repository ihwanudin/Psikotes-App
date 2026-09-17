# F5 psychologist review UI readiness

Status: read-only implementation-readiness report

Assigned baseline: `79390f3`, with accepted UI-readiness report `17cc3e4`

Observed main history while reporting: through `5afcc1e`

Scope: smallest Filament 5 psychologist-review UI slice that can be built from an immutable synthetic fixture before authoritative F3/F4/F5 persistence exists.

## Decision

A **testing-only, fixture-driven, single-page psychologist review prototype** is implementable now. It may render the frozen F3 eligibility, F4 bilingual narrative, G6, G7, validity, and signing-prerequisite shapes and exercise draft review interactions entirely in memory. It must not read or write a report record, create a signature, transition lifecycle state, publish a document, or expose a production route.

The prototype is useful because it freezes information architecture, role denial, public/internal projection separation, responsive behavior, keyboard behavior, G6 reason UX, G7 resolution UX, V3 stop behavior, and prerequisite messaging without pretending caller-supplied fixtures are persistence authority.

Actual review persistence and signing remain blocked. `ReportSigningSnapshotComposer` intentionally emits `persistence_authority_bound=false`; ADR-0029 requires a transactional reader to reload stored session/result/eligibility/G6/G7/narrative evidence and bind it to the exact case/report version before signing.

## Accepted contracts available to the fixture

### F3 result and eligibility

- `EligibilityDecisionSnapshot` derives one immutable decision from all 18 canonical aspects, the six accepted target fields, IQ, V1/V2/V3 validity, exact versioned standard configuration, and source versions for IST, PAPI, Kraepelin, RMIB, and reporting.
- `ReviewedEligibilityDecision` preserves system levels, applies only exact G6 level overrides, recalculates zones and recommendation, then applies an optional exact label override. It keeps system, recalculated, and final decisions side by side.
- V3 is publication-blocked and has no recommendation label. DASS is not an input to this decision.
- G7 source discrepancy is required when the source-level spread is at least two. Its evidence carries the canonical aspect, ordered source/level rows, minimum, maximum, spread, and automatic-narrative prohibition.

### F4 narrative

- `BilingualClusterNarrativeComposer` produces deterministic ID/JP drafts for clusters A-D from all 18 aspects in canonical order.
- Both languages omit the same unresolved G7 aspects and expose omission provenance. The Japanese child has no Indonesian connector behavior.
- The cluster drafts are accepted as review inputs, not final psychologist prose. S1-S7 integration text is not fully authoritative: S5/S6 are only bounded structural partials, while D support, RMIB tie/UMUM handling, suitability labels, system-versus-final G6 selection, and several prose rules remain open. The prototype must not invent those slot bodies.

### F5 review and signing policy

- Lifecycle states are `DRAFT_SCORED`, `DRAFT_NARRATED`, `UNDER_REVIEW`, `REVISED`, `SIGNED`, `PUBLISHED`, `REVOKED`, and `VOID`. The generic state machine cannot transition directly to `SIGNED`; signing is only through `ReportSigningTransitionPolicy` from exact `UNDER_REVIEW`.
- G6 level or label changes require a trimmed, valid UTF-8 reason of at least 20 Unicode characters. Unchanged values must not carry a reason. Level changes require eligibility recalculation; label-only changes do not change zones.
- A G7-required aspect has explicit `UNRESOLVED` and `RESOLVED` states. A changed final level must match a G6 override and its reason; a signing-ready set contains exactly all 18 aspects and no unresolved item.
- Signing prerequisites block V3, V2 without a procedure note, `DIPERTIMBANGKAN` without accompaniment conditions, unresolved G7, changed overrides with a short reason, missing target field, and any empty A-D narrative cluster.
- The signing snapshot binds the typed reviewed decision and all 18 G7 evidence projections, but it is not yet backed by stored authority.

## PRD/SPEC review requirements

The PRD and `SPEC.md` require one psychologist review screen containing raw instrument evidence, level and source detail for every aspect, standards/zones, active guardrails, editable draft narrative, a visually separate DASS panel, procedure notes, and validity. The machine creates drafts; a licensed psychologist decides and signs.

The two output projections must remain distinct:

| Projection | May show | Must not show |
|---|---|---|
| HPP preview | identity/administration, IQ, 18 aspect bands/zones, final ID/JP cluster narratives, general DASS category and non-judgmental general narrative, conclusion/recommendation, limitations | raw answers/scores, source anchors, aspect codes in the external document, validity checklist, DASS subscale scores/responses, internal S1-S7 drafts |
| Internal + DASS review | validity evidence and procedure note, raw instrument summaries, source levels/versions, system/final level and reasons, G7 evidence/resolution, guardrails, internal integration drafts when authoritative, detailed DASS subscales and follow-up | any pathway that feeds DASS values into levels, zones, G7, recommendation, or signing eligibility |

Only the general DASS projection belongs in the HPP preview. Detailed DASS data must be rendered only when the internal panel is active, not merely hidden with CSS in the HPP DOM. The underlying view model must also keep it in a separate nested object so HPP serialization cannot accidentally spread internal/DASS fields.

## Frozen synthetic view model

The testing-only page should accept one exact, already-canonical fixture projection. Field names below are owned by the prototype only and are not a public/API contract:

```text
fixtureId
case: { publicId, origin, organizationLabel, packageLabel, intendedField }
participant: { testNumber, displayName }
report: { publicId, version, state, expectedSnapshotHash|null }
validity: { status: V1|V2|V3, procedureNote|null, findings[] }
eligibility: {
  publicationBlocked,
  standardVersion,
  sourceVersions: { ist, papi, kraepelin, rmib, reporting },
  iq,
  aspects[18]: {
    code, label, critical, systemLevel, finalLevel, standard, zone,
    sources[]: { sourceCode, sourceVersion, level },
    g7: { required, state, spread, reasonCode, finalLevel|null, reason|null },
    override: { changed, reason|null }
  },
  systemLabel|null, recalculatedLabel|null, finalLabel|null,
  labelOverride: { changed, reason|null },
  guardrails[]
}
hpp: {
  clusters: { A:{id,jp}, B:{id,jp}, C:{id,jp}, D:{id,jp} },
  generalDass: { category, narrativeId, narrativeJp }
}
internal: {
  instrumentSummaries[],
  integrationSlots: { authorityReady, S1|null, S2|null, S3|null, S4|null, S5|null, S6|null, S7|null },
  dass: { subscales[], generalCategory, followUp, flags[] }
}
reviewDraft: {
  procedureNote|null,
  accompanimentConditions|null,
  clusters: { A:{id,jp}, B:{id,jp}, C:{id,jp}, D:{id,jp} }
}
signingReadiness: { canSign, blockerCodes[], persistenceAuthorityBound:false }
```

Every array must use canonical ordering and exact keys. The fixture uses fictitious names, test numbers, IDs, scores, and DASS values. It may exercise the existing pure domain policies, but the page must label the result **synthetic readiness only** and must not display a functional signing control.

For V3, the fixture must set `publicationBlocked=true`, all three labels to `null`, and readiness blockers containing `VALIDITY_V3`. The page shows a prominent “stop, no report, reschedule” state; override, narrative-finalization, and signing affordances are absent or disabled.

## Smallest exclusive implementation slice

Assign one UI worker exactly these new files:

1. `app/Filament/Pages/PsychologistReviewFixture.php`
2. `resources/views/filament/pages/psychologist-review-fixture.blade.php`
3. `tests/Feature/Admin/PsychologistReviewFixturePageTest.php`
4. `tests/Frontend/PsychologistReview/browser.test.mjs`

No existing Filament resource/page/provider, shared Blade component, policy, enum/ability, model, route, migration, DTO/API contract, domain policy, package file, or lockfile belongs to this slice.

Use a custom Filament 5 `Page`, not a `Resource`, because no authoritative report model exists. Follow the repository's existing Filament 5 patterns:

- `composer.json` pins `filament/filament ~5.0`;
- override `isDiscovered()` and `shouldRegisterNavigation()` so discovery/navigation are true only in the `testing` environment;
- `canAccess()`, `mount()`, `hydrate()`, and every Livewire action recheck the authenticated `admin` guard and both `ReviewReports` and `ViewDass` abilities;
- immutable fixture/baseline properties use `#[Locked]`; browser-editable draft fields are separately validated and never trusted as signing evidence;
- actions use Filament notifications and field errors, plus loading-disabled controls; no success notification may claim persistence.

The exact four-file boundary intentionally leaves synthetic server startup/authentication orchestration to the coordinator's existing test tooling. If the browser runner needs a reusable shared harness, that is a separate coordinator-owned increment; the UI worker must not add an unreviewed login bypass or route.

## Interaction and state rules

### G6

- Always show system and final level/label together; never overwrite or visually erase the system value.
- Selecting a different level reveals a required reason with a live character count. Nineteen Unicode characters fail; twenty pass after trimming.
- Reverting to the system value clears and disables the reason. A label override follows the same rule.
- A level change invalidates the displayed zone/recommendation preview until the server-side pure recalculation completes. Client-side arithmetic must not synthesize the result.
- DASS cannot appear in an override source, dependency, or recalculation request.

### G7

- Put unresolved aspects first in a labelled review queue, while preserving canonical A1-D5 identity in the full aspect list.
- Show every authoritative source/level/version, spread, system level, selected final level, and the fact that automatic narrative is withheld.
- Do not promote a runner-up narrative candidate when the true extreme is unresolved.
- Resolution requires an explicit final level. If it differs from system level, reuse the same G6 reason rule and show that both artifacts must match.
- `G7ReviewSet` is signing-ready only; the editable UI must not construct it until all 18 aspect states are resolved/not-required.

### Signing readiness

The prototype may show the stable blocker codes translated into actionable Indonesian text, but the raw code remains available for deterministic tests. It must always show the additional integration blocker `PERSISTENCE_AUTHORITY_UNBOUND` while using the synthetic fixture. Therefore no enabled “Sign” or “Publish” action is permitted.

The eventual signing action must additionally wait for ADR-0029 prerequisites: exact immutable case/report identity, package binding, latest expected report version and snapshot hash, authenticated psychologist principal, opaque idempotency key, transactional reload of all stored evidence, append-only signed version, audit, and replay/conflict handling.

## Tenant and role boundary

- Only `AdminRole::Psychologist` currently has both `ReviewReports` and `ViewDass`. Super admin, branch admin, and staff must receive 404/not-discovered behavior on direct access, not an empty page containing serialized fixture data.
- The psychologist RLS context is central/global in the current model. A branch selector must not be added to this review page.
- The future persistence reader must bind the requested report to exactly one ADR-0029 assessment case and perform authorization before projection. A known public ID must not bypass case ownership or role checks.
- HPP-only access for central/branch/LPK/kumiai users is a different F6/F7 projection and resource. It must never reuse this internal review page or its DASS-bearing view model.
- Reauthorize every Livewire hydration and action; hiding navigation or buttons is not authorization.

## Browser, accessibility, and responsive acceptance

The feature and browser tests use only synthetic fixtures and no PostgreSQL, real participant, real DASS response, external request, signature, document generation, storage URL, notification, or publication.

Required checks:

1. Guest redirects to the Filament login; super admin, branch admin, and staff cannot discover or directly open the page; psychologist can.
2. Production/non-testing discovery and navigation are false.
3. HPP mode contains the general DASS category/narrative but no subscale, response, raw score, source anchor, validity checklist, or internal slot in the DOM.
4. Internal mode exposes the synthetic detailed evidence only to the psychologist and clearly labels DASS as separate from eligibility.
5. Changed level and label keep system/final values side by side, enforce trimmed Unicode 20-character reasons, and never calculate zones in JavaScript.
6. An unresolved G7 aspect is prominent, excluded from automatic narrative, and blocks readiness until an explicit resolution is validated.
7. V3 shows the stop/reschedule state, no recommendation label, no enabled override/finalize/sign/publish action, and `VALIDITY_V3`.
8. V2 without a procedure note and `DIPERTIMBANGKAN` without conditions expose their exact blockers and focus the corresponding field from the blocker summary.
9. Missing target field, short override reason, unresolved G7, and blank A-D narrative each map to the stable policy blocker code and accessible text.
10. HPP/Internal panel controls are native buttons or tabs with name, selected state, keyboard operation, visible focus, and focus restoration to the panel heading.
11. Aspect/source data uses semantic headings or table captions/headers; zone and critical/G7 states are not conveyed by color alone; status changes use a restrained live region.
12. At 320, 390, 768, and 1280 CSS pixels, there is no page-level horizontal overflow, clipped reason field, inaccessible blocker, or hidden action. Dense aspect/source tables may use labelled contained scrolling or responsive cards.
13. Browser history/reload does not imply draft persistence; the page explicitly warns that fixture changes are temporary.
14. No unexpected console error, unhandled rejection, external network request, database write, lifecycle transition, signature, or publish request occurs.

Run the new focused Feature test, the synthetic browser test, scoped Pint/PHPStan for the Page, scoped frontend lint where applicable, and existing `AdminPanelAccessTest`/`AdminAuthorizationTest` regressions. Do not run PostgreSQL for this slice.

## What can be implemented now

- Testing-only page discovery and psychologist-only role gate.
- Frozen synthetic view-model validation and four-panel information architecture: overview/blockers, HPP preview, internal evidence, and separate DASS detail.
- In-memory G6 and G7 draft interactions using existing pure domain rules.
- Readiness blocker presentation, including an unconditional persistence-unbound blocker.
- V1/V2/V3 fixture scenarios, responsive/a11y behavior, and synthetic Feature/browser evidence.

## What must wait for persistence integration

- Production resource, route, list/query, record policy, or navigation.
- Authoritative normalized instrument result/source-level reader and F3 eligibility snapshot persistence.
- Append-only G6 override/recalculation, G7 resolution, edited bilingual narrative, and procedure-note persistence with audit.
- Full authoritative S1-S7 internal integration draft.
- Dedicated DASS reader/storage authorization and safe HPP general-category projection.
- Report/report-version tables, optimistic expected-version/hash commands, lifecycle transitions, signature identity/material, idempotency, publish/revoke, and document generation.
- Any enabled sign/publish action or claim that T-15/T-22/F5 is end-to-end complete.

## Acceptance conclusion

The four-file synthetic prototype is dependency-unblocked and safe to implement now if it stays testing-only and cannot sign or persist. It provides useful F5 UI evidence but does not close F3/F4 persistence, F5 signing integration, T-15, T-22, or F5 phase exit. Production review UI must wait for the ADR-0029 transactional report/version authority and stored F3/F4 evidence.
