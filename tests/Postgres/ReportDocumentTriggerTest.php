<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Reports\Concerns\SeedsSignedReportCase;

/**
 * PostgreSQL runtime evidence for report_documents: RLS forced under the
 * non-superuser runtime role, append-only enforcement (UPDATE/DELETE
 * rejected), and the guard requiring a real SIGNED signing snapshot on
 * the same case (report-documents-schema-proposal.md §9 point 2).
 */
final class ReportDocumentTriggerTest extends TestCase
{
    use SeedsSignedReportCase;

    private int $caseId;

    private string $snapshotId;

    private int $psychologistId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();

        $seed = app(RlsContextRunner::class)->runAsService(function (): array {
            $case = $this->createReportCase();
            $psychologist = $this->reportPsychologist();
            $snapshotId = $this->signCase($case, $psychologist);

            return ['caseId' => $case->id, 'snapshotId' => $snapshotId, 'psychologistId' => (int) $psychologist->id];
        });
        $this->caseId = $seed['caseId'];
        $this->snapshotId = $seed['snapshotId'];
        $this->psychologistId = $seed['psychologistId'];

        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_role_is_not_privileged(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_rls_is_enabled_and_forced(): void
    {
        $table = DB::table('pg_tables')->where('tablename', 'report_documents')->first(['rowsecurity']);
        $this->assertNotNull($table);
        $this->assertTrue($table->rowsecurity);
    }

    public function test_table_privileges_are_select_and_insert_only(): void
    {
        $privileges = DB::table('information_schema.table_privileges')
            ->where('table_name', 'report_documents')
            ->where('grantee', 'psikotes_runtime')
            ->pluck('privilege_type')
            ->toArray();
        sort($privileges);
        $this->assertSame(['INSERT', 'SELECT'], $privileges);
    }

    public function test_insert_for_a_real_signed_snapshot_succeeds_and_is_readable(): void
    {
        $id = app(RlsContextRunner::class)->runAsService(function (): string {
            $id = $this->insertDocument($this->snapshotId, $this->caseId, $this->psychologistId);
            DB::table('report_documents')->where('id', $id)->sole();

            return $id;
        });

        $row = app(RlsContextRunner::class)->runAsService(fn () => DB::table('report_documents')->where('id', $id)->first());
        $this->assertNotNull($row);
        $this->assertSame($this->caseId, (int) $row->assessment_case_id);
        $this->assertSame($this->snapshotId, $row->signing_snapshot_id);
    }

    public function test_update_is_rejected_by_the_append_only_trigger(): void
    {
        $caught = false;
        try {
            app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
                $id = $this->insertDocument($this->snapshotId, $this->caseId, $this->psychologistId);

                DB::table('report_documents')->where('id', $id)->update(['render_seq' => 2]);
            });
        } catch (QueryException $e) {
            $caught = true;
            $msg = $e->getMessage();
            $this->assertTrue(
                str_contains($msg, '42501') || str_contains($msg, 'append-only'),
                "UPDATE should be rejected; got: {$msg}",
            );
        }
        $this->assertTrue($caught, 'UPDATE should be rejected');

        // The insert itself was rolled back with the failed update: nothing persisted.
        $this->assertSame(0, app(RlsContextRunner::class)->runAsService(fn () => DB::table('report_documents')->count()));
    }

    public function test_delete_is_rejected_by_the_append_only_trigger(): void
    {
        $caught = false;
        try {
            app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
                $id = $this->insertDocument($this->snapshotId, $this->caseId, $this->psychologistId);

                DB::table('report_documents')->where('id', $id)->delete();
            });
        } catch (QueryException $e) {
            $caught = true;
            $msg = $e->getMessage();
            $this->assertTrue(
                str_contains($msg, '42501') || str_contains($msg, 'append-only'),
                "DELETE should be rejected; got: {$msg}",
            );
        }
        $this->assertTrue($caught, 'DELETE should be rejected');
        $this->assertSame(0, app(RlsContextRunner::class)->runAsService(fn () => DB::table('report_documents')->count()));
    }

    /**
     * report_signing_snapshots' own contract check (state = 'SIGNED' ...)
     * makes every row in that table SIGNED by construction — there is no
     * way to create a non-SIGNED row to reference. The practically
     * reachable equivalent of "don't attach a document to something that
     * wasn't really signed" is: the referenced snapshot must actually
     * exist. Flagged to the coordinator rather than assumed.
     */
    public function test_insert_referencing_a_nonexistent_snapshot_is_rejected(): void
    {
        $caught = false;
        try {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $this->insertDocument((string) Str::ulid(), $this->caseId, $this->psychologistId);
            });
        } catch (QueryException $e) {
            $caught = true;
            $this->assertStringContainsString('non-existent signing snapshot', $e->getMessage());
        }
        $this->assertTrue($caught, 'INSERT referencing a non-existent snapshot should be rejected');
    }

    public function test_insert_with_a_snapshot_from_a_different_case_is_rejected(): void
    {
        // Distinct test_number: createReportCase()'s default collides with
        // the one setUp() already created (PostgreSQL enforces the unique
        // constraint that SQLite's schema in this fixture does not hit
        // within a single test method).
        $otherCaseId = app(RlsContextRunner::class)->runAsService(
            fn (): int => $this->createReportCase(testNumber: 'T26-09-9002')->id,
        );

        $caught = false;
        try {
            app(RlsContextRunner::class)->runAsService(function () use ($otherCaseId): void {
                // A real, SIGNED snapshot — but for the WRONG case.
                $this->insertDocument($this->snapshotId, $otherCaseId, $this->psychologistId);
            });
        } catch (QueryException $e) {
            $caught = true;
            $this->assertStringContainsString('does not belong to the same assessment_case_id', $e->getMessage());
        }
        $this->assertTrue($caught, 'INSERT with a cross-case snapshot should be rejected');
    }

    private function insertDocument(string $snapshotId, int $caseId, int $psychologistId): string
    {
        $id = (string) Str::ulid();
        $random = Str::lower(Str::random(64));

        DB::table('report_documents')->insert([
            'id' => $id,
            'assessment_case_id' => $caseId,
            'signing_snapshot_id' => $snapshotId,
            'document_type' => 'hpp',
            'report_number' => 'HPP/2026/09/0001',
            'report_version' => 1,
            'render_seq' => 1,
            'object_key' => 'reports/hpp/'.substr($random, 0, 2).'/'.substr($random, 2).'.pdf',
            'sha256' => hash('sha256', $id),
            'size_bytes' => 1024,
            'psychologist_admin_id' => $psychologistId,
            'psychologist_name_snapshot' => 'Psikolog Uji PostgreSQL',
            'psychologist_silp_snapshot' => 'SILP-PG-TEST',
            'psychologist_str_snapshot' => null,
            'facility_name_snapshot' => 'Fasilitas Uji PostgreSQL',
            'generated_at' => now(),
            'created_at' => now(),
        ]);

        return $id;
    }
}
