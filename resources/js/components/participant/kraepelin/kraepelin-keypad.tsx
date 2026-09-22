import { cn } from '@/lib/utils';

import { backspace, enterDigit } from './column-input-state.ts';
import type { ColumnInputState } from './column-input-state.ts';

/**
 * Pure presentational on-screen numeric keypad for Kraepelin's answer
 * slots — mobile-first, so input doesn't depend on the OS keyboard
 * (`inputMode="numeric"` on kraepelin-column.tsx's slots is a fallback
 * for keyboard/assistive input, not the primary path). Always acts on
 * whichever slot `state.focusedIndex` currently points to; has no idea
 * which slot that is beyond what `state` tells it.
 *
 * A custom component rather than the shadcn `input-otp` wrapper already
 * in this repo (resources/js/components/ui/input-otp.tsx): that library
 * models one continuous code auto-advancing through a flat row of
 * slots, which doesn't fit 27 slots interleaved with printed numbers in
 * a vertical, bottom-to-top layout — trying to bend it to that shape
 * would fight the library rather than use it (Lead's 2026-09-21
 * guidance: prefer a simple custom component over forcing a mismatched
 * one).
 */

export type KraepelinKeypadProps = {
    state: ColumnInputState;
    onChange: (next: ColumnInputState) => void;
    disabled?: boolean;
};

const DIGITS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];

export function KraepelinKeypad({
    state,
    onChange,
    disabled = false,
}: KraepelinKeypadProps) {
    return (
        <div
            role="group"
            aria-label="Keypad angka"
            className="grid grid-cols-5 gap-2"
        >
            {DIGITS.map((digit) => (
                <button
                    key={digit}
                    type="button"
                    disabled={disabled}
                    onClick={() => onChange(enterDigit(state, digit))}
                    aria-label={`Masukkan angka ${digit}`}
                    className={cn(
                        'border-input shadow-xs h-11 rounded-md border text-base font-semibold tabular-nums',
                        'active:bg-slate-100',
                        'disabled:pointer-events-none disabled:opacity-50',
                    )}
                >
                    {digit}
                </button>
            ))}
            <button
                type="button"
                disabled={disabled}
                onClick={() => onChange(backspace(state))}
                aria-label="Hapus angka terakhir"
                className={cn(
                    'border-input shadow-xs col-span-5 h-11 rounded-md border text-sm font-medium',
                    'active:bg-slate-100',
                    'disabled:pointer-events-none disabled:opacity-50',
                )}
            >
                Hapus
            </button>
        </div>
    );
}
