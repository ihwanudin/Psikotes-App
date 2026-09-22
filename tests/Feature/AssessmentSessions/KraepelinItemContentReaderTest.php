<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\KraepelinItemContentReader;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F2 item-delivery Stage 2 (2026-09-21). Runs the REAL InstrumentSeeder
 * against the REAL kraepelin_grid.json (verified independently, see
 * tasks/handoffs/f2/verifikasi-kraepelin-2026-09-21.md) rather than a
 * synthetic fixture, so the sheet-to-administration-order reversal is
 * proven against the actual authority a production reader would see.
 */
final class KraepelinItemContentReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());
    }

    public function test_it_reverses_each_column_into_administration_order_against_the_real_seeded_grid(): void
    {
        $rawGrid = json_decode(
            (string) DB::table('instrument_versions')->where('code', 'kraepelin_grid')->value('source_text'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new KraepelinItemContentReader)->contentFor(
                GenericAssessmentInstrument::Kraepelin,
                $this->syntheticDefinition(),
                1,
            ),
        );

        $this->assertSame(GenericAssessmentInstrument::Kraepelin, $content->instrument);
        $this->assertSame('F0-2026.09', $content->version);
        $this->assertCount(50, $content->subtests);

        // Column 1 (index 0): position 1 (administration first) must be the
        // BOTTOM of the printed sheet (row 27, the last row in sheet order);
        // position 28 (administration last) must be the TOP of the printed
        // sheet (row 0, the first row in sheet order). A swap here would
        // mean every participant's answer is paired with the wrong number.
        $column1 = $content->subtests[0];
        $this->assertSame('col_01', $column1['code']);
        $this->assertCount(28, $column1['items']);
        $this->assertSame(['position' => 1, 'value' => $rawGrid['grid'][27][0]], $column1['items'][0]);
        $this->assertSame(['position' => 28, 'value' => $rawGrid['grid'][0][0]], $column1['items'][27]);

        // Column 50 (index 49): same check on the other edge of the grid.
        $column50 = $content->subtests[49];
        $this->assertSame('col_50', $column50['code']);
        $this->assertSame(['position' => 1, 'value' => $rawGrid['grid'][27][49]], $column50['items'][0]);
        $this->assertSame(['position' => 28, 'value' => $rawGrid['grid'][0][49]], $column50['items'][27]);

        // Every value is a single digit 1-9, matching the physical sheet.
        foreach ($content->subtests as $subtest) {
            foreach ($subtest['items'] as $item) {
                $this->assertIsInt($item['value']);
                $this->assertGreaterThanOrEqual(1, $item['value']);
                $this->assertLessThanOrEqual(9, $item['value']);
            }
        }
    }

    public function test_it_rejects_a_non_kraepelin_instrument(): void
    {
        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new KraepelinItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(instrument: 'ist'),
                1,
            ),
        );
    }

    public function test_it_fails_closed_when_no_active_grid_authority_is_seeded(): void
    {
        DB::table('instrument_versions')->where('code', 'kraepelin_grid')->update(['is_active' => false]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new KraepelinItemContentReader)->contentFor(
                GenericAssessmentInstrument::Kraepelin,
                $this->syntheticDefinition(),
                1,
            ),
        );
    }

    public function test_it_fails_closed_when_the_checksum_does_not_match_the_source_text(): void
    {
        DB::table('instrument_versions')->where('code', 'kraepelin_grid')->update([
            'source_text' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new KraepelinItemContentReader)->contentFor(
                GenericAssessmentInstrument::Kraepelin,
                $this->syntheticDefinition(),
                1,
            ),
        );
    }

    private function syntheticDefinition(string $instrument = 'kraepelin'): SessionDefinition
    {
        if ($instrument === 'kraepelin') {
            $payload = [
                'instrument' => 'kraepelin', 'version' => 'synthetic-definition-v1',
                'provenance' => 'kraepelin-item-content-test-only', 'total_duration_seconds' => 750,
                'subtests' => [['code' => 'KRAEPELIN', 'duration_seconds' => 750, 'item_count' => 1350]],
                'randomization' => 'fixed', 'seed' => null,
                'generator' => [
                    'algorithm' => 'fixed-sheet', 'version' => 'F0-2026.09', 'columns' => 50,
                    'seconds_per_column' => 15, 'numbers_per_column' => 28, 'answer_slots_per_column' => 27,
                ],
            ];
        } else {
            $payload = [
                'instrument' => $instrument, 'version' => 'synthetic-definition-v1',
                'provenance' => 'kraepelin-item-content-test-only', 'total_duration_seconds' => 600,
                'subtests' => [['code' => 'ALL', 'duration_seconds' => 600, 'item_count' => 10]],
                'randomization' => 'fixed', 'seed' => null, 'generator' => null,
            ];
        }

        return SessionDefinition::fromArray([...$payload, 'checksum' => SessionDefinition::checksumFor($payload)]);
    }
}
