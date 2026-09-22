<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AssessmentAssets\SyncIstAssets;
use Illuminate\Console\Command;

/**
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off). Run automatically
 * at deploy time, same stage as `migrate` (Lead's decision) -- NOT on the
 * recurring scheduler, since there is nothing to re-check between deploys.
 * Non-zero exit on ANY sync failure (including a post-write checksum
 * mismatch) must fail the deploy; see SyncIstAssets's own doc comment.
 */
final class SyncIstAssetsCommand extends Command
{
    protected $signature = 'assets:sync-ist {--source=} {--disk=}';

    protected $description = 'Sync IST instrument image assets from the checked-in source directory onto the private asset disk';

    public function handle(SyncIstAssets $sync): int
    {
        $source = $this->option('source') ?? database_path('seeders/data/assets/ist');
        $disk = $this->option('disk') ?? (string) config('assessment_assets.ist.disk', 'ist-assets');

        if (! is_dir($source)) {
            $this->error("Source directory not found: {$source}");

            return self::FAILURE;
        }

        $result = $sync->handle($source, $disk);

        $this->info("Synced {$result['synced']} asset(s), {$result['unchanged']} unchanged.");

        if ($result['failed'] !== []) {
            foreach ($result['failed'] as $failure) {
                $this->error($failure);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
