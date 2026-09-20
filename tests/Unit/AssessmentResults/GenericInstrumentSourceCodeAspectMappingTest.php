<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentResults;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F2 lane G7 (2026-09-20). Lead-required guard: a `source_code` persisted by
 * PersistSealedPapiResult/PersistSealedRmibResult that does not exactly match
 * what database/seeders/data/aspect_sources.json expects does not error — it
 * silently drops that source from HPP aggregation, the most expensive class
 * of failure (silent and wrong). This test reads both the real seed data and
 * the real aspect map directly (never a copy written into this test), so a
 * future edit to either file is exactly what would break it.
 *
 * See tasks/handoffs/f2/generic-instrument-result-field-mapping.md for the
 * full field-mapping rationale.
 */
final class GenericInstrumentSourceCodeAspectMappingTest extends TestCase
{
    public function test_every_non_excluded_papi_dimension_code_is_referenced_by_aspect_sources(): void
    {
        $papiCodes = $this->dimensionCodes();
        $referenced = $this->referencedCodes('PAPI_');
        $excluded = $this->papiData()['normalization']['excluded_from_hpp'];

        sort($excluded);
        $this->assertSame(['G', 'I', 'X', 'Z'], $excluded, 'The excluded set itself must be exactly these four.');

        foreach ($papiCodes as $code) {
            if (in_array($code, $excluded, true)) {
                $this->assertNotContains(
                    $code,
                    $referenced,
                    "PAPI dimension {$code} is HPP-excluded and must not be referenced by aspect_sources.json.",
                );

                continue;
            }
            $this->assertContains(
                $code,
                $referenced,
                "PAPI dimension {$code} is not excluded, so aspect_sources.json must reference PAPI_{$code}.",
            );
        }

        // No aspect_sources.json PAPI reference may point at a dimension that
        // does not exist in the canonical mapping at all.
        foreach ($referenced as $code) {
            $this->assertContains($code, $papiCodes, "aspect_sources.json references an unknown PAPI dimension {$code}.");
        }
    }

    public function test_every_d1_d5_rmib_category_code_is_referenced_by_aspect_sources_and_others_are_not(): void
    {
        $rmibCodes = $this->rmibCategoryCodes();
        $referenced = $this->referencedCodes('RMIB_');

        // D1-D5 per SCORING_ALGORITHM.md.
        $expectedReferenced = ['Out', 'Me', 'Prac', 'Med', 'S.Se'];
        sort($expectedReferenced);
        $sortedReferenced = $referenced;
        sort($sortedReferenced);
        $this->assertSame($expectedReferenced, $sortedReferenced);

        foreach ($rmibCodes as $code) {
            if (in_array($code, $expectedReferenced, true)) {
                $this->assertContains($code, $referenced, "RMIB category {$code} must be referenced by aspect_sources.json as D1-D5.");

                continue;
            }
            $this->assertNotContains(
                $code,
                $referenced,
                "RMIB category {$code} is one of the seven psychologist-review-only categories and must not be referenced.",
            );
        }
    }

    /** @return list<string> */
    private function dimensionCodes(): array
    {
        $codes = [];
        foreach ($this->papiData()['mapping'] as $definition) {
            $codes[$definition['a']] = true;
            $codes[$definition['b']] = true;
        }

        return array_keys($codes);
    }

    /** @return list<string> */
    private function rmibCategoryCodes(): array
    {
        return array_map(
            static fn (array $category): string => $category['code'],
            $this->rmibData()['categories'],
        );
    }

    /** @return list<string> */
    private function referencedCodes(string $prefix): array
    {
        $codes = [];
        foreach ($this->aspectSourcesData()['aspects'] as $sources) {
            foreach ($sources as $source) {
                if (str_starts_with($source, $prefix)) {
                    $codes[] = substr($source, strlen($prefix));
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /** @return array<string, mixed> */
    private function papiData(): array
    {
        return $this->readJson('papi.json');
    }

    /** @return array<string, mixed> */
    private function rmibData(): array
    {
        return $this->readJson('rmib.json');
    }

    /** @return array<string, mixed> */
    private function aspectSourcesData(): array
    {
        return $this->readJson('aspect_sources.json');
    }

    /** @return array<string, mixed> */
    private function readJson(string $fileName): array
    {
        $path = dirname(__DIR__, 3).'/database/seeders/data/'.$fileName;
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException("Canonical data file {$fileName} could not be read.");
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Canonical data file {$fileName} did not decode to an array.");
        }

        return $decoded;
    }
}
