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
    const [confirmations, setConfirmations] = useState(0);
    const [legalPending, setLegalPending] = useState(true);
    const [formRevision, setFormRevision] = useState(0);
    const [psychotestRevision, setPsychotestRevision] = useState(0);
    const [dassRevision, setDassRevision] = useState(0);
    const [keyboard, setKeyboard] = useState('Belum ada input keyboard.');
    const [focus, setFocus] = useState('Belum ada fokus.');
    const base = scenarios[scenario];
    const screen: IntegratedCheckoutProps['screen'] =
        base.state === 'ready'
            ? {
                  state: 'ready',
                  summary: {
                      ...base.summary,
                      formKey: `${base.summary.formKey}:${formRevision}`,
                      consents: {
                          ...base.summary.consents,
                          legalReviewPending: legalPending,
                          psychotest:
                              base.summary.consents.psychotest.state ===
                              'required'
                                  ? {
                                        state: 'required',
                                        document: {
                                            ...base.summary.consents.psychotest
                                                .document,
                                            version: `${base.summary.consents.psychotest.document.version}:${psychotestRevision}`,
                                        },
                                    }
                                  : base.summary.consents.psychotest,
                          dass:
                              base.summary.consents.dass.state === 'required'
                                  ? {
                                        state: 'required',
                                        document: {
                                            ...base.summary.consents.dass
                                                .document,
                                            version: `${base.summary.consents.dass.document.version}:${dassRevision}`,
                                        },
                                    }
                                  : base.summary.consents.dass,
                      },
                  },
              }
            : base;

    return (
        <div
            onKeyDownCapture={(event) =>
                setKeyboard(
                    `key=${event.key}; trusted=${event.nativeEvent.isTrusted}`,
                )
            }
            onFocusCapture={(event) => {
                const target = event.target;
                setFocus(
                    target.getAttribute('aria-label') ??
                        target.closest('label')?.textContent?.trim() ??
                        target.textContent?.trim().slice(0, 100) ??
                        target.tagName,
                );
            }}
        >
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
                    <label className="flex min-h-11 items-center gap-2">
                        <input
                            type="checkbox"
                            checked={legalPending}
                            onChange={(event) =>
                                setLegalPending(event.target.checked)
                            }
                        />
                        Legal review pending (fixture)
                    </label>
                    <button
                        type="button"
                        className="min-h-11 rounded border border-slate-500 bg-white px-3"
                        onClick={() => setFormRevision((value) => value + 1)}
                    >
                        Ganti formKey fixture
                    </button>
                    <button
                        type="button"
                        className="min-h-11 rounded border border-slate-500 bg-white px-3"
                        onClick={() =>
                            setPsychotestRevision((value) => value + 1)
                        }
                    >
                        Revisi versi psikotes fixture
                    </button>
                    <button
                        type="button"
                        className="min-h-11 rounded border border-slate-500 bg-white px-3"
                        onClick={() => setDassRevision((value) => value + 1)}
                    >
                        Revisi versi DASS fixture
                    </button>
                    <button
                        type="button"
                        className="min-h-11 rounded border border-slate-500 bg-white px-3"
                        onClick={() => {
                            // Programmatic regression probe for the handler, NOT native keyboard evidence.
                            document
                                .querySelector<HTMLFormElement>(
                                    'form[aria-label="Konfirmasi checkout"]',
                                )
                                ?.requestSubmit();
                        }}
                    >
                        Uji handler requestSubmit (bukan keyboard)
                    </button>
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
                <output aria-label="Jumlah konfirmasi">{confirmations}</output>
                <output
                    aria-label="Input keyboard terakhir"
                    className="block text-sm"
                >
                    {keyboard}
                </output>
                <output
                    aria-label="Fokus terakhir"
                    className="block text-sm wrap-anywhere"
                >
                    {focus}
                </output>
                <p className="text-sm">
                    Fixture revision: form {formRevision}; psikotes{' '}
                    {psychotestRevision}; DASS {dassRevision}. Toggle legal
                    hanya mengubah data sintetis, bukan mengesahkan naskah.
                </p>
            </section>
            <IntegratedCheckout
                key={scenario}
                screen={screen}
                busy={busy}
                feedback={feedback}
                onConfirm={
                    callbacks
                        ? (confirmation) => {
                              setConfirmations((value) => value + 1);
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
        </div>
    );
}

const previewRoot = createRoot(document.getElementById('root')!);
previewRoot.render(<Preview />);
import.meta.hot?.dispose(() => previewRoot.unmount());
