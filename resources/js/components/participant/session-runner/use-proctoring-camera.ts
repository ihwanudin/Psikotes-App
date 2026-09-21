import { useCallback, useEffect, useRef, useState } from 'react';

import { createCameraController } from './camera-controller.ts';
import type {
    CameraController,
    CameraStatus,
    StreamRequestOutcome,
} from './camera-controller.ts';
import { noopProctoringReporter } from './proctoring-reporter.ts';
import type { ProctoringReporter } from './proctoring-reporter.ts';

/**
 * Thin React wrapper around camera-controller.ts: adds the real
 * `getUserMedia` call, track-lifecycle listening, and visibility/focus
 * wiring that the pure controller deliberately knows nothing about. Split
 * the same way use-autosave.ts wraps autosave-engine.ts, specifically so
 * the "mounting never requests a camera" guarantee stays a pure,
 * DOM-free `node --test` assertion on the controller rather than
 * something only provable with a real browser/jsdom.
 *
 * `activate()` is exposed for an explicit caller (eventually SPEC.md
 * 8A.7's consent screen, out of scope this increment) — this hook never
 * calls it itself, on mount or otherwise.
 *
 * The controller is constructed inside a mount-only effect, not during
 * render: its `requestStream` callback needs to reach `streamRef` (a
 * genuinely persistent, mutable place to hold the live MediaStream
 * across separate activate/reactivate calls, so a later call can stop
 * the previous stream and a track's 'ended' listener can reach the
 * controller). The project's react-hooks/refs rule (React Compiler)
 * forbids feeding anything ref-derived into a function call that runs
 * during render — a useState lazy initializer counts, even though it
 * only runs once — so that construction has to happen in an effect
 * instead. Like `send` in use-autosave.ts, `reporter`/`getUserMedia` are
 * captured once at that point, not kept "latest" — effects run before
 * the browser paints and before any real user interaction can occur, so
 * `activate()` always finds a constructed controller by the time
 * anything could call it.
 */

export type GetUserMedia = (
    constraints: MediaStreamConstraints,
) => Promise<MediaStream>;

export type UseProctoringCameraOptions = {
    reporter?: ProctoringReporter;
    /** Injectable for tests; defaults to navigator.mediaDevices.getUserMedia. */
    getUserMedia?: GetUserMedia;
};

export type UseProctoringCameraResult = {
    status: CameraStatus;
    /** The only way to start the camera. Never called by this hook on its
     * own — wire it to an explicit participant action. */
    activate: () => Promise<void>;
    deactivate: () => void;
};

const VIDEO_CONSTRAINTS: MediaStreamConstraints = { video: true };

export function useProctoringCamera({
    reporter = noopProctoringReporter,
    getUserMedia,
}: UseProctoringCameraOptions = {}): UseProctoringCameraResult {
    const controllerRef = useRef<CameraController | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const [status, setStatus] = useState<CameraStatus>('inactive');

    const stopStream = useCallback((): void => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
    }, []);

    useEffect(() => {
        async function requestStream(): Promise<StreamRequestOutcome> {
            stopStream();

            const request =
                getUserMedia ??
                ((constraints: MediaStreamConstraints) =>
                    navigator.mediaDevices.getUserMedia(constraints));

            let stream: MediaStream;

            try {
                stream = await request(VIDEO_CONSTRAINTS);
            } catch (error) {
                // NotAllowedError/PermissionDeniedError is the
                // participant explicitly declining the permission
                // prompt; everything else (no camera hardware, device
                // busy, constraints unsatisfiable, aborted) is treated
                // as 'unavailable' rather than a denial, per SPEC.md
                // 8A's distinction between the two.
                if (
                    error instanceof DOMException &&
                    error.name === 'NotAllowedError'
                ) {
                    return 'denied';
                }

                return 'unavailable';
            }

            streamRef.current = stream;
            const [track] = stream.getVideoTracks();
            track?.addEventListener('ended', () => {
                controllerRef.current?.handleStreamEnded();
            });

            return 'granted';
        }

        const controller = createCameraController({ requestStream, reporter });
        controllerRef.current = controller;
        const unsubscribe = controller.subscribe(setStatus);

        // Mobile app-switch/lock-screen: the camera stream dies while the
        // tab is hidden (SPEC.md 8A.2), so returning to the tab is
        // treated as a reactivation trigger. reactivate() is itself a
        // no-op unless status is currently 'interrupted', so calling it
        // unconditionally here is safe.
        function onVisible(): void {
            if (document.visibilityState === 'visible') {
                void controller.reactivate();
            }
        }
        function onFocus(): void {
            void controller.reactivate();
        }

        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onFocus);

        return () => {
            unsubscribe();
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onFocus);
            stopStream();
            controllerRef.current = null;
        };
        // Constructed once on mount; see module doc for why
        // reporter/getUserMedia are captured at that point rather than
        // tracked across later renders.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [stopStream]);

    const activate = useCallback((): Promise<void> => {
        return controllerRef.current?.activate() ?? Promise.resolve();
    }, []);

    const deactivate = useCallback((): void => {
        stopStream();
        controllerRef.current?.deactivate();
    }, [stopStream]);

    return { status, activate, deactivate };
}
