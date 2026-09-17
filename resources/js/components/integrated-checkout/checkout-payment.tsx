import { Button } from '@/components/ui/button';
import type { CheckoutPayment as Payment } from '@/types/integrated-checkout';

const rupiah = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

function paymentLabel(payment: Payment) {
    switch (payment.state) {
        case 'unselected':
            return 'Pembayar belum dipilih';
        case 'unpaid':
            return 'Belum dibayar';
        case 'unbilled':
            return 'Menunggu penagihan oleh lembaga';
        case 'pending':
            return payment.payer === 'organization'
                ? 'Menunggu pembayaran lembaga'
                : 'Menunggu pembayaran Anda';
        case 'paid':
            return 'Biaya Anda sudah lunas';
        case 'free':
            return 'Gratis · dikonfirmasi server';
        case 'review':
            return 'Pembayaran sedang ditinjau';
        case 'expired':
            return 'Tagihan kedaluwarsa · perlu bantuan petugas';
        case 'rejected':
            return 'Pembayaran ditolak · perlu bantuan petugas';
    }
}

export function CheckoutPayment({
    payment,
    busy,
    onPayment,
}: {
    payment: Payment;
    busy: boolean;
    onPayment?: () => void;
}) {
    const canOfferPayment =
        payment.payer === 'self' &&
        (payment.state === 'unpaid' || payment.state === 'pending') &&
        payment.actionAvailable;

    return (
        <section
            aria-labelledby="checkout-payment-title"
            className="border-brand-green/20 space-y-4 rounded-lg border bg-white p-5"
        >
            <h2 id="checkout-payment-title" className="text-xl font-semibold">
                Pembayaran
            </h2>
            <dl className="space-y-4">
                <div>
                    <dt className="text-sm text-slate-600">
                        {payment.payer === 'unselected'
                            ? 'Pembayar'
                            : 'Pembayar · terkunci'}
                    </dt>
                    <dd className="mt-1 font-medium">
                        {payment.payer === 'unselected'
                            ? 'Belum dipilih'
                            : payment.payer === 'self'
                              ? 'Bayar sendiri'
                              : `Dibayar lembaga — ${payment.organizationName}`}
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-slate-600">
                        Biaya attempt Anda
                    </dt>
                    <dd className="mt-1 text-2xl font-semibold tabular-nums">
                        {payment.amountIdr === null
                            ? 'Belum tersedia'
                            : rupiah.format(payment.amountIdr)}
                    </dd>
                </div>
            </dl>
            <p
                role="status"
                className="bg-brand-gold-soft rounded-md p-3 text-sm font-medium text-slate-900"
            >
                {paymentLabel(payment)}
            </p>
            {payment.payer === 'organization' ? (
                <p className="text-sm leading-6 text-slate-600">
                    Pembayaran diurus oleh lembaga. Anda tidak perlu membayar
                    mandiri. Ringkasan ini hanya untuk attempt Anda.
                </p>
            ) : null}
            {payment.state === 'paid' || payment.state === 'free' ? (
                <p className="text-sm leading-6 text-slate-600">
                    Tidak ada pembayaran baru. Akses tetap mengikuti verifikasi
                    identitas dan persetujuan yang berlaku.
                </p>
            ) : null}
            {payment.state === 'expired' ||
            payment.state === 'rejected' ||
            payment.state === 'review' ? (
                <p className="text-sm leading-6 text-slate-600">
                    Hubungi petugas untuk tindak lanjut. Jangan membuat
                    pembayaran pengganti sebelum status dikonfirmasi.
                </p>
            ) : null}
            {canOfferPayment ? (
                <>
                    <Button
                        type="button"
                        disabled={busy || !onPayment}
                        onClick={onPayment}
                        className="bg-brand-green hover:bg-brand-green-deep focus-visible:outline-brand-green min-h-12 w-full whitespace-normal text-white focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        {payment.state === 'pending'
                            ? 'Lanjutkan pembayaran yang sama'
                            : 'Pilih metode pembayaran'}
                    </Button>
                    {!onPayment ? (
                        <p className="text-sm text-slate-600">
                            Pembayaran belum terhubung pada tampilan ini.
                        </p>
                    ) : null}
                </>
            ) : null}
        </section>
    );
}
