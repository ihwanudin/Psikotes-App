<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Models\Branch;
use App\Models\Participant;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PackageSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PaymentMethodSeeder::class);
        DB::table('payment_methods')->where('code', 'manual_transfer')->update(['is_active' => true]);
    }

    public function test_package_catalog_uses_normalized_test_items_and_idr_prices(): void
    {
        $this->assertTrue(Schema::hasColumns('packages', [
            'id',
            'code',
            'name',
            'description',
            'amount',
            'currency',
            'is_active',
        ]));
        $this->assertTrue(Schema::hasColumns('package_items', [
            'package_id',
            'test_type',
            'sort_order',
        ]));
        $this->assertTrue(Schema::hasColumn('participants', 'package_id'));

        $packageId = DB::table('packages')->insertGetId([
            'code' => 'IST-ONLY',
            'name' => 'Tes IST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('packages', [
            'id' => $packageId,
            'amount' => null,
            'currency' => 'IDR',
            'is_active' => false,
        ]);
    }

    public function test_registration_screen_only_exposes_active_priced_packages(): void
    {
        $this->branch();
        $active = $this->package('BATTERY', 350000, true, ['ist', 'papi', 'rmib', 'kraepelin']);
        $this->package('INACTIVE', 200000, false, ['ist']);
        $this->package('UNPRICED', null, false, ['papi']);

        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registration/create')
                ->has('packages', 1)
                ->where('packages.0.id', $active)
                ->where('packages.0.code', 'BATTERY')
                ->where('packages.0.amount', 350000)
                ->where('packages.0.currency', 'IDR')
                ->where('packages.0.testTypes', ['ist', 'papi', 'rmib', 'kraepelin'])
                ->where('packageConfigurationPending', false)
            );
    }

    public function test_registration_screen_fails_closed_when_no_package_is_ready(): void
    {
        $this->branch();
        $this->package('UNPRICED', null, false, ['ist']);

        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('packages', 0)
                ->where('packageConfigurationPending', true)
            );
    }

    public function test_registration_stores_the_selected_active_package(): void
    {
        $this->branch();
        $packageId = $this->package('IST-ONLY', 150000, true, ['ist']);
        $token = (string) Str::uuid();

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $this->validPayload($token, $packageId))
            ->assertRedirect('/registration/received');

        $this->assertSame($packageId, Participant::query()->sole()->package_id);
    }

    public function test_registration_rejects_inactive_unpriced_and_unknown_packages(): void
    {
        $this->branch();
        $inactive = $this->package('INACTIVE', 150000, false, ['ist']);
        $unpriced = $this->package('UNPRICED', null, false, ['papi']);

        foreach ([$inactive, $unpriced, 999999] as $packageId) {
            $token = (string) Str::uuid();

            $this->withSession(['registration.token' => $token])
                ->post('/registrations', $this->validPayload($token, $packageId))
                ->assertSessionHasErrors('package_id');
        }

        $this->assertDatabaseCount('participants', 0);
    }

    public function test_idempotency_token_cannot_be_reused_for_a_different_package(): void
    {
        $this->branch();
        $firstPackage = $this->package('IST-ONLY', 150000, true, ['ist']);
        $secondPackage = $this->package('PAPI-ONLY', 175000, true, ['papi']);
        $token = (string) Str::uuid();

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $this->validPayload($token, $firstPackage))
            ->assertRedirect('/registration/received');

        $this->withSession(['registration.token' => $token])
            ->post('/registrations', $this->validPayload($token, $secondPackage))
            ->assertSessionHasErrors('_registration_token');

        $this->assertDatabaseCount('participants', 1);
        $this->assertSame($firstPackage, Participant::query()->sole()->package_id);
    }

    /** @param list<string> $testTypes */
    private function package(string $code, ?int $amount, bool $active, array $testTypes): int
    {
        $packageId = DB::table('packages')->insertGetId([
            'code' => $code,
            'name' => "Package {$code}",
            'amount' => $amount,
            'currency' => 'IDR',
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($testTypes as $sortOrder => $testType) {
            DB::table('package_items')->insert([
                'package_id' => $packageId,
                'test_type' => $testType,
                'sort_order' => $sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $packageId;
    }

    /** @return array<string, mixed> */
    private function validPayload(string $token, int $packageId): array
    {
        return [
            '_registration_token' => $token,
            'package_id' => $packageId,
            'payment_method_code' => 'manual_transfer',
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
