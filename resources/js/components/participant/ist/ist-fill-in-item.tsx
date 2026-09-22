import type { IstFillInItem } from './ist-items.ts';

/**
 * Pure presentational fill-in item (GE word association, RA/ZR numeric)
 * — a single free-text input. `inputMode` is a presentational hint only
 * (numeric keypad on mobile for RA/ZR); the value sent for autosave stays
 * a plain string regardless, per `ist-subtest-navigation-state.ts`'s doc
 * — no client-side parsing/validation of what counts as "a number".
 */

export type IstFillInItemProps = {
    item: IstFillInItem;
    itemNumber: number;
    itemCount: number;
    answerType: 'fill_in_word' | 'fill_in_numeric';
    value: string;
    onChange: (value: string) => void;
};

export function IstFillInItemView({
    item,
    itemNumber,
    itemCount,
    answerType,
    value,
    onChange,
}: IstFillInItemProps) {
    const inputId = `ist-fill-in-${itemNumber}`;

    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-slate-500">
                Butir {itemNumber} dari {itemCount}
            </p>

            <p className="text-base leading-7 text-slate-900">{item.text}</p>

            <div className="flex flex-col gap-2">
                <label
                    htmlFor={inputId}
                    className="text-sm font-medium text-slate-700"
                >
                    Jawaban
                </label>
                <input
                    id={inputId}
                    type="text"
                    inputMode={
                        answerType === 'fill_in_numeric' ? 'numeric' : 'text'
                    }
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    className="rounded-xl border border-slate-300 px-4 py-3 text-base text-slate-900 focus:border-teal-600 focus:outline-none"
                />
            </div>
        </div>
    );
}
