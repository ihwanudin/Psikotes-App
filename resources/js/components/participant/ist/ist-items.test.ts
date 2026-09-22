import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    istItemsOutcomeFromGeneric,
    istSubtestContentFromWire,
} from './ist-items.ts';

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

test('istSubtestContentFromWire maps ME during the memorize phase: word_list present, items empty', () => {
    const wordList = {
        BUNGA: ['mawar', 'melati', 'anggrek', 'kamboja', 'kenanga'],
        PERKAKAS: ['palu', 'gergaji', 'obeng', 'tang', 'kunci'],
        BURUNG: ['merpati', 'elang', 'gagak', 'pipit', 'kutilang'],
        KESENIAN: ['wayang', 'gamelan', 'batik', 'tari', 'lukis'],
        BINATANG: ['kucing', 'anjing', 'kuda', 'sapi', 'kambing'],
    };
    const content = istSubtestContentFromWire({
        code: 'ME',
        answer_type: 'multiple_choice',
        instructions: 'Hafalkan lima kata di tiap kategori.',
        items: [],
        word_list: wordList,
    });

    assert.equal(content.answerType, 'multiple_choice');
    assert.equal(content.items.length, 0);
    if (content.answerType !== 'multiple_choice') {
        return;
    }

    assert.deepEqual(content.wordList, wordList);
});

test('istSubtestContentFromWire maps ME during the answer phase: items present, word_list absent', () => {
    const content = istSubtestContentFromWire({
        code: 'ME',
        answer_type: 'multiple_choice',
        instructions: 'Hafalkan lima kata di tiap kategori.',
        items: [
            {
                item: 157,
                text: 'Kata pertama di kategori BUNGA adalah ...',
                options: { a: 'mawar', b: 'melati', c: 'anggrek', d: 'kamboja', e: 'kenanga' },
            },
        ],
    });

    assert.equal(content.answerType, 'multiple_choice');
    assert.equal(content.items.length, 1);
    if (content.answerType !== 'multiple_choice') {
        return;
    }

    assert.equal(content.wordList, undefined);
    assert.ok(!('wordList' in content), 'wordList must be entirely absent, not an undefined key');
});

test('istItemsOutcomeFromGeneric maps every subtest in an available outcome', () => {
    // GenericItemsContent's `subtests` type is deliberately narrow
    // (`{code, items}` only — see http-transport.ts's module doc: the
    // generic transport layer doesn't know per-instrument fields like
    // `answer_type`/`instructions`). The wire payload really does carry
    // them; this cast mirrors how istItemsOutcomeFromGeneric itself reads
    // them at runtime, not a type-safety gap in that function.
    const rawSubtests = [
        {
            code: 'SE',
            answer_type: 'multiple_choice',
            instructions: 'Pilih kata yang tepat.',
            items: [
                {
                    item: 1,
                    text: 'x',
                    options: { a: '1', b: '2', c: '3', d: '4', e: '5' },
                },
            ],
        },
        {
            code: 'GE',
            answer_type: 'fill_in_word',
            instructions: 'Temukan kata.',
            items: [{ item: 61, text: 'mawar - melati' }],
        },
    ];
    const outcome = istItemsOutcomeFromGeneric({
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'ist',
            version: 'v1',
            instructions: null,
            subtests: rawSubtests as unknown as {
                code: string;
                items: unknown[];
            }[],
        },
    });

    assert.equal(outcome.type, 'available');

    if (outcome.type !== 'available') {
        return;
    }

    assert.equal(outcome.subtests.length, 2);
    assert.equal(outcome.subtests[0]!.code, 'SE');
    assert.equal(outcome.subtests[0]!.answerType, 'multiple_choice');
    assert.equal(outcome.subtests[1]!.code, 'GE');
    assert.equal(outcome.subtests[1]!.answerType, 'fill_in_word');
});

for (const type of [
    'not_started',
    'closed',
    'deadline_exceeded',
    'not_found',
    'content_unavailable',
    'network_error',
] as const) {
    test(`istItemsOutcomeFromGeneric passes a non-available outcome (${type}) through as-is`, () => {
        assert.deepEqual(istItemsOutcomeFromGeneric({ type }), { type });
    });
}
