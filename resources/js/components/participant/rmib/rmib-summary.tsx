import { CircleAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';

/**
 * Pure presentational pre-submit summary: lists unanswered (unconfirmed)
 * groups as jump links, and locks the submit button until none remain.
 * Mirrors `papi-summary.tsx`'s exact contract and reasoning.
 *
 * This is a UI safeguard, not the authoritative gate —
 * `AssessmentSessionSubmitPolicy` (app/Domain/AssessmentSessions/
 * AssessmentSessionSubmitPolicy.php) has no answer-completeness check
 * today, the same gap already known for PAPI and confirmed to apply here
 * too (RMIB runner plan sent to Lead, 2026-09-21). This component mirrors
 * the intended rule, it does not replace it.
 */

export type RmibSummaryProps = {
    groupCount: number;
    /** 1-based group numbers still unconfirmed, ascending. */
    unconfirmedGroupNumbers: number[];
    groupLetterFor: (groupNumber: number) => string;
    onJumpToGroup: (groupNumber: number) => void;
    onSubmit: () => void;
    submitting?: boolean;
};

export function RmibSummary({
    groupCount,
    unconfirmedGroupNumbers,
    groupLetterFor,
    onJumpToGroup,
    onSubmit,
    submitting = false,
}: RmibSummaryProps) {
    const confirmedCount = groupCount - unconfirmedGroupNumbers.length;
    const complete = unconfirmedGroupNumbers.length === 0;

    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-slate-600">
                {confirmedCount} dari {groupCount} kelompok terjawab.
            </p>

            {!complete && (
                <div
                    role="alert"
                    className="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4"
                >
                    <div className="flex items-start gap-2 text-amber-900">
                        <CircleAlert
                            className="mt-0.5 size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <p className="text-sm leading-6">
                            {unconfirmedGroupNumbers.length} kelompok belum
                            dijawab. Semua kelompok harus dijawab sebelum
                            mengirim.
                        </p>
                    </div>
                    <ul
                        className="flex flex-wrap gap-2"
                        aria-label="Kelompok belum terjawab"
                    >
                        {unconfirmedGroupNumbers.map((groupNumber) => (
                            <li key={groupNumber}>
                                <button
                                    type="button"
                                    onClick={() => onJumpToGroup(groupNumber)}
                                    className="rounded-full border border-amber-300 bg-white px-3 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100"
                                >
                                    Kelompok {groupLetterFor(groupNumber)}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <Button
                type="button"
                onClick={onSubmit}
                disabled={!complete || submitting}
            >
                {submitting ? 'Mengirim…' : 'Kirim jawaban'}
            </Button>
        </div>
    );
}
