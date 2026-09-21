import { Head } from '@inertiajs/react';
import {
    CheckCheck,
    CheckCircle2,
    CircleAlert,
    CircleHelp,
    ClipboardList,
    Clock,
    Lock,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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

type StatusMeta = {
    label: string;
    /** One factual sentence about the state itself; never a reason (the
     * server doesn't tell us why, so we don't invent one). Null for
     * statuses that need no explanation. */
    description: string | null;
    badgeClass: string;
    Icon: LucideIcon;
};

// Entitlement.status documents the shape the server is expected to send,
// but this lookup is keyed by plain string and falls back safely below:
// this page shows server state as-is and must not crash or print a raw
// word if a status outside that shape ever arrives.
const STATUS_META: Record<string, StatusMeta> = {
    ready: {
        label: 'Siap',
        description: null,
        badgeClass: 'bg-teal-50 text-teal-800',
        Icon: CheckCircle2,
    },
    locked: {
        label: 'Belum dibuka',
        description: 'Akses untuk tes ini belum dibuka.',
        badgeClass: 'bg-slate-100 text-slate-600',
        Icon: Lock,
    },
    in_progress: {
        label: 'Sedang berlangsung',
        description: 'Tes ini sedang berlangsung.',
        badgeClass: 'bg-amber-50 text-amber-800',
        Icon: Clock,
    },
    done: {
        label: 'Selesai',
        description: null,
        badgeClass: 'bg-emerald-50 text-emerald-700',
        Icon: CheckCheck,
    },
};

const UNKNOWN_STATUS_META: StatusMeta = {
    label: 'Status tidak dikenali',
    description: 'Status tes ini tidak dikenali oleh sistem.',
    badgeClass: 'bg-slate-100 text-slate-500',
    Icon: CircleHelp,
};

function statusMeta(status: string): StatusMeta {
    return STATUS_META[status] ?? UNKNOWN_STATUS_META;
}

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
                        <p className="mt-6 text-sm font-medium uppercase tracking-wide text-teal-200">
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
                                                (entitlement) => {
                                                    const meta = statusMeta(
                                                        entitlement.status,
                                                    );

                                                    return (
                                                        <li
                                                            key={
                                                                entitlement.test_type
                                                            }
                                                            className="rounded-xl border border-slate-200 p-4"
                                                        >
                                                            <div className="flex items-center justify-between gap-4">
                                                                <span className="font-medium">
                                                                    {testNames[
                                                                        entitlement
                                                                            .test_type
                                                                    ] ??
                                                                        entitlement.test_type.toUpperCase()}
                                                                </span>
                                                                <span
                                                                    className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${meta.badgeClass}`}
                                                                >
                                                                    <meta.Icon
                                                                        className="size-3.5"
                                                                        aria-hidden="true"
                                                                    />
                                                                    {meta.label}
                                                                </span>
                                                            </div>
                                                            {meta.description !==
                                                                null && (
                                                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                                                    {
                                                                        meta.description
                                                                    }
                                                                </p>
                                                            )}
                                                        </li>
                                                    );
                                                },
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
