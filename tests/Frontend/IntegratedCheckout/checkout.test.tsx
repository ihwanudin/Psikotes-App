import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { IntegratedCheckout } from '../../../resources/js/components/integrated-checkout/integrated-checkout';
import type { IntegratedCheckoutProps } from '../../../resources/js/types/integrated-checkout';
import { optionalEmailSummary, scenarios, summary } from './fixtures';

function render(props: Partial<IntegratedCheckoutProps> = {}) {
    return renderToStaticMarkup(
        <IntegratedCheckout screen={{ state: 'ready', summary }} {...props} />,
    );
}

test('complete identity is summarized with no editable profile fields or branch controls', () => {
    const html = render();
    assert.match(html, /Nadia Peserta Contoh/);
    assert.match(html, /tidak perlu mendaftar ulang/);
    assert.match(html, /LPK Contoh — Cabang Bandung/);
    assert.doesNotMatch(
        html,
        /name="(?:fullName|birthDate|gender|educationLevel|intendedField|email|phone|branchId)"/,
    );
});

test('only the missing phone is editable', () => {
    const html = render({ screen: scenarios['Mandiri · profil kurang'] });
    assert.match(html, /name="phone"/);
    assert.doesNotMatch(html, /name="(?:fullName|email|branchId)"/);
});

test('fully missing profile preserves seven blank keys with six required and optional email', () => {
    const html = render({ screen: scenarios['Profil seluruhnya missing'] });

    for (const key of [
        'fullName',
        'birthDate',
        'gender',
        'educationLevel',
        'intendedField',
        'email',
        'phone',
    ]) {
        const input = html.match(
            new RegExp(`<(?:input|select)\\b[^>]*name="${key}"[^>]*>`),
        )?.[0];
        assert.ok(input, key);
        assert.equal(input.includes('required=""'), key !== 'email', key);

        if (input.startsWith('<input')) {
            assert.match(input, /value=""/);
        }
    }

    assert.match(html, /type="date"/);
    assert.doesNotMatch(
        html,
        /<option value="(?:female|male|KAIGO|KENSETSU|NOUGYOU|SEIZOU|GAISHOKU|UMUM)" selected/,
    );
    assert.doesNotMatch(html, /Nadia|nadia@example|12 Februari/);
    assert.match(html, /Lengkapi hanya data yang belum tersedia/);
    assert.match(html, /Akses tes belum dibuka/);
});

test('empty optional email keeps its input but needs no confirmation', () => {
    const html = render({
        screen: { state: 'ready', summary: optionalEmailSummary },
        onConfirm: () => undefined,
    });
    assert.match(html, /Data wajib sudah lengkap/);
    assert.match(html, /Data opsional/);
    assert.match(html, /name="email"/);
    assert.doesNotMatch(
        html.match(/<input[^>]*name="email"[^>]*>/)?.[0] ?? '',
        /required/,
    );
    assert.doesNotMatch(html, /type="submit"|Lengkapi hanya data/);
});

test('required missing still requests completion with optional email visible', () => {
    const html = render({
        screen: scenarios['Phone wajib + email opsional'],
        onConfirm: () => undefined,
    });
    assert.match(html, /Lengkapi hanya data yang belum tersedia/);
    assert.match(html, /name="phone"/);
    assert.match(html, /name="email"/);
    assert.match(html, /type="submit"/);
});

test('optional email does not hide required consent confirmation', () => {
    const html = render({
        screen: scenarios['Email opsional · consent belum'],
        onConfirm: () => undefined,
    });
    assert.match(html, /Data wajib sudah lengkap/);
    assert.match(html, /type="submit"/);
    assert.match(html, /persetujuan psikotes utama/);
});

test('DASS acceptance is separate without package-choice narration', () => {
    const html = render();
    const consent = html.match(
        /<section aria-labelledby="checkout-consent-title"[\s\S]*?<\/section>/,
    )?.[0];
    assert.ok(consent);
    assert.doesNotMatch(html, /checked=""/);
    assert.match(consent, /<h3[^>]*>DASS-21<\/h3>/);
    assert.match(
        consent,
        /Saya telah membaca dan menyetujui persetujuan DASS-21/,
    );
    const dassInput = consent.match(
        /<input[^>]*name="checkout-dass"[^>]*>/,
    )?.[0];
    assert.ok(dassInput);
    assert.match(dassInput, /type="checkbox"/);
    assert.match(dassInput, /required=""/);
    assert.doesNotMatch(
        consent,
        /type="radio"|opsional|menolak|tidak ingin|tidak setuju|bagian wajib|wajib terpisah/i,
    );
});

test('legacy nonaccepted DASS props stay unavailable without package-choice narration', () => {
    for (const dass of [
        { state: 'declined' as const, version: 'legacy-v1' },
        { state: 'not_applicable' as const },
    ]) {
        const html = render({
            screen: {
                state: 'ready',
                summary: {
                    ...summary,
                    consents: { ...summary.consents, dass },
                },
            },
        });
        const consent = html.match(
            /<section aria-labelledby="checkout-consent-title"[\s\S]*?<\/section>/,
        )?.[0];
        assert.ok(consent);
        assert.match(consent, /<h3[^>]*>DASS-21<\/h3>/);
        assert.match(consent, /Persetujuan DASS-21 belum tersedia/);
        assert.doesNotMatch(
            consent,
            /opsional|menolak|tidak ingin|tidak setuju|bagian wajib|wajib terpisah/i,
        );
    }
});

test('organization ignores an injected payment callback and never renders extra batch props', () => {
    const extended = {
        ...summary,
        invoiceUrl: 'https://invoice.example.test/private',
        batchTotal: 99999999,
        batchMembers: ['Other Secret Participant'],
    };
    const html = render({
        screen: { state: 'ready', summary: extended },
        onPayment: () => undefined,
    });
    assert.match(html, /Menunggu pembayaran lembaga/);
    assert.doesNotMatch(
        html,
        /Pilih metode pembayaran|Lanjutkan pembayaran yang sama|invoice.example|99999999|Other Secret Participant/,
    );
});

for (const amountIdr of [null, 0, summary.payment.amountIdr]) {
    test(`unselected payer at amount ${amountIdr} is readonly and never implies free/paid/access`, () => {
        const extended = {
            ...summary,
            payment: {
                payer: 'unselected' as const,
                state: 'unselected' as const,
                amountIdr,
                actionAvailable: true,
                organizationName: 'Unselected Secret Organization',
                invoiceUrl: 'https://invoice.example.test/private',
                batchTotal: 99999999,
                batchMembers: ['Other Secret Participant'],
            },
        };
        const html = render({
            screen: { state: 'ready', summary: extended },
            onPayment: () => assert.fail('Rendering must never call payment'),
        });
        const payment = html.match(
            /<section aria-labelledby="checkout-payment-title"[\s\S]*?<\/section>/,
        )?.[0];
        assert.ok(payment);
        assert.match(payment, /Pembayar belum dipilih/);
        assert.doesNotMatch(
            payment,
            /<button|<a\b|<input|<select|Bayar sendiri|Dibayar lembaga|Gratis|sudah lunas|undefined|invoice.example|99999999|Other Secret|Unselected Secret/,
        );
        assert.match(html, /Akses tes belum dibuka/);
        assert.doesNotMatch(html, /Prasyarat dikonfirmasi server|Mulai tes/);

        if (amountIdr === null) {
            assert.match(payment, /Belum tersedia/);
        }
    });
}

test('payment presentation follows each supplied payer/status without retaining an unselected fallback', () => {
    for (const [scenario, expected] of [
        ['Pembayar belum dipilih', 'Pembayar belum dipilih'],
        ['Mandiri · pending', 'Lanjutkan pembayaran yang sama'],
        ['Lembaga · menunggu', 'Menunggu pembayaran lembaga'],
        ['Gratis · consent belum', 'Gratis · dikonfirmasi server'],
        ['Pembayar belum dipilih · nol', 'Pembayar belum dipilih'],
    ]) {
        const html = render({
            screen: scenarios[scenario],
            onPayment: () => undefined,
        });
        assert.ok(html.includes(expected), scenario);
        assert.match(html, /Akses tes belum dibuka/);
        assert.doesNotMatch(html, /Prasyarat dikonfirmasi server|Mulai tes/);
    }
});

for (const scenario of [
    'Mandiri · lunas',
    'Gratis · consent belum',
    'Tagihan · kedaluwarsa',
    'Tagihan · ditolak',
    'Tagihan · ditinjau',
]) {
    test(`${scenario} offers no new payment or test-start action`, () => {
        const html = render({
            screen: scenarios[scenario],
            onPayment: () => undefined,
        });
        assert.doesNotMatch(
            html,
            /Pilih metode pembayaran|Lanjutkan pembayaran yang sama|Mulai tes/,
        );
    });
}

test('self pending offers only continuation of the same payment', () => {
    const html = render({
        screen: scenarios['Mandiri · pending'],
        onPayment: () => undefined,
    });
    assert.match(html, /Lanjutkan pembayaran yang sama/);
    assert.doesNotMatch(html, /Pilih metode pembayaran/);
});

test('no handler is not presented as a working payment', () => {
    const html = render({ screen: scenarios['Mandiri · pending'] });
    assert.match(html, /Pembayaran belum terhubung/);
    assert.match(html, /disabled=""/);
});

test('amount zero does not infer free or ready', () => {
    const html = render({
        screen: {
            state: 'ready',
            summary: {
                ...summary,
                payment: {
                    payer: 'self',
                    state: 'unpaid',
                    amountIdr: 0,
                    actionAvailable: false,
                },
            },
        },
    });
    assert.match(html, /Belum dibayar/);
    assert.match(html, /Akses tes belum dibuka/);
    assert.doesNotMatch(html, /Gratis · dikonfirmasi server/);
});

for (const state of ['loading', 'expired', 'error'] as const) {
    test(`${state} removes private summary and form`, () => {
        const html = render({
            screen:
                state === 'error' ? { state, message: 'Coba lagi' } : { state },
        });
        assert.doesNotMatch(html, /Nadia|nadia@example|<form|type="checkbox"/);
    });
}

test('recorded consent is text, never a prechecked input', () => {
    const html = render({ screen: scenarios['Gratis · server siap'] });
    assert.match(html, /sudah tercatat/);
    assert.doesNotMatch(html, /type="checkbox"|type="radio"|Mulai tes/);
});

test('busy disables the form and payment action', () => {
    const html = render({
        screen: scenarios['Mandiri · pending'],
        busy: true,
        onPayment: () => undefined,
    });
    assert.match(html, /aria-busy="true"/);
    assert.match(html, /<fieldset[^>]*disabled=""/);
    assert.match(html, /Menyimpan/);
});

for (const legalReviewPending of [true, false]) {
    test(`recorded psychotest consent still requires explicit DASS acceptance when legal review pending is ${legalReviewPending}`, () => {
        const html = render({
            screen: {
                state: 'ready',
                summary: {
                    ...summary,
                    consents: {
                        ...summary.consents,
                        legalReviewPending,
                        psychotest: {
                            state: 'accepted',
                            version: 'synthetic-v1',
                        },
                    },
                },
            },
            onConfirm: () => assert.fail('SSR must not confirm consent'),
        });
        const button = html.match(
            /<button\b[^>]*>Konfirmasi data dan persetujuan<\/button>/,
        )?.[0];
        assert.ok(
            button,
            'DASS consent remains available for explicit confirmation',
        );
        assert.equal(button.includes('disabled=""'), true);
    });
}
