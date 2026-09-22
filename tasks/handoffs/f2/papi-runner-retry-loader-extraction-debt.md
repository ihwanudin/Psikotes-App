# Debt: extract a shared retry-loader core (GLM, recorded 2026-09-21)

**Update 2026-09-22 — done, gate relaxed by Lead.** CI still down with no
ETA; Lead relaxed the "wait for #82 to merge" gate below, same reasoning
and same pattern as the HTTP-transport connection (#108): merge the
still-open branches locally (`git merge`, not rebase) and build on top,
rather than wait indefinitely. Branch
`glm/retry-loader-extraction-all-instruments`, `main` + `glm/retry-loader-extraction`
(#88) + `glm/papi-runner-prototype` (#82) + `glm/rmib-runner-prototype`
(#85) + `glm/ist-runner-prototype` (#92) + `glm/kraepelin-runner-items`
(#69) merged in that order, zero conflicts (one clean auto-merge in
`package.json`).

Two corrections to how this task was described when handed off, found by
reading the actual branches rather than trusting the summary of them:

- PR #88 ("extract the shared retry-loader core, scoped to the copy in
  main") does NOT contain Kraepelin's copy — it only extracted
  `retry-loader.ts` (the shared core) and refactored
  `resume-answers-loader.ts` into a thin wrapper around it, exactly as
  its own title says. Kraepelin's actual `items-loader.ts` copy is still
  on `glm/kraepelin-runner-items` (#69), merged separately here.
- IST does not have a fifth copy of this pattern at all —
  `ist-subtest-screen.tsx` takes `subtest` as a prop rather than fetching
  `/items` itself (by design, per that PR's own scope doc), so there is
  no IST items-loader to migrate. Its only loader-shaped file,
  `ist-asset-url-loader.ts`, is a different, already-reviewed pattern
  (bounded consecutive-failure count for image loads, not the
  fetch-once-on-mount-then-retry shape this debt is about) and is out of
  scope here.

So the actual four copies were: `resume-answers-loader.ts` (already done
in #88), `papi-items-loader.ts`, `rmib-items-loader.ts`, and
`kraepelin/items-loader.ts` — the last three migrated to thin wrappers
around `retry-loader.ts` here, mirroring `resume-answers-loader.ts`'s own
post-extraction shape exactly. Every one of the three loaders' existing
`*.test.ts` files was left completely untouched (confirmed via `git
status` before running them) and all 31 of their tests still pass
unmodified against the new implementations — the "zero behavioral
change" bar the Gate section below required. Full suite (session-runner +
papi + rmib + kraepelin + ist): 234/234, same count as the pre-extraction
baseline on this merged tree.

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
