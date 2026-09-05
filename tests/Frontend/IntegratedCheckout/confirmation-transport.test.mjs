import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    buildConfirmationPayload,
    enhanceCheckoutConfirmation,
    inspectConfirmationElements,
    postConfirmation,
} from '../../../public/js/checkout-confirmation-v1.js';

const csrf = `ocsrf1_${'a'.repeat(64)}`;
const hash = (character) => character.repeat(64);
const element = (
    name,
    value,
    type = 'hidden',
    required = false,
    checked = false,
) => ({
    name,
    value,
    type,
    required,
    checked,
});

function validElements() {
    return [
        element('_checkout_csrf', csrf),
        element('profile[fullName]', '', 'text', true),
        element('profile[birthDate]', '', 'date', true),
        element('profile[gender]', '', 'select-one', true),
        element('consents[psychotest][documentVersion]', 'psychotest-v1'),
        element('consents[psychotest][documentHash]', hash('b')),
        element('consents[psychotest][accepted]', 'true', 'checkbox', true),
        element('consents[dass][documentVersion]', 'dass-v1'),
        element('consents[dass][documentHash]', hash('c')),
        element('consents[dass][accepted]', 'true', 'checkbox', true),
    ];
}

test('validates a fail-closed missing-profile and consent DOM shape', () => {
    assert.deepEqual(inspectConfirmationElements(validElements()), {
        csrf,
        profileNames: ['birthDate', 'fullName', 'gender'],
    });
    assert.equal(
        inspectConfirmationElements([
            ...validElements(),
            element('profile[email]', ''),
        ]),
        null,
    );
    assert.equal(
        inspectConfirmationElements([
            ...validElements(),
            element('amountIdr', '0'),
        ]),
        null,
    );
    assert.equal(
        inspectConfirmationElements(
            validElements().map((item) =>
                item.name === 'consents[dass][documentHash]'
                    ? { ...item, value: 'invalid' }
                    : item,
            ),
        ),
        null,
    );
    assert.deepEqual(
        inspectConfirmationElements(
            validElements().filter((item) => !item.name.startsWith('profile[')),
        ),
        { csrf, profileNames: [] },
    );
    assert.equal(
        inspectConfirmationElements(
            validElements().map((item) =>
                item.name === 'consents[dass][accepted]'
                    ? { ...item, checked: true }
                    : item,
            ),
        ),
        null,
    );
});

test('builds exact JSON with literal true and no readonly or optional fields', () => {
    const entries = [
        ['_checkout_csrf', csrf],
        ['profile[fullName]', 'Peserta Sintetis'],
        ['profile[birthDate]', '2000-02-29'],
        ['profile[gender]', 'FEMALE'],
        ['consents[psychotest][documentVersion]', 'psychotest-v1'],
        ['consents[psychotest][documentHash]', hash('b')],
        ['consents[psychotest][accepted]', 'true'],
        ['consents[dass][documentVersion]', 'dass-v1'],
        ['consents[dass][documentHash]', hash('c')],
        ['consents[dass][accepted]', 'true'],
    ];
    assert.deepEqual(
        buildConfirmationPayload(entries, ['birthDate', 'fullName', 'gender']),
        {
            profile: {
                fullName: 'Peserta Sintetis',
                birthDate: '2000-02-29',
                gender: 'FEMALE',
            },
            consents: {
                psychotest: {
                    accepted: true,
                    documentVersion: 'psychotest-v1',
                    documentHash: hash('b'),
                },
                dass: {
                    accepted: true,
                    documentVersion: 'dass-v1',
                    documentHash: hash('c'),
                },
            },
        },
    );
    assert.throws(() =>
        buildConfirmationPayload(
            [...entries, ['payer', 'self']],
            ['birthDate', 'fullName', 'gender'],
        ),
    );
    assert.throws(() =>
        buildConfirmationPayload(
            entries.filter(([name]) => name !== 'consents[dass][accepted]'),
            ['birthDate', 'fullName', 'gender'],
        ),
    );
    assert.deepEqual(
        buildConfirmationPayload(
            entries.filter(([name]) => !name.startsWith('profile[')),
            [],
        ).profile,
        {},
    );
});

test('posts same-origin JSON with dedicated CSRF and maps safe response states', async () => {
    const calls = [];
    const fetchImpl = async (...args) => {
        calls.push(args);

        return { status: 200 };
    };
    const payload = { profile: {}, consents: {} };
    assert.deepEqual(
        await postConfirmation({
            action: '/checkout/confirm',
            origin: 'https://psikotes.oncam.id',
            csrf,
            payload,
            fetchImpl,
        }),
        {
            kind: 'success',
            retryable: false,
            message:
                'Konfirmasi tersimpan. Muat ulang halaman untuk melihat status terbaru.',
        },
    );
    assert.deepEqual(calls, [
        [
            '/checkout/confirm',
            {
                method: 'POST',
                credentials: 'same-origin',
                redirect: 'manual',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Checkout-CSRF': csrf,
                },
                body: JSON.stringify(payload),
            },
        ],
    ]);

    for (const [status, kind, retryable] of [
        [409, 'conflict', false],
        [419, 'expired', false],
        [422, 'validation', true],
        [429, 'throttled', true],
        [500, 'unexpected', true],
    ]) {
        const result = await postConfirmation({
            action: '/checkout/confirm',
            origin: 'https://psikotes.oncam.id',
            csrf,
            payload,
            fetchImpl: async () => ({ status }),
        });
        assert.equal(result.kind, kind);
        assert.equal(result.retryable, retryable);
        assert.equal(result.message.includes('PRIVATE'), false);
    }

    await assert.rejects(() =>
        postConfirmation({
            action: 'https://foreign.invalid/collect',
            origin: 'https://psikotes.oncam.id',
            csrf,
            payload,
            fetchImpl,
        }),
    );
    assert.deepEqual(
        await postConfirmation({
            action: '/checkout/confirm',
            origin: 'https://psikotes.oncam.id',
            csrf,
            payload,
            fetchImpl: async () => {
                throw new Error('PRIVATE_NETWORK_DETAIL');
            },
        }),
        {
            kind: 'network',
            retryable: true,
            message: 'Koneksi bermasalah. Periksa jaringan lalu coba lagi.',
        },
    );
});

test('enhances valid DOM, prevents native submit, blocks duplicates, and focuses retryable errors', async () => {
    const elements = validElements();
    const submit = {
        disabled: true,
        removeAttribute(name) {
            assert.equal(name, 'aria-disabled');
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
    let handler;
    const attributes = new Map([['action', '/checkout/confirm']]);
    const form = {
        elements,
        dataset: {},
        querySelector(selector) {
            return selector === 'button[type="submit"]' ? submit : status;
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
        addEventListener(type, callback) {
            assert.equal(type, 'submit');
            handler = callback;
        },
        reportValidity() {
            return true;
        },
    };
    const documentRoot = { querySelector: () => form };
    let release;
    const response = new Promise((resolve) => {
        release = resolve;
    });
    let fetchCount = 0;
    const fetchImpl = async () => {
        fetchCount++;

        return response;
    };
    const entries = [
        ['_checkout_csrf', csrf],
        ['profile[fullName]', 'Peserta Sintetis'],
        ['profile[birthDate]', '2000-02-29'],
        ['profile[gender]', 'FEMALE'],
        ['consents[psychotest][documentVersion]', 'psychotest-v1'],
        ['consents[psychotest][documentHash]', hash('b')],
        ['consents[psychotest][accepted]', 'true'],
        ['consents[dass][documentVersion]', 'dass-v1'],
        ['consents[dass][documentHash]', hash('c')],
        ['consents[dass][accepted]', 'true'],
    ];

    assert.equal(
        enhanceCheckoutConfirmation(
            documentRoot,
            { origin: 'https://psikotes.oncam.id' },
            fetchImpl,
            () => ({ entries: () => entries }),
        ),
        true,
    );
    assert.equal(submit.disabled, false);

    for (const item of elements) {
        if (item.type === 'checkbox') {
            item.checked = true;
        }

        if (item.name === 'profile[fullName]') {
            item.value = 'Peserta Sintetis';
        }

        if (item.name === 'profile[birthDate]') {
            item.value = '2000-02-29';
        }

        if (item.name === 'profile[gender]') {
            item.value = 'FEMALE';
        }
    }

    let prevented = 0;
    const first = handler({ preventDefault: () => prevented++ });
    const duplicate = handler({ preventDefault: () => prevented++ });
    assert.equal(fetchCount, 1);
    assert.equal(prevented, 2);
    assert.equal(submit.disabled, true);
    assert.equal(attributes.get('aria-busy'), 'true');
    release({ status: 422 });
    await Promise.all([first, duplicate]);
    assert.equal(submit.disabled, false);
    assert.equal(attributes.has('aria-busy'), false);
    assert.equal(status.dataset.state, 'validation');
    assert.equal(status.focused, true);
});
