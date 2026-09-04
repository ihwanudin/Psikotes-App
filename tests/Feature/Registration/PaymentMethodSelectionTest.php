<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Participant;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        Date::setTestNow('2026-08-25 13:00:00+07:00');
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

    public function test_registration_token_cannot_be_reused_for_another_payment_method(): void
    {
        DB::table('payment_methods')->update(['is_active' => true]);
        $token = (string) Str::uuid();
        $payload = $this->validPayload($token, 'manual_transfer');

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertRedirect('/registration/received');

        $payload['payment_method_code'] = 'xendit';
        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertSessionHasErrors('_registration_token');

        $order = Order::query()->with('paymentMethod')->sole();
        $this->assertSame('manual_transfer', $order->paymentMethod->code);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_active_xendit_registration_creates_invoice_and_redirects_to_hosted_checkout(): void
    {
        config()->set('services.xendit.secret_key', 'xnd_test_secret');
        DB::table('payment_methods')->where('code', 'xendit')->update(['is_active' => true]);
        Http::preventStrayRequests();
        $token = (string) Str::uuid();
        $payload = $this->validPayload($token, 'xendit');

        Http::fake(function (Request $request) {
            $response = [
                'id' => 'invoice-registration-1',
                'external_id' => $request['external_id'],
                'status' => 'PENDING',
                'amount' => 150_000,
                'currency' => 'IDR',
                'invoice_url' => 'https://invoice.xendit.co/invoice-registration-1',
                'expiry_date' => '2026-08-26T13:00:00+07:00',
                'created' => '2026-08-25T13:00:00+07:00',
                'updated' => '2026-08-25T13:00:00+07:00',
            ];

            return Http::response($response);
        });

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertRedirect('https://invoice.xendit.co/invoice-registration-1');

        $order = Order::query()->sole();
        $this->assertSame('invoice-registration-1', $order->gateway_ref);
        $this->assertSame('https://invoice.xendit.co/invoice-registration-1', $order->invoice_url);
        $this->assertNotNull($order->expires_at);
        $this->assertSame('pending', $order->status->value);
        $this->assertDatabaseHas('entitlements', [
            'order_id' => $order->id,
            'status' => 'locked',
        ]);

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertRedirect('https://invoice.xendit.co/invoice-registration-1');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_unknown_xendit_outcome_preserves_pending_registration_for_safe_reconciliation(): void
    {
        config()->set('services.xendit.secret_key', 'xnd_test_secret');
        DB::table('payment_methods')->where('code', 'xendit')->update(['is_active' => true]);
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.xendit.co/*')
            ->pushFailedConnection('create timeout')
            ->pushFailedConnection('status fallback timeout');
        $token = (string) Str::uuid();
        $payload = $this->validPayload($token, 'xendit');

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertRedirect('/registration/received');

        $order = Order::query()->sole();
        $this->assertSame('pending', $order->status->value);
        $this->assertNull($order->gateway_ref);
        $this->assertNull($order->invoice_url);
        $this->assertSame('unknown', $order->metadata['xendit_invoice']['state']);
        $this->assertDatabaseHas('entitlements', [
            'order_id' => $order->id,
            'status' => 'locked',
        ]);

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $payload)
            ->assertRedirect('/registration/received');
        Http::assertSentCount(2);
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
            'consent_dass' => true,
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
        DB::table('package_items')->insert([
            'package_id' => $packageId,
            'test_type' => 'dass21',
            'sort_order' => 1,
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
