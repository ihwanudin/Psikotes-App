import { Clock, Video, VideoOff, WifiOff } from 'lucide-react';
import { useCallback } from 'react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { useAssessmentSession } from './use-assessment-session.ts';
import type {
    AssessmentSessionState,
    FetchSession,
} from './use-assessment-session.ts';
import { useOfflineQueue } from './use-offline-queue.ts';
import type { UseOfflineQueueResult } from './use-offline-queue.ts';
import { useProctoringCamera } from './use-proctoring-camera.ts';
import type {
    GetUserMedia,
    UseProctoringCameraResult,
} from './use-proctoring-camera.ts';
import type { ProctoringReporter } from './proctoring-reporter.ts';

/**
 * Shared chrome for every instrument's test-taking page: the
 * server-authoritative timer, the offline banner, and the camera status
 * badge. This component has no idea what an "item" or "answer" is for
 * any given instrument — it only renders shared state and hands it to
 * `children` via a render prop. Autosave (use-autosave.ts) is
 * deliberately NOT owned here: only the per-instrument page knows what
 * "moving to a different item" means, which is what decides when to
 * flush (see use-autosave.ts's module doc).
 */

export type SessionRunnerContext = {
    session: AssessmentSessionState | null;
    displayRemainingSeconds: number | null;
    loadError: unknown;
    reloadSession: () => Promise<AssessmentSessionState>;
    camera: UseProctoringCameraResult;
    connectivity: UseOfflineQueueResult;
};

export type SessionRunnerShellProps = {
    fetchSession: FetchSession;
    pollIntervalMs?: number;
    proctoring?: {
        reporter?: ProctoringReporter;
        getUserMedia?: GetUserMedia;
    };
    children: (runner: SessionRunnerContext) => ReactNode;
};

function formatRemaining(seconds: number | null): string {
    if (seconds === null) {
        return '--:--';
    }

    const clamped = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(clamped / 60);
    const secs = clamped % 60;

    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

const CAMERA_STATUS_LABEL: Record<UseProctoringCameraResult['status'], string> =
    {
        inactive: 'Kamera belum aktif',
        requesting: 'Meminta akses kamera…',
        active: 'Kamera aktif',
        denied: 'Akses kamera ditolak',
        unavailable: 'Kamera tidak tersedia',
        interrupted: 'Kamera terputus — mencoba menyambungkan ulang',
        reactivating: 'Menyambungkan ulang kamera…',
        reactivation_failed: 'Gagal menyambungkan ulang kamera',
    };

export function SessionRunnerShell({
    fetchSession,
    pollIntervalMs,
    proctoring,
    children,
}: SessionRunnerShellProps) {
    const connectivity = useOfflineQueue();
    const { reportNetworkOutcome } = connectivity;

    // GET /sessions/:id's poll is the app's regular "is the server
    // reachable" signal (Lead's 2026-09-21 decision: no dedicated
    // connectivity-check endpoint) — its outcome feeds the offline queue
    // directly here so the banner reflects it without the caller having
    // to wire this itself. `reportNetworkOutcome` is destructured out
    // (not read as `connectivity.reportNetworkOutcome`) purely so this
    // callback's dependency array can name the function itself, which
    // useOfflineQueue keeps referentially stable across renders.
    const reportingFetchSession = useCallback(async () => {
        try {
            const result = await fetchSession();
            reportNetworkOutcome('success');

            return result;
        } catch (error) {
            reportNetworkOutcome('failure');

            throw error;
        }
    }, [fetchSession, reportNetworkOutcome]);

    const { session, displayRemainingSeconds, loadError, reload } =
        useAssessmentSession({
            fetchSession: reportingFetchSession,
            pollIntervalMs,
        });

    const camera = useProctoringCamera(proctoring);

    return (
        <div className="min-h-screen bg-slate-50 text-slate-950">
            <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/95 backdrop-blur">
                {/* Lead's UI/UX review (ui-ux-pro-max, domain ux, "Layout
                & Responsive"): at 360px the camera badge's retry button
                was clipped off the right edge instead of wrapping —
                flex-wrap on both this row and the camera-status group
                lets the timer, badge, and button each drop to their own
                line as the viewport narrows, verified at 360px with no
                horizontal scroll (see PR description for the
                screenshot). */}
                <div className="mx-auto flex w-full max-w-4xl flex-wrap items-center justify-between gap-4 px-4 py-3">
                    <div className="flex items-center gap-2 font-semibold tabular-nums">
                        <Clock
                            className="size-5 text-teal-700"
                            aria-hidden="true"
                        />
                        <span aria-live="off">
                            {formatRemaining(displayRemainingSeconds)}
                        </span>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 text-sm text-slate-600">
                        {camera.status === 'active' ? (
                            <Video
                                className="size-4 text-teal-700"
                                aria-hidden="true"
                            />
                        ) : (
                            <VideoOff
                                className="size-4 text-slate-400"
                                aria-hidden="true"
                            />
                        )}
                        {/* Lead's UI/UX review (ui-ux-pro-max, domain ux,
                        "Accessibility / Screen Reader"): camera status
                        changes (e.g. going interrupted, or reactivation
                        failing) must reach screen-reader participants,
                        not just sighted ones watching the icon change.
                        role="status" (implies aria-live="polite") is
                        scoped to this span alone, deliberately excluding
                        the timer beside it (aria-live="off" above) —
                        the timer changes every second and would drown
                        out every other announcement if it were live
                        too. Each CAMERA_STATUS_LABEL value already reads
                        as a complete, contextual sentence, so no extra
                        "Status kamera:" prefix is needed for the
                        announcement to make sense on its own. */}
                        <span role="status" aria-atomic="true">
                            {CAMERA_STATUS_LABEL[camera.status]}
                        </span>
                        {camera.status === 'reactivation_failed' ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void camera.reactivate()}
                                className="min-h-11"
                            >
                                Coba aktifkan kamera lagi
                            </Button>
                        ) : null}
                    </div>
                </div>

                {connectivity.state === 'offline' && (
                    <div
                        role="alert"
                        className="flex items-center gap-2 bg-amber-50 px-4 py-2 text-sm text-amber-900"
                    >
                        <WifiOff
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Koneksi terputus. Jawaban akan disimpan otomatis
                            begitu koneksi kembali — jangan memuat ulang halaman
                            selama offline, perubahan yang belum terkirim akan
                            hilang.
                        </span>
                    </div>
                )}
            </header>

            <main className="mx-auto w-full max-w-4xl px-4 py-6">
                {children({
                    session,
                    displayRemainingSeconds,
                    loadError,
                    reloadSession: reload,
                    camera,
                    connectivity,
                })}
            </main>
        </div>
    );
}
