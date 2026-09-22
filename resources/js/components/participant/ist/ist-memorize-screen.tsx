import type { IstWordList, IstWordListCategory } from './ist-items.ts';

/**
 * ME's memorize phase (`current_segment.code === 'ME_MEMORIZE'`):
 * read-only display of the five word-list categories — no items, no
 * answering, no autosave. The answer phase (`ME_ANSWER`) is a completely
 * separate segment with its own real content (`items`, populated only
 * then — see `ist-items.ts`'s module doc); this screen never shows or
 * collects an answer itself. `remainingSeconds` is rendered exactly as
 * given, same "never derives/ticks/reacts beyond showing it" contract as
 * `ist-subtest-screen.tsx`'s own `RemainingSecondsDisplay`.
 */

export type IstMemorizeScreenProps = {
    wordList: IstWordList;
    instructions: string;
    remainingSeconds: number;
};

export function IstMemorizeScreen({
    wordList,
    instructions,
    remainingSeconds,
}: IstMemorizeScreenProps) {
    const minutes = Math.floor(remainingSeconds / 60);
    const seconds = remainingSeconds % 60;
    const categories = Object.keys(wordList) as IstWordListCategory[];

    return (
        <div className="flex flex-col gap-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p
                className="self-end text-sm font-medium text-slate-500"
                aria-live="off"
            >
                Sisa waktu: {minutes}:{String(seconds).padStart(2, '0')}
            </p>

            <div>
                <h2 className="text-lg font-semibold text-slate-950">ME</h2>
                <p className="mt-2 text-sm leading-6 text-slate-700">
                    {instructions}
                </p>
            </div>

            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {categories.map((category) => (
                    <div key={category}>
                        <dt className="text-sm font-semibold text-slate-700">
                            {category}
                        </dt>
                        <dd>
                            <ul className="mt-1 flex flex-col gap-1 text-sm text-slate-950">
                                {wordList[category].map((word) => (
                                    <li key={word}>{word}</li>
                                ))}
                            </ul>
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}
