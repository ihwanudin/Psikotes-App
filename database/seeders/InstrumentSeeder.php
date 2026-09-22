<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Security\RlsContextRunner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LogicException;

final class InstrumentSeeder extends Seeder
{
    private const DATASET_VERSION = 'F0-2026.08';

    /** @var array<string, string> */
    private const SOURCES = [
        'ist' => 'ist.json',
        'papi' => 'papi.json',
        'rmib' => 'rmib.json',
        'kraepelin' => 'kraepelin.json',
        // F2 item-delivery Stage 2 (2026-09-21): the raw item numbers, kept
        // as a separate instrument_versions row from 'kraepelin' above
        // (that one holds scoring norms). See
        // app/Services/AssessmentSessions/KraepelinItemContentReader.php.
        'kraepelin_grid' => 'kraepelin_grid.json',
        // F2 item-delivery Stage 2 (2026-09-21): the raw statement pairs,
        // kept as a separate instrument_versions row from 'papi' above
        // (that one holds the scoring dimension mapping). See
        // app/Services/AssessmentSessions/PapiItemContentReader.php.
        'papi_items' => 'papi_items.json',
        // F2 item-delivery Stage 2 continuation (2026-09-21): the raw job
        // labels (both gender tracks), kept as a separate instrument_versions
        // row from 'rmib' above (that one holds the scoring
        // categories/rotation). See
        // app/Services/AssessmentSessions/RmibItemContentReader.php.
        'rmib_items' => 'rmib_items.json',
        'dass21' => 'dass21.json',
        'reporting' => 'reporting.json',
        'aspect_sources' => 'aspect_sources.json',
    ];

    public function run(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            foreach (self::SOURCES as $code => $sourceFile) {
                $this->seedSource($code, $sourceFile);
            }
        });
    }

    private function seedSource(string $code, string $sourceFile): void
    {
        $sourcePath = database_path("seeders/data/{$sourceFile}");
        $payload = File::get($sourcePath);
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $version = $this->resolveVersion($decoded);
        $checksum = hash('sha256', $payload);

        $existing = DB::table('instrument_versions')
            ->where('code', $code)
            ->where('version', $version)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (! hash_equals($existing->checksum, $checksum)) {
                throw new LogicException("Instrument version {$code}:{$version} is immutable and its checksum differs.");
            }

            return;
        }

        DB::table('instrument_versions')
            ->where('code', $code)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        DB::table('instrument_versions')->insert([
            'code' => $code,
            'version' => $version,
            'source_file' => $sourceFile,
            'checksum' => $checksum,
            'payload' => $payload,
            // Stored verbatim alongside the jsonb `payload` derivative so the
            // checksum can be re-verified against the exact bytes it was
            // computed from. PostgreSQL normalizes jsonb on write (whitespace,
            // key order), so `payload` alone can never be hashed back to
            // `checksum`; `source_text` is the byte-identical source of truth.
            'source_text' => $payload,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function resolveVersion(array $payload): string
    {
        foreach (['version', 'standard_version'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        return self::DATASET_VERSION;
    }
}
