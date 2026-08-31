import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { IntegratedCheckout } from '../../../resources/js/components/integrated-checkout/integrated-checkout';
import type { IntegratedCheckoutProps } from '../../../resources/js/types/integrated-checkout';
import { scenarios, summary } from './fixtures';

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

test('consent starts unchecked and DASS is separate and optional', () => {
    const html = render();
    assert.doesNotMatch(html, /checked=""/);
    assert.match(html, /Saya tidak ingin mengikuti DASS-21/);
    assert.match(html, /tidak menentukan kelayakan kerja/);
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
