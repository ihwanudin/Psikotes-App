import { Form } from '@inertiajs/react';
import { BadgeCheck, FileUp, ReceiptText, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    proofUploaded: boolean;
    status: 'pending' | 'paid' | 'rejected' | 'expired' | 'cancelled';
    amount: number;
    currency: string;
    rejectionReason: string | null;
    stored: boolean;
};

export default function ManualPaymentProofForm({
    proofUploaded,
    status,
    amount,
    currency,
    rejectionReason,
    stored,
}: Props) {
    const formattedAmount = new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(amount);

    if (status === 'paid') {
        return (
            <section
                aria-labelledby="manual-payment-heading"
                className="mb-7 rounded-xl border border-teal-200 bg-teal-50 p-5 text-teal-950"
            >
                <div className="flex gap-3">
                    <BadgeCheck
                        className="mt-0.5 size-5 shrink-0 text-teal-700"
                        aria-hidden="true"
                    />
                    <div>
                        <h2
                            id="manual-payment-heading"
                            className="font-semibold"
                        >
                            Pembayaran telah disetujui
                        </h2>
                        <p className="mt-1 text-sm leading-6">
                            Transfer {formattedAmount} sudah diverifikasi oleh
                            petugas.
                        </p>
                    </div>
                </div>
            </section>
        );
    }

    if (status === 'rejected') {
        return (
            <section
                aria-labelledby="manual-payment-heading"
                className="mb-7 rounded-xl border border-red-200 bg-red-50 p-5 text-red-950"
                role="alert"
            >
                <div className="flex gap-3">
                    <TriangleAlert
                        className="mt-0.5 size-5 shrink-0 text-red-700"
                        aria-hidden="true"
                    />
                    <div>
                        <h2
                            id="manual-payment-heading"
                            className="font-semibold"
                        >
                            Bukti transfer ditolak
                        </h2>
                        <p className="mt-1 text-sm leading-6">
                            {rejectionReason ??
                                'Hubungi petugas LSI untuk informasi lebih lanjut.'}
                        </p>
                    </div>
                </div>
            </section>
        );
    }

    return (
        <section
            aria-labelledby="manual-payment-heading"
            className="mb-7 rounded-xl border border-amber-200 bg-amber-50/70 p-5"
        >
            <div className="flex gap-3">
                <ReceiptText
                    className="mt-0.5 size-5 shrink-0 text-amber-700"
                    aria-hidden="true"
                />
                <div>
                    <h2
                        id="manual-payment-heading"
                        className="font-semibold text-slate-950"
                    >
                        Bukti transfer manual
                    </h2>
                    <p className="mt-1 text-sm leading-6 text-slate-700">
                        Nominal pembayaran: <strong>{formattedAmount}</strong>.
                        Lakukan transfer melalui kanal resmi LSI, lalu unggah
                        buktinya di bawah.
                    </p>
                </div>
            </div>

            {(stored || proofUploaded) && (
                <div
                    role="status"
                    className="mt-4 flex gap-2 rounded-lg border border-teal-200 bg-white p-3 text-sm text-teal-900"
                >
                    <BadgeCheck
                        className="size-5 shrink-0 text-teal-700"
                        aria-hidden="true"
                    />
                    <p>
                        Bukti tersimpan dan menunggu verifikasi. Anda dapat
                        menggantinya selama status masih menunggu.
                    </p>
                </div>
            )}

            <Form
                action="/registration/manual-payment-proof"
                method="post"
                resetOnSuccess
                className="mt-5"
            >
                {({ processing, errors }) => (
                    <>
                        <label
                            htmlFor="payment_proof"
                            className="block text-sm font-medium text-slate-900"
                        >
                            Pilih bukti transfer
                        </label>
                        <div className="mt-2 rounded-lg border border-dashed border-slate-300 bg-white p-4">
                            <div className="flex items-center gap-3">
                                <FileUp
                                    className="size-5 text-slate-500"
                                    aria-hidden="true"
                                />
                                <input
                                    id="payment_proof"
                                    name="payment_proof"
                                    type="file"
                                    required
                                    accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                                    aria-describedby="payment-proof-help payment-proof-error"
                                    className="block min-w-0 flex-1 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:font-medium file:text-slate-900 hover:file:bg-slate-200"
                                />
                            </div>
                            <p
                                id="payment-proof-help"
                                className="mt-2 text-xs leading-5 text-slate-600"
                            >
                                JPG, PNG, atau PDF; maksimal 5 MB.
                            </p>
                        </div>
                        {errors.payment_proof && (
                            <p
                                id="payment-proof-error"
                                role="alert"
                                className="mt-2 text-sm text-red-700"
                            >
                                {errors.payment_proof}
                            </p>
                        )}

                        <Button
                            type="submit"
                            disabled={processing}
                            className="mt-4 h-11 w-full bg-amber-700 hover:bg-amber-800"
                        >
                            {processing && <Spinner />}
                            {proofUploaded
                                ? 'Simpan bukti pengganti'
                                : 'Simpan bukti transfer'}
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}
