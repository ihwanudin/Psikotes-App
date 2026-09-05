import type {
    CheckoutSummaryV2,
    CheckoutSummaryV2Consent,
    CheckoutSummaryV2Payment,
    CheckoutSummaryV2PaymentAction,
} from '../../../resources/js/types/checkout-summary-v2';

// Compile-only probes: v2 exposes server choices, never browser authority.
declare const summary: CheckoutSummaryV2;

export const unavailableAction: CheckoutSummaryV2Payment = {
    payer: 'self',
    state: 'unpaid',
    amountSource: 'unavailable',
    amountIdr: null,
    consultationRequested: null,
    actionAvailable: false,
    action: null,
};

export const selectableAction: CheckoutSummaryV2PaymentAction = {
    path: '/checkout/payment',
    mode: 'select',
    currency: 'IDR',
    choices: [
        {
            consultationRequested: false,
            baseAmountIdr: 99_000,
            consultationAmountIdr: 0,
            amountIdr: 99_000,
        },
    ],
};

export const continuingPayment: CheckoutSummaryV2Payment = {
    payer: 'self',
    state: 'pending',
    amountSource: 'charge_snapshot',
    amountIdr: 149_000,
    consultationRequested: true,
    actionAvailable: true,
    action: {
        path: '/checkout/payment',
        mode: 'continue',
        currency: 'IDR',
        choices: [
            {
                consultationRequested: true,
                baseAmountIdr: 99_000,
                consultationAmountIdr: 50_000,
                amountIdr: 149_000,
            },
        ],
    },
};

// @ts-expect-error Terminal payment states cannot expose an action.
export const terminalAction: CheckoutSummaryV2Payment = {
    ...continuingPayment,
    state: 'paid',
};

// @ts-expect-error A self select action requires unavailable amount evidence.
export const selectWithSnapshot: CheckoutSummaryV2Payment = {
    payer: 'self',
    state: 'unpaid',
    amountSource: 'charge_snapshot',
    amountIdr: 99_000,
    consultationRequested: false,
    actionAvailable: true,
    action: selectableAction,
};

export const absentDass: CheckoutSummaryV2Consent = {
    // @ts-expect-error Mandatory DASS consent has no not_applicable variant.
    state: 'not_applicable',
};

// @ts-expect-error A false capability has no action object.
export const mismatchedAction: CheckoutSummaryV2Payment = {
    ...unavailableAction,
    action: selectableAction,
};

export const leakedReference: CheckoutSummaryV2PaymentAction = {
    ...selectableAction,
    // @ts-expect-error Provider references are outside the public contract.
    reference: 'PRIVATE_REFERENCE',
};

export const wrongPath: CheckoutSummaryV2PaymentAction = {
    ...selectableAction,
    // @ts-expect-error The mutation path is fixed and same-origin.
    path: '/checkout/other',
};

export const pricedFalseChoice: CheckoutSummaryV2PaymentAction = {
    path: '/checkout/payment',
    mode: 'select',
    currency: 'IDR',
    choices: [
        // @ts-expect-error A false choice has an exact zero addon.
        {
            consultationRequested: false,
            baseAmountIdr: 99_000,
            consultationAmountIdr: 1,
            amountIdr: 99_001,
        },
    ],
};

export function readonlyProbe(): void {
    // @ts-expect-error Server facts cannot be changed by the browser.
    summary.payment.actionAvailable = false;

    if (summary.payment.action !== null) {
        // @ts-expect-error Server-priced choices are immutable.
        summary.payment.action.choices.push(selectableAction.choices[0]);
    }
}
