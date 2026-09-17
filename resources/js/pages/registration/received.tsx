import { Form, Head, Link } from '@inertiajs/react';
import {
    BadgeCheck,
    Camera,
    CheckCircle2,
    ClipboardCheck,
    FileImage,
    LockKeyhole,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import IdentityFileField from '@/pages/registration/components/identity-file-field';
import ManualPaymentProofForm from '@/pages/registration/components/manual-payment-proof-form';

type Props = {
    identityEvidence: {
        authorized: boolean;
        complete: boolean;
        outcome: 'pending' | 'match' | 'mismatch' | 'error' | null;
        manualStatus: 'pending' | 'accepted' | 'rejected' | null;
    };
    manualPayment: {
        required: boolean;
        proofUploaded: boolean;
        status:
            'pending' | 'paid' | 'rejected' | 'expired' | 'cancelled' | null;
        amount: number | null;
        currency: string | null;
        rejectionReason: string | null;
    };
    status?: string;
};

export default function RegistrationReceived({
    identityEvidence,
    manualPayment,
    status,
}: Props) {
    const stored =
        status === 'identity-evidence-stored' || identityEvidence.complete;

    return (
        <>
            <Head title="Verifikasi identitas" />
            <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-950 sm:py-12">
                <div className="mx-auto grid w-full max-w-5xl gap-6 lg:grid-cols-[0.8fr_1.2fr] lg:gap-8">
                    <section className="rounded-2xl bg-teal-950 p-7 text-white sm:p-9">
                        <div className="grid size-12 place-items-center rounded-xl bg-white/10 ring-1 ring-white/15">
                            <CheckCircle2
                                className="size-7 text-teal-200"
                                aria-hidden="true"
                            />
                        </div>
                        <p className="mt-8 text-sm font-medium uppercase tracking-wide text-teal-200">
                            Data pendaftaran tersimpan
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Lengkapi bukti identitas Anda.
                        </h1>
                        <p className="mt-4 text-sm leading-7 text-teal-50/80 sm:text-base">
                            Foto ini dipakai untuk mencocokkan peserta dengan
                            dokumen resmi sebelum tes. Ketidakcocokan hanya
                            menjadi penanda untuk tinjauan petugas.
                        </p>

                        <div className="mt-8 flex gap-3 border-t border-white/15 pt-6 text-sm leading-6 text-teal-50/80">
                            <LockKeyhole
                                className="mt-0.5 size-5 shrink-0 text-teal-200"
                                aria-hidden="true"
                            />
                            <p>
                                Berkas disimpan privat. Petugas hanya dapat
                                membukanya melalui tautan sementara yang
                                tercatat di audit.
                            </p>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-9">
                        {manualPayment.required &&
                            manualPayment.status !== null &&
                            manualPayment.amount !== null &&
                            manualPayment.currency !== null && (
                                <ManualPaymentProofForm
                                    proofUploaded={manualPayment.proofUploaded}
                                    status={manualPayment.status}
                                    amount={manualPayment.amount}
                                    currency={manualPayment.currency}
                                    rejectionReason={
                                        manualPayment.rejectionReason
                                    }
                                    stored={
                                        status === 'manual-payment-proof-stored'
                                    }
                                />
                            )}

                        {stored && (
                            <div
                                role="status"
                                className="mb-7 flex gap-3 rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm leading-6 text-teal-950"
                            >
                                <BadgeCheck
                                    className="mt-0.5 size-5 shrink-0 text-teal-700"
                                    aria-hidden="true"
                                />
                                <p>
                                    Kedua foto sudah tersimpan dan menunggu
                                    tinjauan. Anda masih dapat menggantinya
                                    selama sesi ini aktif.
                                </p>
                            </div>
                        )}

                        {identityEvidence.authorized ? (
                            <>
                                <h2 className="text-xl font-semibold tracking-tight">
                                    {identityEvidence.complete
                                        ? 'Ganti foto verifikasi'
                                        : 'Unggah dua foto'}
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    Format JPG, PNG, atau WebP; maksimal 5 MB
                                    per foto. Pastikan teks dokumen dan wajah
                                    terlihat jelas.
                                </p>

                                <Form
                                    action="/registration/identity-evidence"
                                    method="post"
                                    resetOnSuccess
                                    className="mt-7 space-y-6"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <IdentityFileField
                                                id="identity_document"
                                                name="identity_document"
                                                label="Foto KTP atau paspor"
                                                description="Letakkan dokumen di permukaan datar, tanpa pantulan dan tanpa bagian terpotong."
                                                icon={FileImage}
                                                capture="environment"
                                                error={errors.identity_document}
                                            />
                                            <IdentityFileField
                                                id="initial_selfie"
                                                name="initial_selfie"
                                                label="Selfie awal"
                                                description="Hadapkan wajah ke kamera dengan pencahayaan cukup; jangan memakai filter."
                                                icon={Camera}
                                                capture="user"
                                                error={errors.initial_selfie}
                                            />

                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="h-12 w-full bg-teal-800 text-base hover:bg-teal-900"
                                            >
                                                {processing && <Spinner />}
                                                {identityEvidence.complete
                                                    ? 'Simpan foto pengganti'
                                                    : 'Simpan bukti identitas'}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </>
                        ) : (
                            <div role="alert" className="text-center">
                                <ClipboardCheck
                                    className="mx-auto size-10 text-amber-600"
                                    aria-hidden="true"
                                />
                                <h2 className="mt-4 text-xl font-semibold">
                                    Sesi unggah tidak aktif
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    Buka halaman ini langsung setelah
                                    menyelesaikan pendaftaran. Jika sesi
                                    berakhir, hubungi petugas LSI.
                                </p>
                            </div>
                        )}

                        <Button asChild variant="ghost" className="mt-7 w-full">
                            <Link href="/registration/order-status">
                                Lihat status pembayaran
                            </Link>
                        </Button>
                        <Button asChild variant="ghost" className="mt-2 w-full">
                            <Link href="/">Kembali ke beranda</Link>
                        </Button>
                    </section>
                </div>
            </main>
        </>
    );
}
