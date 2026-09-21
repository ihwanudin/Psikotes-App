# Debt: extract a shared retry-loader core (GLM, recorded 2026-09-21)

Recorded per Lead's instruction after reviewing `61c7395`/`cea25fd` on
`glm/papi-runner-prototype`: the same retry-loader pattern now exists as
three separate implementations. Lead's read: three copies is the last
tolerable point — RMIB will be a fourth, and this must be de-duplicated
before that happens.

**Update 2026-09-21 (RMIB runner, `glm/rmib-runner-prototype`, `c71c95b`):**
RMIB is now the fourth copy. As of RMIB's implementation, the PAPI runner
PR (#82) was still open/draft (not merged to `main`) — per the plan Lead
approved for RMIB, the gate below still holds, so RMIB was written as a
fourth near-identical copy rather than forcing the extraction early. This
debt is now overdue by Lead's own stated threshold; the extraction should
be the very next thing after PR #82 merges, not deferred further.

## The four current copies

- `resources/js/components/participant/session-runner/resume-answers-loader.ts`
- `resources/js/components/participant/kraepelin/items-loader.ts`
  (PR #69, `glm/kraepelin-runner-items`, not yet merged to `main`)
- `resources/js/components/participant/papi/papi-items-loader.ts`
  (PR #82, `glm/papi-runner-prototype`, not yet merged to `main`)
- `resources/js/components/participant/rmib/rmib-items-loader.ts`
  (`glm/rmib-runner-prototype`, no PR yet)

Each pairs with a thin React hook (`use-resume-answers.ts`,
`use-items.ts`, `use-papi-items.ts`, `use-rmib-items.ts`) that does
nothing but wire it to `useState`/`useEffect`.

## Why they stayed separate until now

Each instrument's `Outcome` union differs — e.g. PAPI's, Kraepelin's, and
RMIB's each have a `content_unavailable` case `resume-answers` doesn't —
so a premature shared generic risked forcing an awkward common shape onto
outcomes that genuinely differ per instrument. That reasoning held for
two, arguably three, copies. It does not hold at four, per Lead's own
stated threshold — see the 2026-09-21 update above.

## What to extract, once this is unblocked

The genuinely shared core, identical across all four today:

- Classifying a `network_error` outcome and a rejected/thrown fetcher as
  the same "couldn't reach the server" case.
- Transition to a `reconnecting` state, retried via an injected
  `queueRetry` (the same connectivity signal `offline-queue.ts` exposes
  — never a second connectivity detector).
- `MAX_CONSECUTIVE_AUTO_RETRIES = 5`, and resetting that budget whenever
  `retry()` is called manually.
- Stale-attempt handling (an in-flight fetch racing a `dispose()` or a
  newer attempt must not clobber state after the fact — each of the
  four has this, worth checking they're actually identical before
  assuming it, not just similar-looking).

What must stay per-instrument: the `Outcome` union type itself and
whatever mapping each instrument's raw response needs before it reaches
the shared core.

## Gate

**Do not start this before the PAPI runner PR (#82) has merged to
`main`.** Lead's instruction. The refactor must land as its own change,
and must pass every existing test in all four loaders' `*.test.ts` files
with zero behavioral changes — it's an extraction, not a rewrite. If any
existing test needs to change to make the extraction work, that's a sign
the "shared core" isn't as identical as assumed; stop and re-check rather
than adjusting the test to fit.
