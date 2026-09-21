import { useEffect, useRef } from 'react';

import { cn } from '@/lib/utils';

import { backspace, enterDigit, focusSlot } from './column-input-state.ts';
import type { ColumnInputState } from './column-input-state.ts';

/**
 * Pure presentational component: renders one Kraepelin column's 28
 * numbers with a sum-answer slot between each adjacent pair (27 slots).
 * No fetch, no timer, no wiring to real grid/session data — all of that
 * is F2-dependent and out of scope for this prototype (Kraepelin runner
 * plan sent to Lead, 2026-09-21). The caller owns `state` and receives
 * updates via `onChange`, the standard controlled-component shape.
 *
 * `inputMode="none"` (not `"numeric"`) on each slot: this component
 * always ships alongside kraepelin-keypad.tsx's on-screen 0-9 pad, and
 * auto-advancing DOM focus onto a real `<input>` on mobile would
 * otherwise pop the OS's own virtual keyboard on top of that keypad and
 * half the column (Lead's 2026-09-21 review). `inputMode="none"` is the
 * documented way to keep a field genuinely focusable and editable —
 * physical-keyboard digit entry keeps working unchanged, verified by
 * exercising `onChange`/`onKeyDown` the same as before — while telling
 * mobile browsers specifically not to show a virtual keyboard for it,
 * which is exactly what a field with its own dedicated on-screen input
 * needs. `readOnly` was the other option Lead raised; not used, because
 * a read-only field blocks value changes from a physical keyboard too,
 * which would break desktop typing. Not yet empirically verified on a
 * real mobile browser's virtual keyboard — no such harness is available
 * yet (Lead's own note); this is the documented, standard behavior of
 * the attribute, not an assumption invented for this component.
 */

export type KraepelinColumnProps = {
    /** This column's numbers. Index 0 is the first number the
     * participant works from — SPEC.md §4.3's bottom-to-top rule means
     * that is the bottom-most printed number. Rendered in reverse order
     * so index 0 appears at the bottom of the screen, matching the
     * physical answer sheet. Must have exactly one more entry than
     * `state.values` (28 numbers, 27 sum slots between them). */
    numbers: number[];
    state: ColumnInputState;
    onChange: (next: ColumnInputState) => void;
    /** Disables all input — e.g. once the column's server-side time is
     * up and auto-advance to the next column is pending. */
    disabled?: boolean;
};

type ColumnItem =
    { kind: 'number'; value: number } | { kind: 'slot'; index: number };

export function KraepelinColumn({
    numbers,
    state,
    onChange,
    disabled = false,
}: KraepelinColumnProps) {
    const slotRefs = useRef<(HTMLInputElement | null)[]>([]);

    useEffect(() => {
        if (disabled) {
            return;
        }

        slotRefs.current[state.focusedIndex]?.focus();
    }, [state.focusedIndex, disabled]);

    const slotCount = state.values.length;

    const items: ColumnItem[] = [];

    for (let i = 0; i < numbers.length; i++) {
        items.push({ kind: 'number', value: numbers[i]! });

        if (i < slotCount) {
            items.push({ kind: 'slot', index: i });
        }
    }

    items.reverse();

    return (
        <div
            role="group"
            aria-label="Kolom Kraepelin"
            className="flex flex-col items-center gap-1"
        >
            {items.map((item, position) =>
                item.kind === 'number' ? (
                    <div
                        key={`number-${position}`}
                        className="flex h-8 w-10 items-center justify-center text-sm font-medium tabular-nums text-slate-700"
                    >
                        {item.value}
                    </div>
                ) : (
                    <input
                        key={`slot-${item.index}`}
                        ref={(el) => {
                            slotRefs.current[item.index] = el;
                        }}
                        type="text"
                        inputMode="none"
                        pattern="[0-9]"
                        maxLength={1}
                        disabled={disabled || state.locked[item.index]}
                        value={state.values[item.index] ?? ''}
                        aria-label={`Kotak jumlah ${item.index + 1} dari ${slotCount}${state.locked[item.index] ? ', terkunci' : ''}`}
                        className={cn(
                            'border-input shadow-xs h-8 w-10 rounded-md border text-center text-sm tabular-nums outline-none',
                            'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                            'disabled:cursor-not-allowed disabled:opacity-60',
                            state.focusedIndex === item.index &&
                                'ring-ring ring-1',
                        )}
                        onFocus={() => {
                            if (state.focusedIndex !== item.index) {
                                onChange(focusSlot(state, item.index));
                            }
                        }}
                        onChange={(event) => {
                            const digit = event.target.value.slice(-1);

                            if (digit === '' || !/^[0-9]$/.test(digit)) {
                                return;
                            }

                            onChange(
                                enterDigit(focusSlot(state, item.index), digit),
                            );
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'Backspace') {
                                event.preventDefault();
                                onChange(
                                    backspace(focusSlot(state, item.index)),
                                );
                            }
                        }}
                    />
                ),
            )}
        </div>
    );
}
