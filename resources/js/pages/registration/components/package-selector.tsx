import { CircleAlert } from 'lucide-react';
import InputError from '@/components/input-error';

export type RegistrationPackage = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    amount: number;
    currency: 'IDR';
    testTypes: string[];
};

type Props = {
    packages: RegistrationPackage[];
    configurationPending: boolean;
    error?: string;
};

const testTypeLabels: Record<string, string> = {
    ist: 'IST',
    papi: 'PAPI Kostick',
    rmib: 'RMIB',
    kraepelin: 'Kraepelin',
    dass21: 'DASS-21',
};

const rupiah = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

export default function PackageSelector({
    packages,
    configurationPending,
    error,
}: Props) {
    if (configurationPending) {
        return (
            <div
                role="status"
                className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-950"
            >
                <CircleAlert className="mt-0.5 size-5 shrink-0" />
                <div>
                    <p className="text-sm font-medium">
                        Paket belum dapat dipilih
                    </p>
                    <p className="mt-1 text-sm leading-6">
                        Administrator perlu mengisi harga Rupiah dan
                        mengaktifkan setidaknya satu paket sebelum pendaftaran
                        dapat dikirim.
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div
            role="radiogroup"
            aria-labelledby="package-label"
            aria-describedby="package-help package_id-error"
            className="space-y-3"
        >
            <div>
                <p id="package-label" className="text-sm font-medium">
                    Paket psikotes *
                </p>
                <p id="package-help" className="mt-1 text-sm text-slate-500">
                    Pilih berdasarkan jenis tes yang Anda perlukan.
                </p>
            </div>

            <div className="grid gap-3">
                {packages.map((testPackage) => (
                    <label
                        key={testPackage.id}
                        className="cursor-pointer rounded-xl border border-slate-200 bg-white p-4 transition-colors hover:border-teal-400 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50"
                    >
                        <span className="flex items-start gap-3">
                            <input
                                type="radio"
                                name="package_id"
                                value={testPackage.id}
                                required
                                aria-invalid={Boolean(error)}
                                className="mt-1 size-4 shrink-0 accent-teal-700"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                                    <span className="font-semibold text-slate-950">
                                        {testPackage.name}
                                    </span>
                                    <span className="shrink-0 font-semibold text-teal-800">
                                        {rupiah.format(testPackage.amount)}
                                    </span>
                                </span>
                                {testPackage.description && (
                                    <span className="mt-1 block text-sm leading-6 text-slate-600">
                                        {testPackage.description}
                                    </span>
                                )}
                                <span className="mt-3 flex flex-wrap gap-2">
                                    {testPackage.testTypes.map((testType) => (
                                        <span
                                            key={testType}
                                            className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700"
                                        >
                                            {testTypeLabels[testType] ??
                                                testType}
                                        </span>
                                    ))}
                                </span>
                            </span>
                        </span>
                    </label>
                ))}
            </div>

            <InputError id="package_id-error" message={error} role="alert" />
        </div>
    );
}
