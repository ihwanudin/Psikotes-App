<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Participant;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class ParticipantApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('participant_auth.jwt.secret', 'base64:'.base64_encode(str_repeat('A', 32)));
        Date::setTestNow('2026-08-25 10:00:00+07:00');
        $this->branch = Branch::query()->create([
            'code' => 'CENTRAL',
            'name' => 'LSI Pusat',
            'ref_code' => 'CENTRAL-REF',
            'is_default' => true,
        ]);
    }

    public function test_missing_or_tampered_token_is_rejected(): void
    {
        $participant = $this->participant('Ayu Pratiwi', 'LSI-202608-000001-ABCDEF');
        $token = $this->token($participant);
        [$header, $payload, $signature] = explode('.', $token);
        $tampered = $header.'.'.substr($payload, 0, -1).($payload[-1] === 'A' ? 'B' : 'A').'.'.$signature;

        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
        $this->withToken($tampered)->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_profile_and_entitlements_are_derived_only_from_own_claim(): void
    {
        $first = $this->participant('Ayu Pratiwi', 'LSI-202608-000001-ABCDEF');
        $second = $this->participant('Bima Saputra', 'LSI-202608-000002-ABCDEF');
        Entitlement::query()->create(['participant_id' => $first->id, 'test_type' => 'ist', 'status' => 'locked']);
        Entitlement::query()->create(['participant_id' => $second->id, 'test_type' => 'papi', 'status' => 'ready']);
        $token = $this->token($first);

        $profile = $this->withToken($token)->getJson('/api/me')->assertOk();
        $profile->assertJsonPath('data.test_number', $first->test_number)
            ->assertJsonMissing(['test_number' => $second->test_number])
            ->assertJsonMissingPath('data.birth_date')
            ->assertJsonMissingPath('data.phone');

        $entitlements = $this->withToken($token)->getJson('/api/me/entitlements')->assertOk();
        $entitlements->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.test_type', 'ist')
            ->assertJsonMissing(['test_type' => 'papi']);
    }

    public function test_deleted_participant_token_is_rejected(): void
    {
        $participant = $this->participant('Ayu Pratiwi', 'LSI-202608-000001-ABCDEF');
        $token = $this->token($participant);
        $participant->delete();

        $this->withToken($token)->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_locked_entitlement_cannot_start_a_session(): void
    {
        $participant = $this->participant('Ayu Pratiwi', 'LSI-202608-000001-ABCDEF');
        Entitlement::query()->create(['participant_id' => $participant->id, 'test_type' => 'ist', 'status' => 'locked']);

        $this->withToken($this->token($participant))
            ->postJson('/api/sessions/ist/start')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ENTITLEMENT_LOCKED');

        $this->assertDatabaseHas('entitlements', [
            'participant_id' => $participant->id,
            'test_type' => 'ist',
            'status' => 'locked',
        ]);
    }

    private function participant(string $name, string $testNumber): Participant
    {
        return Participant::query()->create([
            'branch_id' => $this->branch->id,
            'referral_branch_id' => $this->branch->id,
            'referral_source' => 'default',
            'full_name' => $name,
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'test_number' => $testNumber,
        ]);
    }

    private function token(Participant $participant): string
    {
        return app(ParticipantJwt::class)->issue($participant->id, $participant->branch_id);
    }
}
