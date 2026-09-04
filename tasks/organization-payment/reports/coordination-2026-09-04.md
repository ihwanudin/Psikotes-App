# Coordination checkpoint — 2026-09-04

## Integrated bounded correction

Worker final `be47d4d` reviewed; root independently reran focused P13/P14 58/1794
and Pint/Node syntax in the env-free worker. PHP 8.3.30 needed explicit mbstring
and SQLite extension flags; the initial missing-extension invocation did not
execute tests. Commits integrated as d88422f/d3af006/5e11bbb/c1963aa, and a Git
comparison confirms middleware/tests/harness equal the tested worker bytes.
Browser limits remain explicit in ADR-012; full P14 remains open. Next single
backend increment is P14c summary projection contract preflight, no implementation
or route activation until mapping existing DRAFT props to persisted authority is
reviewed. Frontend and portal receive no duplicate work.

## P14b2 RED review and bounded fix decision

Reviewed worker commits `04f92fe` (three browser harness files) and `a940b8d` (report/proposal), including actual adapter/middleware code and WHATWG Fetch Origin algorithm. `git diff a49354f a940b8d --check` passed. Browser was not independently rerun by root; worker evidence remains RED, not accepted/integrated. Snapshot `4155cd61-0c2b-4909-bd55-db926b1dc9fe:7` confirms completed/idle.

Technical decision: option B is authorized for a bounded local correction to preserve the existing native logout requirement and no-referrer privacy policy. This does not activate an endpoint. Scope only POST /checkout/logout on the exact HTTPS destination, literal single Origin null, one canonical raw URL-encoded CSRF form field, verified active session/delivery digests and explicit secret equality. Missing/empty/list/duplicate/foreign Origin and header-only or dual-channel CSRF must not enter the exception. Exchange and other mutations retain exact-Origin policy. Metadata fields, when present, must match same-origin/navigate/document individually; absence is allowed only with full session and CSRF proof. Null is not an authority or CORS allowlist value.

Next backend increment: implement and test this bounded exception, document the decision in its lane report (canonical ADR owned by root until final review), repeat browser including foreign/opaque/same-site sibling negative forms, and preserve existing guards. Address harness async request/response-listener completion before checking violations and close no-JS context in finally. Strengthen native successful logout evidence with real Laravel login preservation and exact one logout audit. Do not count every revoked-session audit as a logout audit. Keep acceptance RED until new evidence passes; do not integrate current RED commits alone.

Sources: https://fetch.spec.whatwg.org/#append-a-request-origin-header and https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html. The latter supports explicit CSRF tokens and risk-based fallback when Fetch Metadata is absent; this decision is logout-specific, not blanket null-Origin trust. Existing blocked recursive scratch cleanup is not retried through another mechanism.

## Retry after model-specific limit

The dispatched turn `01a06b66-5a2c-7013-8f16-bd4aeb60be4d` failed with the explicit GPT-5.3-Codex-Spark usage limit before producing a message or tool output (snapshot cursor `4155cd61-0c2b-4909-bd55-db926b1dc9fe:5`). On the user's next continuation request, the same unfinished P14b2 instruction was retried once on the existing backend task with `gpt-5.6-sol`, reasoning high. This is a retry, not a new increment or accepted result. No credit reset was consumed.

Root baseline: `cd173c8`; working tree clean before this note.

- Backend `01a05839-3b48-7801-8175-0392e8764c23`: latest completed turn `01a06627-d206-7112-b149-4595ba10aee9` changed the browser auth probe but did not verify P14b2. Earlier turn `01a06604-9f27-7e83-885f-7be35da3fe96` explicitly failed with usage limit. Worker HEAD remains `a49354f`; browser harness files are uncommitted alongside the established untracked baseline. No changes integrated in this check.
- Frontend `01a05839-3b39-7d83-b59f-9e7432d7883e`: completed, HEAD `3fe0780`; reports 44 browser checkpoints and 27 SSR tests. Waiting for P14/P15 contract; no duplicate continuation sent.
- Portal `01a05839-3b18-73e0-8fdc-8db3b02f835d`: completed, HEAD `90bea2a`; reports P12c verifier correction and 64 tests / 490 assertions. No duplicate continuation sent.

Backend continuation sent once in response to the user's request to resume limit-interrupted work: finish P14b2 browser acceptance from existing harness, prove actual Laravel login isolation and native HTTPS browser behavior, run relevant regression, report and commit only lane changes, clean up owned disposable processes, then stop for review. Production routes/gates, active data, outbound and deployment remain outside this increment.

Observed snapshot cursors before dispatch:
- Backend: `4155cd61-0c2b-4909-bd55-db926b1dc9fe:3`
- Frontend: `48839421-96c5-4ac3-972e-751f2702a64a:3`
- Portal: `2ea20b3e-8c96-4770-a173-8b8ae87a13fb:3`

P14 acceptance remains open. P15 consent/profile, P16 integrated page and P17/P18 end-to-end validation/runbook remain subsequent work. Worker-reported tests above are historical evidence, not fresh coordinator reruns. No overall project percentage inferred from task numbering.
