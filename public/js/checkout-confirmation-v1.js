const PROFILE_CONTROLS = {
    fullName: 'text',
    birthDate: 'date',
    gender: 'select-one',
    educationLevel: 'text',
    intendedField: 'select-one',
    phone: 'tel',
};
const CONSENT_TYPES = ['psychotest', 'dass'];
const CSRF_PATTERN = /^ocsrf1_[0-9a-f]{64}$/;
const HASH_PATTERN = /^[0-9a-f]{64}$/;
const PROFILE_NAME_PATTERN = /^profile\[([A-Za-z]+)]$/;
const CONSENT_NAME_PATTERN =
    /^consents\[(psychotest|dass)]\[(accepted|documentVersion|documentHash)]$/;

function safeAction(action, origin) {
    if (
        typeof action !== 'string' ||
        !/^\/checkout\/[a-z0-9-]+$/.test(action)
    ) {
        return false;
    }

    try {
        return new URL(action, origin).origin === origin;
    } catch {
        return false;
    }
}

export function inspectConfirmationElements(elements, requireUnchecked = true) {
    const seen = new Set();
    const profiles = [];
    const consents = { psychotest: new Set(), dass: new Set() };
    let csrf = null;

    for (const element of Array.from(elements)) {
        const name = element.name;

        if (typeof name !== 'string' || name === '') {
            continue;
        }

        if (seen.has(name) || typeof element.value !== 'string') {
            return null;
        }

        seen.add(name);

        if (name === '_checkout_csrf') {
            if (
                element.type !== 'hidden' ||
                !CSRF_PATTERN.test(element.value)
            ) {
                return null;
            }

            csrf = element.value;
            continue;
        }

        const profileMatch = name.match(PROFILE_NAME_PATTERN);

        if (profileMatch) {
            const key = profileMatch[1];

            if (
                PROFILE_CONTROLS[key] !== element.type ||
                element.required !== true
            ) {
                return null;
            }

            profiles.push(key);
            continue;
        }

        const consentMatch = name.match(CONSENT_NAME_PATTERN);

        if (!consentMatch) {
            return null;
        }

        const [, type, field] = consentMatch;

        if (field === 'accepted') {
            if (
                element.type !== 'checkbox' ||
                element.required !== true ||
                (requireUnchecked && element.checked === true) ||
                element.value !== 'true'
            ) {
                return null;
            }
        } else if (
            element.type !== 'hidden' ||
            (field === 'documentVersion' &&
                (element.value.trim() === '' || element.value.length > 64)) ||
            (field === 'documentHash' && !HASH_PATTERN.test(element.value))
        ) {
            return null;
        }

        consents[type].add(field);
    }

    profiles.sort();

    if (!csrf || new Set(profiles).size !== profiles.length) {
        return null;
    }

    for (const type of CONSENT_TYPES) {
        if (consents[type].size !== 3) {
            return null;
        }
    }

    return { csrf, profileNames: profiles };
}

export function buildConfirmationPayload(entries, expectedProfileNames) {
    const values = new Map();

    for (const [name, value] of entries) {
        if (
            typeof name !== 'string' ||
            typeof value !== 'string' ||
            values.has(name)
        ) {
            throw new Error('Invalid confirmation form.');
        }

        values.set(name, value);
    }

    const expected = [...expectedProfileNames].sort();
    const profile = {};
    const suppliedProfiles = [];
    const consents = {};

    for (const [name, value] of values) {
        if (name === '_checkout_csrf') {
            if (!CSRF_PATTERN.test(value)) {
                throw new Error('Invalid confirmation form.');
            }

            continue;
        }

        const profileMatch = name.match(PROFILE_NAME_PATTERN);

        if (profileMatch) {
            const key = profileMatch[1];

            if (!(key in PROFILE_CONTROLS) || value.trim() === '') {
                throw new Error('Invalid confirmation form.');
            }

            suppliedProfiles.push(key);
            profile[key] = value;
            continue;
        }

        const consentMatch = name.match(CONSENT_NAME_PATTERN);

        if (!consentMatch) {
            throw new Error('Invalid confirmation form.');
        }

        const [, type, field] = consentMatch;
        consents[type] ??= {};
        consents[type][field] = field === 'accepted' ? value === 'true' : value;
    }

    suppliedProfiles.sort();

    if (
        !values.has('_checkout_csrf') ||
        suppliedProfiles.length !== expected.length ||
        suppliedProfiles.some((key, index) => key !== expected[index])
    ) {
        throw new Error('Invalid confirmation form.');
    }

    for (const type of CONSENT_TYPES) {
        const consent = consents[type];

        if (
            !consent ||
            consent.accepted !== true ||
            typeof consent.documentVersion !== 'string' ||
            consent.documentVersion.trim() === '' ||
            consent.documentVersion.length > 64 ||
            !HASH_PATTERN.test(consent.documentHash) ||
            Object.keys(consent).sort().join(',') !==
                'accepted,documentHash,documentVersion'
        ) {
            throw new Error('Invalid confirmation form.');
        }
    }

    return {
        profile,
        consents: { psychotest: consents.psychotest, dass: consents.dass },
    };
}

function resultForStatus(status) {
    switch (status) {
        case 200:
            return {
                kind: 'success',
                retryable: false,
                message:
                    'Konfirmasi tersimpan. Muat ulang halaman untuk melihat status terbaru.',
            };
        case 409:
            return {
                kind: 'conflict',
                retryable: false,
                message:
                    'Data checkout berubah. Muat ulang halaman sebelum mencoba kembali.',
            };
        case 419:
            return {
                kind: 'expired',
                retryable: false,
                message:
                    'Sesi checkout berakhir. Muat ulang halaman untuk melanjutkan.',
            };
        case 422:
            return {
                kind: 'validation',
                retryable: true,
                message: 'Periksa kembali data wajib dan kedua persetujuan.',
            };
        case 429:
            return {
                kind: 'throttled',
                retryable: true,
                message:
                    'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.',
            };
        default:
            return {
                kind: 'unexpected',
                retryable: true,
                message: 'Konfirmasi belum dapat disimpan. Coba lagi.',
            };
    }
}

export async function postConfirmation({
    action,
    origin,
    csrf,
    payload,
    fetchImpl = fetch,
}) {
    if (!safeAction(action, origin) || !CSRF_PATTERN.test(csrf)) {
        throw new Error('Invalid confirmation destination.');
    }

    try {
        const response = await fetchImpl(action, {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'manual',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Checkout-CSRF': csrf,
            },
            body: JSON.stringify(payload),
        });

        return resultForStatus(response.status);
    } catch {
        return {
            kind: 'network',
            retryable: true,
            message: 'Koneksi bermasalah. Periksa jaringan lalu coba lagi.',
        };
    }
}

function setStatus(status, result) {
    status.textContent = result.message;
    status.dataset.state = result.kind;

    if (result.kind !== 'success') {
        status.focus();
    }
}

export function enhanceCheckoutConfirmation(
    documentRoot = document,
    locationObject = window.location,
    fetchImpl = fetch,
    formDataFactory = (form) => new FormData(form),
) {
    const form = documentRoot.querySelector('form[data-checkout-confirmation]');

    if (!form) {
        return false;
    }

    const submit = form.querySelector('button[type="submit"]');
    const status = form.querySelector('[data-confirmation-status]');
    const initial = inspectConfirmationElements(form.elements);
    const action = form.getAttribute('action');

    if (
        !submit ||
        !status ||
        !initial ||
        !safeAction(action, locationObject.origin)
    ) {
        return false;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (form.dataset.pending === 'true' || !form.reportValidity()) {
            return;
        }

        const current = inspectConfirmationElements(form.elements, false);

        if (
            !current ||
            current.profileNames.join(',') !== initial.profileNames.join(',')
        ) {
            setStatus(status, {
                kind: 'unexpected',
                message:
                    'Formulir berubah. Muat ulang halaman sebelum melanjutkan.',
            });
            submit.disabled = true;

            return;
        }

        let payload;

        try {
            payload = buildConfirmationPayload(
                formDataFactory(form).entries(),
                current.profileNames,
            );
        } catch {
            setStatus(status, {
                kind: 'validation',
                message: 'Periksa kembali data wajib dan kedua persetujuan.',
            });

            return;
        }

        form.dataset.pending = 'true';
        form.setAttribute('aria-busy', 'true');
        submit.disabled = true;
        status.textContent = 'Menyimpan konfirmasi…';
        status.dataset.state = 'pending';
        const result = await postConfirmation({
            action,
            origin: locationObject.origin,
            csrf: current.csrf,
            payload,
            fetchImpl,
        });
        delete form.dataset.pending;
        form.removeAttribute('aria-busy');
        submit.disabled = !result.retryable;
        setStatus(status, result);
    });
    submit.disabled = false;
    submit.removeAttribute('aria-disabled');
    status.textContent =
        'Formulir siap dikirim setelah seluruh data wajib dilengkapi.';
    status.dataset.state = 'ready';

    return true;
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            () => enhanceCheckoutConfirmation(),
            { once: true },
        );
    } else {
        enhanceCheckoutConfirmation();
    }
}
