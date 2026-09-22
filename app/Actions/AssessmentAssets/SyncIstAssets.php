<?php

declare(strict_types=1);

namespace App\Actions\AssessmentAssets;

use App\Domain\AssessmentAssets\AssessmentAssetSyncFailed;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off). Copies the
 * checked-in `database/seeders/data/assets/ist/**\/*.png` source (reviewed
 * the same way as ist_items.json -- CLAUDE.md: instrument data only through
 * tools/extract/ + review) onto the private `ist-assets` disk, and records
 * an opaque, stable `asset_id` per (instrument, object_key) in
 * `assessment_asset_references` -- the ONLY thing a reader is allowed to
 * put in an item payload; the object_key/disk stay server-side.
 *
 * Idempotent: an unchanged source file that already matches both the DB
 * checksum and the live disk bytes is skipped, not rewritten. A checksum
 * that fails verification AFTER writing (write corruption, not source
 * drift) throws AssessmentAssetSyncFailed, which the console command turns
 * into a non-zero exit -- Lead's explicit requirement is that this fails
 * the deploy outright rather than serving old or corrupted asset bytes. One
 * bad file does not stop the others from being processed (same
 * per-item-isolation reasoning as SweepExpiredAssessmentSessions), but the
 * overall result still reports failure so the deploy still fails.
 *
 * Deliberately does NOT handle a source file being removed (deactivating or
 * deleting its assessment_asset_references row) -- there is no real
 * removal case yet (FA/WU has not even landed once), and CLAUDE.md's
 * "kerjakan hanya yang diminta" cuts against building that lifecycle ahead
 * of a real need. Note it here so a future PR does not have to rediscover
 * the gap.
 */
final readonly class SyncIstAssets
{
    private const string INSTRUMENT = 'ist';

    public function __construct(private RlsContextRunner $contexts) {}

    /** @return array{synced: int, unchanged: int, failed: list<string>} */
    public function handle(string $sourceDirectory, string $disk): array
    {
        $synced = 0;
        $unchanged = 0;
        $failed = [];

        foreach ($this->sourceFiles($sourceDirectory) as $objectKey => $absolutePath) {
            try {
                if ($this->syncOne($objectKey, $absolutePath, $disk)) {
                    $synced++;
                } else {
                    $unchanged++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = "{$objectKey}: {$exception->getMessage()}";
            }
        }

        return ['synced' => $synced, 'unchanged' => $unchanged, 'failed' => $failed];
    }

    /** @return array<string, string> object_key (forward-slash relative path) => absolute source path */
    private function sourceFiles(string $sourceDirectory): array
    {
        $files = [];
        foreach (File::allFiles($sourceDirectory) as $file) {
            if (strtolower($file->getExtension()) !== 'png') {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $files[$relative] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }

    private function syncOne(string $objectKey, string $absolutePath, string $disk): bool
    {
        $bytes = File::get($absolutePath);
        $checksum = hash('sha256', $bytes);

        return $this->contexts->runAsService(
            function () use ($objectKey, $bytes, $checksum, $disk): bool {
                $existing = DB::table('assessment_asset_references')
                    ->where('instrument', self::INSTRUMENT)
                    ->where('object_key', $objectKey)
                    ->lockForUpdate()
                    ->first();

                $diskUnchanged = $existing !== null
                    && $existing->checksum_sha256 === $checksum
                    && $existing->disk === $disk
                    && Storage::disk($disk)->exists($objectKey)
                    && hash_equals($checksum, hash('sha256', (string) Storage::disk($disk)->get($objectKey)));

                if ($diskUnchanged) {
                    return false;
                }

                Storage::disk($disk)->put($objectKey, $bytes);

                $writtenBytes = Storage::disk($disk)->get($objectKey);
                if (! is_string($writtenBytes) || ! hash_equals($checksum, hash('sha256', $writtenBytes))) {
                    throw new AssessmentAssetSyncFailed(
                        "Asset \"{$objectKey}\" failed checksum verification after sync.",
                    );
                }

                DB::table('assessment_asset_references')->updateOrInsert(
                    ['instrument' => self::INSTRUMENT, 'object_key' => $objectKey],
                    [
                        'asset_id' => $existing->asset_id ?? (string) Str::ulid(),
                        'disk' => $disk,
                        'checksum_sha256' => $checksum,
                        'created_at' => $existing->created_at ?? now(),
                        'updated_at' => now(),
                    ],
                );

                return true;
            },
        );
    }
}
