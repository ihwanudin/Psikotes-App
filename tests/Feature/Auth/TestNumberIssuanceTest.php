<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Services\TestNumber\MonthlyTestNumberIssuer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TestNumberIssuanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_are_unique_and_sequential_within_a_month(): void
    {
        Date::setTestNow('2026-08-25 09:30:00');
        $issuer = app(MonthlyTestNumberIssuer::class);

        $first = $issuer->issue();
        $second = $issuer->issue();

        $this->assertMatchesRegularExpression('/^LSI-202608-000001-[A-Z0-9]{6}$/', $first);
        $this->assertMatchesRegularExpression('/^LSI-202608-000002-[A-Z0-9]{6}$/', $second);
        $this->assertNotSame($first, $second);
        $this->assertSame(2, DB::table('test_number_sequences')->where('period', '202608')->value('last_value'));
    }

    public function test_a_new_month_starts_from_one_without_mutating_prior_period(): void
    {
        $issuer = app(MonthlyTestNumberIssuer::class);

        Date::setTestNow('2026-08-31 23:59:00+07:00');
        $august = $issuer->issue();
        Date::setTestNow('2026-09-01 00:01:00+07:00');
        $september = $issuer->issue();

        $this->assertStringContainsString('202608-000001-', $august);
        $this->assertStringContainsString('202609-000001-', $september);
        $this->assertDatabaseHas('test_number_sequences', ['period' => '202608', 'last_value' => 1]);
        $this->assertDatabaseHas('test_number_sequences', ['period' => '202609', 'last_value' => 1]);
    }

    public function test_month_preparation_command_is_idempotent_and_does_not_reset_issued_values(): void
    {
        Date::setTestNow('2026-10-01 00:00:00');

        Artisan::call('test-numbers:prepare-month');
        app(MonthlyTestNumberIssuer::class)->issue();
        Artisan::call('test-numbers:prepare-month');

        $this->assertDatabaseCount('test_number_sequences', 1);
        $this->assertDatabaseHas('test_number_sequences', ['period' => '202610', 'last_value' => 1]);
    }

    public function test_month_preparation_is_scheduled_for_the_first_day(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'test-numbers:prepare-month'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 1 * *', $event->expression);
        $this->assertSame(config('participant_auth.test_number_timezone'), $event->timezone);
        $this->assertTrue($event->onOneServer);
    }
}
