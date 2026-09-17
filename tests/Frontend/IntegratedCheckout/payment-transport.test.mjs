import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    enhanceCheckoutPayment,
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
            retryable: false,
            message:
                'Respons pembayaran tidak dapat digunakan. Muat ulang halaman dan coba lagi.',
        });
    }
});

test('maps HTTP, abort, and network failures without reading or reflecting sensitive bodies', async () => {
    const expected = new Map([
        [409, ['conflict', false]],
        [419, ['expired', false]],
        [422, ['validation', false]],
        [429, ['throttled', true]],
        [503, ['unavailable', true]],
        [500, ['unexpected', true]],
        [302, ['unexpected', false]],
    ]);

    for (const [status, [kind, retryable]] of expected) {
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
        assert.equal(result.retryable, retryable);
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
            retryable: true,
            message:
                'Koneksi pembayaran bermasalah. Periksa jaringan lalu coba lagi.',
        });
        assert.equal(JSON.stringify(result).includes('PRIVATE'), false);
    }
});

function paymentHarness({ mode = 'select', values = ['false', 'true'] } = {}) {
    const listeners = new Map();
    const controls = values.map((value) => ({
        type: mode === 'select' ? 'radio' : 'hidden',
        name: 'consultationRequested',
        value,
        required: mode === 'select',
        checked: false,
        disabled: true,
        addEventListener(type, listener) {
            this.listener = listener;
            assert.equal(type, 'change');
        },
    }));
    const submitAttributes = new Map([['aria-disabled', 'true']]);
    const submit = {
        disabled: true,
        setAttribute(name, value) {
            submitAttributes.set(name, value);
        },
    };
    const status = {
        textContent: '',
        dataset: {},
        focused: false,
        focus() {
            this.focused = true;
        },
    };
    const attributes = new Map([['action', '/checkout/payment']]);
    const form = {
        dataset: { paymentMode: mode },
        querySelector(selector) {
            return selector === 'button[type="submit"]' ? submit : status;
        },
        querySelectorAll() {
            return controls;
        },
        getAttribute(name) {
            return attributes.get(name) ?? null;
        },
        setAttribute(name, value) {
            attributes.set(name, value);
        },
        removeAttribute(name) {
            attributes.delete(name);
        },
        addEventListener(type, listener) {
            listeners.set(type, listener);
        },
        reportValidity() {
            return true;
        },
    };
    const meta = { getAttribute: () => csrf };
    const documentRoot = {
        querySelector(selector) {
            return selector === 'form[data-checkout-payment]' ? form : meta;
        },
    };
    const navigations = [];
    const locationObject = {
        assign(value) {
            navigations.push(value);
        },
    };

    return {
        attributes,
        controls,
        documentRoot,
        form,
        listeners,
        locationObject,
        navigations,
        status,
        submit,
        submitAttributes,
    };
}

test('select mode starts unselected, then pending locks controls and uses same-tab persisted URL', async () => {
    const harness = paymentHarness();
    let release;
    const pendingResponse = new Promise((resolve) => {
        release = resolve;
    });
    let fetchCount = 0;

    assert.equal(
        enhanceCheckoutPayment(
            harness.documentRoot,
            harness.locationObject,
            async () => {
                fetchCount++;

                return pendingResponse;
            },
        ),
        true,
    );
    assert.equal(harness.submit.disabled, true);
    assert.equal(
        harness.controls.every((control) => !control.disabled),
        true,
    );
    harness.controls[1].checked = true;
    harness.controls[1].listener();
    assert.equal(harness.submit.disabled, false);

    let prevented = 0;
    const first = harness.listeners.get('submit')({
        preventDefault: () => prevented++,
    });
    const duplicate = harness.listeners.get('submit')({
        preventDefault: () => prevented++,
    });
    assert.equal(fetchCount, 1);
    assert.equal(prevented, 2);
    assert.equal(harness.attributes.get('aria-busy'), 'true');
    assert.equal(
        harness.controls.every((control) => control.disabled),
        true,
    );
    release(
        response(200, {
            data: {
                paymentState: 'pending',
                paymentUrl: 'https://payments.example.invalid/persisted',
            },
        }),
    );
    await Promise.all([first, duplicate]);
    assert.deepEqual(harness.navigations, [
        'https://payments.example.invalid/persisted',
    ]);
    assert.equal(harness.status.dataset.state, 'success');
});

test('continue is immutable, paid reloads checkout, permanent errors stay locked, and transient failures retry', async () => {
    const paid = paymentHarness({ mode: 'continue', values: ['true'] });
    assert.equal(
        enhanceCheckoutPayment(
            paid.documentRoot,
            paid.locationObject,
            async () =>
                response(200, {
                    data: { paymentState: 'paid', paymentUrl: null },
                }),
        ),
        true,
    );
    assert.equal(paid.submit.disabled, false);
    await paid.listeners.get('submit')({ preventDefault() {} });
    assert.deepEqual(paid.navigations, ['/checkout']);
    assert.equal(paid.controls[0].disabled, true);

    for (const [statusCode, retryable] of [
        [409, false],
        [419, false],
        [422, false],
        [429, true],
        [500, true],
    ]) {
        const harness = paymentHarness();
        enhanceCheckoutPayment(
            harness.documentRoot,
            harness.locationObject,
            async () => response(statusCode, null),
        );
        harness.controls[0].checked = true;
        harness.controls[0].listener();
        await harness.listeners.get('submit')({ preventDefault() {} });
        assert.equal(
            harness.controls.every((control) => control.disabled),
            !retryable,
        );
        assert.equal(harness.submit.disabled, !retryable);
        assert.equal(harness.status.focused, true);
        assert.deepEqual(harness.navigations, []);
    }
});

test('malformed response and mutated controls remain locked until reload', async () => {
    const malformed = paymentHarness();
    enhanceCheckoutPayment(
        malformed.documentRoot,
        malformed.locationObject,
        async () => response(200, { data: { paymentState: 'paid' } }),
    );
    malformed.controls[0].checked = true;
    malformed.controls[0].listener();
    await malformed.listeners.get('submit')({ preventDefault() {} });
    assert.equal(malformed.submit.disabled, true);
    assert.equal(
        malformed.controls.every((control) => control.disabled),
        true,
    );
    assert.equal(malformed.status.dataset.state, 'malformed');

    const mutated = paymentHarness({ mode: 'continue', values: ['false'] });
    let fetchCount = 0;
    enhanceCheckoutPayment(
        mutated.documentRoot,
        mutated.locationObject,
        async () => {
            fetchCount++;

            return response(200, {
                data: { paymentState: 'paid', paymentUrl: null },
            });
        },
    );
    mutated.controls[0].value = 'true';
    await mutated.listeners.get('submit')({ preventDefault() {} });
    assert.equal(fetchCount, 0);
    assert.equal(mutated.submit.disabled, true);
    assert.equal(mutated.controls[0].disabled, true);
    assert.equal(mutated.status.dataset.state, 'malformed');
});
