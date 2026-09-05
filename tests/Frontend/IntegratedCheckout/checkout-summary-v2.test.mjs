import assert from 'node:assert/strict';
import { test } from 'node:test';

import { parseCheckoutSummaryV2 } from '../../../resources/js/types/checkout-summary-v2.ts';

const consent = { state: 'accepted', version: 'synthetic-v2' };

function summary(payment = paymentWithSelect()) {
    return {
        contractVersion: 'checkout-summary-v2',
        sourceName: 'Integrasi sintetis',
        branchName: 'Cabang Sintetis',
        packageName: 'Paket Sintetis',
        packageSource: 'catalog',
        attemptLabel: 'Assessment Anda',
        profile: [
            profile('fullName', 'Nama lengkap', true),
            profile('birthDate', 'Tanggal lahir', true),
            profile('gender', 'Jenis kelamin', true),
            profile('educationLevel', 'Pendidikan terakhir', true),
            profile('intendedField', 'Bidang tujuan', true),
            profile('email', 'Email', false),
            profile('phone', 'Nomor telepon', true),
        ],
        identityMessage: 'Verifikasi identitas tetap diperlukan.',
        payment,
        access: {
            state: 'locked',
            tests: [
                { testType: 'dass21', state: 'locked' },
                { testType: 'ist', state: 'locked' },
            ],
            startAvailable: false,
            message: 'Akses tes belum siap.',
        },
        consents: {
            psychotest: { ...consent },
            dass: { ...consent },
            legalReviewPending: false,
        },
    };
}

function profile(key, label, required) {
    return { key, label, required, state: 'missing' };
}

function paymentWithSelect() {
    return {
        payer: 'self',
        state: 'unpaid',
        amountIdr: null,
        amountSource: 'unavailable',
        consultationRequested: null,
        actionAvailable: true,
        action: {
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
                {
                    consultationRequested: true,
                    baseAmountIdr: 99_000,
                    consultationAmountIdr: 50_000,
                    amountIdr: 149_000,
                },
            ],
        },
    };
}

function clone(value) {
    return structuredClone(value);
}

function rejects(mutator) {
    const value = summary();
    mutator(value);
    assert.throws(
        () => parseCheckoutSummaryV2(value),
        /Invalid checkout summary v2/,
    );
}

test('accepts exact select, continue, and unavailable payment capabilities', () => {
    const select = summary();
    assert.deepEqual(parseCheckoutSummaryV2(select), select);

    const continuing = paymentWithSelect();
    continuing.action.mode = 'continue';
    continuing.action.choices = [continuing.action.choices[1]];
    assert.deepEqual(
        parseCheckoutSummaryV2(summary(continuing)),
        summary(continuing),
    );

    const unavailable = paymentWithSelect();
    unavailable.actionAvailable = false;
    unavailable.action = null;
    assert.deepEqual(
        parseCheckoutSummaryV2(summary(unavailable)),
        summary(unavailable),
    );
});

test('requires actionAvailable to match an exact fixed payment action', () => {
    rejects((value) => {
        value.payment.actionAvailable = false;
    });
    rejects((value) => {
        value.payment.action = null;
    });
    rejects((value) => {
        value.payment.action.path = '/checkout/other';
    });
    rejects((value) => {
        value.payment.action.currency = 'USD';
    });
    rejects((value) => {
        value.payment.action.invoiceUrl =
            'https://payments.example.invalid/private';
    });
});

test('enforces nonempty ordered unique choices and continue has exactly one', () => {
    rejects((value) => {
        value.payment.action.choices = [];
    });
    rejects((value) => {
        value.payment.action.choices.reverse();
    });
    rejects((value) => {
        value.payment.action.choices.push(
            clone(value.payment.action.choices[0]),
        );
    });
    rejects((value) => {
        value.payment.action.mode = 'continue';
    });
    rejects((value) => {
        value.payment.action.mode = 'resume';
    });
});

test('allows only the exact zero select capability for organization payer', () => {
    const zeroOrganization = paymentWithSelect();
    zeroOrganization.payer = 'organization';
    zeroOrganization.organizationName = 'Cabang Sintetis';
    zeroOrganization.action.choices = [
        {
            consultationRequested: false,
            baseAmountIdr: 0,
            consultationAmountIdr: 0,
            amountIdr: 0,
        },
    ];
    assert.deepEqual(
        parseCheckoutSummaryV2(summary(zeroOrganization)),
        summary(zeroOrganization),
    );

    rejects((value) => {
        value.payment.payer = 'organization';
        value.payment.organizationName = 'Cabang Sintetis';
    });
    rejects((value) => {
        value.payment.payer = 'organization';
        value.payment.organizationName = 'Cabang Sintetis';
        value.payment.action.mode = 'continue';
        value.payment.action.choices = [
            {
                consultationRequested: false,
                baseAmountIdr: 0,
                consultationAmountIdr: 0,
                amountIdr: 0,
            },
        ];
    });
});

test('accepts only safe exact server totals and consultation addon rules', () => {
    for (const mutate of [
        (choice) => {
            choice.baseAmountIdr = -1;
        },
        (choice) => {
            choice.amountIdr = 99_001;
        },
        (choice) => {
            choice.baseAmountIdr = Number.MAX_SAFE_INTEGER + 1;
        },
        (choice) => {
            choice.consultationAmountIdr = 1;
            choice.amountIdr = 99_001;
        },
    ]) {
        rejects((value) => mutate(value.payment.action.choices[0]));
    }

    rejects((value) => {
        value.payment.action.choices[1].consultationAmountIdr = 0;
        value.payment.action.choices[1].amountIdr = 99_000;
    });
    rejects((value) => {
        value.payment.action.choices[0].amountIdr = 99_000.5;
    });
});

test('rejects extra or missing keys and authority-bearing fields at every payment layer', () => {
    rejects((value) => {
        value.extra = true;
    });
    rejects((value) => {
        delete value.payment.state;
    });
    rejects((value) => {
        value.profile[0].input = 'text';
    });
    rejects((value) => {
        delete value.access.message;
    });
    rejects((value) => {
        value.consents.dass.documentId = 3;
    });

    for (const [scope, field, fieldValue] of [
        ['payment', 'participantId', 7],
        ['payment', 'batchTotal', 10],
        ['action', 'paymentUrl', 'https://payments.example.invalid/private'],
        ['action', 'reference', 'PRIVATE_REFERENCE'],
        ['choice', 'billId', 9],
    ]) {
        rejects((value) => {
            const target =
                scope === 'payment'
                    ? value.payment
                    : scope === 'action'
                      ? value.payment.action
                      : value.payment.action.choices[0];
            target[field] = fieldValue;
        });
    }
});

test('requires mandatory DASS consent and a DASS-21 test in the package', () => {
    rejects((value) => {
        value.consents.dass = { state: 'not_applicable' };
    });
    rejects((value) => {
        delete value.consents.dass;
    });
    rejects((value) => {
        value.access.tests = [{ testType: 'ist', state: 'locked' }];
    });
});

test('retains exact v1 visible semantics outside the v2 capability', () => {
    rejects((value) => {
        value.profile[0].displayValue = '';
    });
    rejects((value) => {
        value.profile[5].required = true;
    });
    rejects((value) => {
        value.access.startAvailable = true;
    });
    rejects((value) => {
        value.payment.amountIdr = 0;
    });

    const snapshot = paymentWithSelect();
    snapshot.amountSource = 'charge_snapshot';
    snapshot.amountIdr = 149_000;
    snapshot.consultationRequested = true;
    const snapshotSummary = summary(snapshot);
    snapshotSummary.packageSource = 'charge_snapshot';
    assert.deepEqual(parseCheckoutSummaryV2(snapshotSummary), snapshotSummary);

    rejects((value) => {
        value.payment.amountSource = 'charge_snapshot';
        value.payment.amountIdr = 149_000;
        value.payment.consultationRequested = true;
    });
});
