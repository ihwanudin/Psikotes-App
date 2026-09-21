import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { ProctoringConsentScreen } from '../../../resources/js/components/participant/proctoring/proctoring-consent-screen';
import { useFullscreen } from '../../../resources/js/components/participant/proctoring/use-fullscreen';
import { useProctoringCamera } from '../../../resources/js/components/participant/session-runner/use-proctoring-camera';
import type { GetUserMedia } from '../../../resources/js/components/participant/session-runner/use-proctoring-camera';
import './preview.css';

type SimulatedOutcome = 'granted' | 'denied' | 'unavailable';

// Fake MediaStream: use-proctoring-camera.ts only calls getVideoTracks()
// (to attach an 'ended' listener) and getTracks() (to stop on teardown) —
// no real camera hardware needed for this fixture or its browser test.
function fakeStream(): MediaStream {
    const track = {
        stop: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
    };

    return {
        getVideoTracks: () => [track],
        getTracks: () => [track],
    } as unknown as MediaStream;
}

function simulatedGetUserMedia(outcome: SimulatedOutcome): GetUserMedia {
    return () => {
        if (outcome === 'granted') {
            return Promise.resolve(fakeStream());
        }

        if (outcome === 'denied') {
            return Promise.reject(
                new DOMException('Simulated denial', 'NotAllowedError'),
            );
        }

        return Promise.reject(
            new DOMException('Simulated unavailability', 'NotFoundError'),
        );
    };
}

function Session({
    outcome,
    cameraMandatory,
    persistenceEnabled,
}: {
    outcome: SimulatedOutcome;
    cameraMandatory: boolean;
    persistenceEnabled: boolean;
}) {
    const camera = useProctoringCamera({
        getUserMedia: simulatedGetUserMedia(outcome),
    });
    const fullscreen = useFullscreen();
    const [proceeded, setProceeded] = useState(0);

    return (
        <>
            <output aria-label="Jumlah proceed" className="sr-only">
                {proceeded}
            </output>
            <ProctoringConsentScreen
                camera={camera}
                fullscreen={fullscreen}
                cameraMandatory={cameraMandatory}
                persistenceEnabled={persistenceEnabled}
                onProceed={() => setProceeded((value) => value + 1)}
            />
        </>
    );
}

function Preview() {
    const [outcome, setOutcome] = useState<SimulatedOutcome>('granted');
    const [cameraMandatory, setCameraMandatory] = useState(true);
    const [persistenceEnabled, setPersistenceEnabled] = useState(false);
    // Forces the session (and its hooks, which construct their
    // controllers once on mount) to remount whenever a fixture control
    // changes — same pattern as IntegratedCheckout's preview.tsx
    // `key={scenario}`.
    const sessionKey = `${outcome}:${cameraMandatory}:${persistenceEnabled}`;

    return (
        <div>
            <section
                aria-label="Kontrol preview sintetis"
                className="border-brand-gold bg-brand-gold-soft space-y-4 border-b-2 p-4 text-slate-950"
            >
                <p className="font-semibold">
                    PREVIEW INTERNAL · Data sintetis · Kamera dan penyimpanan
                    disimulasikan, tidak ada akses perangkat atau server
                    sungguhan
                </p>
                <div className="flex flex-wrap items-center gap-4">
                    <label className="space-y-1">
                        Hasil kamera simulasi
                        <select
                            aria-label="Hasil kamera simulasi"
                            value={outcome}
                            onChange={(event) =>
                                setOutcome(
                                    event.target.value as SimulatedOutcome,
                                )
                            }
                            className="ml-2 min-h-11 max-w-full rounded border border-slate-500 bg-white px-3"
                        >
                            <option value="granted">granted</option>
                            <option value="denied">denied</option>
                            <option value="unavailable">unavailable</option>
                        </select>
                    </label>
                    <label className="flex min-h-11 items-center gap-2">
                        <input
                            type="checkbox"
                            checked={cameraMandatory}
                            onChange={(event) =>
                                setCameraMandatory(event.target.checked)
                            }
                        />
                        Kamera wajib (cameraMandatory)
                    </label>
                    <label className="flex min-h-11 items-center gap-2">
                        <input
                            type="checkbox"
                            checked={persistenceEnabled}
                            onChange={(event) =>
                                setPersistenceEnabled(event.target.checked)
                            }
                        />
                        Penyimpanan aktif (persistenceEnabled, fixture only —
                        tidak ada backend sungguhan)
                    </label>
                </div>
            </section>
            <Session
                key={sessionKey}
                outcome={outcome}
                cameraMandatory={cameraMandatory}
                persistenceEnabled={persistenceEnabled}
            />
        </div>
    );
}

const previewRoot = createRoot(document.getElementById('root')!);
previewRoot.render(<Preview />);
import.meta.hot?.dispose(() => previewRoot.unmount());
