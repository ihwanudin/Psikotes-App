/**
 * Converts a Kraepelin grid into the per-column numbers array
 * kraepelin-column.tsx expects — no React, no fetch, testable via
 * `node --test`.
 *
 * `KraepelinGrid` mirrors `database/seeders/data/kraepelin_grid.json`'s
 * own `grid` field shape (`grid[row][col]`, row 0 = the topmost row on
 * the printed sheet, per Lead's 2026-09-21 confirmation) — the most
 * natural pass-through shape for F2's still-unbuilt `/items` endpoint to
 * deliver, but that endpoint's exact wire contract is F2's call, not
 * decided here. This module exists so the frontend never reads
 * kraepelin_grid.json directly (that file is server-side seed data, not
 * something the browser fetches) — it only defines the conversion any
 * real transport layer will need once `/items` exists.
 *
 * kraepelin-column.tsx's `numbers` prop is documented as index 0 = the
 * first number the participant works from, which SPEC.md §4.3's
 * addition-proceeds-bottom-to-top rule makes the BOTTOM-most printed
 * number — the opposite end from `grid`'s row 0. `columnNumbersFromGrid`
 * does that reversal once, here, so kraepelin-column.tsx itself never
 * has to know which row index means "top" on the source grid.
 */

export type KraepelinGrid = {
    /** `grid[row][col]`; row 0 = the topmost row on the printed sheet. */
    grid: number[][];
};

export function columnNumbersFromGrid(
    source: KraepelinGrid,
    columnIndex: number,
): number[] {
    const rowCount = source.grid.length;

    if (rowCount === 0) {
        throw new RangeError('columnNumbersFromGrid: grid has no rows');
    }

    const numbers: number[] = [];

    for (let row = rowCount - 1; row >= 0; row--) {
        const rowValues = source.grid[row]!;

        if (columnIndex < 0 || columnIndex >= rowValues.length) {
            throw new RangeError(
                `columnNumbersFromGrid: columnIndex ${columnIndex} out of range for a ${rowValues.length}-column row`,
            );
        }

        numbers.push(rowValues[columnIndex]!);
    }

    return numbers;
}
