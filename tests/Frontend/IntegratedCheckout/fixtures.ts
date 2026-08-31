import type {
    CheckoutSummary,
    IntegratedCheckoutProps,
} from '../../../resources/js/types/integrated-checkout';

/** Synthetic presentation fixtures only. No production import or real payment destination. */
export const summary: CheckoutSummary = {
    formKey: 'synthetic-attempt-1-draft-v1',
    sourceName: 'Portal Seleksi Contoh',
    branchName: 'LPK Contoh — Cabang Bandung',
    packageName: 'Psikotes utama + DASS-21 opsional',
    attemptLabel: 'Seleksi September 2026 · Attempt 1',
    profile: [
        {
            key: 'fullName',
            label: 'Nama lengkap',
            state: 'locked',
            displayValue: 'Nadia Peserta Contoh',
        },
        {
            key: 'birthDate',
            label: 'Tanggal lahir',
            state: 'locked',
            displayValue: '12 Februari 2000',
        },
        {
            key: 'gender',
            label: 'Jenis kelamin',
            state: 'locked',
            displayValue: 'Perempuan',
        },
        {
            key: 'educationLevel',
            label: 'Pendidikan terakhir',
            state: 'locked',
            displayValue: 'SMA / sederajat',
        },
        {
            key: 'intendedField',
            label: 'Bidang tujuan',
            state: 'locked',
            displayValue: 'Kaigo / perawatan',
        },
        {
            key: 'email',
            label: 'Email',
            state: 'locked',
            displayValue: 'nadia@example.test',
        },
        {
            key: 'phone',
            label: 'Nomor WhatsApp',
            state: 'locked',
            displayValue: '+62 800 0000 0000 (contoh)',
        },
    ],
    identityMessage: 'Verifikasi identitas masih menunggu pemeriksaan server.',
    payment: {
        payer: 'organization',
        organizationName: 'LPK Contoh',
        state: 'pending',
        amountIdr: 175000,
    },
    access: {
        state: 'locked',
        message:
            'Akses tes belum dibuka. Lengkapi persetujuan dan tunggu verifikasi server.',
    },
    consents: {
        legalReviewPending: true,
        psychotest: {
            state: 'required',
            document: {
                version: 'contoh-v1',
                title: 'Baca persetujuan psikotes',
                text: 'NASKAH SINTETIS UNTUK UJI TAMPILAN. Bukan naskah legal final dan tidak mencatat persetujuan nyata.\nSaya memahami tujuan asesmen dan pengolahan data sesuai dokumen yang nantinya disahkan.',
            },
        },
        dass: {
            state: 'required',
            document: {
                version: 'contoh-dass-v1',
                title: 'Baca persetujuan DASS-21',
                text: 'NASKAH SINTETIS. DASS bersifat opsional, tidak menentukan kelayakan kerja, dan data klinis tidak dibagikan kepada cabang pembayar.',
            },
        },
    },
};

export const optionalEmailSummary: CheckoutSummary = {
    ...summary,
    formKey: 'synthetic-optional-email',
    profile: summary.profile.map((field) =>
        field.key === 'email'
            ? {
                  key: 'email',
                  label: 'Email',
                  state: 'missing',
                  required: false,
                  input: 'email',
              }
            : field,
    ),
    consents: {
        legalReviewPending: false,
        psychotest: { state: 'accepted', version: 'contoh-v1' },
        dass: { state: 'declined', version: 'contoh-dass-v1' },
    },
};

export const scenarios: Record<string, IntegratedCheckoutProps['screen']> = {
    'Email opsional · consent tercatat': {
        state: 'ready',
        summary: optionalEmailSummary,
    },
    'Email opsional · consent belum': {
        state: 'ready',
        summary: { ...optionalEmailSummary, consents: summary.consents },
    },
    'Phone wajib + email opsional': {
        state: 'ready',
        summary: {
            ...optionalEmailSummary,
            profile: optionalEmailSummary.profile.map((field) =>
                field.key === 'phone'
                    ? {
                          key: 'phone',
                          label: 'Nomor WhatsApp',
                          state: 'missing',
                          required: true,
                          input: 'tel',
                      }
                    : field,
            ),
        },
    },
    'Lembaga · menunggu': { state: 'ready', summary },
    'Lembaga · belum ditagihkan': {
        state: 'ready',
        summary: {
            ...summary,
            payment: {
                ...summary.payment,
                payer: 'organization',
                organizationName: 'LPK Contoh',
                state: 'unbilled',
            },
        },
    },
    'Lembaga · lunas, consent belum': {
        state: 'ready',
        summary: {
            ...summary,
            payment: {
                ...summary.payment,
                payer: 'organization',
                organizationName: 'LPK Contoh',
                state: 'paid',
            },
        },
    },
    'Mandiri · profil kurang': {
        state: 'ready',
        summary: {
            ...summary,
            profile: summary.profile.map((field) =>
                field.key === 'phone'
                    ? {
                          key: 'phone',
                          label: 'Nomor WhatsApp',
                          state: 'missing',
                          required: true,
                          input: 'tel',
                          autoComplete: 'tel',
                      }
                    : field,
            ),
            payment: {
                payer: 'self',
                state: 'unpaid',
                amountIdr: 175000,
                actionAvailable: true,
            },
        },
    },
    'Mandiri · pending': {
        state: 'ready',
        summary: {
            ...summary,
            payment: {
                payer: 'self',
                state: 'pending',
                amountIdr: 175000,
                actionAvailable: true,
            },
        },
    },
    'Mandiri · lunas': {
        state: 'ready',
        summary: {
            ...summary,
            payment: { payer: 'self', state: 'paid', amountIdr: 175000 },
        },
    },
    'Gratis · consent belum': {
        state: 'ready',
        summary: {
            ...summary,
            payment: { payer: 'self', state: 'free', amountIdr: 0 },
        },
    },
    'Gratis · server siap': {
        state: 'ready',
        summary: {
            ...summary,
            payment: { payer: 'self', state: 'free', amountIdr: 0 },
            consents: {
                legalReviewPending: false,
                psychotest: { state: 'accepted', version: 'contoh-v1' },
                dass: { state: 'declined', version: 'contoh-dass-v1' },
            },
            access: {
                state: 'ready',
                message:
                    'Prasyarat telah dikonfirmasi server. Ikuti petunjuk akses resmi.',
            },
        },
    },
    'Tagihan · kedaluwarsa': {
        state: 'ready',
        summary: {
            ...summary,
            payment: { payer: 'self', state: 'expired', amountIdr: 175000 },
        },
    },
    'Tagihan · ditolak': {
        state: 'ready',
        summary: {
            ...summary,
            payment: { payer: 'self', state: 'rejected', amountIdr: 175000 },
        },
    },
    'Tagihan · ditinjau': {
        state: 'ready',
        summary: {
            ...summary,
            payment: { payer: 'self', state: 'review', amountIdr: 175000 },
        },
    },
    Memuat: { state: 'loading' },
    'Tautan kedaluwarsa': { state: 'expired' },
    'Gagal memuat': {
        state: 'error',
        message:
            'Ringkasan belum dapat dimuat. Coba periksa lagi atau hubungi petugas.',
    },
};
