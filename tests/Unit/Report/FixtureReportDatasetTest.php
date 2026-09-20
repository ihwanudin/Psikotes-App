<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Services\ReportRendering\FixtureReportDataset;
use PHPUnit\Framework\TestCase;

final class FixtureReportDatasetTest extends TestCase
{
    public function test_internal_fixture_is_deterministic_and_carries_internal_material(): void
    {
        $first = FixtureReportDataset::internalDraft()->toViewData();
        $second = FixtureReportDataset::internalDraft()->toViewData();

        $this->assertSame($first, $second);
        $this->assertSame('V1', $first['validity']['status']);
        $this->assertCount(10, $first['validity']['items']);
        $this->assertCount(7, $first['integration_slots']);
        $this->assertSame(['d', 'a', 's'], array_keys($first['dass_detail']['subscales']));
        $this->assertSame('SMA/SMK', $first['kraepelin']['norm_group']);
        $this->assertSame('5,032', $first['kraepelin']['factors']['hanker']['value']);
        $this->assertCount(20, $first['papi']);
        $this->assertCount(12, $first['rmib']);
    }

    public function test_fixture_aspect_levels_match_the_verbalised_grey_area_story(): void
    {
        $viewData = FixtureReportDataset::internalDraft()->toViewData();

        $this->assertSame(['OK' => 13, 'GREY' => 1, 'BELUM' => 0], $viewData['zone_counts']);

        $grey = array_values(array_filter(
            $viewData['aspect_rows'],
            static fn (array $row): bool => $row['zone'] === 'GREY',
        ));

        $this->assertSame(['C2'], array_map(static fn (array $row): string => $row['code'], $grey));
    }

    public function test_internal_fixture_accepts_optional_psychologist_block(): void
    {
        $draft = FixtureReportDataset::internalDraft(FixtureReportDataset::psychologist());

        $this->assertSame('Dewi Kartika, S.Psi.', $draft->psychologist()?->toArray()['name']);
        $this->assertFalse($draft->validity()->isPublicationBlocked());
    }
}
