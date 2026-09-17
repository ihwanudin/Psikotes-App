<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Payments\UpdateFundingPolicy;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class FundingPolicyManagementTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Branch $organization;

    private IntegrationSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Branch::create([
            'code' => 'FUNDING', 'ref_code' => 'FUNDING', 'name' => 'Synthetic funding',
            'organization_code' => 'FUNDING', 'display_name' => 'Synthetic funding',
            'allowed_payer_types' => ['self'],
        ])->refresh();
        $client = IntegrationClient::create([
            'organization_id' => $this->organization->id, 'client_id' => 'synthetic-funding',
            'credential_reference' => 'synthetic-not-a-secret',
        ]);
        $this->source = IntegrationSource::create([
            'integration_client_id' => $client->id, 'source_system' => 'SYNTHETIC',
            'allowed_assessment_packages' => ['WORK_V1'], 'allowed_funding_modes' => ['SPONSORED'],
            'allowed_payer_types' => ['self'],
        ])->refresh();
        $this->admin = Admin::create([
            'name' => 'Synthetic admin', 'email' => 'funding@example.test',
            'password' => 'synthetic-test-password', 'role' => AdminRole::SuperAdmin,
        ]);
    }

    public function test_super_admin_updates_only_payer_policy_and_records_minimal_audit(): void
    {
        $this->freezeTime();
        $branch = $this->action()->forOrganization($this->admin, $this->organization->id, [
            'allowed_payer_types' => ['organization', 'self'],
        ]);
        $source = $this->action()->forSource($this->admin, $branch->id, $this->source->id, [
            'allowed_payer_types' => ['organization'], 'locked_payer_type' => 'organization',
        ]);

        $this->assertSame(['self', 'organization'], $branch->allowed_payer_types);
        $this->assertSame(['organization'], $source->allowed_payer_types);
        $this->assertSame('organization', $source->locked_payer_type);
        $this->assertSame(['SPONSORED'], $source->allowed_funding_modes);
        $this->assertSame('v1', $source->contract_version);
        $this->assertDatabaseCount('audit_logs', 2);
        $audit = DB::table('audit_logs')->where('subject_type', IntegrationSource::class)->first();
        $this->assertNotNull($audit);
        $this->assertSame('funding_policy.updated', $audit->action);
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame((string) $this->admin->id, $audit->actor_id);
        $this->assertSame($branch->id, $audit->branch_id);
        $this->assertSame((string) $source->id, $audit->subject_id);
        $this->assertSame([
            'from' => ['allowed_payer_types' => ['self'], 'locked_payer_type' => null],
            'to' => ['allowed_payer_types' => ['organization'], 'locked_payer_type' => 'organization'],
        ], json_decode($audit->context, true, flags: JSON_THROW_ON_ERROR));
        $anchor = CarbonImmutable::parse((string) $audit->occurred_at, 'UTC')->utc();
        $this->assertSame(
            app(RetentionPolicy::class)->expiresAt(RetentionDataClass::Audit, $anchor)->format('Y-m-d H:i:s.uP'),
            CarbonImmutable::parse((string) $audit->expires_at, 'UTC')->utc()->format('Y-m-d H:i:s.uP'),
        );
        $this->assertSame(
            CarbonImmutable::instance(now())->utc()->startOfSecond()->format('Y-m-d H:i:s.uP'),
            $anchor->format('Y-m-d H:i:s.uP'),
        );
        $this->assertSame(
            '2029-02-28 03:15:00.000000+00:00',
            app(RetentionPolicy::class)->expiresAt(
                RetentionDataClass::Audit,
                CarbonImmutable::parse('2024-02-29 10:15:00+07:00')->utc(),
            )->format('Y-m-d H:i:s.uP'),
        );
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('entitlements', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    #[DataProvider('deniedActors')]
    public function test_untrusted_or_revoked_admin_cannot_change_either_policy(string $actor): void
    {
        match ($actor) {
            'branch' => $this->admin->update(['role' => AdminRole::BranchAdmin, 'branch_id' => $this->organization->id, 'can_verify_payments' => true]),
            'staff' => $this->admin->update(['role' => AdminRole::Staff, 'branch_id' => $this->organization->id, 'can_verify_payments' => true]),
            'psychologist' => $this->admin->update(['role' => AdminRole::Psychologist]),
            'deleted' => DB::table('admins')->where('id', $this->admin->id)->update(['deleted_at' => now()]),
            'revoked' => DB::table('admins')->where('id', $this->admin->id)->update(['role' => AdminRole::Staff->value, 'branch_id' => $this->organization->id]),
            'unsaved' => $this->admin = new Admin(['role' => AdminRole::SuperAdmin]),
        };
        foreach (['organization', 'source'] as $target) {
            try {
                $this->change($target, ['allowed_payer_types' => ['organization'], 'locked_payer_type' => 'organization']);
                $this->fail('Unauthorized policy write succeeded.');
            } catch (AuthorizationException) {
                $this->assertSame(['self'], $this->organization->refresh()->allowed_payer_types);
                $this->assertSame(['self'], $this->source->refresh()->allowed_payer_types);
                $this->assertDatabaseCount('audit_logs', 0);
            }
        }
    }

    public static function deniedActors(): iterable
    {
        foreach (['branch', 'staff', 'psychologist', 'deleted', 'revoked', 'unsaved'] as $actor) {
            yield $actor => [$actor];
        }
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_payload_is_rejected_without_changes(string $target, array $payload): void
    {
        try {
            $this->change($target, $payload);
            $this->fail('Invalid policy accepted.');
        } catch (ValidationException) {
            $this->assertSame(['self'], $this->organization->refresh()->allowed_payer_types);
            $this->assertSame(['self'], $this->source->refresh()->allowed_payer_types);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public static function invalidPolicies(): iterable
    {
        foreach (['organization', 'source'] as $target) {
            foreach ([null, 'self', ['SELF'], ['SPONSORED'], ['organization', 'organization'], ['self' => true], [['self']], [null], [''], [1]] as $i => $allowed) {
                yield "$target invalid $i" => [$target, array_merge(
                    ['allowed_payer_types' => $allowed], $target === 'source' ? ['locked_payer_type' => null] : [],
                )];
            }
            yield "$target missing" => [$target, []];
            yield "$target forged scope" => [$target, ['allowed_payer_types' => ['self'], 'organization_id' => 123]];
        }
        yield 'branch cannot have a lock' => ['organization', ['allowed_payer_types' => ['self'], 'locked_payer_type' => 'self']];
        yield 'source missing lock' => ['source', ['allowed_payer_types' => ['self']]];
        foreach (['organization', 'SPONSORED', '', false, ['self']] as $i => $lock) {
            yield "invalid lock $i" => ['source', ['allowed_payer_types' => ['self'], 'locked_payer_type' => $lock]];
        }
        yield 'locked empty list' => ['source', ['allowed_payer_types' => [], 'locked_payer_type' => 'self']];
    }

    public function test_wrong_organization_source_pair_is_not_found(): void
    {
        $other = Branch::create([
            'code' => 'OTHER', 'ref_code' => 'OTHER', 'name' => 'Synthetic other',
            'organization_code' => 'OTHER', 'display_name' => 'Synthetic other',
        ]);
        try {
            $this->action()->forSource($this->admin, $other->id, $this->source->id, [
                'allowed_payer_types' => ['organization'], 'locked_payer_type' => null,
            ]);
            $this->fail('Cross-organization source accepted.');
        } catch (ModelNotFoundException) {
            $this->assertSame(['self'], $this->source->refresh()->allowed_payer_types);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_empty_policy_disables_and_repeated_canonical_input_is_a_noop(): void
    {
        $this->change('organization', ['allowed_payer_types' => ['organization', 'self']]);
        $this->change('organization', ['allowed_payer_types' => ['self', 'organization']]);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->change('source', ['allowed_payer_types' => [], 'locked_payer_type' => null]);
        $this->change('organization', ['allowed_payer_types' => []]);
        $this->assertSame([], $this->organization->refresh()->allowed_payer_types);
        $this->assertSame([], $this->source->refresh()->allowed_payer_types);
        $this->assertNull($this->source->locked_payer_type);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_switching_off_preserves_existing_orders_entitlements_and_channels(): void
    {
        $package = DB::table('packages')->insertGetId([
            'code' => 'POLICY-PACKAGE',
            'name' => 'Synthetic policy package',
            'amount' => 1,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        foreach (['dass21', 'ist'] as $sortOrder => $testType) {
            DB::table('package_items')->insert([
                'package_id' => $package,
                'test_type' => $testType,
                'sort_order' => $sortOrder,
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $this->organization->id, 'referral_branch_id' => $this->organization->id,
            'referral_source' => 'default', 'full_name' => 'Synthetic participant',
            'gender' => 'male', 'birth_date' => '2000-01-01', 'education_level' => 'SMA_SMK',
            'intended_field' => 'KAIGO', 'phone' => '620000000000',
            'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC',
        ]);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'synthetic', 'display_name' => 'Synthetic channel', 'is_active' => true,
        ]);
        $orderIds = [];
        $caseIds = [];
        foreach (['paid', 'pending'] as $status) {
            $publicId = (string) str()->ulid();
            $caseIds[] = DB::table('assessment_cases')->insertGetId([
                'public_id' => $publicId,
                'participant_id' => $participant,
                'organization_id' => $this->organization->id,
                'package_id' => $package,
                'origin' => 'DIRECT_PUBLIC',
                'intended_field_snapshot' => 'KAIGO',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $orderIds[] = DB::table('orders')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant,
                'assessment_case_id' => $caseIds[array_key_last($caseIds)],
                'payment_method_id' => $method, 'amount' => 1, 'currency' => 'IDR', 'status' => $status,
            ]);
        }
        DB::table('entitlements')->insert([
            'participant_id' => $participant,
            'order_id' => $orderIds[0],
            'assessment_case_id' => $caseIds[0],
            'test_type' => 'ist',
            'status' => 'locked',
        ]);
        $orders = DB::table('orders')->orderBy('id')->get()->toArray();
        $entitlements = DB::table('entitlements')->get()->toArray();
        $methods = DB::table('payment_methods')->get()->toArray();

        $this->change('organization', ['allowed_payer_types' => []]);
        $this->change('source', ['allowed_payer_types' => [], 'locked_payer_type' => null]);

        $this->assertEquals($orders, DB::table('orders')->orderBy('id')->get()->toArray());
        $this->assertEquals($entitlements, DB::table('entitlements')->get()->toArray());
        $this->assertEquals($methods, DB::table('payment_methods')->get()->toArray());
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_audit_failure_rolls_back_policy_even_when_outer_context_catches_it(): void
    {
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'insert into "audit_logs"')) {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        app(RlsContextRunner::class)->run(new RlsContext('super_admin'), function (): void {
            try {
                $this->change('organization', ['allowed_payer_types' => ['organization']]);
                $this->fail('Expected audit failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic audit failure', $exception->getMessage());
            }
            $this->assertSame(['self'], $this->organization->refresh()->allowed_payer_types);
            $this->assertSame('super_admin', app(RlsContextRunner::class)->current()?->role);
        });
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function change(string $target, array $payload): void
    {
        if ($target === 'organization') {
            $this->action()->forOrganization($this->admin, $this->organization->id, $payload);
        } else {
            $this->action()->forSource($this->admin, $this->organization->id, $this->source->id, $payload);
        }
    }

    private function action(): UpdateFundingPolicy
    {
        return app(UpdateFundingPolicy::class);
    }
}
