import { Input } from '@/components/ui/input';
import type {
    CheckoutProfileField,
    CheckoutProfileKey,
} from '@/types/integrated-checkout';

export function CheckoutProfile({
    fields,
    values,
    errors,
    onChange,
}: {
    fields: ReadonlyArray<CheckoutProfileField>;
    values: Partial<Record<CheckoutProfileKey, string>>;
    errors?: Partial<Record<CheckoutProfileKey, string>>;
    onChange: (key: CheckoutProfileKey, value: string) => void;
}) {
    const missing = fields.filter((field) => field.state === 'missing');

    return (
        <section aria-labelledby="checkout-profile-title" className="space-y-5">
            <div>
                <h2
                    id="checkout-profile-title"
                    className="text-xl font-semibold"
                >
                    Identitas Anda
                </h2>
                <p className="mt-2 text-sm leading-6 text-slate-600">
                    {missing.length
                        ? 'Lengkapi hanya data yang belum tersedia.'
                        : 'Data sudah lengkap. Anda tidak perlu mendaftar ulang.'}{' '}
                    Koreksi data terkunci melalui sumber atau admin berwenang.
                </p>
            </div>
            <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                {fields.map((field) =>
                    field.state === 'locked' ? (
                        <div key={field.key} className="min-w-0">
                            <dt className="text-sm text-slate-600">
                                {field.label}
                            </dt>
                            <dd className="mt-1 font-medium wrap-anywhere">
                                {field.displayValue}
                            </dd>
                        </div>
                    ) : null,
                )}
            </dl>
            {missing.length ? (
                <fieldset className="grid gap-5 rounded-lg border border-slate-300 p-4 sm:grid-cols-2">
                    <legend className="px-1 font-semibold">
                        Data yang perlu dilengkapi
                    </legend>
                    {missing.map((field) => {
                        const id = `checkout-${field.key}`;
                        const error = errors?.[field.key];
                        const common = {
                            id,
                            name: field.key,
                            required: field.required,
                            value: values[field.key] ?? '',
                            'aria-invalid': Boolean(error),
                            'aria-describedby': error
                                ? `${id}-error`
                                : undefined,
                            onChange: (
                                event: React.ChangeEvent<
                                    HTMLInputElement | HTMLSelectElement
                                >,
                            ) => onChange(field.key, event.target.value),
                            className:
                                'h-12 w-full rounded-md border border-slate-400 bg-white px-3 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green',
                        };

                        return (
                            <div key={field.key} className="min-w-0 space-y-2">
                                <label
                                    htmlFor={id}
                                    className="block text-sm font-medium"
                                >
                                    {field.label}
                                    {field.required ? ' *' : ' (opsional)'}
                                </label>
                                {field.input === 'select' ? (
                                    <select {...common}>
                                        <option value="">
                                            Pilih {field.label.toLowerCase()}
                                        </option>
                                        {field.options.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                ) : (
                                    <Input
                                        {...common}
                                        type={field.input}
                                        autoComplete={field.autoComplete}
                                    />
                                )}
                                {error ? (
                                    <p
                                        id={`${id}-error`}
                                        className="text-sm text-red-800"
                                    >
                                        {error}
                                    </p>
                                ) : null}
                            </div>
                        );
                    })}
                </fieldset>
            ) : null}
        </section>
    );
}
