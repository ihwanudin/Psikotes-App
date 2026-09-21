import { useState } from 'react';

import type { AutosaveSend } from '../session-runner/autosave-engine.ts';
import { useAutosave } from '../session-runner/use-autosave.ts';
import { useResumeAnswers } from '../session-runner/use-resume-answers.ts';
import type { UseResumeAnswersOptions } from '../session-runner/use-resume-answers.ts';
import type { ResumeAnswersOutcome } from '../session-runner/resume-answers.ts';
import { initialAutosaveRevisionFromResume } from '../session-runner/resume-answers.ts';
import { RmibInstructionsScreen } from './rmib-instructions-screen.tsx';
import type { RmibGroupState } from './rmib-group-state.ts';
import { rmibGroupStatesFromResumedAnswers } from './rmib-group-state.ts';
import type { RmibInstructions, RmibPosition } from './rmib-items.ts';
import { rmibItemNo } from './rmib-items.ts';
import { RmibRunner } from './rmib-runner.tsx';
import { useRmibItems } from './use-rmib-items.ts';
import type { UseRmibItemsOptions } from './use-rmib-items.ts';

/**
 * Wires the RMIB prototype (rmib-runner.tsx and everything it composes) to
 * `GET /sessions/:id/items` (RMIB), `GET /sessions/:id/answers` (resume),
 * and `POST /sessions/:id/answers` (autosave) — built genuinely connected
 * from the start, unlike the PAPI runner's first version (Lead's
 * 2026-09-21 review of that PR required retrofitting real autosave/resume
 * there; the RMIB plan Lead approved the same day folds that lesson in
 * immediately).
 *
 * `queueRetry` is shared between the items loader and the resume-answers
 * loader — the SAME connectivity signal, not two independent detectors,
 * same as the PAPI runner.
 *
 * `POST /sessions/:id/submit` itself is still not wired — `onSubmit`
 * stays the caller's honest stub. No real `fetch()`-based HTTP transport
 * exists anywhere in this codebase yet for any instrument (a shared,
 * cross-instrument module is planned separately, after RMIB); `send`/
 * `fetchResumeAnswers`/`fetchItems` are all still caller-injected here,
 * same division of labor as the PAPI runner.
 */

export type RmibItemsRunnerProps = UseRmibItemsOptions & {
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
 * `not_started` is deliberately absent (see papi-items-runner.tsx's
 * identical comment on its own RESUME_FINAL_MESSAGE — same reasoning
 * applies unchanged here).
 */
const RESUME_FINAL_MESSAGE: Partial<
    Record<ResumeAnswersOutcome['type'], string>
> = {
    closed: 'Sesi sudah ditutup.',
    deadline_exceeded: 'Waktu pengerjaan sudah habis.',
    not_found: 'Sesi tidak ditemukan.',
    network_error: 'Tidak dapat terhubung. Coba lagi.',
};

export function RmibItemsRunner({
    fetchItems,
    fetchResumeAnswers,
    queueRetry,
    send,
    onSubmit,
    submitting,
}: RmibItemsRunnerProps) {
    const items = useRmibItems({ fetchItems, queueRetry });
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
        <RmibReadyRunner
            positions={itemsOutcome.content.positions}
            instructions={itemsOutcome.content.instructions}
            initialGroupStates={rmibGroupStatesFromResumedAnswers(
                resumeOutcome.type === 'available' ? resumeOutcome.answers : [],
            )}
            initialRevision={initialRevision}
            send={send}
            onSubmit={onSubmit}
            submitting={submitting}
        />
    );
}

type RmibReadyRunnerProps = {
    positions: RmibPosition[];
    instructions: RmibInstructions;
    initialGroupStates: RmibGroupState[];
    initialRevision: number;
    send: AutosaveSend;
    onSubmit: () => void;
    submitting?: boolean;
};

/**
 * Only ever mounted once `initialGroupStates`/`initialRevision` are known
 * — same reason `PapiReadyRunner` (papi-items-runner.tsx) exists: this
 * makes "mount" and "the real starting values are known" the same
 * moment, so `useAutosave`'s captured-once-at-mount `initialRevision`
 * never gets fed a placeholder.
 */
function RmibReadyRunner({
    positions,
    instructions,
    initialGroupStates,
    initialRevision,
    send,
    onSubmit,
    submitting,
}: RmibReadyRunnerProps) {
    const autosave = useAutosave({ initialRevision, send });
    const [showInstructions, setShowInstructions] = useState(true);

    if (showInstructions) {
        return (
            <RmibInstructionsScreen
                instructions={instructions}
                onStart={() => setShowInstructions(false)}
            />
        );
    }

    return (
        <RmibRunner
            positions={positions}
            initialGroupStates={initialGroupStates}
            onGroupChange={(groupNumber, state) => {
                // A single reorder can change more than one position's
                // rank (moving one item shifts everything between its
                // old and new rank) — every position in the group is
                // re-queued, not just the one the participant touched,
                // per the approved RMIB runner plan. needsReload
                // recovery is out of scope here, same guard/reasoning as
                // papi-items-runner.tsx's onAnswerChange.
                if (autosave.needsReload) {
                    return;
                }

                state.order.forEach((position, index) => {
                    const rank = index + 1;
                    autosave.queueChange(
                        rmibItemNo(groupNumber, position),
                        String(rank),
                    );
                });
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
