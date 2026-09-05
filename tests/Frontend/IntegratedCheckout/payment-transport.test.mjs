import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    parseCheckoutPaymentResponse,
    requestCheckoutPayment,
} from '../../../public/js/checkout-payment-v1.js';

const csrf = `ocsrf1_${'a'.repeat(64)}`;

function response(status, payload, overrides = {}) {
    return {
        status,
        redirected: false,
        type: 'basic',
        json: async () => payload,
        ...overrides,
    };
}

test('sends the exact fixed same-origin JSON request for either consultation choice', async () => {
    for (const consultationRequested of [false, true]) {
        const calls = [];
        const result = await requestCheckoutPayment(consultationRequested, {
            csrf,
            fetchImpl: async (...args) => {
                calls.push(args);

                return response(200, {
                    data: {
                        paymentState: 'pending',
                        paymentUrl:
                            'https://payments.example.invalid/invoice/SAFE',
                    },
                });
            },
        });

        assert.deepEqual(calls, [
            [
                '/checkout/payment',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    redirect: 'manual',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Checkout-CSRF': csrf,
                    },
                    body: JSON.stringify({ consultationRequested }),
                },
            ],
        ]);
        assert.deepEqual(result, {
            ok: true,
            paymentState: 'pending',
            paymentUrl: 'https://payments.example.invalid/invoice/SAFE',
        });
    }
});

test('rejects nonboolean input, malformed CSRF, and caller-supplied authority before fetch', async () => {
    const invalid = [
        null,
        0,
        1,
        'true',
        {},
        [],
        { consultationRequested: true },
    ];
    let calls = 0;

    for (const value of invalid) {
        await assert.rejects(
            () =>
                requestCheckoutPayment(value, {
                    csrf,
                    fetchImpl: async () => {
                        calls++;
                    },
                }),
            /Invalid checkout payment request/,
        );
    }

    for (const extra of [
        { origin: 'https://psikotes.oncam.id' },
        { url: 'https://payments.example.invalid/private' },
        { path: '/checkout/other' },
        { amountIdr: 1 },
        { payer: 'self' },
    ]) {
        await assert.rejects(
            () =>
                requestCheckoutPayment(false, {
                    csrf,
                    fetchImpl: async () => {
                        calls++;
                    },
                    ...extra,
                }),
            /Invalid checkout payment adapter/,
        );
    }

    await assert.rejects(
        () =>
            requestCheckoutPayment(false, {
                csrf: 'PRIVATE_CSRF',
                fetchImpl: async () => {
                    calls++;
                },
            }),
        /Invalid checkout payment adapter/,
    );
    assert.equal(calls, 0);
});

test('accepts only exact pending HTTPS URL or paid null success payloads', async () => {
    assert.deepEqual(
        await parseCheckoutPaymentResponse(
            response(200, {
                data: { paymentState: 'paid', paymentUrl: null },
            }),
        ),
        { ok: true, paymentState: 'paid', paymentUrl: null },
    );

    const malformed = [
        {},
        { data: { paymentState: 'paid', paymentUrl: null }, extra: true },
        { data: { paymentState: 'paid', paymentUrl: null, amount: 1 } },
        { data: { paymentState: 'pending', paymentUrl: null } },
        {
            data: {
                paymentState: 'pending',
                paymentUrl: 'http://payments.example.invalid/a',
            },
        },
        {
            data: {
                paymentState: 'pending',
                paymentUrl: 'https://user:pass@payments.example.invalid/a',
            },
        },
        { data: { paymentState: 'pending', paymentUrl: 'not a URL' } },
        {
            data: {
                paymentState: 'paid',
                paymentUrl: 'https://payments.example.invalid/a',
            },
        },
        { data: { paymentState: 'free', paymentUrl: null } },
    ];

    for (const payload of malformed) {
        const result = await parseCheckoutPaymentResponse(
            response(200, payload),
        );
        assert.equal(result.ok, false);
        assert.equal(result.kind, 'malformed');
        assert.equal(
            JSON.stringify(result).includes('payments.example.invalid'),
            false,
        );
    }

    for (const unsafe of [
        response(
            200,
            { data: { paymentState: 'paid', paymentUrl: null } },
            { redirected: true },
        ),
        response(
            200,
            { data: { paymentState: 'paid', paymentUrl: null } },
            { type: 'opaqueredirect' },
        ),
        response(200, null, {
            json: async () => {
                throw new Error('PRIVATE_RESPONSE_BODY');
            },
        }),
    ]) {
        assert.deepEqual(await parseCheckoutPaymentResponse(unsafe), {
            ok: false,
            kind: 'malformed',
            message:
                'Respons pembayaran tidak dapat digunakan. Muat ulang halaman dan coba lagi.',
        });
    }
});

test('maps HTTP, abort, and network failures without reading or reflecting sensitive bodies', async () => {
    const expected = new Map([
        [409, 'conflict'],
        [419, 'expired'],
        [422, 'validation'],
        [429, 'throttled'],
        [503, 'unavailable'],
        [500, 'unexpected'],
        [302, 'unexpected'],
    ]);

    for (const [status, kind] of expected) {
        let bodyRead = false;
        const result = await parseCheckoutPaymentResponse(
            response(status, null, {
                json: async () => {
                    bodyRead = true;

                    throw new Error('PRIVATE_BODY');
                },
            }),
        );
        assert.equal(result.ok, false);
        assert.equal(result.kind, kind);
        assert.equal(bodyRead, false);
        assert.equal(JSON.stringify(result).includes('PRIVATE'), false);
    }

    for (const error of [
        new Error('PRIVATE_NETWORK_SECRET'),
        new DOMException('PRIVATE_ABORT_SECRET', 'AbortError'),
    ]) {
        const result = await requestCheckoutPayment(true, {
            csrf,
            fetchImpl: async () => {
                throw error;
            },
        });
        assert.deepEqual(result, {
            ok: false,
            kind: 'network',
            message:
                'Koneksi pembayaran bermasalah. Periksa jaringan lalu coba lagi.',
        });
        assert.equal(JSON.stringify(result).includes('PRIVATE'), false);
    }
});
