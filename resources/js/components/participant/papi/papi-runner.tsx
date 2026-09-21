import { useState } from 'react';

import {
    createPapiNavigationState,
    goToItem,
    next,
    previous,
    selectChoice,
    unansweredIndices,
} from './papi-navigation-state.ts';
import type {
    PapiChoice,
    PapiNavigationState,
} from './papi-navigation-state.ts';
import { PapiItem } from './papi-item.tsx';
import { PapiItemNav } from './papi-item-nav.tsx';
import { PapiSummary } from './papi-summary.tsx';

/**
 * Composes the item view, navigation, and pre-submit summary/lock into
 * one runner. `items` is passed in as a prop, not fetched — the caller
 * (`papi-items-runner.tsx`) owns `GET /sessions/:id/items`, resume, and
 * autosave, and hands this component only what it needs to render and to
 * report answer/navigation events back up.
 *
 * Mirrors `database/seeders/data/papi_items.json`'s real item shape
 * (`{item, statement_a, statement_b}`) so wiring the real transport
 * later is a drop-in, not a reshape.
 */

export type PapiRunnerItem = {
    item: number;
    statement_a: string;
    statement_b: string;
};

export type PapiRunnerProps = {
    items: PapiRunnerItem[];
    /** Pre-seeds each item's answer, from a resumed session — same shape
     * `papiAnswersFromResumedAnswers` returns. Omit for a fresh session
     * (every item starts unanswered). */
    initialAnswers?: PapiNavigationState['answers'];
    /** Called whenever an answer changes, with the wire-shape value
     * (`'a'`/`'b'`) already correct — the caller wires this to
     * session-runner's `useAutosave().queueChange`. */
    onAnswerChange?: (itemNo: number, value: PapiChoice) => void;
    /** Called right after the participant leaves the currently-shown item
     * (next/previous/jump-to-item, or opening the review summary) — the
     * caller wires this to `useAutosave().flush()` so an edit made just
     * before navigating isn't left sitting in the debounce window. */
    onAfterNavigate?: () => void;
    /** Called when the participant submits with every item answered.
     * Honest stub: this component has no idea how to actually submit
     * (`POST /sessions/:id/submit` isn't wired here) — the caller
     * decides. Never called while any item is unanswered; see
     * papi-summary.tsx's module doc for why that's a UI mirror of the
     * server rule, not a replacement for it. */
    onSubmit: () => void;
    submitting?: boolean;
};

export function PapiRunner({
    items,
    initialAnswers,
    onAnswerChange,
    onAfterNavigate,
    onSubmit,
    submitting,
}: PapiRunnerProps) {
    const [nav, setNav] = useState<PapiNavigationState>(() =>
        createPapiNavigationState(items.length, initialAnswers),
    );
    const [view, setView] = useState<'item' | 'summary'>('item');

    const currentItem = items[nav.currentIndex]!;

    if (view === 'summary') {
        return (
            <div className="flex flex-col gap-4">
                <PapiSummary
                    itemCount={items.length}
                    unansweredIndices={unansweredIndices(nav)}
                    onJumpToItem={(index) => {
                        setNav((current) => goToItem(current, index));
                        setView('item');
                        onAfterNavigate?.();
                    }}
                    onSubmit={onSubmit}
                    submitting={submitting}
                />
                <button
                    type="button"
                    onClick={() => setView('item')}
                    className="flex min-h-11 items-center self-start px-1 text-sm text-teal-700 underline-offset-4 hover:underline"
                >
                    Kembali ke soal
                </button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <PapiItem
                itemNumber={nav.currentIndex + 1}
                itemCount={items.length}
                statementA={currentItem.statement_a}
                statementB={currentItem.statement_b}
                selected={nav.answers[nav.currentIndex] ?? null}
                onSelect={(choice) => {
                    setNav((current) => selectChoice(current, choice));
                    onAnswerChange?.(currentItem.item, choice);
                }}
            />
            <PapiItemNav
                itemNumber={nav.currentIndex + 1}
                itemCount={items.length}
                onPrevious={() => {
                    setNav((current) => previous(current));
                    onAfterNavigate?.();
                }}
                onNext={() => {
                    setNav((current) => next(current));
                    onAfterNavigate?.();
                }}
                onReviewAnswers={() => {
                    setView('summary');
                    onAfterNavigate?.();
                }}
            />
        </div>
    );
}
