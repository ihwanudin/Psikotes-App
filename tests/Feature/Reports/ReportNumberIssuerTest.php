<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Services\ReportRendering\ReportNumberIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReportNumberIssuerTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_number_matches_the_format_in_template_v23(): void
    {
        $number = app(ReportNumberIssuer::class)->issue(CarbonImmutable::parse('2026-09-20'));

        $this->assertMatchesRegularExpression('/^HPP\/2026\/09\/0001$/', $number);
    }

    public function test_numbers_increment_sequentially_within_the_same_period(): void
    {
        $issuer = app(ReportNumberIssuer::class);
        $at = CarbonImmutable::parse('2026-09-20');

        $this->assertSame('HPP/2026/09/0001', $issuer->issue($at));
        $this->assertSame('HPP/2026/09/0002', $issuer->issue($at));
        $this->assertSame('HPP/2026/09/0003', $issuer->issue($at));
    }

    public function test_sequence_resets_for_a_new_period(): void
    {
        $issuer = app(ReportNumberIssuer::class);

        $this->assertSame('HPP/2026/09/0001', $issuer->issue(CarbonImmutable::parse('2026-09-30')));
        $this->assertSame('HPP/2026/10/0001', $issuer->issue(CarbonImmutable::parse('2026-10-01')));
        $this->assertSame('HPP/2026/09/0002', $issuer->issue(CarbonImmutable::parse('2026-09-05')));
    }

    public function test_defaults_to_the_current_instant_when_no_date_is_given(): void
    {
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');

        $number = app(ReportNumberIssuer::class)->issue();

        $this->assertSame('HPP/2026/11/0001', $number);

        CarbonImmutable::setTestNow();
    }
}
