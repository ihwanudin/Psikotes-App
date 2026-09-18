# Psychologist duration confirmation — PAPI & RMIB (2026-09-19)

For: `codex/instrument-authority-pack` lane, to formally incorporate into
`tasks/handoffs/authority-pack/papi.md` and `rmib.md` when that lane resumes.
Not written directly into those files — that folder is Codex's exclusive
lane per `AGENTS.md`, and this coordinator session should not edit it.

## What happened

The project owner asked Rizqi Ulin Nuha, S.Psi., Psikolog (the named
psychometric approver in both packs) via WhatsApp for the administration
duration of PAPI and RMIB. The psychologist **replied and confirmed**:

- **PAPI: 30 minutes**
- **RMIB: 30 minutes**

This is a real reply from the named approver, not a team estimate — treat it
as evidence, but see "Still open" below for what it does and doesn't resolve.

## What this answers

- `papi.md` evidence-matrix row "Duration" / unanswered question 4 ("What is
  the approved total duration in seconds?") — candidate answer: **1800
  seconds**. `papi.md` previously had no confirmed figure at all, only an
  unapproved "PAPI (20)" from an old diagram — this is the first real answer.
- `rmib.md` evidence-matrix row "Duration" / open question RMIB-A2 (same
  question) — candidate answer: **1800 seconds**. `rmib.md` previously had
  only an unapproved "RMIB (15)" from an old diagram, conflicting with this
  new figure.

## Still open — do not treat this as closing either pack

Duration was one BLOCKED row out of many in each pack (form/version,
provenance, checksum, subtest encoding, rights/license, named approval of
the *complete* manifest). This WhatsApp reply:

- Does not by itself become the "signed timer decision" both packs require —
  confirm with Codex/the coordinator whether a WhatsApp reply meets the
  evidentiary bar the packs were written to, or whether a written/signed
  follow-up is still needed for the formal record.
- Does not address pause/resume/timeout/late-answer behavior, which
  `rmib.md`'s "Required final evidence" column for Duration explicitly also
  asks for alongside the raw seconds figure.
- Does not resolve any of the other BLOCKED rows in either pack — both
  remain BLOCKED overall (readiness 0/4) regardless of this answer.

## Also worth relaying back

Two different old-diagram figures exist per pack: PAPI showed "(20)" (20
min) and RMIB showed "(15)" (15 min), both unapproved. The psychologist's
30-minute answer for *both* instruments doesn't match either old figure —
worth a one-line confirmation-of-confirmation ("yes, 30 for both, that's not
a typo") before this is written into the formal packs, given the mismatch.

## Coordinator's separate note (informal, already recorded, now superseded in weight)

An earlier informal, pre-confirmation duration estimate (~30 minutes, from
the project owner's own experience with manual/paper administration, not
from the psychologist) was recorded during F5 work on
`deepseek/f3-f4-eligibility-narrative` (commits `e6907b2`, `4abb192`) but
deliberately not merged to `main` — same collision-avoidance reason as
above. That estimate happened to match this now-confirmed figure, but this
document's confirmed answer is what should actually be used; that earlier
commit's content is now superseded and doesn't need separate incorporation.
