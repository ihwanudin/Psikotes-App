import { useState } from 'react';

import type { AutosaveSend } from '../session-runner/autosave-engine.ts';
import { useAutosave } from '../session-runner/use-autosave.ts';
import { useResumeAnswers } from '../session-runner/use-resume-answers.ts';
import type { UseResumeAnswersOptions } from '../session-runner/use-resume-answers.ts';
import type { ResumeAnswersOutcome } from '../session-runner/resume-answers.ts';
import { initialAutosaveRevisionFromResume } from '../session-runner/resume-answers.ts';
import { PapiInstructionsScreen } from './papi-instructions-screen.tsx';
import type { PapiInstructions } from './papi-items.ts';
import { papiAnswersFromResumedAnswers } from './papi-navigation-state.ts';
import type { PapiChoice } from './papi-navigation-state.ts';
import { PapiRunner } from './papi-runner.tsx';
import type { PapiRunnerItem } from './papi-runner.tsx';
import { usePapiItems } from './use-papi-items.ts';
import type { UsePapiItemsOptions } from './use-papi-items.ts';

/**
 * Wires the PAPI prototype (papi-runner.tsx and everything it composes) to
 * the real `GET /sessions/:id/items` PAPI reader (PR #71), the real
 * `GET /sessions/:id/answers` resume reader, and the real
 * `POST /sessions/:id/answers` autosave transport — the three pieces Lead
 * required be genuinely connected (2026-09-21 review) before a PAPI PR can
 * claim to be done; the previous version of this file fetched items only
 * and left `useAutosave` as a documented TODO, meaning the page never
 * actually saved an answer anywhere.
 *
 * `queueRetry` is shared between the items loader and the resume-answers
 * loader on purpose — both are the SAME underlying connectivity signal
 * (`useOfflineQueue()`/`SessionRunnerContext.connectivity`), not two
 * independent detectors.
 *
 * `POST /sessions/:id/submit` itself is still not wired here — `onSubmit`
 * stays the caller's honest stub, same as before this file existed. What
 * IS wired: `flush()` is awaited before `onSubmit` runs, so nothing queued
 * is lost to a submit that races the 2s autosave debounce.
 */

export type PapiItemsRunnerProps = UsePapiItemsOptions & {
    fetchResumeAnswers: UseResumeAnswersOptions['fetchResumeAnswers'];
    send: AutosaveSend;
    onSubmit: () => void;
    submitting?: boolean;
};

const ITEMS_FINAL_MESSAGE: Record<string, string> = {
    not_started: 'Sesi belum dimulai.',
    closed: 'Sesi sudah ditutup.',
    deadline_exceeded: 'Waktu pengerjaan sudah habis.',
    not_found: 'Sesi tidak ditemukan.',
    content_unavailable: 'Isi soal belum tersedia. Coba lagi nanti.',
};

/**
 * `not_started` is deliberately absent here (unlike ITEMS_FINAL_MESSAGE):
 * it just means "nothing autosaved yet", a normal fresh-start case, not an
 * error — `initialAutosaveRevisionFromResume` already returns revision 0
 * for it and the runner mounts normally. `network_error` is included only
 * for type completeness; `useResumeAnswers`'s loader never reaches `ready`
 * with that outcome (see resume-answers-loader.ts's module doc), so this
 * branch is structurally unreachable, not a real UI a participant can see.
 */
const RESUME_FINAL_MESSAGE: Partial<
    Record<ResumeAnswersOutcome['type'], string>
> = {
    closed: 'Sesi sudah ditutup.',
    deadline_exceeded: 'Waktu pengerjaan sudah habis.',
    not_found: 'Sesi tidak ditemukan.',
    network_error: 'Tidak dapat terhubung. Coba lagi.',
};

export function PapiItemsRunner({
    fetchItems,
    fetchResumeAnswers,
    queueRetry,
    send,
    onSubmit,
    submitting,
}: PapiItemsRunnerProps) {
    const items = usePapiItems({ fetchItems, queueRetry });
    const resume = useResumeAnswers({ fetchResumeAnswers, queueRetry });

    if (items.state.status === 'loading' || resume.state.status === 'loading') {
        return (
            <p role="status" aria-busy="true">
                Memuat soal…
            </p>
        );
    }

    if (items.state.status === 'reconnecting') {
        return (
            <div role="alert">
                <p>Tidak dapat terhubung. Coba lagi.</p>
                <button type="button" onClick={items.retry}>
                    Coba lagi
                </button>
            </div>
        );
    }

    if (resume.state.status === 'reconnecting') {
        return (
            <div role="alert">
                <p>Tidak dapat memuat jawaban tersimpan. Coba lagi.</p>
                <button type="button" onClick={resume.retry}>
                    Coba lagi
                </button>
            </div>
        );
    }

    const { outcome: itemsOutcome } = items.state;

    if (itemsOutcome.type !== 'available') {
        return <p role="alert">{ITEMS_FINAL_MESSAGE[itemsOutcome.type]}</p>;
    }

    const { outcome: resumeOutcome } = resume.state;
    const initialRevision = initialAutosaveRevisionFromResume(resumeOutcome);

    if (initialRevision === null) {
        return (
            <p role="alert">
                {RESUME_FINAL_MESSAGE[resumeOutcome.type] ??
                    'Jawaban tersimpan tidak dapat dibaca.'}
            </p>
        );
    }

    return (
        <PapiReadyRunner
            items={itemsOutcome.content.items}
            instructions={itemsOutcome.content.instructions}
            initialAnswers={papiAnswersFromResumedAnswers(
                itemsOutcome.content.items.length,
                resumeOutcome.type === 'available' ? resumeOutcome.answers : [],
            )}
            initialRevision={initialRevision}
            send={send}
            onSubmit={onSubmit}
            submitting={submitting}
        />
    );
}

type PapiReadyRunnerProps = {
    items: PapiRunnerItem[];
    instructions: PapiInstructions;
    initialAnswers: (PapiChoice | null)[];
    initialRevision: number;
    send: AutosaveSend;
    onSubmit: () => void;
    submitting?: boolean;
};

/**
 * Only ever mounted once `initialAnswers`/`initialRevision` are known —
 * `useAutosave` captures `initialRevision` (and `send`) once at mount (see
 * that hook's own doc for why), so this split exists specifically to make
 * "mount" and "the real starting values are known" the same moment,
 * instead of feeding it a placeholder that would silently stick.
 */
function PapiReadyRunner({
    items,
    instructions,
    initialAnswers,
    initialRevision,
    send,
    onSubmit,
    submitting,
}: PapiReadyRunnerProps) {
    const autosave = useAutosave({ initialRevision, send });
    const [showInstructions, setShowInstructions] = useState(true);

    if (showInstructions) {
        return (
            <PapiInstructionsScreen
                instructions={instructions}
                onStart={() => setShowInstructions(false)}
            />
        );
    }

    return (
        <PapiRunner
            items={items}
            initialAnswers={initialAnswers}
            onAnswerChange={(itemNo, value: PapiChoice) => {
                // needsReload recovery (a stale/gapped revision) is a
                // separate increment — see autosave-engine.ts's module doc
                // for why the fix is a full reload, not a queueChange
                // retry. This guard only prevents queueChange's throw from
                // crashing the item screen; the participant's choice is
                // already recorded in papi-runner.tsx's own nav state
                // regardless, so nothing they picked is lost locally.
                if (!autosave.needsReload) {
                    autosave.queueChange(itemNo, value);
                }
            }}
            onAfterNavigate={() => {
                void autosave.flush();
            }}
            onSubmit={() => {
                void (async () => {
                    await autosave.flush();
                    onSubmit();
                })();
            }}
            submitting={submitting}
        />
    );
}
