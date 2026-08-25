<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Participant;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class ParticipantLoginTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('participant_auth.jwt.secret', str_repeat('test-secret-', 4));
        Date::setTestNow('2026-08-25 10:00:00+07:00');
        $this->branch = Branch::query()->create([
            'code' => 'CENTRAL',
            'name' => 'LSI Pusat',
            'ref_code' => 'CENTRAL-REF',
            'is_default' => true,
        ]);
    }

    public function test_participant_can_login_with_test_number_and_birth_date(): void
    {
        $participant = $this->participant();

        $response = $this->postJson('/api/auth/participant/login', [
            'test_number' => strtolower((string) $participant->test_number),
            'birth_date' => '2001-04-15',
        ])->assertOk()->assertJsonStructure(['jwt']);

        $principal = app(ParticipantJwt::class)->verify((string) $response->json('jwt'));
        $this->assertSame($participant->id, $principal->participantId);
        $this->assertSame($this->branch->id, $principal->branchId);
    }

    public function test_invalid_credentials_use_the_same_generic_response(): void
    {
        $participant = $this->participant();

        $wrongBirthDate = $this->postJson('/api/auth/participant/login', [
            'test_number' => $participant->test_number,
            'birth_date' => '2000-01-01',
        ])->assertUnauthorized()->json();
        $unknownNumber = $this->postJson('/api/auth/participant/login', [
            'test_number' => 'LSI-202608-999999-ABCDEF',
            'birth_date' => '2000-01-01',
        ])->assertUnauthorized()->json();

        $this->assertSame($wrongBirthDate, $unknownNumber);
        $this->assertSame('INVALID_CREDENTIALS', data_get($unknownNumber, 'error.code'));
    }

    public function test_login_is_limited_to_five_attempts_per_minute_per_ip(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/auth/participant/login', [
                'test_number' => sprintf('LSI-202608-%06d-ABCDEF', $attempt),
                'birth_date' => '2000-01-01',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/participant/login', [
            'test_number' => 'LSI-202608-999999-ABCDEF',
            'birth_date' => '2000-01-01',
        ])->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_failed_attempts_progressively_lock_the_test_number(): void
    {
        $participant = $this->participant();
        $payload = ['test_number' => $participant->test_number, 'birth_date' => '2000-01-01'];

        $this->postJson('/api/auth/participant/login', $payload)->assertUnauthorized();
        $this->postJson('/api/auth/participant/login', $payload)->assertUnauthorized();
        $this->postJson('/api/auth/participant/login', $payload)->assertUnauthorized();
        $locked = $this->postJson('/api/auth/participant/login', [
            'test_number' => $participant->test_number,
            'birth_date' => '2001-04-15',
        ])->assertTooManyRequests()->assertJsonPath('error.code', 'CREDENTIAL_LOCKED');

        $this->assertGreaterThanOrEqual(1, (int) $locked->headers->get('Retry-After'));

        Date::setTestNow('2026-08-25 10:01:01+07:00');
        $this->postJson('/api/auth/participant/login', $payload)->assertUnauthorized();
        $longerLock = $this->postJson('/api/auth/participant/login', [
            'test_number' => $participant->test_number,
            'birth_date' => '2001-04-15',
        ])->assertTooManyRequests()->assertJsonPath('error.code', 'CREDENTIAL_LOCKED');

        $this->assertGreaterThanOrEqual(299, (int) $longerLock->headers->get('Retry-After'));
    }

    public function test_successful_login_clears_failed_attempt_history_before_lockout(): void
    {
        $participant = $this->participant();
        $wrong = ['test_number' => $participant->test_number, 'birth_date' => '2000-01-01'];
        $valid = ['test_number' => $participant->test_number, 'birth_date' => '2001-04-15'];

        $this->postJson('/api/auth/participant/login', $wrong)->assertUnauthorized();
        $this->postJson('/api/auth/participant/login', $wrong)->assertUnauthorized();
        $this->postJson('/api/auth/participant/login', $valid)->assertOk();
        Date::setTestNow('2026-08-25 10:01:01+07:00');
        $this->postJson('/api/auth/participant/login', $wrong)->assertUnauthorized();
        $this->postJson('/api/auth/participant/login', $valid)->assertOk();
    }

    private function participant(): Participant
    {
        return Participant::query()->create([
            'branch_id' => $this->branch->id,
            'referral_branch_id' => $this->branch->id,
            'referral_source' => 'default',
            'full_name' => 'Ayu Pratiwi',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'test_number' => 'LSI-202608-000001-ABCDEF',
        ]);
    }
}
