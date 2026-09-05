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
            <div className="space-y-3 border-t pt-5">
                <h3 className="font-semibold">
                    DASS-21 · persetujuan wajib terpisah
                </h3>
                <p className="text-sm leading-6 text-slate-600">
                    DASS-21 merupakan bagian wajib psikotes. Hasilnya tidak
                    menentukan kelayakan kerja dan data klinis tidak dibagikan
                    kepada cabang pembayar.
                </p>
                {consents.dass.state === 'required' ? (
                    <>
                        <ConsentText document={consents.dass.document} />
                        <label className="flex min-h-11 cursor-pointer items-start gap-3 py-2">
                            <input
                                type="checkbox"
                                name="checkout-dass"
                                checked={dass === true}
                                onChange={(event) =>
                                    onDass(event.target.checked)
                                }
                                required
                                aria-describedby="checkout-dass-help"
                                className="mt-1 size-5 shrink-0 accent-brand-green focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green"
                            />
                            <span className="text-sm leading-6">
                                Saya telah membaca dan menyetujui persetujuan
                                DASS-21. *
                            </span>
                        </label>
                        <p
                            id="checkout-dass-help"
                            className="text-sm text-slate-600"
                        >
                            Persetujuan DASS-21 dicatat terpisah dari
                            persetujuan psikotes utama dan wajib diberikan
                            secara eksplisit.
                        </p>
                    </>
                ) : consents.dass.state === 'accepted' ? (
                    <p className="text-sm text-slate-600">
                        Persetujuan DASS-21 versi {consents.dass.version} sudah
                        tercatat.
                    </p>
                ) : (
                    <p className="text-sm text-slate-600" role="alert">
                        Persetujuan DASS-21 wajib belum tersedia untuk
                        dikonfirmasi. Muat ulang halaman atau hubungi petugas.
                    </p>
                )}
            </div>
        </section>
    );
}
