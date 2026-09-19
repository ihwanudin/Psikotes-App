# F7 proctoring persistence proposal

Date: 2026-09-20

Branch: `codex/f7-proctoring-proposal`

Baseline: `origin/main` at `650eea5e393ea9258b6d72b9e53489a519cf0b36`

Status: **PROPOSAL ONLY — no migration, code, route, controller, test, or
lockfile change is authorized in this increment.**

## Scope boundary

This proposal covers the remaining F7 proctoring slice that `AGENTS.md`
explicitly marks as authorized but not yet started: proctoring persistence
(`proctor_photos`/`proctor_logs`) plus a timeline UI, tied to the existing pure
`ProctoringValidityPolicy` domain, with migration schema to be proposed before
creation (`AGENTS.md:139`).

The wording and product behavior must stay within the documented proctoring
boundary: web proctoring is **detection and evidence**, not a lockdown system.
`CLAUDE.md:27` forbids overclaiming web proctoring capability. `SPEC.md:197-200`
says the browser can only deter, detect, and produce evidence reviewed by a
psychologist.

## Existing authority and constraints

- Current acceptance status: T-25 camera denied, T-26 camera interrupted, and
  T-27 visibility duration are domain-pass but persistence/UI/browser paths are
  still partial/open (`tasks/f2-f9-acceptance.md:41-43`). F7 remains partial
  specifically because proctoring persistence and timeline UI have not started
  (`tasks/f2-f9-acceptance.md:56`).
- SPEC already names `proctor_photos` and `proctor_logs` as infra tables
  (`SPEC.md:257`).
- Camera policy is periodic capture every 12-20 seconds plus start/submit;
  stream interruptions and permission denial are evidence markers, not automatic
  invalidation (`SPEC.md:202-206`).
- Visibility/focus signals must be recorded with timestamps and duration
  (`SPEC.md:210-212`).
- Threat mapping is explicit: visibility/fullscreen and camera-off are
  detected, face mismatch is a manual-review marker, and HP second-device use is
  not detected (`SPEC.md:220-235`).
- Participants must be told clearly before starting that periodic photos and
  screen-departure logs are stored temporarily (`SPEC.md:237-238`).
- Current security model requires tenant tables to use PostgreSQL RLS with
  `ENABLE ROW LEVEL SECURITY` and `FORCE ROW LEVEL SECURITY`; runtime role must
  be `NOSUPERUSER` and `NOBYPASSRLS` (`SECURITY.md:20-31`).
- Existing F7 RLS pattern for branch-scoped data grants service all access and
  tenant read to `super_admin` plus branch admin/staff scoped by `branch_id`
  (`database/migrations/2026_09_17_000100_create_branch_fee_ledger.php:197-210`;
  same pattern for gaps at
  `database/migrations/2026_09_17_000200_create_commission_ledger_gaps.php:56-66`).
- Private storage pattern: identity/selfie/payment evidence uses private
  storage, random keys without participant names/original filenames, MIME/size
  validation, and temporary 15-minute URLs after a policy check
  (`SECURITY.md:45-48`).
- Face mismatch is only a marker for review; provider choice is not final
  (`SECURITY.md:54-55`).
- SECURITY currently says proctoring retention is 90 days (`SECURITY.md:112-116`)
  while the draft privacy policy says proctoring photos 6 months and logs 2
  years (`PRIVACY_POLICY.md:5-9`). This conflict must be resolved before any
  production purge/scheduler behavior is implemented.

## Proposed schema

### `proctor_photos`

Purpose: append-only photo evidence metadata for periodic webcam captures and
start/submit captures. The binary image is stored in private object storage; the
database stores only metadata and a non-PII object key.

Proposed columns:

| Column | Type | Null | Notes |
|---|---:|---:|---|
| `id` | bigserial | no | Primary key. |
| `public_id` | ulid/string | no | Stable opaque identifier for UI/signed URL issuance; unique. |
| `branch_id` | bigint FK `branches.id` | no | Denormalized tenant scope for RLS and branch UI. |
| `participant_id` | bigint FK `participants.id` | no | Owner participant. |
| `assessment_case_id` | bigint FK `assessment_cases.id` | nullable until exact case contract is chosen | Prefer non-null if proctoring is case-level. |
| `test_session_id` | bigint FK `test_sessions.id` | nullable | Non-null when capture belongs to a generic session. |
| `assessment_attempt_id` | ulid/string | nullable | Needed for integrated attempts if no numeric FK exists. |
| `instrument` | string(32) | no | Maps to `ProctoringInstrument`. |
| `capture_kind` | string(32) | no | `session_start`, `periodic`, `session_submit`, `manual_review_upload` if later approved. |
| `captured_at` | timestamptz | no | Client observed capture time after server validation. |
| `received_at` | timestamptz | no | Server receive time, default server clock. |
| `sequence` | unsigned integer | no | Per session/case monotonic sequence. |
| `disk` | string(32) | no | Private disk name. |
| `object_key` | string(512) | no | Random, non-PII key. Unique. |
| `mime_type` | string(64) | no | Allow JPEG/WebP unless product chooses otherwise. |
| `size_bytes` | unsigned bigint | no | Enforce maximum in Form Request/action. |
| `width` | unsigned integer | nullable | Captured metadata. |
| `height` | unsigned integer | nullable | Captured metadata. |
| `checksum_sha256` | char(64) | no | Byte checksum of stored object. |
| `face_match_status` | string(32) | nullable | `not_run`, `match`, `mismatch`, `inconclusive`; marker only. |
| `face_match_score` | decimal/integer | nullable | Optional provider score; no decision directly from this field. |
| `provider_reference` | string(160) | nullable | Opaque external provider ID, if any. |
| `retention_expires_at` | timestamptz | nullable until legal retention is resolved | Candidate value depends on conflict resolution. |
| `created_at`, `updated_at` | timestamptz | no | Normal timestamps. |

Indexes/constraints:

- Unique `public_id`.
- Unique `object_key`.
- Unique idempotency key: suggested
  `(participant_id, instrument, test_session_id, sequence, capture_kind)` when
  `test_session_id` is present; otherwise use an explicit `client_event_id`
  column and unique `(participant_id, instrument, client_event_id)`.
- Index `(branch_id, captured_at)`.
- Index `(assessment_case_id, captured_at)`.
- Index `(test_session_id, sequence)`.
- Check `capture_kind` and `face_match_status` against allowlisted values, or
  use backed enums if the project adopts them.

Append-only decision:

- V1 should be **append-only**. Do not update or delete evidence rows except
  retention purge after formal retention authority exists. If a capture is
  rejected, record a `proctor_logs` event rather than mutating or deleting the
  photo row.

### `proctor_logs`

Purpose: append-only structured timeline events for client observations and
system observations that feed `ProctoringValidityPolicy`.

Proposed columns:

| Column | Type | Null | Notes |
|---|---:|---:|---|
| `id` | bigserial | no | Primary key. |
| `public_id` | ulid/string | no | Stable opaque event identifier; unique. |
| `branch_id` | bigint FK `branches.id` | no | Tenant scope. |
| `participant_id` | bigint FK `participants.id` | no | Owner participant. |
| `assessment_case_id` | bigint FK `assessment_cases.id` | nullable until exact case contract is chosen | Prefer non-null for review UI. |
| `test_session_id` | bigint FK `test_sessions.id` | nullable | Session-level event source. |
| `assessment_attempt_id` | ulid/string | nullable | Integrated attempt bridge if needed. |
| `instrument` | string(32) | no | Maps to `ProctoringInstrument`. |
| `event_kind` | string(64) | no | Maps to `ProctoringEventKind`. |
| `evidence_source` | string(32) | no | Client observation or system observation. |
| `evidence_id` | string(100) | no | Same ID contract as `ProctoringEvent::$evidenceId`. |
| `occurred_at` | timestamptz | no | Client or system event time. |
| `received_at` | timestamptz | no | Server receive time. |
| `duration_ms` | unsigned integer | nullable | Required for visibility/focus interruptions. |
| `photo_id` | bigint FK `proctor_photos.id` | nullable | Links face/photo events to the capture row. |
| `client_event_id` | string(100) | nullable | Idempotency token from client. |
| `metadata` | jsonb | no default `{}` | Must exclude PII/raw answers/object keys. |
| `review_status` | string(32) | no default `pending` | `pending`, `dismissed`, `confirmed`, `system_only`. |
| `reviewed_by_admin_id` | bigint FK `admins.id` | nullable | Psychologist/super admin review identity. |
| `reviewed_at` | timestamptz | nullable | Review timestamp. |
| `review_note` | text | nullable | Procedure/adjudication note, no raw PII. |
| `created_at`, `updated_at` | timestamptz | no | Normal timestamps. |

Indexes/constraints:

- Unique `public_id`.
- Unique `(participant_id, instrument, evidence_id)`, matching the pure domain
  rule that duplicate evidence IDs must have identical payloads.
- Unique `(participant_id, instrument, client_event_id)` where
  `client_event_id is not null`.
- Index `(branch_id, occurred_at)`.
- Index `(assessment_case_id, occurred_at)`.
- Index `(test_session_id, occurred_at)`.
- Index `(event_kind, occurred_at)`.
- Check `event_kind` against current `ProctoringEventKind` values:
  camera permission denied, camera unavailable, camera interrupted, screen
  departure, face mismatch, second face detected, audio assistance detected,
  network interrupted, unreasonable timing, identity failure, subtest
  incomplete, invalid response pattern confirmed
  (`app/Domain/Proctoring/ProctoringEventKind.php:7-20`).

Append-only decision:

- Raw event rows should be append-only. Review/adjudication can either update
  review columns on the event or, more strictly, use a third append-only table
  such as `proctor_log_reviews`. For V1, updating review columns is lower scope
  but weaker audit. If strict append-only is required, add
  `proctor_adjudications` instead of mutable review columns.

## RLS and role access proposal

All new tenant-scoped tables should follow the established PostgreSQL pattern:
`ENABLE ROW LEVEL SECURITY`, `FORCE ROW LEVEL SECURITY`, runtime grants to
`psikotes_runtime`, service policy for writes, and tenant read policy for roles
that are explicitly authorized.

Recommended policies:

| Role | Proposed access | Reason |
|---|---|---|
| `service` | `SELECT/INSERT/UPDATE` only through service-boundary actions | Ingest endpoints and review actions should run with trusted server context; browser-provided branch/participant IDs must not become RLS context, consistent with `SECURITY.md:27-38`. |
| `super_admin` | Read all proctoring photos/logs; review/adjudicate if product permits | Existing F7 branch data pattern grants super admin all tenant reads (`2026_09_17_000100_create_branch_fee_ledger.php:207-210`). |
| `psychologist` | Read all cases assigned/reviewable by psychologist; review/adjudicate validity markers | SPEC says decisions V1/V2/V3 are psychologist-reviewed, and internal/proctoring evidence belongs to review workflow (`SPEC.md:197-200`, `CLAUDE.md:27`). Exact assignment predicate needs Lead/DeepSeek alignment because F5 RLS widening is still in progress. |
| `branch_admin` / `staff` | Read only rows with `branch_id = app_private.app_branch_id()`; no direct insert/update | Branch staff can see operational status/timeline for their branch, but raw internal/DASS data remains restricted by SPEC §12. Writes should be via service action, not policy INSERT. |
| participant | Default: no direct SELECT from admin tables. Only expose a participant-facing explanation/consent/history endpoint if SPEC/user explicitly requires it | Privacy policy gives participants rights to know/access their data (`PRIVACY_POLICY.md:10`), but no product UI requirement currently requires raw photo/log timeline exposure. If added later, use a separate participant endpoint with redaction and signed URL policy, not broad RLS table access. |

Open RLS decision: the exact `psychologist` predicate should be decided with
the F5 signing/review RLS work. Avoid `runAsService` for read paths unless the
service action also enforces role and branch/case scope explicitly.

## Photo storage proposal

- Disk: private S3-compatible disk, never public.
- Object key: random ULID/UUID path without participant name, phone, test
  number, original filename, branch name, or case label. Example:
  `proctoring/photos/{yyyy}/{mm}/{ulid}.jpg`.
- Signed URL: short-lived URL generated only after an authorization check.
  Existing private evidence pattern uses temporary 15-minute URLs after policy
  check (`SECURITY.md:45-48`). Reuse 15 minutes unless product/security chooses
  a shorter reviewer-only duration.
- Size/type: JPEG/WebP around 480-640px as SPEC suggests for periodic capture
  (`SPEC.md:202-203`). Enforce MIME sniffing and byte-size ceiling server-side.
- Checksum: SHA-256 over stored bytes, recorded in `checksum_sha256`.
- Retention: unresolved conflict. SPEC says proctoring video/photo 90 days with
  only event summaries retained (`SPEC.md:237-238`, `SPEC.md:269`), SECURITY
  says proctoring 90 days (`SECURITY.md:112-116`), while PRIVACY_POLICY draft
  says photos 6 months and logs 2 years (`PRIVACY_POLICY.md:5-9`). Do not
  schedule purge until this is resolved by product/legal/psychologist authority.

## Event ingest proposal

Suggested endpoint shape:

- `POST /participant/sessions/{session}/proctoring/events`
- `POST /participant/sessions/{session}/proctoring/photos`

The final route names can differ, but each route should:

1. Authenticate participant session.
2. Verify entitlement/session ownership server-side.
3. Apply participant/branch RLS context through trusted middleware or a closed
   service action.
4. Accept only allowlisted event kinds and metadata fields.
5. Require `client_event_id` for idempotency.
6. Rate-limit per participant/session/IP.
7. Never log raw photo bytes, object keys, URLs, participant phone, test number,
   or raw answers.

Form Request fields for event ingest:

- `client_event_id`: required opaque string, 16-100 chars.
- `instrument`: required enum matching `ProctoringInstrument`.
- `event_kind`: required enum; client-observation events limited to the allowed
  kinds in `ProctoringEvent::allowedKinds()` for `ClientObservation`
  (`app/Domain/Proctoring/ProctoringEvent.php:34-47`).
- `occurred_at`: required timestamp, bounded near server time.
- `duration_ms`: required for visibility/focus/screen-departure intervals.
- `metadata`: optional allowlisted JSON; no PII, raw answers, object keys, or
  signed URLs.

Event mapping to acceptance rows:

| Acceptance | Events | Persistence/UI implication |
|---|---|---|
| T-25 camera denied | `CAMERA_PERMISSION_DENIED`, `CAMERA_UNAVAILABLE` | Create `proctor_logs` marker; in mandatory-camera mode, session start can be blocked by start flow, but existing running session records V2 marker. |
| T-26 stream interrupted/mobile app switch | `CAMERA_INTERRUPTED`, optionally paired with recovery metadata | Record each interruption and recovery attempt; aggregate in timeline; V2 marker per policy. |
| T-27 visibility duration | `SCREEN_DEPARTURE` with `duration_ms` and timestamps | Persist duration; timeline shows cumulative and per-event duration. |
| T-28 face mismatch | `FACE_MISMATCH` linked to `proctor_photos` | Marker only; human review required, no automatic stop/publication decision. |

This proposal deliberately does not claim prevention. It records detection
signals, recovery attempts, and review markers.

## Filament timeline UI proposal

Suggested UI: `ProctoringTimeline` page or relation manager under the
assessment-case/review surface.

Route contract:

- Route must carry the case/session parameter, e.g.
  `/admin/assessment-cases/{case}/proctoring`.
- Do not repeat the ReportSigning bug pattern where a page had
  `mount(string $case)` but route lacked `{case}`.
- Feature tests must include real HTTP requests such as
  `actingAs($admin)->get(...)` for psychologist, super admin, branch admin,
  staff, and unauthorized roles; do not rely only on `Livewire::test`.

UI content:

- Case/session header: participant display name/test number only as permitted
  by existing admin UI policy.
- Timeline grouped by time and instrument.
- Event severity chips: camera, visibility, network, face marker, human review.
- Photo thumbnails only via signed URL after authorization.
- Cumulative visibility duration and camera-off count.
- Review/adjudication controls only for roles explicitly authorized
  (`psychologist` and/or `super_admin`, pending F5 RLS alignment).
- Branch admin/staff read-only view scoped to their branch.

## Integration with `ProctoringValidityPolicy`

The existing pure domain accepts typed events and optional adjudicated findings
and returns V1/V2/V3 plus marker and pending-adjudication state
(`app/Domain/Proctoring/ProctoringValidityPolicy.php:15-108`).

Persistence should therefore:

1. Load `proctor_logs` rows for a case/session.
2. Convert each row into `ProctoringEvent` using persisted `event_kind`,
   `instrument`, `evidence_source`, and `evidence_id`.
3. Convert reviewed rows or a future `proctor_adjudications` table into
   `ProctoringAdjudicatedFinding`.
4. Pass those arrays to `ProctoringValidityPolicy::decide()`.
5. Surface the decision as a review marker. Do not publish, block, or override
   psychologist decisions automatically.

Policy implications from current domain:

- Camera denied/unavailable/interrupted, screen departure, network interruption,
  and unreasonable timing produce V2 markers
  (`app/Domain/Proctoring/ProctoringValidityPolicy.php:111-120`).
- Identity failure, subtest incomplete, and confirmed invalid response patterns
  produce V3 markers (`app/Domain/Proctoring/ProctoringValidityPolicy.php:123-130`).
- Face mismatch, second face, and audio assistance require human review
  (`app/Domain/Proctoring/ProctoringValidityPolicy.php:132-139`).

## Decisions needed before migration/code

1. Retention authority: choose between SPEC/SECURITY 90 days and
   PRIVACY_POLICY 6-month photos / 2-year logs, or document a split policy.
2. Exact parent identity: should proctoring rows be primarily case-level,
   session-level, integrated-attempt-level, or a required combination?
3. Psychologist RLS predicate: all cases, assigned cases, or same scope as F5
   signing snapshots after the pending RLS widening work?
4. Participant access: no direct access, redacted audit summary, or full
   subject-access export only.
5. Review storage: mutable review columns on `proctor_logs` versus strict
   append-only `proctor_adjudications`.
6. Photo cadence configurability: global 12-20 seconds versus per-branch
   configuration; if per-branch, where is that config stored?
7. Face-match provider and score semantics. Current security note says provider
   is not chosen and mismatch is only a marker (`SECURITY.md:54-55`).
8. Mandatory-camera branch setting: where it is configured, and whether camera
   denial blocks start or only marks V2 for each branch.
9. Signed URL lifetime for photos: reuse 15 minutes from existing evidence
   pattern or shorten for proctoring.
10. Whether `proctor_photos` should store face-match derived fields in V1 or
    keep all provider output in `proctor_logs.metadata` until provider choice.

## Proposed next increment after review

After Lead/user review accepts this proposal:

1. Create migrations for `proctor_photos` and `proctor_logs` with PostgreSQL RLS
   and SQLite-compatible tests.
2. Add ingestion service actions and Form Requests with idempotency and rate
   limit coverage.
3. Add Filament timeline read-only UI with HTTP authorization tests.
4. Add review/adjudication write path only after role scope is approved.
5. Add browser E2E separately; this proposal does not start E2E.

## Worker handoff record

Task/thread ID: Codex `/root`

Lane and phase: F7 proctoring persistence proposal, proposal-only pre-migration
phase.

Branch/worktree: `codex/f7-proctoring-proposal`;
`C:\Users\ThinkPad\.codex\worktrees\f7-proctoring-proposal`.

Baseline commit: `650eea5e393ea9258b6d72b9e53489a519cf0b36`.

Owned files/directories: `tasks/handoffs/f7/proctoring-persistence-proposal.md`
only.

Acceptance criteria: one proposal document covering schema, RLS, private photo
storage, ingest, Filament timeline, `ProctoringValidityPolicy` integration, and
open decisions; no migration/code/test/lockfile changes.

Verification commands:

- `git status --short --branch`
- `git diff --stat`
- `rg "lockdown guarantee" tasks/handoffs/f7/proctoring-persistence-proposal.md`

Result commit: pending until committed.

Tests and evidence: documentation-only; citations are embedded as file:line
references.

Known blockers: retention conflict between SPEC/SECURITY and PRIVACY_POLICY;
psychologist/super-admin RLS predicate pending F5 RLS alignment; face-match
provider not chosen; mandatory-camera config storage undecided.

Next dependency or increment: Lead/user review of schema and decisions before
any migration is created.

Review status: pending.
