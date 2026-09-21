import { Button } from '@/components/ui/button';

/**
 * Pure presentational forward/back navigation + position indicator for
 * the item view. Boundary clamping (can't go before item 1 or past the
 * last one) is papi-navigation-state.ts's job — this component only
 * disables the buttons to match, it doesn't re-decide the boundary.
 */

export type PapiItemNavProps = {
    itemNumber: number;
    itemCount: number;
    onPrevious: () => void;
    onNext: () => void;
    /** Navigate to the pre-submit summary screen. */
    onReviewAnswers: () => void;
};

export function PapiItemNav({
    itemNumber,
    itemCount,
    onPrevious,
    onNext,
    onReviewAnswers,
}: PapiItemNavProps) {
    return (
        <div className="flex flex-col items-center gap-3">
            {/* Prev/next on their own full-width row: at 320px, three
             * items (button, indicator, button) sharing one row don't
             * fit — measured, not assumed (this exact bug shipped once
             * before, in lobby.tsx, fixed in #58). Two buttons alone
             * always fit comfortably, so they get the row to themselves. */}
            <div className="flex w-full items-center justify-between gap-3">
                <Button
                    type="button"
                    variant="outline"
                    className="h-11"
                    onClick={onPrevious}
                    disabled={itemNumber <= 1}
                >
                    Sebelumnya
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="h-11"
                    onClick={onNext}
                    disabled={itemNumber >= itemCount}
                >
                    Berikutnya
                </Button>
            </div>
            <div className="flex flex-col items-center gap-1">
                <span className="text-sm font-medium text-slate-600">
                    Butir {itemNumber} dari {itemCount}
                </span>
                <button
                    type="button"
                    onClick={onReviewAnswers}
                    className="flex min-h-11 items-center px-1 text-xs text-teal-700 underline-offset-4 hover:underline"
                >
                    Lihat ringkasan
                </button>
            </div>
        </div>
    );
}
