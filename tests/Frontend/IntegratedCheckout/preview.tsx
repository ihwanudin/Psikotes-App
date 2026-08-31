import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { IntegratedCheckout } from '../../../resources/js/components/integrated-checkout/integrated-checkout';
import type { IntegratedCheckoutProps } from '../../../resources/js/types/integrated-checkout';
import { scenarios } from './fixtures';
import './preview.css';

function Preview() {
    const [scenario, setScenario] = useState('Lembaga · menunggu');
    const [callbacks, setCallbacks] = useState(true);
    const [busy, setBusy] = useState(false);
    const [feedback, setFeedback] =
        useState<IntegratedCheckoutProps['feedback']>();
    const [receipt, setReceipt] = useState('Belum ada callback.');

    return (
        <>
            <section
                aria-label="Kontrol preview sintetis"
                className="space-y-4 border-b-2 border-brand-gold bg-brand-gold-soft p-4 text-slate-950"
            >
                <p className="font-semibold">
                    PREVIEW INTERNAL · Data sintetis · Tidak ada pembayaran,
                    persetujuan nyata, atau akses tes
                </p>
                <div className="flex flex-wrap items-center gap-4">
                    <label className="space-y-1">
                        Skenario
                        <select
                            aria-label="Skenario"
                            value={scenario}
                            onChange={(event) => {
                                setScenario(event.target.value);
                                setFeedback(undefined);
                                setReceipt('Belum ada callback.');
                            }}
                            className="ml-2 min-h-11 max-w-full rounded border border-slate-500 bg-white px-3"
                        >
                            {Object.keys(scenarios).map((name) => (
                                <option key={name}>{name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="flex min-h-11 items-center gap-2">
                        <input
                            type="checkbox"
                            checked={callbacks}
                            onChange={(event) =>
                                setCallbacks(event.target.checked)
                            }
                        />
                        Callback simulasi
                    </label>
                    <label className="flex min-h-11 items-center gap-2">
                        <input
                            type="checkbox"
                            checked={busy}
                            onChange={(event) => setBusy(event.target.checked)}
                        />
                        Sedang menyimpan
                    </label>
                    <button
                        type="button"
                        className="min-h-11 rounded border border-slate-500 bg-white px-3"
                        onClick={() =>
                            setFeedback({
                                message:
                                    'Simulasi: nomor belum dapat diverifikasi. Periksa kembali data Anda.',
                                fieldErrors: {
                                    phone: 'Gunakan nomor WhatsApp yang dapat dihubungi.',
                                },
                            })
                        }
                    >
                        Simulasikan error validasi
                    </button>
                </div>
                <output
                    aria-label="Hasil callback simulasi"
                    className="block text-sm wrap-anywhere"
                    role="status"
                >
                    {receipt}
                </output>
            </section>
            <IntegratedCheckout
                key={scenario}
                screen={scenarios[scenario]}
                busy={busy}
                feedback={feedback}
                onConfirm={
                    callbacks
                        ? (confirmation) => {
                              setFeedback(undefined);
                              setReceipt(
                                  `SIMULASI saja: ${JSON.stringify(confirmation)}. Status pembayaran dan akses tidak berubah.`,
                              );
                          }
                        : undefined
                }
                onPayment={
                    callbacks
                        ? () =>
                              setReceipt(
                                  'SIMULASI: callback pembayaran dipanggil. Tidak ada invoice atau navigasi gateway; status tetap sama.',
                              )
                        : undefined
                }
                onRefresh={
                    callbacks
                        ? () =>
                              setReceipt(
                                  'SIMULASI: callback periksa status dipanggil. Data fixture tetap sama.',
                              )
                        : undefined
                }
                onHelp={
                    callbacks
                        ? () =>
                              setReceipt(
                                  'SIMULASI: callback bantuan dipanggil. Tidak mengirim pesan apa pun.',
                              )
                        : undefined
                }
            />
        </>
    );
}

const previewRoot = createRoot(document.getElementById('root')!);
previewRoot.render(<Preview />);
import.meta.hot?.dispose(() => previewRoot.unmount());
