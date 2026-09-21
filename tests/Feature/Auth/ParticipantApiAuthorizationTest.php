<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DirectPublicPaymentFixture;
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
        $this->createCaseScopedEntitlement($first, 'ist', 'locked');
        $this->createCaseScopedEntitlement($second, 'dass21', 'ready');
        $token = $this->token($first);

        $profile = $this->withToken($token)->getJson('/api/me')->assertOk();
        $profile->assertJsonPath('data.test_number', $first->test_number)
            ->assertJsonMissing(['test_number' => $second->test_number])
            ->assertJsonMissingPath('data.birth_date')
            ->assertJsonMissingPath('data.phone');

        $entitlements = $this->withToken($token)->getJson('/api/me/entitlements')->assertOk();
        $entitlements->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.test_type', 'ist')
            ->assertJsonMissing(['test_type' => 'dass21']);
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

    // F2 S5 (2026-09-21): test_locked_entitlement_cannot_start_a_session moved to
    // tests/Feature/AssessmentSessions/StartParticipantSessionHttpTest.php as
    // test_locked_entitlement_is_rejected_with_403. It used to assert
    // 403 ENTITLEMENT_LOCKED, produced by this controller's own
    // ParticipantEntitlementGate::assertReady() check. ADR-0030 removes that
    // check entirely (the controller must not read entitlements at all); the
    // real command now rejects a locked entitlement with the generic
    // 403 ASSESSMENT_NOT_AVAILABLE bucket, by design (ADR-0030: "... yang
    // missing, foreign, revoked, stale, belum siap ... memakai pesan generik
    // dan tanpa details" -- entitlement state is explicitly one of the
    // conditions this bucket covers). This is a genuine contract change, not a
    // relocation of an unchanged assertion; confirmed with the coordinator
    // before moving. The other three tests in this file (/api/me,
    // /api/me/entitlements, token tampering) are unrelated to session-start
    // and unchanged.

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

    private function createCaseScopedEntitlement(Participant $participant, string $testType, string $status): Entitlement
    {
        $publicId = (string) Str::ulid();
        $case = DirectPublicPaymentFixture::caseFor($participant, $this->branch, $publicId, 100);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'method-'.$publicId,
            'display_name' => 'Synthetic payment method',
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'public_id' => $publicId,
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'payment_method_id' => $method,
            'status' => 'paid',
            'amount' => 100,
            'currency' => 'IDR',
            'paid_at' => now(),
        ]);

        return Entitlement::query()->create([
            'participant_id' => $participant->id,
            'order_id' => $order->id,
            'assessment_case_id' => $testType === 'dass21' ? null : $case->id,
            'test_type' => $testType,
            'status' => $status,
            'ready_at' => $status === 'ready' ? now() : null,
        ]);
    }
}
