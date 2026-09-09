<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Participant;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ParticipantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PaymentMethodSeeder::class);
        DB::table('payment_methods')->where('code', 'manual_transfer')->update(['is_active' => true]);
    }

    public function test_registration_screen_exposes_server_assigned_branch_and_versioned_consents(): void
    {
        $branch = $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);

        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registration/create')
                ->where('assignedBranch.name', $branch->name)
                ->where('consents.psychotest.version', 'draft-2026-08-25.2')
                ->where('consents.dass.version', 'draft-2026-09-08')
                ->where('consents.legalReviewPending', true)
                ->has('registrationToken')
            );
    }

    public function test_valid_input_creates_participant_and_versioned_consent_records(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        $branch = $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);
        $token = (string) Str::uuid();

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $this->validPayload($token))
            ->assertRedirect('/registration/received')
            ->assertSessionHas('registration.participant_id')
            ->assertSessionHas('registration.evidence_authorized_until');

        $participant = Participant::query()->sole();
        $this->assertSame($branch->id, $participant->branch_id);
        $this->assertSame($branch->id, $participant->referral_branch_id);
        $this->assertSame('default', $participant->referral_source);
        $this->assertSame('KAIGO', $participant->intended_field);
        $this->assertMatchesRegularExpression(
            '/^LSI-\d{6}-\d{6}-[A-Z0-9]{6}$/',
            (string) $participant->test_number,
        );
        $this->assertDatabaseHas('entitlements', [
            'participant_id' => $participant->id,
            'test_type' => 'ist',
            'status' => 'locked',
        ]);

        $order = Order::query()->with('assessmentCase')->sole();
        $case = $order->assessmentCase;
        $this->assertNotNull($case);
        $this->assertSame($order->public_id, $case->public_id);
        $this->assertSame($participant->id, $case->participant_id);
        $this->assertSame($branch->id, $case->organization_id);
        $this->assertSame($participant->package_id, $case->package_id);
        $this->assertSame('DIRECT_PUBLIC', $case->origin);
        $this->assertSame('KAIGO', $case->intended_field_snapshot);
        $this->assertTrue($order->created_at->equalTo($case->created_at));

        $this->assertDatabaseHas('consent_records', [
            'participant_id' => $participant->id,
            'consent_type' => 'psychotest',
            'status' => 'accepted',
            'document_version' => 'draft-2026-08-25.2',
            'consented_at' => '2026-08-25 10:00:00',
        ]);
        $this->assertDatabaseHas('consent_records', [
            'participant_id' => $participant->id,
            'consent_type' => 'dass',
            'status' => 'accepted',
            'document_version' => 'draft-2026-09-08',
            'consented_at' => '2026-08-25 10:00:00',
        ]);
    }

    public function test_invalid_input_and_missing_required_consent_are_rejected(): void
    {
        $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);
        $token = (string) Str::uuid();
        $payload = $this->validPayload($token);
        $payload['full_name'] = str_repeat('x', 201);
        $payload['gender'] = 'other';
        $payload['birth_date'] = 'tomorrow';
        $payload['phone'] = 'not-a-phone';
        $payload['intended_field'] = 'CLIENT_INJECTED';
        $payload['consent_psychotest'] = false;
        unset($payload['consent_dass']);

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertSessionHasErrors([
                'full_name',
                'gender',
                'birth_date',
                'phone',
                'intended_field',
                'consent_psychotest',
                'consent_dass',
            ]);

        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('consent_records', 0);
    }

    public function test_dass_consent_is_required_as_part_of_psychotest(): void
    {
        $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);
        $token = (string) Str::uuid();
        $payload = $this->validPayload($token);
        $payload['consent_dass'] = false;
        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertSessionHasErrors('consent_dass');

        $this->assertDatabaseCount('participants', 0);
    }

    public function test_referral_and_protected_fields_are_derived_server_side(): void
    {
        $default = $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);
        $referral = $this->branch('OSAKA', 'OSAKA-REF');
        $token = (string) Str::uuid();
        $payload = $this->validPayload($token) + [
            'branch_id' => $default->id,
            'referral_branch_id' => $default->id,
            'referral_source' => 'manual',
            'test_number' => 'ATTACKER-CONTROLLED',
            'consented_at' => '2000-01-01 00:00:00',
        ];
        $cookie = json_encode([
            'version' => 1,
            'ref_code' => $referral->ref_code,
            'source' => 'link',
            'expires_at' => now()->addDays(30)->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        $this->withCookie('psikotes_referral', $cookie)
            ->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertRedirect('/registration/received');

        $participant = Participant::query()->sole();
        $this->assertSame($referral->id, $participant->branch_id);
        $this->assertSame($referral->id, $participant->referral_branch_id);
        $this->assertSame('link', $participant->referral_source);
        $this->assertMatchesRegularExpression(
            '/^LSI-\d{6}-\d{6}-[A-Z0-9]{6}$/',
            (string) $participant->test_number,
        );
        $this->assertDatabaseMissing('consent_records', [
            'consented_at' => '2000-01-01 00:00:00',
        ]);
    }

    public function test_repeated_submission_with_the_same_token_is_idempotent(): void
    {
        $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);
        $token = (string) Str::uuid();
        $payload = $this->validPayload($token);

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertRedirect('/registration/received');
        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertRedirect('/registration/received');

        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('consent_records', 2);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('assessment_cases', 1);
    }

    public function test_replay_fails_closed_when_the_persisted_order_graph_is_incomplete(): void
    {
        $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);
        $token = (string) Str::uuid();
        $payload = $this->validPayload($token);

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertRedirect('/registration/received');
        $participant = Participant::query()->sole();
        $participant->entitlements()->where('test_type', 'ist')->delete();

        $this->withSession(['registration.token' => $token])
            ->from('/register')
            ->post('/registrations', $payload)
            ->assertRedirect('/register')
            ->assertSessionHasErrors('_registration_token');

        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('assessment_cases', 1);
    }

    public function test_registration_is_rate_limited_by_ip(): void
    {
        $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);

        foreach (range(1, 5) as $attempt) {
            $token = (string) Str::uuid();
            $payload = $this->validPayload($token);
            $payload['phone'] = '+6281234567'.str_pad((string) $attempt, 3, '0', STR_PAD_LEFT);

            $this->withSession(['registration.token' => $token])
                ->post('/registrations', $payload)
                ->assertRedirect('/registration/received');
        }

        $token = (string) Str::uuid();
        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $this->validPayload($token))
            ->assertTooManyRequests();
    }

    /** @return array<string, mixed> */
    private function validPayload(string $token): array
    {
        return [
            '_registration_token' => $token,
            'package_id' => $this->activePackage(),
            'payment_method_code' => 'manual_transfer',
            'full_name' => 'Ayu Pratiwi',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'email' => 'ayu@example.test',
            'consent_psychotest' => true,
            'consent_dass' => true,
        ];
    }

    private function activePackage(): int
    {
        $existing = DB::table('packages')->where('code', 'TEST-BATTERY')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        $packageId = DB::table('packages')->insertGetId([
            'code' => 'TEST-BATTERY',
            'name' => 'Paket Baterai Tes',
            'amount' => 350000,
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('package_items')->insert([
            'package_id' => $packageId,
            'test_type' => 'ist',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('package_items')->insert([
            'package_id' => $packageId,
            'test_type' => 'dass21',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $packageId;
    }

    private function branch(string $code, string $refCode, bool $isDefault = false): Branch
    {
        return Branch::query()->create([
            'code' => $code,
            'name' => "Branch {$code}",
            'ref_code' => $refCode,
            'is_default' => $isDefault,
        ]);
    }
}
