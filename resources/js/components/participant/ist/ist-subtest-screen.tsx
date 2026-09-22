import { useState } from 'react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';

import type { AutosaveSend } from '../session-runner/autosave-engine.ts';
import { useAutosave } from '../session-runner/use-autosave.ts';
import { useResumeAnswers } from '../session-runner/use-resume-answers.ts';
import type { FetchResumeAnswers } from '../session-runner/resume-answers.ts';
import { initialAutosaveRevisionFromResume } from '../session-runner/resume-answers.ts';
import { IstFillInItemView } from './ist-fill-in-item.tsx';
import { IstMultipleChoiceItemView } from './ist-multiple-choice-item.tsx';
import type { IstMultipleChoiceOptionKey } from './ist-items.ts';
import type { IstSubtestContent } from './ist-items.ts';
import {
    createIstSubtestNavigationState,
    goToItem,
    istAnswersFromResumedAnswers,
    next,
    previous,
    selectAnswer,
    unansweredIndices,
} from './ist-subtest-navigation-state.ts';
import type { IstSubtestNavigationState } from './ist-subtest-navigation-state.ts';
import { IstSubtestNav } from './ist-subtest-nav.tsx';
import { IstSubtestSummary } from './ist-subtest-summary.tsx';

/**
 * Renders ONE IST subtest's items, with autosave/resume wired for real —
 * scope Lead approved 2026-09-21 ("komponen per subtes, BUKAN
 * orkestrasi"). Deliberately does NOT:
 * - fetch `/items` itself (the `subtest` prop is handed in by the
 *   caller — no instructions-gate screen here either, per Lead's
 *   explicit exclusion);
 * - decide when this subtest starts/ends, or move to another subtest
 *   (`POST /sessions/:id/subtest/next` doesn't exist yet — see
 *   `tasks/handoffs/f2/ist-reader-and-asset-endpoint.md`'s "Explicitly
 *   deferred" section and Lead's 2026-09-21 reply to the gap-summary
 *   this PR's plan surfaced);
 * - compute, tick, or otherwise act on time — `remainingSeconds` is
 *   rendered exactly as given, nothing else.
 *
 * Session-wide `useResumeAnswers` is fetched here (not by an outer
 * orchestrator, since none exists yet) and filtered down to just this
 * subtest's own items via `istAnswersFromResumedAnswers` — a
 * component-level concern (interpreting resume data for the items this
 * component owns), not the subtest-sequencing orchestration Lead's
 * review excluded.
 */

export type IstSubtestScreenProps = {
    subtest: IstSubtestContent;
    fetchResumeAnswers: FetchResumeAnswers;
    send: AutosaveSend;
    queueRetry: (retry: () => void) => () => void;
    /** Server-given remaining seconds for the session, displayed as-is.
     * This component never derives, ticks, or reacts to this value
     * beyond showing it. */
    remainingSeconds: number;
    onComplete: () => void;
    completing?: boolean;
};

const RESUME_FINAL_MESSAGE: Record<string, string> = {
    closed: 'Sesi sudah ditutup.',
    deadline_exceeded: 'Waktu pengerjaan sudah habis.',
    not_found: 'Sesi tidak ditemukan.',
};

export function IstSubtestScreen({
    subtest,
    fetchResumeAnswers,
    send,
    queueRetry,
    remainingSeconds,
    onComplete,
    completing,
}: IstSubtestScreenProps) {
    const resume = useResumeAnswers({ fetchResumeAnswers, queueRetry });

    if (resume.state.status === 'loading') {
        return (
            <p role="status" aria-busy="true">
                Memuat jawaban tersimpan…
            </p>
        );
    }

    if (resume.state.status === 'reconnecting') {
        return (
            <div role="alert" className="flex flex-col items-start gap-3">
                <p className="text-sm text-slate-700">
                    Tidak dapat memuat jawaban tersimpan. Coba lagi.
                </p>
                <Button type="button" className="h-11" onClick={resume.retry}>
                    Coba lagi
                </Button>
            </div>
        );
    }

    const { outcome } = resume.state;
    const initialRevision = initialAutosaveRevisionFromResume(outcome);

    if (initialRevision === null) {
        return (
            <p role="alert">
                {RESUME_FINAL_MESSAGE[outcome.type] ??
                    'Jawaban tersimpan tidak dapat dibaca.'}
            </p>
        );
    }

    return (
        <IstSubtestReadyScreen
            subtest={subtest}
            initialAnswers={istAnswersFromResumedAnswers(
                subtest.items,
                outcome.type === 'available' ? outcome.answers : [],
            )}
            initialRevision={initialRevision}
            send={send}
            remainingSeconds={remainingSeconds}
            onComplete={onComplete}
            completing={completing}
        />
    );
}

type IstSubtestReadyScreenProps = {
    subtest: IstSubtestContent;
    initialAnswers: (string | null)[];
    initialRevision: number;
    send: AutosaveSend;
    remainingSeconds: number;
    onComplete: () => void;
    completing?: boolean;
};

/**
 * Only ever mounted once `initialAnswers`/`initialRevision` are known —
 * same reason every other instrument's "ReadyRunner" split exists:
 * `useAutosave` captures `initialRevision` once at mount, so this split
 * makes "mount" and "the real starting values are known" the same
 * moment.
 */
function IstSubtestReadyScreen({
    subtest,
    initialAnswers,
    initialRevision,
    send,
    remainingSeconds,
    onComplete,
    completing,
}: IstSubtestReadyScreenProps) {
    const autosave = useAutosave({ initialRevision, send });
    const [nav, setNav] = useState<IstSubtestNavigationState>(() =>
        createIstSubtestNavigationState(subtest.items.length, initialAnswers),
    );
    const [view, setView] = useState<'item' | 'summary'>('item');

    function queueAnswer(itemNo: number, value: string): void {
        if (!autosave.needsReload) {
            autosave.queueChange(itemNo, value);
        }
    }

    function afterNavigate(): void {
        void autosave.flush();
    }

    if (view === 'summary') {
        return (
            <div className="flex flex-col gap-4">
                <RemainingSecondsDisplay remainingSeconds={remainingSeconds} />
                <IstSubtestSummary
                    itemCount={subtest.items.length}
                    unansweredIndices={unansweredIndices(nav)}
                    onJumpToItem={(index) => {
                        setNav((current) => goToItem(current, index));
                        setView('item');
                        afterNavigate();
                    }}
                    onComplete={onComplete}
                    completing={completing}
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

    const currentAnswer = nav.answers[nav.currentIndex];

    // Narrowed by branch, not by casting `currentItem`: `subtest.items`'s
    // element type follows `subtest.answerType` in the IstSubtestContent
    // union (see ist-items.ts), so checking `subtest.answerType` here
    // narrows `subtest.items[...]` correctly in each branch without an
    // unsafe assertion.
    let itemView: ReactNode;

    if (subtest.answerType === 'multiple_choice') {
        const currentItem = subtest.items[nav.currentIndex]!;
        itemView = (
            <IstMultipleChoiceItemView
                item={currentItem}
                itemNumber={nav.currentIndex + 1}
                itemCount={subtest.items.length}
                selected={currentAnswer as IstMultipleChoiceOptionKey | null}
                onSelect={(option) => {
                    setNav((current) => selectAnswer(current, option));
                    queueAnswer(currentItem.item, option);
                }}
            />
        );
    } else {
        const currentItem = subtest.items[nav.currentIndex]!;
        itemView = (
            <IstFillInItemView
                item={currentItem}
                itemNumber={nav.currentIndex + 1}
                itemCount={subtest.items.length}
                answerType={subtest.answerType}
                value={currentAnswer ?? ''}
                onChange={(value) => {
                    setNav((current) => selectAnswer(current, value));
                    queueAnswer(currentItem.item, value);
                }}
            />
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <RemainingSecondsDisplay remainingSeconds={remainingSeconds} />

            {itemView}

            <IstSubtestNav
                itemNumber={nav.currentIndex + 1}
                itemCount={subtest.items.length}
                onPrevious={() => {
                    setNav((current) => previous(current));
                    afterNavigate();
                }}
                onNext={() => {
                    setNav((current) => next(current));
                    afterNavigate();
                }}
                onReviewAnswers={() => {
                    setView('summary');
                    afterNavigate();
                }}
            />
        </div>
    );
}

function RemainingSecondsDisplay({
    remainingSeconds,
}: {
    remainingSeconds: number;
}) {
    const minutes = Math.floor(remainingSeconds / 60);
    const seconds = remainingSeconds % 60;

    return (
        <p
            className="self-end text-sm font-medium text-slate-500"
            aria-live="off"
        >
            Sisa waktu: {minutes}:{String(seconds).padStart(2, '0')}
        </p>
    );
}
