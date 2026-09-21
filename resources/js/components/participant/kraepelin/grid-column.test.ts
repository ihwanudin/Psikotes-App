import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

import { columnNumbersFromGrid } from './grid-column.ts';
import type { KraepelinGrid } from './grid-column.ts';

// --- Synthetic fixture: core reversal/bounds logic, independent of the
// real (large) grid file. ---

const SYNTHETIC: KraepelinGrid = {
    grid: [
        [1, 2, 3], // row 0 — topmost on the sheet
        [4, 5, 6],
        [7, 8, 9], // last row — bottom-most on the sheet
    ],
};

test('column 0 is reversed so index 0 is the bottom-most (last-row) value', () => {
    assert.deepEqual(columnNumbersFromGrid(SYNTHETIC, 0), [7, 4, 1]);
});

test('column 2 is reversed the same way', () => {
    assert.deepEqual(columnNumbersFromGrid(SYNTHETIC, 2), [9, 6, 3]);
});

test('rejects a negative column index', () => {
    assert.throws(() => columnNumbersFromGrid(SYNTHETIC, -1), RangeError);
});

test('rejects a column index beyond the grid width', () => {
    assert.throws(() => columnNumbersFromGrid(SYNTHETIC, 3), RangeError);
});

test('rejects an empty grid', () => {
    assert.throws(() => columnNumbersFromGrid({ grid: [] }, 0), RangeError);
});

test('a single-row grid returns that one value unchanged', () => {
    const single: KraepelinGrid = { grid: [[42]] };

    assert.deepEqual(columnNumbersFromGrid(single, 0), [42]);
});

// --- Real fixture: verify against the actual, independently-verified
// grid data (database/seeders/data/kraepelin_grid.json, from #59) — not
// read by the app at runtime, only by this test, to prove the
// conversion is correct against real data before F2's /items endpoint
// exists to deliver it. ---

const GRID_FILE_PATH = fileURLToPath(
    new URL(
        '../../../../../database/seeders/data/kraepelin_grid.json',
        import.meta.url,
    ),
);
const EXPECTED_GRID_HASH =
    '6df51224c36bea665b9f8b0f8792139489f3c3204476089134657e3ae6ff9ee0';

type RealGridFile = KraepelinGrid & {
    numbers_per_column: number;
    answer_slots_per_column: number;
};

function loadRealGrid(): RealGridFile {
    return JSON.parse(readFileSync(GRID_FILE_PATH, 'utf-8'));
}

test('the real kraepelin_grid.json is the exact verified data (hash gate, recomputed here independently)', () => {
    const data = loadRealGrid();

    // json.dumps(grid, separators=(",", ":")) in Python (what
    // extract_kraepelin.py's hash gate uses) and JSON.stringify(grid) in
    // JS produce byte-identical output for a plain array-of-arrays of
    // integers — neither inserts whitespace by default.
    const recomputed = createHash('sha256')
        .update(JSON.stringify(data.grid))
        .digest('hex');

    assert.equal(
        recomputed,
        EXPECTED_GRID_HASH,
        'the committed kraepelin_grid.json must be the exact verified grid',
    );
});

test('the real grid is 50 columns x 28 rows, matching numbers_per_column/answer_slots_per_column', () => {
    const data = loadRealGrid();

    assert.equal(data.grid.length, 28);
    assert.ok(data.grid.every((row: number[]) => row.length === 50));
    assert.equal(data.numbers_per_column, 28);
    assert.equal(data.answer_slots_per_column, 27);
});

test('every one of the 50 real columns converts to 28 numbers, each 1-9', () => {
    const data = loadRealGrid();

    for (let column = 0; column < 50; column++) {
        const numbers = columnNumbersFromGrid(data, column);
        assert.equal(numbers.length, 28, `column ${column}`);
        assert.ok(
            numbers.every((n) => Number.isInteger(n) && n >= 1 && n <= 9),
            `column ${column} has an out-of-range value`,
        );
    }
});

test('the real column 0 conversion is exactly reversed row order', () => {
    const data = loadRealGrid();
    const numbers = columnNumbersFromGrid(data, 0);

    // Index 0 (bottom-most, first worked) must equal the LAST row's
    // value; the last index (top-most, last worked) must equal the
    // FIRST row's value — cross-checked directly against the raw grid,
    // not just re-deriving the same reversal a second time.
    assert.equal(numbers[0], data.grid[27][0]);
    assert.equal(numbers[27], data.grid[0][0]);
    assert.equal(numbers[14], data.grid[13][0]);
});
