import type { CameraStatus } from '../session-runner/camera-controller.ts';

/**
 * Every string the proctoring consent screen shows a participant, in one
 * file, deliberately separate from proctoring-consent-screen.tsx's JSX —
 * so this can be read and reviewed (by the product owner, and by the
 * psychologist per CLAUDE.md's document hierarchy for consent/scoring
 * wording) without reading component code. Wording matches
 * tasks/handoffs/f7/proctoring-consent-fullscreen-screen-plan-2026-09-21.md
 * §3, approved 2026-09-21 — do not diverge from that draft without the
 * same review.
 *
 * Two axes deliberately change the copy, both explained in the plan:
 *
 * - `fullscreenSupported`: SPEC.md 8A.1 forbids promising fullscreen on a
 *   platform that can't do it (iOS Safari has no standard Fullscreen API
 *   on ordinary page content). `false` swaps the fullscreen bullet for an
 *   honest "your device doesn't support this" line instead of omitting
 *   it silently.
 * - `persistenceEnabled`: CLAUDE.md forbids implying proctoring data is
 *   recorded/sent before a backend exists to receive it. While the
 *   caller passes `false` (true today — see proctoring-reporter.ts's
 *   noopProctoringReporter — no ingest endpoint exists at all, see
 *   tasks/handoffs/f7/proctoring-persistence-proposal.md), the retention
 *   sentence is replaced with an honest "this runs on your device;
 *   server storage isn't built yet" note instead of the 90-day
 *   disclosure SPEC.md 8A.7 requires once persistence is real.
 *
 * Camera is mandatory for every participant, no exception — product
 * owner decision, 2026-09-21 (Lead relay, tasks/handoffs/decisions, item
 * 11, PR #81), because the system persists randomized-interval snapshots
 * (SPEC.md 8A.2's 12-20 second capture model) rather than something
 * optional to opt out of. There is deliberately no `cameraMandatory`
 * parameter and no "proceed without a camera" action anywhere in this
 * module — an earlier version kept the parameter for testability with a
 * doc comment saying "no real caller may pass false", which Lead
 * flagged in review as a prohibition enforced only by a comment while
 * the code still shipped a working shortcut (CLAUDE.md forbids exactly
 * this). `denied` and `unavailable` both always block starting; their
 * notices differ (a browser permission prompt the participant
 * dismissed, versus hardware that genuinely isn't there or working) and
 * neither may blame the participant — see denialNotice() below.
 *
 * Deliberately NOT covered here: the camera going dead mid-session
 * (CameraStatus 'interrupted'/'reactivating'/'reactivation_failed').
 * Those only occur after the participant has already proceeded past
 * this screen, inside a running session — out of scope for this
 * component by design, and Lead asked that nothing be designed for that
 * case in this increment without asking first.
 *
 * UU No. 27/2022 PDP consent checklist (owner decision item 16, PR #81
 * `c0c772a`: the copy follows best practice, no word-by-word owner
 * review) — every element below must be present for this to count as
 * valid consent, cross-checked against this file's actual text:
 * 1. Purpose stated plainly — `policyParagraphs()`'s opening line.
 * 2. Data types named — periodic photos, face matching, screen-
 *    departure logging, each their own bullet in `policyParagraphs()`.
 * 3. Retention period (90 days, owner decision item 14) — only on the
 *    `persistenceEnabled: true` variant (`RETENTION_NOTE_ENABLED`);
 *    saying it on the `false` variant would be dishonest, since nothing
 *    is actually stored yet (see the `persistenceEnabled` note above).
 * 4. Who processes/views it — the psychologist handling the report
 *    (`PROCESSOR_NOTE` bullet in `policyParagraphs()`), not a claim
 *    that no one else ever does (that boundary isn't settled — see
 *    tasks/handoffs/f7/proctoring-persistence-proposal.md's RLS table).
 * 5. Data-subject rights + a way to exercise them — `dataRightsNote()`.
 *    `contactReference` is a configuration placeholder, never a
 *    hardcoded email/phone (none has been decided, and inventing one
 *    would be worse than a generic pointer) — see its own doc comment.
 * 6. Still true throughout: never blames the participant, and states
 *    detection/evidence rather than prevention (`HONESTY_NOTE`).
 */

export type ProctoringConsentCopyInput = {
    cameraStatus: CameraStatus;
    fullscreenSupported: boolean;
    persistenceEnabled: boolean;
    /** Where a participant is told to go to exercise their UU PDP
     * data-subject rights (access/correction/deletion) or ask
     * questions. Defaults to a generic, non-committal pointer
     * ("hubungi penyelenggara tes Anda") — this default is a
     * PLACEHOLDER, not a real contact channel, because no owner
     * decision has named one (CLAUDE.md: never invent an email/phone
     * number). Whoever wires this screen into a real page MUST pass a
     * real, configured value before go-live. */
    contactReference?: string;
};

export type ProctoringConsentNotice = {
    heading: string;
    body: string;
};

export type ProctoringConsentAction = {
    label: string;
};

export type ProctoringConsentCopy = {
    title: string;
    /** Always-shown policy paragraphs, in display order. */
    policyParagraphs: string[];
    /** The "detection, not prevention" paragraph (CLAUDE.md/SPEC.md 8A.1). */
    honestyNote: string;
    /** Retention disclosure (SPEC.md 8A.7) when `persistenceEnabled`, or
     * the honest not-yet-built substitute otherwise. Never null — there
     * is always something honest to say about where the data goes. */
    dataHandlingNote: string;
    /** UU PDP data-subject rights (access, correction, deletion) plus
     * how to exercise them. Always shown — the right to ask exists
     * regardless of whether server persistence is live yet. */
    dataRightsNote: string;
    /** Populated only when cameraStatus is 'denied' or 'unavailable'. */
    notice: ProctoringConsentNotice | null;
    primaryAction: ProctoringConsentAction;
};

const TITLE = 'Sebelum memulai: persetujuan pemantauan';

const HONESTY_NOTE =
    'Pemantauan ini mendeteksi dan mencatat — bukan mencegah. Batas teknis peramban web membuat kami tidak bisa benar-benar mengunci perangkat Anda. Keputusan akhir soal keabsahan hasil Anda selalu ada di tangan psikolog yang meninjau laporan, bukan sistem otomatis.';

const RETENTION_NOTE_ENABLED =
    'Foto dan catatan ini disimpan sementara (maksimal 90 hari); setelah itu hanya ringkasan peristiwa yang bertahan, tanpa gambar.';

const RETENTION_NOTE_DISABLED =
    'Sesi ini menggunakan kamera dan pencatatan kepergian layar secara langsung di perangkat Anda; penyimpanan otomatis ke server psikolog masih dalam pengembangan.';

const FULLSCREEN_BULLET_SUPPORTED =
    'Layar akan beralih ke mode penuh setiap subtes dimulai. Anda tetap bisa keluar kapan saja — ini pengingat, bukan kuncian.';

const FULLSCREEN_BULLET_UNSUPPORTED =
    'Perangkat/peramban Anda tidak mendukung mode layar penuh. Sesi tetap berjalan normal; kamera dan pencatatan kepergian layar tetap aktif seperti biasa.';

// UU PDP checklist item 4 (siapa memproses/melihat hasil): names the
// psychologist as processor without claiming exclusivity — branch
// admin/staff operational visibility into proctoring status is a real,
// separately-tracked open question (proctoring-persistence-proposal.md),
// not something this screen should overclaim either way.
const PROCESSOR_NOTE =
    'Data pemantauan ini menjadi bagian penilaian psikolog yang menangani laporan Anda.';

// UU PDP checklist item 5 (hak peserta + kontak). `contactReference` is
// a configuration placeholder (see ProctoringConsentCopyInput's doc
// comment) — never a hardcoded email/phone.
const DEFAULT_CONTACT_REFERENCE = 'penyelenggara tes Anda';

function policyParagraphs(fullscreenSupported: boolean): string[] {
    return [
        'Untuk menjaga keadilan bagi semua peserta, sesi ini dipantau selama berlangsung:',
        'Kamera Anda akan mengambil foto secara berkala (bukan merekam video terus-menerus), termasuk saat mulai dan saat mengirim jawaban.',
        'Wajah Anda dibandingkan sekali di awal dengan foto identitas Anda, dan sesekali selama sesi, untuk memastikan Anda peserta yang terdaftar.',
        'Sistem mencatat bila Anda meninggalkan layar tes (berpindah aplikasi/tab, mengunci layar, atau keluar dari mode layar penuh). Waktu tidak berhenti saat ini terjadi.',
        PROCESSOR_NOTE,
        fullscreenSupported
            ? FULLSCREEN_BULLET_SUPPORTED
            : FULLSCREEN_BULLET_UNSUPPORTED,
        'Selama mengerjakan soal, klik kanan, seleksi teks, salin, dan tempel dinonaktifkan pada halaman soal.',
    ];
}

function dataRightsNote(contactReference: string | undefined): string {
    const contact = contactReference ?? DEFAULT_CONTACT_REFERENCE;

    return `Anda berhak mengetahui, mengoreksi identitas Anda, dan meminta penghapusan data pemantauan yang tersimpan tentang Anda, sesuai ketentuan yang berlaku. Untuk menggunakan hak ini atau bertanya lebih lanjut, hubungi ${contact}.`;
}

function denialNotice(
    cameraStatus: 'denied' | 'unavailable',
): ProctoringConsentNotice {
    if (cameraStatus === 'denied') {
        return {
            // Neutral/factual, not an accusation — describes the browser
            // permission state, not a judgment about the participant
            // (product owner decision, 2026-09-21: messages must not
            // blame the participant, tasks/handoffs/decisions).
            heading:
                'Izin kamera untuk halaman ini belum aktif di peramban Anda.',
            body: 'Kamera wajib untuk semua peserta, jadi sesi belum bisa dimulai. Klik ikon kamera atau gembok di address bar peramban Anda dan izinkan akses kamera untuk halaman ini, lalu muat ulang. Di HP, buka pengaturan izin aplikasi peramban (Chrome/Safari) di perangkat Anda dan aktifkan izin kamera untuk peramban tersebut, lalu muat ulang halaman ini.',
        };
    }

    return {
        heading:
            'Kamera tidak terdeteksi di perangkat Anda saat ini. Ini bisa karena kamera sedang dipakai aplikasi lain, perangkat tidak punya kamera, atau kendala teknis lain — bukan berarti Anda menolak.',
        body: 'Kamera wajib untuk semua peserta. Tutup aplikasi lain yang mungkin memakai kamera Anda (panggilan video, aplikasi kamera lain), pastikan kamera perangkat terpasang dan berfungsi, lalu coba lagi. Jika kamera memang tidak berfungsi atau tidak tersedia di perangkat ini, gunakan perangkat lain yang punya kamera. Jika kamera tetap tidak dapat digunakan, hubungi penyelenggara tes Anda.',
    };
}

export function getProctoringConsentCopy(
    input: ProctoringConsentCopyInput,
): ProctoringConsentCopy {
    const {
        cameraStatus,
        fullscreenSupported,
        persistenceEnabled,
        contactReference,
    } = input;

    const shared = {
        title: TITLE,
        policyParagraphs: policyParagraphs(fullscreenSupported),
        honestyNote: HONESTY_NOTE,
        dataHandlingNote: persistenceEnabled
            ? RETENTION_NOTE_ENABLED
            : RETENTION_NOTE_DISABLED,
        dataRightsNote: dataRightsNote(contactReference),
    };

    switch (cameraStatus) {
        case 'denied':
        case 'unavailable':
            return {
                ...shared,
                notice: denialNotice(cameraStatus),
                primaryAction: { label: 'Coba lagi' },
            };

        case 'inactive':
            return {
                ...shared,
                notice: null,
                primaryAction: { label: 'Izinkan kamera & mulai' },
            };

        case 'requesting':
        case 'active':
        case 'interrupted':
        case 'reactivating':
        case 'reactivation_failed':
            // 'active' is handled by the screen component itself, which
            // proceeds automatically rather than rendering this copy
            // (see proctoring-consent-screen.tsx). The other statuses
            // here only occur after the participant has already
            // proceeded into a running session — this component should
            // never actually be mounted while they're current. Fall back
            // to the same neutral "in progress" copy as 'requesting'
            // rather than show something misleading if it somehow is.
            return {
                ...shared,
                notice: null,
                primaryAction: { label: 'Meminta akses kamera…' },
            };
    }
}
