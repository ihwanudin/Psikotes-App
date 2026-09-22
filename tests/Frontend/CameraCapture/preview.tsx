import { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { SessionRunnerShell } from '../../../resources/js/components/participant/session-runner/session-runner-shell';
import type { AssessmentSessionState } from '../../../resources/js/components/participant/session-runner/use-assessment-session';
import type { PeriodicPhotoCaptureConfig } from '../../../resources/js/components/participant/session-runner/use-periodic-photo-capture';
import type { GetUserMedia } from '../../../resources/js/components/participant/session-runner/use-proctoring-camera';
import './preview.css';

// Deliberately NOT 480x360/0.6 (the manual capture buttons below use
// that pair) — a distinctive, non-round size/quality proves a periodic
// capture actually used THESE injected values and not some coincidental
// default. Short interval (real seconds, not minutes) so the evidence
// harness can observe several periodic ticks without a long wait; this
// is fixture-only tuning, never a value baked into application code
// (see use-periodic-photo-capture.ts's module doc — CLAUDE.md forbids
// embedding configurable thresholds in code, so the real cadence always
// comes from server config, not from anything here).
const PHOTO_CAPTURE_MIN_INTERVAL_SECONDS = 1.5;
const PHOTO_CAPTURE_MAX_INTERVAL_SECONDS = 2;
const PHOTO_CAPTURE_MAX_WIDTH = 321;
const PHOTO_CAPTURE_MAX_HEIGHT = 241;
const PHOTO_CAPTURE_JPEG_QUALITY = 0.42;

// A real MediaStream from a real <canvas> — canvas.captureStream() is
// implemented by every evergreen browser and needs no camera hardware,
// so this fixture proves captureFrame()'s actual video/canvas pipeline
// (not just the pure sizing math, already covered by
// capture-frame-sizing.test.ts) against a real MediaStream. Deliberately
// 800x450 (16:9), not 4:3, so a capture bounded to 480x360 exercises the
// "shrunk to fit without stretching" rule with a non-trivial answer
// (480x270, not 480x360) rather than a coincidental exact fit.
const SOURCE_WIDTH = 800;
const SOURCE_HEIGHT = 450;

// Module-scope, not React state: use-proctoring-camera.ts documents
// that `getUserMedia` is captured once at mount and never kept "latest"
// (correct for its real caller, navigator.mediaDevices.getUserMedia,
// which never changes identity). A `getUserMedia` closure that instead
// read changing React state would only ever see whatever that state
// was on the FIRST render.
const sourceCanvas = document.createElement('canvas');
sourceCanvas.width = SOURCE_WIDTH;
sourceCanvas.height = SOURCE_HEIGHT;

function startDrawing(): () => void {
    const context = sourceCanvas.getContext('2d')!;
    let hue = 0;
    let frame = 0;

    const draw = () => {
        hue = (hue + 5) % 360;
        context.fillStyle = `hsl(${hue}, 70%, 50%)`;
        context.fillRect(0, 0, SOURCE_WIDTH, SOURCE_HEIGHT);
        context.fillStyle = 'white';
        context.fillRect(50, 50, 100, 100);
        frame = requestAnimationFrame(draw);
    };
    frame = requestAnimationFrame(draw);

    return () => cancelAnimationFrame(frame);
}

// Scripted outcomes for successive getUserMedia calls, driven by the
// fixture's own buttons rather than real device state — lets the
// harness walk the camera through active -> interrupted ->
// reactivation_failed -> active without real hardware. Call 1:
// activate() succeeds. Call 2: the automatic reactivate() (triggered by
// a synthetic 'focus' event, simulating "the participant returned")
// fails. Call 3+: a later reactivate() (the manual retry button, or
// another simulated focus) succeeds.
let callCount = 0;
let liveTrack: MediaStreamTrack | null = null;

const getUserMedia: GetUserMedia = async () => {
    callCount++;

    if (callCount === 2) {
        throw new DOMException('simulated busy device', 'NotFoundError');
    }

    const stream = sourceCanvas.captureStream(10);
    [liveTrack] = stream.getVideoTracks();

    return stream;
};

const fetchSession: () => Promise<AssessmentSessionState> = async () => ({
    sessionId: 'synthetic-session',
    testType: 'ist',
    status: 'in_progress',
    attemptNo: 1,
    startedAt: new Date().toISOString(),
    endsAt: null,
    writeDeadline: null,
    submittedAt: null,
    serverTime: new Date().toISOString(),
    remainingSeconds: 3600,
    answersRevision: 0,
    config: null,
    seed: null,
});

type CaptureResult =
    | { state: 'idle' }
    | { state: 'null' }
    | {
          state: 'captured';
          width: number;
          height: number;
          type: string;
          bytes: number;
      };

type PeriodicCaptureLogEntry = {
    kind: string;
    at: string;
    width: number;
    height: number;
    type: string;
    bytes: number;
};

function Preview() {
    useEffect(() => startDrawing(), []);
    const [result, setResult] = useState<CaptureResult>({ state: 'idle' });
    const imgRef = useRef<HTMLImageElement | null>(null);
    const [periodicLog, setPeriodicLog] = useState<PeriodicCaptureLogEntry[]>(
        [],
    );

    // useMemo, not a fresh object literal every render: photoCapture.md
    // requires this to be referentially stable across renders (see
    // use-periodic-photo-capture.ts's module doc) — a new object every
    // render would tear down and restart the scheduler's cadence
    // constantly, which this fixture's own evidence run would otherwise
    // never be able to distinguish from the real, intended behavior.
    const photoCaptureConfig = useMemo<PeriodicPhotoCaptureConfig>(
        () => ({
            minIntervalSeconds: PHOTO_CAPTURE_MIN_INTERVAL_SECONDS,
            maxIntervalSeconds: PHOTO_CAPTURE_MAX_INTERVAL_SECONDS,
            maxWidth: PHOTO_CAPTURE_MAX_WIDTH,
            maxHeight: PHOTO_CAPTURE_MAX_HEIGHT,
            jpegQuality: PHOTO_CAPTURE_JPEG_QUALITY,
            onCapture: (blob, kind) => {
                const url = URL.createObjectURL(blob);
                const img = new Image();
                img.onload = () => {
                    setPeriodicLog((log) => [
                        ...log,
                        {
                            kind,
                            at: new Date().toLocaleTimeString(),
                            width: img.naturalWidth,
                            height: img.naturalHeight,
                            type: blob.type,
                            bytes: blob.size,
                        },
                    ]);
                    URL.revokeObjectURL(url);
                };
                img.src = url;
            },
        }),
        [],
    );

    return (
        <SessionRunnerShell
            fetchSession={fetchSession}
            proctoring={{ getUserMedia }}
            photoCapture={photoCaptureConfig}
        >
            {(runner) => {
                async function runCapture(maxWidth: number, maxHeight: number) {
                    const blob = await runner.camera.captureFrame({
                        maxWidth,
                        maxHeight,
                        jpegQuality: 0.6,
                    });

                    if (!blob) {
                        setResult({ state: 'null' });

                        return;
                    }

                    const url = URL.createObjectURL(blob);
                    const img = new Image();
                    img.onload = () => {
                        setResult({
                            state: 'captured',
                            width: img.naturalWidth,
                            height: img.naturalHeight,
                            type: blob.type,
                            bytes: blob.size,
                        });
                        URL.revokeObjectURL(url);
                    };
                    img.src = url;
                    imgRef.current = img;
                }

                return (
                    <div className="space-y-4">
                        <section
                            aria-label="Kontrol preview sintetis"
                            className="border-brand-gold bg-brand-gold-soft space-y-3 border-b-2 p-4 text-slate-950"
                        >
                            <p className="font-semibold">
                                PREVIEW INTERNAL · Kamera disimulasikan lewat
                                canvas.captureStream() — tidak ada akses
                                perangkat sungguhan
                            </p>
                            <p>Status kamera: {runner.camera.status}</p>
                            <div className="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    className="min-h-11 rounded border border-slate-500 bg-white px-3"
                                    onClick={() =>
                                        void runner.camera.activate()
                                    }
                                >
                                    Aktifkan kamera
                                </button>
                                <button
                                    type="button"
                                    className="min-h-11 rounded border border-slate-500 bg-white px-3"
                                    onClick={() => {
                                        // .stop() on a canvas.captureStream()
                                        // track doesn't reliably fire
                                        // 'ended' in every engine (verified
                                        // directly in this fixture's
                                        // browser); dispatching the event
                                        // is what use-proctoring-camera.ts's
                                        // listener actually reacts to, so
                                        // this simulates the same effect a
                                        // real device disconnecting would
                                        // have.
                                        liveTrack?.stop();
                                        liveTrack?.dispatchEvent(
                                            new Event('ended'),
                                        );
                                    }}
                                >
                                    Putuskan kamera (simulasikan track berakhir)
                                </button>
                                <button
                                    type="button"
                                    className="min-h-11 rounded border border-slate-500 bg-white px-3"
                                    onClick={() =>
                                        window.dispatchEvent(new Event('focus'))
                                    }
                                >
                                    Simulasikan peserta kembali (focus)
                                </button>
                                {/* Dispatching a synthetic 'mute'/'unmute'
                                Event exercises the status transition
                                (use-proctoring-camera.ts's listeners react
                                to the event type, not to track.muted
                                itself) — it does not flip the track's own
                                read-only `.muted` property, so this proves
                                the primary mute->interrupted->active path
                                but not captureFrame()'s extra `.muted`
                                belt-and-suspenders check, which depends on
                                a real muted track. */}
                                <button
                                    type="button"
                                    className="min-h-11 rounded border border-slate-500 bg-white px-3"
                                    onClick={() =>
                                        liveTrack?.dispatchEvent(
                                            new Event('mute'),
                                        )
                                    }
                                >
                                    Mute kamera (simulasikan iOS app-switch)
                                </button>
                                <button
                                    type="button"
                                    className="min-h-11 rounded border border-slate-500 bg-white px-3"
                                    onClick={() =>
                                        liveTrack?.dispatchEvent(
                                            new Event('unmute'),
                                        )
                                    }
                                >
                                    Unmute kamera (simulasikan kembali)
                                </button>
                                <button
                                    type="button"
                                    className="min-h-11 rounded border border-slate-500 bg-white px-3"
                                    onClick={() => void runCapture(480, 360)}
                                >
                                    Capture (480x360)
                                </button>
                                <button
                                    type="button"
                                    className="min-h-11 rounded border border-slate-500 bg-white px-3"
                                    onClick={() => void runCapture(100, 100)}
                                >
                                    Capture (100x100)
                                </button>
                                <button
                                    type="button"
                                    className="min-h-11 rounded border border-slate-500 bg-white px-3"
                                    onClick={() =>
                                        void runner.photoCapture.captureNow(
                                            'session_submit',
                                        )
                                    }
                                >
                                    Trigger capture sekarang (session_submit)
                                </button>
                            </div>
                        </section>
                        <output
                            aria-label="Hasil capture"
                            className="block px-4"
                        >
                            {result.state === 'idle' && 'Belum ada capture.'}
                            {result.state === 'null' &&
                                'Hasil: null (tidak ada frame)'}
                            {result.state === 'captured' &&
                                `Hasil: ${result.width}x${result.height}, type=${result.type}, bytes=${result.bytes}`}
                        </output>
                        <output
                            aria-label="Status penjadwal foto periodik"
                            className="block space-y-1 px-4"
                        >
                            <p
                                data-photo-scheduler-running={String(
                                    runner.photoCapture.isRunning,
                                )}
                            >
                                Penjadwal foto periodik:{' '}
                                {runner.photoCapture.isRunning
                                    ? 'AKTIF'
                                    : 'BERHENTI'}
                            </p>
                            <ul
                                aria-label="Log capture periodik"
                                data-periodic-log-count={periodicLog.length}
                            >
                                {periodicLog.map((entry, index) => (
                                    <li key={index}>
                                        [{entry.at}] {entry.kind}: {entry.width}
                                        x{entry.height}, type=
                                        {entry.type}, bytes={entry.bytes}
                                    </li>
                                ))}
                            </ul>
                        </output>
                    </div>
                );
            }}
        </SessionRunnerShell>
    );
}

const previewRoot = createRoot(document.getElementById('root')!);
previewRoot.render(<Preview />);
import.meta.hot?.dispose(() => previewRoot.unmount());
