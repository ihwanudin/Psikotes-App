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
const jsonResponse = (
    body = { data: { confirmed: true, replayed: false } },
    overrides = {},
) => ({
    status: 200,
    redirected: false,
    type: 'basic',
    headers: { get: () => 'application/json; charset=utf-8' },
    json: async () => body,
    ...overrides,
});
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

function validElements(consentTypes = ['psychotest', 'dass']) {
    const consents = [
        ...(consentTypes.includes('psychotest')
            ? [
                  element(
                      'consents[psychotest][documentVersion]',
                      'psychotest-v1',
                  ),
                  element('consents[psychotest][documentHash]', hash('b')),
                  element(
                      'consents[psychotest][accepted]',
                      'true',
                      'checkbox',
                      true,
                  ),
              ]
            : []),
        ...(consentTypes.includes('dass')
            ? [
                  element('consents[dass][documentVersion]', 'dass-v1'),
                  element('consents[dass][documentHash]', hash('c')),
                  element('consents[dass][accepted]', 'true', 'checkbox', true),
              ]
            : []),
    ];

    return [
        element('_checkout_csrf', csrf),
        element('profile[fullName]', '', 'text', true),
        element('profile[birthDate]', '', 'date', true),
        element('profile[gender]', '', 'select-one', true),
        ...consents,
    ];
}

test('validates an exact fail-closed profile and current-consent subset DOM shape', () => {
    const all = inspectConfirmationElements(validElements());
    assert.equal(all.csrf, csrf);
    assert.deepEqual(all.profileNames, ['birthDate', 'fullName', 'gender']);
    assert.deepEqual(all.consentTypes, ['psychotest', 'dass']);
    assert.equal(typeof all.consentSignature, 'string');
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
    const consentOnly = inspectConfirmationElements(
        validElements(['dass']).filter(
            (item) => !item.name.startsWith('profile['),
        ),
    );
    assert.deepEqual(consentOnly.profileNames, []);
    assert.deepEqual(consentOnly.consentTypes, ['dass']);
    const profileOnly = inspectConfirmationElements(validElements([]));
    assert.deepEqual(profileOnly.consentTypes, []);
    assert.equal(
        inspectConfirmationElements(
            validElements([]).filter(
                (item) => !item.name.startsWith('profile['),
            ),
        ),
        null,
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
    const inspected = inspectConfirmationElements(validElements());
    assert.deepEqual(
        buildConfirmationPayload(
            entries,
            inspected.profileNames,
            inspected.consentTypes,
            inspected.consentSignature,
        ),
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
            inspected.consentTypes,
            inspected.consentSignature,
        ),
    );
    assert.throws(() =>
        buildConfirmationPayload(
            entries.filter(([name]) => name !== 'consents[dass][accepted]'),
            ['birthDate', 'fullName', 'gender'],
            inspected.consentTypes,
            inspected.consentSignature,
        ),
    );
    assert.throws(() =>
        buildConfirmationPayload(
            entries.filter(([name]) => !name.startsWith('consents[dass]')),
            ['birthDate', 'fullName', 'gender'],
            inspected.consentTypes,
            inspected.consentSignature,
        ),
    );
    const dassElements = validElements(['dass']);
    const dassInspected = inspectConfirmationElements(dassElements);
    const dassEntries = entries.filter(
        ([name]) => !name.startsWith('consents[psychotest]'),
    );
    assert.deepEqual(
        buildConfirmationPayload(
            dassEntries,
            dassInspected.profileNames,
            dassInspected.consentTypes,
            dassInspected.consentSignature,
        ).consents,
        { dass: entriesToConsent(dassEntries, 'dass') },
    );
    assert.throws(() =>
        buildConfirmationPayload(
            entries,
            dassInspected.profileNames,
            dassInspected.consentTypes,
            dassInspected.consentSignature,
        ),
    );
    assert.throws(() =>
        buildConfirmationPayload(
            dassEntries.map(([name, value]) => [
                name,
                name === 'consents[dass][documentVersion]'
                    ? 'mutated-v2'
                    : value,
            ]),
            dassInspected.profileNames,
            dassInspected.consentTypes,
            dassInspected.consentSignature,
        ),
    );
    assert.deepEqual(
        buildConfirmationPayload(
            entries.filter(([name]) => !name.startsWith('consents[')),
            inspected.profileNames,
            [],
            '[]',
        ).consents,
        {},
    );
    assert.throws(() =>
        buildConfirmationPayload(
            entries.filter(([name]) => name === '_checkout_csrf'),
            inspected.profileNames,
            [],
            '[]',
        ),
    );
});

function entriesToConsent(entries, type) {
    const consent = {};

    for (const [name, value] of entries) {
        const match = name.match(
            new RegExp(
                `^consents\\[${type}]\\[(accepted|documentVersion|documentHash)]$`,
            ),
        );

        if (match) {
            consent[match[1]] =
                match[1] === 'accepted' ? value === 'true' : value;
        }
    }

    return consent;
}

test('posts same-origin JSON with dedicated CSRF and maps safe response states', async () => {
    const calls = [];
    const fetchImpl = async (...args) => {
        calls.push(args);

        return jsonResponse();
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
        assert.equal(result.message.includes('kedua'), false);
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

    for (const action of [
        '/checkout/payment',
        '/checkout/logout',
        '/checkout/other',
    ]) {
        await assert.rejects(() =>
            postConfirmation({
                action,
                origin: 'https://psikotes.oncam.id',
                csrf,
                payload,
                fetchImpl,
            }),
        );
    }

    assert.equal(calls.length, 1);
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

    assert.equal(
        (
            await postConfirmation({
                action: '/checkout/confirm',
                origin: 'https://psikotes.oncam.id',
                csrf,
                payload,
                fetchImpl: async () =>
                    jsonResponse({
                        data: { confirmed: true, replayed: true },
                    }),
            })
        ).kind,
        'success',
    );

    const malformedResponses = [
        null,
        'not-a-response',
        jsonResponse(undefined, { redirected: true }),
        jsonResponse(undefined, { status: 0, type: 'opaqueredirect' }),
        jsonResponse(undefined, { type: 'error' }),
        jsonResponse(undefined, { type: 'opaqueredirect' }),
        jsonResponse(undefined, {
            headers: { get: () => 'text/html' },
        }),
        jsonResponse(undefined, {
            headers: { get: () => 'application/json;garbage' },
        }),
        jsonResponse(undefined, {
            headers: {
                get: () => 'application/json; charset=utf-8, text/html',
            },
        }),
        jsonResponse(undefined, {
            json: async () => {
                throw new Error('PRIVATE_MALFORMED_BODY');
            },
        }),
        jsonResponse({}),
        jsonResponse({ data: { confirmed: false, replayed: false } }),
        jsonResponse({ data: { confirmed: true, replayed: 'false' } }),
        jsonResponse({ data: { confirmed: true, replayed: false, extra: 1 } }),
        jsonResponse({ data: { confirmed: true, replayed: false }, extra: 1 }),
    ];

    for (const response of malformedResponses) {
        const result = await postConfirmation({
            action: '/checkout/confirm',
            origin: 'https://psikotes.oncam.id',
            csrf,
            payload,
            fetchImpl: async () => response,
        });
        assert.deepEqual(result, {
            kind: 'malformed',
            retryable: false,
            message:
                'Respons konfirmasi tidak valid. Muat ulang halaman sebelum melanjutkan.',
        });
        assert.equal(result.message.includes('PRIVATE'), false);
    }
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
    let activeResponse = response;
    let fetchCount = 0;
    const fetchImpl = async () => {
        fetchCount++;

        return activeResponse;
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

    const dassHash = elements.find(
        (item) => item.name === 'consents[dass][documentHash]',
    );
    const initialDassHash = dassHash.value;
    dassHash.value = hash('d');
    let prevented = 0;
    await handler({ preventDefault: () => prevented++ });
    assert.equal(fetchCount, 0);
    assert.equal(submit.disabled, true);
    assert.equal(status.dataset.state, 'unexpected');
    dassHash.value = initialDassHash;
    submit.disabled = false;

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

    const first = handler({ preventDefault: () => prevented++ });
    const duplicate = handler({ preventDefault: () => prevented++ });
    assert.equal(fetchCount, 1);
    assert.equal(prevented, 3);
    assert.equal(submit.disabled, true);
    assert.equal(attributes.get('aria-busy'), 'true');
    release({ status: 422 });
    await Promise.all([first, duplicate]);
    assert.equal(submit.disabled, false);
    assert.equal(attributes.has('aria-busy'), false);
    assert.equal(status.dataset.state, 'validation');
    assert.equal(status.focused, true);

    activeResponse = Promise.resolve(jsonResponse({}));
    status.focused = false;
    await handler({ preventDefault: () => prevented++ });
    assert.equal(fetchCount, 2);
    assert.equal(submit.disabled, true);
    assert.equal(attributes.has('aria-busy'), false);
    assert.equal(status.dataset.state, 'malformed');
    assert.equal(status.focused, true);
});
