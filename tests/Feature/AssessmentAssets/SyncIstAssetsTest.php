<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentAssets;

use App\Actions\AssessmentAssets\SyncIstAssets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off). FA/WU has not
 * landed (#73), so this proves SyncIstAssets against a synthetic source
 * directory (Lead's plan review: "Endpoint aset di tahap 1 cukup
 * dibuktikan dengan fixture sintetis") -- a temp directory the test itself
 * writes and tears down, not a fixture checked into the repo (avoids
 * committing binary "instrument data" ahead of the real #73 extraction,
 * per CLAUDE.md's tools/extract/-only rule for that).
 */
final class SyncIstAssetsTest extends TestCase
{
    private string $sourceDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        Storage::fake('ist-assets');
        $this->sourceDirectory = sys_get_temp_dir().'/ist-assets-sync-test-'.uniqid('', true);
        File::ensureDirectoryExists($this->sourceDirectory.'/fa');
        File::put($this->sourceDirectory.'/fa/legend-1-a.png', 'synthetic-png-bytes-a');
        File::put($this->sourceDirectory.'/fa/legend-1-b.png', 'synthetic-png-bytes-b');
        File::put($this->sourceDirectory.'/fa/readme.txt', 'not an image, must be ignored');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sourceDirectory);
        parent::tearDown();
    }

    public function test_it_syncs_every_png_and_ignores_other_extensions(): void
    {
        $result = $this->sync();

        $this->assertSame(2, $result['synced']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame([], $result['failed']);

        Storage::disk('ist-assets')->assertExists('fa/legend-1-a.png');
        Storage::disk('ist-assets')->assertExists('fa/legend-1-b.png');
        Storage::disk('ist-assets')->assertMissing('fa/readme.txt');

        $rows = DB::table('assessment_asset_references')->orderBy('object_key')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('ist', $rows[0]->instrument);
        $this->assertSame('fa/legend-1-a.png', $rows[0]->object_key);
        $this->assertSame(hash('sha256', 'synthetic-png-bytes-a'), $rows[0]->checksum_sha256);
        $this->assertTrue(Str::isUlid($rows[0]->asset_id));

        // The stored bytes really do match the recorded checksum -- proves
        // the post-write verification's premise, not just that some row
        // got written.
        foreach ($rows as $row) {
            $onDisk = Storage::disk('ist-assets')->get($row->object_key);
            $this->assertSame($row->checksum_sha256, hash('sha256', (string) $onDisk));
        }
    }

    public function test_a_second_run_with_no_source_changes_is_fully_unchanged_and_keeps_the_same_asset_ids(): void
    {
        $first = $this->sync();
        $assetIdsBefore = DB::table('assessment_asset_references')->orderBy('object_key')->pluck('asset_id', 'object_key');

        $second = $this->sync();

        $this->assertSame(2, $first['synced']);
        $this->assertSame(0, $second['synced']);
        $this->assertSame(2, $second['unchanged']);
        $assetIdsAfter = DB::table('assessment_asset_references')->orderBy('object_key')->pluck('asset_id', 'object_key');
        $this->assertSame($assetIdsBefore->all(), $assetIdsAfter->all());
    }

    public function test_a_changed_source_file_is_resynced_with_a_stable_asset_id_but_a_new_checksum(): void
    {
        $this->sync();
        $assetIdBefore = DB::table('assessment_asset_references')
            ->where('object_key', 'fa/legend-1-a.png')->value('asset_id');

        File::put($this->sourceDirectory.'/fa/legend-1-a.png', 'synthetic-png-bytes-a-REVISED');
        $result = $this->sync();

        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['unchanged']);

        $row = DB::table('assessment_asset_references')->where('object_key', 'fa/legend-1-a.png')->first();
        $this->assertSame($assetIdBefore, $row->asset_id);
        $this->assertSame(hash('sha256', 'synthetic-png-bytes-a-REVISED'), $row->checksum_sha256);
        $this->assertSame(
            'synthetic-png-bytes-a-REVISED',
            Storage::disk('ist-assets')->get('fa/legend-1-a.png'),
        );
    }

    public function test_it_reports_a_failure_and_writes_no_row_when_the_target_disk_is_not_configured(): void
    {
        $sync = app(SyncIstAssets::class);
        $result = $sync->handle($this->sourceDirectory, 'this-disk-does-not-exist-in-config');

        $this->assertSame(0, $result['synced']);
        $this->assertCount(2, $result['failed']);
        $this->assertCount(0, DB::table('assessment_asset_references')->get());
    }

    public function test_the_console_command_exits_non_zero_when_the_target_disk_is_not_configured(): void
    {
        $this->artisanCommand('assets:sync-ist', [
            '--source' => $this->sourceDirectory,
            '--disk' => 'this-disk-does-not-exist-in-config',
        ])->assertExitCode(1);
    }

    public function test_the_console_command_exits_zero_and_syncs_on_the_happy_path(): void
    {
        $this->artisanCommand('assets:sync-ist', [
            '--source' => $this->sourceDirectory,
            '--disk' => 'ist-assets',
        ])->assertExitCode(0);

        $this->assertCount(2, DB::table('assessment_asset_references')->get());
    }

    public function test_the_console_command_fails_closed_when_the_source_directory_does_not_exist(): void
    {
        $this->artisanCommand('assets:sync-ist', [
            '--source' => $this->sourceDirectory.'/does-not-exist',
            '--disk' => 'ist-assets',
        ])->assertExitCode(1);
    }

    /** @param array<string, string> $parameters */
    private function artisanCommand(string $command, array $parameters): PendingCommand
    {
        $result = $this->artisan($command, $parameters);
        if (is_int($result)) {
            $this->fail('The artisan command did not return a test command wrapper.');
        }

        return $result;
    }

    /** @return array{synced: int, unchanged: int, failed: list<string>} */
    private function sync(): array
    {
        return app(SyncIstAssets::class)->handle($this->sourceDirectory, 'ist-assets');
    }
}
