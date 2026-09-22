import { CircleAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';

/**
 * Pure presentational pre-submit summary: lists unanswered items as
 * jump links, and locks the submit button until none remain.
 *
 * This is a UI safeguard, not the authoritative gate — Lead's
 * 2026-09-21 decision on the PAPI runner plan: `AssessmentSessionSubmitPolicy`
 * (app/Domain/AssessmentSessions/AssessmentSessionSubmitPolicy.php) has
 * no answer-completeness check today, so nothing server-side currently
 * stops an incomplete submit; F2 is adding one. This component mirrors
 * that intended rule, it does not replace it — CLAUDE.md: the client
 * only reflects server state, it isn't the only thing enforcing it.
 */

export type PapiSummaryProps = {
    itemCount: number;
    /** 0-based indices of every still-unanswered item, ascending — from
     * papi-navigation-state.ts's `unansweredIndices()`. */
    unansweredIndices: number[];
    /** Navigate the item view to this 0-based index. */
    onJumpToItem: (index: number) => void;
    onSubmit: () => void;
    /** True while a submit is in flight — disables the button
     * separately from the completeness lock, so the two reasons a
     * participant can't currently submit stay distinguishable. */
    submitting?: boolean;
};

export function PapiSummary({
    itemCount,
    unansweredIndices,
    onJumpToItem,
    onSubmit,
    submitting = false,
}: PapiSummaryProps) {
    const answeredCount = itemCount - unansweredIndices.length;
    const complete = unansweredIndices.length === 0;

    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-slate-600">
                {answeredCount} dari {itemCount} butir terjawab.
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
                            {unansweredIndices.length} butir belum dijawab.
                            Semua butir harus dijawab sebelum mengirim.
                        </p>
                    </div>
                    <ul
                        className="flex flex-wrap gap-2"
                        aria-label="Butir belum terjawab"
                    >
                        {unansweredIndices.map((index) => (
                            <li key={index}>
                                <button
                                    type="button"
                                    onClick={() => onJumpToItem(index)}
                                    className="flex min-h-11 min-w-11 items-center justify-center rounded-full border border-amber-300 bg-white px-3 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100"
                                >
                                    Butir {index + 1}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <Button
                type="button"
                className="h-11"
                onClick={onSubmit}
                disabled={!complete || submitting}
            >
                {submitting ? 'Mengirim…' : 'Kirim jawaban'}
            </Button>
        </div>
    );
}
