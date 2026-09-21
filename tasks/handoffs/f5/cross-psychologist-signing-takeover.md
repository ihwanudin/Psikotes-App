# Cross-psychologist report takeover — found and fixed 2026-09-22

Found by an independent security check on PR #97 (`f5/report-signing-takeover`, `b6adb91`),
verified directly against the code before any fix was written. Unrelated to the concurrency
work in `tasks/handoffs/f5/report-signing-conflict-500.md` — this is an authorization gap, not
a race condition, and predates this PR (this PR just added the queue page that first exposed
it to a psychologist browsing other cases).

## The gap

`ReportSigningController::sign()` only checked `$admin->canPerform(AdminAbility::ReviewReports)`,
which is `true` for every psychologist account — not specifically the one who signed the
version being revised. `ReportSigningService::sign()`'s re-signing closure only checked
`$latest->state === 'SIGNED'` and that `revision_reason` was at least 20 characters; it never
compared `$latest->signed_by_admin_id` to the requesting psychologist. `ReportSigningQueue`
lists every case from every branch with no ownership filter (by design — it's meant to be a
shared work queue for *unsigned* cases), and the per-case `ReportSigning` Filament page set
`isReadOnly` from snapshot state alone, showing the "Ajukan Revisi" (request revision) form to
whichever psychologist opened the page.

Net effect: any psychologist with `ReviewReports` could open any already-signed case, type any
20+ character reason, and become the new `signed_by_admin_id` — overwriting the original
signer's SILP/STR attribution on a case they never reviewed, with no involvement from the
original signer or a super_admin. Violates CLAUDE.md G5 (psychologist review+signature is
required and authoritative) and carries real professional/legal exposure (a practice license
number printed against a case that psychologist never handled).

No existing test caught this because every resign test used the same psychologist account for
both the initial signing and the revision.

## The fix

1. `ReportSigningService::sign()` (`app/Services/Review/ReportSigningService.php`): inside the
   signing closure, when `$latest !== null && $latest->state === 'SIGNED'`, now checks
   `(int) $latest->signed_by_admin_id !== $signedByAdminId` *before* the revision_reason check
   and rejects with a new `SIGNED_BY_ANOTHER_PSYCHOLOGIST` (403) error code if it doesn't match.
   No case-reassignment path exists — that's a separate process decision for the project owner,
   not something this fix invents as a side door.
2. `ReportSigning` Filament page (`app/Filament/Pages/ReportSigning.php`): `mount()` now also
   sets a new locked property `isSignedByAnotherPsychologist` by comparing the latest signed
   snapshot's `signed_by_admin_id` to the current admin's id. `requestRevision()` checks it and
   refuses (with a notification, no state change) even if called directly — defense in depth,
   since a Livewire action is callable regardless of what the template renders.
3. Blade view (`resources/views/filament/pages/report-signing.blade.php`): the "Ajukan Revisi"
   form (textarea + submit button) only renders when `! $isSignedByAnotherPsychologist`;
   otherwise a short message explains why revision isn't offered here. `ReportSigningQueue`
   itself needed no change — it already links every case through one generic
   "Tinjau & Tanda Tangan" action with no separate revision control of its own, and continuing
   to list *unsigned* cases to every psychologist is correct (shared work queue), so the actual
   fix belongs entirely on the per-case page where the real action lives.

## Tests added

- `tests/Integration/Review/ReportSigningPersistenceTest.php`:
  `test_revision_by_a_different_psychologist_is_rejected` (two real psychologist accounts,
  through the real HTTP endpoint — psychologist B is rejected 403
  `SIGNED_BY_ANOTHER_PSYCHOLOGIST`, and the DB still shows exactly one row still attributed to
  psychologist A) and `test_revision_by_the_same_psychologist_still_succeeds` (explicit
  regression guard: the existing same-psychologist resign flow is unaffected).
- `tests/Feature/Filament/ReportSigningPageTest.php`:
  `test_revision_form_is_hidden_for_a_case_signed_by_another_psychologist` (the form doesn't
  render for psychologist B, and calling `requestRevision()` directly is a no-op) and
  `test_revision_form_is_shown_for_the_signing_psychologists_own_case` (regression guard on the
  UI side).

## Not done here (explicitly out of scope, per Lead)

Case reassignment (letting a different psychologist take over an already-signed case through
some deliberate handoff) is a process question for the project owner, not part of this fix.
