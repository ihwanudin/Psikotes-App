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
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off). Reads
 * code=ist_items -- a separate instrument_versions row from code=ist (that
 * one holds SCORING norms: match_strategy, answer keys; this one holds only
 * item text/options). Same code/data separation as the Kraepelin readers.
 *
 * SCOPE: this reader only knows how to build SE/WA/AN/GE/RA/ZR (the six
 * text subtests confirmed `status:"final"` in ist_items.json today). ME's
 * quiz shares the exact multiple-choice shape SE/WA/AN use, but is
 * deliberately NOT in self::SUBTESTS: its memorization word list still has
 * an unresolved content ambiguity (`draft_reason` in the source file --
 * two printed copies disagree, psychologist hasn't picked one) and its
 * delivery needs a two-phase-timer mechanism that does not exist anywhere
 * in the codebase yet (Lead's plan review, Titik 4, held pending
 * psychologist input on whether instruction-reading time is inside or
 * outside the subtest clock). FA/WU are not in ist_items.json at all yet
 * (pending PR #73). None of this matters for what actually ships today,
 * because...
 *
 * ist_items.json's top-level `status` is `"draft"` for as long as ANY
 * subtest (currently ME) is draft -- this reader only has to check that
 * ONE field to fail closed for the whole instrument, exactly the same way
 * KraepelinItemContentReader fails closed on a missing/unverifiable
 * authority row. The per-subtest checks below (exact SUBTESTS set,
 * PAYLOAD_FIELDS) are defense-in-depth for the day status does flip to
 * final -- they make sure this reader either builds every final subtest
 * correctly or refuses the whole instrument, never silently drops one.
 *
 * No item or option field here is ever copied through unfiltered from the
 * decoded JSON: every field in the returned item arrays is named
 * explicitly by buildSubtest()/multipleChoiceItems()/fillInItems() below,
 * so a stray scoring field accidentally added to ist_items.json in the
 * future is dropped, not leaked to the client -- match_strategy and answer
 * keys live only in the separate `ist` (scoring norms) row, never read
 * here.
 */
final readonly class IstItemContentReader implements AssessmentItemContentAuthority
{
    private const PAYLOAD_FIELDS = ['version', 'status', 'subtests'];

    /** @var array<string, array{first: int, count: int, answer_type: string}> */
    private const SUBTESTS = [
        'SE' => ['first' => 1, 'count' => 20, 'answer_type' => 'multiple_choice'],
        'WA' => ['first' => 21, 'count' => 20, 'answer_type' => 'multiple_choice'],
        'AN' => ['first' => 41, 'count' => 20, 'answer_type' => 'multiple_choice'],
        'GE' => ['first' => 61, 'count' => 16, 'answer_type' => 'fill_in_word'],
        'RA' => ['first' => 77, 'count' => 20, 'answer_type' => 'fill_in_numeric'],
        'ZR' => ['first' => 97, 'count' => 20, 'answer_type' => 'fill_in_numeric'],
    ];

    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
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
            $subtests[] = $this->buildSubtest($code, $spec, $subtestPayload);
        }

        // A subtest this reader does not yet support (ME today; FA/WU once
        // #73 lands) must never be silently dropped from a delivery that
        // otherwise claims to be final -- fail the whole instrument instead.
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
        $instructions = $payload['instructions'] ?? null;
        if (! is_array($instructions) || array_keys($instructions) !== ['text']
            || ! is_string($instructions['text']) || $instructions['text'] === '') {
            throw new AssessmentItemContentUnavailable("The IST subtest \"{$code}\" instructions are missing or malformed.");
        }

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
            'instructions' => $instructions['text'],
            'items' => $builtItems,
        ];
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
