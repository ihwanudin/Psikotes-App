<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ParticipantOrderStatusAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('participant_auth.jwt.secret', 'base64:'.base64_encode(str_repeat('A', 32)));
        Date::setTestNow('2026-08-25 15:00:00+07:00');
        $this->branch = Branch::query()->create([
            'code' => 'CENTRAL',
            'name' => 'LSI Pusat',
            'ref_code' => 'CENTRAL-REF',
            'is_default' => true,
        ]);
        $this->method = new PaymentMethod;
        $this->method->forceFill([
            'code' => 'manual_transfer',
            'display_name' => 'Transfer Manual',
            'is_active' => true,
        ])->save();
    }

    public function test_jwt_order_status_is_derived_from_own_claim_only(): void
    {
        $first = $this->participant('Ayu Pratiwi', 'LSI-202608-000001-ABCDEF');
        $second = $this->participant('Bima Saputra', 'LSI-202608-000002-ABCDEF');
        $older = $this->order($first, 'pending', 250_000);
        $own = $this->order($first, 'paid', 350_000);
        $other = $this->order($second, 'rejected', 999_000, 'Dokumen peserta lain');

        $response = $this->withToken($this->token($first))
            ->getJson('/api/me/order?participant_id='.$second->id.'&order_id='.$other->public_id)
            ->assertOk();

        $response->assertJsonPath('data.public_id', $own->public_id)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount', 350_000)
            ->assertJsonPath('data.currency', 'IDR')
            ->assertJsonPath('data.payment_method.display_name', 'Transfer Manual')
            ->assertJsonMissing(['public_id' => $older->public_id])
            ->assertJsonMissing(['public_id' => $other->public_id])
            ->assertJsonMissing(['rejection_reason' => 'Dokumen peserta lain'])
            ->assertJsonMissingPath('data.participant_id')
            ->assertJsonMissingPath('data.gateway_ref');
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_jwt_order_status_requires_a_valid_participant_token(): void
    {
        $this->getJson('/api/me/order')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_registration_status_uses_active_server_session_and_ignores_query_ids(): void
    {
        $first = $this->participant('Ayu Pratiwi', 'LSI-202608-000001-ABCDEF');
        $second = $this->participant('Bima Saputra', 'LSI-202608-000002-ABCDEF');
        $own = $this->order($first, 'pending', 250_000);
        $other = $this->order($second, 'paid', 999_000);

        $response = $this->withSession([
            'registration.participant_id' => $first->id,
            'registration.evidence_authorized_until' => now()->addHour()->getTimestamp(),
        ])->get('/registration/order-status?participant_id='.$second->id.'&order_id='.$other->public_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registration/order-status')
                ->where('authorized', true)
                ->where('order.publicId', $own->public_id)
                ->where('order.status', 'pending')
                ->where('order.amount', 250_000)
                ->missing('order.participantId')
                ->missing('order.gatewayRef')
            );
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_registration_status_hides_data_when_session_is_missing_or_expired(): void
    {
        $participant = $this->participant('Ayu Pratiwi', 'LSI-202608-000001-ABCDEF');
        $this->order($participant, 'paid', 350_000);

        $this->get('/registration/order-status')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registration/order-status')
                ->where('authorized', false)
                ->where('order', null)
            );

        $this->withSession([
            'registration.participant_id' => $participant->id,
            'registration.evidence_authorized_until' => now()->subSecond()->getTimestamp(),
        ])->get('/registration/order-status')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('authorized', false)
                ->where('order', null)
            );
    }

    private function participant(string $name, string $testNumber): Participant
    {
        return Participant::query()->create([
            'branch_id' => $this->branch->id,
            'referral_branch_id' => $this->branch->id,
            'referral_source' => 'default',
            // These authorization fixtures cover the retained non-direct order status endpoint.
            // A direct-public participant would correctly require an exact assessment case.
            'source_system' => 'STATUS_AUTH_TEST',
            'full_name' => $name,
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'test_number' => $testNumber,
        ]);
    }

    private function order(
        Participant $participant,
        string $status,
        int $amount,
        ?string $rejectionReason = null,
    ): Order {
        return Order::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'payment_method_id' => $this->method->id,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'IDR',
            'paid_at' => $status === 'paid' ? now() : null,
            'rejection_reason' => $rejectionReason,
        ]);
    }

    private function token(Participant $participant): string
    {
        return app(ParticipantJwt::class)->issue($participant->id, $participant->branch_id);
    }
}
