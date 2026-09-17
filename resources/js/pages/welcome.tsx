import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeCheck,
    BarChart3,
    BrainCircuit,
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    FileCheck2,
    Fingerprint,
    HeartPulse,
    Laptop2,
    LockKeyhole,
    ShieldCheck,
    UserRoundCheck,
} from 'lucide-react';
import { dashboard, login, register } from '@/routes';

const instruments = ['IST', 'PAPI Kostick', 'Kraepelin', 'RMIB', 'DASS-21'];

const steps = [
    {
        number: '01',
        title: 'Daftar dan verifikasi',
        description:
            'Lengkapi data, pilih paket asesmen, lalu selesaikan verifikasi identitas dan pembayaran.',
    },
    {
        number: '02',
        title: 'Ikuti asesmen daring',
        description:
            'Kerjakan rangkaian tes melalui perangkat yang sesuai dengan panduan sesi yang diberikan.',
    },
    {
        number: '03',
        title: 'Hasil ditinjau psikolog',
        description:
            'Skor dan validitas sesi diperiksa sebelum laporan memperoleh persetujuan profesional.',
    },
    {
        number: '04',
        title: 'Laporan diterbitkan',
        description:
            'HPP dwibahasa Indonesia–Jepang disiapkan untuk peserta dan organisasi yang berwenang.',
    },
];

const benefits = [
    {
        icon: BrainCircuit,
        title: 'Gambaran yang menyeluruh',
        description:
            'Kemampuan intelektual, karakter kerja, ritme kerja, dan minat dipetakan dalam satu rangkaian.',
    },
    {
        icon: BarChart3,
        title: 'Relevan dengan bidang kerja',
        description:
            'Hasil dibaca terhadap kebutuhan bidang seperti kaigo, konstruksi, manufaktur, pertanian, dan jasa.',
    },
    {
        icon: UserRoundCheck,
        title: 'Ada tinjauan profesional',
        description:
            'Laporan tidak diterbitkan otomatis. Psikolog meninjau hasil dan validitas sesi terlebih dahulu.',
    },
];

function BrandLogo({ inverse = false }: { inverse?: boolean }) {
    return (
        <Link
            href="/"
            className="focus-visible:outline-brand-gold inline-flex items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4"
            aria-label="ONCAM Psikotes — beranda"
        >
            <span
                className={`grid size-10 place-items-center overflow-hidden rounded-lg ${inverse ? 'bg-white' : 'bg-white ring-1 ring-slate-200'}`}
            >
                <img
                    src="/brand/oncam-logo-full-color.png"
                    alt=""
                    width="1200"
                    height="1027"
                    className="h-9 w-auto object-contain"
                />
            </span>
            <span className="leading-none">
                <span
                    className={`block text-base font-semibold tracking-[0.18em] ${inverse ? 'text-white' : 'text-brand-green-deep'}`}
                >
                    ONCAM
                </span>
                <span
                    className={`mt-1 block text-[0.62rem] font-medium uppercase tracking-[0.14em] ${inverse ? 'text-white/65' : 'text-slate-500'}`}
                >
                    Psikotes
                </span>
            </span>
        </Link>
    );
}

function ReportPreview() {
    const overview = [
        { label: 'Kemampuan intelektual', width: '82%' },
        { label: 'Kecepatan & ketelitian', width: '71%' },
        { label: 'Karakter kerja', width: '88%' },
    ];

    return (
        <div className="relative mx-auto w-full max-w-lg lg:mr-0">
            <div
                className="bg-brand-gold/20 absolute -right-6 -top-8 size-40 rounded-full blur-3xl"
                aria-hidden="true"
            />
            <div
                className="absolute -bottom-10 -left-10 size-48 rounded-full bg-emerald-300/10 blur-3xl"
                aria-hidden="true"
            />

            <div className="relative overflow-hidden rounded-2xl border border-white/15 bg-white text-slate-900 shadow-2xl shadow-black/20">
                <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-7">
                    <div>
                        <p className="text-brand-green text-xs font-semibold uppercase tracking-[0.16em]">
                            Ilustrasi laporan
                        </p>
                        <p className="mt-1 text-sm font-semibold">
                            Ringkasan HPP Peserta
                        </p>
                    </div>
                    <span className="text-brand-green inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold">
                        <BadgeCheck
                            className="size-3.5"
                            aria-hidden="true"
                            focusable="false"
                        />
                        Ditinjau
                    </span>
                </div>

                <div className="space-y-6 p-5 sm:p-7">
                    <div className="grid grid-cols-3 gap-2 rounded-xl bg-slate-50 p-3 text-center">
                        <div>
                            <p className="text-brand-green-deep text-lg font-semibold">
                                5
                            </p>
                            <p className="mt-0.5 text-[0.65rem] leading-tight text-slate-500">
                                instrumen
                            </p>
                        </div>
                        <div className="border-x border-slate-200">
                            <p className="text-brand-green-deep text-lg font-semibold">
                                18
                            </p>
                            <p className="mt-0.5 text-[0.65rem] leading-tight text-slate-500">
                                aspek HPP
                            </p>
                        </div>
                        <div>
                            <p className="text-brand-green-deep text-lg font-semibold">
                                ID–JP
                            </p>
                            <p className="mt-0.5 text-[0.65rem] leading-tight text-slate-500">
                                dua bahasa
                            </p>
                        </div>
                    </div>

                    <div className="space-y-4">
                        {overview.map((item) => (
                            <div key={item.label}>
                                <div className="mb-2 flex items-center justify-between gap-4 text-xs">
                                    <span className="font-medium text-slate-700">
                                        {item.label}
                                    </span>
                                    <span className="text-slate-400">
                                        Terpetakan
                                    </span>
                                </div>
                                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        className="bg-brand-green h-full rounded-full"
                                        style={{ width: item.width }}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="border-brand-gold/30 bg-brand-gold-soft/55 flex items-start gap-3 rounded-xl border p-4">
                        <ClipboardCheck
                            className="text-brand-green mt-0.5 size-5 shrink-0"
                            aria-hidden="true"
                            focusable="false"
                        />
                        <p className="text-xs leading-5 text-slate-600">
                            Rekomendasi dibaca terhadap standar bidang kerja dan
                            disahkan setelah tinjauan psikolog.
                        </p>
                    </div>
                </div>
            </div>

            <div className="bg-brand-green-deep absolute -bottom-6 -right-3 flex items-center gap-3 rounded-xl border border-white/20 px-4 py-3 text-white shadow-xl sm:-right-6">
                <span className="bg-brand-gold text-brand-green-deep grid size-9 place-items-center rounded-full">
                    <ShieldCheck
                        className="size-5"
                        aria-hidden="true"
                        focusable="false"
                    />
                </span>
                <span>
                    <span className="block text-xs font-semibold">
                        Data terlindungi
                    </span>
                    <span className="mt-0.5 block text-[0.65rem] text-white/65">
                        Akses berbasis peran
                    </span>
                </span>
            </div>
        </div>
    );
}

function SectionHeading({
    eyebrow,
    title,
    description,
}: {
    eyebrow: string;
    title: string;
    description: string;
}) {
    return (
        <div className="max-w-2xl">
            <p className="text-brand-green text-xs font-semibold uppercase tracking-[0.18em]">
                {eyebrow}
            </p>
            <h2 className="mt-4 text-3xl font-semibold leading-tight tracking-tight text-slate-950 sm:text-4xl">
                {title}
            </h2>
            <p className="mt-5 text-base leading-7 text-slate-600 sm:text-lg">
                {description}
            </p>
        </div>
    );
}

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head>
                <title>Psikotes Daring CPMI Jepang</title>
                <meta
                    name="description"
                    content="Platform psikotes daring CPMI tujuan Jepang dengan rangkaian asesmen terintegrasi dan laporan yang ditinjau psikolog."
                />
                <meta
                    property="og:title"
                    content="ONCAM Psikotes — Asesmen Daring CPMI Jepang"
                />
                <meta
                    property="og:description"
                    content="Satu alur asesmen, dari pendaftaran hingga laporan hasil pemeriksaan psikologis."
                />
            </Head>

            <div className="selection:bg-brand-gold/40 min-h-screen bg-white text-slate-950">
                <a
                    href="#konten-utama"
                    className="text-brand-green-deep fixed left-3 top-3 z-50 -translate-y-20 rounded-md bg-white px-4 py-2 text-sm font-semibold shadow-lg transition-transform focus:translate-y-0"
                >
                    Lewati ke konten utama
                </a>

                <header className="absolute inset-x-0 top-0 z-30 border-b border-white/10">
                    <div className="mx-auto flex h-20 max-w-7xl items-center justify-between px-5 sm:px-8 lg:px-10">
                        <BrandLogo inverse />

                        <nav
                            aria-label="Navigasi utama"
                            className="hidden items-center gap-8 md:flex"
                        >
                            <a
                                href="#manfaat"
                                className="focus-visible:outline-brand-gold text-sm font-medium text-white/75 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-4"
                            >
                                Manfaat
                            </a>
                            <a
                                href="#cara-kerja"
                                className="focus-visible:outline-brand-gold text-sm font-medium text-white/75 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-4"
                            >
                                Cara kerja
                            </a>
                            <a
                                href="#keamanan"
                                className="focus-visible:outline-brand-gold text-sm font-medium text-white/75 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-4"
                            >
                                Keamanan
                            </a>
                        </nav>

                        <Link
                            href={auth.user ? dashboard() : login()}
                            className="focus-visible:outline-brand-gold inline-flex h-10 items-center justify-center rounded-lg border border-white/25 px-4 text-sm font-semibold text-white transition hover:border-white/45 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-4"
                        >
                            {auth.user ? 'Buka dashboard' : 'Portal pengelola'}
                        </Link>
                    </div>
                </header>

                <main id="konten-utama">
                    <section className="bg-brand-green-deep relative overflow-hidden pb-20 pt-32 text-white sm:pb-28 sm:pt-40">
                        <div
                            className="absolute inset-0 opacity-[0.055]"
                            aria-hidden="true"
                            style={{
                                backgroundImage:
                                    'linear-gradient(rgba(255,255,255,.8) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.8) 1px, transparent 1px)',
                                backgroundSize: '56px 56px',
                            }}
                        />
                        <div
                            className="bg-brand-green absolute left-[12%] top-20 h-72 w-72 rounded-full blur-3xl"
                            aria-hidden="true"
                        />

                        <div className="relative mx-auto grid max-w-7xl items-center gap-16 px-5 sm:px-8 lg:grid-cols-[1.05fr_0.95fr] lg:px-10">
                            <div className="max-w-2xl">
                                <p className="border-brand-gold/35 bg-brand-gold/10 text-brand-gold inline-flex items-center gap-2 rounded-full border px-3.5 py-2 text-xs font-semibold uppercase tracking-[0.12em]">
                                    <Fingerprint
                                        className="size-4"
                                        aria-hidden="true"
                                        focusable="false"
                                    />
                                    Asesmen kerja CPMI Jepang
                                </p>
                                <h1 className="mt-7 text-balance text-4xl font-semibold leading-[1.08] tracking-[-0.035em] sm:text-5xl lg:text-6xl">
                                    Kenali kesiapan kerja dengan asesmen yang
                                    bertanggung jawab.
                                </h1>
                                <p className="text-white/72 mt-6 max-w-xl text-base leading-8 sm:text-lg">
                                    ONCAM menyatukan pendaftaran, psikotes
                                    daring, tinjauan psikolog, dan laporan HPP
                                    untuk proses seleksi CPMI yang lebih
                                    tertata.
                                </p>

                                <div className="mt-9 flex flex-col gap-3 sm:flex-row">
                                    <Link
                                        href={register()}
                                        className="bg-brand-gold text-brand-green-deep inline-flex h-12 items-center justify-center gap-2 rounded-lg px-6 text-sm font-semibold transition hover:bg-[#e2c34f] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                                    >
                                        Daftar psikotes
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden="true"
                                            focusable="false"
                                        />
                                    </Link>
                                    <a
                                        href="#cara-kerja"
                                        className="focus-visible:outline-brand-gold inline-flex h-12 items-center justify-center rounded-lg border border-white/25 px-6 text-sm font-semibold text-white transition hover:border-white/45 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-4"
                                    >
                                        Pelajari alurnya
                                    </a>
                                </div>

                                <ul className="text-white/72 mt-9 grid gap-3 text-sm sm:grid-cols-3">
                                    <li className="flex items-center gap-2">
                                        <CheckCircle2
                                            className="text-brand-gold size-4"
                                            aria-hidden="true"
                                            focusable="false"
                                        />
                                        100% daring
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <CheckCircle2
                                            className="text-brand-gold size-4"
                                            aria-hidden="true"
                                            focusable="false"
                                        />
                                        HPP ID–JP
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <CheckCircle2
                                            className="text-brand-gold size-4"
                                            aria-hidden="true"
                                            focusable="false"
                                        />
                                        Ditinjau psikolog
                                    </li>
                                </ul>
                            </div>

                            <ReportPreview />
                        </div>
                    </section>

                    <section
                        aria-label="Instrumen asesmen"
                        className="bg-brand-canvas border-b border-slate-200"
                    >
                        <div className="mx-auto flex max-w-7xl flex-col gap-5 px-5 py-7 sm:px-8 lg:flex-row lg:items-center lg:justify-between lg:px-10">
                            <p className="text-sm font-medium text-slate-500">
                                Rangkaian asesmen terintegrasi
                            </p>
                            <ul className="flex flex-wrap gap-x-7 gap-y-3">
                                {instruments.map((instrument) => (
                                    <li
                                        key={instrument}
                                        className="text-brand-green-deep text-sm font-semibold"
                                    >
                                        {instrument}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>

                    <section
                        id="manfaat"
                        className="scroll-mt-16 px-5 py-20 sm:px-8 sm:py-28 lg:px-10"
                    >
                        <div className="mx-auto max-w-7xl">
                            <SectionHeading
                                eyebrow="Lebih dari sekadar skor"
                                title="Informasi yang membantu keputusan, disajikan dengan konteks."
                                description="Setiap instrumen memiliki peran yang berbeda. Hasilnya dirangkum untuk membantu peserta dan organisasi memahami kecocokan terhadap tuntutan bidang kerja."
                            />

                            <div className="mt-12 grid gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 lg:grid-cols-3">
                                {benefits.map((benefit) => {
                                    const Icon = benefit.icon;

                                    return (
                                        <article
                                            key={benefit.title}
                                            className="bg-white p-7 sm:p-9"
                                        >
                                            <span className="text-brand-green grid size-11 place-items-center rounded-lg bg-emerald-50">
                                                <Icon
                                                    className="size-5"
                                                    aria-hidden="true"
                                                    focusable="false"
                                                />
                                            </span>
                                            <h3 className="mt-6 text-lg font-semibold">
                                                {benefit.title}
                                            </h3>
                                            <p className="mt-3 text-sm leading-7 text-slate-600">
                                                {benefit.description}
                                            </p>
                                        </article>
                                    );
                                })}
                            </div>
                        </div>
                    </section>

                    <section
                        id="cara-kerja"
                        className="scroll-mt-16 bg-slate-950 px-5 py-20 text-white sm:px-8 sm:py-28 lg:px-10"
                    >
                        <div className="mx-auto max-w-7xl">
                            <div className="grid gap-12 lg:grid-cols-[0.8fr_1.2fr] lg:gap-20">
                                <div>
                                    <p className="text-brand-gold text-xs font-semibold uppercase tracking-[0.18em]">
                                        Cara kerja
                                    </p>
                                    <h2 className="mt-4 text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">
                                        Alur yang jelas dari awal hingga
                                        laporan.
                                    </h2>
                                    <p className="mt-5 text-base leading-7 text-white/65">
                                        Peserta mengetahui tahap yang sedang
                                        dijalani, sementara pengelola memperoleh
                                        proses yang lebih konsisten dan dapat
                                        ditelusuri.
                                    </p>
                                </div>

                                <ol className="grid gap-x-8 gap-y-9 sm:grid-cols-2">
                                    {steps.map((step) => (
                                        <li
                                            key={step.number}
                                            className="border-t border-white/15 pt-5"
                                        >
                                            <span className="text-brand-gold font-mono text-xs font-semibold">
                                                {step.number}
                                            </span>
                                            <h3 className="mt-3 text-lg font-semibold">
                                                {step.title}
                                            </h3>
                                            <p className="mt-2 text-sm leading-6 text-white/60">
                                                {step.description}
                                            </p>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </div>
                    </section>

                    <section
                        id="keamanan"
                        className="bg-brand-canvas scroll-mt-16 px-5 py-20 sm:px-8 sm:py-28 lg:px-10"
                    >
                        <div className="mx-auto grid max-w-7xl overflow-hidden rounded-2xl border border-emerald-950/10 bg-white lg:grid-cols-[0.9fr_1.1fr]">
                            <div className="bg-brand-green px-7 py-10 text-white sm:p-12">
                                <LockKeyhole
                                    className="text-brand-gold size-8"
                                    aria-hidden="true"
                                    focusable="false"
                                />
                                <h2 className="mt-8 max-w-md text-3xl font-semibold leading-tight tracking-tight">
                                    Privasi dan keputusan profesional menjadi
                                    bagian dari alur.
                                </h2>
                                <p className="mt-5 max-w-md text-sm leading-7 text-white/70 sm:text-base">
                                    Data psikologis tidak diperlakukan seperti
                                    formulir biasa. Hak akses dibatasi sesuai
                                    peran dan laporan melewati tinjauan sebelum
                                    diterbitkan.
                                </p>
                            </div>

                            <div className="grid gap-7 p-7 sm:grid-cols-2 sm:p-12">
                                <div>
                                    <FileCheck2
                                        className="text-brand-green size-6"
                                        aria-hidden="true"
                                        focusable="false"
                                    />
                                    <h3 className="mt-4 font-semibold">
                                        Wajib tinjauan psikolog
                                    </h3>
                                    <p className="mt-2 text-sm leading-6 text-slate-600">
                                        Tidak ada laporan final yang terbit
                                        hanya dari proses skoring otomatis.
                                    </p>
                                </div>
                                <div>
                                    <HeartPulse
                                        className="text-brand-green size-6"
                                        aria-hidden="true"
                                        focusable="false"
                                    />
                                    <h3 className="mt-4 font-semibold">
                                        DASS-21 dipisahkan
                                    </h3>
                                    <p className="mt-2 text-sm leading-6 text-slate-600">
                                        Skrining kesehatan mental tidak
                                        digunakan untuk menentukan rekomendasi
                                        kerja.
                                    </p>
                                </div>
                                <div>
                                    <Laptop2
                                        className="text-brand-green size-6"
                                        aria-hidden="true"
                                        focusable="false"
                                    />
                                    <h3 className="mt-4 font-semibold">
                                        Sesi dapat ditelusuri
                                    </h3>
                                    <p className="mt-2 text-sm leading-6 text-slate-600">
                                        Kejadian teknis selama asesmen dicatat
                                        sebagai bahan pemeriksaan validitas.
                                    </p>
                                </div>
                                <div>
                                    <ShieldCheck
                                        className="text-brand-green size-6"
                                        aria-hidden="true"
                                        focusable="false"
                                    />
                                    <h3 className="mt-4 font-semibold">
                                        Akses berbasis peran
                                    </h3>
                                    <p className="mt-2 text-sm leading-6 text-slate-600">
                                        Peserta, LPK, dan psikolog hanya
                                        mengakses data sesuai kewenangannya.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="px-5 py-20 sm:px-8 sm:py-28 lg:px-10">
                        <div className="bg-brand-gold-soft mx-auto grid max-w-7xl items-center gap-10 rounded-2xl px-7 py-10 sm:px-12 sm:py-14 lg:grid-cols-[1fr_auto]">
                            <div>
                                <p className="text-brand-green text-sm font-semibold">
                                    Siap memulai?
                                </p>
                                <h2 className="text-brand-green-deep mt-3 max-w-2xl text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">
                                    Mulai asesmen melalui alur pendaftaran yang
                                    aman.
                                </h2>
                                <p className="mt-4 max-w-2xl text-sm leading-7 text-slate-600 sm:text-base">
                                    Siapkan identitas resmi dan perangkat yang
                                    akan digunakan untuk mengikuti psikotes
                                    daring.
                                </p>
                            </div>
                            <Link
                                href={register()}
                                className="bg-brand-green-deep hover:bg-brand-green focus-visible:outline-brand-green inline-flex h-12 items-center justify-center gap-2 rounded-lg px-6 text-sm font-semibold text-white transition focus-visible:outline-2 focus-visible:outline-offset-4"
                            >
                                Daftar sekarang
                                <ArrowRight
                                    className="size-4"
                                    aria-hidden="true"
                                    focusable="false"
                                />
                            </Link>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-slate-200 bg-white">
                    <div className="mx-auto flex max-w-7xl flex-col gap-8 px-5 py-10 sm:px-8 md:flex-row md:items-end md:justify-between lg:px-10">
                        <div>
                            <BrandLogo />
                            <p className="mt-5 max-w-md text-sm leading-6 text-slate-500">
                                Platform psikotes daring untuk proses seleksi
                                CPMI tujuan Jepang.
                            </p>
                        </div>
                        <div className="flex flex-col gap-2 text-sm text-slate-500 md:text-right">
                            <span className="inline-flex items-center gap-2 md:justify-end">
                                <Clock3
                                    className="size-4"
                                    aria-hidden="true"
                                    focusable="false"
                                />
                                Layanan asesmen daring
                            </span>
                            <p>
                                © {new Date().getFullYear()} ONCAM. Hak cipta
                                dilindungi.
                            </p>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
