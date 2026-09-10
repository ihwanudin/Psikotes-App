<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\GenericAssessmentResultVersion;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Services\Integrations\GenericAssessmentResultOutbox;
use App\Services\Integrations\GenericAssessmentResultStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\AssessmentBillingFixture;
use Tests\TestCase;

final class GenericAssessmentResultOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-05 12:00:00+07:00');
    }

    public function test_it_enqueues_an_exact_persisted_result_binding_without_copying_sensitive_payload(): void
    {
        $assessment = $this->assessment();
        $source = $this->persist($assessment);

        $result = app(GenericAssessmentResultOutbox::class)->enqueueExact(
            $source->id,
            $assessment->assessment_attempt_id,
            1,
            $source->result_checksum,
        );

        $this->assertSame('CREATED', $result['action']);
        $row = DB::table('generic_assessment_result_outbox')->sole();
        $this->assertSame($source->id, $row->generic_assessment_result_version_id);
        $this->assertSame($assessment->id, $row->assessment_participant_id);
        $this->assertSame(1, $row->result_version);
        $this->assertSame($source->result_checksum, $row->result_checksum);
        $this->assertSame('generic-assessment-result:v1', $row->envelope_contract);
        $this->assertFalse(property_exists($row, 'iq'));
        $this->assertFalse(property_exists($row, 'payload'));

        $audit = DB::table('audit_logs')->where('action', 'generic_assessment_result_outbox.created')->sole();
        $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', $assessment->assessment_attempt_id), $context['assessmentAttemptReference']);
        $this->assertSame(1, $context['resultVersion']);
        $this->assertSame($source->result_checksum, $context['resultChecksum']);
        $this->assertSame('FINALIZED', $context['finality']);
        $this->assertFalse($context['isRevoked']);
        $encoded = json_encode([$row, $audit], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($assessment->assessment_attempt_id, $audit->context);
        $this->assertStringNotContainsString('99.125', $encoded);
        $this->assertStringNotContainsString('engineVersion', $encoded);
        $this->assertStringNotContainsString('Synthetic participant', $encoded);
    }

    public function test_exact_replay_is_idempotent_and_audited(): void
    {
        $assessment = $this->assessment();
        $source = $this->persist($assessment);
        $outbox = app(GenericAssessmentResultOutbox::class);

        $first = $outbox->enqueueExact($source->id, $assessment->assessment_attempt_id, 1, $source->result_checksum);
        $replay = $outbox->enqueueExact($source->id, $assessment->assessment_attempt_id, 1, $source->result_checksum);

        $this->assertSame('CREATED', $first['action']);
        $this->assertSame('REPLAYED', $replay['action']);
        $this->assertSame($first['outboxId'], $replay['outboxId']);
        $this->assertDatabaseCount('generic_assessment_result_outbox', 1);
        $this->assertSame([
            'generic_assessment_result_outbox.created',
            'generic_assessment_result_outbox.replayed',
        ], DB::table('audit_logs')->where('action', 'like', 'generic_assessment_result_outbox.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_unpublished_stale_source_is_skipped_but_revocation_is_a_publishable_latest_version(): void
    {
        $assessment = $this->assessment();
        $first = $this->persist($assessment);
        $second = $this->persist($assessment, iq: 101.5, version: 2);
        $outbox = app(GenericAssessmentResultOutbox::class);

        $skipped = $outbox->enqueueExact($first->id, $assessment->assessment_attempt_id, 1, $first->result_checksum);
        $this->assertSame('SKIPPED_STALE', $skipped['action']);
        $this->assertNull($skipped['outboxId']);
        $this->assertDatabaseCount('generic_assessment_result_outbox', 0);

        $revoked = $this->persist($assessment, iq: 101.5, version: 3, revokedAt: '2026-09-05T12:30:00+07:00');
        $published = $outbox->enqueueExact($revoked->id, $assessment->assessment_attempt_id, 3, $revoked->result_checksum);
        $this->assertSame('CREATED', $published['action']);
        $this->assertDatabaseCount('generic_assessment_result_outbox', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'generic_assessment_result_outbox.skipped']);
        $audit = DB::table('audit_logs')->where('action', 'generic_assessment_result_outbox.created')->sole();
        $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($context['isRevoked']);
        $this->assertSame($revoked->result_checksum, $context['resultChecksum']);
        $this->assertNotSame($second->result_checksum, $context['resultChecksum']);
    }

    public function test_binding_conflict_fails_closed_and_leaves_a_durable_safe_failure_audit(): void
    {
        $assessment = $this->assessment();
        $source = $this->persist($assessment);

        try {
            app(GenericAssessmentResultOutbox::class)->enqueueExact(
                $source->id,
                $assessment->assessment_attempt_id,
                1,
                str_repeat('b', 64),
            );
            $this->fail('A mismatched checksum was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame('ASSESSMENT_RESULT_OUTBOX_SOURCE_CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseCount('generic_assessment_result_outbox', 0);
        $audit = DB::table('audit_logs')->where('action', 'generic_assessment_result_outbox.failed')->sole();
        $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', $assessment->assessment_attempt_id), $context['assessmentAttemptReference']);
        $this->assertSame(1, $context['resultVersion']);
        $this->assertSame(str_repeat('b', 64), $context['resultChecksum']);
        $this->assertSame('SOURCE_CONFLICT', $context['reasonCode']);
        $this->assertNull($context['finality']);
        $this->assertNull($context['isRevoked']);
        $this->assertStringNotContainsString($assessment->assessment_attempt_id, (string) $audit->context);
    }

    public function test_audit_failure_rolls_back_a_new_outbox_intent_atomically(): void
    {
        $assessment = $this->assessment();
        $source = $this->persist($assessment);
        DB::unprepared("CREATE TRIGGER reject_result_outbox_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'generic_assessment_result_outbox.created'
            BEGIN SELECT RAISE(ABORT, 'synthetic outbox audit failure'); END");

        try {
            app(GenericAssessmentResultOutbox::class)->enqueueExact(
                $source->id,
                $assessment->assessment_attempt_id,
                1,
                $source->result_checksum,
            );
            $this->fail('Audit failure must abort outbox persistence.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic outbox audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('generic_assessment_result_outbox', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'generic_assessment_result_outbox.%')->count());
    }

    public function test_database_guards_reject_identity_mutation_deletion_and_forged_source_binding(): void
    {
        $assessment = $this->assessment();
        $other = $this->assessment();
        $source = $this->persist($assessment);
        $otherSource = $this->persist($other);
        app(GenericAssessmentResultOutbox::class)->enqueueExact(
            $source->id,
            $assessment->assessment_attempt_id,
            1,
            $source->result_checksum,
        );
        $row = (array) DB::table('generic_assessment_result_outbox')->sole();

        foreach ([
            fn () => DB::table('generic_assessment_result_outbox')->where('id', $row['id'])->update(['result_version' => 2]),
            fn () => DB::table('generic_assessment_result_outbox')->where('id', $row['id'])->delete(),
            fn () => DB::table('generic_assessment_result_outbox')->insert([
                ...$row,
                'id' => (string) Str::ulid(),
                'generic_assessment_result_version_id' => $otherSource->id,
                'assessment_participant_id' => $assessment->id,
                'assessment_attempt_id' => $assessment->assessment_attempt_id,
                'result_checksum' => $otherSource->result_checksum,
            ]),
            fn () => DB::table('generic_assessment_result_outbox')->insert([
                ...$row,
                'id' => (string) Str::ulid(),
                'generic_assessment_result_version_id' => $otherSource->id,
                'assessment_participant_id' => $other->id,
                'assessment_attempt_id' => $other->assessment_attempt_id,
                'result_checksum' => $otherSource->result_checksum,
                'envelope_contract' => 'generic-assessment-result:v2',
            ]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Invalid direct outbox write was accepted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('generic_assessment_result_outbox', 1);
    }

    private function persist(
        AssessmentParticipant $assessment,
        int|float $iq = 99.125,
        int $version = 1,
        ?string $revokedAt = null,
    ): GenericAssessmentResultVersion {
        app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot([
            'assessmentAttemptId' => $assessment->assessment_attempt_id,
            'iq' => $iq,
            'engineVersion' => 'ist-2026.09.1',
            'completedAt' => '2026-09-05T10:15:30.123456+07:00',
            'finality' => 'FINALIZED',
            'revokedAt' => $revokedAt,
            'resultVersion' => $version,
        ]);

        return GenericAssessmentResultVersion::query()
            ->where('assessment_participant_id', $assessment->id)
            ->where('result_version', $version)
            ->sole();
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
        $attemptPublicId = (string) Str::ulid();
        $case = AssessmentBillingFixture::createExactIntegratedCase(
            $participant->id, $organization->id, $package->id, $attemptPublicId,
        );

        return AssessmentParticipant::query()->create([
            'organization_id' => $organization->id,
            'integration_client_id' => $client->id,
            'participant_id' => $participant->id,
            'package_id' => $package->id,
            'assessment_case_id' => $case,
            'assessment_attempt_id' => $attemptPublicId,
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
