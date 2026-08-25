import { Head, Link } from '@inertiajs/react';
import {
    BadgeCheck,
    CircleAlert,
    Clock3,
    CreditCard,
    ShieldCheck,
} from 'lucide-react';
import { Button } from '@/components/ui/button';

type OrderStatus = 'pending' | 'paid' | 'rejected' | 'expired' | 'cancelled';

type Props = {
    authorized: boolean;
    order: {
        publicId: string;
        status: OrderStatus;
        amount: number;
        currency: string;
        paymentMethod: { code: string; displayName: string };
        paidAt: string | null;
        expiresAt: string | null;
        rejectionReason: string | null;
    } | null;
};

const statusCopy: Record<
    OrderStatus,
    { label: string; description: string; className: string }
> = {
    pending: {
        label: 'Menunggu pembayaran',
        description: 'Pembayaran belum dikonfirmasi. Status akan berubah setelah verifikasi selesai.',
        className: 'border-amber-200 bg-amber-50 text-amber-950',
    },
    paid: {
        label: 'Pembayaran diterima',
        description: 'Akses tes telah diaktifkan. Gunakan nomor tes yang dikirimkan kepada Anda.',
        className: 'border-teal-200 bg-teal-50 text-teal-950',
    },
    rejected: {
        label: 'Pembayaran ditolak',
        description: 'Bukti pembayaran perlu diperbaiki sebelum dapat diverifikasi kembali.',
        className: 'border-red-200 bg-red-50 text-red-950',
    },
    expired: {
        label: 'Pembayaran kedaluwarsa',
        description: 'Batas waktu pembayaran telah berakhir. Hubungi petugas LSI untuk bantuan.',
        className: 'border-slate-200 bg-slate-100 text-slate-900',
    },
    cancelled: {
        label: 'Pesanan dibatalkan',
        description: 'Pesanan ini tidak lagi aktif. Hubungi petugas LSI jika Anda memerlukan bantuan.',
        className: 'border-slate-200 bg-slate-100 text-slate-900',
    },
};

export default function RegistrationOrderStatus({ authorized, order }: Props) {
    const status = order ? statusCopy[order.status] : null;
    const formattedAmount = order
        ? new Intl.NumberFormat('id-ID', {
              style: 'currency',
              currency: order.currency,
              maximumFractionDigits: 0,
          }).format(order.amount)
        : null;

    return (
        <>
            <Head title="Status pembayaran" />
            <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-950 sm:py-12">
                <div className="mx-auto w-full max-w-2xl">
                    <header className="rounded-2xl bg-teal-950 p-7 text-white sm:p-9">
                        <ShieldCheck className="size-10 text-teal-200" aria-hidden="true" />
                        <p className="mt-6 text-sm font-medium tracking-wide text-teal-200 uppercase">
                            Area peserta
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Status pembayaran
                        </h1>
                        <p className="mt-3 leading-7 text-teal-50/80">
                            Informasi ini hanya berasal dari sesi pendaftaran Anda.
                        </p>
                    </header>

                    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        {!authorized ? (
                            <div role="alert" className="text-center">
                                <CircleAlert className="mx-auto size-10 text-amber-600" aria-hidden="true" />
                                <h2 className="mt-4 text-xl font-semibold">Sesi status tidak aktif</h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    Buka halaman ini setelah pendaftaran atau hubungi petugas LSI jika sesi berakhir.
                                </p>
                            </div>
                        ) : order && status ? (
                            <>
                                <div role="status" className={`rounded-xl border p-5 ${status.className}`}>
                                    <div className="flex gap-3">
                                        {order.status === 'paid' ? (
                                            <BadgeCheck className="mt-0.5 size-6 shrink-0" aria-hidden="true" />
                                        ) : (
                                            <Clock3 className="mt-0.5 size-6 shrink-0" aria-hidden="true" />
                                        )}
                                        <div>
                                            <h2 className="font-semibold">{status.label}</h2>
                                            <p className="mt-1 text-sm leading-6">{status.description}</p>
                                        </div>
                                    </div>
                                </div>

                                <dl className="mt-7 divide-y divide-slate-200 text-sm">
                                    <div className="flex justify-between gap-4 py-4">
                                        <dt className="text-slate-600">Nomor pesanan</dt>
                                        <dd className="break-all text-right font-medium">{order.publicId}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4 py-4">
                                        <dt className="text-slate-600">Metode pembayaran</dt>
                                        <dd className="text-right font-medium">{order.paymentMethod.displayName}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4 py-4">
                                        <dt className="text-slate-600">Total</dt>
                                        <dd className="text-right font-semibold">{formattedAmount}</dd>
                                    </div>
                                </dl>

                                {order.status === 'rejected' && order.rejectionReason && (
                                    <div role="alert" className="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-950">
                                        <strong>Alasan penolakan:</strong> {order.rejectionReason}
                                    </div>
                                )}
                            </>
                        ) : (
                            <div role="status" className="text-center">
                                <CreditCard className="mx-auto size-10 text-slate-500" aria-hidden="true" />
                                <h2 className="mt-4 text-xl font-semibold">Pesanan belum tersedia</h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    Muat ulang halaman ini beberapa saat lagi.
                                </p>
                            </div>
                        )}

                        <div className="mt-8 grid gap-3 sm:grid-cols-2">
                            <Button asChild variant="outline">
                                <Link href="/registration/received">Kembali ke verifikasi</Link>
                            </Button>
                            <Button asChild className="bg-teal-800 hover:bg-teal-900">
                                <Link href="/registration/order-status">Muat ulang status</Link>
                            </Button>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
