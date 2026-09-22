<?php

declare(strict_types=1);

namespace App\Services\AssessmentSessions;

use App\Contracts\AssessmentItemContentAuthority;
use App\Domain\AssessmentSessions\AssessmentItemContent;
use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * F2 item-delivery Stage 2 continuation (2026-09-21). Reads the 108 RMIB
 * job-interest positions from the versioned `instrument_versions`
 * authority, code=rmib_items -- deliberately a separate row from
 * code=rmib (that one holds the SCORING data: `categories`/`rotation`,
 * which derive a position's scoring category from `group`+`position`
 * alone, never from which gender label was shown -- see the variant
 * decision below). Same checksum-verification and non-`final`-status
 * rejection pattern as PapiItemContentReader.
 *
 * **Gender-track selection (Lead decision, 2026-09-21)**: `rmib_items.json`
 * carries BOTH `job_male` and `job_female` per position -- server picks
 * exactly one and returns a neutral `job` field, never both, so the
 * response itself never reveals which track was used. Chosen server-side
 * (not left to the client) because: (1) the client becomes a pure
 * renderer, no per-item selection logic to get right 108 times; (2) only
 * the applicable track is ever transmitted; (3) one source of truth
 * (`participants.gender`) instead of the client also needing gender from
 * a second channel.
 *
 * **Locked, not re-derived, on every read**: $lockedVariant is null
 * exactly once per session -- the very first call, at
 * AllocateAndStartAssessmentSession::allocateNew(), before anything is
 * persisted. Only then does this reader resolve a variant fresh from
 * $participantId via `participants.gender`. Every later call (every
 * `GET /sessions/{id}/items`) passes the LOCKED value already persisted
 * on the session (`test_sessions.item_content_variant`) as
 * $lockedVariant, and this reader uses it as-is -- it never queries
 * `participants.gender` again once a variant is locked. This is what
 * keeps the served job labels stable even if the participant's profile
 * (e.g. a corrected gender) changes mid-test: the ranking a participant
 * already built up in their head stays matched to the text they actually
 * saw.
 *
 * **Gender null or unrecognized -> fail closed, never a default.**
 * `participants.gender` is nullable (ADR-004, checkout-v2 partial
 * profiles). A null/unknown gender at the one fresh-resolution moment
 * means the START gate itself rejects (503
 * ASSESSMENT_ITEM_CONTENT_UNAVAILABLE, before any session/grant row is
 * written) -- this class never guesses a track, because that would be an
 * engineering decision about psychometric content, not a participant's
 * own data.
 */
final readonly class RmibItemContentReader implements AssessmentItemContentAuthority
{
    private const PAYLOAD_FIELDS = ['version', 'status', 'instructions', 'positions'];

    private const POSITION_FIELDS = ['group', 'group_letter', 'position', 'job_male', 'job_female'];

    private const POSITION_COUNT = 108;

    private const POSITIONS_PER_GROUP = 12;

    private const INSTRUCTIONS_FIELDS = ['text', 'write_preferred_jobs_prompt'];

    private const VALID_VARIANTS = ['male', 'female'];

    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
        int $participantId,
        ?string $lockedVariant = null,
    ): AssessmentItemContent {
        if ($instrument !== GenericAssessmentInstrument::Rmib) {
            throw new AssessmentItemContentUnavailable(
                'RmibItemContentReader only serves the RMIB instrument.',
            );
        }

        $variant = $lockedVariant ?? $this->resolveVariant($participantId);
        if (! in_array($variant, self::VALID_VARIANTS, true)) {
            throw new AssessmentItemContentUnavailable(
                'No valid RMIB item content variant is available for this participant.',
            );
        }

        $authority = DB::table('instrument_versions')
            ->where('code', 'rmib_items')
            ->where('is_active', true)
            ->select(['version', 'checksum', 'source_text'])
            ->first();

        $verified = $this->verifiedPayload($authority, $variant);

        return new AssessmentItemContent(
            $instrument,
            $verified['version'],
            [['code' => 'POSITIONS', 'items' => $verified['items']]],
            $verified['instructions'],
            $lockedVariant === null ? $variant : null,
        );
    }

    /**
     * Resolves fresh from the participant's CURRENT profile -- only ever
     * called when $lockedVariant was null, i.e. the one allocation-time
     * call for a session that doesn't exist yet. Never called on a read.
     */
    private function resolveVariant(int $participantId): ?string
    {
        if ($participantId < 1) {
            return null;
        }

        $gender = DB::table('participants')->where('id', $participantId)->value('gender');

        return is_string($gender) ? $gender : null;
    }

    /**
     * @return array{
     *     version: string,
     *     items: list<array{group: int, group_letter: string, position: int, job: string}>,
     *     instructions: array{text: string, write_preferred_jobs_prompt: string}
     * }
     */
    private function verifiedPayload(?object $authority, string $variant): array
    {
        $version = $authority->version ?? null;
        $checksum = $authority->checksum ?? null;
        $sourceText = $authority->source_text ?? null;

        if ($authority === null
            || ! is_string($version) || $version === ''
            || ! is_string($checksum) || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
            || ! is_string($sourceText) || $sourceText === ''
            || ! hash_equals($checksum, hash('sha256', $sourceText))) {
            throw new AssessmentItemContentUnavailable('The RMIB items authority is missing or unverifiable.');
        }

        try {
            $decoded = json_decode($sourceText, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AssessmentItemContentUnavailable('The RMIB items payload is not valid JSON.');
        }

        if (! is_array($decoded) || array_keys($decoded) !== self::PAYLOAD_FIELDS
            || ($decoded['status'] ?? null) !== 'final'
            || ! is_array($decoded['positions']) || ! array_is_list($decoded['positions'])
            || count($decoded['positions']) !== self::POSITION_COUNT) {
            throw new AssessmentItemContentUnavailable('The RMIB items payload has an unexpected shape.');
        }

        $jobField = $variant === 'male' ? 'job_male' : 'job_female';
        $items = [];
        foreach ($decoded['positions'] as $offset => $position) {
            // Source order is 9 groups of 12 contiguous positions each
            // (108 = 9x12) -- `position` restarts at 1 every 12 entries,
            // it is NOT a flat 1..108 sequence. `group`/`position` together
            // are what the RMIB scoring rotation actually keys on
            // (category = ((position + group - 2) % 12) + 1 in rmib.json),
            // so both are re-derived from $offset here as the structural
            // proof of exact, unshuffled source order.
            $expectedGroup = intdiv($offset, self::POSITIONS_PER_GROUP) + 1;
            $expectedPosition = ($offset % self::POSITIONS_PER_GROUP) + 1;
            $expectedGroupLetter = chr(ord('A') + $expectedGroup - 1);

            if (! is_array($position) || array_keys($position) !== self::POSITION_FIELDS
                || $position['group'] !== $expectedGroup
                || $position['group_letter'] !== $expectedGroupLetter
                || $position['position'] !== $expectedPosition
                || ! is_string($position['job_male']) || $position['job_male'] === ''
                || ! is_string($position['job_female']) || $position['job_female'] === '') {
                throw new AssessmentItemContentUnavailable('The RMIB items payload contains a malformed position.');
            }

            $items[] = [
                'group' => $position['group'],
                'group_letter' => $position['group_letter'],
                'position' => $position['position'],
                'job' => $position[$jobField],
            ];
        }

        return [
            'version' => $version,
            'items' => $items,
            'instructions' => $this->verifiedInstructions($decoded['instructions']),
        ];
    }

    /** @return array{text: string, write_preferred_jobs_prompt: string} */
    private function verifiedInstructions(mixed $instructions): array
    {
        if (! is_array($instructions) || array_keys($instructions) !== self::INSTRUCTIONS_FIELDS
            || ! is_string($instructions['text']) || $instructions['text'] === ''
            || ! is_string($instructions['write_preferred_jobs_prompt'])
            || $instructions['write_preferred_jobs_prompt'] === '') {
            throw new AssessmentItemContentUnavailable('The RMIB items payload has malformed instructions.');
        }

        return [
            'text' => $instructions['text'],
            'write_preferred_jobs_prompt' => $instructions['write_preferred_jobs_prompt'],
        ];
    }
}
