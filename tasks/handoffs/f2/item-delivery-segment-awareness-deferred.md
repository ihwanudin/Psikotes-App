# Item-delivery segment-awareness — deferred until an IST/FA-WU reader exists

Written 2026-09-22 during timed-segments stage 5 (PR #109), per Lead's explicit agreement to
defer. This is the third and last piece of the plan doc's "Response shape and consuming-endpoint
changes" section (`tasks/handoffs/f2/timed-segments-plan.md`) — the other two (`GET /sessions/:id`
`current_segment`, autosave's per-subtest item_no range check) shipped in this PR.

## What the plan asks for

> `GET /sessions/:id/items` becomes segment-aware for any subtest with more than one segment
> (today, only ME): which sub-slice of that subtest's item payload is returned depends on
> `current_segment`, following the same per-reader field-whitelisting pattern each instrument
> reader already owns... For ME specifically: the word list is only present in the response
> while `current_segment.code == "ME_MEMORIZE"`; the recall/answer items only while
> `current_segment.code == "ME_ANSWER"`.

## Why this isn't built here

`RegistryAssessmentItemContentAuthority` (`app/Providers/AppServiceProvider.php`) has exactly one
real reader registered: Kraepelin. IST — the only instrument that needs ME's two-phase filtering
at all — has no reader yet and fails closed (`ASSESSMENT_ITEM_CONTENT_UNAVAILABLE`), same as
PAPI/RMIB. The plan's own text already flags this: "IST cannot even start yet on main... this is
forward infrastructure, not an active gap," and separately calls out that FA/WU's own `/items`
shape is "blocked on PR #73 merging."

The filtering itself has to live *inside* whichever reader eventually renders ME's items — the
plan explicitly says so ("following the same per-reader field-whitelisting pattern each
instrument reader already owns... this controller does no field-shaping itself," referring to
`GetAssessmentSessionItemsController`'s own existing doc comment). Building the
`AssessmentItemContentAuthority::contentFor()` interface change (passing current-segment info
into readers) now, with zero real readers to validate the shape against, risks guessing wrong and
having to redesign it the moment IST's actual reader lands — worse than just waiting.

## What's already in place for whoever builds the IST/ME reader

- `GET /sessions/:id`'s `current_segment.code` already distinguishes `ME_MEMORIZE`-shaped vs
  `ME_ANSWER`-shaped segments once a real catalog names them that way (stage 5, this PR).
- `TimedSegmentSweep` is the single source of truth for "what segment is current right now,"
  already used by three call sites (`GetAssessmentSession`, `AutosaveAssessmentAnswers`,
  `SubtestNext`) — a fourth call site (inside the future IST reader, or in
  `GetAssessmentSessionItems` calling into it) would follow the exact same pattern: sweep fresh
  from `test_sessions.current_segment_*`, never trust a stored value.
- `SessionDefinition::segments`/`segmentSubtestIndex` already carry ME's two-segment shape once a
  real catalog defines it with an explicit `segments` array on the ME subtest (stage 2, PR #109).

## How to apply

When the IST reader (or FA/WU's own reader, PR #73) is actually built and needs ME's
memorize/answer filtering: read `current_segment.code` (from a `TimedSegmentSweep` evaluation,
following the existing three call sites' pattern) inside that reader, and whitelist fields the
same way readers already do for everything else. `GetAssessmentSessionItemsController`/
`GetAssessmentSessionItems` should not need to change beyond, if anything, passing the swept
current-segment info down to `AssessmentItemContentAuthority::contentFor()` — design that
signature change against the real reader's actual needs at that time, not speculatively here.
