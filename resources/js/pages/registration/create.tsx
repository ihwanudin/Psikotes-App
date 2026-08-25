import { Form, Head } from '@inertiajs/react';
import {
    BrainCircuit,
    Building2,
    LockKeyhole,
    ShieldCheck,
} from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import PackageSelector from '@/pages/registration/components/package-selector';
import type { RegistrationPackage } from '@/pages/registration/components/package-selector';

type ConsentDocument = {
    version: string;
    title: string;
    text: string;
};

type Props = {
    assignedBranch: {
        name: string;
        source: 'link' | 'default';
    };
    registrationToken: string;
    packages: RegistrationPackage[];
    packageConfigurationPending: boolean;
    consents: {
        psychotest: ConsentDocument;
        dass: ConsentDocument;
        legalReviewPending: boolean;
    };
};

const intendedFields = [
    ['KAIGO', 'Kaigo / perawatan'],
    ['KENSETSU', 'Kensetsu / konstruksi'],
    ['NOUGYOU', 'Nougyou / pertanian'],
    ['SEIZOU', 'Seizou / manufaktur'],
    ['GAISHOKU', 'Gaishoku / layanan makanan'],
    ['UMUM', 'Umum / belum ditentukan'],
] as const;

export default function CreateRegistration({
    assignedBranch,
    registrationToken,
    packages,
    packageConfigurationPending,
    consents,
}: Props) {
    return (
        <>
            <Head title="Pendaftaran psikotes" />
            <main className="min-h-screen bg-slate-50 text-slate-950">
                <div className="mx-auto grid w-full max-w-6xl lg:min-h-screen lg:grid-cols-[0.78fr_1.22fr]">
                    <aside className="relative overflow-hidden bg-teal-950 px-6 py-10 text-white sm:px-10 lg:sticky lg:top-0 lg:h-screen lg:px-12 lg:py-14">
                        <div
                            className="absolute -top-24 -right-20 h-72 w-72 rounded-full bg-teal-400/15 blur-3xl"
                            aria-hidden="true"
                        />
                        <div className="relative flex h-full max-w-md flex-col">
                            <div className="mb-12 flex items-center gap-3">
                                <span className="grid size-11 place-items-center rounded-2xl bg-white/10 ring-1 ring-white/15">
                                    <BrainCircuit className="size-6 text-teal-200" />
                                </span>
                                <div>
                                    <p className="font-semibold tracking-tight">
                                        Psikotes LSI
                                    </p>
                                    <p className="text-xs text-teal-100/70">
                                        psikotes.oncam.id
                                    </p>
                                </div>
                            </div>

                            <div className="my-auto">
                                <p className="mb-3 text-sm font-medium tracking-wide text-teal-200 uppercase">
                                    Pendaftaran peserta
                                </p>
                                <h1 className="text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">
                                    Satu langkah sebelum verifikasi identitas.
                                </h1>
                                <p className="mt-5 max-w-sm text-sm leading-7 text-teal-50/75 sm:text-base">
                                    Isi data sesuai dokumen resmi. Informasi ini
                                    digunakan untuk administrasi tes dan laporan
                                    psikologis.
                                </p>
                            </div>

                            <div className="mt-10 space-y-4 border-t border-white/10 pt-7 text-sm text-teal-50/80">
                                <div className="flex gap-3">
                                    <ShieldCheck className="mt-0.5 size-5 shrink-0 text-teal-300" />
                                    <p>
                                        DASS-21 terpisah dan boleh ditolak tanpa
                                        membatalkan psikotes utama.
                                    </p>
                                </div>
                                <div className="flex gap-3">
                                    <LockKeyhole className="mt-0.5 size-5 shrink-0 text-teal-300" />
                                    <p>
                                        Atribusi cabang dikunci oleh server dari
                                        tautan pertama yang Anda buka.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </aside>

                    <section className="px-4 py-8 sm:px-8 sm:py-12 lg:px-14 lg:py-16">
                        <div className="mx-auto max-w-2xl">
                            <div className="mb-8">
                                <p className="text-sm text-slate-500">
                                    Kolom bertanda * wajib diisi
                                </p>
                                <h2 className="mt-2 text-2xl font-semibold tracking-tight">
                                    Data diri dan persetujuan
                                </h2>
                            </div>

                            <Form
                                action="/registrations"
                                method="post"
                                disableWhileProcessing
                                className="space-y-9"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="_registration_token"
                                            value={registrationToken}
                                        />

                                        <fieldset className="space-y-5">
                                            <legend className="mb-5 text-base font-semibold">
                                                Identitas peserta
                                            </legend>

                                            <div className="space-y-2">
                                                <Label htmlFor="full_name">
                                                    Nama lengkap *
                                                </Label>
                                                <Input
                                                    id="full_name"
                                                    name="full_name"
                                                    autoComplete="name"
                                                    autoFocus
                                                    required
                                                    maxLength={200}
                                                    aria-invalid={Boolean(
                                                        errors.full_name,
                                                    )}
                                                    aria-describedby="full_name-error"
                                                    className="h-12 bg-white"
                                                />
                                                <InputError
                                                    id="full_name-error"
                                                    message={errors.full_name}
                                                    role="alert"
                                                />
                                            </div>

                                            <div className="grid gap-5 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="gender">
                                                        Jenis kelamin *
                                                    </Label>
                                                    <select
                                                        id="gender"
                                                        name="gender"
                                                        required
                                                        defaultValue=""
                                                        aria-invalid={Boolean(
                                                            errors.gender,
                                                        )}
                                                        aria-describedby="gender-error"
                                                        className="h-12 w-full rounded-md border border-input bg-white px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                                    >
                                                        <option
                                                            value=""
                                                            disabled
                                                        >
                                                            Pilih jenis kelamin
                                                        </option>
                                                        <option value="female">
                                                            Perempuan
                                                        </option>
                                                        <option value="male">
                                                            Laki-laki
                                                        </option>
                                                    </select>
                                                    <InputError
                                                        id="gender-error"
                                                        message={errors.gender}
                                                        role="alert"
                                                    />
                                                </div>

                                                <div className="space-y-2">
                                                    <Label htmlFor="birth_date">
                                                        Tanggal lahir *
                                                    </Label>
                                                    <Input
                                                        id="birth_date"
                                                        name="birth_date"
                                                        type="date"
                                                        autoComplete="bday"
                                                        required
                                                        aria-invalid={Boolean(
                                                            errors.birth_date,
                                                        )}
                                                        aria-describedby="birth_date-error"
                                                        className="h-12 bg-white"
                                                    />
                                                    <InputError
                                                        id="birth_date-error"
                                                        message={
                                                            errors.birth_date
                                                        }
                                                        role="alert"
                                                    />
                                                </div>
                                            </div>

                                            <div className="grid gap-5 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="education_level">
                                                        Pendidikan terakhir *
                                                    </Label>
                                                    <Input
                                                        id="education_level"
                                                        name="education_level"
                                                        required
                                                        maxLength={64}
                                                        placeholder="Contoh: SMA/SMK"
                                                        aria-invalid={Boolean(
                                                            errors.education_level,
                                                        )}
                                                        aria-describedby="education_level-error"
                                                        className="h-12 bg-white"
                                                    />
                                                    <InputError
                                                        id="education_level-error"
                                                        message={
                                                            errors.education_level
                                                        }
                                                        role="alert"
                                                    />
                                                </div>

                                                <div className="space-y-2">
                                                    <Label htmlFor="intended_field">
                                                        Bidang kerja tujuan *
                                                    </Label>
                                                    <select
                                                        id="intended_field"
                                                        name="intended_field"
                                                        required
                                                        defaultValue=""
                                                        aria-invalid={Boolean(
                                                            errors.intended_field,
                                                        )}
                                                        aria-describedby="intended_field-error"
                                                        className="h-12 w-full rounded-md border border-input bg-white px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                                    >
                                                        <option
                                                            value=""
                                                            disabled
                                                        >
                                                            Pilih bidang
                                                        </option>
                                                        {intendedFields.map(
                                                            ([
                                                                value,
                                                                label,
                                                            ]) => (
                                                                <option
                                                                    key={value}
                                                                    value={
                                                                        value
                                                                    }
                                                                >
                                                                    {label}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <InputError
                                                        id="intended_field-error"
                                                        message={
                                                            errors.intended_field
                                                        }
                                                        role="alert"
                                                    />
                                                </div>
                                            </div>
                                        </fieldset>

                                        <fieldset className="space-y-5 border-t border-slate-200 pt-8">
                                            <legend className="mb-5 text-base font-semibold">
                                                Kontak dan cabang
                                            </legend>
                                            <div className="grid gap-5 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="phone">
                                                        Nomor WhatsApp *
                                                    </Label>
                                                    <Input
                                                        id="phone"
                                                        name="phone"
                                                        type="tel"
                                                        inputMode="tel"
                                                        autoComplete="tel"
                                                        required
                                                        maxLength={32}
                                                        placeholder="+62 812 3456 7890"
                                                        aria-invalid={Boolean(
                                                            errors.phone,
                                                        )}
                                                        aria-describedby="phone-error"
                                                        className="h-12 bg-white"
                                                    />
                                                    <InputError
                                                        id="phone-error"
                                                        message={errors.phone}
                                                        role="alert"
                                                    />
                                                </div>

                                                <div className="space-y-2">
                                                    <Label htmlFor="email">
                                                        Email{' '}
                                                        <span className="font-normal text-slate-500">
                                                            (opsional)
                                                        </span>
                                                    </Label>
                                                    <Input
                                                        id="email"
                                                        name="email"
                                                        type="email"
                                                        autoComplete="email"
                                                        maxLength={255}
                                                        placeholder="nama@email.com"
                                                        aria-invalid={Boolean(
                                                            errors.email,
                                                        )}
                                                        aria-describedby="email-error"
                                                        className="h-12 bg-white"
                                                    />
                                                    <InputError
                                                        id="email-error"
                                                        message={errors.email}
                                                        role="alert"
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex items-start gap-3 rounded-xl border border-teal-200 bg-teal-50 p-4">
                                                <Building2 className="mt-0.5 size-5 shrink-0 text-teal-700" />
                                                <div>
                                                    <p className="text-xs font-medium tracking-wide text-teal-700 uppercase">
                                                        Cabang teratribusi
                                                    </p>
                                                    <p className="mt-1 font-semibold text-teal-950">
                                                        {assignedBranch.name}
                                                    </p>
                                                    <p className="mt-1 text-xs leading-5 text-teal-800">
                                                        Ditentukan otomatis oleh
                                                        server dan tidak dapat
                                                        diubah dari formulir.
                                                    </p>
                                                </div>
                                            </div>

                                            <PackageSelector
                                                packages={packages}
                                                configurationPending={
                                                    packageConfigurationPending
                                                }
                                                error={errors.package_id}
                                            />
                                        </fieldset>

                                        <fieldset className="space-y-5 border-t border-slate-200 pt-8">
                                            <legend className="mb-5 text-base font-semibold">
                                                Persetujuan
                                            </legend>

                                            {consents.legalReviewPending && (
                                                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                                                    Naskah persetujuan ini masih
                                                    berstatus draft dan wajib
                                                    ditinjau penasihat hukum
                                                    sebelum layanan diluncurkan.
                                                </div>
                                            )}

                                            <div className="rounded-xl border border-slate-200 bg-white p-5">
                                                <div className="flex items-start gap-3">
                                                    <input
                                                        id="consent_psychotest"
                                                        name="consent_psychotest"
                                                        type="checkbox"
                                                        value="1"
                                                        required
                                                        aria-invalid={Boolean(
                                                            errors.consent_psychotest,
                                                        )}
                                                        aria-describedby="psychotest-copy consent_psychotest-error"
                                                        className="mt-1 size-5 shrink-0 accent-teal-700"
                                                    />
                                                    <div>
                                                        <Label
                                                            htmlFor="consent_psychotest"
                                                            className="text-sm leading-6"
                                                        >
                                                            Saya menyetujui
                                                            consent A (wajib) *
                                                        </Label>
                                                        <p
                                                            id="psychotest-copy"
                                                            className="mt-2 text-sm leading-6 text-slate-600"
                                                        >
                                                            {
                                                                consents
                                                                    .psychotest
                                                                    .text
                                                            }
                                                        </p>
                                                        <p className="mt-2 text-xs text-slate-400">
                                                            Versi{' '}
                                                            {
                                                                consents
                                                                    .psychotest
                                                                    .version
                                                            }
                                                        </p>
                                                    </div>
                                                </div>
                                                <InputError
                                                    id="consent_psychotest-error"
                                                    message={
                                                        errors.consent_psychotest
                                                    }
                                                    role="alert"
                                                    className="mt-3"
                                                />
                                            </div>

                                            <div className="rounded-xl border border-slate-200 bg-white p-5">
                                                <p className="font-medium">
                                                    Consent B — DASS-21 *
                                                </p>
                                                <p
                                                    id="dass-copy"
                                                    className="mt-2 text-sm leading-6 text-slate-600"
                                                >
                                                    {consents.dass.text}
                                                </p>
                                                <div
                                                    className="mt-4 grid gap-3 sm:grid-cols-2"
                                                    aria-describedby="dass-copy consent_dass-error"
                                                >
                                                    <label className="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm hover:border-teal-400 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50">
                                                        <input
                                                            type="radio"
                                                            name="consent_dass"
                                                            value="1"
                                                            required
                                                            className="size-4 accent-teal-700"
                                                        />
                                                        Ya, saya memilih ikut
                                                    </label>
                                                    <label className="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm hover:border-teal-400 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50">
                                                        <input
                                                            type="radio"
                                                            name="consent_dass"
                                                            value="0"
                                                            required
                                                            className="size-4 accent-teal-700"
                                                        />
                                                        Tidak, saya menolak
                                                    </label>
                                                </div>
                                                <InputError
                                                    id="consent_dass-error"
                                                    message={
                                                        errors.consent_dass
                                                    }
                                                    role="alert"
                                                    className="mt-3"
                                                />
                                                <p className="mt-3 text-xs text-slate-400">
                                                    Versi{' '}
                                                    {consents.dass.version}
                                                </p>
                                            </div>
                                        </fieldset>

                                        <Button
                                            type="submit"
                                            size="lg"
                                            disabled={
                                                processing ||
                                                packageConfigurationPending
                                            }
                                            className="h-12 w-full bg-teal-800 text-base hover:bg-teal-900"
                                        >
                                            {processing && <Spinner />}
                                            Simpan pendaftaran
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
