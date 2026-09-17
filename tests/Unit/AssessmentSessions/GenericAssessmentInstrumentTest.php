<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GenericAssessmentInstrumentTest extends TestCase
{
    #[DataProvider('genericInstruments')]
    public function test_it_accepts_only_generic_assessment_instruments(
        string $value,
        GenericAssessmentInstrument $expected,
    ): void {
        $this->assertSame($expected, GenericAssessmentInstrument::fromExternal($value));
    }

    /** @return iterable<string, array{string, GenericAssessmentInstrument}> */
    public static function genericInstruments(): iterable
    {
        yield 'IST' => ['ist', GenericAssessmentInstrument::Ist];
        yield 'PAPI' => ['papi', GenericAssessmentInstrument::Papi];
        yield 'RMIB' => ['rmib', GenericAssessmentInstrument::Rmib];
        yield 'Kraepelin' => ['kraepelin', GenericAssessmentInstrument::Kraepelin];
    }

    #[DataProvider('unsupportedInstruments')]
    public function test_it_rejects_dass21_and_unknown_instruments(string $value): void
    {
        $this->expectException(UnsupportedGenericAssessmentInstrument::class);

        GenericAssessmentInstrument::fromExternal($value);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedInstruments(): iterable
    {
        yield 'DASS-21 isolated path' => ['dass21'];
        yield 'unknown' => ['unknown'];
        yield 'case variant is not silently normalized' => ['IST'];
    }
}
