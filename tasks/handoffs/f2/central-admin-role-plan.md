# F2 — Central-office admin role ("central_admin") plan (2026-09-22)

**Status: plan only, no code. Ability matrix now final** — the owner's
answer came back (item 20, PR #81 `77ef777`): `VerifyPayments`,
`EditParticipants`, `ManageTestPackages`, and read access to the active
payment-methods list are all granted. `ManageAdmins`, `ReviewReports`,
`ViewDass` stay denied. See the updated matrix below; the three additions
from the first review round are unchanged.

## Source of the requirement

`tasks/handoffs/decisions/owner-decisions-2026-09-21.md`, item 17 (owner's
clarification of the word "admin" elsewhere in the decisions doc): a new role,
**"admin aplikasi pusat" (central-office admin), one level below
`super_admin`, not branch-bound**.

> "Menerbitkan (`GenerateReports`): psikolog, super_admin, dan admin aplikasi
> pusat. Hanya untuk laporan yang sudah ditandatangani psikolog."
> "Menandatangani (`ReviewReports`): tetap hanya psikolog."
> "`branch_admin` dan `staff` cabang: tetap tidak boleh menerbitkan."
> "Membuat peran baru (ability, konteks RLS, pengelolaan akun) adalah
> perubahan keamanan: rencana dulu, ditinjau Lead, baru kode."

## Two findings that change the real scope of work

1. **`report_documents`/`report_signing_snapshots` carry no role-based RLS
   policy at all** — both are gated exclusively to
   `app_private.app_role() = 'service'`
   (`database/migrations/2026_09_20_100200_create_report_documents.php:150-158`,
   `2026_09_17_000200_create_report_signing_snapshots.php:162-170`). The
   distinction between who may sign vs. publish is enforced entirely in
   `Admin::canPerform()`, not at the database layer. **Adding this role needs
   zero RLS/migration changes to the report tables themselves.**

2. **A second hardcoded admin-role list exists, separate from
   `RlsContext::ROLES`, and it sits directly on `GenerateReports`'s critical
   path.** `App\Security\RlsContextRunner::ADMIN_ROLES`
   (`app/Security/RlsContextRunner.php:14-15`) is a 4-item list
   (`super_admin, branch_admin, staff, psychologist`) that gates
   `runAsService()`'s elevation check
   (`RlsContextRunner.php:65-67`: throws `LogicException('Only administrator
   contexts may elevate to service.')` for anything not in that list).
   `ReportDocumentIssuer::issue()` — the real write path behind
   `GenerateReports` — calls `runAsService()`
   (`ReportDocumentIssuer.php:60`). **If `central_admin` is added to
   `AdminRole`/`RlsContext::ROLES` but not to this second list, the ability
   check passes, the Filament page opens, and the feature then throws at the
   moment of actually publishing a report** — not a clean deny, a runtime
   exception on first real use. Both lists must be updated together.

**Correction to an earlier assumption of mine**: I previously cited the
Group C `packages`/`package_items` migration as an existing "every admin
role" RLS precedent. That's accurate for PR #99, but PR #99 has not merged
into `main` yet — on `main` today, `packages`/`package_items` carry **no**
RLS policy at all (access is gated purely by `TestPackagePolicy` in
application code). The only DB-level policy in the whole repo that lists
every admin role together is `payment_methods_read`
(`database/schema/rls_policies.sql:147-148`).

## Ability matrix — final (item 20), every row decided explicitly, none derived from `super_admin`

| Ability | `central_admin` | Why |
|---|---|---|
| `AccessPanel` | **true** | granted to every role today |
| `ViewParticipants` | **true** | granted to every role today |
| `ManageAdmins` | **false** | explicit, item 20 |
| `ManageTestPackages` | **true** | explicit grant, item 20 ("kelola paket") |
| `ManagePaymentMethods` | **false** | item 20 grants *viewing* the payment-methods list, not managing it — the owner said "lihat daftar metode pembayaran," not "kelola." Kept as a separate RLS-policy decision below, not this ability. |
| `ManageIntegrations` | **false** | never addressed — default deny holds |
| `EditParticipants` | **true** | explicit grant, item 20 |
| `VerifyPayments` | **true**, unconditional (matching `super_admin`'s own unconditional grant, not the `can_verify_payments`-gated conditional path `branch_admin`/`staff` use) | explicit grant, item 20 ("verifikasi pembayaran") |
| `ViewDass` | **false** | explicit, item 20 |
| `ReviewReports` | **false** | explicit, item 20: signing stays psychologist-only |
| `GenerateReports` | **true** | explicit grant, item 17 |

This is now the final matrix — nothing here is "pending" anymore.

## Proposed identity/RLS plumbing (all must land together, per finding #2)

- `app/Enums/AdminRole.php` — new case, proposed name `CentralAdmin =
  'central_admin'` (Lead: usable for now, not necessarily final).
- `Admin::rlsContext()` — `AdminRole::CentralAdmin => new RlsContext('central_admin')`,
  no branch id (same shape as `SuperAdmin`/`Psychologist` — `admins.branch_id`
  is already nullable, confirmed at
  `database/migrations/2026_08_25_000100_create_tenant_identity_tables.php:24-26`,
  no schema change needed there).
- `Admin::canPerform()` — extend `GenerateReports`'s `in_array(...)`.
- `App\Security\RlsContext::ROLES` — add `'central_admin'`.
- `App\Security\RlsContextRunner::ADMIN_ROLES` — add `'central_admin'`
  (**required**, per finding #2 above).
- New migration widening `admins_role_check`
  (`database/migrations/2026_08_25_000100_create_tenant_identity_tables.php:110`,
  `CHECK (role IN ('super_admin', 'branch_admin', 'staff', 'psychologist'))`)
  — Postgres-only today, no SQLite equivalent exists to update.

## RLS policies needing an explicit per-table decision (audited against every policy naming an admin role, i.e. the "ratchet #80 checklist")

Everything not listed here stays unchanged for `central_admin` by default —
consistent with "never derived from `super_admin`."

- **`admins_read`** (`database/schema/rls_policies.sql:82-85`): today even
  `psychologist` cannot read the `admins` table via RLS (excluded from that
  policy). The same exclusion likely applies cleanly to `central_admin` too,
  but not decided here.
- **`payment_methods_read`** — **now included** (item 20: "lihat daftar
  metode pembayaran" is exactly this policy, read-only). `central_admin`
  gets added to this policy's role list; `payment_methods_write` (the
  actual manage/create/deactivate policy) does **not** include
  `central_admin`, matching `ManagePaymentMethods` staying denied above.
- **`packages`/`package_items` read/write policies, once PR #99 merges**:
  **now included, both read and write**. PR #99 adds `packages_read`
  (every admin role) and `packages_update` (service + `super_admin`
  only). Since `ManageTestPackages` is now granted to `central_admin`
  (item 20), it needs the same access `super_admin` has there — add
  `central_admin` to **both** `packages_read` and `packages_update`
  (`package_items` stays service-only for update either way, per PR #99's
  own design — no admin UI edits `package_items` directly, only
  `packages`' own fields).
- All other admin-role-naming policies (`branches`, `participants`,
  `orders`, `entitlements`, `identity_evidence`, `identity_verifications`,
  `audit_logs`, `assessment_participants`, `assessment_bills`/`assessment_bill_items`/
  `assessment_charges`/`assessment_entitlements`, `test_sessions`,
  `branch_fee_rules`/`commission_entries`/`withdrawal_requests`/
  `withdrawal_request_items`, `commission_ledger_gaps`, `consent_records`,
  `referral_visits`) — **unchanged**, no access for `central_admin` unless a
  specific future need is decided.

## Addition 1 (Lead): LKI and DASS — proving `central_admin` (and `super_admin`) never see them

CLAUDE.md: *"Lembar Kerja Internal & data DASS: akses HANYA psikolog+peserta.
Jangan render ke admin/LPK/kumiai."*

**Structural finding, not just a policy claim**: the only production report
path either role can reach (`GenerateReports` → `ReportGeneration::generate()`
→ `SignedReportDataset::hpp()` → `BladeReportRenderer::renderHpp()`) is
structurally incapable of carrying DASS subscale scores or LKI content,
independent of who triggers it:

- `SignedReportDataset::hpp()`'s only DASS query, `dassGeneralCategory()`
  (`SignedReportDataset.php:379-404`), selects `overall_category` only — no
  subscale column is ever named in SQL. Proven by a `DB::listen()`-based test,
  `SignedReportDatasetTest::test_dass_subscales_are_never_read_or_rendered()`
  (`tests/Feature/Reports/SignedReportDatasetTest.php:266-288`), asserting no
  executed query matches `/depression|anxiety|stress/i`.
- `HppReportDraft::toViewData()` only carries
  `DassScreeningSummary::toArray()` (`general_category, narrative,
  follow_up` — 3 keys), and `DassScreeningSummary::fromArray()` rejects any
  extra key (`count($input) !== 3` check) — it cannot structurally carry
  subscale data even if something tried to pass it in.
- "Lembar Kerja Internal" (LKI) exists in code (`InternalReportDraft`,
  `BladeReportRenderer::renderInternal()`, `internal.blade.php`,
  `ReportDocumentPublisher::DOCUMENT_TYPES` includes `'internal'`), but has
  **no admin-reachable path at all today**: no Filament page, no route, and
  its only data source is a fixture explicitly marked as disconnected from
  real data (`FixtureReportDataset::internalDraft()`,
  `FixtureReportDataset.php:13-18`). `ReportGeneration.php` is hardcoded to
  `DOCUMENT_TYPE = 'hpp'` and its own doc comment states `GenerateReports`
  must never be reused to gate a future internal-document page — that page
  "needs its own, stricter ability check" when built.
  `ReportDocumentIssuer::issue()`'s doc comment separately confirms it is
  "not wired into ReportGeneration yet."

**The confirmed gap (why this needs a test, not just an argument)**: every
existing test that calls `->call('generate')` and inspects the resulting
PDF/ledger row for content
(`tests/Feature/Reports/ReportGenerationPageTest.php`, e.g.
`test_complete_signed_case_generates_a_private_pdf_and_short_lived_link`,
`:168-201`) runs **only as `$this->reportPsychologist()`**. The role-varying
tests that DO include `AdminRole::SuperAdmin`
(`test_psychologists_and_super_admins_can_open_the_page`,
`test_real_http_request_renders_for_super_admin`) check page **access**
only — they never call `generate()` and never inspect PDF/HTML content. **No
test today exercises a `super_admin`-triggered `generate()` and then checks
the output for DASS/LKI leakage.** Since `central_admin` sits on the exact
same `GenerateReports` ability, this is the same gap for both roles, not
something new introduced by this plan.

**What the plan requires at implementation time**: extend
`ReportGenerationPageTest`'s content-inspecting tests (the ones that actually
call `generate()` and check the PDF/ledger) to run for `AdminRole::SuperAdmin`
and `AdminRole::CentralAdmin` as well as `AdminRole::Psychologist` — reusing
`HppReportRenderingTest::test_hpp_template_never_prints_internal_only_material()`'s
assertion list (`Subskala`, `Depresi`, `INTEGRASI`, `Validitas Sesi`,
`Kraepelin`, `PAPI`, `RMIB` all absent) as the content check, parameterized
over actor role. This closes the pre-existing `super_admin` gap at the same
time as covering the new role, rather than leaving it as a known-but-untested
assumption for either.

## Addition 2 (Lead): how admin accounts are created today, and a minimal audited path

**Confirmed: no real, production-usable path to create an `Admin` row exists
anywhere in this codebase today.** `database/seeders/DatabaseSeeder.php`
calls only `BranchSeeder`, `InstrumentSeeder`, `TestPackageSeeder`,
`PaymentMethodSeeder` — no admin seeder exists. No `app/Console/Commands/`
file creates an `Admin`. `DEPLOYMENT.md` has no bootstrap-admin step
documented anywhere. Every `Admin::create()`/`Admin::query()->create()` call
in the repo is inside a test file. `AdminAbility::ManageAdmins` is defined
and appears in `canPerform()`'s match arm but is never consumed by any
controller, page, or policy — there is no `AdminPolicy` class and no Filament
resource for the `Admin` model at all.

This means the proposal below would be the **first real admin-creation path**
in the codebase, not an additional one alongside an existing UI — full
`ManageAdmins`/admin-management UI stays explicitly out of scope here, per
Lead.

**Proposed minimal audited path**: an artisan command (e.g.
`admins:create-central-admin {--name=} {--email=}`, naming illustrative),
runnable only by whoever has operator/deploy access to the server (no HTTP
surface, no Filament UI — consistent with "full `ManageAdmins` UI is out of
scope"), that:
1. Creates the `Admin` row with `role = AdminRole::CentralAdmin`,
   `branch_id = null`.
2. Writes an `audit_logs` row in the same operation, following the existing
   precedent shape from `SetPaymentMethodActivation.php:41-56` (`branch_id`,
   `actor_type`, `actor_id`, `action` as a dotted string e.g.
   `admin.central_admin_created`, `subject_type` = `Admin::class`,
   `subject_id`, `context` as a JSON blob, `occurred_at`,
   `expires_at` via `RetentionPolicy::expiresAt(RetentionDataClass::Audit, ...)`).
   Since this command runs outside any authenticated admin session,
   `actor_type`/`actor_id` need a convention for "operator via CLI" — not
   decided here, flagged for implementation (e.g. a required `--operator=`
   argument identifying who ran it, recorded in `context` if not as
   `actor_id` itself).
3. Requires an explicit confirmation flag (e.g. `--confirm`) before writing,
   matching the general pattern of destructive/sensitive artisan commands
   elsewhere refusing to run silently.

No existing artisan command in this codebase currently writes to
`audit_logs` (only `PurgeExpiredAuditLogsCommand` deletes expired rows) — this
would be a new combination (command + audit write), but the audit-row shape
itself follows strong existing precedent, not a new invention.

## Addition 3: resolved by the owner's answer (item 20) — no longer parked

All items previously parked here are now decided:

- `payment_methods_read` inclusion for `central_admin` — **grant** (see RLS
  section above).
- `packages`/`package_items` read/write-policy inclusion for `central_admin`,
  once PR #99 merges — **grant, both read and write** (see RLS section
  above).
- The four ability-matrix rows that were pending (`ManageTestPackages`,
  `EditParticipants`, `VerifyPayments` — all **granted**; `ManagePaymentMethods`
  stays **denied**, since item 20 only grants viewing the payment-methods
  list, not managing it) are now final in the matrix above. Nothing in this
  document is waiting on a further owner answer.

## Full access-matrix test (designed, not written)

For every `(AdminRole × AdminAbility)` pair, assert the expected boolean from
`canPerform()` — including explicitly re-confirming `branch_admin`/`staff`
remain rejected for `GenerateReports` (regression protection, not new
behavior), and now also covering real assertions for the four
previously-pending abilities (`ManageTestPackages`, `EditParticipants`,
`VerifyPayments`, `ManagePaymentMethods`) rather than placeholders. Separately,
a test that actually exercises `RlsContextRunner::runAsService()` from a
`central_admin` `RlsContext` and asserts it succeeds (not just that
`canPerform()` returns `true` in PHP) — this is what would have caught
finding #2 above before it reached production. A third layer, new since the
matrix went final: Postgres RLS tests proving `central_admin` can actually
read `payment_methods` and read+update `packages` (mirroring
`super_admin`'s existing coverage in `TestPackageCatalogRlsSecurityTest`),
and cannot write `payment_methods` or delete either table. Combined with
Addition 1's role-parameterized content checks, this is the full
verification surface for this role once implemented.

## Nothing changed yet

Until this role is actually built, `GenerateReports` stays psychologist +
super_admin only, exactly as it is today. This document is design only.
