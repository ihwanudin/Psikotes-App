<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentOperationsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_and_operational_tables_exist(): void
    {
        foreach (['payment_methods', 'orders', 'entitlements', 'audit_logs', 'outbox_messages'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('payment_methods', ['code', 'display_name', 'is_active']));
        $this->assertTrue(Schema::hasColumns('orders', [
            'participant_id',
            'payment_method_id',
            'status',
            'amount',
            'gateway_ref',
            'invoice_url',
            'expires_at',
            'paid_at',
        ]));
    }

    public function test_payment_channels_are_seeded_inactive_and_idempotent(): void
    {
        $this->seed(PaymentMethodSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $this->assertSame([
            ['code' => 'manual_transfer', 'is_active' => 0],
            ['code' => 'xendit', 'is_active' => 0],
        ], DB::table('payment_methods')->orderBy('code')->get(['code', 'is_active'])->map(fn (object $row): array => [
            'code' => $row->code,
            'is_active' => (int) $row->is_active,
        ])->all());
    }

    public function test_reseeding_does_not_disable_an_activated_channel(): void
    {
        $this->seed(PaymentMethodSeeder::class);
        DB::table('payment_methods')->where('code', 'xendit')->update(['is_active' => true]);

        $this->seed(PaymentMethodSeeder::class);

        $this->assertDatabaseHas('payment_methods', [
            'code' => 'xendit',
            'is_active' => true,
        ]);
    }

    public function test_disabling_a_payment_method_preserves_historical_orders(): void
    {
        [$participantId, $methodId] = $this->seedParticipantAndPaymentMethod();

        $orderId = DB::table('orders')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participantId,
            'payment_method_id' => $methodId,
            'status' => 'pending',
            'amount' => 500000,
            'currency' => 'IDR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_methods')->where('id', $methodId)->update(['is_active' => false]);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_method_id' => $methodId]);
    }

    public function test_gateway_references_are_unique_when_present(): void
    {
        [$participantId, $methodId] = $this->seedParticipantAndPaymentMethod();
        $order = [
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participantId,
            'payment_method_id' => $methodId,
            'status' => 'pending',
            'amount' => 500000,
            'currency' => 'IDR',
            'gateway_ref' => 'invoice-synthetic-1',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('orders')->insert($order);

        $this->expectException(QueryException::class);
        $order['public_id'] = (string) Str::ulid();
        DB::table('orders')->insert($order);
    }

    public function test_each_participant_has_only_one_entitlement_per_test_type(): void
    {
        [$participantId] = $this->seedParticipantAndPaymentMethod();
        $entitlement = [
            'participant_id' => $participantId,
            'test_type' => 'ist',
            'status' => 'locked',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('entitlements')->insert($entitlement);

        $this->expectException(QueryException::class);
        DB::table('entitlements')->insert($entitlement);
    }

    /** @return array{int, int} */
    private function seedParticipantAndPaymentMethod(): array
    {
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'PUSAT',
            'name' => 'Pusat',
            'ref_code' => 'PUSAT',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $participantId = DB::table('participants')->insertGetId([
            'branch_id' => $branchId,
            'referral_branch_id' => $branchId,
            'referral_source' => 'default',
            'full_name' => 'Peserta Sintetis',
            'gender' => 'female',
            'birth_date' => '2000-01-01',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '080000000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(PaymentMethodSeeder::class);
        $methodId = DB::table('payment_methods')->where('code', 'xendit')->value('id');

        $this->assertIsInt($methodId);

        return [$participantId, $methodId];
    }
}
