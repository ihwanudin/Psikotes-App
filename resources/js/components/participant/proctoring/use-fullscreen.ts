import { useCallback, useEffect, useRef, useState } from 'react';

import { createFullscreenController } from './fullscreen-controller.ts';
import type {
    FullscreenController,
    FullscreenStatus,
} from './fullscreen-controller.ts';

/**
 * Thin React wrapper around fullscreen-controller.ts, the same split used
 * for use-proctoring-camera.ts / camera-controller.ts. Must be called at
 * the top level of a page component, not inside a render-prop callback
 * (e.g. SessionRunnerShell's `children`) — calling a hook inside a
 * callback that may run a different number of times per render violates
 * React's Rules of Hooks. Pass the returned value down as a plain prop
 * instead (see tasks/handoffs/f7/proctoring-consent-fullscreen-screen-plan-2026-09-21.md
 * §2 for the corrected integration example).
 */

export type UseFullscreenResult = {
    status: FullscreenStatus;
    isSupported: boolean;
    request: () => Promise<void>;
    exit: () => Promise<void>;
};

function detectSupport(): boolean {
    return (
        typeof document !== 'undefined' &&
        typeof document.documentElement?.requestFullscreen === 'function'
    );
}

export function useFullscreen(): UseFullscreenResult {
    const controllerRef = useRef<FullscreenController | null>(null);
    const [status, setStatus] = useState<FullscreenStatus>(() =>
        detectSupport() ? 'inactive' : 'unsupported',
    );

    useEffect(() => {
        const controller = createFullscreenController({
            supported: detectSupport(),
            requestFullscreen: () =>
                document.documentElement.requestFullscreen(),
            exitFullscreen: () => document.exitFullscreen(),
        });
        controllerRef.current = controller;
        const unsubscribe = controller.subscribe(setStatus);

        // Covers the participant pressing Esc or using a browser/OS
        // control directly, not just our own exit() calls.
        function onFullscreenChange(): void {
            if (!document.fullscreenElement) {
                controller.handleExternalExit();
            }
        }

        document.addEventListener('fullscreenchange', onFullscreenChange);

        return () => {
            unsubscribe();
            document.removeEventListener(
                'fullscreenchange',
                onFullscreenChange,
            );
            controllerRef.current = null;
        };
    }, []);

    const request = useCallback((): Promise<void> => {
        return controllerRef.current?.request() ?? Promise.resolve();
    }, []);

    const exit = useCallback((): Promise<void> => {
        return controllerRef.current?.exit() ?? Promise.resolve();
    }, []);

    return { status, isSupported: status !== 'unsupported', request, exit };
}
