# F2 — Audited admin account bootstrap/lifecycle commands (2026-09-22)

**Status: plan only, no code.** Go-live blocker: there is no production path
today to create ANY admin account — not the first `super_admin`, not a
psychologist, nothing. Lead's explicit ask, following from finding (2) in the
central-office admin role plan (`tasks/handoffs/f2/central-admin-role-plan.md`).

## The gap, confirmed precisely

No seeder, no artisan command, and no `DEPLOYMENT.md` bootstrap step creates
an `Admin` row anywhere in this codebase. Every `Admin::create()` call in the
repo is inside a test file. This affects every role, not just the future
`central_admin` — a fresh production database cannot get its first
`super_admin`, and no psychologist account can ever be created either.

## 1. Bootstrap: the first `super_admin`

A dedicated, one-time command (name illustrative:
`admins:bootstrap-super-admin`) that:

- Refuses to run if **any** row with `role = 'super_admin'` already exists —
  checked without a trashed/disabled distinction (i.e. `Admin::query()
  ->withTrashed()->where('role', AdminRole::SuperAdmin)->exists()`, once the
  disabled-state column from §4 exists, checked regardless of disabled
  state too). The intent per Lead is "only while there is no super_admin,"
  not "only while there is no *active* super_admin" — a disabled former
  super_admin still means the system was bootstrapped once.
- Runs under `RlsContextRunner::runAsService()` — structurally the only
  option: `admins_write`'s RLS policy only permits `app_role() IN ('service',
  'super_admin')`, and there is by definition no authenticated `super_admin`
  principal yet for the very first row (`app/Security/RlsContextRunner.php`
  precedent for this exact `runAsService()`-from-a-command idiom already
  exists in `PurgeExpiredAuditLogsCommand.php:42-44`).
- After bootstrap, creating further accounts (of any role, including
  additional `super_admin`s) goes through the **same general creation
  command** described in §2 — not a repeat of the bootstrap command, which
  becomes permanently inert once one `super_admin` exists. The difference
  Lead asked for ("akun baru butuh jalur yang sama tapi tercatat siapa
  operatornya") is exactly what §2/§5 already require unconditionally: every
  creation, bootstrap or not, records an operator identity in the audit row.
  Bootstrap does not get a lighter audit requirement — it is the *first*
  audited creation, not an unaudited exception.

## 2. General creation path — every existing role, and `central_admin` once it exists

One command (name illustrative: `admins:create`), parameterized by role, not
one command per role — so it does not need to change shape when
`central_admin` is added later (see "Dependency on the central-admin role
plan" below).

Required inputs, validated per role:
- `name`, `email` (unique — already enforced at the DB level,
  `admins.email` has a `unique()` constraint).
- `role` — validated against `AdminRole::cases()` (the enum is the single
  source of truth; the command must not hardcode its own role list
  separately, or the two can drift). The Postgres `admins_role_check` CHECK
  constraint is the actual hard backstop, but the command should reject
  invalid input before ever reaching the database, with a clear error, not
  rely on the CHECK to reject it as an unhandled `QueryException`.
- `branch_id` — **required** for `branch_admin`/`staff` (validated against
  an existing `branches` row; command fails clearly if omitted or invalid,
  mirroring `Admin::requiredBranchId()`'s existing runtime check, but caught
  before the write, not after). **Must be absent/null** for
  `super_admin`/`psychologist`/(later)`central_admin` — these are
  structurally not branch-bound, and the command should reject a `branch_id`
  supplied for one of them rather than silently ignoring it.
- `silp_number`/`str_number` — **required for `psychologist`**, not merely
  optional-but-recommended. Confirmed these are genuinely printed on the
  signed HPP document (`resources/views/reports/partials/psychologist.blade.php`,
  labeled "No. SILP"/"No. STR"), and `SignedReportDataset` already fails
  closed (`SignedHppDataset::blocked(...)`) if either is blank for the
  signing psychologist at report-issue time. Requiring them at account
  creation, rather than allowing a psychologist account that is silently
  incapable of ever producing a valid signed report, closes that failure
  mode earlier and more clearly. Not applicable to any other role — the
  command should reject them being supplied for a non-psychologist role.
- `can_verify_payments` — existing boolean column, presumably only
  meaningful for `branch_admin`/`staff` per its existing use in
  `Admin::canPerform()`'s `VerifyPayments` arm; not part of this plan's core
  scope but the command's input validation should account for it existing.
- `--operator=` — **required on every invocation**, identifying who is
  running the command (see §5 — this is not a secret, just an identifier
  recorded in the audit trail; exact format, e.g. a name or email string,
  left to implementation, but it must never be optional).

### Dependency on the central-admin role plan

`central_admin` cannot be created by this command until: (a) `AdminRole`
gains that case, and (b) a migration widens `admins_role_check`'s CHECK
constraint (currently `IN ('super_admin', 'branch_admin', 'staff',
'psychologist')`) to include it. Since this command reads valid roles from
`AdminRole::cases()` rather than hardcoding a role list, once that role
lands (per `tasks/handoffs/f2/central-admin-role-plan.md`), this command
supports creating it with zero changes to this command itself.

## 3. Passwords — never on the CLI argument list, two viable modes

Both of Lead's suggested mechanisms are real options; this plan describes
both rather than picking one exclusively, since they suit different
situations:

**Mode 1 — interactive hidden prompt** (`Command::secret($question)`, no
existing precedent in this repo but standard Artisan API). The operator
runs the command in a real terminal and types the password directly; it
never appears in shell history, process list, or command arguments. Best
suited to the bootstrap command specifically — a rare, high-trust,
one-time operation already requiring direct server access, where there is
no admin yet to receive an emailed link.

**Mode 2 — one-time set-password link/token, expiring, single-use.** The
command creates the `Admin` row with no usable password (or a random,
never-revealed placeholder hash) and issues a token the operator relays to
the new admin out-of-band (email, or manually copied — implementation
detail). This decouples "who runs the creation command" from "who knows the
password," which is generally preferable for ongoing account creation
(`admins:create`, not just bootstrap). This reuses the *shape* already
established by `IssueAssessmentInvitation`/`ConsumeAssessmentInvitation`
(`app/Actions/Integrations/IssueAssessmentInvitation.php:35,85-95`,
`ConsumeAssessmentInvitation.php:19-26`) — not the code directly, but the
pattern: `Str::random(64)` opaque token, HMAC-hashed at rest
(`hash_hmac('sha256', $token, config('app.key'))`, never the raw token
stored), delivered via a URL fragment so it never reaches server logs,
single-use via a status/active-marker state machine, row-locked and atomic
on consumption, with an `expires_at` checked at use time. A new table
(shape only, e.g. `admin_password_setup_tokens: {public_id, admin_id,
token_hash, status, active_marker, expires_at, created_at, updated_at}`)
would carry this — not designed further here, described only as "follows
the assessment-invitation shape."

**Password strength — must not rely on `Password::defaults()`.**
Confirmed: `Password::defaults()` (`app/Providers/AppServiceProvider.php:99-107`)
resolves to **`null`** — no rules at all — outside production
(`app()->isProduction()` false). An admin-creation command run in
staging/local today would enforce nothing if it trusted that default. The
command must **hardcode** the same rule set unconditionally, in every
environment: `Password::min(12)->mixedCase()->letters()->numbers()->symbols()->uncompromised()`
— an admin credential is never a throwaway dev account, regardless of which
environment happens to be running the command.

**Never printed.** The password (Mode 1) or the raw token (Mode 2) must
never appear in command output, `Log::` calls, or the `audit_logs` `context`
JSON blob — §5 makes this explicit as a hard requirement, not an
oversight-prone convention.

## 4. Disable / re-enable / reset password — no row ever deleted

**Confirmed gap**: no `disabled_at`/`is_active`/`status` column exists on
`admins` today. The only state-toggle mechanism present is `SoftDeletes`'
`deleted_at`, which is semantically a deletion marker (and Eloquent's
default global scope excludes soft-deleted rows from ordinary queries) —
not a distinct "disabled but still fully present" flag. A new nullable
`disabled_at` timestamp column is needed.

**Why never delete, concretely, not just as a stated rule**: `report_signing_snapshots.signed_by_admin_id`
carries a real Postgres foreign key
(`report_signing_snapshots_admin_fk`, `database/migrations/2026_09_17_000200_create_report_signing_snapshots.php:57-60`)
with `restrictOnDelete()` — the database itself already refuses to hard-delete
an admin who has signed anything. But that FK only protects admins who have
*already* signed something; a newly-created psychologist who hasn't signed
yet is still technically deletable at the DB level. The actual guarantee
this plan relies on is a **design choice** (no delete command exists at
all, only disable), not solely the FK — the FK is a backstop for one case,
not the general mechanism.

Three commands (names illustrative): `admins:disable {id}`,
`admins:enable {id}`, `admins:reset-password {id}` (offering the same two
password-delivery modes as §3). All three:
- Set/clear `disabled_at` (disable/enable) or issue a new credential
  (reset), never touch `deleted_at`.
- Require `--operator=` and write an audit row (§5), same as creation.
- `admins:reset-password` should also invalidate any outstanding, unused
  Mode-2 set-password token for that admin (reissuing revokes the prior
  active one, mirroring `IssueAssessmentInvitation.php:49-52`'s existing
  reissue-revokes-prior behavior).

**Login must respect `disabled_at`.** Not designed in full here, but the
Filament `admin` guard's authentication (or `Admin::canAccessPanel()`) needs
a check that a disabled admin cannot authenticate — this is the one piece of
non-command application code this plan touches, flagged for implementation,
not designed in detail.

## 5. Audit — every action, no secrets

Every command in this plan (bootstrap, create, disable, enable,
reset-password) writes exactly one `audit_logs` row per invocation, in the
same transaction as the state change, following the established shape from
`SetPaymentMethodActivation.php:41-56` (`branch_id, actor_type, actor_id,
action, subject_type, subject_id, context, occurred_at, expires_at` via
`RetentionPolicy::expiresAt(RetentionDataClass::Audit, $occurredAt)`).

**New `actor_type` value proposed: `'operator'`.** Existing values in this
codebase (`'admin'`, `'service'`, `'system'`, `'integration_client'`,
`'checkout_handoff'`, `'checkout_session'`, `'participant'`) all represent
either an authenticated principal or an internal system process — none fit
"a human running a CLI command with server access, before any admin session
exists." `actor_id` for this type is the `--operator` string from §2/§3
(not an `admins.id`, since for bootstrap specifically no admin record can
be the actor). `subject_type` = `Admin::class`, `subject_id` = the affected
admin's id, `action` a dotted string per command (e.g.
`admin.bootstrapped`, `admin.created`, `admin.disabled`, `admin.enabled`,
`admin.password_reset`).

**Hard requirement, not a convention**: the `context` JSON blob must never
contain a password, a raw Mode-2 token, or a password hash — only
non-secret facts (role, branch_id, which fields were set/changed). This
needs to be enforced by construction (e.g. the context-building code never
has the plaintext/token in scope at the point it assembles `context`), not
left to reviewer discipline.

## 6. RLS — already sufficient, one narrowing worth flagging

Confirmed: `admins`'s Postgres GRANT to `psikotes_runtime` has never been
narrowed by a Group-style remediation migration (unlike `instrument_versions`,
`report_documents`, `report_signing_snapshots`, and others) — it still runs
on the original blanket `GRANT SELECT, INSERT, UPDATE, DELETE ... TO
psikotes_runtime` (`database/schema/postgres_roles.sql:19`), and
`admins_write`'s policy (`FOR ALL`, `USING`/`WITH CHECK` both `app_role() IN
('service', 'super_admin')`) covers INSERT/UPDATE/DELETE together. **An
artisan command running under `RlsContextRunner::runAsService()` can already
INSERT/UPDATE an `admins` row today with zero grant changes** — there is no
gap to close for this feature to work.

**Worth flagging, not designed here**: DELETE is technically still permitted
by both the grant and the RLS policy today, even though this plan's design
never issues one. Narrowing the grant to `SELECT, INSERT, UPDATE` only
(REVOKE DELETE, matching the established Group A/B/C pattern from the RLS
remediation effort) would make "never delete an admin" a database-enforced
guarantee instead of solely an application-level discipline — a reasonable
follow-up, not required for this plan's commands to work, and not designed
further here.

## 7. MFA — options noted, not designed (owner decision)

Confirmed: the `admin` Filament panel has **zero** auth hardening beyond
password login today. No MFA/2FA of any kind is active. Two structurally
different reasons this matters specifically for `super_admin` and
`psychologist`: `super_admin` can do essentially anything in the system, and
`psychologist` accounts can sign and (once `central_admin` lands) admins
adjacent to publishing can distribute legally-relevant HPP reports bearing a
real SILP/STR number.

Two options exist in this codebase's dependency tree, neither wired up:
- **Filament 5's built-in `MultiFactorAuthentication` feature** (app-authenticator
  or email-code providers) — `composer.json` already pins `filament/filament: ~5.0`,
  which ships this, but `AdminPanelProvider.php` never calls
  `->multiFactorAuthentication(...)`. Lowest-effort option if the owner wants
  MFA scoped to the Filament panel specifically.
- **Laravel Fortify's existing 2FA** (`config/fortify.php:175-179`,
  `Features::twoFactorAuthentication(...)`) — already built and working, but
  hard-pinned to the `web` guard and `App\Models\User`
  (`config/fortify.php:18`). `Admin` does not use the
  `TwoFactorAuthenticatable` trait and is structurally unreachable by it
  today. Reusing this for the `admin` guard would mean either adding a
  second Fortify-style flow wired to `Admin`, or a more invasive
  guard-unification change — meaningfully more work than the Filament-native
  option.

This is the owner's decision (which roles need it, whether it's mandatory
or optional, which of the two mechanisms). Not designed further here.

## 8. Tests (designed, not written)

- Bootstrap runs exactly once: a second invocation (with a `super_admin`
  already present, active or disabled) refuses and makes no change —
  including specifically the disabled-but-still-exists case from §1.
- The password (Mode 1) and the raw set-password token (Mode 2) never
  appear in command output, captured and asserted absent, not just "the
  command exits 0."
- `role`/`branch_id` combinations are validated: `branch_admin`/`staff`
  without a valid `branch_id` rejected; `super_admin`/`psychologist`/(later)
  `central_admin` with a `branch_id` supplied rejected; an invalid `role`
  string rejected before ever reaching the database (not surfaced as a raw
  `QueryException` from the CHECK constraint).
- `psychologist` creation without `silp_number`/`str_number` rejected;
  supplying either for a non-psychologist role rejected.
- Every command invocation (bootstrap, create, disable, enable,
  reset-password) produces exactly one `audit_logs` row with the correct
  `actor_type='operator'`, `actor_id` from `--operator`, and a `context`
  blob asserted to contain no password/token/hash.
- A disabled admin cannot authenticate through the `admin` Filament guard
  (once that login-side check from §4 is implemented) — a genuine login
  attempt (not just a direct `canPerform()`/model check) fails.
- RLS: the bootstrap command's very first `INSERT`, run before any
  `super_admin` exists, succeeds under a `service` context and would fail
  under any other/no context — proving the "structurally must run as
  service" claim in §1, not just asserting it.

## Nothing changed yet

No command exists today. This document is design only — Lead review comes
before any of it is implemented.
