import type {
    CheckoutConsentDocument,
    CheckoutSummary,
} from '@/types/integrated-checkout';

function ConsentText({ document }: { document: CheckoutConsentDocument }) {
    return (
        <details className="rounded-md border border-slate-300 bg-white p-3">
            <summary className="min-h-11 cursor-pointer py-2 font-medium focus-visible:outline-2 focus-visible:outline-brand-green">
                {document.title} · {document.version}
            </summary>
            <p className="mt-3 text-sm leading-7 whitespace-pre-line">
                {document.text}
            </p>
        </details>
    );
}

export function CheckoutConsents({
    consents,
    psychotest,
    dass,
    onPsychotest,
    onDass,
}: {
    consents: CheckoutSummary['consents'];
    psychotest: boolean;
    dass: boolean | null;
    onPsychotest: (value: boolean) => void;
    onDass: (value: boolean) => void;
}) {
    return (
        <section aria-labelledby="checkout-consent-title" className="space-y-5">
            <h2 id="checkout-consent-title" className="text-xl font-semibold">
                Persetujuan Anda
            </h2>
            {consents.legalReviewPending ? (
                <p className="rounded-md bg-brand-gold-soft p-3 text-sm text-slate-900">
                    Naskah persetujuan masih dalam tinjauan legal. Tampilan ini
                    belum siap digunakan untuk persetujuan nyata.
                </p>
            ) : null}
            <div className="space-y-3">
                <h3 className="font-semibold">Psikotes utama</h3>
                {consents.psychotest.state === 'required' ? (
                    <>
                        <ConsentText document={consents.psychotest.document} />
                        <label className="flex min-h-11 cursor-pointer items-start gap-3 py-2">
                            <input
                                type="checkbox"
                                checked={psychotest}
                                onChange={(event) =>
                                    onPsychotest(event.target.checked)
                                }
                                required
                                className="mt-1 size-5 shrink-0 accent-brand-green focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green"
                            />
                            <span className="text-sm leading-6">
                                Saya telah membaca dan menyetujui persetujuan
                                psikotes utama. *
                            </span>
                        </label>
                    </>
                ) : (
                    <p className="text-sm text-slate-600">
                        Persetujuan psikotes utama versi{' '}
                        {consents.psychotest.version} sudah tercatat.
                    </p>
                )}
            </div>
            {consents.dass.state !== 'not_applicable' ? (
                <div className="space-y-3 border-t pt-5">
                    <h3 className="font-semibold">
                        DASS-21 · pilihan terpisah
                    </h3>
                    <p className="text-sm leading-6 text-slate-600">
                        DASS-21 opsional dan tidak menentukan kelayakan kerja.
                        Menolak DASS tidak membatalkan psikotes utama. Data
                        klinis tidak dibagikan kepada cabang pembayar.
                    </p>
                    {consents.dass.state === 'required' ? (
                        <>
                            <ConsentText document={consents.dass.document} />
                            <fieldset className="space-y-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Pilihan DASS-21
                                </legend>
                                {[
                                    {
                                        value: true,
                                        label: 'Saya setuju mengikuti DASS-21.',
                                    },
                                    {
                                        value: false,
                                        label: 'Saya tidak ingin mengikuti DASS-21.',
                                    },
                                ].map((option) => (
                                    <label
                                        key={String(option.value)}
                                        className="flex min-h-11 cursor-pointer items-center gap-3 text-sm"
                                    >
                                        <input
                                            type="radio"
                                            name="checkout-dass"
                                            checked={dass === option.value}
                                            onChange={() =>
                                                onDass(option.value)
                                            }
                                            className="size-5 shrink-0 accent-brand-green focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green"
                                        />
                                        {option.label}
                                    </label>
                                ))}
                                <p className="text-sm text-slate-600">
                                    Belum memilih tidak dianggap setuju; akses
                                    DASS tetap memerlukan persetujuan terpisah.
                                </p>
                            </fieldset>
                        </>
                    ) : (
                        <p className="text-sm text-slate-600">
                            Pilihan tercatat:{' '}
                            {consents.dass.state === 'accepted'
                                ? 'setuju'
                                : 'tidak setuju'}{' '}
                            DASS-21 · versi {consents.dass.version}.
                        </p>
                    )}
                </div>
            ) : null}
        </section>
    );
}
