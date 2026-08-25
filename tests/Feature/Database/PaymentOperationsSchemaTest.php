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
        foreach (['payment_methods', 'orders', 'entitlements', 'payment_webhook_events', 'audit_logs', 'outbox_messages'] as $table) {
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
        $this->assertTrue(Schema::hasColumns('payment_webhook_events', [
            'provider',
            'event_id',
            'provider_reference',
            'merchant_reference',
            'status',
            'amount',
            'currency',
            'intent_hash',
            'outcome',
            'error_code',
            'processed_at',
        ]));
        $this->assertTrue(Schema::hasColumns('outbox_messages', [
            'message_id',
            'deduplication_key',
            'topic',
            'aggregate_type',
            'aggregate_id',
            'payload',
            'status',
            'attempts',
            'available_at',
            'processed_at',
            'expires_at',
            'last_error',
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

    public function test_provider_event_ids_are_unique_per_provider(): void
    {
        $event = [
            'provider' => 'xendit',
            'event_id' => 'invoice-event-unique',
            'provider_reference' => 'invoice-reference',
            'merchant_reference' => '01K3H9M5YXB62D9QK7E5V2G8Z1',
            'status' => 'paid',
            'amount' => 500000,
            'currency' => 'IDR',
            'occurred_at' => now(),
            'intent_hash' => hash('sha256', 'intent'),
            'outcome' => 'applied',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('payment_webhook_events')->insert($event);

        $this->expectException(QueryException::class);
        DB::table('payment_webhook_events')->insert($event);
    }

    public function test_outbox_deduplication_keys_are_unique(): void
    {
        $message = [
            'message_id' => (string) Str::ulid(),
            'deduplication_key' => hash('sha256', 'participant.activation|synthetic-order'),
            'topic' => 'participant.activation',
            'aggregate_type' => 'order',
            'aggregate_id' => 'synthetic-order',
            'payload' => json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'expires_at' => now()->addYears(2),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('outbox_messages')->insert($message);

        $this->expectException(QueryException::class);
        $message['message_id'] = (string) Str::ulid();
        DB::table('outbox_messages')->insert($message);
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
