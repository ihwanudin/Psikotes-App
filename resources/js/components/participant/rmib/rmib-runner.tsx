import { useState } from 'react';

import {
    confirmCurrentOrder,
    createRmibGroupState,
    isGroupComplete,
    moveDown,
    moveUp,
    reorder,
} from './rmib-group-state.ts';
import type { RmibGroupState } from './rmib-group-state.ts';
import { RMIB_GROUP_COUNT, groupRmibPositions } from './rmib-items.ts';
import type { RmibPosition } from './rmib-items.ts';
import { RmibGroupScreen } from './rmib-group-screen.tsx';
import { RmibSummary } from './rmib-summary.tsx';

/**
 * Composes the group view, navigation, and pre-submit summary/lock into
 * one runner — mirrors `papi-runner.tsx`'s composition shape for this
 * instrument's group-based (not single-item) flow. `positions` is passed
 * in as a prop, not fetched; the caller (`rmib-items-runner.tsx`) owns
 * `GET /sessions/:id/items`, resume, and autosave.
 */

function groupLetterFor(
    positions: RmibPosition[][],
    groupNumber: number,
): string {
    return positions[groupNumber - 1]?.[0]?.group_letter ?? '';
}

export type RmibRunnerProps = {
    /** All 108 positions, flat, in source (group-major) order. */
    positions: RmibPosition[];
    /** Pre-seeds each group's state, from a resumed session — one entry
     * per group, same order as `groupRmibPositions(positions)`. Omit for
     * a fresh session (every group starts unconfirmed, document order). */
    initialGroupStates?: RmibGroupState[];
    /** Called whenever a group's order or confirmed flag changes, with
     * the group's 1-based number and its full new state — the caller
     * queues autosave for ALL 12 positions in that group (not just the
     * one that moved: a single swap/reorder can change more than one
     * position's rank), per the approved RMIB runner plan. */
    onGroupChange?: (groupNumber: number, state: RmibGroupState) => void;
    /** Called right after the participant leaves the currently-shown
     * group (next/previous/jump-to-group, or opening the review
     * summary) — the caller wires this to `useAutosave().flush()`. */
    onAfterNavigate?: () => void;
    /** Called when the participant submits with every group confirmed.
     * Honest stub, same contract as `papi-runner.tsx`'s `onSubmit`. */
    onSubmit: () => void;
    submitting?: boolean;
};

export function RmibRunner({
    positions,
    initialGroupStates,
    onGroupChange,
    onAfterNavigate,
    onSubmit,
    submitting,
}: RmibRunnerProps) {
    const [groupedPositions] = useState<RmibPosition[][]>(() =>
        groupRmibPositions(positions),
    );
    const [groupStates, setGroupStates] = useState<RmibGroupState[]>(
        () =>
            initialGroupStates ??
            Array.from({ length: RMIB_GROUP_COUNT }, () =>
                createRmibGroupState(),
            ),
    );
    const [currentGroupIndex, setCurrentGroupIndex] = useState(0);
    const [view, setView] = useState<'group' | 'summary'>('group');

    // Deliberately NOT a functional setGroupStates(current => ...) updater:
    // onGroupChange is a caller-supplied side effect (queues autosave), and
    // React (Strict Mode) may invoke an updater function more than once to
    // check for impurity, which would fire that side effect more than
    // once. Reading groupStates/currentGroupIndex directly from this
    // render's closure is safe here -- every call site is a single
    // synchronous event handler, never a rapid-fire/concurrent update.
    function applyToCurrentGroup(
        update: (state: RmibGroupState) => RmibGroupState,
    ): void {
        const updated = update(groupStates[currentGroupIndex]!);
        const next = groupStates.slice();
        next[currentGroupIndex] = updated;
        setGroupStates(next);
        onGroupChange?.(currentGroupIndex + 1, updated);
    }

    function goToGroup(index: number): void {
        setCurrentGroupIndex(
            Math.max(0, Math.min(index, RMIB_GROUP_COUNT - 1)),
        );
        setView('group');
        onAfterNavigate?.();
    }

    if (view === 'summary') {
        const unconfirmedGroupNumbers = groupStates
            .map((state, index) => (isGroupComplete(state) ? null : index + 1))
            .filter(
                (groupNumber): groupNumber is number => groupNumber !== null,
            );

        return (
            <div className="flex flex-col gap-4">
                <RmibSummary
                    groupCount={RMIB_GROUP_COUNT}
                    unconfirmedGroupNumbers={unconfirmedGroupNumbers}
                    groupLetterFor={(groupNumber) =>
                        groupLetterFor(groupedPositions, groupNumber)
                    }
                    onJumpToGroup={(groupNumber) => goToGroup(groupNumber - 1)}
                    onSubmit={onSubmit}
                    submitting={submitting}
                />
                <button
                    type="button"
                    onClick={() => setView('group')}
                    className="flex min-h-11 items-center self-start px-1 text-sm text-teal-700 underline-offset-4 hover:underline"
                >
                    Kembali ke kelompok
                </button>
            </div>
        );
    }

    const currentPositions = groupedPositions[currentGroupIndex]!;
    const currentState = groupStates[currentGroupIndex]!;

    return (
        <RmibGroupScreen
            positions={currentPositions}
            groupNumber={currentGroupIndex + 1}
            groupLetter={groupLetterFor(
                groupedPositions,
                currentGroupIndex + 1,
            )}
            groupCount={RMIB_GROUP_COUNT}
            order={currentState.order}
            confirmed={currentState.confirmed}
            onMoveUp={(position) =>
                applyToCurrentGroup((state) => moveUp(state, position))
            }
            onMoveDown={(position) =>
                applyToCurrentGroup((state) => moveDown(state, position))
            }
            onReorder={(fromIndex, toIndex) =>
                applyToCurrentGroup((state) =>
                    reorder(state, fromIndex, toIndex),
                )
            }
            onConfirmCurrentOrder={() =>
                applyToCurrentGroup((state) => confirmCurrentOrder(state))
            }
            onPrevious={() => goToGroup(currentGroupIndex - 1)}
            onNext={() => goToGroup(currentGroupIndex + 1)}
            onReviewAnswers={() => {
                setView('summary');
                onAfterNavigate?.();
            }}
        />
    );
}
