import type {
    CheckoutSummaryV1,
    CheckoutSummaryV1Consent,
    CheckoutSummaryV1Payment,
    CheckoutSummaryV1Profile,
    CheckoutSummaryV1ProfileField,
    CheckoutSummaryV1Test,
} from '../../../resources/js/types/checkout-summary-v1';
import type { CheckoutSummary as DraftSummary } from '../../../resources/js/types/integrated-checkout';
import {
    completeProfileV1,
    missingProfileV1,
    paymentCasesV1,
    summaryCasesV1,
} from './summary-v1.fixtures';

// Compile-only probes. Never imported by a page or executed as a submission.
type Assert<T extends true> = T;
type Equal<A, B> =
    (<T>() => T extends A ? 1 : 2) extends <T>() => T extends B ? 1 : 2
        ? true
        : false;
type KeysOfUnion<T> = T extends unknown ? keyof T : never;
export type ConsentVariantKeys = Assert<
    Equal<
        KeysOfUnion<CheckoutSummaryV1Consent>,
        'state' | 'version' | 'document'
    >
>;
export type DocumentKeys = Assert<
    Equal<
        keyof Extract<
            CheckoutSummaryV1Consent,
            { state: 'required' }
        >['document'],
        'version' | 'title' | 'text'
    >
>;
export type AccessFixtureCoverage = Assert<
    Equal<
        (typeof summaryCasesV1)[keyof typeof summaryCasesV1]['access']['state'],
        CheckoutSummaryV1['access']['state']
    >
>;
export type SummaryKeys = Assert<
    Equal<
        keyof CheckoutSummaryV1,
        | 'contractVersion'
        | 'sourceName'
        | 'branchName'
        | 'packageName'
        | 'packageSource'
        | 'attemptLabel'
        | 'profile'
        | 'identityMessage'
        | 'payment'
        | 'access'
        | 'consents'
    >
>;
export type PaymentKeys = Assert<
    Equal<
        KeysOfUnion<CheckoutSummaryV1Payment>,
        | 'payer'
        | 'state'
        | 'amountIdr'
        | 'amountSource'
        | 'consultationRequested'
        | 'actionAvailable'
        | 'organizationName'
    >
>;
export type ProfileKeys = Assert<
    Equal<
        KeysOfUnion<CheckoutSummaryV1ProfileField>,
        'key' | 'label' | 'state' | 'required' | 'displayValue'
    >
>;
export type AccessKeys = Assert<
    Equal<
        keyof CheckoutSummaryV1['access'],
        'state' | 'tests' | 'startAvailable' | 'message'
    >
>;
export type ConsentKeys = Assert<
    Equal<
        keyof CheckoutSummaryV1['consents'],
        'psychotest' | 'dass' | 'legalReviewPending'
    >
>;
export type TestKeys = Assert<
    Equal<keyof CheckoutSummaryV1Test, 'testType' | 'state'>
>;
export type NoDraftCast = Assert<
    Equal<CheckoutSummaryV1 extends DraftSummary ? true : false, false>
>;
export type NoReverseDraftCast = Assert<
    Equal<DraftSummary extends CheckoutSummaryV1 ? true : false, false>
>;

export const nullLocked: CheckoutSummaryV1ProfileField = {
    ...completeProfileV1[0],
    // @ts-expect-error Locked display is a string, never nullable.
    displayValue: null,
};
// @ts-expect-error Missing field has no displayValue, including empty placeholders.
export const placeholderMissing: CheckoutSummaryV1ProfileField = {
    ...missingProfileV1[0],
    displayValue: '',
};
// @ts-expect-error Email alone is optional; required metadata cannot be invented.
export const requiredEmail: CheckoutSummaryV1ProfileField = {
    ...missingProfileV1[5],
    required: true,
};
// @ts-expect-error Full name remains required even when locked.
export const optionalName: CheckoutSummaryV1ProfileField = {
    ...completeProfileV1[0],
    required: false,
};
export const lostEmail: CheckoutSummaryV1Profile = [
    missingProfileV1[0],
    missingProfileV1[1],
    missingProfileV1[2],
    missingProfileV1[3],
    missingProfileV1[4],
    // @ts-expect-error All seven fields must remain in the serialized profile.
    missingProfileV1[6],
];
export const inventedInput: CheckoutSummaryV1ProfileField = {
    ...missingProfileV1[0],
    // @ts-expect-error Input configuration is not part of the read-only contract.
    input: 'text',
};
export const inventedOptions: CheckoutSummaryV1ProfileField = {
    ...missingProfileV1[2],
    // @ts-expect-error Options are not supplied by this DTO.
    options: [],
};
export const paymentAction: CheckoutSummaryV1Payment = {
    ...paymentCasesV1.pending,
    // @ts-expect-error No payment action in any state.
    actionAvailable: true,
};
// @ts-expect-error Unavailable amount is null, not zero.
export const guessedZero: CheckoutSummaryV1Payment = {
    ...paymentCasesV1.unselected,
    amountIdr: 0,
};
// @ts-expect-error Snapshot consultation choice cannot be null.
export const missingConsultation: CheckoutSummaryV1Payment = {
    ...paymentCasesV1.paid,
    consultationRequested: null,
};
// @ts-expect-error Organization name must be present for organization payer.
export const missingOrganization: CheckoutSummaryV1Payment = {
    ...paymentCasesV1.unpaid,
    payer: 'organization',
};
// @ts-expect-error Self does not receive an organization payment label.
export const leakedOrganization: CheckoutSummaryV1Payment = {
    ...paymentCasesV1.unpaid,
    organizationName: 'Synthetic',
};
export const inventedReview: CheckoutSummaryV1Payment = {
    ...paymentCasesV1.paid,
    // @ts-expect-error DRAFT review state is not serialized; recovery_required is explicit.
    state: 'review',
};
export const leakedInvoice: CheckoutSummaryV1Payment = {
    ...paymentCasesV1.paid,
    // @ts-expect-error Invoice data is not projected.
    invoiceUrl: '/synthetic-invoice',
};
// @ts-expect-error Catalog labels cannot be combined with snapshot monetary facts.
export const wrongProvenance: CheckoutSummaryV1 = {
    ...summaryCasesV1.ready,
    packageSource: 'catalog',
};
export const startAction: CheckoutSummaryV1['access'] = {
    ...summaryCasesV1.ready.access,
    // @ts-expect-error Ready prerequisites never expose a start action.
    startAvailable: true,
};
export const emptyTests: CheckoutSummaryV1['access'] = {
    ...summaryCasesV1.ready.access,
    // @ts-expect-error A package always contains at least one supported test.
    tests: [],
};
export const unknownTest: CheckoutSummaryV1Test = {
    // @ts-expect-error Unknown instrument types are rejected.
    testType: 'unknown',
    state: 'locked',
};
export const clinicalLeak: CheckoutSummaryV1Test = {
    testType: 'dass21',
    state: 'locked',
    // @ts-expect-error Clinical facts are absent from per-test access facts.
    score: 1,
};
export const incompleteDocument: CheckoutSummaryV1Consent = {
    state: 'required',
    // @ts-expect-error Required consent includes the exact public document shape.
    document: { version: 'synthetic-v1', title: 'Synthetic' },
};
export const inventedDecline: CheckoutSummaryV1Consent = {
    // @ts-expect-error The server projects non-accepted consent as required, never declined.
    state: 'declined',
    version: 'synthetic-v1',
};
export const absentMain: CheckoutSummaryV1['consents']['psychotest'] = {
    // @ts-expect-error Only DASS may be not_applicable.
    state: 'not_applicable',
};
// @ts-expect-error Not-applicable DASS does not carry a document version.
export const absentDassVersion: CheckoutSummaryV1['consents']['dass'] = {
    state: 'not_applicable',
    version: 'synthetic-v1',
};
export const inventedFormKey: CheckoutSummaryV1 = {
    ...summaryCasesV1.ready,
    // @ts-expect-error No form identity in serialized summary.
    formKey: 'synthetic',
};
export const leakedId: CheckoutSummaryV1 = {
    ...summaryCasesV1.ready,
    // @ts-expect-error No request-scoping identifier in serialized summary.
    participantId: 1,
};

export function readonlyProbes(summary: CheckoutSummaryV1): void {
    // @ts-expect-error Scalar display facts are readonly.
    summary.branchName = 'changed';
    // @ts-expect-error Nested profile facts are readonly.
    summary.profile[0].label = 'changed';
    // @ts-expect-error Test list cannot be appended client-side.
    summary.access.tests.push({ testType: 'ist', state: 'ready' });

    if (summary.consents.psychotest.state === 'required') {
        // @ts-expect-error Legal document contents are readonly.
        summary.consents.psychotest.document.text = 'changed';
    }
}
