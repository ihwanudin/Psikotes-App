import assert from 'node:assert/strict';
import { test } from 'node:test';

import { computeContainFitSize } from './capture-frame-sizing.ts';

test('a source already within bounds is returned unchanged (never upscaled)', () => {
    const result = computeContainFitSize(
        { width: 320, height: 240 },
        { maxWidth: 480, maxHeight: 360 },
    );

    assert.deepEqual(result, { width: 320, height: 240 });
});

test('a source matching the bounds exactly is returned unchanged', () => {
    const result = computeContainFitSize(
        { width: 480, height: 360 },
        { maxWidth: 480, maxHeight: 360 },
    );

    assert.deepEqual(result, { width: 480, height: 360 });
});

test('a 16:9 source wider than the bounds is shrunk to fit width, not stretched to 480x360', () => {
    // 1280x720 (16:9) into 480x360 (4:3): height is the binding
    // constraint (720/360 = 2 > 1280/480 = 2.667... wait, compute both).
    // width ratio = 480/1280 = 0.375, height ratio = 360/720 = 0.5.
    // The smaller ratio (0.375, from width) wins, so height comes out
    // to 720*0.375=270, not the full 360 — proving it is never
    // stretched/padded to fill the bounding box.
    const result = computeContainFitSize(
        { width: 1280, height: 720 },
        { maxWidth: 480, maxHeight: 360 },
    );

    assert.deepEqual(result, { width: 480, height: 270 });
});

test('a tall (portrait) source is shrunk to fit height, preserving its aspect ratio', () => {
    // 720x1280 (9:16, typical phone portrait) into 480x360: width ratio
    // = 480/720 = 0.667, height ratio = 360/1280 = 0.28125. The smaller
    // (height) wins. width = 720*0.28125 = 202.5, which Math.round
    // takes up to 203.
    const result = computeContainFitSize(
        { width: 720, height: 1280 },
        { maxWidth: 480, maxHeight: 360 },
    );

    assert.deepEqual(result, { width: 203, height: 360 });
});

test('a source larger than bounds in both dimensions equally (same aspect ratio) fits exactly', () => {
    const result = computeContainFitSize(
        { width: 960, height: 720 }, // same 4:3 ratio as 480x360, double size
        { maxWidth: 480, maxHeight: 360 },
    );

    assert.deepEqual(result, { width: 480, height: 360 });
});

test('output dimensions are never zero even for extreme aspect ratios', () => {
    const result = computeContainFitSize(
        { width: 10_000, height: 1 },
        { maxWidth: 480, maxHeight: 360 },
    );

    assert.ok(result.width > 0);
    assert.ok(result.height >= 1);
});

test('rejects non-positive source or bound dimensions', () => {
    assert.throws(() =>
        computeContainFitSize(
            { width: 0, height: 240 },
            { maxWidth: 480, maxHeight: 360 },
        ),
    );
    assert.throws(() =>
        computeContainFitSize(
            { width: 320, height: -1 },
            { maxWidth: 480, maxHeight: 360 },
        ),
    );
    assert.throws(() =>
        computeContainFitSize(
            { width: 320, height: 240 },
            { maxWidth: 0, maxHeight: 360 },
        ),
    );
    assert.throws(() =>
        computeContainFitSize(
            { width: 320, height: 240 },
            { maxWidth: 480, maxHeight: -10 },
        ),
    );
});

test('the injected bounds are actually used, not a hardcoded 480x360', () => {
    const result = computeContainFitSize(
        { width: 1000, height: 1000 },
        { maxWidth: 100, maxHeight: 200 },
    );

    assert.deepEqual(result, { width: 100, height: 100 });
});
