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

    public function test_it_builds_the_nine_final_subtests_from_the_real_extracted_content(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
        );

        $this->assertSame(GenericAssessmentInstrument::Ist, $content->instrument);
        $this->assertCount(9, $content->subtests);
        $this->assertSame(['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'], array_column($content->subtests, 'code'));

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

        // FA: two legend groups. Items 117-128 (FA-L1) and 129-136 (FA-L2)
        // resolve to two DIFFERENT sets of option asset_ids -- proves the
        // per-item legend_id lookup actually varies by item, not just
        // reuses whatever legend happened to be built first.
        $fa = $content->subtests[6];
        $this->assertSame('FA', $fa['code']);
        $this->assertSame('image_choice', $fa['answer_type']);
        $this->assertCount(20, $fa['items']);
        $this->assertSame(117, $fa['items'][0]['item']);
        $this->assertSame($this->assetId('fa/117.png'), $fa['items'][0]['asset_id']);
        $this->assertSame(
            ['a' => $this->assetId('fa/legend-1-a.png'), 'b' => $this->assetId('fa/legend-1-b.png'),
                'c' => $this->assetId('fa/legend-1-c.png'), 'd' => $this->assetId('fa/legend-1-d.png'),
                'e' => $this->assetId('fa/legend-1-e.png')],
            $fa['items'][0]['options'],
        );
        $this->assertSame(129, $fa['items'][12]['item']);
        $this->assertSame($this->assetId('fa/legend-2-a.png'), $fa['items'][12]['options']['a']);
        $this->assertNotSame($fa['items'][0]['options'], $fa['items'][12]['options']);

        // WU: one shared legend for all 20 items.
        $wu = $content->subtests[7];
        $this->assertSame('WU', $wu['code']);
        $this->assertSame('image_choice', $wu['answer_type']);
        $this->assertCount(20, $wu['items']);
        $this->assertSame(137, $wu['items'][0]['item']);
        $this->assertSame($this->assetId('wu/137.png'), $wu['items'][0]['asset_id']);
        $this->assertSame($wu['items'][0]['options'], $wu['items'][19]['options']);
        $this->assertSame(
            ['a' => $this->assetId('wu/legend-a.png'), 'b' => $this->assetId('wu/legend-b.png'),
                'c' => $this->assetId('wu/legend-c.png'), 'd' => $this->assetId('wu/legend-d.png'),
                'e' => $this->assetId('wu/legend-e.png')],
            $wu['items'][0]['options'],
        );

        // ME with no live segment (this test's syntheticDefinition() carries
        // no ME_MEMORIZE/ME_ANSWER segments, and contentFor() is called with
        // no $currentSegmentCode) -- both halves present, matching the
        // documented null-segment behavior.
        $me = $content->subtests[8];
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

        // No item anywhere carries a field beyond the explicit whitelist for
        // its own answer_type -- proves the reader whitelists rather than
        // passes decoded JSON through, independent of whether today's
        // source data happens to be clean.
        foreach ($content->subtests as $subtest) {
            $whitelist = $subtest['answer_type'] === 'image_choice'
                ? ['item', 'asset_id', 'options']
                : ['item', 'text', 'options'];
            foreach ($subtest['items'] as $item) {
                $this->assertSame([], array_diff(array_keys($item), $whitelist));
            }
        }
    }

    public function test_me_memorize_phase_sends_the_word_list_without_answer_items(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(),
                1,
                currentSegmentCode: 'ME_MEMORIZE',
            ),
        );

        $me = $content->subtests[8];
        $this->assertSame('ME', $me['code']);
        $this->assertArrayHasKey('word_list', $me);
        $this->assertContains('TEKUKUR', $me['word_list']['BURUNG']);
        $this->assertSame([], $me['items']);
    }

    public function test_me_answer_phase_sends_answer_items_without_the_word_list(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(),
                1,
                currentSegmentCode: 'ME_ANSWER',
            ),
        );

        $me = $content->subtests[8];
        $this->assertSame('ME', $me['code']);
        $this->assertCount(20, $me['items']);
        $this->assertSame(157, $me['items'][0]['item']);
        $this->assertArrayNotHasKey('word_list', $me);
    }

    public function test_me_shows_neither_half_while_a_different_subtest_segment_is_current(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $content = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(),
                1,
                currentSegmentCode: 'SE',
            ),
        );

        $me = $content->subtests[8];
        $this->assertSame([], $me['items']);
        $this->assertArrayNotHasKey('word_list', $me);
    }

    public function test_se_through_zr_are_unaffected_by_the_current_segment_code(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $memorize = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1, currentSegmentCode: 'ME_MEMORIZE'),
        );
        $answer = app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1, currentSegmentCode: 'ME_ANSWER'),
        );

        foreach (['SE', 'WA', 'AN', 'GE', 'RA', 'ZR'] as $index => $code) {
            $this->assertSame($code, $memorize->subtests[$index]['code']);
            $this->assertSame($memorize->subtests[$index], $answer->subtests[$index]);
        }
    }

    public function test_me_word_list_is_still_validated_during_the_answer_phase(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        $payload['subtests']['ME']['word_list']['BUNGA'] = ['only-one-word'];
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Ist,
                $this->syntheticDefinition(),
                1,
                currentSegmentCode: 'ME_ANSWER',
            ),
        );
    }

    /**
     * item-delivery reconciliation with #76/RMIB (2026-09-22): this used to
     * assert the real seeded data fails closed because ME was still draft,
     * then became a positive "builds successfully" assertion once #118
     * merged ME into main. FA/WU's addition to self::SUBTESTS (2026-09-23)
     * flips it back: this branch is off main BEFORE PR #133 (FA/WU) lands,
     * so the real seeded ist_items.json genuinely does not have FA/WU yet
     * -- and now that this reader requires them, the whole instrument
     * fails closed against today's real data, exactly the guard's job
     * (same situation ME was in before #118, not a bug this PR introduced).
     * This will need to flip positive again, the same way it did for ME,
     * once #133 actually merges.
     */
    public function test_it_still_fails_closed_against_the_real_seeded_data_because_fa_wu_are_not_final_yet(): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
        );
    }

    public function test_it_rejects_a_non_ist_instrument(): void
    {
        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(
                GenericAssessmentInstrument::Papi,
                $this->syntheticDefinition(instrument: 'papi'),
                1,
            ),
        );
    }

    public function test_it_fails_closed_when_no_active_authority_is_seeded(): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());
        DB::table('instrument_versions')->where('code', 'ist_items')->update(['is_active' => false]);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
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
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
        );
    }

    public function test_it_fails_closed_when_an_unsupported_subtest_is_also_marked_final(): void
    {
        // Every real subtest code is supported as of this reader's FA/WU
        // extension -- synthesize a stand-in ("XX") for "some future
        // subtest not yet supported here," since no real unsupported code
        // exists in ist_items.json today. If the data ever claims one final
        // anyway (authoring mistake, or this reader simply hasn't been
        // extended for it yet), the whole instrument must still fail closed
        // rather than silently ship without it.
        $payload = $this->realIstItemsPayloadFullyFinal();
        $payload['subtests']['XX'] = [
            'status' => 'final',
            'instructions' => ['text' => 'Synthetic unsupported subtest.'],
            'items' => [],
        ];
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
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
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
        );
    }

    /**
     * The real gap flagged to Lead/Codex #1 (2026-09-23): FA/WU's real
     * source has no `instructions` field at all, and every subtest (image
     * choice included) still goes through instructionsText(), same as
     * every other subtest -- so the real shape stays fail-closed for the
     * whole instrument until that data gap is resolved, exactly the
     * guard's job. This test uses the real shape verbatim (no placeholder
     * instructions), unlike every other test in this file.
     */
    public function test_it_still_fails_closed_against_the_real_fa_wu_shape_because_instructions_are_missing(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        foreach (['FA', 'WU'] as $code) {
            unset($payload['subtests'][$code]['instructions']);
        }
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
        );
    }

    public function test_image_choice_fails_closed_when_an_item_legend_id_disagrees_with_the_legend_group(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        $payload['subtests']['FA']['items'][0]['legend_id'] = 'FA-L2';
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
        );
    }

    public function test_image_choice_fails_closed_when_a_legend_group_leaves_an_item_uncovered(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        // Drop item 136 from FA-L2's own declared coverage -- 19 covered,
        // one short, even though the items array itself still has 20 rows.
        $payload['subtests']['FA']['option_legends'][1]['items'] = range(129, 135);
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
        );
    }

    public function test_image_choice_fails_closed_when_both_option_legends_and_option_legend_are_present(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        $payload['subtests']['WU']['option_legends'] = $payload['subtests']['FA']['option_legends'];
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
        );
    }

    public function test_image_choice_fails_closed_when_neither_option_legends_nor_option_legend_is_present(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        unset($payload['subtests']['WU']['option_legend']);
        $this->seedIstItemsAuthority($payload);
        $this->seedIstAssetReferences($this->allFaWuAssetPaths());

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
        );
    }

    public function test_image_choice_fails_closed_when_an_image_asset_has_not_been_synced(): void
    {
        $payload = $this->realIstItemsPayloadFullyFinal();
        $this->seedIstItemsAuthority($payload);
        // Every asset except FA item 117's own stem image.
        $paths = array_values(array_diff($this->allFaWuAssetPaths(), ['fa/117.png']));
        $this->seedIstAssetReferences($paths);

        $this->expectException(AssessmentItemContentUnavailable::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => (new IstItemContentReader)->contentFor(GenericAssessmentInstrument::Ist, $this->syntheticDefinition(), 1),
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
     * 2026-09-21 item 21) and FA/WU replaced by their actual finalized
     * shape from PR #73/#133 (confirmed directly against
     * origin/lead/repair-ist-fa-wu-merge:database/seeders/data/ist_items.json,
     * not assumed). Not synthetic: this is the exact data this reader will
     * see once #118 and #73/#133 both merge into this branch -- except
     * FA/WU's `instructions` field, which the real source genuinely has
     * none of (see realFaWuSubtests()'s own docblock for why a placeholder
     * is used here instead).
     *
     * @return array<string, mixed>
     */
    private function realIstItemsPayloadFullyFinal(): array
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

        foreach ($this->realFaWuSubtests() as $code => $subtest) {
            $payload['subtests'][$code] = $subtest;
        }

        return $payload;
    }

    /**
     * FA/WU's real item/legend shape, confirmed directly against
     * origin/lead/repair-ist-fa-wu-merge:database/seeders/data/ist_items.json
     * (PR #73/#133) -- both genuinely `status: "final"` (visual review
     * passed), both `answer_type: "image_choice"`. FA has two answer-legend
     * groups (items 117-128 use FA-L1, 129-136 use FA-L2); WU has one
     * legend shared by all 20 items (137-156), and its items carry no
     * `legend_id` field at all.
     *
     * The real source has NO `instructions` field for either subtest at
     * all (confirmed -- not an extraction bug this test is working around,
     * a genuine data-shape gap flagged back to Lead/Codex #1, 2026-09-23:
     * unclear whether FA/WU instructions are meant to come from data (a
     * follow-up F0 extraction) or fixed frontend copy). This placeholder
     * text is NOT real instrument content and exists only so the tests
     * below can exercise imageChoiceItems() itself; see
     * test_it_still_fails_closed_against_the_real_fa_wu_shape_because_instructions_are_missing()
     * for the regression guard proving the reader does NOT accept the real
     * shape as-is.
     *
     * @return array<string, array<string, mixed>>
     */
    private function realFaWuSubtests(): array
    {
        $faItems = [];
        foreach (range(117, 128) as $itemNumber) {
            $faItems[] = ['item' => $itemNumber, 'image' => "assets/ist/fa/{$itemNumber}.png", 'legend_id' => 'FA-L1'];
        }
        foreach (range(129, 136) as $itemNumber) {
            $faItems[] = ['item' => $itemNumber, 'image' => "assets/ist/fa/{$itemNumber}.png", 'legend_id' => 'FA-L2'];
        }

        $wuItems = [];
        foreach (range(137, 156) as $itemNumber) {
            $wuItems[] = ['item' => $itemNumber, 'image' => "assets/ist/wu/{$itemNumber}.png"];
        }

        $placeholderInstructions = ['text' => 'PLACEHOLDER -- not real instrument content, see realFaWuSubtests() docblock.'];

        return [
            'FA' => [
                'status' => 'final',
                'answer_type' => 'image_choice',
                'instructions' => $placeholderInstructions,
                'option_legends' => [
                    [
                        'legend_id' => 'FA-L1',
                        'items' => range(117, 128),
                        'options' => $this->legendPaths('fa', 'legend-1'),
                    ],
                    [
                        'legend_id' => 'FA-L2',
                        'items' => range(129, 136),
                        'options' => $this->legendPaths('fa', 'legend-2'),
                    ],
                ],
                'items' => $faItems,
            ],
            'WU' => [
                'status' => 'final',
                'answer_type' => 'image_choice',
                'instructions' => $placeholderInstructions,
                'option_legend' => ['options' => $this->legendPaths('wu', 'legend')],
                'items' => $wuItems,
            ],
        ];
    }

    /** @return array<string, string> */
    private function legendPaths(string $subtest, string $prefix): array
    {
        $built = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
            $built[$letter] = "assets/ist/{$subtest}/{$prefix}-{$letter}.png";
        }

        return $built;
    }

    /** @return list<string> object_key values (relative to database/seeders/data/assets/ist/) */
    private function allFaWuAssetPaths(): array
    {
        $paths = [];
        foreach (range(117, 156) as $itemNumber) {
            $subtest = $itemNumber <= 136 ? 'fa' : 'wu';
            $paths[] = "{$subtest}/{$itemNumber}.png";
        }
        foreach (['legend-1', 'legend-2'] as $prefix) {
            foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
                $paths[] = "fa/{$prefix}-{$letter}.png";
            }
        }
        foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
            $paths[] = "wu/legend-{$letter}.png";
        }

        return $paths;
    }

    /** @param  list<string>  $objectKeys */
    private function seedIstAssetReferences(array $objectKeys): void
    {
        $now = now();
        DB::table('assessment_asset_references')->insert(array_map(
            fn (string $objectKey): array => [
                'asset_id' => $this->assetId($objectKey),
                'instrument' => 'ist',
                'disk' => 'ist-assets',
                'object_key' => $objectKey,
                'checksum_sha256' => hash('sha256', $objectKey),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $objectKeys,
        ));
    }

    /**
     * Deterministic per-object_key id, shared by seedIstAssetReferences()
     * and assertions. assessment_asset_references.asset_id is a ulid()
     * column (char(26) on PostgreSQL) -- exactly 26 lowercase hex chars
     * fits without needing a real ULID generator.
     */
    private function assetId(string $objectKey): string
    {
        return substr(hash('sha256', $objectKey), 0, 26);
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
