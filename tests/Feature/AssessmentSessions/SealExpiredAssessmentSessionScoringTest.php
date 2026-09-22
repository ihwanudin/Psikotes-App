<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

/**
 * ADR-0032 §1(b)/PR2 (2026-09-23), psychologist P4: an expired session is
 * scored inside the SAME transaction as its own `expired` seal -- proven
 * here directly against `SealExpiredAssessmentSession`, the one class both
 * the sweep command and autosave/submit's lazy expiry discovery share (see
 * `SweepExpiredAssessmentSessionsTest`/`SweepExpiredAssessmentSessionsConcurrencyTest`
 * for the sweep's own candidate-selection/race coverage, which deliberately
 * stays instrument-agnostic via kraepelin fixtures).
 */
final class SealExpiredAssessmentSessionScoringTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    public function test_an_expired_papi_session_with_incomplete_answers_still_seals_with_a_failed_to_score_attempt_row(): void
    {
        // Mirrors SubmitAssessmentSessionTest's analogous submit-path test:
        // PAPI's all-or-nothing completeness policy is untouched by
        // ADR-0032 -- only 2 of the 3 defined items are answered, so
        // scoring is rejected as predictable (INCOMPLETE_ANSWERS), and per
        // ADR-0032 §1's atomicity requirement that must NOT roll back the
        // expiry seal itself.
        [$sessionId, $publicId] = $this->buildInProgressSession('papi', itemCount: 3, answeredItemNos: [1, 2]);

        app(RlsContextRunner::class)->runAsService(function () use ($sessionId): void {
            DB::transaction(function () use ($sessionId): void {
                app(SealExpiredAssessmentSession::class)->sealWithinTransaction(
                    $sessionId,
                    new DateTimeImmutable('2026-09-23T04:00:00.000001+00:00'),
                );
            });
        });

        $session = DB::table('test_sessions')->where('id', $sessionId)->first();
        $this->assertSame('expired', $session->status);
        $this->assertNotNull($session->expired_at);
        $this->assertNull($session->submitted_at);
        $this->assertDatabaseMissing('generic_instrument_results', ['session_id' => $sessionId]);
        $attempt = DB::table('assessment_scoring_attempts')->where('session_id', $sessionId)->sole();
        $this->assertSame('failed_to_score', $attempt->outcome);
        $this->assertSame('INCOMPLETE_ANSWERS', $attempt->reason_code);
        $this->assertNull($attempt->result_public_id);
        $this->assertSame('papi', $attempt->instrument_code);
        $this->assertSame(strtoupper($publicId), $attempt->session_public_id);
    }

    public function test_kraepelin_expiry_never_calls_the_scorer(): void
    {
        // ScoreAssessmentSession::scores() gates Kraepelin out entirely --
        // proven by a session with NO session_definition_payload at all:
        // if the scorer were ever invoked, LoadSealedGenericAnswerSet would
        // throw SEALED_GENERIC_ANSWER_INVALID (not the predictable
        // INCOMPLETE code), which is not caught and would roll back the
        // seal. The seal succeeding at all is the proof.
        $key = (string) Str::ulid();
        $participant = $this->participant($key);
        $sessionId = DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant, 'test_type' => 'kraepelin',
            'attempt_no' => 1, 'authorization_id' => 'synthetic-auth-'.$key,
            'allocation_intent_id' => 'synthetic-allocation-'.$key, 'duration_seconds' => 750,
            'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-23 03:00:00.000000+00:00', 'ends_at' => '2026-09-23 04:00:00.000000+00:00',
        ]);

        app(RlsContextRunner::class)->runAsService(function () use ($sessionId): void {
            DB::transaction(function () use ($sessionId): void {
                app(SealExpiredAssessmentSession::class)->sealWithinTransaction(
                    $sessionId,
                    new DateTimeImmutable('2026-09-23T04:00:00.000001+00:00'),
                );
            });
        });

        $this->assertSame('expired', DB::table('test_sessions')->where('id', $sessionId)->value('status'));
        $this->assertDatabaseMissing('assessment_scoring_attempts', ['session_id' => $sessionId]);
        $this->assertDatabaseMissing('generic_instrument_results', ['session_id' => $sessionId]);
    }

    /** @param list<int> $answeredItemNos */
    private function buildInProgressSession(string $testType, int $itemCount, array $answeredItemNos): array
    {
        $key = (string) Str::ulid();
        $participant = $this->participant($key);
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'organization_id' => DB::table('participants')->where('id', $participant)->value('branch_id'),
            'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'created_at' => '2026-09-23 03:00:00.000000+00:00', 'updated_at' => '2026-09-23 03:00:00.000000+00:00',
        ]);
        $definitionSource = [
            'instrument' => $testType, 'version' => 'synthetic-v1', 'provenance' => 'synthetic-test-only',
            'total_duration_seconds' => 3600,
            'subtests' => [
                ['code' => 'A', 'duration_seconds' => 1200, 'item_count' => 1],
                ['code' => 'B', 'duration_seconds' => 2400, 'item_count' => $itemCount - 1],
            ],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = [...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource)];
        $sessionId = DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => $testType,
            'attempt_no' => 1, 'authorization_id' => 'synthetic-auth-'.$key,
            'allocation_intent_id' => 'synthetic-allocation-'.$key, 'duration_seconds' => 3600,
            'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-23 03:00:00.000000+00:00', 'ends_at' => '2026-09-23 04:00:00.000000+00:00',
            'session_definition_version' => $definition['version'],
            'session_definition_provenance' => $definition['provenance'],
            'session_definition_checksum' => $definition['checksum'],
            'session_definition_payload' => json_encode($definition, JSON_THROW_ON_ERROR),
        ]);
        $publicId = (string) DB::table('test_sessions')->where('id', $sessionId)->value('public_id');

        DB::table('assessment_autosave_mutations')->insert([
            'session_id' => $sessionId, 'mutation_id' => (string) Str::ulid(), 'revision' => 1,
            'request_hash' => hash('sha256', $key), 'accepted_item_numbers' => json_encode($answeredItemNos, JSON_THROW_ON_ERROR),
            'received_at' => '2026-09-23 03:10:00.000000+00:00', 'created_at' => '2026-09-23 03:10:00.000000+00:00',
        ]);
        foreach ($answeredItemNos as $itemNo) {
            DB::table('answers')->insert([
                'session_id' => $sessionId, 'item_no' => $itemNo, 'value' => json_encode('A', JSON_THROW_ON_ERROR),
                'revision' => 1, 'answered_at' => '2026-09-23 03:10:00.123456+00:00',
                'created_at' => '2026-09-23 03:10:00.123456+00:00', 'updated_at' => '2026-09-23 03:10:00.123456+00:00',
            ]);
        }
        DB::table('test_sessions')->where('id', $sessionId)->update(['answers_revision' => 1]);

        return [$sessionId, $publicId];
    }

    private function participant(string $key): int
    {
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
    }
}
