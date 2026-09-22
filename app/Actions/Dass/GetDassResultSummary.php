<?php

declare(strict_types=1);

namespace App\Actions\Dass;

use App\Domain\Report\DassScreeningSummary;
use App\Security\RlsContextRunner;
use App\Services\Dass\Dass21ConfigReader;
use App\Services\Dass\DassTableNames;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Builds the participant-safe `DassScreeningSummary` (general category +
 * narrative + follow-up, never subscale scores) from a completed
 * assessment's `dass.results` row. Lead's explicit requirement,
 * 2026-09-22: the participant-facing read path must go through this DTO,
 * never the raw `dass.results` row -- RLS can't filter columns per role
 * here (every request shares one `psikotes_runtime` Postgres role, so a
 * column-level `GRANT` can't be conditioned on `app_private.app_role()`'s
 * session variable), so this application-layer construction IS the
 * enforcement boundary, the same way `DassInternalDetail` already is for
 * the psychologist-facing sheet.
 *
 * Narrative/follow-up text mapping, verified by reading
 * `database/seeders/data/dass21.json` directly, not guessed:
 * `narratives` is a flat 20-entry list (3 subscales x 5 levels + 1
 * general x 5 levels); the general-category narrative is the entry where
 * `type === "Kategori umum"` and `category` matches `dass.results.overall_category`
 * exactly (no separate level lookup needed -- category strings are unique
 * per level). `follow_up.type` ('none'|'monitoring'|'referral_support_offer',
 * stored verbatim in `dass.results.follow_up` by `SubmitDassAssessment`)
 * maps to `dass21.json`'s top-level `follow_up.monitoring.text_id` /
 * `follow_up.referral.text_id`; 'none' has no narrative, matching
 * `DassScreeningSummary`'s own "null for Normal/Ringan" contract.
 *
 * Has no HTTP route yet -- Lead: hold back participant-facing result
 * display until the project owner answers whether even the general
 * category should reach the participant live (decision (c)). This class
 * exists so that decision doesn't also block building/testing the data
 * assembly itself.
 */
final readonly class GetDassResultSummary
{
    public function __construct(
        private RlsContextRunner $contexts,
        private Dass21ConfigReader $config,
    ) {}

    public function execute(int $participantId, string $publicId): ?DassScreeningSummary
    {
        $this->assertCleanOuterBoundary();

        return $this->contexts->runAsService(
            fn (): ?DassScreeningSummary => $this->build($participantId, $publicId),
        );
    }

    private function build(int $participantId, string $publicId): ?DassScreeningSummary
    {
        $row = DB::table(DassTableNames::assessments().' as a')
            ->join(DassTableNames::results().' as r', 'r.assessment_id', '=', 'a.id')
            ->where('a.public_id', $publicId)
            ->where('a.participant_id', $participantId)
            ->where('a.status', 'completed')
            ->select(['r.overall_category', 'r.follow_up'])
            ->first();

        if ($row === null) {
            return null;
        }

        $config = $this->config->read();
        $narrative = $this->generalNarrative($config, (string) $row->overall_category);
        $followUp = $this->followUpText($config, $row->follow_up === null ? null : (string) $row->follow_up);

        return DassScreeningSummary::fromArray([
            'general_category' => (string) $row->overall_category,
            'narrative' => $narrative,
            'follow_up' => $followUp,
        ]);
    }

    /** @param  array{narratives: array<mixed>, followUp: array<mixed>}  $config */
    private function generalNarrative(array $config, string $overallCategory): string
    {
        foreach ($config['narratives'] as $entry) {
            if (is_array($entry)
                && ($entry['type'] ?? null) === 'Kategori umum'
                && ($entry['category'] ?? null) === $overallCategory
                && is_string($entry['text_id'] ?? null) && $entry['text_id'] !== '') {
                return $entry['text_id'];
            }
        }

        throw new LogicException("No general-category DASS-21 narrative found for category \"{$overallCategory}\".");
    }

    /** @param  array{narratives: array<mixed>, followUp: array<mixed>}  $config */
    private function followUpText(array $config, ?string $followUpType): ?string
    {
        if ($followUpType === null || $followUpType === 'none') {
            return null;
        }

        $key = $followUpType === 'referral_support_offer' ? 'referral' : 'monitoring';
        $text = $config['followUp'][$key]['text_id'] ?? null;

        if (! is_string($text) || $text === '') {
            throw new LogicException("No DASS-21 follow-up narrative found for type \"{$followUpType}\".");
        }

        return $text;
    }

    private function assertCleanOuterBoundary(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Reading a DASS-21 result summary must own its outer service transaction.');
        }
    }
}
