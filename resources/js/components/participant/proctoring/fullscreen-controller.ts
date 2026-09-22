/**
 * Pure fullscreen status state machine. No React, no DOM — the actual
 * `Element.requestFullscreen()`/`document.exitFullscreen()` calls and
 * `fullscreenchange` listening live in use-fullscreen.ts, the same split
 * used for camera-controller.ts / use-proctoring-camera.ts.
 *
 * SPEC.md 8A.3 calls this deterrence, not a lock: the participant can
 * always exit, and the Fullscreen API spec guarantees that. It also
 * requires requesting fullscreen per subtest, not once for the whole
 * session — that per-subtest wiring belongs to the (not yet built)
 * per-instrument test pages, which will call `request()`/`exit()`
 * themselves. This module and its hook exist now so the proctoring
 * consent screen can read `supported` to choose the right copy variant
 * (SPEC.md 8A.1: never promise fullscreen where the platform doesn't
 * support it — iOS Safari does not implement the standard Fullscreen API
 * on ordinary page content).
 */

export type FullscreenStatus = 'unsupported' | 'inactive' | 'active';

export type FullscreenControllerOptions = {
    supported: boolean;
    requestFullscreen: () => Promise<void>;
    exitFullscreen: () => Promise<void>;
};

export type FullscreenController = {
    getStatus: () => FullscreenStatus;
    /** No-op when unsupported or already active. */
    request: () => Promise<void>;
    /** No-op when unsupported or already inactive. */
    exit: () => Promise<void>;
    /** Call when the browser reports a `fullscreenchange` event and the
     * document is no longer fullscreen — covers the participant pressing
     * Esc or using a browser/OS control directly, not just `exit()`. A
     * no-op unless status is currently `active`. */
    handleExternalExit: () => void;
    subscribe: (listener: (status: FullscreenStatus) => void) => () => void;
};

export function createFullscreenController(
    options: FullscreenControllerOptions,
): FullscreenController {
    let status: FullscreenStatus = options.supported
        ? 'inactive'
        : 'unsupported';
    const listeners = new Set<(status: FullscreenStatus) => void>();

    function setStatus(next: FullscreenStatus): void {
        status = next;

        for (const listener of listeners) {
            listener(status);
        }
    }

    async function request(): Promise<void> {
        if (status !== 'inactive') {
            return;
        }

        try {
            await options.requestFullscreen();
            setStatus('active');
        } catch {
            // Rejected (not a user gesture, permissions-policy denial, or
            // the participant dismissed a browser prompt some UAs show).
            // Deterrence, not evidence on its own — stay 'inactive'
            // rather than treat this as something to retry automatically
            // or report.
        }
    }

    async function exit(): Promise<void> {
        if (status !== 'active') {
            return;
        }

        await options.exitFullscreen();
        setStatus('inactive');
    }

    function handleExternalExit(): void {
        if (status === 'active') {
            setStatus('inactive');
        }
    }

    return {
        getStatus: () => status,
        request,
        exit,
        handleExternalExit,
        subscribe(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
    };
}
