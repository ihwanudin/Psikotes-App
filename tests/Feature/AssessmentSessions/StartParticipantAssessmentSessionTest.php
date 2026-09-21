<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\AllocateAndStartAssessmentSession;
use App\Actions\AssessmentSessions\AssessmentSessionAllocationResult;
use App\Actions\AssessmentSessions\StartParticipantAssessmentSession;
use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocationPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
use App\Services\AssessmentSessions\ParticipantAssessmentSessionCandidates;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AlwaysAvailableAssessmentItemContentAuthority;

final class StartParticipantAssessmentSessionTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private const NOW = '2026-09-10T02:00:00.123456+00:00';

    public function test_command_contract_accepts_only_a_typed_participant_and_generic_instrument(): void
    {
        $method = new ReflectionMethod(StartParticipantAssessmentSession::class, 'execute');
        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame(ParticipantPrincipal::class, (string) $parameters[0]->getType());
        $this->assertSame(GenericAssessmentInstrument::class, (string) $parameters[1]->getType());
        $this->assertSame(AssessmentSessionAllocationResult::class, (string) $method->getReturnType());

        $commandSource = file_get_contents(app_path('Actions/AssessmentSessions/StartParticipantAssessmentSession.php'));
        $this->assertIsString($commandSource);
        $this->assertSame(1, substr_count($commandSource, '->runAsService('));

        $allocator = new \ReflectionClass(AllocateAndStartAssessmentSession::class);
        $this->assertFalse($allocator->hasMethod('execute'));
        $integrated = $allocator->getMethod('executeIntegrated')->getParameters();
        $this->assertSame(AssessmentPrincipal::class, (string) $integrated[0]->getType());
    }

    public function test_zero_candidates_fail_closed_without_writes_or_context_leak(): void
    {
        $principal = $this->participantWithoutCase();
        $authority = new StartParticipantDefinitionAuthority;

        try {
            $this->command($authority)->execute($principal, GenericAssessmentInstrument::Ist);
            $this->fail('A participant without a candidate was allocated.');
        } catch (InvalidAssessmentSessionState $exception) {
            $this->assertSame('UNAVAILABLE', $exception->getMessage());
            $this->assertDatabaseCount('test_sessions', 0);
            $this->assertDatabaseCount('test_session_grants', 0);
            $this->assertSame(0, $authority->calls);
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_multiple_ready_candidates_fail_closed_without_silent_fifo(): void
    {
        $principal = $this->participantWithReadyCases(2);
        $authority = new StartParticipantDefinitionAuthority;

        try {
            $this->command($authority)->execute($principal, GenericAssessmentInstrument::Ist);
            $this->fail('Ambiguous ready cases were silently ordered.');
        } catch (InvalidAssessmentSessionState $exception) {
            $this->assertSame('SELECTION_AMBIGUOUS', $exception->getMessage());
            $this->assertDatabaseCount('test_sessions', 0);
            $this->assertSame(0, $authority->calls);
            $this->assertNull(app(RlsContextRunner::class)->current());
        }
    }

    public function test_existing_rls_context_is_rejected_before_command_sql(): void
    {
        $command = $this->command(new StartParticipantDefinitionAuthority);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            app(RlsContextRunner::class)->run(
                new RlsContext('participant', 1, 1),
                fn () => $command->execute(new ParticipantPrincipal(1, 1), GenericAssessmentInstrument::Ist),
            );
            $this->fail('An active RLS context entered the participant command.');
        } catch (LogicException) {
            $this->assertSame([], $queries);
        }
    }

    public function test_existing_transaction_is_rejected_before_command_sql(): void
    {
        $command = $this->command(new StartParticipantDefinitionAuthority);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            DB::transaction(
                fn () => $command->execute(new ParticipantPrincipal(1, 1), GenericAssessmentInstrument::Ist),
            );
            $this->fail('An active transaction entered the participant command.');
        } catch (LogicException) {
            $this->assertSame([], $queries);
        }
    }

    private function participantWithoutCase(): ParticipantPrincipal
    {
        [$branch, $package] = $this->branchAndPackage();
        $participant = DB::table('participants')->insertGetId([
            'package_id' => $package,
            'branch_id' => $branch,
            'referral_branch_id' => $branch,
            'referral_source' => 'manual',
            'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Synthetic',
            'intended_field' => 'KAIGO',
            'phone' => '620000000000',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return new ParticipantPrincipal($participant, $branch);
    }

    private function participantWithReadyCases(int $count): ParticipantPrincipal
    {
        DB::statement('DROP INDEX IF EXISTS entitlements_participant_id_test_type_unique');
        [$branch, $package] = $this->branchAndPackage();
        $participant = DB::table('participants')->insertGetId([
            'package_id' => $package,
            'branch_id' => $branch,
            'referral_branch_id' => $branch,
            'referral_source' => 'manual',
            'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Synthetic',
            'intended_field' => 'KAIGO',
            'phone' => '620000000000',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        for ($index = 0; $index < $count; $index++) {
            $publicId = (string) Str::ulid();
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => $publicId,
                'participant_id' => $participant,
                'organization_id' => $branch,
                'package_id' => $package,
                'origin' => 'DIRECT_PUBLIC',
                'intended_field_snapshot' => 'KAIGO',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            $order = DB::table('orders')->insertGetId([
                'public_id' => $publicId,
                'participant_id' => $participant,
                'assessment_case_id' => $case,
                'status' => 'paid',
                'amount' => 99000,
                'currency' => 'IDR',
                'paid_at' => self::NOW,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            DB::table('entitlements')->insert([
                'participant_id' => $participant,
                'order_id' => $order,
                'assessment_case_id' => $case,
                'test_type' => 'ist',
                'status' => 'ready',
                'ready_at' => now()->subSecond(),
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            if ($index === 0) {
                DB::table('entitlements')->insert([
                    'participant_id' => $participant,
                    'order_id' => $order,
                    'assessment_case_id' => null,
                    'test_type' => 'dass21',
                    'status' => 'ready',
                    'ready_at' => now()->subSecond(),
                    'created_at' => self::NOW,
                    'updated_at' => self::NOW,
                ]);
            }
        }

        return new ParticipantPrincipal($participant, $branch);
    }

    /** @return array{int,int} */
    private function branchAndPackage(): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key,
            'name' => $key,
            'ref_code' => $key,
            'organization_code' => $key,
            'display_name' => $key,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key,
            'name' => 'Synthetic',
            'amount' => 99000,
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach (['dass21', 'ist'] as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package,
                'test_type' => $type,
                'sort_order' => $sort,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }

        return [$branch, $package];
    }

    private function command(StartParticipantDefinitionAuthority $authority): StartParticipantAssessmentSession
    {
        $allocator = new AllocateAndStartAssessmentSession(
            app(RlsContextRunner::class),
            app(CaseAuthorizationResolver::class),
            $authority,
            new AlwaysAvailableAssessmentItemContentAuthority,
            new AssessmentAttemptAllocationPolicy,
            new AssessmentSessionStateMachine,
            new AssessmentSessionDeadlinePolicy,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );

        return new StartParticipantAssessmentSession(
            app(RlsContextRunner::class),
            app(ParticipantAssessmentSessionCandidates::class),
            new AssessmentSessionSelectionPolicy,
            app(CaseAuthorizationResolver::class),
            $allocator,
        );
    }
}

final class StartParticipantDefinitionAuthority implements AssessmentSessionDefinitionAuthority
{
    public int $calls = 0;

    public function issueForNewSession(
        GenericAssessmentInstrument $instrument,
        CaseAuthorization $authorization,
        string $sessionPublicId,
    ): SessionDefinition {
        $this->calls++;

        return SessionDefinition::fromArray([
            'instrument' => $instrument->value,
            'version' => 'synthetic-v1',
            'provenance' => 'start-participant-feature-test',
            'total_duration_seconds' => 600,
            'sections' => [[
                'key' => 'main',
                'title' => 'Main',
                'instructions' => 'Synthetic',
                'duration_seconds' => 600,
                'item_refs' => ['item-1'],
            ]],
            'items' => [[
                'ref' => 'item-1',
                'section_key' => 'main',
                'type' => 'single_choice',
                'prompt' => 'Synthetic?',
                'options' => [['value' => 'A', 'label' => 'A']],
                'required' => true,
            ]],
        ]);
    }
}
