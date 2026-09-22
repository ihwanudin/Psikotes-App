<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\PapiItemContentReader;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F2 item-delivery Stage 2 (2026-09-21). Runs the REAL InstrumentSeeder
 * against the REAL papi_items.json rather than a synthetic fixture, so
 * exact-source-order preservation and the `status: final` gate are proven
 * against the actual authority a production reader would see.
 */
final class PapiItemContentReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());
    }

    public function test_it_returns_all_90_items_in_exact_source_order_against_the_real_seeded_data(): void
    {
        $rawPayload = json_decode(
            (string) DB::table('instrument_versions')->where('code', 'papi_items')->value('source_text'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('final', $rawPayload['status']);

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new PapiItemContentReader)->contentFor(
                GenericAssessmentInstrument::Papi,
                $this->syntheticDefinition(),
            ),
        );

        $this->assertSame(GenericAssessmentInstrument::Papi, $content->instrument);
        $this->assertSame($rawPayload['version'], $content->version);
        $this->assertCount(1, $content->subtests);
        $this->assertSame('ITEMS', $content->subtests[0]['code']);
        $items = $content->subtests[0]['items'];
        $this->assertCount(90, $items);

        // No mapping/scoring metadata field leaks -- exactly item/statement_a/statement_b.
        foreach ($items as $offset => $item) {
            $this->assertSame(['item', 'statement_a', 'statement_b'], array_keys($item));
            $this->assertSame($offset + 1, $item['item']);
            $this->assertSame($rawPayload['items'][$offset]['statement_a'], $item['statement_a']);
            $this->assertSame($rawPayload['items'][$offset]['statement_b'], $item['statement_b']);
        }

        // Administration text is delivered alongside the items -- one
        // server-side source of truth, no client-side hardcoded copy.
        $this->assertSame([
            'intro' => $rawPayload['instructions']['intro'],
            'example' => $rawPayload['instructions']['example'],
            'answer_sheet_demo' => $rawPayload['instructions']['answer_sheet_demo'],
            'closing' => $rawPayload['instructions']['closing'],
        ], $content->instructions);
    }

    public function test_it_fails_closed_when_instructions_are_malformed(): void
    {
        $original = (string) DB::table('instrument_versions')->where('code', 'papi_items')->value('source_text');
        $decoded = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        unset($decoded['instructions']['closing']);
        $tampered = json_encode($decoded, JSON_THROW_ON_ERROR);
        DB::table('instrument_versions')->where('code', 'papi_items')->update([
            'source_text' => $tampered,
            'checksum' => hash('sha256', $tampered),
        ]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new PapiItemContentReader)->contentFor(
                GenericAssessmentInstrument::Papi,
                $this->syntheticDefinition(),
            ),
        );
    }

    public function test_it_rejects_a_non_papi_instrument(): void
    {
        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new PapiItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(instrument: 'ist'),
            ),
        );
    }

    public function test_it_fails_closed_when_no_active_items_authority_is_seeded(): void
    {
        DB::table('instrument_versions')->where('code', 'papi_items')->update(['is_active' => false]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new PapiItemContentReader)->contentFor(
                GenericAssessmentInstrument::Papi,
                $this->syntheticDefinition(),
            ),
        );
    }

    public function test_it_fails_closed_when_the_checksum_does_not_match_the_source_text(): void
    {
        DB::table('instrument_versions')->where('code', 'papi_items')->update([
            'source_text' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new PapiItemContentReader)->contentFor(
                GenericAssessmentInstrument::Papi,
                $this->syntheticDefinition(),
            ),
        );
    }

    public function test_it_fails_closed_when_status_is_not_final(): void
    {
        $original = (string) DB::table('instrument_versions')->where('code', 'papi_items')->value('source_text');
        $decoded = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $decoded['status'] = 'draft';
        $tampered = json_encode($decoded, JSON_THROW_ON_ERROR);
        DB::table('instrument_versions')->where('code', 'papi_items')->update([
            'source_text' => $tampered,
            'checksum' => hash('sha256', $tampered),
        ]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new PapiItemContentReader)->contentFor(
                GenericAssessmentInstrument::Papi,
                $this->syntheticDefinition(),
            ),
        );
    }

    private function syntheticDefinition(string $instrument = 'papi'): SessionDefinition
    {
        $payload = [
            'instrument' => $instrument, 'version' => 'synthetic-definition-v1',
            'provenance' => 'papi-item-content-test-only', 'total_duration_seconds' => 1800,
            'subtests' => [['code' => 'ALL', 'duration_seconds' => 1800, 'item_count' => 90]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];

        return SessionDefinition::fromArray([...$payload, 'checksum' => SessionDefinition::checksumFor($payload)]);
    }
}
