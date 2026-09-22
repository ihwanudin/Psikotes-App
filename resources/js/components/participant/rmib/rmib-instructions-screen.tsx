import { Button } from '@/components/ui/button';

import type { RmibInstructions } from './rmib-items.ts';

/**
 * Pure presentational screen for the RMIB `instructions` that travel
 * through `/items` alongside the 108 positions (PR #76) — one server-side
 * source of truth instead of a hardcoded client copy. Mirrors
 * `papi-instructions-screen.tsx`'s shape for this instrument's simpler
 * two-field instructions.
 */

export type RmibInstructionsScreenProps = {
    instructions: RmibInstructions;
    onStart: () => void;
};

export function RmibInstructionsScreen({
    instructions,
    onStart,
}: RmibInstructionsScreenProps) {
    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-4 text-sm leading-6 text-slate-700">
                <p>{instructions.text}</p>
                <p className="font-medium text-slate-900">
                    {instructions.write_preferred_jobs_prompt}
                </p>
            </div>

            <Button type="button" onClick={onStart}>
                Mulai
            </Button>
        </div>
    );
}
