import { Button } from '@/components/ui/button';

/**
 * Pure presentational forward/back navigation + position indicator for
 * one subtest's item view. Two-row layout (buttons row, then a position
 * row) — the same fix `papi-item-nav.tsx` needed for the identical
 * three-things-in-one-row 320px overflow risk.
 */

export type IstSubtestNavProps = {
    itemNumber: number;
    itemCount: number;
    onPrevious: () => void;
    onNext: () => void;
    onReviewAnswers: () => void;
};

export function IstSubtestNav({
    itemNumber,
    itemCount,
    onPrevious,
    onNext,
    onReviewAnswers,
}: IstSubtestNavProps) {
    return (
        <div className="flex flex-col gap-2 border-t border-slate-200 pt-4">
            <div className="flex justify-between gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="h-11"
                    disabled={itemNumber === 1}
                    onClick={onPrevious}
                >
                    Sebelumnya
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="h-11"
                    disabled={itemNumber === itemCount}
                    onClick={onNext}
                >
                    Berikutnya
                </Button>
            </div>
            <div className="flex items-center justify-between">
                <p className="text-sm text-slate-500">
                    Butir {itemNumber} dari {itemCount}
                </p>
                <button
                    type="button"
                    onClick={onReviewAnswers}
                    className="flex min-h-11 items-center px-1 text-sm text-teal-700 underline-offset-4 hover:underline"
                >
                    Lihat ringkasan
                </button>
            </div>
        </div>
    );
}
