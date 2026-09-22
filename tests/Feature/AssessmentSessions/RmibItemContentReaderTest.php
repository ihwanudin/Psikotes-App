<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\RmibItemContentReader;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F2 item-delivery Stage 2 continuation (2026-09-21). Runs the REAL
 * InstrumentSeeder against the REAL rmib_items.json rather than a
 * synthetic fixture, so exact-source-order preservation, the
 * `status: final` gate, and the gender-track selection are all proven
 * against the actual authority a production reader would see.
 */
final class RmibItemContentReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());
    }

    public function test_locked_male_variant_returns_job_male_values_in_exact_source_order_and_never_touches_the_participant(): void
    {
        $rawPayload = $this->rawPayload();

        // participantId 999999 does not exist -- proves a locked variant
        // never queries participants.gender at all, only current-profile
        // resolution (lockedVariant === null) does.
        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                999999,
                'male',
            ),
        );

        $this->assertSame(GenericAssessmentInstrument::Rmib, $content->instrument);
        $this->assertSame($rawPayload['version'], $content->version);
        $this->assertNull($content->resolvedVariant, 'a locked call must never report a resolved variant');
        $this->assertCount(1, $content->subtests);
        $this->assertSame('POSITIONS', $content->subtests[0]['code']);
        $items = $content->subtests[0]['items'];
        $this->assertCount(108, $items);

        foreach ($items as $offset => $item) {
            $this->assertSame(['group', 'group_letter', 'position', 'job'], array_keys($item));
            $this->assertSame($rawPayload['positions'][$offset]['group'], $item['group']);
            $this->assertSame($rawPayload['positions'][$offset]['group_letter'], $item['group_letter']);
            // Position resets to 1 every 12 entries (9 groups x 12 positions
            // = 108) -- it is not a flat 1..108 sequence.
            $this->assertSame(($offset % 12) + 1, $item['position']);
            $this->assertSame($rawPayload['positions'][$offset]['job_male'], $item['job']);
        }
    }

    public function test_locked_female_variant_returns_job_female_values(): void
    {
        $rawPayload = $this->rawPayload();

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                999999,
                'female',
            ),
        );

        $items = $content->subtests[0]['items'];
        foreach ($items as $offset => $item) {
            $this->assertSame($rawPayload['positions'][$offset]['job_female'], $item['job']);
        }
    }

    public function test_fresh_resolution_picks_the_participants_current_gender_and_reports_it(): void
    {
        $rawPayload = $this->rawPayload();
        $male = $this->participant('male');
        $female = $this->participant('female');

        $maleContent = app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                $male,
            ),
        );
        $this->assertSame('male', $maleContent->resolvedVariant);
        $this->assertSame($rawPayload['positions'][0]['job_male'], $maleContent->subtests[0]['items'][0]['job']);

        $femaleContent = app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                $female,
            ),
        );
        $this->assertSame('female', $femaleContent->resolvedVariant);
        $this->assertSame($rawPayload['positions'][0]['job_female'], $femaleContent->subtests[0]['items'][0]['job']);
    }

    public function test_it_delivers_instructions_alongside_the_positions(): void
    {
        $rawPayload = $this->rawPayload();

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                999999,
                'male',
            ),
        );

        $this->assertSame([
            'text' => $rawPayload['instructions']['text'],
            'write_preferred_jobs_prompt' => $rawPayload['instructions']['write_preferred_jobs_prompt'],
        ], $content->instructions);
    }

    public function test_it_fails_closed_when_gender_is_null(): void
    {
        $participant = $this->participant(null);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                $participant,
            ),
        );
    }

    public function test_it_fails_closed_when_the_participant_does_not_exist_and_no_variant_is_locked(): void
    {
        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                999999,
            ),
        );
    }

    public function test_it_fails_closed_on_an_invalid_locked_variant(): void
    {
        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                999999,
                'MALE',
            ),
        );
    }

    public function test_it_rejects_a_non_rmib_instrument(): void
    {
        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(instrument: 'ist'),
                999999,
                'male',
            ),
        );
    }

    public function test_it_fails_closed_when_no_active_items_authority_is_seeded(): void
    {
        DB::table('instrument_versions')->where('code', 'rmib_items')->update(['is_active' => false]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                999999,
                'male',
            ),
        );
    }

    public function test_it_fails_closed_when_the_checksum_does_not_match_the_source_text(): void
    {
        DB::table('instrument_versions')->where('code', 'rmib_items')->update([
            'source_text' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                999999,
                'male',
            ),
        );
    }

    public function test_it_fails_closed_when_status_is_not_final(): void
    {
        $original = (string) DB::table('instrument_versions')->where('code', 'rmib_items')->value('source_text');
        $decoded = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $decoded['status'] = 'draft';
        $tampered = json_encode($decoded, JSON_THROW_ON_ERROR);
        DB::table('instrument_versions')->where('code', 'rmib_items')->update([
            'source_text' => $tampered,
            'checksum' => hash('sha256', $tampered),
        ]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                999999,
                'male',
            ),
        );
    }

    public function test_it_fails_closed_when_instructions_are_malformed(): void
    {
        $original = (string) DB::table('instrument_versions')->where('code', 'rmib_items')->value('source_text');
        $decoded = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        unset($decoded['instructions']['write_preferred_jobs_prompt']);
        $tampered = json_encode($decoded, JSON_THROW_ON_ERROR);
        DB::table('instrument_versions')->where('code', 'rmib_items')->update([
            'source_text' => $tampered,
            'checksum' => hash('sha256', $tampered),
        ]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new RmibItemContentReader)->contentFor(
                GenericAssessmentInstrument::Rmib,
                $this->syntheticDefinition(),
                999999,
                'male',
            ),
        );
    }

    /** @return array<string, mixed> */
    private function rawPayload(): array
    {
        return json_decode(
            (string) DB::table('instrument_versions')->where('code', 'rmib_items')->value('source_text'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function participant(?string $gender): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000', 'gender' => $gender,
        ]);
    }

    private function syntheticDefinition(string $instrument = 'rmib'): SessionDefinition
    {
        $payload = [
            'instrument' => $instrument, 'version' => 'synthetic-definition-v1',
            'provenance' => 'rmib-item-content-test-only', 'total_duration_seconds' => 900,
            'subtests' => [['code' => 'ALL', 'duration_seconds' => 900, 'item_count' => 108]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];

        return SessionDefinition::fromArray([...$payload, 'checksum' => SessionDefinition::checksumFor($payload)]);
    }
}
