import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import type {
    CheckoutConfirmation,
    CheckoutProfileKey,
    CheckoutSummary,
    IntegratedCheckoutProps,
} from '@/types/integrated-checkout';
import { CheckoutConsents } from './checkout-consents';
import { CheckoutPayment } from './checkout-payment';
import { CheckoutProfile } from './checkout-profile';

export function CheckoutForm({
    summary,
    busy = false,
    feedback,
    onConfirm,
    onPayment,
    onRefresh,
    onHelp,
}: Omit<IntegratedCheckoutProps, 'screen'> & { summary: CheckoutSummary }) {
    const [values, setValues] = useState<
        Partial<Record<CheckoutProfileKey, string>>
    >({});
    const [psychotest, setPsychotest] = useState(false);
    const [dass, setDass] = useState<boolean | null>(null);
    const errorSummary = useRef<HTMLDivElement>(null);
    const missingFields = summary.profile.filter(
        (field) => field.state === 'missing',
    );
    const needsConfirmation =
        missingFields.length > 0 ||
        summary.consents.psychotest.state === 'required' ||
        summary.consents.dass.state === 'required';
    const canConfirm =
        summary.consents.legalReviewPending === false &&
        (summary.consents.psychotest.state === 'accepted' || psychotest) &&
        !busy &&
        Boolean(onConfirm);

    useEffect(() => {
        if (feedback) {
            errorSummary.current?.focus();
        }
    }, [feedback]);

    return (
        <form
            aria-label="Konfirmasi checkout"
            aria-busy={busy}
            onSubmit={(event) => {
                event.preventDefault();

                if (!canConfirm) {
                    return;
                }

                const confirmation: CheckoutConfirmation = {
                    missingProfile: {},
                };

                for (const field of missingFields) {
                    confirmation.missingProfile[field.key] =
                        values[field.key] ?? '';
                }

                if (
                    summary.consents.psychotest.state === 'required' &&
                    psychotest
                ) {
                    confirmation.psychotest = {
                        version: summary.consents.psychotest.document.version,
                        accepted: true,
                    };
                }

                if (
                    summary.consents.dass.state === 'required' &&
                    dass !== null
                ) {
                    confirmation.dass = {
                        version: summary.consents.dass.document.version,
                        accepted: dass,
                    };
                }

                onConfirm?.(confirmation);
            }}
            className="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]"
        >
            <div className="min-w-0 space-y-7">
                {feedback ? (
                    <div
                        ref={errorSummary}
                        tabIndex={-1}
                        role="alert"
                        className="rounded-lg border border-red-700 bg-red-50 p-4 text-red-900 focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        <h2 className="font-semibold">
                            Verifikasi belum selesai
                        </h2>
                        <p className="mt-2 text-sm">{feedback.message}</p>
                        <ul className="mt-2 space-y-2">
                            {missingFields.map((field) =>
                                feedback.fieldErrors?.[field.key] ? (
                                    <li key={field.key}>
                                        <a
                                            className="text-sm underline"
                                            href={`#checkout-${field.key}`}
                                        >
                                            {field.label}:{' '}
                                            {feedback.fieldErrors[field.key]}
                                        </a>
                                    </li>
                                ) : null,
                            )}
                        </ul>
                    </div>
                ) : null}
                <fieldset
                    disabled={busy}
                    className="min-w-0 space-y-8 rounded-lg border bg-white p-5 sm:p-7"
                >
                    <legend className="sr-only">
                        Data dan persetujuan checkout
                    </legend>
                    <CheckoutProfile
                        fields={summary.profile}
                        values={values}
                        errors={feedback?.fieldErrors}
                        onChange={(key, value) =>
                            setValues((previous) => ({
                                ...previous,
                                [key]: value,
                            }))
                        }
                    />
                    <p className="rounded-md bg-brand-canvas p-3 text-sm leading-6 text-slate-700">
                        {summary.identityMessage}
                    </p>
                    <CheckoutConsents
                        consents={summary.consents}
                        psychotest={psychotest}
                        dass={dass}
                        onPsychotest={setPsychotest}
                        onDass={setDass}
                    />
                    {needsConfirmation ? (
                        <div className="space-y-3 border-t pt-5">
                            <Button
                                type="submit"
                                disabled={!canConfirm}
                                aria-describedby="checkout-confirmation-help"
                                className="min-h-12 w-full bg-brand-green whitespace-normal text-white hover:bg-brand-green-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green"
                            >
                                {busy
                                    ? 'Menyimpan…'
                                    : 'Konfirmasi data dan persetujuan'}
                            </Button>
                            <p
                                id="checkout-confirmation-help"
                                className="text-sm leading-6 text-slate-600"
                            >
                                {summary.consents.legalReviewPending
                                    ? 'Konfirmasi dinonaktifkan selama naskah persetujuan masih ditinjau.'
                                    : !onConfirm
                                      ? 'Konfirmasi belum terhubung pada tampilan ini.'
                                      : 'Konfirmasi dikirim untuk pemeriksaan server; tidak langsung membuka akses tes.'}
                            </p>
                        </div>
                    ) : null}
                </fieldset>
            </div>
            <aside className="min-w-0 space-y-5">
                <CheckoutPayment
                    payment={summary.payment}
                    busy={busy}
                    onPayment={onPayment}
                />
                <section
                    aria-labelledby="checkout-access-title"
                    className="space-y-3 rounded-lg bg-brand-green-deep p-5 text-white"
                >
                    <h2 id="checkout-access-title" className="font-semibold">
                        {summary.access.state === 'ready'
                            ? 'Prasyarat dikonfirmasi server'
                            : 'Akses tes belum dibuka'}
                    </h2>
                    <p className="text-sm leading-6">
                        {summary.access.message}
                    </p>
                </section>
                <div className="flex flex-wrap gap-3">
                    {onRefresh ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={onRefresh}
                            className="min-h-11"
                        >
                            Periksa status
                        </Button>
                    ) : null}
                    {onHelp ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={onHelp}
                            className="min-h-11"
                        >
                            Hubungi petugas
                        </Button>
                    ) : (
                        <p className="text-sm leading-6 text-slate-600">
                            Jika perlu bantuan, hubungi admin sumber pendaftaran
                            Anda.
                        </p>
                    )}
                </div>
            </aside>
        </form>
    );
}
