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
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off), extended for ME
 * (2026-09-22). The REAL ist_items.json on this branch still has
 * top-level status:"draft" today (PR #118, which finalizes ME with a real
 * word_list, has not merged into this branch) -- so the "reader builds
 * real content correctly" tests below seed a MODIFIED copy of the real
 * file (the six text subtests' real content, plus ME's REAL finalized
 * shape from PR #118/commit ee3f5528 -- TEKUKUR/QUINTET, the psychologist-
 * confirmed word list -- injected here so this reader's own ME parsing is
 * exercised against the actual data shape it will see once #118 merges,
 * not a synthetic stand-in) rather than the genuinely-seeded row. The
 * "fails closed against production data" test uses the actual
 * InstrumentSeeder + actual ist_items.json unmodified -- that one is the
 * real regression guard; it must keep failing until #118 (or FA/WU) merge.
 */
final class IstItemContentReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }

    public function test_it_builds_the_seven_final_subtests_from_the_real_extracted_content(): void
    {
        $payload = $this->realIstItemsPayloadWithFinalMe();
        $this->seedIstItemsAuthority($payload);

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition()),
        );

        $this->assertSame(GenericAssessmentInstrument::Ist, $content->instrument);
        $this->assertCount(7, $content->subtests);
        $this->assertSame(['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'ME'], array_column($content->subtests, 'code'));

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

        // ME with no live segment (this test's syntheticDefinition() carries
        // no ME_MEMORIZE/ME_ANSWER segments, and contentFor() is called with
        // no $currentSegmentCode) -- both halves present, matching the
        // documented null-segment behavior.
        $me = $content->subtests[6];
        $this->assertSame('multiple_choice', $me['answer_type']);
        $this->assertCount(20, $me['items']);
        $this->assertSame(157, $me['items'][0]['item']);
        $this->assertArrayHasKey('word_list', $me);
        $this->assertSame(
            ['BUNGA', 'PERKAKAS', 'BURUNG', 'KESENIAN', 'BINATANG'],
            array_keys($me['word_list']),
        );
        $this->assertSame(['SOKA', 'LARAT', 'FLAMBOYAN', 'YASMIN', 'DAHLIA'], $me['word_list']['BUNGA']);
        $this->assertContains('TEKUKUR', $me['word_list']['BURUNG']);
        $this->assertContains('QUINTET', $me['word_list']['KESENIAN']);

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

    public function test_me_memorize_phase_sends_the_word_list_without_answer_items(): void
    {
        $payload = $this->realIstItemsPayloadWithFinalMe();
        $this->seedIstItemsAuthority($payload);

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(),
                'ME_MEMORIZE',
            ),
        );

        $me = $content->subtests[6];
        $this->assertSame('ME', $me['code']);
        $this->assertArrayHasKey('word_list', $me);
        $this->assertContains('TEKUKUR', $me['word_list']['BURUNG']);
        $this->assertSame([], $me['items']);
    }

    public function test_me_answer_phase_sends_answer_items_without_the_word_list(): void
    {
        $payload = $this->realIstItemsPayloadWithFinalMe();
        $this->seedIstItemsAuthority($payload);

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(),
                'ME_ANSWER',
            ),
        );

        $me = $content->subtests[6];
        $this->assertSame('ME', $me['code']);
        $this->assertCount(20, $me['items']);
        $this->assertSame(157, $me['items'][0]['item']);
        $this->assertArrayNotHasKey('word_list', $me);
    }

    public function test_me_shows_neither_half_while_a_different_subtest_segment_is_current(): void
    {
        $payload = $this->realIstItemsPayloadWithFinalMe();
        $this->seedIstItemsAuthority($payload);

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(),
                'SE',
            ),
        );

        $me = $content->subtests[6];
        $this->assertSame([], $me['items']);
        $this->assertArrayNotHasKey('word_list', $me);
    }

    public function test_se_through_zr_are_unaffected_by_the_current_segment_code(): void
    {
        $payload = $this->realIstItemsPayloadWithFinalMe();
        $this->seedIstItemsAuthority($payload);

        $memorize = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 'ME_MEMORIZE'),
        );
        $answer = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 'ME_ANSWER'),
        );

        foreach (['SE', 'WA', 'AN', 'GE', 'RA', 'ZR'] as $index => $code) {
            $this->assertSame($code, $memorize->subtests[$index]['code']);
            $this->assertSame($memorize->subtests[$index], $answer->subtests[$index]);
        }
    }

    public function test_me_word_list_is_still_validated_during_the_answer_phase(): void
    {
        $payload = $this->realIstItemsPayloadWithFinalMe();
        $payload['subtests']['ME']['word_list']['BUNGA'] = ['only-one-word'];
        $this->seedIstItemsAuthority($payload);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(),
                'ME_ANSWER',
            ),
        );
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
        // FA/WU (pending PR #73) still are not in this reader's supported
        // set even after ME's addition -- synthesize a stand-in ("XX") for
        // "some future subtest not yet supported here," since neither real
        // unsupported code exists in ist_items.json today. If the data ever
        // claims one final anyway (authoring mistake, or this reader simply
        // hasn't been extended for it yet), the whole instrument must still
        // fail closed rather than silently ship without it.
        $payload = $this->realIstItemsPayloadWithFinalMe();
        $payload['subtests']['XX'] = [
            'status' => 'final',
            'instructions' => ['text' => 'Synthetic unsupported subtest.'],
            'items' => [],
        ];
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

    /**
     * The real payload, with ME replaced by its actual finalized shape from
     * PR #118 / commit ee3f5528 (psychologist-confirmed Versi A word list:
     * TEKUKUR for Burung, QUINTET for Kesenian -- owner-decisions
     * 2026-09-21 item 21). Not synthetic: this is the exact data this
     * reader will see once #118 merges into this branch.
     *
     * @return array<string, mixed>
     */
    private function realIstItemsPayloadWithFinalMe(): array
    {
        $payload = $this->realIstItemsPayload();
        $payload['status'] = 'final';

        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U'];
        $items = [];
        foreach ($letters as $offset => $letter) {
            $items[] = [
                'item' => 157 + $offset,
                'text' => "Kata yang mempunyai huruf permulaan – {$letter} – adalah …… .",
                'options' => [
                    'a' => 'bunga', 'b' => 'perkakas', 'c' => 'burung', 'd' => 'kesenian', 'e' => 'binatang',
                ],
            ];
        }

        $payload['subtests']['ME'] = [
            'status' => 'final',
            'instructions' => ['text' => '(Soal-soal No. 157 – 176) Pada persoalan berikutnya, terdapat sejumlah pertanyaan mengenai kata-kata yang telah saudara hafalkan tadi. Coretlah jawaban saudara pada lembaran jawaban di belakang nomor soal yang sesuai.'],
            'items' => $items,
            'word_list' => [
                'BUNGA' => ['SOKA', 'LARAT', 'FLAMBOYAN', 'YASMIN', 'DAHLIA'],
                'PERKAKAS' => ['WAJAN', 'JARUM', 'KIKIR', 'CANGKUL', 'PALU'],
                'BURUNG' => ['ITIK', 'ELANG', 'WALET', 'TEKUKUR', 'NURI'],
                'KESENIAN' => ['QUINTET', 'ARCA', 'OPERA', 'UKIRAN', 'GAMELAN'],
                'BINATANG' => ['RUSA', 'MUSANG', 'BERUANG', 'HARIMAU', 'ZEBRA'],
            ],
        ];

        return $payload;
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
