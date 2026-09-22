/**
 * TEST-ONLY oracle. Converts the RAW, sheet-order Kraepelin grid
 * (`database/seeders/data/kraepelin_grid.json`'s own `grid[row][col]`
 * shape, row 0 = the topmost row on the printed sheet) into the same
 * per-column numbers shape `kraepelin-column.tsx` expects, by reversing
 * each column once (SPEC.md §4.3's addition-proceeds-bottom-to-top rule
 * makes index 0 = the BOTTOM-most printed number, the opposite end from
 * `grid`'s row 0).
 *
 * **Do not call this on a `GET /sessions/:id/items` response.**
 * `KraepelinItemContentReader` (#67) already performs this exact
 * reversal server-side before the response ever reaches a client — see
 * `items.ts`'s module doc and `columnNumbersFromItems`, which is what
 * real `/items` data goes through instead (a straight position-order
 * copy, no reversal). Calling this function on already-reversed data
 * flips the column back to sheet order, silently pairing every
 * participant's answer with the wrong pair of numbers (Lead's
 * 2026-09-21 review). The only legitimate use of this module today is
 * as grid-column.test.ts's independent oracle for reading raw values
 * out of the committed `kraepelin_grid.json` fixture — it is
 * deliberately not imported by any other application file.
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
