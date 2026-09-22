<?php

declare(strict_types=1);

namespace App\Services\Dass;

use App\Domain\Dass\Dass21ConfigUnavailable;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Reads DASS-21's scoring configuration (items/cutoffs/multiplier) from
 * the versioned `instrument_versions` authority, code=dass21 — same
 * checksum-verified pattern `KraepelinItemContentReader`/
 * `IstItemContentReader` already use, not a new convention.
 *
 * `minimum_completion_seconds` (required by `Dass21ScreeningPolicy`'s
 * constructor, to flag a suspiciously-fast completion) is NOT currently
 * present in `database/seeders/data/dass21.json` — verified by reading the
 * seeded file directly, not guessed. CLAUDE.md forbids embedding a
 * threshold like this in application code ("JANGAN menanam bobot/ambang...
 * di kode — SEMUA dibaca dari Tabel Lookup (data)"), so this reader fails
 * closed (`Dass21ConfigUnavailable`) rather than inventing a number — the
 * real value is a clinical/product decision for the psychologist, extended
 * into `dass21.json` via `tools/extract/extract_dass.py` + review, same as
 * every other instrument threshold in this codebase. Tests supply their
 * own complete fixture including this field; only the real seeded
 * production data is currently missing it.
 */
final class Dass21ConfigReader
{
    /**
     * @return array{
     *     version: string,
     *     items: array<mixed>,
     *     cutoffs: array<mixed>,
     *     multiplier: int,
     *     minimumCompletionSeconds: int,
     *     narratives: array<mixed>,
     *     followUp: array<mixed>,
     * }
     */
    public function read(): array
    {
        $authority = DB::table('instrument_versions')
            ->where('code', 'dass21')
            ->where('is_active', true)
            ->select(['version', 'checksum', 'source_text'])
            ->first();

        if ($authority === null) {
            throw new Dass21ConfigUnavailable('The DASS-21 configuration authority is missing.');
        }

        $version = $authority->version ?? null;
        $checksum = $authority->checksum ?? null;
        $sourceText = $authority->source_text ?? null;

        if (! is_string($version) || $version === ''
            || ! is_string($checksum) || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
            || ! is_string($sourceText) || $sourceText === ''
            || ! hash_equals($checksum, hash('sha256', $sourceText))) {
            throw new Dass21ConfigUnavailable('The DASS-21 configuration authority is unverifiable.');
        }

        try {
            $decoded = json_decode($sourceText, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Dass21ConfigUnavailable('The DASS-21 configuration payload is invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded)
            || ! isset($decoded['items'], $decoded['cutoffs'], $decoded['multiplier'], $decoded['narratives'], $decoded['follow_up'])
            || ! is_array($decoded['items'])
            || ! is_array($decoded['cutoffs'])
            || ! is_array($decoded['narratives'])
            || ! is_array($decoded['follow_up'])) {
            throw new Dass21ConfigUnavailable('The DASS-21 configuration payload is malformed.');
        }

        if (! isset($decoded['minimum_completion_seconds'])
            || ! is_int($decoded['minimum_completion_seconds'])
            || $decoded['minimum_completion_seconds'] < 1) {
            throw new Dass21ConfigUnavailable(
                'The DASS-21 configuration is missing minimum_completion_seconds -- '
                .'extend dass21.json via tools/extract/extract_dass.py, reviewed by the psychologist, before DASS-21 submissions can be scored.',
            );
        }

        return [
            'version' => $version,
            'items' => $decoded['items'],
            'cutoffs' => $decoded['cutoffs'],
            'multiplier' => $decoded['multiplier'],
            'minimumCompletionSeconds' => $decoded['minimum_completion_seconds'],
            'narratives' => $decoded['narratives'],
            'followUp' => $decoded['follow_up'],
        ];
    }
}
