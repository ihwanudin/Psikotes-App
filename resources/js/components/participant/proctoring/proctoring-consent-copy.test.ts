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
            for (const persistenceEnabled of [true, false]) {
                const copy = getProctoringConsentCopy({
                    cameraStatus,
                    fullscreenSupported,
                    persistenceEnabled,
                });

                assert.equal(copy.title.length > 0, true);
                assert.equal(copy.policyParagraphs.length > 0, true);
                assert.equal(copy.honestyNote.length > 0, true);
                assert.equal(copy.dataHandlingNote.length > 0, true);
                assert.equal(copy.dataRightsNote.length > 0, true);
                assert.equal(copy.primaryAction.label.length > 0, true);
            }
        }
    }
});

test('persistenceEnabled: false never claims server-side storage anywhere in the copy', () => {
    for (const cameraStatus of ALL_CAMERA_STATUSES) {
        for (const fullscreenSupported of [true, false]) {
            const copy = getProctoringConsentCopy({
                cameraStatus,
                fullscreenSupported,
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
});

test('persistenceEnabled: true shows the 90-day retention disclosure', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: true,
        persistenceEnabled: true,
    });

    assert.match(copy.dataHandlingNote, /90 hari/);
});

test('denied and unavailable produce different headings — a broken camera is never told "you refused"', () => {
    const denied = getProctoringConsentCopy({
        cameraStatus: 'denied',
        fullscreenSupported: true,
        persistenceEnabled: false,
    });
    const unavailable = getProctoringConsentCopy({
        cameraStatus: 'unavailable',
        fullscreenSupported: true,
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
            persistenceEnabled: false,
        });

        assert.equal(
            copy.notice,
            null,
            `unexpected notice for ${cameraStatus}`,
        );
    }
});

test('no camera status ever offers a way to proceed without an active camera', () => {
    // Camera is mandatory for every participant, no exception (product
    // owner decision, PR #81 item 11) — there is no `cameraMandatory`
    // parameter and no secondary "proceed anyway" action anywhere in
    // this module. The only way past this screen is camera.status
    // reaching 'active' (handled by the screen component itself, not by
    // any action this copy describes).
    for (const cameraStatus of ALL_CAMERA_STATUSES) {
        if (cameraStatus === 'active') {
            continue;
        }

        const copy = getProctoringConsentCopy({
            cameraStatus,
            fullscreenSupported: true,
            persistenceEnabled: false,
        });

        assert.equal(
            'secondaryAction' in copy,
            false,
            `unexpected secondaryAction field for ${cameraStatus} — no path around the camera may exist`,
        );
        assert.doesNotMatch(
            copy.primaryAction.label,
            /tanpa kamera/,
            `primary action for ${cameraStatus} must not offer to skip the camera`,
        );
    }
});

test('a denied notice gives concrete, actionable steps to grant the permission', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'denied',
        fullscreenSupported: true,
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

test('an unavailable notice gives concrete device steps and points to the test organizer, never an invented alternative-supervision pathway', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'unavailable',
        fullscreenSupported: true,
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

test('a denial notice never mentions proceeding without a camera', () => {
    for (const cameraStatus of ['denied', 'unavailable'] as CameraStatus[]) {
        const copy = getProctoringConsentCopy({
            cameraStatus,
            fullscreenSupported: true,
            persistenceEnabled: false,
        });

        assert.doesNotMatch(copy.notice?.body ?? '', /tanpa kamera/);
    }
});

test('fullscreenSupported: false never promises fullscreen will activate', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: false,
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
        persistenceEnabled: false,
    });

    assert.match(copy.honestyNote, /mendeteksi dan mencatat/);
    assert.match(copy.honestyNote, /bukan mencegah/);
});

// UU No. 27/2022 PDP consent checklist (owner decision item 16, PR #81)
// — each of these tests maps to one numbered item in
// proctoring-consent-copy.ts's own module doc comment.

test('PDP item 1: the purpose of monitoring is stated plainly', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: true,
        persistenceEnabled: false,
    });
    const text = copy.policyParagraphs.join('\n');

    assert.match(text, /Untuk menjaga keadilan bagi semua peserta/);
});

test('PDP item 2: every data type collected is named — periodic photos, face matching, screen-departure logging', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: true,
        persistenceEnabled: false,
    });
    const text = copy.policyParagraphs.join('\n');

    assert.match(text, /mengambil foto secara berkala/);
    assert.match(text, /dibandingkan.*foto identitas/);
    assert.match(text, /meninggalkan layar tes/);
});

test('PDP item 4: names the psychologist as who reviews this data, for every camera status', () => {
    for (const cameraStatus of ALL_CAMERA_STATUSES) {
        const copy = getProctoringConsentCopy({
            cameraStatus,
            fullscreenSupported: true,
            persistenceEnabled: false,
        });
        const text = copy.policyParagraphs.join('\n');

        assert.match(
            text,
            /psikolog yang menangani laporan Anda/,
            `missing processor note for ${cameraStatus}`,
        );
    }
});

test('PDP item 5: data-subject rights (access, correction, deletion) are stated, with a contact', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: true,
        persistenceEnabled: false,
    });

    assert.match(copy.dataRightsNote, /berhak mengetahui/);
    assert.match(copy.dataRightsNote, /mengoreksi identitas/);
    assert.match(copy.dataRightsNote, /meminta penghapusan/);
    assert.match(copy.dataRightsNote, /sesuai ketentuan yang berlaku/);
});

test('PDP item 5: the contact reference defaults to a generic, non-invented pointer, never a hardcoded email/phone', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: true,
        persistenceEnabled: false,
    });

    assert.match(copy.dataRightsNote, /hubungi penyelenggara tes Anda/);
    // No invented contact detail of any kind.
    assert.doesNotMatch(copy.dataRightsNote, /@/);
    assert.doesNotMatch(copy.dataRightsNote, /\d{3,}/);
});

test('PDP item 5: a caller-supplied contactReference replaces the default placeholder', () => {
    const copy = getProctoringConsentCopy({
        cameraStatus: 'inactive',
        fullscreenSupported: true,
        persistenceEnabled: false,
        contactReference: 'admin cabang Anda',
    });

    assert.match(copy.dataRightsNote, /hubungi admin cabang Anda/);
    assert.doesNotMatch(copy.dataRightsNote, /penyelenggara tes Anda/);
});

test('PDP item 5: the rights note is present regardless of persistenceEnabled — the right to ask does not depend on it', () => {
    for (const persistenceEnabled of [true, false]) {
        const copy = getProctoringConsentCopy({
            cameraStatus: 'inactive',
            fullscreenSupported: true,
            persistenceEnabled,
        });

        assert.ok(
            copy.dataRightsNote.length > 0,
            `missing rights note for persistenceEnabled:${persistenceEnabled}`,
        );
    }
});
