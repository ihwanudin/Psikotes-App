import { Button } from '@/components/ui/button';

import type { PapiInstructions } from './papi-items.ts';

/**
 * Pure presentational screen for the PAPI `instructions` text that now
 * travels through `/items` alongside the 90 statement pairs (PR #71) —
 * one server-side source of truth instead of a hardcoded client copy.
 * Shown once before the 90-item flow starts; `closing` is shown here
 * too rather than on the summary screen, since it explains how to use
 * the answer sheet, not how to finish the test.
 */

export type PapiInstructionsScreenProps = {
    instructions: PapiInstructions;
    onStart: () => void;
};

export function PapiInstructionsScreen({
    instructions,
    onStart,
}: PapiInstructionsScreenProps) {
    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-4 text-sm leading-6 text-slate-700">
                <p>{instructions.intro}</p>

                <div className="rounded-xl border border-slate-200 p-4">
                    <p className="font-semibold text-slate-900">Contoh</p>
                    <p className="mt-2">{instructions.example.statement_a}</p>
                    <p>{instructions.example.statement_b}</p>
                </div>

                <div className="rounded-xl border border-slate-200 p-4">
                    <p className="font-semibold text-slate-900">
                        {instructions.answer_sheet_demo.label}
                    </p>
                    <p className="mt-2">
                        {instructions.answer_sheet_demo.statement_a}
                    </p>
                    <p>{instructions.answer_sheet_demo.statement_b}</p>
                </div>

                <p>{instructions.closing}</p>
            </div>

            <Button type="button" onClick={onStart}>
                Mulai
            </Button>
        </div>
    );
}
