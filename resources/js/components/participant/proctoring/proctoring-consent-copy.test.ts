import assert from 'node:assert/strict';
import { test } from 'node:test';

import type { CameraStatus } from '../session-runner/camera-controller.ts';
import { getProctoringConsentCopy } from './proctoring-consent-copy.ts';

const ALL_CAMERA_STATUSES: CameraStatus[] = [
    'inactive',
    'requesting',
    'active',
    'denied',
    'unavailable',
    'interrupted',
    'reactivating',
    'reactivation_failed',
];

function allStrings(value: unknown): string[] {
    if (typeof value === 'string') {
        return [value];
    }

    if (Array.isArray(value)) {
        return value.flatMap(allStrings);
    }

    if (value && typeof value === 'object') {
        return Object.values(value).flatMap(allStrings);
    }

    return [];
}

test('every combination produces a title, policy paragraphs, honesty note, and a primary action', () => {
    for (const cameraStatus of ALL_CAMERA_STATUSES) {
        for (const fullscreenSupported of [true, false]) {
            for (const cameraMandatory of [true, false]) {
                for (const persistenceEnabled of [true, false]) {
                    const copy = getProctoringConsentCopy({
                        cameraStatus,
                        fullscreenSupported,
                        cameraMandatory,
                        persistenceEnabled,
                    });

                    assert.equal(copy.title.length > 0, true);
                    assert.equal(copy.policyParagraphs.length > 0, true);
                    assert.equal(copy.honestyNote.length > 0, true);
                    assert.equal(copy.dataHandlingNote.length > 0, true);
                    assert.equal(copy.primaryAction.label.length > 0, true);
                }
            }
        }
    }
});

test('persistenceEnabled: false never claims server-side storage anywhere in the copy', () => {
    for (const cameraStatus of ALL_CAMERA_STATUSES) {
        for (const fullscreenSupported of [true, false]) {
            for (const cameraMandatory of [true, false]) {
                const copy = getProctoringConsentCopy({
                    cameraStatus,
                    fullscreenSupported,
                    cameraMandatory,
                    persistenceEnabled: false,
                });
                const text = allStrings(copy).join('\n');

                assert.doesNotMatch(
                    text,
                    /disimpan sementara|maksimal 90 hari|ringkasan peristiwa yang bertahan/,
                    `persistenceEnabled:false leaked a retention claim for ${cameraStatus}`,
                );
                assert.match(
                    copy.dataHandlingNote,
                    /masih dalam pengembangan/,
                    'must say persistence is not built yet, not stay silent about it',
                );
            }
        }
    }
});

test('persistenceEnabled: true shows the 90-day retention disclosure', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: true,
        cameraMandatory: false,
        persistenceEnabled: true,
    });

    assert.match(copy.dataHandlingNote, /90 hari/);
});

test('denied and unavailable produce different headings — a broken camera is never told "you refused"', () => {
    const denied = getProctoringConsentCopy({
        cameraStatus: 'denied',
        fullscreenSupported: true,
        cameraMandatory: false,
        persistenceEnabled: false,
    });
    const unavailable = getProctoringConsentCopy({
        cameraStatus: 'unavailable',
        fullscreenSupported: true,
        cameraMandatory: false,
        persistenceEnabled: false,
    });

    assert.notEqual(denied.notice?.heading, unavailable.notice?.heading);
    // Neither heading may accuse the participant (product owner
    // decision, 2026-09-21: messages must not blame the participant) —
    // "denied" describes the browser permission state factually rather
    // than saying "you refused", and "unavailable" goes further with an
    // explicit reassurance.
    assert.doesNotMatch(denied.notice?.heading ?? '', /Anda menolak/);
    assert.doesNotMatch(unavailable.notice?.heading ?? '', /^Anda menolak/);
    assert.match(
        unavailable.notice?.heading ?? '',
        /bukan berarti Anda menolak/,
    );
});

test('inactive and active/other in-session statuses have no denial notice', () => {
    for (const cameraStatus of [
        'inactive',
        'requesting',
        'active',
        'interrupted',
        'reactivating',
        'reactivation_failed',
    ] as CameraStatus[]) {
        const copy = getProctoringConsentCopy({
            cameraStatus,
            fullscreenSupported: true,
            cameraMandatory: false,
            persistenceEnabled: false,
        });

        assert.equal(
            copy.notice,
            null,
            `unexpected notice for ${cameraStatus}`,
        );
    }
});

test('cameraMandatory: true removes the secondary action for every camera status', () => {
    for (const cameraStatus of ALL_CAMERA_STATUSES) {
        const copy = getProctoringConsentCopy({
            cameraStatus,
            fullscreenSupported: true,
            cameraMandatory: true,
            persistenceEnabled: false,
        });

        assert.equal(
            copy.secondaryAction,
            null,
            `mandatory branch must not offer to proceed without a camera (${cameraStatus})`,
        );
    }
});

test('cameraMandatory: false offers a "proceed without camera" secondary action', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'denied',
        fullscreenSupported: true,
        cameraMandatory: false,
        persistenceEnabled: false,
    });

    assert.equal(copy.secondaryAction?.label, 'Lanjutkan tanpa kamera');
});

test('a mandatory denied notice gives concrete, actionable steps to grant the permission', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'denied',
        fullscreenSupported: true,
        cameraMandatory: true,
        persistenceEnabled: false,
    });
    const body = copy.notice?.body ?? '';

    // Desktop: the address-bar permission icon pattern common to modern
    // browsers. Mobile: pointing at the browser's own app permission
    // settings, since exact menu paths vary too much by OS/browser
    // version to hardcode reliably.
    assert.match(body, /address bar/);
    assert.match(body, /izin kamera/i);
    assert.match(body, /muat ulang/);
    assert.match(body, /HP/);
});

test('a mandatory unavailable notice gives concrete device steps and points to the test organizer, never an invented alternative-supervision pathway', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'unavailable',
        fullscreenSupported: true,
        cameraMandatory: true,
        persistenceEnabled: false,
    });
    const body = copy.notice?.body ?? '';

    assert.match(body, /Tutup aplikasi lain/);
    assert.match(body, /perangkat lain/);
    assert.match(body, /penyelenggara tes/);
    // No operational pathway for this has ever been decided (product
    // owner correction, 2026-09-21) — the copy must not point a
    // participant at staff who wouldn't know what to do with them.
    assert.doesNotMatch(body, /LPK/);
});

test('a mandatory denial notice never mentions proceeding without a camera', () => {
    for (const cameraStatus of ['denied', 'unavailable'] as CameraStatus[]) {
        const copy = getProctoringConsentCopy({
            cameraStatus,
            fullscreenSupported: true,
            cameraMandatory: true,
            persistenceEnabled: false,
        });

        assert.doesNotMatch(copy.notice?.body ?? '', /tanpa kamera/);
    }
});

test('fullscreenSupported: false never promises fullscreen will activate', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: false,
        cameraMandatory: false,
        persistenceEnabled: false,
    });
    const text = copy.policyParagraphs.join('\n');

    assert.doesNotMatch(text, /Layar akan beralih ke mode penuh/);
    assert.match(text, /tidak mendukung mode layar penuh/);
});

test('fullscreenSupported: true mentions fullscreen as a per-subtest reminder, not a lock', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: true,
        cameraMandatory: false,
        persistenceEnabled: false,
    });
    const text = copy.policyParagraphs.join('\n');

    assert.match(text, /Layar akan beralih ke mode penuh/);
    assert.match(text, /tetap bisa keluar kapan saja/);
});

test('the honesty note always states detection, not prevention', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: true,
        cameraMandatory: false,
        persistenceEnabled: false,
    });

    assert.match(copy.honestyNote, /mendeteksi dan mencatat/);
    assert.match(copy.honestyNote, /bukan mencegah/);
});
