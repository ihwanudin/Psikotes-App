import assert from 'node:assert/strict';
import { test } from 'node:test';

import { istSubtestContentFromWire } from './ist-items.ts';

test('istSubtestContentFromWire maps a multiple_choice subtest', () => {
    const content = istSubtestContentFromWire({
        code: 'SE',
        answer_type: 'multiple_choice',
        instructions: 'Pilih kata yang tepat.',
        items: [
            {
                item: 1,
                text: 'Lawannya "hemat" ialah ...',
                options: {
                    a: 'murah',
                    b: 'kikir',
                    c: 'boros',
                    d: 'bernilai',
                    e: 'kaya',
                },
            },
        ],
    });

    assert.equal(content.answerType, 'multiple_choice');
    assert.equal(content.code, 'SE');
    assert.equal(content.instructions, 'Pilih kata yang tepat.');
    assert.equal(content.items.length, 1);
    assert.equal(content.items[0]!.item, 1);
});

test('istSubtestContentFromWire maps a multiple_choice item with no stem text (WA)', () => {
    const content = istSubtestContentFromWire({
        code: 'WA',
        answer_type: 'multiple_choice',
        instructions: 'Perhatikan gambar pada buku soal.',
        items: [
            {
                item: 21,
                options: {
                    a: 'lingkungan',
                    b: 'panah',
                    c: 'elips',
                    d: 'busur',
                    e: 'lengkungan',
                },
            },
        ],
    });

    assert.equal(content.answerType, 'multiple_choice');
    assert.ok(!('text' in content.items[0]!));
});

test('istSubtestContentFromWire maps a fill_in_word subtest (GE)', () => {
    const content = istSubtestContentFromWire({
        code: 'GE',
        answer_type: 'fill_in_word',
        instructions: 'Temukan kata yang menghubungkan.',
        items: [{ item: 61, text: 'mawar - melati' }],
    });

    assert.equal(content.answerType, 'fill_in_word');
    assert.equal(content.items[0]!.text, 'mawar - melati');
});

test('istSubtestContentFromWire maps a fill_in_numeric subtest (RA/ZR)', () => {
    const content = istSubtestContentFromWire({
        code: 'ZR',
        answer_type: 'fill_in_numeric',
        instructions: 'Lengkapi deret angka.',
        items: [{ item: 97, text: '6 9 12 15 18 21 24 ?' }],
    });

    assert.equal(content.answerType, 'fill_in_numeric');
});

test('istSubtestContentFromWire rejects an unsupported answer_type rather than guessing', () => {
    assert.throws(
        () =>
            istSubtestContentFromWire({
                code: 'FA',
                answer_type: 'image_choice',
                instructions: 'x',
                items: [],
            }),
        RangeError,
    );
});
