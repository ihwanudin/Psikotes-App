<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\AllocateAndStartAssessmentSession;
use App\Actions\AssessmentSessions\GetAssessmentSessionItems;
use App\Actions\AssessmentSessions\StartParticipantAssessmentSession;
use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocationPolicy;
use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
use App\Services\AssessmentSessions\ParticipantAssessmentSessionCandidates;
use App\Services\AssessmentSessions\RmibItemContentReader;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Database\Seeders\InstrumentSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

/**
 * F2 item-delivery Stage 2 continuation (2026-09-21), Lead's mandatory
 * test: proves the RMIB gender-track lock end to end, through the REAL
 * `RmibItemContentReader` (not a fake) at both the start gate and the
 * `/items` read -- a session's job labels must stay exactly what they were
 * at allocation, even after the participant's own profile changes.
 *
 * Deliberately separate from AllocateAndStartAssessmentSessionTest.php
 * (scoped there to "allocation mechanics, no HTTP binding") -- this test
 * spans allocation AND the read side together, which is the whole point
 * of the invariant being proven.
 */
final class RmibItemContentVariantLockTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private const NOW = '2026-09-21T00:00:00.000000+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());
    }

    public function test_a_locked_male_variant_survives_a_later_gender_change_and_items_still_shows_job_male(): void
    {
        $fixture = $this->participantGraph('male');

        $result = $this->action()->execute(
            new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
            GenericAssessmentInstrument::Rmib,
        );

        $session = DB::table('test_sessions')->where('public_id', $result->sessionId)->sole();
        $this->assertSame('male', $session->item_content_variant);

        // The participant's profile is corrected AFTER the session started.
        DB::table('participants')->where('id', $fixture['participant'])->update(['gender' => 'female']);

        $rawPayload = json_decode(
            (string) DB::table('instrument_versions')->where('code', 'rmib_items')->value('source_text'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        // GetAssessmentSessionItems::execute() owns its own service-context
        // elevation -- wrapping it in another runAsService() here would trip
        // RlsContextRunner's reentrancy guard.
        $items = $this->itemsAction()->execute($fixture['participant'], $result->sessionId);

        $this->assertTrue($items->accepted);
        $this->assertNotNull($items->content);
        // Still 'male' -- the locked value, not the participant's now-'female' profile.
        $this->assertSame($rawPayload['positions'][0]['job_male'], $items->content->subtests[0]['items'][0]['job']);
        $this->assertNotSame($rawPayload['positions'][0]['job_female'], $items->content->subtests[0]['items'][0]['job']);
    }

    public function test_start_is_rejected_and_no_session_or_grant_row_is_written_when_gender_is_null(): void
    {
        $fixture = $this->participantGraph(null);

        $this->expectException(AssessmentItemContentUnavailable::class);

        try {
            $this->action()->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Rmib,
            );
        } finally {
            $this->assertSame(0, DB::table('test_sessions')->where('participant_id', $fixture['participant'])->count());
            $this->assertSame(0, DB::table('test_session_grants')->where('participant_id', $fixture['participant'])->count());
        }
    }

    private function action(): StartParticipantAssessmentSession
    {
        $contexts = app(RlsContextRunner::class);

        return new StartParticipantAssessmentSession(
            $contexts,
            app(ParticipantAssessmentSessionCandidates::class),
            new AssessmentSessionSelectionPolicy,
            app(CaseAuthorizationResolver::class),
            new AllocateAndStartAssessmentSession(
                $contexts,
                app(CaseAuthorizationResolver::class),
                new class implements AssessmentSessionDefinitionAuthority
                {
                    public function issueForNewSession(
                        GenericAssessmentInstrument $instrument,
                        CaseAuthorization $authorization,
                        string $sessionPublicId,
                    ): SessionDefinition {
                        $payload = [
                            'instrument' => $instrument->value, 'version' => 'synthetic-v1',
                            'provenance' => 'rmib-variant-lock-test', 'total_duration_seconds' => 900,
                            'subtests' => [['code' => 'all', 'duration_seconds' => 900, 'item_count' => 108]],
                            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
                        ];
                        $payload['checksum'] = SessionDefinition::checksumFor($payload);

                        return SessionDefinition::fromArray($payload);
                    }
                },
                new RmibItemContentReader,
                new AssessmentAttemptAllocationPolicy,
                new AssessmentSessionStateMachine,
                new AssessmentSessionDeadlinePolicy,
                static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
            ),
        );
    }

    private function itemsAction(): GetAssessmentSessionItems
    {
        return new GetAssessmentSessionItems(
            app(RlsContextRunner::class),
            new RmibItemContentReader,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );
    }

    /** @return array{branch:int,participant:int,case:int,order:int,entitlement:int} */
    private function participantGraph(?string $gender): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $key, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => 'Synthetic', 'amount' => 99000,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        foreach (['dass21', 'rmib'] as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'package_id' => $package, 'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'manual', 'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Synthetic', 'phone' => '620000000000', 'gender' => $gender,
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'organization_id' => $branch,
            'package_id' => $package, 'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'assessment_case_id' => $case,
            'payment_method_id' => null, 'status' => 'paid', 'amount' => 0, 'currency' => 'IDR',
            'paid_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $entitlement = 0;
        foreach (['dass21', 'rmib'] as $type) {
            $id = DB::table('entitlements')->insertGetId([
                'participant_id' => $participant, 'order_id' => $order,
                'assessment_case_id' => $type === 'dass21' ? null : $case,
                'test_type' => $type, 'status' => 'ready', 'ready_at' => now()->subSecond(),
                'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
            if ($type === 'rmib') {
                $entitlement = $id;
            }
        }

        return compact('branch', 'participant', 'case', 'order', 'entitlement');
    }
}
