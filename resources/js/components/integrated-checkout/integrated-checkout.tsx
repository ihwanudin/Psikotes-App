import { Button } from '@/components/ui/button';
import type { IntegratedCheckoutProps } from '@/types/integrated-checkout';
import oncamLogo from '../../../../public/brand/oncam-logo-full-color.png';
import { CheckoutForm } from './checkout-form';

/** Presentation only: no HTTP, storage, payment calculation, or test-start capability. */
export function IntegratedCheckout({
    screen,
    ...callbacks
}: IntegratedCheckoutProps) {
    return (
        <main className="bg-brand-canvas min-h-screen text-slate-950">
            <a
                href="#checkout-content"
                className="focus:text-brand-green sr-only focus:not-sr-only focus:block focus:bg-white focus:p-4"
            >
                Lewati ke ringkasan checkout
            </a>
            <header className="border-brand-green/15 border-b bg-white">
                <div className="mx-auto flex max-w-6xl items-center gap-5 px-4 py-5 sm:px-8">
                    <img
                        src={oncamLogo}
                        alt="ONCAM — Online Career Mentor"
                        width="1200"
                        height="1027"
                        className="h-auto w-20 shrink-0"
                    />
                    <div>
                        <p className="text-brand-green text-sm font-semibold">
                            Checkout peserta
                        </p>
                        <p className="mt-1 text-sm text-slate-600">
                            Ringkasan pribadi dan persetujuan
                        </p>
                    </div>
                </div>
            </header>
            <div
                id="checkout-content"
                tabIndex={-1}
                className="mx-auto max-w-6xl px-4 py-8 sm:px-8 sm:py-10"
            >
                <h1 className="text-3xl font-semibold tracking-tight">
                    Tinjau persiapan psikotes Anda
                </h1>
                {screen.state === 'ready' ? (
                    <>
                        <div className="border-brand-gold mb-8 mt-5 rounded-lg border-l-4 bg-white p-5">
                            <p className="text-sm text-slate-600">
                                {screen.summary.sourceName} ·{' '}
                                {screen.summary.attemptLabel}
                            </p>
                            <p className="wrap-anywhere mt-2 font-semibold">
                                {screen.summary.packageName}
                            </p>
                            <p className="wrap-anywhere mt-2 text-sm">
                                Cabang terkunci:{' '}
                                <strong>{screen.summary.branchName}</strong>
                            </p>
                        </div>
                        <CheckoutForm
                            key={JSON.stringify([
                                screen.summary.formKey,
                                screen.summary.consents,
                            ])}
                            summary={screen.summary}
                            {...callbacks}
                        />
                    </>
                ) : (
                    <section
                        className="mt-8 max-w-xl space-y-4 rounded-lg border bg-white p-6"
                        aria-busy={screen.state === 'loading'}
                    >
                        <h2 className="text-xl font-semibold">
                            {screen.state === 'loading'
                                ? 'Memuat ringkasan…'
                                : screen.state === 'expired'
                                  ? 'Tautan checkout tidak berlaku'
                                  : 'Ringkasan belum tersedia'}
                        </h2>
                        <p
                            role={screen.state === 'error' ? 'alert' : 'status'}
                            className="leading-7 text-slate-600"
                        >
                            {screen.state === 'loading'
                                ? 'Mohon tunggu. Data Anda sedang dimuat.'
                                : screen.state === 'expired'
                                  ? 'Tautan sudah kedaluwarsa atau tidak dapat digunakan. Hubungi admin sumber untuk tautan baru; jangan mendaftar ulang.'
                                  : screen.message}
                        </p>
                        {screen.state === 'error' && callbacks.onRefresh ? (
                            <Button
                                type="button"
                                onClick={callbacks.onRefresh}
                                disabled={callbacks.busy}
                                className="min-h-11"
                            >
                                Coba lagi
                            </Button>
                        ) : null}
                        {screen.state !== 'loading' && callbacks.onHelp ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={callbacks.onHelp}
                                className="min-h-11"
                            >
                                Hubungi petugas
                            </Button>
                        ) : null}
                    </section>
                )}
            </div>
        </main>
    );
}
