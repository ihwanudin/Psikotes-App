<?php

declare(strict_types=1);

namespace Tests\Unit\Retention;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    #[DataProvider('fiveYearDataClasses')]
    public function test_five_year_data_classes_expire_without_calendar_overflow(
        RetentionDataClass $dataClass,
    ): void {
        $anchor = new DateTimeImmutable('2024-02-29T10:20:30.123456+07:00');

        $expiresAt = (new RetentionPolicy)->expiresAt($dataClass, $anchor);

        $this->assertSame('2029-02-28T10:20:30.123456+07:00', $expiresAt->format('Y-m-d\TH:i:s.uP'));
    }

    /** @return iterable<string, array{RetentionDataClass}> */
    public static function fiveYearDataClasses(): iterable
    {
        yield 'psychotest raw' => [RetentionDataClass::PsychotestRaw];
        yield 'HPP' => [RetentionDataClass::Hpp];
        yield 'internal report' => [RetentionDataClass::InternalReport];
        yield 'audit' => [RetentionDataClass::Audit];
    }

    #[DataProvider('twoYearDataClasses')]
    public function test_two_year_data_classes_expire_without_calendar_overflow(
        RetentionDataClass $dataClass,
    ): void {
        $anchor = new DateTimeImmutable('2024-02-29T10:20:30.123456+07:00');

        $expiresAt = (new RetentionPolicy)->expiresAt($dataClass, $anchor);

        $this->assertSame('2026-02-28T10:20:30.123456+07:00', $expiresAt->format('Y-m-d\TH:i:s.uP'));
    }

    /** @return iterable<string, array{RetentionDataClass}> */
    public static function twoYearDataClasses(): iterable
    {
        yield 'DASS response' => [RetentionDataClass::DassResponse];
        yield 'DASS result' => [RetentionDataClass::DassResult];
    }

    public function test_proctor_media_expires_after_exactly_ninety_days(): void
    {
        $anchor = new DateTimeImmutable('2026-09-10T00:00:00.000000Z');

        $expiresAt = (new RetentionPolicy)->expiresAt(RetentionDataClass::ProctorMedia, $anchor);

        $this->assertSame('2026-12-09T00:00:00.000000+00:00', $expiresAt->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame(90, $anchor->diff($expiresAt)->days);
    }

    public function test_expiry_calculation_is_deterministic_and_does_not_mutate_the_anchor(): void
    {
        $anchor = new DateTimeImmutable('2026-03-15T08:09:10.111213+07:00');
        $policy = new RetentionPolicy;

        $first = $policy->expiresAt(RetentionDataClass::PsychotestRaw, $anchor);
        $second = $policy->expiresAt(RetentionDataClass::PsychotestRaw, $anchor);

        $this->assertEquals($first, $second);
        $this->assertSame('2026-03-15T08:09:10.111213+07:00', $anchor->format('Y-m-d\TH:i:s.uP'));
        $this->assertNotSame($anchor, $first);
    }

    public function test_data_class_vocabulary_is_exact_and_excludes_proctor_event_summaries(): void
    {
        $values = array_map(
            static fn (RetentionDataClass $dataClass): string => $dataClass->value,
            RetentionDataClass::cases(),
        );

        $this->assertSame([
            'PSYCHOTEST_RAW',
            'HPP',
            'INTERNAL_REPORT',
            'DASS_RESPONSE',
            'DASS_RESULT',
            'PROCTOR_MEDIA',
            'AUDIT',
        ], $values);
        $this->assertNotContains('PROCTOR_EVENT_SUMMARY', $values);
    }
}
