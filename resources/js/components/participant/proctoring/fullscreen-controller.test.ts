import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createFullscreenController } from './fullscreen-controller.ts';

test('unsupported starts and stays unsupported; request/exit are no-ops', async () => {
    let requestCount = 0;
    const controller = createFullscreenController({
        supported: false,
        requestFullscreen: async () => {
            requestCount++;
        },
        exitFullscreen: async () => {},
    });

    assert.equal(controller.getStatus(), 'unsupported');
    await controller.request();
    assert.equal(controller.getStatus(), 'unsupported');
    assert.equal(
        requestCount,
        0,
        'never calls the browser API when unsupported',
    );
});

test('supported starts inactive and request() enters fullscreen', async () => {
    let requestCount = 0;
    const controller = createFullscreenController({
        supported: true,
        requestFullscreen: async () => {
            requestCount++;
        },
        exitFullscreen: async () => {},
    });

    assert.equal(controller.getStatus(), 'inactive');
    await controller.request();
    assert.equal(controller.getStatus(), 'active');
    assert.equal(requestCount, 1);
});

test('request() while already active does not call the browser API again', async () => {
    let requestCount = 0;
    const controller = createFullscreenController({
        supported: true,
        requestFullscreen: async () => {
            requestCount++;
        },
        exitFullscreen: async () => {},
    });

    await controller.request();
    await controller.request();
    assert.equal(requestCount, 1);
});

test('a rejected request() stays inactive instead of throwing', async () => {
    const controller = createFullscreenController({
        supported: true,
        requestFullscreen: async () => {
            throw new DOMException('denied', 'NotAllowedError');
        },
        exitFullscreen: async () => {},
    });

    await assert.doesNotReject(() => controller.request());
    assert.equal(controller.getStatus(), 'inactive');
});

test('exit() while inactive is a no-op', async () => {
    let exitCount = 0;
    const controller = createFullscreenController({
        supported: true,
        requestFullscreen: async () => {},
        exitFullscreen: async () => {
            exitCount++;
        },
    });

    await controller.exit();
    assert.equal(exitCount, 0);
    assert.equal(controller.getStatus(), 'inactive');
});

test('exit() while active calls the browser API and returns to inactive', async () => {
    let exitCount = 0;
    const controller = createFullscreenController({
        supported: true,
        requestFullscreen: async () => {},
        exitFullscreen: async () => {
            exitCount++;
        },
    });

    await controller.request();
    await controller.exit();
    assert.equal(exitCount, 1);
    assert.equal(controller.getStatus(), 'inactive');
});

test('handleExternalExit moves active to inactive without calling exitFullscreen', async () => {
    let exitCount = 0;
    const controller = createFullscreenController({
        supported: true,
        requestFullscreen: async () => {},
        exitFullscreen: async () => {
            exitCount++;
        },
    });

    await controller.request();
    controller.handleExternalExit();

    assert.equal(controller.getStatus(), 'inactive');
    assert.equal(
        exitCount,
        0,
        'the browser already left fullscreen on its own',
    );
});

test('handleExternalExit is a no-op unless status is currently active', () => {
    const controller = createFullscreenController({
        supported: true,
        requestFullscreen: async () => {},
        exitFullscreen: async () => {},
    });

    controller.handleExternalExit(); // status is 'inactive'
    assert.equal(controller.getStatus(), 'inactive');

    const unsupported = createFullscreenController({
        supported: false,
        requestFullscreen: async () => {},
        exitFullscreen: async () => {},
    });

    unsupported.handleExternalExit();
    assert.equal(unsupported.getStatus(), 'unsupported');
});

test('subscribe receives every status transition and unsubscribe stops delivery', async () => {
    const controller = createFullscreenController({
        supported: true,
        requestFullscreen: async () => {},
        exitFullscreen: async () => {},
    });
    const seen: string[] = [];
    const unsubscribe = controller.subscribe((status) => seen.push(status));

    await controller.request();
    assert.deepEqual(seen, ['active']);

    unsubscribe();
    await controller.exit();
    assert.deepEqual(seen, ['active'], 'no more events after unsubscribe');
});
