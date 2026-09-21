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
 * F2 item-delivery Stage 2 (2026-09-21). Reads the Kraepelin number grid
 * from the versioned `instrument_versions` authority, code=kraepelin_grid --
 * deliberately a separate row from code=kraepelin (that one holds the
 * SCORING norms: hanker_formula, score_bands; this one holds the raw item
 * numbers). CLAUDE.md keeps norms/lookup tables and instrument item data on
 * separate paths into the app; sharing one row would blur that line.
 *
 * Contract decision, communicated to the coordinator for GLM's
 * `grid-column.ts` (2026-09-21): items are delivered ALREADY IN
 * ADMINISTRATION ORDER, not sheet order. `kraepelin_grid.json`'s `grid` is
 * stored in sheet order (row 0 = top of the printed sheet, verified against
 * the scanned sheet image -- see
 * tasks/handoffs/f2/verifikasi-kraepelin-2026-09-21.md). Administration
 * itself works bottom-to-top ("jumlahkan dari bawah ke atas", CLAUDE.md),
 * so this reader reverses each column HERE, once, server-side, before it
 * ever reaches a client: item position 1 is the bottom-most number on the
 * sheet, position 28 is the top-most. A client renders `items` in list
 * order with no reordering step of its own -- CLAUDE.md forbids
 * timer/scoring logic in the frontend, and a bottom-to-top flip is exactly
 * the kind of correctness-critical transformation ("one misplaced reversal
 * pairs every participant's answer with the wrong pair of numbers") that
 * belongs in exactly one place, proven by one test, rather than depend on a
 * second implementation staying in lockstep with this one.
 *
 * $participantId/$lockedVariant (interface, 2026-09-21): ignored -- Kraepelin
 * content has no per-participant variant axis, every participant sees the
 * same grid.
 */
final readonly class KraepelinItemContentReader implements AssessmentItemContentAuthority
{
    private const PAYLOAD_FIELDS = ['version', 'grid', 'sha256', 'numbers_per_column', 'answer_slots_per_column'];

    private const COLUMNS = 50;

    private const NUMBERS_PER_COLUMN = 28;

    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
        int $participantId,
        ?string $lockedVariant = null,
    ): AssessmentItemContent {
        if ($instrument !== GenericAssessmentInstrument::Kraepelin) {
            throw new AssessmentItemContentUnavailable(
                'KraepelinItemContentReader only serves the Kraepelin instrument.',
            );
        }

        $authority = DB::table('instrument_versions')
            ->where('code', 'kraepelin_grid')
            ->where('is_active', true)
            ->select(['version', 'checksum', 'source_text'])
            ->first();

        $grid = $this->verifiedGridByColumn($authority);

        $subtests = [];
        foreach ($grid['columns'] as $columnIndex => $sheetOrderColumn) {
            $items = [];
            foreach (array_reverse($sheetOrderColumn) as $offset => $value) {
                $items[] = ['position' => $offset + 1, 'value' => $value];
            }
            $subtests[] = [
                'code' => sprintf('col_%02d', $columnIndex + 1),
                'items' => $items,
            ];
        }

        return new AssessmentItemContent($instrument, $grid['version'], $subtests);
    }

    /** @return array{version: string, columns: list<list<int>>} */
    private function verifiedGridByColumn(?object $authority): array
    {
        $version = $authority->version ?? null;
        $checksum = $authority->checksum ?? null;
        $sourceText = $authority->source_text ?? null;

        if ($authority === null
            || ! is_string($version) || $version === ''
            || ! is_string($checksum) || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
            || ! is_string($sourceText) || $sourceText === ''
            || ! hash_equals($checksum, hash('sha256', $sourceText))) {
            throw new AssessmentItemContentUnavailable('The Kraepelin grid authority is missing or unverifiable.');
        }

        try {
            $decoded = json_decode($sourceText, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AssessmentItemContentUnavailable('The Kraepelin grid payload is not valid JSON.');
        }

        if (! is_array($decoded) || array_keys($decoded) !== self::PAYLOAD_FIELDS || ! is_array($decoded['grid'])
            || count($decoded['grid']) !== self::NUMBERS_PER_COLUMN) {
            throw new AssessmentItemContentUnavailable('The Kraepelin grid payload has an unexpected shape.');
        }

        $columns = array_fill(0, self::COLUMNS, []);
        foreach ($decoded['grid'] as $sheetRow) {
            if (! is_array($sheetRow) || count($sheetRow) !== self::COLUMNS || ! array_is_list($sheetRow)) {
                throw new AssessmentItemContentUnavailable('The Kraepelin grid payload has an unexpected shape.');
            }
            foreach ($sheetRow as $columnIndex => $value) {
                if (! is_int($value) || $value < 1 || $value > 9) {
                    throw new AssessmentItemContentUnavailable('The Kraepelin grid payload contains a non-digit value.');
                }
                $columns[$columnIndex][] = $value;
            }
        }

        return ['version' => $version, 'columns' => array_values($columns)];
    }
}
