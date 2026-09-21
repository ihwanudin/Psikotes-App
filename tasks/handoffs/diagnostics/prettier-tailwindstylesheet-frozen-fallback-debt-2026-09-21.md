# Technical debt — `resources/css/app.prettier-sort.css` freezes a broken resolution on purpose

**Tanggal:** 2026-09-21. **Dicatat oleh:** kanal FE-Infra, sesuai arahan Lead
setelah insiden CI di PR [#53](https://github.com/ihwanudin/Psikotes-App/pull/53)
(`fe/fix-frontend-fixture-harness`, merged as `3df676f`).

## Ringkas

`.prettierrc`'s `tailwindStylesheet` points at `resources/css/app.prettier-sort.css`,
not the real `resources/css/app.css`. That file deliberately keeps importing
the **original, unresolvable** `virtual:oncam-design-tokens.css` module id —
see the comment block at the top of `resources/css/app.prettier-sort.css`
for the full mechanism. In short: `prettier-plugin-tailwindcss` reads this
stylesheet directly off disk, outside Vite entirely, and its fallback
behavior for a stylesheet whose `@import` chain has *always* failed to
resolve happens to be exactly the class-sort order the whole codebase is
already committed against. The moment that resolution *succeeds* — even
with `@theme` content byte-identical to what's intended — the computed sort
order shifts for ~35 files that have nothing to do with ONCAM tokens
(verified: `docker`/CI logs and local reproduction, see PR #53's commit
`5b081cc` message for the full isolation of variables).

## Kenapa ini utang, bukan solusi permanen

This intentionally preserves a broken resolution rather than fixing it,
because fixing it (making `tailwindStylesheet` resolve to the *real* theme,
including ONCAM's custom tokens) means `prettier --write` would reformat
class order across the whole codebase in one shot — a large, disruptive
diff that would conflict with every frontend PR open right now (GLM's lobby
fix, the checkout investigation, and whatever session builds the IST/PAPI/
RMIB/Kraepelin test-taking pages next). Freezing the fallback was the
correct call *for right now* per Lead's explicit sign-off on PR #53, not a
permanent design decision.

## Rekomendasi

Suatu saat, ketika tidak banyak PR frontend terbuka secara bersamaan:

1. Point `tailwindStylesheet` at the real, resolving theme (either
   `resources/css/app.css` directly once its generated-file dependency is
   made safe for this use case, or an equivalent that includes ONCAM's
   `@theme inline static { ... }` aliases).
2. Run `prettier --write resources/` in one dedicated, mechanical PR — no
   other changes bundled in, so reviewers can verify it's pure
   reformatting.
3. Delete `resources/css/app.prettier-sort.css` and this handoff file.
4. Confirm `npm run format:check` still passes deterministically
   afterward, the same way PR #53 verified it (fresh `npm ci` clone, no
   build ever run, and with a build having run — both must give 0
   findings).

Until then, any new file that uses ONCAM-namespaced Tailwind utility
classes (`bg-oncam-*`, `text-oncam-*`, etc., see
`tools/design-tokens/oncam-runtime-bridge.mjs`'s `tailwindNamespace()`)
will get sorted using prettier's fallback default, not the project's real
theme — cosmetically inconsistent with hand-sorted code, but not a
functional bug (the runtime CSS itself is unaffected; only prettier's
*opinion* about class order is frozen).
