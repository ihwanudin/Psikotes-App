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
 * F2 item-delivery Stage 2 (2026-09-21). Reads the 90 PAPI statement pairs
 * from the versioned `instrument_versions` authority, code=papi_items --
 * deliberately a separate row from code=papi (that one holds the SCORING
 * dimension mapping: `mapping` -- which scale each statement_a/statement_b
 * belongs to). `papi_items.json` itself carries no such mapping; this
 * reader would reject it anyway if it ever did (see PAYLOAD_FIELDS below),
 * same fail-closed shape check as KraepelinItemContentReader.
 *
 * No gender/variant axis (unlike RMIB) -- each item is delivered exactly as
 * printed, in exact source order, one subtest. Rejects a non-`final`
 * `status` the same way it rejects a checksum mismatch: `papi_items.json`
 * is instrument data extracted via tools/extract/, and a draft/unreviewed
 * extraction must never reach a participant.
 *
 * `instructions` (Stage 2 continuation, 2026-09-21, GLM request): the
 * administration text printed alongside the 90 statement pairs
 * (intro/example/answer_sheet_demo/closing) is delivered through the same
 * response as the items themselves, so the client has one source of truth
 * instead of hardcoding this text statically. Whitelisted to exactly the
 * four fields `papi_items.json` actually has -- an unexpected fifth field
 * fails closed the same way a malformed item does.
 *
 * $participantId/$lockedVariant (interface, 2026-09-21): ignored -- PAPI
 * content has no per-participant variant axis.
 */
final readonly class PapiItemContentReader implements AssessmentItemContentAuthority
{
    private const PAYLOAD_FIELDS = ['version', 'status', 'instructions', 'items'];

    private const ITEM_FIELDS = ['item', 'statement_a', 'statement_b'];

    private const ITEM_COUNT = 90;

    private const INSTRUCTIONS_FIELDS = ['intro', 'example', 'answer_sheet_demo', 'closing'];

    private const STATEMENT_PAIR_FIELDS = ['statement_a', 'statement_b'];

    private const ANSWER_SHEET_DEMO_FIELDS = ['label', 'statement_a', 'statement_b'];

    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
        int $participantId,
        ?string $lockedVariant = null,
    ): AssessmentItemContent {
        if ($instrument !== GenericAssessmentInstrument::Papi) {
            throw new AssessmentItemContentUnavailable(
                'PapiItemContentReader only serves the PAPI instrument.',
            );
        }

        $authority = DB::table('instrument_versions')
            ->where('code', 'papi_items')
            ->where('is_active', true)
            ->select(['version', 'checksum', 'source_text'])
            ->first();

        $verified = $this->verifiedPayload($authority);

        return new AssessmentItemContent($instrument, $verified['version'], [
            ['code' => 'ITEMS', 'items' => $verified['items']],
        ], $verified['instructions']);
    }

    /**
     * @return array{
     *     version: string,
     *     items: list<array{item: int, statement_a: string, statement_b: string}>,
     *     instructions: array{intro: string, example: array{statement_a: string, statement_b: string}, answer_sheet_demo: array{label: string, statement_a: string, statement_b: string}, closing: string}
     * }
     */
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
            throw new AssessmentItemContentUnavailable('The PAPI items authority is missing or unverifiable.');
        }

        try {
            $decoded = json_decode($sourceText, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AssessmentItemContentUnavailable('The PAPI items payload is not valid JSON.');
        }

        if (! is_array($decoded) || array_keys($decoded) !== self::PAYLOAD_FIELDS
            || ($decoded['status'] ?? null) !== 'final'
            || ! is_array($decoded['items']) || ! array_is_list($decoded['items'])
            || count($decoded['items']) !== self::ITEM_COUNT) {
            throw new AssessmentItemContentUnavailable('The PAPI items payload has an unexpected shape.');
        }

        $items = [];
        foreach ($decoded['items'] as $offset => $item) {
            if (! is_array($item) || array_keys($item) !== self::ITEM_FIELDS
                || ! is_int($item['item']) || $item['item'] !== $offset + 1
                || ! is_string($item['statement_a']) || $item['statement_a'] === ''
                || ! is_string($item['statement_b']) || $item['statement_b'] === '') {
                throw new AssessmentItemContentUnavailable('The PAPI items payload contains a malformed item.');
            }

            $items[] = [
                'item' => $item['item'],
                'statement_a' => $item['statement_a'],
                'statement_b' => $item['statement_b'],
            ];
        }

        return [
            'version' => $version,
            'items' => $items,
            'instructions' => $this->verifiedInstructions($decoded['instructions']),
        ];
    }

    /**
     * @return array{intro: string, example: array{statement_a: string, statement_b: string}, answer_sheet_demo: array{label: string, statement_a: string, statement_b: string}, closing: string}
     */
    private function verifiedInstructions(mixed $instructions): array
    {
        if (! is_array($instructions) || array_keys($instructions) !== self::INSTRUCTIONS_FIELDS
            || ! is_string($instructions['intro']) || $instructions['intro'] === ''
            || ! is_string($instructions['closing']) || $instructions['closing'] === ''
            || ! is_array($instructions['example']) || array_keys($instructions['example']) !== self::STATEMENT_PAIR_FIELDS
            || ! is_string($instructions['example']['statement_a']) || $instructions['example']['statement_a'] === ''
            || ! is_string($instructions['example']['statement_b']) || $instructions['example']['statement_b'] === ''
            || ! is_array($instructions['answer_sheet_demo'])
            || array_keys($instructions['answer_sheet_demo']) !== self::ANSWER_SHEET_DEMO_FIELDS
            || ! is_string($instructions['answer_sheet_demo']['label']) || $instructions['answer_sheet_demo']['label'] === ''
            || ! is_string($instructions['answer_sheet_demo']['statement_a']) || $instructions['answer_sheet_demo']['statement_a'] === ''
            || ! is_string($instructions['answer_sheet_demo']['statement_b']) || $instructions['answer_sheet_demo']['statement_b'] === '') {
            throw new AssessmentItemContentUnavailable('The PAPI items payload has malformed instructions.');
        }

        return [
            'intro' => $instructions['intro'],
            'example' => [
                'statement_a' => $instructions['example']['statement_a'],
                'statement_b' => $instructions['example']['statement_b'],
            ],
            'answer_sheet_demo' => [
                'label' => $instructions['answer_sheet_demo']['label'],
                'statement_a' => $instructions['answer_sheet_demo']['statement_a'],
                'statement_b' => $instructions['answer_sheet_demo']['statement_b'],
            ],
            'closing' => $instructions['closing'],
        ];
    }
}
