<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Participant;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PaymentMethodSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch();
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_registration_only_exposes_active_payment_methods(): void
    {
        DB::table('payment_methods')->where('code', 'manual_transfer')->update(['is_active' => true]);

        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registration/create')
                ->has('paymentMethods', 1)
                ->where('paymentMethods.0.code', 'manual_transfer')
                ->where('paymentMethods.0.displayName', 'Transfer Manual')
                ->where('paymentConfigurationPending', false)
            );
    }

    public function test_registration_fails_closed_when_all_payment_methods_are_disabled(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('paymentMethods', 0)
                ->where('paymentConfigurationPending', true)
            );
    }

    public function test_forced_disabled_payment_method_code_is_rejected_with_stable_error(): void
    {
        $token = (string) Str::uuid();

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $this->validPayload($token, 'xendit'))
            ->assertSessionHasErrors([
                'payment_method_code' => 'Metode pembayaran tidak tersedia.',
            ]);

        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_active_method_creates_pending_order_and_links_locked_entitlement(): void
    {
        DB::table('payment_methods')->where('code', 'manual_transfer')->update(['is_active' => true]);
        $token = (string) Str::uuid();

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $this->validPayload($token, 'manual_transfer'))
            ->assertRedirect('/registration/received');

        $participant = Participant::query()->sole();
        $order = Order::query()->with('paymentMethod')->sole();
        $this->assertSame($participant->id, $order->participant_id);
        $this->assertSame('manual_transfer', $order->paymentMethod->code);
        $this->assertSame('pending', $order->status->value);
        $this->assertSame(150_000, $order->amount);
        $this->assertSame('IDR', $order->currency);
        $this->assertDatabaseHas('entitlements', [
            'participant_id' => $participant->id,
            'order_id' => $order->id,
            'test_type' => 'ist',
            'status' => 'locked',
        ]);
    }

    public function test_disabling_a_method_preserves_and_exposes_its_historical_order(): void
    {
        DB::table('payment_methods')->where('code', 'manual_transfer')->update(['is_active' => true]);
        $token = (string) Str::uuid();
        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $this->validPayload($token, 'manual_transfer'))
            ->assertRedirect('/registration/received');
        $order = Order::query()->sole();

        DB::table('payment_methods')->where('code', 'manual_transfer')->update(['is_active' => false]);

        $historical = Order::query()->with('paymentMethod')->findOrFail($order->id);
        $this->assertSame('manual_transfer', $historical->paymentMethod->code);
        $this->assertFalse($historical->paymentMethod->is_active);
    }

    /** @return array<string, mixed> */
    private function validPayload(string $token, string $paymentMethodCode): array
    {
        return [
            '_registration_token' => $token,
            'package_id' => $this->activePackage(),
            'payment_method_code' => $paymentMethodCode,
            'full_name' => 'Ayu Pratiwi',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'email' => 'ayu@example.test',
            'consent_psychotest' => true,
            'consent_dass' => false,
        ];
    }

    private function activePackage(): int
    {
        $packageId = DB::table('packages')->insertGetId([
            'code' => 'IST-ONLY-'.Str::random(8),
            'name' => 'Paket IST',
            'amount' => 150_000,
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

        return $packageId;
    }

    private function branch(): Branch
    {
        return Branch::query()->create([
            'code' => 'CENTRAL',
            'name' => 'LSI Pusat',
            'ref_code' => 'CENTRAL-REF',
            'is_default' => true,
        ]);
    }
}
