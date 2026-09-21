import { Button } from '@/components/ui/button';

import { RmibGroupList } from './rmib-group-list.tsx';
import type { RmibPosition } from './rmib-items.ts';

/**
 * One RMIB group's full screen: heading, the ranked list, the explicit
 * "Simpan urutan ini" confirmation action, and prev/next group
 * navigation — one group per screen, per the approved plan. Two-row nav
 * layout (buttons row, then a group-position indicator row) mirrors the
 * fix already applied to `papi-item-nav.tsx` for the same 320px overflow
 * risk three buttons/labels sharing one row ran into there.
 */

export type RmibGroupScreenProps = {
    positions: RmibPosition[];
    groupNumber: number;
    groupLetter: string;
    groupCount: number;
    order: number[];
    confirmed: boolean;
    onMoveUp: (position: number) => void;
    onMoveDown: (position: number) => void;
    onReorder: (fromIndex: number, toIndex: number) => void;
    onConfirmCurrentOrder: () => void;
    onPrevious: () => void;
    onNext: () => void;
    onReviewAnswers: () => void;
};

export function RmibGroupScreen({
    positions,
    groupNumber,
    groupLetter,
    groupCount,
    order,
    confirmed,
    onMoveUp,
    onMoveDown,
    onReorder,
    onConfirmCurrentOrder,
    onPrevious,
    onNext,
    onReviewAnswers,
}: RmibGroupScreenProps) {
    return (
        <div className="flex flex-col gap-4">
            <div>
                <h2 className="text-base font-semibold text-slate-900">
                    Kelompok {groupLetter}
                </h2>
                <p className="text-sm text-slate-600">
                    Urutkan 12 pekerjaan ini dari yang paling Anda sukai
                    (peringkat 1) sampai yang paling tidak Anda sukai (peringkat
                    12). Seret untuk mengurutkan, atau gunakan tombol naik/turun
                    pada setiap baris.
                </p>
            </div>

            <RmibGroupList
                positions={positions}
                order={order}
                onMoveUp={onMoveUp}
                onMoveDown={onMoveDown}
                onReorder={onReorder}
            />

            {!confirmed && (
                <Button
                    type="button"
                    variant="secondary"
                    onClick={onConfirmCurrentOrder}
                >
                    Simpan urutan ini
                </Button>
            )}
            {confirmed && (
                <p role="status" className="text-sm text-teal-700">
                    Urutan kelompok ini tersimpan.
                </p>
            )}

            <div className="flex flex-col gap-2 border-t border-slate-200 pt-4">
                <div className="flex justify-between gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="h-11"
                        disabled={groupNumber === 1}
                        onClick={onPrevious}
                    >
                        Sebelumnya
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-11"
                        disabled={groupNumber === groupCount}
                        onClick={onNext}
                    >
                        Berikutnya
                    </Button>
                </div>
                <div className="flex items-center justify-between">
                    <p className="text-sm text-slate-500">
                        Kelompok {groupNumber} dari {groupCount}
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
        </div>
    );
}
