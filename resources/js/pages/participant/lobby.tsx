import { Head } from '@inertiajs/react';
import {
    CheckCircle2,
    CircleAlert,
    ClipboardList,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Profile = {
    full_name: string | null;
    test_number: string | null;
};

type Entitlement = {
    test_type: string;
    status: 'locked' | 'ready' | 'in_progress' | 'done';
};

type LobbyState =
    | { status: 'loading' }
    | { status: 'ready'; profile: Profile; entitlements: Entitlement[] }
    | { status: 'error'; message: string };

const testNames: Record<string, string> = {
    ist: 'Tes Inteligensi IST',
    papi: 'Tes Kepribadian PAPI',
    rmib: 'Tes Minat RMIB',
    kraepelin: 'Tes Kraepelin',
    dass21: 'DASS-21',
};

export default function ParticipantLobby() {
    const [token] = useState(() =>
        typeof window === 'undefined'
            ? null
            : sessionStorage.getItem('participant_access_token'),
    );
    const [state, setState] = useState<LobbyState>(() =>
        token
            ? { status: 'loading' }
            : {
                  status: 'error',
                  message:
                      'Sesi psikotes tidak ditemukan. Buka kembali tautan dari aplikasi seleksi.',
              },
    );

    useEffect(() => {
        if (!token) {
            return;
        }

        const headers = {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
        };

        Promise.all([
            fetch('/api/me', { headers }),
            fetch('/api/me/entitlements', { headers }),
        ])
            .then(async ([profileResponse, entitlementResponse]) => {
                if (!profileResponse.ok || !entitlementResponse.ok) {
                    throw new Error('unauthorized');
                }

                const profile = (await profileResponse.json()) as {
                    data: Profile;
                };
                const entitlements = (await entitlementResponse.json()) as {
                    data: Entitlement[];
                };
                setState({
                    status: 'ready',
                    profile: profile.data,
                    entitlements: entitlements.data,
                });
            })
            .catch(() => {
                sessionStorage.removeItem('participant_access_token');
                setState({
                    status: 'error',
                    message:
                        'Sesi psikotes telah berakhir. Buka kembali tautan dari aplikasi seleksi.',
                });
            });
    }, [token]);

    return (
        <>
            <Head title="Ruang psikotes" />
            <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-950 sm:py-12">
                <div className="mx-auto w-full max-w-2xl">
                    <header className="rounded-2xl bg-teal-950 p-7 text-white sm:p-9">
                        <ShieldCheck
                            className="size-10 text-teal-200"
                            aria-hidden="true"
                        />
                        <p className="mt-6 text-sm font-medium tracking-wide text-teal-200 uppercase">
                            Seleksi Beasiswa Jepang
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Ruang psikotes
                        </h1>
                        <p className="mt-3 leading-7 text-teal-50/80">
                            Periksa identitas dan kesiapan tes Anda sebelum
                            memulai.
                        </p>
                    </header>

                    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        {state.status === 'loading' && (
                            <div
                                role="status"
                                aria-busy="true"
                                className="space-y-4"
                            >
                                <div className="h-6 w-2/3 animate-pulse rounded bg-slate-200" />
                                <div className="h-16 animate-pulse rounded-xl bg-slate-100" />
                                <span className="sr-only">
                                    Memuat data peserta
                                </span>
                            </div>
                        )}

                        {state.status === 'error' && (
                            <div role="alert" className="text-center">
                                <CircleAlert
                                    className="mx-auto size-10 text-amber-600"
                                    aria-hidden="true"
                                />
                                <h2 className="mt-4 text-xl font-semibold">
                                    Sesi tidak aktif
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    {state.message}
                                </p>
                            </div>
                        )}

                        {state.status === 'ready' && (
                            <>
                                <div className="flex items-start gap-3 border-b border-slate-200 pb-6">
                                    <CheckCircle2
                                        className="mt-0.5 size-6 shrink-0 text-teal-700"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <h2 className="text-lg font-semibold">
                                            {state.profile.full_name?.trim()
                                                ? state.profile.full_name
                                                : 'Nama belum dilengkapi'}
                                        </h2>
                                        <p className="mt-1 text-sm text-slate-600">
                                            Nomor tes{' '}
                                            {state.profile.test_number?.trim()
                                                ? state.profile.test_number
                                                : 'Belum tersedia'}
                                        </p>
                                    </div>
                                </div>

                                <div className="mt-6">
                                    <div className="flex items-center gap-2">
                                        <ClipboardList
                                            className="size-5 text-teal-700"
                                            aria-hidden="true"
                                        />
                                        <h2 className="font-semibold">
                                            Tes yang tersedia
                                        </h2>
                                    </div>
                                    {state.entitlements.length > 0 ? (
                                        <ul
                                            className="mt-4 space-y-3"
                                            aria-label="Daftar tes"
                                        >
                                            {state.entitlements.map(
                                                (entitlement) => (
                                                    <li
                                                        key={
                                                            entitlement.test_type
                                                        }
                                                        className="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4"
                                                    >
                                                        <span className="font-medium">
                                                            {testNames[
                                                                entitlement
                                                                    .test_type
                                                            ] ??
                                                                entitlement.test_type.toUpperCase()}
                                                        </span>
                                                        <span className="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800">
                                                            {entitlement.status ===
                                                            'ready'
                                                                ? 'Siap'
                                                                : entitlement.status}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    ) : (
                                        <p className="mt-4 text-sm leading-6 text-slate-600">
                                            Belum ada tes yang dibuka untuk
                                            Anda.
                                        </p>
                                    )}
                                </div>

                                <p className="mt-7 rounded-xl bg-slate-100 p-4 text-sm leading-6 text-slate-700">
                                    Tombol mulai akan aktif setelah modul
                                    pengerjaan tes tersedia. Jangan membagikan
                                    sesi ini kepada orang lain.
                                </p>
                            </>
                        )}
                    </section>
                </div>
            </main>
        </>
    );
}
