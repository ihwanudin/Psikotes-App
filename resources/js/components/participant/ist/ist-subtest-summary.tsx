import { CircleAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';

/**
 * Pure presentational pre-completion summary for ONE subtest: lists
 * unanswered items as jump links, locks completion until none remain.
 * Mirrors `papi-summary.tsx`'s exact contract.
 *
 * Deliberately named `onComplete`, not `onSubmit` — there is no
 * session-level submit at this scope (Lead's 2026-09-21 review: this
 * component has no involvement in subtest transitions or the eventual
 * `POST /sessions/:id/subtest/next`). What the caller does with
 * "this subtest's items are all answered" is entirely up to it; this
 * component only knows the subtest it was given is complete.
 */

export type IstSubtestSummaryProps = {
    itemCount: number;
    unansweredIndices: number[];
    onJumpToItem: (index: number) => void;
    onComplete: () => void;
    completing?: boolean;
};

export function IstSubtestSummary({
    itemCount,
    unansweredIndices,
    onJumpToItem,
    onComplete,
    completing = false,
}: IstSubtestSummaryProps) {
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
                            Semua butir harus dijawab sebelum melanjutkan.
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
                onClick={onComplete}
                disabled={!complete || completing}
            >
                {completing ? 'Menyimpan…' : 'Selesai'}
            </Button>
        </div>
    );
}
