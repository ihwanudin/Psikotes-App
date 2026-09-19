# Psychologist duration confirmation — PAPI & RMIB (2026-09-19)

**Superseded once, corrected below — see "Revision history" at the end.**
This is the final figure set: **PAPI 30 minutes, RMIB 15 minutes** (not 30/30
as an earlier revision of this same file said).

For: `codex/instrument-authority-pack` lane, to formally incorporate into
`tasks/handoffs/authority-pack/papi.md` and `rmib.md` when that lane resumes.
Not written directly into those files — that folder is Codex's exclusive
lane per `AGENTS.md`, and this coordinator session should not edit it.

## What happened

The project owner asked Rizqi Ulin Nuha, S.Psi., Psikolog (the named
psychometric approver in both packs) via WhatsApp for the administration
duration of PAPI and RMIB. The psychologist **replied and confirmed**:

- **PAPI: 30 minutes**
- **RMIB: 15 minutes**

This is a real reply from the named approver, not a team estimate — treat it
as evidence, but see "Still open" below for what it does and doesn't resolve.

## What this answers

- `papi.md` evidence-matrix row "Duration" / unanswered question 4 ("What is
  the approved total duration in seconds?") — candidate answer: **1800
  seconds**. `papi.md` previously had no confirmed figure at all, only an
  unapproved "PAPI (20)" from an old diagram — this is the first real answer,
  and it does not match that old figure.
- `rmib.md` evidence-matrix row "Duration" / open question RMIB-A2 (same
  question) — candidate answer: **900 seconds**. `rmib.md` previously had
  only an unapproved "RMIB (15)" from an old diagram — this confirmed answer
  **matches** that old figure exactly, which is worth noting in the pack as
  corroboration (independent-seeming agreement between an old artifact and
  the named approver's direct answer), though it's still a fresh confirmation
  from the approver and should be recorded as such, not treated as "already
  approved because the old diagram agreed."

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

## Revision history

- **2026-09-19, first revision (superseded):** recorded PAPI 30 min / RMIB
  30 min, both from the same relayed WhatsApp exchange. This was corrected
  by the project owner shortly after to PAPI 30 min / RMIB **15** min — the
  30/30 figure was a relay error, not a second psychologist answer. If
  anyone finds the 30/30 figure referenced elsewhere (chat logs, an earlier
  read of this file, another session's memory), it is wrong — use 30/15.
- **2026-09-19, this revision (current):** PAPI 30 min, RMIB 15 min, per the
  project owner's explicit correction, stated to be the psychologist's
  direct confirmation.

## Coordinator's separate note (informal, already recorded, now superseded in weight)

An earlier informal, pre-confirmation duration estimate (~30 minutes for
*both* instruments, from the project owner's own experience with
manual/paper administration, not from the psychologist) was recorded during
F5 work on `deepseek/f3-f4-eligibility-narrative` (commits `e6907b2`,
`4abb192`) but deliberately not merged to `main` — same collision-avoidance
reason as above. That estimate matched neither the first nor the final
revision of the psychologist's actual RMIB answer (15 min); it happened to
match PAPI. This document's confirmed answer (PAPI 30 / RMIB 15) is what
should actually be used; that earlier commit's content is superseded and
doesn't need separate incorporation.
