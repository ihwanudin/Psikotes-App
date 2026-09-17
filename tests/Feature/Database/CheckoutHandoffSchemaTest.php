<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\CheckoutHandoff;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class CheckoutHandoffSchemaTest extends OrganizationPaymentTestCase
{
    protected function tearDown(): void
    {
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        } finally {
            parent::tearDown();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
    }

    public function test_portable_columns_model_casts_and_sensitive_fields_are_exact(): void
    {
        $this->assertTrue(Schema::hasColumns('checkout_handoffs', [
            'id', 'public_id', 'assessment_participant_id', 'organization_id', 'participant_id', 'package_id',
            'integration_client_id', 'integration_source_id', 'source_system', 'contract_version', 'purpose',
            'destination', 'token_digest', 'active_marker', 'status', 'issue_number',
            'issue_idempotency_key_digest', 'request_hash', 'revocation_reason', 'issued_at', 'expires_at',
            'consumed_at', 'revoked_at', 'expired_at', 'created_at', 'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('checkout_handoffs', 'issue_idempotency_key'));
        $graph = $this->graph();
        $handoff = CheckoutHandoff::query()->create($this->row($graph));

        $this->assertInstanceOf(CarbonImmutable::class, $handoff->issued_at);
        $this->assertInstanceOf(CarbonImmutable::class, $handoff->expires_at);
        $this->assertTrue($handoff->active_marker);
        $this->assertSame(1, $handoff->issue_number);
        $this->assertContains('token_digest', $handoff->getHidden());
        $this->assertContains('issue_idempotency_key_digest', $handoff->getHidden());
        $this->assertContains('request_hash', $handoff->getHidden());
        $this->assertArrayNotHasKey('issue_idempotency_key', $handoff->getAttributes());
        $this->assertArrayNotHasKey('token_digest', $handoff->toArray());
        $this->assertArrayNotHasKey('issue_idempotency_key_digest', $handoff->toArray());
        $this->assertArrayNotHasKey('request_hash', $handoff->toArray());
    }

    public function test_portable_unique_contract_allows_terminal_history_but_only_one_active(): void
    {
        $graph = $this->graph();
        CheckoutHandoff::query()->create($this->row($graph));
        $terminal = $this->row($graph, 2, 'CONSUMED');
        CheckoutHandoff::query()->create($terminal);
        CheckoutHandoff::query()->create($this->row($graph, 3, 'REVOKED'));
        CheckoutHandoff::query()->create($this->row($graph, 4, 'EXPIRED'));
        $this->assertSame(4, CheckoutHandoff::query()->count());

        $this->expectException(QueryException::class);
        CheckoutHandoff::query()->create($this->row($graph, 5));
    }

    public function test_portable_idempotency_digest_is_unique_per_client(): void
    {
        $graph = $this->graph();
        $first = $this->row($graph, 1, 'CONSUMED');
        CheckoutHandoff::query()->create($first);
        $duplicate = $this->row($graph, 2, 'REVOKED');
        $duplicate['issue_idempotency_key_digest'] = $first['issue_idempotency_key_digest'];

        $this->expectException(QueryException::class);
        CheckoutHandoff::query()->create($duplicate);
    }

    public function test_portable_composite_foreign_keys_reject_every_cross_scope_binding(): void
    {
        $graph = $this->graph();
        $foreign = $this->graph();
        $foreignVersionSource = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $graph['client'], 'source_system' => 'HANDOFF_SOURCE',
            'contract_version' => 'v1', 'allowed_assessment_packages' => '["HANDOFF"]',
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $foreignSystemSource = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $graph['client'], 'source_system' => 'FOREIGN_SOURCE',
            'contract_version' => 'checkout-v2', 'allowed_assessment_packages' => '["HANDOFF"]',
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $invalid = [
            'attempt client' => ['integration_client_id' => $foreign['client'], 'integration_source_id' => $foreign['source']],
            'source client' => ['integration_source_id' => $foreign['source']],
            'source system' => ['source_system' => 'FOREIGN_SOURCE'],
            'source contract' => ['integration_source_id' => $foreignVersionSource],
            'attempt source system' => [
                'integration_source_id' => $foreignSystemSource,
                'source_system' => 'FOREIGN_SOURCE',
            ],
            'organization' => ['organization_id' => $foreign['organization']],
            'participant' => ['participant_id' => $foreign['participant']],
            'package' => ['package_id' => $foreign['package']],
        ];

        foreach ($invalid as $label => $override) {
            try {
                CheckoutHandoff::query()->create([...$this->row($graph), ...$override]);
                $this->fail("Cross-scope {$label} binding was accepted.");
            } catch (QueryException) {
                $this->assertDatabaseCount('checkout_handoffs', 0);
            }
        }

        $mismatchedClientAttemptId = (string) Str::ulid();
        $mismatchedClientCase = DB::table('assessment_cases')->insertGetId([
            'public_id' => $mismatchedClientAttemptId, 'participant_id' => $foreign['participant'],
            'organization_id' => $foreign['organization'], 'package_id' => $foreign['package'],
            'origin' => 'INTEGRATED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mismatchedClientAttempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $foreign['organization'], 'integration_client_id' => $graph['client'],
            'participant_id' => $foreign['participant'], 'package_id' => $foreign['package'],
            'assessment_case_id' => $mismatchedClientCase, 'assessment_attempt_id' => $mismatchedClientAttemptId, 'source_system' => 'HANDOFF_SOURCE',
            'external_candidate_id' => (string) Str::ulid(), 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => (string) Str::ulid(),
            'request_hash' => hash('sha256', 'cross-organization-request'),
            'logical_assessment_key' => hash('sha256', 'cross-organization-logical'),
        ]);
        $mismatchedClientGraph = [
            ...$foreign, 'attempt' => $mismatchedClientAttempt,
            'client' => $graph['client'], 'source' => $graph['source'],
        ];
        try {
            CheckoutHandoff::query()->create($this->row($mismatchedClientGraph));
            $this->fail('Cross-organization client binding was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('checkout_handoffs', 0);
        }

        CheckoutHandoff::query()->create($this->row($graph));
        $this->assertDatabaseCount('checkout_handoffs', 1);
    }

    public function test_attempt_delete_cascades_while_source_and_client_delete_are_restricted(): void
    {
        $sourceGraph = $this->graph();
        CheckoutHandoff::query()->create($this->row($sourceGraph));
        try {
            DB::table('integration_sources')->where('id', $sourceGraph['source'])->delete();
            $this->fail('Source delete bypassed the checkout handoff restrict foreign key.');
        } catch (QueryException) {
            $this->assertDatabaseHas('integration_sources', ['id' => $sourceGraph['source']]);
        }
        try {
            DB::table('integration_clients')->where('id', $sourceGraph['client'])->delete();
            $this->fail('Client delete bypassed the checkout handoff restrict foreign key.');
        } catch (QueryException) {
            $this->assertDatabaseHas('integration_clients', ['id' => $sourceGraph['client']]);
        }

        DB::table('assessment_participants')->where('id', $sourceGraph['attempt'])->delete();
        $this->assertDatabaseCount('checkout_handoffs', 0);
        $this->assertDatabaseHas('participants', ['id' => $sourceGraph['participant']]);
    }

    public function test_populated_graph_survives_empty_down_up_and_populated_down_refuses_atomically(): void
    {
        $graph = $this->graph();
        CheckoutHandoff::query()->create($this->row($graph));
        $before = DB::table('checkout_handoffs')->first();
        try {
            $this->migrateDown();
            $this->fail('Rollback discarded checkout handoff history.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Checkout handoff history prevents rollback.', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('checkout_handoffs'));
        $this->assertEquals($before, DB::table('checkout_handoffs')->first());

        DB::table('checkout_handoffs')->delete();
        $counts = $this->graphCounts();
        $this->migrateDown();
        $this->assertFalse(Schema::hasTable('checkout_handoffs'));
        $this->assertSame($counts, $this->graphCounts());
        $this->migrateUp();
        $this->assertTrue(Schema::hasTable('checkout_handoffs'));
        $this->assertSame($counts, $this->graphCounts());
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int} */
    private function graph(): array
    {
        $key = (string) Str::ulid();
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'gender' => 'male', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA_SMK', 'intended_field' => 'UMUM', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key, 'credential_reference' => 'synthetic-only',
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => 'HANDOFF_SOURCE',
            'contract_version' => 'checkout-v2', 'allowed_assessment_packages' => '["HANDOFF"]',
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $key, 'name' => 'Synthetic', 'amount' => 100, 'currency' => 'IDR',
        ]);
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $key, 'participant_id' => $participant,
            'organization_id' => $organization, 'package_id' => $package,
            'origin' => 'INTEGRATED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client, 'participant_id' => $participant,
            'package_id' => $package, 'assessment_case_id' => $case, 'assessment_attempt_id' => $key, 'source_system' => 'HANDOFF_SOURCE',
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);

        return compact('organization', 'participant', 'client', 'source', 'package', 'case', 'attempt');
    }

    /** @param array{organization:int,participant:int,client:int,source:int,package:int,attempt:int} $graph
     * @return array<string, mixed>
     */
    private function row(array $graph, int $issue = 1, string $status = 'ISSUED'): array
    {
        $issued = CarbonImmutable::parse('2026-09-02T00:00:00+00:00');
        $row = [
            'public_id' => (string) Str::ulid(), 'assessment_participant_id' => $graph['attempt'],
            'organization_id' => $graph['organization'], 'participant_id' => $graph['participant'],
            'package_id' => $graph['package'], 'integration_client_id' => $graph['client'],
            'integration_source_id' => $graph['source'], 'source_system' => 'HANDOFF_SOURCE',
            'contract_version' => 'checkout-v2', 'purpose' => 'checkout-handoff',
            'destination' => 'integrated-checkout-session', 'token_digest' => hash('sha256', 'token-'.$issue),
            'active_marker' => $status === 'ISSUED' ? true : null, 'status' => $status, 'issue_number' => $issue,
            'issue_idempotency_key_digest' => hash('sha256', 'idempotency-'.$issue),
            'request_hash' => hash('sha256', 'request-'.$issue), 'issued_at' => $issued,
            'expires_at' => $issued->addMinutes(10), 'consumed_at' => null, 'revoked_at' => null,
            'expired_at' => null, 'revocation_reason' => null,
        ];
        if ($status === 'CONSUMED') {
            $row['consumed_at'] = $issued->addMinute();
        } elseif ($status === 'REVOKED') {
            $row['revoked_at'] = $issued->addMinute();
            $row['revocation_reason'] = 'REISSUED';
        } elseif ($status === 'EXPIRED') {
            $row['expired_at'] = $issued->addMinutes(11);
        }

        return $row;
    }

    /** @return array<string, int> */
    private function graphCounts(): array
    {
        return [
            'branches' => DB::table('branches')->count(), 'participants' => DB::table('participants')->count(),
            'clients' => DB::table('integration_clients')->count(), 'sources' => DB::table('integration_sources')->count(),
            'packages' => DB::table('packages')->count(), 'attempts' => DB::table('assessment_participants')->count(),
        ];
    }

    private function migrateUp(): void
    {
        $migration = require database_path('migrations/2026_09_02_000100_create_checkout_handoffs.php');
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Checkout handoff migration has no up method.');
        }
        $migration->up();
    }

    private function migrateDown(): void
    {
        $migration = require database_path('migrations/2026_09_02_000100_create_checkout_handoffs.php');
        if (! is_object($migration) || ! method_exists($migration, 'down')) {
            throw new RuntimeException('Checkout handoff migration has no down method.');
        }
        $migration->down();
    }
}
