import {
    DndContext,
    PointerSensor,
    TouchSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    SortableContext,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ChevronDown, ChevronUp, GripVertical } from 'lucide-react';

import { Button } from '@/components/ui/button';

import type { RmibPosition } from './rmib-items.ts';

/**
 * Presentational ranked list for one RMIB group's 12 job positions.
 * Renders in RANK order (`order[0]` = rank 1) — the caller
 * (`rmib-group-screen.tsx`) owns `RmibGroupState` and passes down only
 * what this component needs to draw and to report actions back up.
 *
 * TWO independent, equally real ways to reorder (Lead's 2026-09-21 plan
 * approval): pointer/touch drag via `@dnd-kit` (`PointerSensor` for
 * mouse, `TouchSensor` with a short delay+tolerance so a normal vertical
 * scroll gesture on a touchscreen doesn't get mistaken for a drag start
 * — the exact "scroll vs. drag" conflict Lead flagged), AND always-visible
 * ▲/▼ buttons on every row as the PRIMARY keyboard/screen-reader path —
 * deliberately not `@dnd-kit`'s own keyboard sensor, which requires
 * focusing a drag handle that isn't reliably discoverable by a screen
 * reader. No `KeyboardSensor` is registered here for that reason; the
 * buttons are the whole keyboard story, not a fallback bolted on.
 */

export type RmibGroupListProps = {
    /** This group's 12 positions, in whatever order the server sent them
     * (NOT rank order) — looked up by `position.position` as needed. */
    positions: RmibPosition[];
    /** Current rank order: `order[0]` is the position number ranked 1st. */
    order: number[];
    onMoveUp: (position: number) => void;
    onMoveDown: (position: number) => void;
    /** 0-based rank indices, matching `@dnd-kit/sortable`'s `arrayMove`
     * arguments exactly — the caller feeds this straight into
     * `reorder()` from rmib-group-state.ts. */
    onReorder: (fromIndex: number, toIndex: number) => void;
};

export function RmibGroupList({
    positions,
    order,
    onMoveUp,
    onMoveDown,
    onReorder,
}: RmibGroupListProps) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 250, tolerance: 5 },
        }),
    );
    const jobByPosition = new Map(
        positions.map((entry) => [entry.position, entry.job]),
    );

    function handleDragEnd(event: DragEndEvent): void {
        const { active, over } = event;

        if (over === null || active.id === over.id) {
            return;
        }

        const fromIndex = order.indexOf(Number(active.id));
        const toIndex = order.indexOf(Number(over.id));

        if (fromIndex === -1 || toIndex === -1) {
            return;
        }

        // fromIndex/toIndex are exactly the index pair
        // rmib-group-state.ts's reorder() expects (the same semantics
        // @dnd-kit/sortable's own arrayMove uses) -- the caller applies
        // the actual state update, this component never mutates order.
        onReorder(fromIndex, toIndex);
    }

    return (
        <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
            <SortableContext
                items={order}
                strategy={verticalListSortingStrategy}
            >
                <ol className="flex flex-col gap-2">
                    {order.map((position, index) => (
                        <RmibGroupRow
                            key={position}
                            position={position}
                            job={jobByPosition.get(position) ?? ''}
                            rank={index + 1}
                            isFirst={index === 0}
                            isLast={index === order.length - 1}
                            onMoveUp={() => onMoveUp(position)}
                            onMoveDown={() => onMoveDown(position)}
                        />
                    ))}
                </ol>
            </SortableContext>
        </DndContext>
    );
}

type RmibGroupRowProps = {
    position: number;
    job: string;
    rank: number;
    isFirst: boolean;
    isLast: boolean;
    onMoveUp: () => void;
    onMoveDown: () => void;
};

function RmibGroupRow({
    position,
    job,
    rank,
    isFirst,
    isLast,
    onMoveUp,
    onMoveDown,
}: RmibGroupRowProps) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: position });

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition: transition ?? undefined,
            }}
            className={`flex items-center gap-2 rounded-xl border border-slate-200 bg-white p-2 ${
                isDragging ? 'z-10 shadow-md' : ''
            }`}
        >
            <button
                type="button"
                {...attributes}
                {...listeners}
                aria-label={`Seret untuk mengurutkan ulang: ${job}`}
                className="flex size-11 shrink-0 touch-none items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            >
                <GripVertical className="size-5" aria-hidden="true" />
            </button>

            <div className="flex min-w-0 flex-1 items-center gap-3">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">
                    {rank}
                </span>
                <span className="min-w-0 flex-1 truncate text-sm text-slate-900">
                    {job}
                </span>
            </div>

            <div className="flex shrink-0 flex-col gap-1">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-11"
                    disabled={isFirst}
                    aria-label={`Naikkan peringkat: ${job}`}
                    onClick={onMoveUp}
                >
                    <ChevronUp aria-hidden="true" />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-11"
                    disabled={isLast}
                    aria-label={`Turunkan peringkat: ${job}`}
                    onClick={onMoveDown}
                >
                    <ChevronDown aria-hidden="true" />
                </Button>
            </div>
        </li>
    );
}
