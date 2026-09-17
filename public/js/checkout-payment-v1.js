const PAYMENT_PATH = '/checkout/payment';
const CSRF_PATTERN = /^ocsrf1_[0-9a-f]{64}$/;

const FAILURE_MESSAGES = {
    conflict:
        'Status pembayaran berubah. Muat ulang halaman sebelum mencoba kembali.',
    expired: 'Sesi checkout berakhir. Muat ulang halaman untuk melanjutkan.',
    validation:
        'Pilihan konsultasi tidak dapat diproses. Muat ulang halaman dan coba lagi.',
    throttled: 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.',
    unavailable: 'Layanan pembayaran belum tersedia. Coba lagi nanti.',
    unexpected: 'Pembayaran belum dapat diproses. Coba lagi.',
    malformed:
        'Respons pembayaran tidak dapat digunakan. Muat ulang halaman dan coba lagi.',
    network: 'Koneksi pembayaran bermasalah. Periksa jaringan lalu coba lagi.',
};

function failure(kind, retryable = false) {
    return { ok: false, kind, retryable, message: FAILURE_MESSAGES[kind] };
}

function exactKeys(value, expected) {
    return (
        value !== null &&
        typeof value === 'object' &&
        !Array.isArray(value) &&
        Object.keys(value).sort().join(',') === [...expected].sort().join(',')
    );
}

function validPaymentUrl(value) {
    if (typeof value !== 'string') {
        return false;
    }

    try {
        const url = new URL(value);

        return (
            url.protocol === 'https:' &&
            url.username === '' &&
            url.password === ''
        );
    } catch {
        return false;
    }
}

function statusFailure(status) {
    switch (status) {
        case 409:
            return failure('conflict');
        case 419:
            return failure('expired');
        case 422:
            return failure('validation');
        case 429:
            return failure('throttled', true);
        case 503:
            return failure('unavailable', true);
        default:
            return failure(
                'unexpected',
                Number.isInteger(status) && status >= 500 && status <= 599,
            );
    }
}

export async function parseCheckoutPaymentResponse(response) {
    if (
        response === null ||
        typeof response !== 'object' ||
        response.redirected === true ||
        response.type === 'opaqueredirect'
    ) {
        return failure('malformed');
    }

    if (response.status !== 200) {
        return statusFailure(response.status);
    }

    let payload;

    try {
        payload = await response.json();
    } catch {
        return failure('malformed');
    }

    if (
        !exactKeys(payload, ['data']) ||
        !exactKeys(payload.data, ['paymentState', 'paymentUrl'])
    ) {
        return failure('malformed');
    }

    const { paymentState, paymentUrl } = payload.data;

    if (
        (paymentState === 'pending' && !validPaymentUrl(paymentUrl)) ||
        (paymentState === 'paid' && paymentUrl !== null) ||
        !['pending', 'paid'].includes(paymentState)
    ) {
        return failure('malformed');
    }

    return { ok: true, paymentState, paymentUrl };
}

export async function requestCheckoutPayment(consultationRequested, adapters) {
    if (typeof consultationRequested !== 'boolean') {
        throw new TypeError('Invalid checkout payment request.');
    }

    if (
        !exactKeys(adapters, ['csrf', 'fetchImpl']) ||
        !CSRF_PATTERN.test(adapters.csrf) ||
        typeof adapters.fetchImpl !== 'function'
    ) {
        throw new TypeError('Invalid checkout payment adapter.');
    }

    let response;

    try {
        response = await adapters.fetchImpl(PAYMENT_PATH, {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'manual',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Checkout-CSRF': adapters.csrf,
            },
            body: JSON.stringify({ consultationRequested }),
        });
    } catch {
        return failure('network', true);
    }

    return parseCheckoutPaymentResponse(response);
}

function inspectPaymentForm(form, requireUnselected = false) {
    if (
        !form ||
        form.getAttribute('action') !== PAYMENT_PATH ||
        !['select', 'continue'].includes(form.dataset.paymentMode)
    ) {
        return null;
    }

    const controls = Array.from(
        form.querySelectorAll('input[name="consultationRequested"]'),
    );

    if (
        controls.length === 0 ||
        controls.length > 2 ||
        (form.dataset.paymentMode === 'continue' && controls.length !== 1)
    ) {
        return null;
    }

    const values = new Set();

    for (const control of controls) {
        if (
            !['true', 'false'].includes(control.value) ||
            values.has(control.value) ||
            (form.dataset.paymentMode === 'select' &&
                (control.type !== 'radio' || control.required !== true)) ||
            (form.dataset.paymentMode === 'continue' &&
                control.type !== 'hidden') ||
            (requireUnselected && control.checked === true)
        ) {
            return null;
        }

        values.add(control.value);
    }

    return { controls, mode: form.dataset.paymentMode };
}

function selectedConsultation(inspected) {
    const selected =
        inspected.mode === 'continue'
            ? inspected.controls[0]
            : inspected.controls.find((control) => control.checked === true);

    return selected ? selected.value === 'true' : null;
}

function updateStatus(status, result) {
    status.textContent = result.message;
    status.dataset.state = result.kind;

    if (!result.ok) {
        status.focus();
    }
}

export function enhanceCheckoutPayment(
    documentRoot = document,
    locationObject = window.location,
    fetchImpl = fetch,
) {
    const form = documentRoot.querySelector('form[data-checkout-payment]');

    if (!form) {
        return false;
    }

    const submit = form.querySelector('button[type="submit"]');
    const status = form.querySelector('[data-payment-status]');
    const meta = documentRoot.querySelector('meta[name="checkout-csrf-token"]');
    const initial = inspectPaymentForm(form, true);
    const csrf = meta?.getAttribute('content');

    if (
        !submit ||
        !status ||
        !initial ||
        !CSRF_PATTERN.test(csrf ?? '') ||
        typeof locationObject.assign !== 'function'
    ) {
        return false;
    }

    const initialSignature = initial.controls.map((control) => ({
        control,
        value: control.value,
        type: control.type,
        required: control.required,
    }));

    const matchesInitialSignature = (current) =>
        current.mode === initial.mode &&
        current.controls.length === initialSignature.length &&
        current.controls.every((control, index) => {
            const expected = initialSignature[index];

            return (
                control === expected.control &&
                control.value === expected.value &&
                control.type === expected.type &&
                control.required === expected.required
            );
        });

    const setEnabledState = () => {
        const choice = selectedConsultation(initial);
        submit.disabled = choice === null;
        submit.setAttribute('aria-disabled', String(choice === null));
    };

    for (const control of initial.controls) {
        control.disabled = false;

        if (initial.mode === 'select') {
            control.addEventListener('change', setEnabledState);
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (form.dataset.pending === 'true') {
            return;
        }

        const current = inspectPaymentForm(form);
        const consultationRequested = current
            ? selectedConsultation(current)
            : null;

        if (
            !current ||
            !matchesInitialSignature(current) ||
            consultationRequested === null ||
            !form.reportValidity()
        ) {
            for (const control of initial.controls) {
                control.disabled = true;
            }

            submit.disabled = true;
            submit.setAttribute('aria-disabled', 'true');
            updateStatus(status, failure('malformed'));

            return;
        }

        form.dataset.pending = 'true';
        form.setAttribute('aria-busy', 'true');

        for (const control of current.controls) {
            control.disabled = true;
        }

        submit.disabled = true;
        submit.setAttribute('aria-disabled', 'true');
        status.textContent = 'Menyiapkan pembayaran…';
        status.dataset.state = 'pending';

        const result = await requestCheckoutPayment(consultationRequested, {
            csrf,
            fetchImpl,
        });

        delete form.dataset.pending;
        form.removeAttribute('aria-busy');

        if (result.ok) {
            updateStatus(status, {
                ok: true,
                kind: 'success',
                message:
                    result.paymentState === 'pending'
                        ? 'Pembayaran siap. Anda sedang diarahkan ke halaman pembayaran.'
                        : 'Pembayaran sudah tercatat. Ringkasan sedang dimuat ulang.',
            });
            locationObject.assign(
                result.paymentState === 'pending'
                    ? result.paymentUrl
                    : '/checkout',
            );

            return;
        }

        updateStatus(status, result);

        if (result.retryable) {
            for (const control of current.controls) {
                control.disabled = false;
            }

            setEnabledState();
        }
    });

    status.textContent =
        initial.mode === 'select'
            ? 'Pilih salah satu layanan untuk melanjutkan.'
            : 'Pilihan tersimpan siap dilanjutkan.';
    status.dataset.state = 'ready';
    setEnabledState();

    return true;
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            () => enhanceCheckoutPayment(),
            { once: true },
        );
    } else {
        enhanceCheckoutPayment();
    }
}
