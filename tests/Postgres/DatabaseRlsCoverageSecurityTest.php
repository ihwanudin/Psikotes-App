<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

/**
 * F2 catch-all RLS ratchet (2026-09-21, Lead's request after PR #78's
 * assessment_asset_references RLS gap). Scans every table in the `public`
 * schema and requires each one to be either RLS `ENABLE`+`FORCE`-protected,
 * or explicitly listed below with a written reason. A table in neither
 * list fails the build -- this is the guard manual review didn't provide
 * for assessment_asset_references.
 *
 * This is a RATCHET, not a bug-fix gate (Lead's explicit call): the
 * discovery audit that produced EXCEPTION_TABLES/KNOWN_GAPS below found
 * real, pre-existing app-data tables with no RLS at all. Failing the build
 * for debt that already exists in every other PR would redden CI
 * repo-wide and block the whole team. So: existing debt is frozen into
 * KNOWN_GAPS (with a finding code, reported to Lead), no NEW unprotected
 * table can be added without landing in one of the two lists, and fixing a
 * gap requires removing its entry -- enforced by
 * test_known_gaps_are_still_actually_unprotected() below, so the list
 * cannot go stale once a fix lands.
 *
 * Each RLS-GAP-* finding is real app-data, not test-authoring debt, and
 * needs its own remediation PLAN before any code changes -- enabling RLS
 * on a table something reads outside a service context would break that
 * read in production (the exact class of bug this whole audit started
 * from). See tasks/handoffs/f2/database-rls-coverage-audit.md.
 */
final class DatabaseRlsCoverageSecurityTest extends TestCase
{
    /**
     * Permanent exceptions: Laravel's own framework infrastructure, never
     * expected to carry RLS. Reviewed and confirmed with Lead 2026-09-21.
     *
     * @var array<string, string>
     */
    private const array EXCEPTION_TABLES = [
        'cache' => 'Laravel cache store -- framework infrastructure, not app data.',
        'cache_locks' => 'Laravel cache lock bookkeeping -- framework infrastructure, not app data.',
        'jobs' => 'Laravel queue table -- framework infrastructure, not app data. NOTE (Lead, '
            .'2026-09-21): job payloads can contain participant data; that is a data-RETENTION '
            .'question, not an RLS question -- tracked as a separate finding, not by this test.',
        'job_batches' => 'Laravel queue batch bookkeeping -- framework infrastructure, not app data.',
        'failed_jobs' => 'Laravel failed-queue-job bookkeeping -- framework infrastructure, not app '
            .'data. Same payload-retention note as jobs above.',
        'migrations' => 'Laravel migration bookkeeping -- framework infrastructure, not app data.',
    ];

    /**
     * Known, tracked gaps -- real app-data tables with no RLS today,
     * found by the 2026-09-21 catalog audit. Frozen debt: this test does
     * not fail for these, but test_known_gaps_are_still_actually_unprotected()
     * below fails loudly the moment one of these IS fixed without being
     * removed from this list, so the list can never quietly go stale.
     * Each remediation is its own PR with its own read/write-path-mapped
     * plan (RLS on a table read outside a service context breaks that
     * read in production -- the exact bug class instrument_versions's own
     * later "harden" migration exists to prevent).
     *
     * @var array<string, string>
     */
    private const array KNOWN_GAPS = [
        'generic_assessment_result_versions' => 'RLS-GAP-01: generic assessment result payload '
            .'versions (Selection-integration result-callback pipeline, feature-flag-gated by '
            .'selection_integration.result_callback_enabled). See GenericAssessmentResultStore.',
        'generic_assessment_result_outbox' => 'RLS-GAP-02: result-callback dispatch outbox. See '
            .'GenericAssessmentResultOutbox.',
        'generic_assessment_result_dispatch_attempts' => 'RLS-GAP-03: result-callback dispatch '
            .'attempt/retry ledger. See GenericAssessmentResultDispatch.',
        'generic_assessment_result_callback_schedules' => 'RLS-GAP-04: result-callback schedule/'
            .'cadence tracking. See GenericAssessmentResultCallbackOrchestrator.',
        'report_number_sequences' => 'RLS-GAP-05: sequential report-number issuance state. See '
            .'App\Services\ReportRendering\ReportNumberIssuer.',
        'test_number_sequences' => 'RLS-GAP-06: sequential test-number issuance state, used by the '
            .'scheduled test-numbers:prepare-month command. See '
            .'App\Services\TestNumber\MonthlyTestNumberIssuer.',
        'packages' => 'RLS-GAP-07: relrowsecurity=0 but relforcerowsecurity=1 -- FORCE set without '
            .'ENABLE, a broken/incomplete migration, not a deliberate no-RLS choice. Read by the '
            .'public registration page per Lead\'s note; likely needs a non-service-only read '
            .'policy, not a blanket service-only one.',
        'package_items' => 'RLS-GAP-08: same FORCE-without-ENABLE migration bug as packages above; '
            .'same remediation PR.',
        'users' => 'RLS-GAP-09: Fortify/Jetstream default auth scaffold cluster, registered and '
            .'live in bootstrap/providers.php but of unconfirmed actual use versus the app\'s real '
            .'Admin/participant-JWT auth -- under investigation, not yet a confirmed dead surface. '
            .'See tasks/handoffs/f2/fortify-users-cluster-investigation.md.',
        'password_reset_tokens' => 'RLS-GAP-10: same Fortify/Jetstream cluster as users above.',
        'sessions' => 'RLS-GAP-11: same Fortify/Jetstream cluster as users above (Laravel web '
            .'session storage for the `web` guard specifically, not participant JWT sessions).',
        'passkeys' => 'RLS-GAP-12: same Fortify/Jetstream cluster as users above; stores WebAuthn '
            .'credential_id/credential per users.id.',
    ];

    public function test_every_public_table_is_rls_protected_or_an_explicit_exception(): void
    {
        foreach ($this->publicTables() as $table) {
            if (array_key_exists($table, self::EXCEPTION_TABLES) || array_key_exists($table, self::KNOWN_GAPS)) {
                continue;
            }

            $this->assertTrue($this->isRlsProtected($table), <<<MESSAGE
                Table "{$table}" has no RLS (ENABLE+FORCE) and is not in EXCEPTION_TABLES or
                KNOWN_GAPS. New tables must ship with RLS from their own creation migration (see
                assessment_asset_references's migration for the pattern), or be added to one of
                the two documented lists above with a written reason -- never silently skipped.
                MESSAGE);
        }
    }

    public function test_every_listed_table_still_exists(): void
    {
        $tables = $this->publicTables();

        foreach ([...array_keys(self::EXCEPTION_TABLES), ...array_keys(self::KNOWN_GAPS)] as $listed) {
            $this->assertContains(
                $listed,
                $tables,
                "\"{$listed}\" is listed in this test but no longer exists as a table -- remove its entry.",
            );
        }
    }

    public function test_known_gaps_are_still_actually_unprotected(): void
    {
        foreach (array_keys(self::KNOWN_GAPS) as $table) {
            $this->assertFalse(
                $this->isRlsProtected($table),
                "\"{$table}\" is listed in KNOWN_GAPS but is now RLS-protected -- the fix landed; "
                ."remove its entry so this list doesn't go stale.",
            );
        }
    }

    public function test_exception_tables_have_no_rls_either(): void
    {
        // Not a security requirement -- just catches a copy-paste mistake
        // where a real app table gets mislabeled as framework infrastructure.
        foreach (array_keys(self::EXCEPTION_TABLES) as $table) {
            $this->assertFalse(
                $this->isRlsProtected($table),
                "\"{$table}\" is in EXCEPTION_TABLES but is actually RLS-protected -- "
                .'double check it belongs in that list at all.',
            );
        }
    }

    /** @return list<string> */
    private function publicTables(): array
    {
        return array_values(DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', 'public')
            ->where('c.relkind', 'r')
            ->pluck('c.relname')
            ->all());
    }

    private function isRlsProtected(string $table): bool
    {
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', [$table]);

        return $row !== null && (bool) $row->relrowsecurity && (bool) $row->relforcerowsecurity;
    }
}
