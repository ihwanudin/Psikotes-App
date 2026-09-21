import type {
    IstMultipleChoiceItem,
    IstMultipleChoiceOptionKey,
} from './ist-items.ts';

/**
 * Pure presentational multiple-choice item (SE/WA/AN, five options
 * a-e) — no fetch, no autosave, just renders `item` and reports a
 * selection back up. `text` is optional (WA's items have none, per
 * `ist-items.ts`'s doc) — when absent, only the five options are shown,
 * matching the printed booklet the participant reads the stem from.
 */

export type IstMultipleChoiceItemProps = {
    item: IstMultipleChoiceItem;
    itemNumber: number;
    itemCount: number;
    selected: IstMultipleChoiceOptionKey | null;
    onSelect: (option: IstMultipleChoiceOptionKey) => void;
};

const OPTION_KEYS: IstMultipleChoiceOptionKey[] = ['a', 'b', 'c', 'd', 'e'];

export function IstMultipleChoiceItemView({
    item,
    itemNumber,
    itemCount,
    selected,
    onSelect,
}: IstMultipleChoiceItemProps) {
    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-slate-500">
                Butir {itemNumber} dari {itemCount}
            </p>

            {item.text !== undefined && (
                <p className="text-base leading-7 text-slate-900">
                    {item.text}
                </p>
            )}

            <div
                role="radiogroup"
                aria-label={`Butir ${itemNumber}`}
                className="flex flex-col gap-2"
            >
                {OPTION_KEYS.map((key) => (
                    <button
                        key={key}
                        type="button"
                        role="radio"
                        aria-checked={selected === key}
                        onClick={() => onSelect(key)}
                        className={`flex items-center gap-3 rounded-xl border p-3 text-left text-sm transition-colors ${
                            selected === key
                                ? 'border-teal-600 bg-teal-50 text-teal-900'
                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                        }`}
                    >
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-full border border-current text-xs font-semibold uppercase">
                            {key}
                        </span>
                        <span>{item.options[key]}</span>
                    </button>
                ))}
            </div>
        </div>
    );
}
