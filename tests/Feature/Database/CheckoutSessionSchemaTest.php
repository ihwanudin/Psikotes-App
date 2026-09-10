<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\CheckoutSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture;

final class CheckoutSessionSchemaTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
    }

    public function test_portable_schema_model_and_sensitive_serialization_are_exact(): void
    {
        $this->assertTrue(Schema::hasColumns('checkout_sessions', [
            'id', 'public_id', 'selector_digest', 'csrf_digest', 'checkout_handoff_id',
            'assessment_participant_id', 'organization_id', 'participant_id', 'package_id',
            'integration_client_id', 'integration_source_id', 'source_system', 'contract_version',
            'status', 'active_marker', 'established_at', 'last_seen_at', 'idle_expires_at',
            'absolute_expires_at', 'revoked_at', 'expired_at', 'revocation_reason',
            'created_at', 'updated_at',
        ]));
        $graph = $this->graph();
        $session = CheckoutSession::query()->create($this->row($graph));

        $this->assertInstanceOf(CarbonImmutable::class, $session->established_at);
        $this->assertInstanceOf(CarbonImmutable::class, $session->last_seen_at);
        $this->assertInstanceOf(CarbonImmutable::class, $session->idle_expires_at);
        $this->assertInstanceOf(CarbonImmutable::class, $session->absolute_expires_at);
        $this->assertTrue($session->active_marker);
        $this->assertContains('selector_digest', $session->getHidden());
        $this->assertContains('csrf_digest', $session->getHidden());
        $this->assertArrayNotHasKey('selector_digest', $session->toArray());
        $this->assertArrayNotHasKey('csrf_digest', $session->toArray());
        $this->assertSame($graph['handoff'], $session->handoff->id);
        $this->assertSame($graph['attempt'], $session->assessment->id);
        $this->assertSame($graph['client'], $session->client->id);
        $this->assertSame($graph['source'], $session->source->id);
    }

    public function test_portable_lifecycle_and_digest_contract_rejects_invalid_rows(): void
    {
        $invalid = [
            'selector uppercase' => ['selector_digest' => str_repeat('A', 64)],
            'csrf short' => ['csrf_digest' => str_repeat('a', 63)],
            'active marker missing' => ['active_marker' => null],
            'active revoked timestamp' => ['revoked_at' => '2026-09-02 00:03:00'],
            'last seen before establish' => ['last_seen_at' => '2026-09-02 00:00:59'],
            'idle before last seen' => ['idle_expires_at' => '2026-09-02 00:01:00'],
            'idle after absolute' => ['idle_expires_at' => '2026-09-02 02:02:01'],
            'revoked missing timestamp' => ['status' => 'REVOKED', 'active_marker' => null,
                'revocation_reason' => 'LOGOUT'],
            'revoked unknown reason' => ['status' => 'REVOKED', 'active_marker' => null,
                'revoked_at' => '2026-09-02 00:03:00', 'revocation_reason' => 'FREE_TEXT'],
            'expired missing timestamp' => ['status' => 'EXPIRED', 'active_marker' => null],
            'expired too early' => ['status' => 'EXPIRED', 'active_marker' => null,
                'expired_at' => '2026-09-02 00:31:59'],
            'unknown status' => ['status' => 'PENDING'],
        ];
        foreach ($invalid as $label => $override) {
            $graph = $this->graph();
            try {
                DB::table('checkout_sessions')->insert([...$this->row($graph), ...$override]);
                $this->fail("Invalid {$label} checkout session was accepted.");
            } catch (QueryException) {
                $this->assertDatabaseMissing('checkout_sessions', ['checkout_handoff_id' => $graph['handoff']]);
            }
        }
    }

    public function test_portable_uniques_and_composite_scope_are_authoritative(): void
    {
        $graph = $this->graph();
        $first = $this->row($graph, 'REVOKED');
        CheckoutSession::query()->create($first);

        foreach ([
            'handoff' => ['checkout_handoff_id' => $graph['handoff']],
            'selector' => ['selector_digest' => $first['selector_digest']],
            'csrf' => ['csrf_digest' => $first['csrf_digest']],
        ] as $label => $override) {
            $other = $this->graph();
            try {
                CheckoutSession::query()->create([...$this->row($other, 'REVOKED'), ...$override]);
                $this->fail("Duplicate {$label} was accepted.");
            } catch (QueryException) {
                $this->assertDatabaseCount('checkout_sessions', 1);
            }
        }

        $active = $this->graph();
        CheckoutSession::query()->create($this->row($active));
        $nextHandoff = $this->consumedHandoff($active, 2);
        try {
            CheckoutSession::query()->create($this->row([...$active, 'handoff' => $nextHandoff]));
            $this->fail('A second active checkout session for one attempt was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('checkout_sessions', 2);
        }

        $foreign = $this->graph();
        try {
            CheckoutSession::query()->create([...$this->row($active, 'REVOKED'),
                'organization_id' => $foreign['organization']]);
            $this->fail('Cross-tenant checkout session binding was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('checkout_sessions', 2);
        }
    }

    public function test_attempt_or_handoff_delete_cascades_but_source_and_client_delete_are_restricted(): void
    {
        $graph = $this->graph();
        CheckoutSession::query()->create($this->row($graph));
        foreach ([['integration_sources', $graph['source']], ['integration_clients', $graph['client']]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail("{$table} delete bypassed checkout session scope.");
            } catch (QueryException) {
                $this->assertDatabaseHas($table, ['id' => $id]);
            }
        }
        DB::table('assessment_participants')->where('id', $graph['attempt'])->delete();
        $this->assertDatabaseMissing('checkout_sessions', ['id' => 1]);
    }

    public function test_populated_parent_survives_up_and_populated_down_refuses_before_mutation(): void
    {
        $this->migrateDown();
        $graph = $this->graph();
        $handoff = DB::table('checkout_handoffs')->where('id', $graph['handoff'])->first();
        $this->migrateUp();
        $this->assertEquals($handoff, DB::table('checkout_handoffs')->where('id', $graph['handoff'])->first());

        CheckoutSession::query()->create($this->row($graph));
        $before = DB::table('checkout_sessions')->first();
        try {
            $this->migrateDown();
            $this->fail('Rollback discarded checkout session history.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Checkout session history prevents rollback.', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('checkout_sessions'));
        $this->assertEquals($before, DB::table('checkout_sessions')->first());
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,handoff:int} */
    private function graph(): array
    {
        $key = (string) Str::ulid();
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key, 'credential_reference' => 'synthetic-only',
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => 'CHECKOUT_SESSION_SOURCE',
            'contract_version' => 'checkout-v2', 'allowed_assessment_packages' => '["SESSION"]',
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $key, 'name' => 'Synthetic', 'amount' => 100, 'currency' => 'IDR',
        ]);
        $case = AssessmentBillingFixture::createExactIntegratedCase($participant, $organization, $package, $key);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package, 'assessment_case_id' => $case,
            'assessment_attempt_id' => $key, 'source_system' => 'CHECKOUT_SESSION_SOURCE',
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);
        $handoff = $this->consumedHandoff(compact(
            'organization', 'participant', 'client', 'source', 'package', 'attempt',
        ), 1);

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt', 'handoff');
    }

    /** @param array{organization:int,participant:int,client:int,source:int,package:int,attempt:int} $graph */
    private function consumedHandoff(array $graph, int $issue): int
    {
        $issued = CarbonImmutable::parse('2026-09-02T00:00:00+00:00');

        return DB::table('checkout_handoffs')->insertGetId([
            'public_id' => (string) Str::ulid(), 'assessment_participant_id' => $graph['attempt'],
            'organization_id' => $graph['organization'], 'participant_id' => $graph['participant'],
            'package_id' => $graph['package'], 'integration_client_id' => $graph['client'],
            'integration_source_id' => $graph['source'], 'source_system' => 'CHECKOUT_SESSION_SOURCE',
            'contract_version' => 'checkout-v2', 'purpose' => 'checkout-handoff',
            'destination' => 'integrated-checkout-session',
            'token_digest' => hash('sha256', $graph['attempt'].'-token-'.$issue),
            'active_marker' => null, 'status' => 'CONSUMED', 'issue_number' => $issue,
            'issue_idempotency_key_digest' => hash('sha256', $graph['attempt'].'-idempotency-'.$issue),
            'request_hash' => hash('sha256', $graph['attempt'].'-request-'.$issue),
            'issued_at' => $issued, 'expires_at' => $issued->addMinutes(10),
            'consumed_at' => $issued->addMinute(),
        ]);
    }

    /** @param array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,handoff:int} $graph
     * @return array<string, mixed>
     */
    private function row(array $graph, string $status = 'ACTIVE'): array
    {
        $established = CarbonImmutable::parse('2026-09-02T00:02:00+00:00');
        $row = [
            'public_id' => (string) Str::ulid(), 'selector_digest' => hash('sha256', (string) Str::ulid()),
            'csrf_digest' => hash('sha256', (string) Str::ulid()), 'checkout_handoff_id' => $graph['handoff'],
            'assessment_participant_id' => $graph['attempt'], 'organization_id' => $graph['organization'],
            'participant_id' => $graph['participant'], 'package_id' => $graph['package'],
            'integration_client_id' => $graph['client'], 'integration_source_id' => $graph['source'],
            'source_system' => 'CHECKOUT_SESSION_SOURCE', 'contract_version' => 'checkout-v2',
            'status' => $status, 'active_marker' => $status === 'ACTIVE' ? true : null,
            'established_at' => $established, 'last_seen_at' => $established,
            'idle_expires_at' => $established->addMinutes(30),
            'absolute_expires_at' => $established->addMinutes(120),
            'revoked_at' => null, 'expired_at' => null, 'revocation_reason' => null,
        ];
        if ($status === 'REVOKED') {
            $row['revoked_at'] = $established->addMinute();
            $row['revocation_reason'] = 'LOGOUT';
        } elseif ($status === 'EXPIRED') {
            $row['expired_at'] = $established->addMinutes(30);
        }

        return $row;
    }

    private function migrateUp(): void
    {
        $migration = require database_path('migrations/2026_09_02_000200_create_checkout_sessions.php');
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Checkout session migration has no up method.');
        }
        $migration->up();
    }

    private function migrateDown(): void
    {
        $migration = require database_path('migrations/2026_09_02_000200_create_checkout_sessions.php');
        if (! is_object($migration) || ! method_exists($migration, 'down')) {
            throw new RuntimeException('Checkout session migration has no down method.');
        }
        $migration->down();
    }
}
