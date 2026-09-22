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
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off), extended for ME
 * (2026-09-22, per tasks/handoffs/f2/item-delivery-segment-awareness-deferred.md,
 * urgent reorder ahead of PR #118 merging). Reads code=ist_items -- a
 * separate instrument_versions row from code=ist (that one holds SCORING
 * norms: match_strategy, answer keys; this one holds only item
 * text/options). Same code/data separation as the Kraepelin readers.
 *
 * SCOPE: SE/WA/AN/GE/RA/ZR (single-phase) plus ME (two-phase: memorize the
 * word_list, then answer 20 multiple-choice recall items -- the same
 * shape SE/WA/AN use). FA/WU are not in ist_items.json at all yet (pending
 * PR #73), so still deliberately excluded from self::SUBTESTS.
 *
 * ist_items.json's top-level `status` is `"draft"` for as long as ANY
 * subtest is draft -- this reader only has to check that ONE field to fail
 * closed for the whole instrument, exactly the same way
 * KraepelinItemContentReader fails closed on a missing/unverifiable
 * authority row. The per-subtest checks below (exact SUBTESTS set,
 * PAYLOAD_FIELDS) are defense-in-depth for when a subtest not yet supported
 * here (FA/WU) flips to final -- they make sure this reader either builds
 * every final subtest correctly or refuses the whole instrument, never
 * silently drops one. This is exactly the guard that would otherwise have
 * killed the whole instrument the moment ME alone went final (PR #118)
 * while this reader didn't know about it yet -- the reason this change was
 * reordered ahead of everything else in this session's queue.
 *
 * ME's memorize/answer split (per the deferred doc): the word list is only
 * present in the response while the caller's current-segment code is
 * "ME_MEMORIZE"; the recall/answer items only while it is "ME_ANSWER". Both
 * halves of ME's source payload (word_list and items) are still always
 * validated regardless of phase -- fail-closed on malformed data applies
 * uniformly, only which validated half gets INCLUDED in the response is
 * phase-dependent. A null current-segment code (no live session to sweep --
 * AllocateAndStartAssessmentSession's pre-start deliverability check only,
 * whose return value is discarded) still validates both halves but
 * includes neither, so a broken/incomplete ME entry fails deliverability
 * exactly the same way SE-ZR would.
 *
 * No item, option, or word-list field here is ever copied through
 * unfiltered from the decoded JSON: every field in the returned arrays is
 * named explicitly by buildSubtest()/buildMemorizationSubtest()/
 * multipleChoiceItems()/fillInItems()/wordList() below, so a stray scoring
 * field accidentally added to ist_items.json in the future is dropped, not
 * leaked to the client -- match_strategy and answer keys live only in the
 * separate `ist` (scoring norms) row, never read here.
 */
final readonly class IstItemContentReader implements AssessmentItemContentAuthority
{
    private const PAYLOAD_FIELDS = ['version', 'status', 'subtests'];

    private const WORD_LIST_CATEGORIES = ['BUNGA', 'PERKAKAS', 'BURUNG', 'KESENIAN', 'BINATANG'];

    private const ME_MEMORIZE_SEGMENT = 'ME_MEMORIZE';

    private const ME_ANSWER_SEGMENT = 'ME_ANSWER';

    /** @var array<string, array{first: int, count: int, answer_type: string}> */
    private const SUBTESTS = [
        'SE' => ['first' => 1, 'count' => 20, 'answer_type' => 'multiple_choice'],
        'WA' => ['first' => 21, 'count' => 20, 'answer_type' => 'multiple_choice'],
        'AN' => ['first' => 41, 'count' => 20, 'answer_type' => 'multiple_choice'],
        'GE' => ['first' => 61, 'count' => 16, 'answer_type' => 'fill_in_word'],
        'RA' => ['first' => 77, 'count' => 20, 'answer_type' => 'fill_in_numeric'],
        'ZR' => ['first' => 97, 'count' => 20, 'answer_type' => 'fill_in_numeric'],
        'ME' => ['first' => 157, 'count' => 20, 'answer_type' => 'multiple_choice'],
    ];

    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
        ?string $currentSegmentCode = null,
    ): AssessmentItemContent {
        if ($instrument !== GenericAssessmentInstrument::Ist) {
            throw new AssessmentItemContentUnavailable(
                'IstItemContentReader only serves the IST instrument.',
            );
        }

        $authority = DB::table('instrument_versions')
            ->where('code', 'ist_items')
            ->where('is_active', true)
            ->select(['version', 'checksum', 'source_text'])
            ->first();

        $decoded = $this->verifiedPayload($authority);

        $subtests = [];
        foreach (self::SUBTESTS as $code => $spec) {
            $subtestPayload = $decoded['subtests'][$code] ?? null;
            if (! is_array($subtestPayload) || ($subtestPayload['status'] ?? null) !== 'final') {
                throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" is missing or not final.");
            }
            $subtests[] = $code === 'ME'
                ? $this->buildMemorizationSubtest($code, $spec, $subtestPayload, $currentSegmentCode)
                : $this->buildSubtest($code, $spec, $subtestPayload);
        }

        // A subtest this reader does not yet support (FA/WU once #73 lands)
        // must never be silently dropped from a delivery that otherwise
        // claims to be final -- fail the whole instrument instead.
        foreach ($decoded['subtests'] as $code => $subtestPayload) {
            if (is_array($subtestPayload)
                && ($subtestPayload['status'] ?? null) === 'final'
                && ! array_key_exists($code, self::SUBTESTS)) {
                throw new AssessmentItemContentUnavailable(
                    "The IST items payload marks subtest \"{$code}\" final but no reader supports it yet.",
                );
            }
        }

        return new AssessmentItemContent($instrument, $decoded['version'], $subtests);
    }

    /** @return array{version: string, status: string, subtests: array<string, mixed>} */
    private function verifiedPayload(?object $authority): array
    {
        $version = $authority->version ?? null;
        $checksum = $authority->checksum ?? null;
        $sourceText = $authority->source_text ?? null;

        if ($authority === null
            || ! is_string($version) || $version === ''
            || ! is_string($checksum) || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
            || ! is_string($sourceText) || $sourceText === ''
            || ! hash_equals($checksum, hash('sha256', $sourceText))) {
            throw new AssessmentItemContentUnavailable('The IST items authority is missing or unverifiable.');
        }

        try {
            $decoded = json_decode($sourceText, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AssessmentItemContentUnavailable('The IST items payload is not valid JSON.');
        }

        if (! is_array($decoded) || array_keys($decoded) !== self::PAYLOAD_FIELDS
            || ! is_string($decoded['version']) || $decoded['version'] === ''
            || ! is_string($decoded['status'])
            || ! is_array($decoded['subtests'])) {
            throw new AssessmentItemContentUnavailable('The IST items payload has an unexpected shape.');
        }

        if ($decoded['status'] !== 'final') {
            throw new AssessmentItemContentUnavailable('The IST instrument is not final for every subtest yet.');
        }

        /** @var array{version: string, status: string, subtests: array<string, mixed>} $decoded */
        return $decoded;
    }

    /**
     * @param  array{first: int, count: int, answer_type: string}  $spec
     * @param  array<string, mixed>  $payload
     * @return array{code: string, answer_type: string, instructions: string, items: list<array<string, mixed>>}
     */
    private function buildSubtest(string $code, array $spec, array $payload): array
    {
        $instructionsText = $this->instructionsText($code, $payload);

        $items = $payload['items'] ?? null;
        if (! is_array($items)) {
            throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" items are missing or malformed.");
        }

        $builtItems = match ($spec['answer_type']) {
            'multiple_choice' => $this->multipleChoiceItems($code, $items, $spec['first'], $spec['count']),
            'fill_in_word', 'fill_in_numeric' => $this->fillInItems($code, $items, $spec['first'], $spec['count']),
            default => throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" has an unsupported answer type."),
        };

        return [
            'code' => $code,
            'answer_type' => $spec['answer_type'],
            'instructions' => $instructionsText,
            'items' => $builtItems,
        ];
    }

    /**
     * ME (per tasks/handoffs/f2/item-delivery-segment-awareness-deferred.md):
     * word_list is ME's memorize-phase content (five lettered categories,
     * five words each), items is its answer-phase content (the same
     * multiple_choice shape SE/WA/AN use). Both halves are validated
     * unconditionally -- a malformed word_list must fail this instrument's
     * deliverability even during a request that only needs items right now,
     * exactly like every other per-subtest check in this reader is
     * defense-in-depth regardless of which single field a given request
     * happens to want. Only which validated half is INCLUDED in the
     * returned array depends on $currentSegmentCode.
     *
     * @param  array{first: int, count: int, answer_type: string}  $spec
     * @param  array<string, mixed>  $payload
     * @return array{code: string, answer_type: string, instructions: string, items: list<array<string, mixed>>, word_list?: array<string, list<string>>}
     */
    private function buildMemorizationSubtest(string $code, array $spec, array $payload, ?string $currentSegmentCode): array
    {
        $instructionsText = $this->instructionsText($code, $payload);
        $items = $payload['items'] ?? null;
        if (! is_array($items)) {
            throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" items are missing or malformed.");
        }
        $builtItems = $this->multipleChoiceItems($code, $items, $spec['first'], $spec['count']);
        $wordList = $this->wordList($code, $payload['word_list'] ?? null);

        $entry = [
            'code' => $code,
            'answer_type' => $spec['answer_type'],
            'instructions' => $instructionsText,
        ];

        $entry['items'] = $currentSegmentCode === self::ME_ANSWER_SEGMENT || $currentSegmentCode === null
            ? $builtItems
            : [];
        if ($currentSegmentCode === self::ME_MEMORIZE_SEGMENT || $currentSegmentCode === null) {
            $entry['word_list'] = $wordList;
        }

        return $entry;
    }

    /** @param  array<string, mixed>  $payload */
    private function instructionsText(string $code, array $payload): string
    {
        $instructions = $payload['instructions'] ?? null;
        if (! is_array($instructions) || array_keys($instructions) !== ['text']
            || ! is_string($instructions['text']) || $instructions['text'] === '') {
            throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" instructions are missing or malformed.");
        }

        return $instructions['text'];
    }

    /** @return array<string, list<string>> */
    private function wordList(string $code, mixed $wordList): array
    {
        if (! is_array($wordList) || array_keys($wordList) !== self::WORD_LIST_CATEGORIES) {
            throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" word list is missing or malformed.");
        }

        $built = [];
        foreach ($wordList as $category => $words) {
            if (! is_array($words) || ! array_is_list($words) || count($words) !== 5) {
                throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" word list category \"{$category}\" is malformed.");
            }
            foreach ($words as $word) {
                if (! is_string($word) || $word === '') {
                    throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" word list category \"{$category}\" is malformed.");
                }
            }
            $built[$category] = $words;
        }

        return $built;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function multipleChoiceItems(string $code, array $items, int $firstItemNumber, int $expectedCount): array
    {
        if (! array_is_list($items) || count($items) !== $expectedCount) {
            throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" does not have exactly {$expectedCount} items.");
        }

        $built = [];
        $expectedNumber = $firstItemNumber;
        foreach ($items as $item) {
            if (! is_array($item) || ($item['item'] ?? null) !== $expectedNumber) {
                throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" items are not in the expected order.");
            }

            $options = $item['options'] ?? null;
            if (! is_array($options) || array_keys($options) !== ['a', 'b', 'c', 'd', 'e']) {
                throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" item {$expectedNumber} has malformed options.");
            }
            foreach ($options as $option) {
                if (! is_string($option) || $option === '') {
                    throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" item {$expectedNumber} has malformed options.");
                }
            }

            $entry = ['item' => $expectedNumber];
            if (array_key_exists('text', $item)) {
                $text = $item['text'];
                if (! is_string($text) || $text === '') {
                    throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" item {$expectedNumber} has a malformed stem.");
                }
                $entry['text'] = $text;
            }
            $entry['options'] = $options;

            $built[] = $entry;
            $expectedNumber++;
        }

        return $built;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function fillInItems(string $code, array $items, int $firstItemNumber, int $expectedCount): array
    {
        if (! array_is_list($items) || count($items) !== $expectedCount) {
            throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" does not have exactly {$expectedCount} items.");
        }

        $built = [];
        $expectedNumber = $firstItemNumber;
        foreach ($items as $item) {
            if (! is_array($item) || ($item['item'] ?? null) !== $expectedNumber) {
                throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" items are not in the expected order.");
            }
            $text = $item['text'] ?? null;
            if (! is_string($text) || $text === '') {
                throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" item {$expectedNumber} is missing its text.");
            }

            $built[] = ['item' => $expectedNumber, 'text' => $text];
            $expectedNumber++;
        }

        return $built;
    }
}
