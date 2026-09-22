import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';

import { SessionRunnerShell } from '../session-runner/session-runner-shell.tsx';
import type { SessionRunnerContext } from '../session-runner/session-runner-shell.tsx';
import type { AutosaveSend } from '../session-runner/autosave-engine.ts';
import type { FetchResumeAnswers } from '../session-runner/resume-answers.ts';
import type {
    FetchSession,
    CurrentSegmentState,
} from '../session-runner/use-assessment-session.ts';
import type { GenericItemsOutcome } from '../session-runner/http-transport.ts';
import type { SubtestNextSend } from '../session-runner/subtest-next.ts';
import type { ProctoringReporter } from '../session-runner/proctoring-reporter.ts';
import type { GetUserMedia } from '../session-runner/use-proctoring-camera.ts';
import { useIstItems } from './use-ist-items.ts';
import { IstSubtestScreen } from './ist-subtest-screen.tsx';
import type { IstSubtestContent } from './ist-items.ts';

/**
 * The real IST test-taking orchestration: subtest sequence
 * (SE→WA→AN→GE→RA→ZR→ME), the reading-gap "Mulai mengerjakan" confirmation,
 * and per-subtest time display — built on `current_segment` from
 * `GET /sessions/:id` (F2 timed-segments, PR #109), never a client-computed
 * timer or sequence (CLAUDE.md: "JANGAN menaruh logika timer atau skoring
 * di frontend").
 *
 * Scope (Lead's instruction, 2026-09-22, narrowed after this session found
 * that merging #118's finalized ME word list without an ME-aware
 * `IstItemContentReader` would fail the WHOLE instrument closed — see
 * `tasks/handoffs/f2/item-delivery-segment-awareness-deferred.md` and this
 * session's report to Lead): SE/WA/AN/GE/RA/ZR run for real — real
 * sequencing, real per-subtest timer display, real "Mulai mengerjakan"
 * confirmation via `POST /sessions/:id/subtest/next`, real autosave. ME
 * (and FA/WU, already out of scope pending #73) is explicitly NOT rendered
 * as if it works: `current_segment.code` pointing at a subtest this
 * component's `/items` fetch didn't receive (`IstItemContentReader`
 * doesn't build ME yet) renders a clearly-labeled "belum didukung" state,
 * never a fixture standing in for real content.
 *
 * `allow_early_finish` is false for every real segment today (Lead's
 * instruction) — completing a subtest's items and confirming still calls
 * the real `subtestNextSend()` (exercising the real endpoint, not a no-op
 * placeholder), which the server is EXPECTED to reject with
 * `invalid_transition` (see `TimedSegmentTransitionPolicy`'s doc) until
 * that subtest's own timer actually elapses. This is surfaced as
 * "menunggu waktu subtes berakhir", not an error.
 */

export type IstAssessmentRunnerProps = {
    fetchSession: FetchSession;
    fetchItems: () => Promise<GenericItemsOutcome>;
    fetchResumeAnswers: FetchResumeAnswers;
    send: AutosaveSend;
    subtestNextSend: SubtestNextSend;
    pollIntervalMs?: number;
    proctoring?: {
        reporter?: ProctoringReporter;
        getUserMedia?: GetUserMedia;
    };
};

export function IstAssessmentRunner({
    fetchSession,
    fetchItems,
    fetchResumeAnswers,
    send,
    subtestNextSend,
    pollIntervalMs,
    proctoring,
}: IstAssessmentRunnerProps) {
    return (
        <SessionRunnerShell
            fetchSession={fetchSession}
            pollIntervalMs={pollIntervalMs}
            proctoring={proctoring}
        >
            {(runner) => (
                <IstAssessmentBody
                    runner={runner}
                    fetchItems={fetchItems}
                    fetchResumeAnswers={fetchResumeAnswers}
                    send={send}
                    subtestNextSend={subtestNextSend}
                />
            )}
        </SessionRunnerShell>
    );
}

const SESSION_STATUS_MESSAGE: Partial<Record<string, string>> = {
    created: 'Sesi belum dimulai.',
    submitted: 'Jawaban sudah dikirim.',
    scored: 'Tes sudah selesai dinilai.',
    expired: 'Waktu pengerjaan sudah habis.',
    void: 'Sesi ini sudah tidak berlaku.',
};

const ITEMS_FINAL_MESSAGE: Partial<Record<string, string>> = {
    not_started: 'Sesi belum dimulai.',
    closed: 'Sesi sudah ditutup.',
    deadline_exceeded: 'Waktu pengerjaan sudah habis.',
    not_found: 'Sesi tidak ditemukan.',
    content_unavailable: 'Isi soal belum tersedia. Coba lagi nanti.',
};

/** Cosmetic-only local countdown for ONE segment's remaining seconds —
 * same "never used to decide anything, only to display" contract as
 * use-assessment-session.ts's own displayRemainingSeconds (see that
 * module's doc). Restarts from the server's own value every time a fresh
 * one arrives (each 30s poll, or after subtestNextSend + reloadSession),
 * so it never drifts far from the server-authoritative number. */
function useDisplaySegmentRemainingSeconds(
    remainingSeconds: number | null,
): number | null {
    const [display, setDisplay] = useState(remainingSeconds);
    // React's documented "adjusting state when a prop changes" pattern
    // (never inside an effect body, which the project's react-hooks/refs
    // rule flags as cascading-render-prone — see use-assessment-session.ts's
    // own tick effect, which only ever calls setState from a setInterval
    // callback, never synchronously in the effect body itself; this mirrors
    // that split): reset the displayed baseline during render, the moment a
    // fresh server value arrives.
    const [previous, setPrevious] = useState(remainingSeconds);

    if (previous !== remainingSeconds) {
        setPrevious(remainingSeconds);
        setDisplay(remainingSeconds);
    }

    useEffect(() => {
        if (remainingSeconds === null) {
            return;
        }

        const tick = setInterval(() => {
            setDisplay((current) =>
                current !== null && current > 0 ? current - 1 : 0,
            );
        }, 1_000);

        return () => clearInterval(tick);
    }, [remainingSeconds]);

    return display;
}

type IstAssessmentBodyProps = {
    runner: SessionRunnerContext;
    fetchItems: () => Promise<GenericItemsOutcome>;
    fetchResumeAnswers: FetchResumeAnswers;
    send: AutosaveSend;
    subtestNextSend: SubtestNextSend;
};

function IstAssessmentBody({
    runner,
    fetchItems,
    fetchResumeAnswers,
    send,
    subtestNextSend,
}: IstAssessmentBodyProps) {
    const { session, reloadSession, connectivity } = runner;
    const items = useIstItems({
        fetchItems,
        queueRetry: connectivity.queueRetry,
    });

    if (session === null) {
        return (
            <p role="status" aria-busy="true">
                Memuat sesi…
            </p>
        );
    }

    if (session.status !== 'in_progress') {
        return (
            <p role="alert">
                {SESSION_STATUS_MESSAGE[session.status] ??
                    'Sesi tidak dapat dilanjutkan.'}
            </p>
        );
    }

    if (items.state.status === 'loading') {
        return (
            <p role="status" aria-busy="true">
                Memuat soal…
            </p>
        );
    }

    if (items.state.status === 'reconnecting') {
        return (
            <div role="alert" className="flex flex-col items-start gap-3">
                <p className="text-sm text-slate-700">
                    Tidak dapat memuat soal. Coba lagi.
                </p>
                <Button type="button" className="h-11" onClick={items.retry}>
                    Coba lagi
                </Button>
            </div>
        );
    }

    const itemsOutcome = items.state.outcome;

    if (itemsOutcome.type !== 'available') {
        return (
            <p role="alert">
                {ITEMS_FINAL_MESSAGE[itemsOutcome.type] ??
                    'Soal tidak dapat dibaca.'}
            </p>
        );
    }

    const segment = session.currentSegment;

    if (segment === null) {
        return (
            <p role="status" aria-busy="true">
                Menunggu sesi dimulai…
            </p>
        );
    }

    const subtest = itemsOutcome.subtests.find((s) => s.code === segment.code);

    if (subtest === undefined) {
        return (
            <div
                role="status"
                className="rounded-2xl border border-amber-200 bg-amber-50 p-6"
            >
                <p className="text-sm text-amber-900">
                    Subtes berikutnya ({segment.code}) belum didukung sistem —
                    menunggu pembaruan pembaca soal IST. Kembali lagi nanti.
                </p>
            </div>
        );
    }

    return (
        <IstCurrentSegment
            subtest={subtest}
            segment={segment}
            fetchResumeAnswers={fetchResumeAnswers}
            send={send}
            subtestNextSend={subtestNextSend}
            queueRetry={connectivity.queueRetry}
            reloadSession={reloadSession}
        />
    );
}

type IstCurrentSegmentProps = {
    subtest: IstSubtestContent;
    segment: CurrentSegmentState;
    fetchResumeAnswers: FetchResumeAnswers;
    send: AutosaveSend;
    subtestNextSend: SubtestNextSend;
    queueRetry: (retry: () => void) => () => void;
    reloadSession: () => Promise<unknown>;
};

function IstCurrentSegment({
    subtest,
    segment,
    fetchResumeAnswers,
    send,
    subtestNextSend,
    queueRetry,
    reloadSession,
}: IstCurrentSegmentProps) {
    const displayRemainingSeconds = useDisplaySegmentRemainingSeconds(
        segment.remainingSeconds,
    );
    const [confirming, setConfirming] = useState(false);
    const [confirmFailed, setConfirmFailed] = useState(false);
    const [completing, setCompleting] = useState(false);
    const [completionNotice, setCompletionNotice] = useState<string | null>(
        null,
    );

    async function confirmStart(): Promise<void> {
        setConfirming(true);
        setConfirmFailed(false);

        try {
            const outcome = await subtestNextSend();

            if (outcome.type !== 'accepted') {
                setConfirmFailed(true);

                return;
            }

            await reloadSession();
        } catch {
            setConfirmFailed(true);
        } finally {
            setConfirming(false);
        }
    }

    if (segment.startedAt === null) {
        return (
            <div className="flex flex-col gap-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">
                        {subtest.code}
                    </h2>
                    <p className="mt-2 text-sm leading-6 text-slate-700">
                        {subtest.instructions}
                    </p>
                </div>

                {confirmFailed && (
                    <p role="alert" className="text-sm text-red-700">
                        Tidak dapat memulai subtes. Coba lagi.
                    </p>
                )}

                <Button
                    type="button"
                    className="h-11 self-start"
                    onClick={() => void confirmStart()}
                    disabled={confirming}
                >
                    {confirming ? 'Memulai…' : 'Mulai mengerjakan'}
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            {completionNotice !== null && (
                <p role="status" className="text-sm text-slate-600">
                    {completionNotice}
                </p>
            )}
            <IstSubtestScreen
                key={subtest.code}
                subtest={subtest}
                fetchResumeAnswers={fetchResumeAnswers}
                send={send}
                queueRetry={queueRetry}
                remainingSeconds={displayRemainingSeconds ?? 0}
                completing={completing}
                onComplete={() => {
                    setCompleting(true);
                    setCompletionNotice(null);

                    void (async () => {
                        try {
                            const outcome = await subtestNextSend();

                            if (outcome.type === 'accepted') {
                                await reloadSession();

                                return;
                            }

                            if (outcome.type === 'invalid_transition') {
                                setCompletionNotice(
                                    'Semua butir sudah terjawab — tunggu hingga waktu subtes ini berakhir untuk lanjut ke subtes berikutnya.',
                                );

                                return;
                            }

                            setCompletionNotice(
                                'Tidak dapat melanjutkan saat ini. Coba lagi.',
                            );
                        } catch {
                            setCompletionNotice(
                                'Tidak dapat melanjutkan saat ini. Coba lagi.',
                            );
                        } finally {
                            setCompleting(false);
                        }
                    })();
                }}
            />
        </div>
    );
}
