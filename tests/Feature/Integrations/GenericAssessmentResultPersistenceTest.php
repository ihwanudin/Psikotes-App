<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\AssessmentCase;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Services\Integrations\GenericAssessmentResultStore;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class GenericAssessmentResultPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-05 12:00:00+07:00');
    }

    public function test_it_persists_fractional_iq_without_rounding_and_audits_only_safe_context(): void
    {
        $assessment = $this->assessment();
        Date::setTestNow('2024-02-29 10:15:00+07:00');
        $snapshot = $this->snapshot($assessment->assessment_attempt_id, iq: 98.75);
        $snapshot['completedAt'] = '2024-02-29T03:00:00+00:00';

        $result = app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot($snapshot);

        $this->assertSame('CREATED', $result['action']);
        $this->assertSame(98.75, $result['envelope']['iq']);
        $row = DB::table('generic_assessment_result_versions')->sole();
        $this->assertSame(98.75, (float) $row->iq);
        $this->assertSame('98.75', $row->iq_canonical);
        $this->assertSame(1, $row->result_version);
        $this->assertNull($row->supersedes_id);

        $audit = DB::table('audit_logs')->where('action', 'generic_assessment_result.created')->sole();
        $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2024-02-29 03:15:00', $audit->occurred_at);
        $this->assertSame('2029-02-28 03:15:00', $audit->expires_at);
        $this->assertSame([
            'assessmentAttemptReference' => hash('sha256', $assessment->assessment_attempt_id),
            'resultVersion' => 1,
            'resultChecksum' => $row->result_checksum,
            'finality' => 'FINALIZED',
            'isRevoked' => false,
        ], $context);
        $participant = $assessment->participant;
        $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
        foreach ([$assessment->assessment_attempt_id, '98.75', 'engineVersion', $participant->full_name,
            $participant->phone, $assessment->external_candidate_id, $assessment->idempotency_key,
            $assessment->request_hash] as $privateValue) {
            $this->assertStringNotContainsString((string) $privateValue, $encoded);
        }
    }

    public function test_exact_replay_keeps_one_result_but_records_each_successful_action(): void
    {
        $assessment = $this->assessment();
        $store = app(GenericAssessmentResultStore::class);
        $snapshot = $this->snapshot($assessment->assessment_attempt_id);

        $first = $store->persistAuthorizedSnapshot($snapshot);
        $replay = $store->persistAuthorizedSnapshot($snapshot);

        $this->assertSame($first['envelope'], $replay['envelope']);
        $this->assertSame('REPLAYED', $replay['action']);
        $this->assertDatabaseCount('generic_assessment_result_versions', 1);
        $this->assertSame(
            ['generic_assessment_result.created', 'generic_assessment_result.replayed'],
            DB::table('audit_logs')->orderBy('id')->pluck('action')->all(),
        );
        $audits = DB::table('audit_logs')->orderBy('id')->get();
        $this->assertSame($audits[0]->context, $audits[1]->context);
        foreach ($audits as $audit) {
            $occurredAt = CarbonImmutable::parse($audit->occurred_at)->utc();
            $this->assertTrue(CarbonImmutable::parse($audit->expires_at)->utc()->equalTo(
                app(RetentionPolicy::class)->expiresAt(RetentionDataClass::Audit, $occurredAt),
            ));
        }
    }

    public function test_lowercase_attempt_input_resolves_and_persists_the_canonical_uppercase_owner(): void
    {
        $assessment = $this->assessment();
        $lowercaseAttempt = strtolower($assessment->assessment_attempt_id);

        $result = app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot(
            $this->snapshot($lowercaseAttempt),
        );

        $this->assertSame($assessment->assessment_attempt_id, $result['envelope']['assessmentAttemptId']);
        $this->assertDatabaseHas('generic_assessment_result_versions', [
            'assessment_participant_id' => $assessment->id,
            'assessment_attempt_id' => $assessment->assessment_attempt_id,
            'result_checksum' => $result['envelope']['resultChecksum'],
        ]);
    }

    public function test_correction_and_revocation_append_a_linear_immutable_history(): void
    {
        $assessment = $this->assessment();
        $store = app(GenericAssessmentResultStore::class);
        $store->persistAuthorizedSnapshot($this->snapshot($assessment->assessment_attempt_id));

        $corrected = $store->persistAuthorizedSnapshot($this->snapshot(
            $assessment->assessment_attempt_id,
            iq: 101.125,
            resultVersion: 2,
        ));
        $revoked = $store->persistAuthorizedSnapshot($this->snapshot(
            $assessment->assessment_attempt_id,
            iq: 101.125,
            resultVersion: 3,
            revokedAt: '2026-09-05T12:30:00+07:00',
        ));

        $this->assertSame('CORRECTED', $corrected['action']);
        $this->assertSame('REVOKED', $revoked['action']);
        $rows = DB::table('generic_assessment_result_versions')->orderBy('result_version')->get();
        $this->assertCount(3, $rows);
        $this->assertSame((string) $rows[0]->id, (string) $rows[1]->supersedes_id);
        $this->assertSame((string) $rows[1]->id, (string) $rows[2]->supersedes_id);
        $this->assertSame(101.125, (float) $rows[2]->iq);
        $this->assertNotNull($rows[2]->revoked_at);
        $this->assertSame([
            'generic_assessment_result.created',
            'generic_assessment_result.corrected',
            'generic_assessment_result.revoked',
        ], DB::table('audit_logs')->orderBy('id')->pluck('action')->all());
    }

    public function test_attempt_must_exist_and_match_the_exact_owned_assessment(): void
    {
        $this->assessment();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ASSESSMENT_RESULT_ATTEMPT_NOT_FOUND');

        app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot(
            $this->snapshot((string) Str::ulid()),
        );
    }

    public function test_iq_above_the_contract_ceiling_is_rejected_before_any_result_or_audit_write(): void
    {
        $assessment = $this->assessment();

        foreach ([300.000001, 9_007_199_254_740_993] as $iq) {
            try {
                app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot(
                    $this->snapshot($assessment->assessment_attempt_id, iq: $iq),
                );
                $this->fail('Out-of-domain IQ was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('ASSESSMENT_RESULT_SOURCE_INVALID', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('generic_assessment_result_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_stale_skipped_conflicting_no_change_and_post_revocation_versions_fail_closed(): void
    {
        $assessment = $this->assessment();
        $store = app(GenericAssessmentResultStore::class);
        $store->persistAuthorizedSnapshot($this->snapshot($assessment->assessment_attempt_id));

        foreach ([
            [$this->snapshot($assessment->assessment_attempt_id, iq: 100, resultVersion: 1), 'ASSESSMENT_RESULT_VERSION_CONFLICT'],
            [$this->snapshot($assessment->assessment_attempt_id, resultVersion: 2), 'ASSESSMENT_RESULT_VERSION_NO_CHANGE'],
            [$this->snapshot($assessment->assessment_attempt_id, iq: 99.0, resultVersion: 2), 'ASSESSMENT_RESULT_VERSION_NO_CHANGE'],
            [$this->snapshot($assessment->assessment_attempt_id, iq: 100, resultVersion: 3), 'ASSESSMENT_RESULT_VERSION_SEQUENCE_INVALID'],
        ] as [$snapshot, $message]) {
            try {
                $store->persistAuthorizedSnapshot($snapshot);
                $this->fail('Invalid result version was persisted.');
            } catch (LogicException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        $store->persistAuthorizedSnapshot($this->snapshot(
            $assessment->assessment_attempt_id,
            resultVersion: 2,
            revokedAt: '2026-09-05T12:30:00+07:00',
        ));

        try {
            $store->persistAuthorizedSnapshot($this->snapshot($assessment->assessment_attempt_id, iq: 100, resultVersion: 3));
            $this->fail('A revoked result must remain terminal.');
        } catch (LogicException $exception) {
            $this->assertSame('ASSESSMENT_RESULT_ALREADY_REVOKED', $exception->getMessage());
        }

        $this->assertDatabaseCount('generic_assessment_result_versions', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_revocation_cannot_rewrite_the_authoritative_result_payload(): void
    {
        $assessment = $this->assessment();
        $store = app(GenericAssessmentResultStore::class);
        $store->persistAuthorizedSnapshot($this->snapshot($assessment->assessment_attempt_id));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ASSESSMENT_RESULT_REVOCATION_INVALID');

        $store->persistAuthorizedSnapshot($this->snapshot(
            $assessment->assessment_attempt_id,
            iq: 100,
            resultVersion: 2,
            revokedAt: '2026-09-05T12:30:00+07:00',
        ));
    }

    public function test_first_result_cannot_arrive_already_revoked(): void
    {
        $assessment = $this->assessment();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ASSESSMENT_RESULT_INITIAL_REVOCATION_INVALID');

        app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot($this->snapshot(
            $assessment->assessment_attempt_id,
            revokedAt: '2026-09-05T12:30:00+07:00',
        ));
    }

    public function test_audit_failure_rolls_back_the_result_atomically(): void
    {
        $assessment = $this->assessment();
        DB::unprepared("CREATE TRIGGER reject_result_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'generic_assessment_result.created'
            BEGIN SELECT RAISE(ABORT, 'synthetic result audit failure'); END");

        try {
            app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot(
                $this->snapshot($assessment->assessment_attempt_id),
            );
            $this->fail('Audit failure must abort result persistence.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic result audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('generic_assessment_result_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_replay_audit_failure_is_not_reported_as_a_success(): void
    {
        $assessment = $this->assessment();
        $snapshot = $this->snapshot($assessment->assessment_attempt_id);
        $store = app(GenericAssessmentResultStore::class);
        $store->persistAuthorizedSnapshot($snapshot);
        DB::unprepared("CREATE TRIGGER reject_result_replay_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'generic_assessment_result.replayed'
            BEGIN SELECT RAISE(ABORT, 'synthetic replay audit failure'); END");

        try {
            $store->persistAuthorizedSnapshot($snapshot);
            $this->fail('Replay audit failure must prevent a successful return.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic replay audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('generic_assessment_result_versions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_database_guards_reject_mutation_delete_and_invalid_direct_history(): void
    {
        $assessment = $this->assessment();
        $otherAssessment = $this->assessment();
        app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot(
            $this->snapshot($assessment->assessment_attempt_id),
        );
        $row = (array) DB::table('generic_assessment_result_versions')->sole();

        foreach ([
            fn () => DB::table('generic_assessment_result_versions')->where('id', $row['id'])->update(['iq' => 110]),
            fn () => DB::table('generic_assessment_result_versions')->where('id', $row['id'])->delete(),
            fn () => DB::table('generic_assessment_result_versions')->insert([
                ...$row,
                'id' => (string) Str::ulid(),
                'result_version' => 3,
                'supersedes_id' => $row['id'],
                'result_checksum' => str_repeat('b', 64),
            ]),
            fn () => DB::table('generic_assessment_result_versions')->insert([
                ...$row,
                'id' => (string) Str::ulid(),
                'assessment_participant_id' => $otherAssessment->id,
                'result_version' => 1,
                'supersedes_id' => null,
                'result_checksum' => str_repeat('a', 64),
            ]),
            fn () => DB::table('generic_assessment_result_versions')->insert([
                ...$row,
                'id' => (string) Str::ulid(),
                'assessment_participant_id' => $otherAssessment->id,
                'result_version' => 2,
                'supersedes_id' => $row['id'],
                'result_checksum' => str_repeat('c', 64),
            ]),
            fn () => DB::table('generic_assessment_result_versions')->insert([
                ...$row,
                'id' => (string) Str::ulid(),
                'result_version' => 2,
                'supersedes_id' => $row['id'],
                'iq' => 100,
                'iq_canonical' => '100oops',
                'result_checksum' => str_repeat('e', 64),
            ]),
            fn () => DB::table('generic_assessment_result_versions')->insert([
                ...$row,
                'id' => (string) Str::ulid(),
                'result_version' => 2,
                'supersedes_id' => $row['id'],
                'iq' => 301,
                'iq_canonical' => '301',
                'result_checksum' => str_repeat('f', 64),
            ]),
            fn () => DB::table('generic_assessment_result_versions')->insert([
                ...$row,
                'id' => (string) Str::ulid(),
                'assessment_participant_id' => $otherAssessment->id,
                'assessment_attempt_id' => $otherAssessment->assessment_attempt_id,
                'result_version' => 1,
                'supersedes_id' => null,
                'revoked_at' => '2026-09-05 05:30:00',
                'result_checksum' => str_repeat('d', 64),
            ]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Direct invalid write was accepted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('generic_assessment_result_versions', 1);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    /** @return array<string, mixed> */
    private function snapshot(
        string $assessmentAttemptId,
        int|float $iq = 99,
        int $resultVersion = 1,
        ?string $revokedAt = null,
    ): array {
        return [
            'assessmentAttemptId' => $assessmentAttemptId,
            'iq' => $iq,
            'engineVersion' => 'ist-2026.09.1',
            'completedAt' => '2026-09-05T10:15:30.123456+07:00',
            'finality' => 'FINALIZED',
            'revokedAt' => $revokedAt,
            'resultVersion' => $resultVersion,
        ];
    }

    private function assessment(): AssessmentParticipant
    {
        $key = (string) Str::ulid();
        $organization = Branch::query()->create([
            'code' => $key,
            'ref_code' => $key,
            'name' => 'Synthetic result organization',
            'organization_code' => $key,
            'display_name' => 'Synthetic result organization',
            'status' => 'ACTIVE',
            'is_active' => true,
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $organization->id,
            'referral_branch_id' => $organization->id,
            'referral_source' => 'manual',
            'source_system' => 'RESULT_TEST',
            'full_name' => 'Synthetic participant',
            'phone' => '620000000000',
        ]);
        $client = IntegrationClient::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $key,
            'credential_reference' => 'synthetic-only',
            'enabled' => true,
        ]);
        $package = TestPackage::query()->create([
            'code' => 'R'.$key,
            'name' => 'Synthetic result package',
            'amount' => 100,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $assessmentAttemptId = (string) Str::ulid();
        $case = AssessmentCase::query()->create([
            'public_id' => $assessmentAttemptId,
            'participant_id' => $participant->id,
            'organization_id' => $organization->id,
            'package_id' => $package->id,
            'origin' => 'INTEGRATED',
            'intended_field_snapshot' => null,
        ]);

        return AssessmentParticipant::query()->create([
            'assessment_case_id' => $case->id,
            'organization_id' => $organization->id,
            'integration_client_id' => $client->id,
            'participant_id' => $participant->id,
            'package_id' => $package->id,
            'assessment_attempt_id' => $assessmentAttemptId,
            'source_system' => 'RESULT_TEST',
            'external_candidate_id' => $key,
            'funding_mode' => 'SPONSORED',
            'assessment_status' => 'UNDER_REVIEW',
            'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);
    }
}
