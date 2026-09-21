import { ShieldCheck, TriangleAlert } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { Button } from '@/components/ui/button';
import type { UseProctoringCameraResult } from '../session-runner/use-proctoring-camera.ts';
import { getProctoringConsentCopy } from './proctoring-consent-copy.ts';
import type { UseFullscreenResult } from './use-fullscreen.ts';

/**
 * SPEC.md 8A.7's consent screen: shown before a session's real content,
 * gating it via the caller's own state (see this component's usage
 * example in tasks/handoffs/f7/proctoring-consent-fullscreen-screen-plan-2026-09-21.md
 * §2) — this component has no opinion on routing/session state, only on
 * what to show and when to call `onProceed`.
 *
 * `camera` must be the SAME useProctoringCamera() instance the session
 * page already has (typically SessionRunnerContext.camera from
 * SessionRunnerShell's children render-prop), not a second call to the
 * hook — activating a different instance would light up a camera this
 * screen never observes. `fullscreen` must come from useFullscreen()
 * called at the top level of the page component (not inside the
 * render-prop callback — that violates React's Rules of Hooks, see the
 * plan doc's integration example) and passed down as a prop.
 *
 * This component never calls fullscreen.request() itself — SPEC.md 8A.3
 * asks for a fullscreen request per subtest, which is the per-instrument
 * page's job once those exist. This screen only reads
 * `fullscreen.isSupported` to choose the right copy variant.
 */

export type ProctoringConsentScreenProps = {
    camera: UseProctoringCameraResult;
    fullscreen: UseFullscreenResult;
    /** Defaults to false: no proctoring ingest endpoint exists yet (see
     * proctoring-reporter.ts's noopProctoringReporter). Do not pass
     * `true` until a real ProctoringReporter is wired in — see plan §4. */
    persistenceEnabled?: boolean;
    /** UU PDP data-subject rights contact — see
     * ProctoringConsentCopyInput's doc comment in proctoring-consent-copy.ts.
     * The default is a placeholder; a real page MUST pass a configured
     * value before go-live. */
    contactReference?: string;
    /** Called once the camera reaches 'active'. There is no other way to
     * proceed — the camera is mandatory for every participant (product
     * owner decision, PR #81 item 11), so this screen offers no "proceed
     * without a camera" action. */
    onProceed: () => void;
};

export function ProctoringConsentScreen({
    camera,
    fullscreen,
    persistenceEnabled = false,
    contactReference,
    onProceed,
}: ProctoringConsentScreenProps) {
    // `onProceed` is read via a ref, not a direct effect dependency: a
    // caller that passes a fresh inline callback every render (easy to do
    // by accident — this component's own preview fixture originally did)
    // would otherwise retrigger this effect on every render once
    // `camera.status` reaches 'active', calling onProceed in an infinite
    // loop. The ref always holds the latest callback without making its
    // identity part of when this effect re-runs.
    const onProceedRef = useRef(onProceed);
    useEffect(() => {
        onProceedRef.current = onProceed;
    });

    useEffect(() => {
        if (camera.status === 'active') {
            onProceedRef.current();
        }
    }, [camera.status]);

    const copy = getProctoringConsentCopy({
        cameraStatus: camera.status,
        fullscreenSupported: fullscreen.isSupported,
        persistenceEnabled,
        contactReference,
    });
    const isRequesting = camera.status === 'requesting';

    return (
        <section
            aria-labelledby="proctoring-consent-title"
            className="mx-auto max-w-xl space-y-5 rounded-lg border bg-white p-6"
        >
            <div className="flex items-start gap-3">
                <ShieldCheck
                    className="mt-1 size-6 shrink-0 text-teal-700"
                    aria-hidden="true"
                />
                <h1
                    id="proctoring-consent-title"
                    className="text-xl font-semibold"
                >
                    {copy.title}
                </h1>
            </div>

            <ul className="space-y-3 text-sm leading-6 text-slate-700">
                {copy.policyParagraphs.map((paragraph, index) => (
                    // Static, ordered content generated once per render
                    // from a fixed-length array — index is a stable key.
                    <li key={index}>{paragraph}</li>
                ))}
            </ul>

            <p className="rounded-md bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                {copy.honestyNote}
            </p>

            <p className="text-sm leading-6 text-slate-600">
                {copy.dataHandlingNote}
            </p>

            <p className="text-sm leading-6 text-slate-600">
                {copy.dataRightsNote}
            </p>

            {copy.notice ? (
                <div
                    role="alert"
                    className="flex items-start gap-3 rounded-md border border-amber-300 bg-amber-50 p-4 text-amber-900"
                >
                    <TriangleAlert
                        className="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <div className="space-y-1 text-sm leading-6">
                        <p className="font-semibold">{copy.notice.heading}</p>
                        <p>{copy.notice.body}</p>
                    </div>
                </div>
            ) : null}

            <div className="flex flex-wrap gap-3 border-t pt-4">
                <Button
                    type="button"
                    disabled={isRequesting}
                    onClick={() => void camera.activate()}
                    className="min-h-11"
                >
                    {copy.primaryAction.label}
                </Button>
            </div>
        </section>
    );
}
