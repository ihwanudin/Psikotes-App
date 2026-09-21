import { cn } from '@/lib/utils';

import type { PapiChoice } from './papi-navigation-state.ts';

/**
 * Pure presentational component: one PAPI item, a forced choice between
 * two statements. `role="radiogroup"` containing two `role="radio"`
 * options is the correct semantic here — exactly one of two mutually
 * exclusive statements, the same shape as a two-option radio button,
 * not a generic pair of buttons.
 */

export type PapiItemProps = {
    itemNumber: number;
    itemCount: number;
    statementA: string;
    statementB: string;
    selected: PapiChoice | null;
    onSelect: (choice: PapiChoice) => void;
    disabled?: boolean;
};

export function PapiItem({
    itemNumber,
    itemCount,
    statementA,
    statementB,
    selected,
    onSelect,
    disabled = false,
}: PapiItemProps) {
    return (
        <div
            role="radiogroup"
            aria-label={`Butir ${itemNumber} dari ${itemCount}`}
            className="flex flex-col gap-3"
        >
            <PapiChoiceOption
                letter="a"
                statement={statementA}
                selected={selected === 'a'}
                onSelect={() => onSelect('a')}
                disabled={disabled}
            />
            <PapiChoiceOption
                letter="b"
                statement={statementB}
                selected={selected === 'b'}
                onSelect={() => onSelect('b')}
                disabled={disabled}
            />
        </div>
    );
}

function PapiChoiceOption({
    letter,
    statement,
    selected,
    onSelect,
    disabled,
}: {
    letter: PapiChoice;
    statement: string;
    selected: boolean;
    onSelect: () => void;
    disabled: boolean;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            disabled={disabled}
            onClick={onSelect}
            className={cn(
                'shadow-xs flex items-start gap-3 rounded-xl border p-4 text-left text-sm leading-6',
                'disabled:pointer-events-none disabled:opacity-50',
                selected
                    ? 'border-teal-700 bg-teal-50 text-teal-950'
                    : 'border-slate-200 bg-white text-slate-950 hover:border-slate-300',
            )}
        >
            <span
                className={cn(
                    'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold uppercase',
                    selected
                        ? 'border-teal-700 bg-teal-700 text-white'
                        : 'border-slate-300 text-slate-500',
                )}
                aria-hidden="true"
            >
                {letter}
            </span>
            <span>{statement}</span>
        </button>
    );
}
