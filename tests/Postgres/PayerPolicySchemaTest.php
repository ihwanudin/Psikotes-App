<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Enums\PayerType;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\ResolvePayerPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayerPolicySchemaTest extends TestCase
{
    private int $branch;

    private int $source;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->branch = DB::table('branches')->insertGetId([
                'code' => 'PAYER', 'ref_code' => 'PAYER', 'name' => 'Synthetic payer',
                'organization_code' => 'PAYER', 'display_name' => 'Synthetic payer',
            ]);
            $client = DB::table('integration_clients')->insertGetId([
                'organization_id' => $this->branch, 'client_id' => 'synthetic-payer',
                'credential_reference' => 'synthetic-not-a-secret',
            ]);
            $this->source = DB::table('integration_sources')->insertGetId([
                'integration_client_id' => $client, 'source_system' => 'SYNTHETIC_PAYER',
                'allowed_assessment_packages' => '[]', 'allowed_funding_modes' => '["SPONSORED"]',
            ]);
        });
        // Nested savepoints retain SET LOCAL until the outer test transaction ends.
        DB::select("SELECT set_config('app.role', '', true)");
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_defaults_and_valid_policy_are_enforced_on_the_runtime_connection(): void
    {
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
        foreach (['public.branches', 'public.integration_sources'] as $table) {
            $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', [$table]);
            $this->assertTrue($security->relrowsecurity);
            $this->assertTrue($security->relforcerowsecurity);
        }
        app(RlsContextRunner::class)->run(new RlsContext('super_admin'), function (): void {
            $this->assertSame(['self'], json_decode(DB::table('branches')->where('id', $this->branch)->value('allowed_payer_types'), true));
            $this->assertSame([], json_decode(DB::table('integration_sources')->where('id', $this->source)->value('allowed_payer_types'), true));
            $this->assertSame(1, DB::table('branches')->where('id', $this->branch)->update(['allowed_payer_types' => '["self","organization"]']));
            $this->assertSame(1, DB::table('integration_sources')->where('id', $this->source)->update([
                'allowed_payer_types' => '["organization"]', 'locked_payer_type' => 'organization',
            ]));
        });
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidLists(): iterable
    {
        foreach (['branches', 'integration_sources'] as $table) {
            foreach (['legacy' => '["SPONSORED"]', 'unknown' => '["credit"]', 'scalar' => '"self"', 'object' => '{"self":true}', 'json-null' => 'null', 'nested' => '[["self"]]'] as $name => $json) {
                yield $table.' '.$name => [$table, $json];
            }
        }
    }

    #[DataProvider('invalidLists')]
    public function test_invalid_payer_lists_are_rejected(string $table, string $json): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(RlsContextRunner::class)->runAsService(fn () => DB::table($table)
            ->where('id', $table === 'branches' ? $this->branch : $this->source)
            ->update(['allowed_payer_types' => $json]));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function invalidLocks(): iterable
    {
        yield 'not allowed' => ['["self"]', 'organization'];
        yield 'empty' => ['[]', 'self'];
        yield 'unconfigured' => [null, 'self'];
        yield 'legacy mode' => ['["self","organization"]', 'SPONSORED'];
    }

    #[DataProvider('invalidLocks')]
    public function test_locked_payer_must_belong_to_the_source_allow_list(?string $allowed, string $locked): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('integration_sources')->where('id', $this->source)
            ->update(['allowed_payer_types' => $allowed, 'locked_payer_type' => $locked]));
    }

    public function test_unconfigured_and_disabled_policies_can_be_stored_without_granting_access(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $orders = DB::table('orders')->count();
            $attempts = DB::table('assessment_participants')->count();
            foreach ([null, '[]'] as $allowed) {
                $this->assertSame(1, DB::table('branches')->where('id', $this->branch)->update(['allowed_payer_types' => $allowed]));
                $this->assertSame(1, DB::table('integration_sources')->where('id', $this->source)->update([
                    'allowed_payer_types' => $allowed, 'locked_payer_type' => null,
                ]));
            }
            $this->assertSame($orders, DB::table('orders')->count());
            $this->assertSame($attempts, DB::table('assessment_participants')->count());
            $this->assertSame('["SPONSORED"]', DB::table('integration_sources')->where('id', $this->source)->value('allowed_funding_modes'));
        });
    }

    public function test_branch_admin_cannot_change_payer_policy_even_for_own_branch(): void
    {
        $this->assertSame(0, DB::table('branches')->where('id', $this->branch)->count());
        $this->assertSame(0, DB::table('integration_sources')->where('id', $this->source)->count());
        app(RlsContextRunner::class)->run(new RlsContext('branch_admin', $this->branch), function (): void {
            $this->assertSame(1, DB::table('branches')->where('id', $this->branch)->count());
            $this->assertSame(0, DB::table('branches')->where('id', $this->branch)->update(['allowed_payer_types' => '["organization"]']));
            $this->assertSame(0, DB::table('integration_sources')->where('id', $this->source)->update(['allowed_payer_types' => '["organization"]']));
        });
    }

    public function test_resolver_uses_persisted_runtime_policy_without_unlocking_or_querying(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $organization = Branch::findOrFail($this->branch);
            $source = IntegrationSource::findOrFail($this->source);
            $client = IntegrationClient::findOrFail($source->integration_client_id);
            $package = TestPackage::create([
                'code' => 'PAYER_TEST', 'name' => 'Synthetic payer test', 'amount' => 1, 'is_active' => true,
            ])->refresh();
            $client->update(['enabled' => true]);
            $organization->update(['allowed_payer_types' => ['self', 'organization']]);
            $source->update([
                'allowed_assessment_packages' => ['PAYER_TEST'],
                'allowed_payer_types' => ['self', 'organization'], 'locked_payer_type' => 'organization',
            ]);
            $source->refresh();
            $organization->refresh();
            $client->refresh();
            $orders = DB::table('orders')->count();
            $entitlements = DB::table('entitlements')->count();
            $at = CarbonImmutable::parse('2026-08-31 12:00:00', 'UTC');
            $resolver = app(ResolvePayerPolicy::class);

            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $decision = $resolver->resolve($organization, $client, $source, $package, $at);
                $this->assertSame([], DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $this->assertNull($decision->rejectionReason);
            $this->assertSame(PayerType::Organization, $decision->selectedPayerType);
            $this->assertSame($this->branch, $decision->payerOrganizationId);
            $this->assertSame('PAYER_LOCKED', $resolver->resolve($organization, $client, $source, $package, $at, 'self')->rejectionReason);

            $other = Branch::create([
                'code' => 'PAYER_OTHER', 'ref_code' => 'PAYER_OTHER', 'name' => 'Synthetic other',
                'organization_code' => 'PAYER_OTHER', 'display_name' => 'Synthetic other',
            ])->refresh();
            $this->assertSame('INTEGRATION_CONTEXT_INVALID', $resolver->resolve($other, $client, $source, $package, $at)->rejectionReason);

            $organization->update(['allowed_payer_types' => ['self']]);
            $this->assertSame('PAYER_POLICY_INVALID', $resolver->resolve($organization->refresh(), $client, $source, $package, $at)->rejectionReason);
            $this->assertSame($orders, DB::table('orders')->count());
            $this->assertSame($entitlements, DB::table('entitlements')->count());
        });
    }
}
