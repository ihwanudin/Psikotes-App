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

function failure(kind) {
    return { ok: false, kind, message: FAILURE_MESSAGES[kind] };
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

function statusKind(status) {
    switch (status) {
        case 409:
            return 'conflict';
        case 419:
            return 'expired';
        case 422:
            return 'validation';
        case 429:
            return 'throttled';
        case 503:
            return 'unavailable';
        default:
            return 'unexpected';
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
        return failure(statusKind(response.status));
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
        return failure('network');
    }

    return parseCheckoutPaymentResponse(response);
}
