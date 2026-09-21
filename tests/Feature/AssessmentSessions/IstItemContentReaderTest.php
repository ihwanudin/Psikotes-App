<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\IstItemContentReader;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off). The REAL
 * ist_items.json always has top-level status:"draft" today (ME's
 * word-list ambiguity keeps it there) -- so the "reader builds real
 * content correctly" tests below seed a MODIFIED copy of the real file
 * (only the top-level `status` flipped to "final") rather than the
 * genuinely-seeded row, to exercise IstItemContentReader's own parsing
 * logic in isolation. The "fails closed against production data" test
 * uses the actual InstrumentSeeder + actual ist_items.json unmodified --
 * that one is the real regression guard; it must keep failing until the
 * psychologist resolves ME's draft_reason (or FA/WU merge and this reader
 * is extended for them).
 */
final class IstItemContentReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }

    public function test_it_builds_the_six_final_text_subtests_from_the_real_extracted_content(): void
    {
        $payload = $this->realIstItemsPayload();
        $payload['status'] = 'final';
        $this->seedIstItemsAuthority($payload);

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition()),
        );

        $this->assertSame(GenericAssessmentInstrument::Ist, $content->instrument);
        $this->assertCount(6, $content->subtests);
        $this->assertSame(['SE', 'WA', 'AN', 'GE', 'RA', 'ZR'], array_column($content->subtests, 'code'));

        $se = $content->subtests[0];
        $this->assertSame('multiple_choice', $se['answer_type']);
        $this->assertCount(20, $se['items']);
        $this->assertSame(1, $se['items'][0]['item']);
        $this->assertSame(
            'Pengaruh seseorang terhadap orang lain seharusnya bergantung pada …..',
            $se['items'][0]['text'],
        );
        $this->assertSame(
            ['a' => 'kekuasaan', 'b' => 'bujukan', 'c' => 'kekayaan', 'd' => 'keberanian', 'e' => 'kewibawaan'],
            $se['items'][0]['options'],
        );

        // WA's items have no stem in the source -- the reader must not
        // invent one.
        $wa = $content->subtests[1];
        $this->assertSame(21, $wa['items'][0]['item']);
        $this->assertArrayNotHasKey('text', $wa['items'][0]);

        $ge = $content->subtests[3];
        $this->assertSame('fill_in_word', $ge['answer_type']);
        $this->assertCount(16, $ge['items']);
        $this->assertSame(['item' => 61, 'text' => 'mawar – melati'], $ge['items'][0]);

        $ra = $content->subtests[4];
        $this->assertSame('fill_in_numeric', $ra['answer_type']);
        $this->assertCount(20, $ra['items']);

        $zr = $content->subtests[5];
        $this->assertSame('fill_in_numeric', $zr['answer_type']);
        $this->assertSame(97, $zr['items'][0]['item']);

        // No item anywhere carries a field beyond the explicit whitelist --
        // proves the reader whitelists rather than passes decoded JSON
        // through, independent of whether today's source data happens to
        // be clean.
        foreach ($content->subtests as $subtest) {
            foreach ($subtest['items'] as $item) {
                $this->assertSame([], array_diff(array_keys($item), ['item', 'text', 'options']));
            }
        }
    }

    public function test_it_fails_closed_against_the_real_seeded_data_because_me_is_still_draft(): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition()),
        );
    }

    public function test_it_rejects_a_non_ist_instrument(): void
    {
        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Papi,
                $this->syntheticDefinition(instrument: 'papi'),
            ),
        );
    }

    public function test_it_fails_closed_when_no_active_authority_is_seeded(): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());
        DB::table('instrument_versions')->where('code', 'ist_items')->update(['is_active' => false]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition()),
        );
    }

    public function test_it_fails_closed_when_the_checksum_does_not_match_the_source_text(): void
    {
        $payload = $this->realIstItemsPayload();
        $payload['status'] = 'final';
        $this->seedIstItemsAuthority($payload);

        DB::table('instrument_versions')->where('code', 'ist_items')->update([
            'source_text' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition()),
        );
    }

    public function test_it_fails_closed_when_an_unsupported_subtest_is_also_marked_final(): void
    {
        $payload = $this->realIstItemsPayload();
        $payload['status'] = 'final';
        // ME is not in this reader's supported set. If the data ever claims
        // it final anyway (authoring mistake, or this reader simply hasn't
        // been extended for it yet), the whole instrument must still fail
        // closed rather than silently ship without ME.
        $payload['subtests']['ME']['status'] = 'final';
        $this->seedIstItemsAuthority($payload);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition()),
        );
    }

    public function test_it_fails_closed_when_a_required_subtest_is_missing(): void
    {
        $payload = $this->realIstItemsPayload();
        $payload['status'] = 'final';
        unset($payload['subtests']['GE']);
        $this->seedIstItemsAuthority($payload);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition()),
        );
    }

    /** @return array<string, mixed> */
    private function realIstItemsPayload(): array
    {
        $payload = File::get(database_path('seeders/data/ist_items.json'));

        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private function seedIstItemsAuthority(array $payload, string $version = 'test-final-v1'): void
    {
        $source = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        DB::table('instrument_versions')->insert([
            'code' => 'ist_items',
            'version' => $version,
            'source_file' => 'ist_items.json',
            'checksum' => hash('sha256', $source),
            'payload' => $source,
            'source_text' => $source,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function syntheticDefinition(string $instrument = 'ist'): SessionDefinition
    {
        $payload = [
            'instrument' => $instrument, 'version' => 'synthetic-definition-v1',
            'provenance' => 'ist-item-content-test-only', 'total_duration_seconds' => 600,
            'subtests' => [['code' => 'ALL', 'duration_seconds' => 600, 'item_count' => 10]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];

        return SessionDefinition::fromArray([...$payload, 'checksum' => SessionDefinition::checksumFor($payload)]);
    }
}
