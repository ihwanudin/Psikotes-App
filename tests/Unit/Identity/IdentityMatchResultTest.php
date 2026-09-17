<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use App\Data\IdentityMatchResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IdentityMatchResultTest extends TestCase
{
    public function test_matcher_result_is_only_a_bounded_review_marker(): void
    {
        $result = IdentityMatchResult::mismatch(0.21, 'faces-differ');

        $this->assertSame('mismatch', $result->outcome);
        $this->assertSame(0.21, $result->confidence);
        $this->assertSame('faces-differ', $result->marker);
    }

    public function test_confidence_outside_zero_to_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdentityMatchResult::match(1.01);
    }
}
