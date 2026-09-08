<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\IstRawScoreLookup;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IstRawScoreLookupTest extends TestCase
{
    public function test_corrected_se_golden_raw_score_maps_to_its_canonical_standard_score(): void
    {
        $lookup = new IstRawScoreLookup($this->canonicalNorms());

        $this->assertSame(131, $lookup->standardScore('SE', 16));
    }

    #[DataProvider('canonicalGeEdges')]
    public function test_ge_domain_edges_map_through_the_canonical_lookup(int $rawScore, int $standardScore): void
    {
        $lookup = new IstRawScoreLookup($this->canonicalNorms());

        $this->assertSame($standardScore, $lookup->standardScore('GE', $rawScore));
    }

    /** @return iterable<string, array{int, int}> */
    public static function canonicalGeEdges(): iterable
    {
        yield 'minimum' => [0, 70];
        yield 'maximum' => [32, 142];
    }

    public function test_unknown_subtest_is_rejected(): void
    {
        $lookup = new IstRawScoreLookup($this->canonicalNorms());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IST subtest is not present in the supplied norms.');

        $lookup->standardScore('UNKNOWN', 0);
    }

    #[DataProvider('outOfDomainRawScores')]
    public function test_raw_score_outside_the_subtest_lookup_domain_is_rejected(string $subtest, int $rawScore): void
    {
        $lookup = new IstRawScoreLookup($this->canonicalNorms());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IST raw score is not present in the supplied subtest norm.');

        $lookup->standardScore($subtest, $rawScore);
    }

    /** @return iterable<string, array{string, int}> */
    public static function outOfDomainRawScores(): iterable
    {
        yield 'non-GE below minimum' => ['SE', -1];
        yield 'non-GE above maximum' => ['SE', 21];
        yield 'GE above maximum' => ['GE', 33];
    }

    /** @return array<string, array<int, int>> */
    private function canonicalNorms(): array
    {
        $path = dirname(__DIR__, 3).'/database/seeders/data/ist.json';
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Canonical IST data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! isset($data['norms']) || ! is_array($data['norms'])) {
            throw new RuntimeException('Canonical IST norms are missing.');
        }

        return $data['norms'];
    }
}
