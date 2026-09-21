/**
 * ULID generator for autosave `mutation_id` (API_CONTRACT.md ~line 95-98,
 * f2-assessment-session-contract.md §Autosave contract). `mutation_id` is
 * an idempotency key: the server treats two different answer batches
 * sharing the same key as one replayed mutation and silently drops one of
 * them. crypto.getRandomValues is required, not Math.random — Math.random
 * is not a cryptographically strong source and this repository treats
 * collision-prone identifiers as a correctness bug, not a cosmetic one.
 *
 * Monotonic within the same millisecond: a same-millisecond call
 * increments the previous random component by one instead of
 * re-randomizing, so rapid autosave flushes (e.g. moving between items
 * quickly) can never produce two identical IDs from the same page load.
 * This is stronger than the contract requires — it only requires
 * uniqueness — but it is cheap and removes a class of bug outright.
 */

const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const ENCODING_LEN = ENCODING.length;
const TIME_LEN = 10;
const RANDOM_LEN = 16;
const TIME_MAX = 2 ** 48 - 1;

function encodeTime(time: number, length: number): string {
    if (!Number.isFinite(time) || time < 0 || time > TIME_MAX) {
        throw new RangeError('ulid: time must be within [0, 2^48 - 1]');
    }

    let remaining = time;
    let encoded = '';

    for (let i = 0; i < length; i++) {
        const mod = remaining % ENCODING_LEN;
        encoded = ENCODING[mod] + encoded;
        remaining = (remaining - mod) / ENCODING_LEN;
    }

    return encoded;
}

function randomCharacters(length: number): string {
    const bytes = new Uint8Array(length);
    crypto.getRandomValues(bytes);

    let encoded = '';

    for (let i = 0; i < length; i++) {
        // 256 is an exact multiple of 32 (the alphabet size), so indexing
        // by byte % 32 has no modulo bias.
        encoded += ENCODING[bytes[i] % ENCODING_LEN];
    }

    return encoded;
}

function incrementRandom(random: string): string {
    const characters = random.split('');

    for (let i = characters.length - 1; i >= 0; i--) {
        const index = ENCODING.indexOf(characters[i]);

        if (index < ENCODING_LEN - 1) {
            characters[i] = ENCODING[index + 1];

            return characters.join('');
        }

        characters[i] = ENCODING[0];
    }

    // 80 bits of randomness overflowing within one millisecond is not
    // reachable in this application; fail loudly rather than silently
    // wrap around and risk a duplicate ID.
    throw new RangeError('ulid: random component overflowed');
}

/**
 * Creates an independent, stateful ULID generator. Each generator tracks
 * its own last-timestamp/last-random pair for monotonicity; use one
 * shared instance per logical source of IDs (e.g. one per autosave
 * engine instance) rather than a fresh generator per call.
 */
export function createUlidGenerator(): (now?: number) => string {
    let lastTime = -1;
    let lastRandom = '';

    return function ulid(now: number = Date.now()): string {
        if (now === lastTime) {
            lastRandom = incrementRandom(lastRandom);
        } else {
            lastTime = now;
            lastRandom = randomCharacters(RANDOM_LEN);
        }

        return encodeTime(now, TIME_LEN) + lastRandom;
    };
}
