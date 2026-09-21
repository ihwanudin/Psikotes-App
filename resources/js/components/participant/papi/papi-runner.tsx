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
 * one runner — the scope Lead approved for this increment (2026-09-21):
 * pure components driven by `items` passed in as a prop, not fetched.
 * Wiring to the real `GET /sessions/:id/items` PAPI reader (not built by
 * F2 yet) and to autosave/submit happens in a later PR, once that
 * reader's response shape exists — see the PAPI runner plan.
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
    /** Called whenever an answer changes, with the wire-shape value
     * (`'a'`/`'b'`) already correct — the caller wires this to
     * session-runner's `useAutosave().queueChange`, not built here yet. */
    onAnswerChange?: (itemNo: number, value: PapiChoice) => void;
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
    onAnswerChange,
    onSubmit,
    submitting,
}: PapiRunnerProps) {
    const [nav, setNav] = useState<PapiNavigationState>(() =>
        createPapiNavigationState(items.length),
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
                    }}
                    onSubmit={onSubmit}
                    submitting={submitting}
                />
                <button
                    type="button"
                    onClick={() => setView('item')}
                    className="self-start text-sm text-teal-700 underline-offset-4 hover:underline"
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
                onPrevious={() => setNav((current) => previous(current))}
                onNext={() => setNav((current) => next(current))}
                onReviewAnswers={() => setView('summary')}
            />
        </div>
    );
}
