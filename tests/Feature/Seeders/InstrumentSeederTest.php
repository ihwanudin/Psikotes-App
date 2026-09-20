<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Security\RlsContextRunner;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class InstrumentSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const SOURCES = [
        'ist' => 'ist.json',
        'papi' => 'papi.json',
        'rmib' => 'rmib.json',
        'kraepelin' => 'kraepelin.json',
        'dass21' => 'dass21.json',
        'reporting' => 'reporting.json',
        'aspect_sources' => 'aspect_sources.json',
    ];

    public function test_it_seeds_every_f0_payload_with_its_source_checksum(): void
    {
        $this->seed(InstrumentSeeder::class);

        $this->assertDatabaseCount('instrument_versions', count(self::SOURCES));

        foreach (self::SOURCES as $code => $sourceFile) {
            $sourcePath = database_path("seeders/data/{$sourceFile}");

            $this->assertDatabaseHas('instrument_versions', [
                'code' => $code,
                'source_file' => $sourceFile,
                'checksum' => hash_file('sha256', $sourcePath),
                'is_active' => true,
            ]);

            $row = DB::table('instrument_versions')->where('code', $code)->first(['payload', 'source_text', 'checksum']);

            $this->assertIsString($row->payload);
            $this->assertJsonStringEqualsJsonFile($sourcePath, $row->payload);

            // `source_text` must be the exact raw file bytes the checksum was
            // computed from — not merely JSON-equivalent to `payload`, but
            // byte-identical, so it can be hashed back to `checksum` even after
            // PostgreSQL's jsonb normalization changes `payload`'s bytes.
            $this->assertIsString($row->source_text);
            $this->assertSame(file_get_contents($sourcePath), $row->source_text);
            $this->assertTrue(hash_equals($row->checksum, hash('sha256', $row->source_text)));
        }
    }

    public function test_running_the_seeder_twice_is_idempotent(): void
    {
        $this->seed(InstrumentSeeder::class);
        $timestamps = DB::table('instrument_versions')->pluck('updated_at', 'code');

        $this->seed(InstrumentSeeder::class);

        $this->assertDatabaseCount('instrument_versions', count(self::SOURCES));
        $this->assertSame($timestamps->all(), DB::table('instrument_versions')->pluck('updated_at', 'code')->all());
    }

    public function test_it_refuses_to_overwrite_an_existing_version_with_different_content(): void
    {
        $payload = json_decode(
            file_get_contents(database_path('seeders/data/ist.json')) ?: throw new LogicException('Missing IST fixture.'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        DB::table('instrument_versions')->insert([
            'code' => 'ist',
            'version' => $payload['version'],
            'source_file' => 'ist.json',
            'checksum' => str_repeat('0', 64),
            'payload' => '{}',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');

        $this->seed(InstrumentSeeder::class);
    }

    public function test_it_refuses_to_overwrite_the_frozen_aspect_source_version(): void
    {
        DB::table('instrument_versions')->insert([
            'code' => 'aspect_sources',
            'version' => 'ASPECT-SOURCES-2026.09',
            'source_file' => 'aspect_sources.json',
            'checksum' => str_repeat('0', 64),
            'payload' => '{}',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->seed(InstrumentSeeder::class);
            $this->fail('Expected the immutable aspect-source version to reject different content.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertDatabaseCount('instrument_versions', 1);
        $this->assertDatabaseHas('instrument_versions', [
            'code' => 'aspect_sources',
            'version' => 'ASPECT-SOURCES-2026.09',
            'checksum' => str_repeat('0', 64),
            'payload' => '{}',
        ]);
    }

    public function test_it_is_safe_inside_an_existing_service_context(): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => $this->seed(InstrumentSeeder::class));

        $this->assertDatabaseCount('instrument_versions', count(self::SOURCES));
        $this->assertNull(app(RlsContextRunner::class)->current());
    }
}
