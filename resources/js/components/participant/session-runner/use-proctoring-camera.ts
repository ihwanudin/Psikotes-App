import { useCallback, useEffect, useRef, useState } from 'react';

import { computeContainFitSize } from '../proctoring/capture-frame-sizing.ts';
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

export type CaptureFrameOptions = {
    /** Bounding box the captured frame is shrunk to fit within,
     * preserving aspect ratio — never a constant here, always supplied
     * by the caller (ultimately server configuration; owner decision
     * item 14, PR #81, currently 480x360). See
     * capture-frame-sizing.ts's module doc for the exact fit rule. */
    maxWidth: number;
    maxHeight: number;
    /** JPEG quality in [0, 1] — also caller-supplied, never a constant
     * here (owner decision item 14: ~0.6, but that value belongs to
     * whoever calls this, not this hook). */
    jpegQuality: number;
};

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
    /** Manually retries after an interruption. A no-op unless `status`
     * is currently `interrupted` or `reactivation_failed` — same guard
     * as the automatic `visibilitychange`/`focus` retry this hook
     * already wires up (see the effect below), so a caller (e.g. a
     * "Coba aktifkan kamera lagi" button) reports through the same
     * `camera_reactivation_*` event kinds instead of the unrelated
     * `activate()` branches, which would otherwise report nothing at
     * all on success and the wrong kind on failure. */
    reactivate: () => Promise<void>;
    /** Grabs one frame from the live camera stream as a JPEG `Blob`,
     * fit within `maxWidth`x`maxHeight` per capture-frame-sizing.ts.
     * Resolves `null` — never rejects — whenever there is no frame to
     * take: camera not `active`, stream not yet producing video
     * dimensions, or canvas export failing. A missing frame is a normal
     * condition for a periodic-capture caller to expect and record as a
     * fact, not a failure (see
     * tasks/handoffs/f7/proctoring-camera-interruption-plan-2026-09-21.md
     * §6 item 5). The underlying `MediaStream` itself is never exposed
     * — this is the only way to get pixel data out of this hook,
     * exactly the design Lead chose over an alternative `getStream()`
     * accessor. */
    captureFrame: (options: CaptureFrameOptions) => Promise<Blob | null>;
};

const VIDEO_CONSTRAINTS: MediaStreamConstraints = { video: true };

export function useProctoringCamera({
    reporter = noopProctoringReporter,
    getUserMedia,
}: UseProctoringCameraOptions = {}): UseProctoringCameraResult {
    const controllerRef = useRef<CameraController | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    // Lazily created on the first captureFrame() call, reused after
    // that — never attached to the visible DOM (created via
    // document.createElement, never appended), so capture never affects
    // layout or shows the participant a second, uncontrolled camera
    // preview.
    const captureVideoRef = useRef<HTMLVideoElement | null>(null);
    const captureCanvasRef = useRef<HTMLCanvasElement | null>(null);
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
            // 'mute' is the signal that actually fires on iOS Safari and
            // several Android browsers when the camera is backgrounded
            // or taken by another app — 'ended' never comes on those
            // platforms; the track just stops producing frames while
            // staying readyState === 'live' (2026-09-21 fix, Lead's
            // review of PR #91). 'unmute' on that same still-live track
            // means the camera resumed without ever having actually
            // stopped, so it goes straight back to active instead of
            // through a fresh requestStream() round trip.
            track?.addEventListener('mute', () => {
                controllerRef.current?.handleStreamEnded();
            });
            track?.addEventListener('unmute', () => {
                controllerRef.current?.handleStreamResumed();
            });

            return 'granted';
        }

        const controller = createCameraController({ requestStream, reporter });
        controllerRef.current = controller;
        const unsubscribe = controller.subscribe(setStatus);

        // Mobile app-switch/lock-screen: the camera stream dies while the
        // tab is hidden (SPEC.md 8A.2), so returning to the tab is
        // treated as a reactivation trigger. reactivate() is itself a
        // no-op unless status is currently 'interrupted' or
        // 'reactivation_failed' (2026-09-21: widened from 'interrupted'
        // only, so a later visibilitychange/focus keeps retrying instead
        // of permanently giving up after one failed attempt — see
        // tasks/handoffs/f7/proctoring-camera-interruption-plan-2026-09-21.md
        // §2), so calling it unconditionally here is safe.
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

    const reactivate = useCallback((): Promise<void> => {
        return controllerRef.current?.reactivate() ?? Promise.resolve();
    }, []);

    const captureFrame = useCallback(
        async (options: CaptureFrameOptions): Promise<Blob | null> => {
            const stream = streamRef.current;

            // Read the controller's own status directly rather than the
            // `status` state closed over here — the controller is the
            // source of truth and is always current, while `status`
            // could theoretically lag by a render. A stream can also
            // still be sitting in `streamRef` after it has died
            // (handleStreamEnded doesn't clear it — only a fresh
            // requestStream() does), so "not active" is checked
            // explicitly rather than inferring liveness from the
            // stream's mere presence.
            if (!stream || controllerRef.current?.getStatus() !== 'active') {
                return null;
            }

            // Belt-and-suspenders against the inherent gap between a
            // track actually muting and this hook's 'mute' listener
            // having run yet: never draw a frame from a track the
            // browser itself says is muted, even if `status` hasn't
            // caught up to 'interrupted' yet — a muted track's last
            // decoded frame would otherwise be captured as a stale or
            // black image instead of correctly reporting no frame.
            if (stream.getVideoTracks()[0]?.muted) {
                return null;
            }

            if (!captureVideoRef.current) {
                const video = document.createElement('video');
                video.muted = true;
                video.playsInline = true;
                captureVideoRef.current = video;
            }

            const video = captureVideoRef.current;

            if (video.srcObject !== stream) {
                video.srcObject = stream;

                try {
                    await video.play();
                } catch {
                    // Autoplay can be rejected in some contexts; the
                    // dimension check below turns that into a clean
                    // `null` rather than a thrown error instead of
                    // surfacing a rejected promise from here.
                }
            }

            if (video.readyState < video.HAVE_CURRENT_DATA) {
                await new Promise<void>((resolve) => {
                    video.addEventListener('loadeddata', () => resolve(), {
                        once: true,
                    });
                });
            }

            if (video.videoWidth === 0 || video.videoHeight === 0) {
                return null;
            }

            const { width, height } = computeContainFitSize(
                { width: video.videoWidth, height: video.videoHeight },
                { maxWidth: options.maxWidth, maxHeight: options.maxHeight },
            );

            if (!captureCanvasRef.current) {
                captureCanvasRef.current = document.createElement('canvas');
            }

            const canvas = captureCanvasRef.current;
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d');

            if (!context) {
                return null;
            }

            context.drawImage(video, 0, 0, width, height);

            return new Promise<Blob | null>((resolve) => {
                canvas.toBlob(
                    (blob) => resolve(blob),
                    'image/jpeg',
                    options.jpegQuality,
                );
            });
        },
        [],
    );

    return { status, activate, deactivate, reactivate, captureFrame };
}
