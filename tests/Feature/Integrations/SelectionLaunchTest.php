<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Participant;
use App\Models\SelectionParticipant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SelectionLaunchTest extends TestCase
{
    use RefreshDatabase;

    private const string CLIENT_ID = 'selection-app';

    private const string CLIENT_SECRET = 'test-selection-secret-with-at-least-32-bytes';

    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-29 11:00:00+07:00');
        config()->set('selection_integration.enabled', true);
        config()->set('selection_integration.client_id', self::CLIENT_ID);
        config()->set('selection_integration.client_secret', self::CLIENT_SECRET);
        config()->set('selection_integration.selection_base_url', 'https://seleksi.example.test');
        config()->set('selection_integration.timeout_seconds', 5);
        config()->set('participant_auth.jwt.secret', 'base64:'.base64_encode(str_repeat('P', 32)));

        [$this->participant] = app(RlsContextRunner::class)->run(
            new RlsContext('service'),
            function (): array {
                $branch = Branch::query()->create([
                    'code' => 'BEASISWA',
                    'name' => 'Program Beasiswa Jepang',
                    'ref_code' => 'BEASISWA-JEPANG',
                    'is_default' => true,
                    'is_active' => true,
                ]);
                $participant = Participant::query()->create([
                    'branch_id' => $branch->id,
                    'referral_branch_id' => $branch->id,
                    'referral_source' => 'manual',
                    'full_name' => 'Ayu Pratiwi',
                    'gender' => 'female',
                    'birth_date' => '2001-04-15',
                    'education_level' => 'SMA/SMK',
                    'intended_field' => 'UMUM',
                    'phone' => '+6281234567890',
                    'email' => 'ayu@example.test',
                    'test_number' => 'LSI-202608-000001-ABCDEF',
                ]);
                $case = AssessmentCase::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'participant_id' => $participant->id,
                    'organization_id' => $branch->id,
                    'package_id' => null,
                    'origin' => 'LEGACY_SELECTION',
                    'intended_field_snapshot' => 'UMUM',
                ]);
                SelectionParticipant::query()->create([
                    'client_id' => self::CLIENT_ID,
                    'external_candidate_id' => '01K3TESTCANDIDATE000000001',
                    'selection_round_id' => '01K3TESTROUND0000000000001',
                    'registration_id' => 'REG-2026-0001',
                    'participant_id' => $participant->id,
                    'assessment_case_id' => $case->id,
                    'idempotency_key' => 'psychotest-participant:v1:01K3TESTCANDIDATE000000001',
                    'request_hash' => hash('sha256', 'fixture'),
                ]);

                return [$participant];
            },
        );
    }

    public function test_launch_consumes_the_single_use_ticket_and_bootstraps_participant_session(): void
    {
        Http::fake(function (Request $request) {
            $body = $request->body();
            $timestamp = (string) $request->header('X-Timestamp')[0];
            $expected = hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), self::CLIENT_SECRET);

            $this->assertSame('https://seleksi.example.test/api/v1/integrations/psychotest/launch-tickets/consume', $request->url());
            $this->assertSame(self::CLIENT_ID, $request->header('X-Client-Id')[0]);
            $this->assertSame($expected, $request->header('X-Signature')[0]);
            $this->assertSame(['ticket' => 'signed-selection-ticket'], $request->data());

            return Http::response(['data' => [
                'candidateId' => '01K3TESTCANDIDATE000000001',
                'selectionRoundId' => '01K3TESTROUND0000000000001',
                'participantId' => (string) $this->participant->id,
            ]]);
        });

        $response = $this->get('/selection/launch?ticket=signed-selection-ticket')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('sessionStorage.setItem', false)
            ->assertDontSee('signed-selection-ticket');

        preg_match('/const participantToken = ("[^"]+");/', $response->getContent(), $matches);
        $this->assertCount(2, $matches);
        $principal = app(ParticipantJwt::class)->verify(json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($this->participant->id, $principal->participantId);
    }

    public function test_provider_identity_must_match_the_local_provisioning_ledger(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            'candidateId' => 'DIFFERENT-CANDIDATE',
            'selectionRoundId' => '01K3TESTROUND0000000000001',
            'participantId' => (string) $this->participant->id,
        ]])]);

        $this->get('/selection/launch?ticket=signed-selection-ticket')
            ->assertUnprocessable()
            ->assertSee('Tautan psikotes tidak dapat diverifikasi.');
    }

    public function test_consumed_or_rejected_ticket_shows_a_safe_error_without_provider_details(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'PSYCHOTEST_TICKET_ALREADY_CONSUMED', 'message' => 'internal provider detail'],
        ], 409)]);

        $this->get('/selection/launch?ticket=signed-selection-ticket')
            ->assertStatus(409)
            ->assertSee('Tautan psikotes sudah digunakan atau tidak lagi berlaku.')
            ->assertDontSee('internal provider detail');
    }

    public function test_ticket_is_required_and_never_sent_to_an_unconfigured_provider(): void
    {
        Http::fake();

        $this->get('/selection/launch')
            ->assertUnprocessable()
            ->assertSee('Tautan psikotes tidak lengkap.');

        Http::assertNothingSent();
    }

    public function test_participant_lobby_is_available_without_exposing_server_data(): void
    {
        $this->get('/participant/lobby')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('participant/lobby')
                ->missing('participantToken')
            );
    }
}
