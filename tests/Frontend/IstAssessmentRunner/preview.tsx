import { createRoot } from 'react-dom/client';

import type {
    AutosaveBatch,
    AutosaveSendOutcome,
} from '../../../resources/js/components/participant/session-runner/autosave-engine';
import type { ResumeAnswersOutcome } from '../../../resources/js/components/participant/session-runner/resume-answers';
import type { GenericItemsOutcome } from '../../../resources/js/components/participant/session-runner/http-transport';
import type { AssessmentSessionState } from '../../../resources/js/components/participant/session-runner/use-assessment-session';
import type { SubtestNextOutcome } from '../../../resources/js/components/participant/session-runner/subtest-next';
import { IstAssessmentRunner } from '../../../resources/js/components/participant/ist/ist-assessment-runner';
import './preview.css';

// Standalone fixture: exercises the real IstAssessmentRunner orchestration
// (sequencing via current_segment, the "Mulai mengerjakan" reading-gap
// confirmation, per-subtest timer display, the early-finish
// invalid_transition rejection path, and the ME-not-yet-supported message)
// with real component/hook logic underneath (useIstItems' real retry
// loader, IstSubtestScreen's real autosave/resume wiring, the real
// subtestNextSend outcome handling). Not a real fetch()/HTTP layer — a
// synthetic in-memory fake server, same convention IstSubtestScreen's own
// fixture already uses for send/fetchResumeAnswers (that component never
// owned a fetch() layer either). The HTTP wire-format parsing itself
// (snake_case -> camelCase, including current_segment and subtestNextSend)
// is separately covered by http-transport.test.ts, and PR #108's
// PapiRunner/RmibRunner fixtures already proved createHttpTransport()
// live against a fake endpoint for the other endpoints — this fixture's
// job is the NEW orchestration logic, not re-proving the transport.

type FakeSegment = {
    code: string;
    startedAt: number | null;
    durationMs: number;
    /** Infinity means "never auto-starts — must be explicitly confirmed",
     * modeling SE's real reading gap. 0 means "starts the instant it
     * becomes current", modeling every other subtest here so the demo
     * sequence moves along on its own once SE is confirmed. */
    readingCapMs: number;
};

const SEGMENTS: FakeSegment[] = [
    { code: 'SE', startedAt: null, durationMs: 3_000, readingCapMs: Infinity },
    { code: 'WA', startedAt: null, durationMs: 1_500, readingCapMs: 0 },
    { code: 'AN', startedAt: null, durationMs: 1_500, readingCapMs: 0 },
    { code: 'GE', startedAt: null, durationMs: 1_500, readingCapMs: 0 },
    { code: 'RA', startedAt: null, durationMs: 1_500, readingCapMs: 0 },
    { code: 'ZR', startedAt: null, durationMs: 1_500, readingCapMs: 0 },
    // Deliberately NOT in ITEM_SUBTESTS below — models
    // IstItemContentReader not building ME yet (see
    // ist-assessment-runner.tsx's module doc).
    {
        code: 'ME_MEMORIZE',
        startedAt: null,
        durationMs: 1_500,
        readingCapMs: 0,
    },
];

let currentIndex = 0;
let becameCurrentAt = Date.now();

function sweep(now: number): void {
    for (;;) {
        const segment = SEGMENTS[currentIndex]!;

        if (segment.startedAt === null) {
            if (
                segment.readingCapMs === Infinity ||
                now - becameCurrentAt < segment.readingCapMs
            ) {
                return;
            }

            segment.startedAt = becameCurrentAt;
            continue;
        }

        if (now - segment.startedAt < segment.durationMs) {
            return;
        }

        if (currentIndex + 1 >= SEGMENTS.length) {
            return;
        }

        becameCurrentAt = segment.startedAt + segment.durationMs;
        currentIndex += 1;
        SEGMENTS[currentIndex]!.startedAt = null;
    }
}

async function fetchSession(): Promise<AssessmentSessionState> {
    sweep(Date.now());
    const now = Date.now();
    const segment = SEGMENTS[currentIndex]!;
    const endsAt =
        segment.startedAt === null
            ? null
            : segment.startedAt + segment.durationMs;

    return {
        sessionId: 'ses_synthetic',
        testType: 'ist',
        status: 'in_progress',
        attemptNo: 1,
        startedAt: new Date(becameCurrentAt).toISOString(),
        endsAt: null,
        writeDeadline: null,
        submittedAt: null,
        serverTime: new Date(now).toISOString(),
        remainingSeconds: 999_999,
        answersRevision: 0,
        config: null,
        seed: null,
        currentSegment: {
            code: segment.code,
            index: currentIndex,
            startedAt:
                segment.startedAt === null
                    ? null
                    : new Date(segment.startedAt).toISOString(),
            endsAt: endsAt === null ? null : new Date(endsAt).toISOString(),
            remainingSeconds:
                endsAt === null
                    ? null
                    : Math.max(0, Math.ceil((endsAt - now) / 1000)),
        },
    };
}

async function subtestNextSend(): Promise<SubtestNextOutcome> {
    sweep(Date.now());
    const segment = SEGMENTS[currentIndex]!;

    if (segment.startedAt === null) {
        const now = Date.now();
        segment.startedAt = now;
        becameCurrentAt = now;

        return {
            type: 'accepted',
            segmentIndex: currentIndex,
            becameCurrentAt: new Date(now).toISOString(),
            startedAt: new Date(now).toISOString(),
        };
    }

    // Mid-window, allow_early_finish false (every real segment today) —
    // the EXPECTED rejection, not an error. See ist-assessment-runner.tsx's
    // module doc.
    return { type: 'invalid_transition' };
}

const ITEM_SUBTESTS: Record<
    string,
    { answer_type: string; instructions: string; items: unknown[] }
> = {
    SE: {
        answer_type: 'multiple_choice',
        instructions: 'Pilih kata yang paling tepat melengkapi kalimat.',
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
            {
                item: 2,
                text: 'Seekor kuda selalu mempunyai ...',
                options: {
                    a: 'kandang',
                    b: 'ladam',
                    c: 'pelana',
                    d: 'kuku',
                    e: 'surai',
                },
            },
        ],
    },
    WA: {
        answer_type: 'multiple_choice',
        instructions: 'Perhatikan gambar pada buku soal.',
        items: [
            {
                item: 21,
                options: { a: 'a', b: 'b', c: 'c', d: 'd', e: 'e' },
            },
        ],
    },
    AN: {
        answer_type: 'multiple_choice',
        instructions: 'Pilih dua kata yang sejenis.',
        items: [
            {
                item: 41,
                text: 'kucing, ...',
                options: {
                    a: 'meja',
                    b: 'anjing',
                    c: 'batu',
                    d: 'air',
                    e: 'awan',
                },
            },
        ],
    },
    GE: {
        answer_type: 'fill_in_word',
        instructions: 'Temukan satu kata yang menghubungkan kedua kata ini.',
        items: [{ item: 61, text: 'mawar - melati' }],
    },
    RA: {
        answer_type: 'fill_in_numeric',
        instructions: 'Lengkapi deret angka.',
        items: [{ item: 77, text: '2 4 6 8 ?' }],
    },
    ZR: {
        answer_type: 'fill_in_numeric',
        instructions: 'Lengkapi deret angka.',
        items: [{ item: 97, text: '6 9 12 15 18 21 24 ?' }],
    },
};

let itemsFetchAttempts = 0;

// The FIRST call fails with a real network_error, so the "Coba lagi" state
// for the whole-instrument items load can actually be seen and clicked,
// not just read from source (same convention as every other fixture here).
async function fetchItems(): Promise<GenericItemsOutcome> {
    itemsFetchAttempts++;

    if (itemsFetchAttempts === 1) {
        return { type: 'network_error' };
    }

    return {
        type: 'available',
        content: {
            sessionId: 'ses_synthetic',
            instrument: 'ist',
            version: 'v1',
            instructions: null,
            subtests: Object.entries(ITEM_SUBTESTS).map(([code, subtest]) => ({
                code,
                ...subtest,
            })) as unknown as { code: string; items: unknown[] }[],
        },
    };
}

async function fetchResumeAnswers(): Promise<ResumeAnswersOutcome> {
    return {
        type: 'available',
        sessionId: 'ses_synthetic',
        answersRevision: 0,
        answers: [],
    };
}

declare global {
    interface Window {
        __istRunnerAutosaveCalls: { item_no: number; value: unknown }[][];
    }
}

window.__istRunnerAutosaveCalls = [];

async function send(batch: AutosaveBatch): Promise<AutosaveSendOutcome> {
    window.__istRunnerAutosaveCalls.push(
        batch.items.map((item) => ({
            item_no: item.itemNo,
            value: item.value,
        })),
    );

    return {
        type: 'accepted',
        revision: batch.revision,
        receivedAt: new Date().toISOString(),
        acceptedItemNos: batch.items.map((item) => item.itemNo),
    };
}

function Fixture() {
    return (
        <IstAssessmentRunner
            fetchSession={fetchSession}
            fetchItems={fetchItems}
            fetchResumeAnswers={fetchResumeAnswers}
            send={send}
            subtestNextSend={subtestNextSend}
            pollIntervalMs={500}
        />
    );
}

const root = document.getElementById('root');

if (root === null) {
    throw new Error('#root not found');
}

createRoot(root).render(<Fixture />);
