import { CreditCard, Landmark } from 'lucide-react';
import InputError from '@/components/input-error';

export type RegistrationPaymentMethod = {
    code: string;
    displayName: string;
};

type Props = {
    methods: RegistrationPaymentMethod[];
    configurationPending: boolean;
    error?: string;
};

const descriptions: Record<string, string> = {
    xendit: 'Bayar melalui invoice online setelah pendaftaran.',
    manual_transfer: 'Unggah bukti transfer setelah pendaftaran.',
};

export default function PaymentMethodSelector({
    methods,
    configurationPending,
    error,
}: Props) {
    return (
        <fieldset className="space-y-3">
            <legend className="text-sm font-medium">Metode pembayaran *</legend>

            {configurationPending ? (
                <div
                    role="status"
                    className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950"
                >
                    Pendaftaran pembayaran belum tersedia. Silakan hubungi admin
                    LSI.
                </div>
            ) : (
                <div
                    className="grid gap-3 sm:grid-cols-2"
                    aria-describedby="payment_method_code-error"
                >
                    {methods.map((method) => {
                        const Icon =
                            method.code === 'manual_transfer'
                                ? Landmark
                                : CreditCard;

                        return (
                            <label
                                key={method.code}
                                className="flex min-h-24 cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 transition-colors hover:border-teal-400 has-[:checked]:border-teal-700 has-[:checked]:bg-teal-50"
                            >
                                <input
                                    type="radio"
                                    name="payment_method_code"
                                    value={method.code}
                                    required
                                    className="mt-1 size-4 shrink-0 accent-teal-700"
                                />
                                <Icon
                                    className="mt-0.5 size-5 shrink-0 text-teal-700"
                                    aria-hidden="true"
                                />
                                <span>
                                    <span className="block text-sm font-semibold text-slate-950">
                                        {method.displayName}
                                    </span>
                                    <span className="mt-1 block text-xs leading-5 text-slate-600">
                                        {descriptions[method.code] ??
                                            'Lanjutkan pembayaran setelah pendaftaran.'}
                                    </span>
                                </span>
                            </label>
                        );
                    })}
                </div>
            )}

            <InputError
                id="payment_method_code-error"
                message={error}
                role="alert"
            />
        </fieldset>
    );
}
