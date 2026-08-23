<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

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

            $storedPayload = DB::table('instrument_versions')->where('code', $code)->value('payload');

            $this->assertIsString($storedPayload);
            $this->assertJsonStringEqualsJsonFile($sourcePath, $storedPayload);
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
        $this->seed(InstrumentSeeder::class);

        DB::table('instrument_versions')->where('code', 'ist')->update(['checksum' => str_repeat('0', 64)]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');

        $this->seed(InstrumentSeeder::class);
    }
}
