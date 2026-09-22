/**
 * Pure types for one IST subtest's `/items` content — no React, no fetch,
 * testable via `node --test`.
 *
 * Verified against `IstItemContentReader.php` (`origin/main`, commit
 * `c1bc8ac`), not guessed:
 * - `answer_type` is exactly `'multiple_choice'` (SE/WA/AN) or
 *   `'fill_in_word'`/`'fill_in_numeric'` (GE/RA/ZR) — see
 *   `IstItemContentReader::SUBTESTS`.
 * - A multiple-choice item is `{item, text?, options:{a..e}}` — `text`
 *   is only present when the source item has a stem sentence (WA's items
 *   have none, per `multipleChoiceItems()`'s `array_key_exists('text', ...)`
 *   guard — WA is administered from a printed booklet, so the item itself
 *   carries only the five options).
 * - A fill-in item is `{item, text}` — `text` here is the PROMPT (the
 *   word pair / arithmetic problem / number sequence), never an answer.
 * - The `item` field is the GLOBAL 1-based item number across the whole
 *   IST instrument (SE 1-20, WA 21-40, AN 41-60, GE 61-76, RA 77-96,
 *   ZR 97-116) — the exact same number the scoring side expects as
 *   `item_no` (`ScoreSealedIstAnswerSet.php`'s `$globalItem` matches
 *   `IstItemContentReader`'s `$expectedNumber`), so no client-side
 *   recomputation is needed: `item` IS `item_no`.
 * - FA/WU (image-option subtests) and ME (memorization) are deliberately
 *   NOT modeled here — `IstItemContentReader` does not build them yet
 *   (see its own doc comment), and per Lead's 2026-09-21 review, their
 *   `/items` wire shape is F2's contract to define, not this codebase's
 *   to invent. Out of scope for this file until that reader ships.
 */

import type { GenericItemsOutcome } from '../session-runner/http-transport.ts';

export type IstMultipleChoiceOptionKey = 'a' | 'b' | 'c' | 'd' | 'e';

export type IstMultipleChoiceItem = {
    item: number;
    text?: string;
    options: Record<IstMultipleChoiceOptionKey, string>;
};

export type IstFillInItem = {
    item: number;
    text: string;
};

export type IstAnswerType =
    'multiple_choice' | 'fill_in_word' | 'fill_in_numeric';

export type IstSubtestContent =
    | {
          code: string;
          answerType: 'multiple_choice';
          instructions: string;
          items: IstMultipleChoiceItem[];
      }
    | {
          code: string;
          answerType: 'fill_in_word' | 'fill_in_numeric';
          instructions: string;
          items: IstFillInItem[];
      };

/**
 * Maps the wire response's snake_case `answer_type` field to this
 * module's camelCase `IstSubtestContent`. Pure mapping only — no
 * validation beyond what TypeScript's structural typing already gives
 * the caller; the server is the trusted source, same convention as
 * every other instrument's items module in this codebase.
 */
export function istSubtestContentFromWire(wire: {
    code: string;
    answer_type: string;
    instructions: string;
    items: unknown[];
}): IstSubtestContent {
    if (wire.answer_type === 'multiple_choice') {
        return {
            code: wire.code,
            answerType: 'multiple_choice',
            instructions: wire.instructions,
            items: wire.items as IstMultipleChoiceItem[],
        };
    }

    if (
        wire.answer_type === 'fill_in_word' ||
        wire.answer_type === 'fill_in_numeric'
    ) {
        return {
            code: wire.code,
            answerType: wire.answer_type,
            instructions: wire.instructions,
            items: wire.items as IstFillInItem[],
        };
    }

    throw new RangeError(
        `istSubtestContentFromWire: unsupported answer_type "${wire.answer_type}" for subtest "${wire.code}"`,
    );
}

/**
 * The whole-instrument `GET /sessions/:id/items` outcome — every subtest
 * `IstItemContentReader` currently builds (SE/WA/AN/GE/RA/ZR), not one at a
 * time. Mirrors PAPI/RMIB's own `xxxItemsOutcomeFromGeneric` shape (F2
 * http-transport connect, PR #108) over `GenericItemsOutcome`
 * (`../session-runner/http-transport.ts`) — IST didn't have this layer
 * before (`ist-subtest-screen.tsx` took its one subtest as a prop, no
 * fetching of its own, per PR #92's explicit scope), so this is new here,
 * not a port of existing IST code.
 */
export type IstItemsOutcome =
    | { type: 'available'; subtests: IstSubtestContent[] }
    | { type: 'not_started' }
    | { type: 'closed' }
    | { type: 'deadline_exceeded' }
    | { type: 'not_found' }
    | { type: 'content_unavailable' }
    | { type: 'network_error' };

export function istItemsOutcomeFromGeneric(
    outcome: GenericItemsOutcome,
): IstItemsOutcome {
    if (outcome.type !== 'available') {
        return outcome;
    }

    return {
        type: 'available',
        subtests: outcome.content.subtests.map((subtest) =>
            istSubtestContentFromWire(
                subtest as unknown as Parameters<
                    typeof istSubtestContentFromWire
                >[0],
            ),
        ),
    };
}
