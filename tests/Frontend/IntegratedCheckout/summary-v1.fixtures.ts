import type {
    CheckoutSummaryV1,
    CheckoutSummaryV1Consent,
    CheckoutSummaryV1Payment,
    CheckoutSummaryV1PaymentState,
    CheckoutSummaryV1Profile,
} from '../../../resources/js/types/checkout-summary-v1';

// Synthetic serialized display facts, never input metadata, real prices or legal text.
export const missingProfileV1 = [
    {
        key: 'fullName',
        label: 'Nama lengkap',
        state: 'missing',
        required: true,
    },
    {
        key: 'birthDate',
        label: 'Tanggal lahir',
        state: 'missing',
        required: true,
    },
    { key: 'gender', label: 'Jenis kelamin', state: 'missing', required: true },
    {
        key: 'educationLevel',
        label: 'Pendidikan terakhir',
        state: 'missing',
        required: true,
    },
    {
        key: 'intendedField',
        label: 'Bidang tujuan',
        state: 'missing',
        required: true,
    },
    { key: 'email', label: 'Email', state: 'missing', required: false },
    { key: 'phone', label: 'Nomor telepon', state: 'missing', required: true },
] as const satisfies CheckoutSummaryV1Profile;

export const completeProfileV1 = [
    {
        ...missingProfileV1[0],
        state: 'locked',
        displayValue: 'Peserta Sintetis',
    },
    { ...missingProfileV1[1], state: 'locked', displayValue: '2000-02-12' },
    { ...missingProfileV1[2], state: 'locked', displayValue: 'Perempuan' },
    { ...missingProfileV1[3], state: 'locked', displayValue: 'SMA sintetis' },
    {
        ...missingProfileV1[4],
        state: 'locked',
        displayValue: 'Kaigo / perawatan',
    },
    {
        ...missingProfileV1[5],
        state: 'locked',
        displayValue: 'synthetic@example.invalid',
    },
    { ...missingProfileV1[6], state: 'locked', displayValue: '+628000000000' },
] as const satisfies CheckoutSummaryV1Profile;

export const mixedProfileV1 = [
    completeProfileV1[0],
    missingProfileV1[1],
    completeProfileV1[2],
    completeProfileV1[3],
    missingProfileV1[4],
    missingProfileV1[5],
    missingProfileV1[6],
] as const satisfies CheckoutSummaryV1Profile;

const unavailable = {
    amountSource: 'unavailable',
    amountIdr: null,
    consultationRequested: null,
    actionAvailable: false,
} as const;
const snapshot = {
    amountSource: 'charge_snapshot',
    amountIdr: 100,
    consultationRequested: false,
    actionAvailable: false,
} as const;

// Exhaustive state inventory. Selected payer paths and provenance mirror reader cases.
export const paymentCasesV1 = {
    unselected: { ...unavailable, payer: 'unselected', state: 'unselected' },
    unpaid: { ...snapshot, payer: 'self', state: 'unpaid', amountIdr: 0 },
    unbilled: {
        ...unavailable,
        payer: 'organization',
        organizationName: 'Cabang Sintetis',
        state: 'unbilled',
    },
    preparing: { ...snapshot, payer: 'self', state: 'preparing' },
    pending: {
        ...snapshot,
        payer: 'organization',
        organizationName: 'Cabang Sintetis',
        state: 'pending',
        consultationRequested: true,
    },
    recovery_required: {
        ...snapshot,
        payer: 'self',
        state: 'recovery_required',
    },
    expired: { ...snapshot, payer: 'self', state: 'expired' },
    rejected: {
        ...snapshot,
        payer: 'organization',
        organizationName: 'Cabang Sintetis',
        state: 'rejected',
    },
    paid: {
        ...snapshot,
        payer: 'organization',
        organizationName: 'Cabang Sintetis',
        state: 'paid',
    },
    free: { ...snapshot, payer: 'self', state: 'free', amountIdr: 0 },
} as const satisfies Record<
    CheckoutSummaryV1PaymentState,
    CheckoutSummaryV1Payment
>;

export const consentCasesV1 = {
    accepted: { state: 'accepted', version: 'synthetic-v1' },
    required: {
        state: 'required',
        document: {
            version: 'synthetic-v1',
            title: 'Dokumen sintetis',
            text: 'Fixture saja; bukan teks legal.',
        },
    },
} as const satisfies Record<
    CheckoutSummaryV1Consent['state'],
    CheckoutSummaryV1Consent
>;

const common = {
    contractVersion: 'checkout-summary-v1',
    sourceName: 'Integrasi seleksi',
    branchName: 'Cabang Sintetis',
    packageName: 'Paket Sintetis',
    attemptLabel: 'Assessment Anda',
    profile: completeProfileV1,
    identityMessage:
        'Kelengkapan profil tidak menggantikan verifikasi identitas.',
    access: {
        state: 'locked',
        tests: [{ testType: 'ist', state: 'locked' }],
        startAvailable: false,
        message: 'Akses tes belum siap.',
    },
    consents: {
        psychotest: consentCasesV1.accepted,
        dass: { state: 'not_applicable' },
        legalReviewPending: true,
    },
} as const;

export const summaryCasesV1 = {
    missingCatalog: {
        ...common,
        profile: missingProfileV1,
        packageSource: 'catalog',
        payment: paymentCasesV1.unselected,
        consents: { ...common.consents, psychotest: consentCasesV1.required },
    },
    catalogSelf: {
        ...common,
        packageSource: 'catalog',
        payment: { ...unavailable, payer: 'self', state: 'unpaid' },
    },
    catalogOrganization: {
        ...common,
        packageSource: 'catalog',
        payment: paymentCasesV1.unbilled,
    },
    zeroUnpaid: {
        ...common,
        packageSource: 'charge_snapshot',
        payment: paymentCasesV1.unpaid,
    },
    paidMissing: {
        ...common,
        profile: missingProfileV1,
        packageSource: 'charge_snapshot',
        payment: paymentCasesV1.paid,
        consents: { ...common.consents, psychotest: consentCasesV1.required },
    },
    freeLocked: {
        ...common,
        packageSource: 'charge_snapshot',
        payment: paymentCasesV1.free,
    },
    mixedLocked: {
        ...common,
        profile: mixedProfileV1,
        packageSource: 'charge_snapshot',
        payment: paymentCasesV1.paid,
    },
    partial: {
        ...common,
        packageSource: 'charge_snapshot',
        payment: paymentCasesV1.paid,
        access: {
            state: 'partial',
            tests: [
                { testType: 'dass21', state: 'locked' },
                { testType: 'ist', state: 'ready' },
            ],
            startAvailable: false,
            message: 'Sebagian akses tes belum siap.',
        },
        consents: { ...common.consents, dass: consentCasesV1.required },
    },
    ready: {
        ...common,
        packageSource: 'charge_snapshot',
        payment: paymentCasesV1.paid,
        access: {
            state: 'ready',
            tests: [
                { testType: 'dass21', state: 'ready' },
                { testType: 'ist', state: 'ready' },
                { testType: 'kraepelin', state: 'ready' },
                { testType: 'papi', state: 'ready' },
                { testType: 'rmib', state: 'ready' },
            ],
            startAvailable: false,
            message:
                'Prasyarat akses tes terpenuhi; mesin sesi belum tersedia.',
        },
        consents: {
            psychotest: consentCasesV1.accepted,
            dass: consentCasesV1.accepted,
            legalReviewPending: false,
        },
    },
} as const satisfies Record<string, CheckoutSummaryV1>;
