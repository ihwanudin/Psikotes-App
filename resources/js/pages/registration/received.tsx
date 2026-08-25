import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';

export default function RegistrationReceived() {
    return (
        <>
            <Head title="Pendaftaran tersimpan" />
            <main className="grid min-h-screen place-items-center bg-slate-50 px-4 py-12">
                <section className="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-7 shadow-sm sm:p-10">
                    <div className="mb-7 grid size-14 place-items-center rounded-2xl bg-teal-100 text-teal-800">
                        <CheckCircle2 className="size-8" aria-hidden="true" />
                    </div>
                    <p className="text-sm font-medium tracking-wide text-teal-700 uppercase">
                        Data tersimpan
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        Pendaftaran Anda sudah kami terima.
                    </h1>
                    <p className="mt-4 leading-7 text-slate-600">
                        Tahap berikutnya adalah verifikasi bukti identitas.
                        Nomor tes belum diterbitkan pada tahap ini.
                    </p>

                    <div className="mt-7 flex gap-3 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-700">
                        <ClipboardCheck className="mt-0.5 size-5 shrink-0 text-teal-700" />
                        <p>
                            Simpan halaman ini sebagai konfirmasi. Jangan
                            mengirim formulir pendaftaran baru untuk peserta
                            yang sama.
                        </p>
                    </div>

                    <Button
                        asChild
                        variant="outline"
                        className="mt-8 h-11 w-full"
                    >
                        <Link href="/">Kembali ke beranda</Link>
                    </Button>
                </section>
            </main>
        </>
    );
}
