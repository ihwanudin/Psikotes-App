<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\AssessmentBill;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture as Fixture;

final class EloquentLazyLoadingGuardTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    public function test_testing_boot_enables_lazy_loading_guard(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
    }

    public function test_persisted_collection_refuses_lazy_loaded_relationship(): void
    {
        $billIds = [
            Fixture::create('organization')['bill'],
            Fixture::create('organization')['bill'],
        ];
        $bills = AssessmentBill::query()->whereKey($billIds)->get();

        $this->expectException(LazyLoadingViolationException::class);

        $bills->firstOrFail()->paymentMethod;
    }

    public function test_explicit_eager_loading_remains_available(): void
    {
        $billIds = [
            Fixture::create('organization')['bill'],
            Fixture::create('organization')['bill'],
        ];
        $bills = AssessmentBill::query()
            ->with('paymentMethod')
            ->whereKey($billIds)
            ->get();

        $this->assertCount(2, $bills);
        $this->assertTrue($bills->every(
            fn (AssessmentBill $bill): bool => $bill->relationLoaded('paymentMethod'),
        ));
        $this->assertTrue($bills->every(
            fn (AssessmentBill $bill): bool => $bill->paymentMethod !== null,
        ));
    }

    public function test_non_testing_provider_disables_guard_without_leaking_static_state(): void
    {
        $original = Model::preventsLazyLoading();
        $isolated = clone $this->app;
        $isolated->detectEnvironment(static fn (): string => 'local');
        $provider = new class($isolated) extends AppServiceProvider
        {
            protected function configureDefaults(): void
            {
                // This test isolates only the environment-dependent Eloquent guard.
            }
        };

        try {
            Model::preventLazyLoading(true);
            $provider->boot();
            $this->assertFalse(Model::preventsLazyLoading());
        } finally {
            Model::preventLazyLoading($original);
        }

        $this->assertSame($original, Model::preventsLazyLoading());
    }
}
