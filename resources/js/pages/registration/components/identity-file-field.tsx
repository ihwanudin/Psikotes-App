import type { LucideIcon } from 'lucide-react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    id: string;
    name: string;
    label: string;
    description: string;
    icon: LucideIcon;
    capture: 'environment' | 'user';
    error?: string;
};

export default function IdentityFileField({
    id,
    name,
    label,
    description,
    icon: Icon,
    capture,
    error,
}: Props) {
    const descriptionId = `${id}-description`;
    const errorId = `${id}-error`;

    return (
        <div className="rounded-xl border border-slate-200 p-4 sm:p-5">
            <div className="flex gap-3">
                <Icon
                    className="mt-0.5 size-5 shrink-0 text-teal-700"
                    aria-hidden="true"
                />
                <div className="min-w-0 flex-1">
                    <Label htmlFor={id}>{label} *</Label>
                    <p
                        id={descriptionId}
                        className="mt-1 text-sm leading-6 text-slate-500"
                    >
                        {description}
                    </p>
                    <Input
                        id={id}
                        name={name}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        capture={capture}
                        required
                        aria-invalid={Boolean(error)}
                        aria-describedby={`${descriptionId} ${errorId}`}
                        className="mt-4 h-11 cursor-pointer file:mr-3 file:font-medium"
                    />
                    <InputError
                        id={errorId}
                        message={error}
                        role="alert"
                        className="mt-2"
                    />
                </div>
            </div>
        </div>
    );
}
