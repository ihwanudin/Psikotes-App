<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\RlsContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RlsContextTest extends TestCase
{
    public function test_participant_context_requires_both_tenant_and_participant_identifiers(): void
    {
        $context = new RlsContext('participant', 12, 34);

        $this->assertSame('participant', $context->role);
        $this->assertSame(12, $context->branchId);
        $this->assertSame(34, $context->participantId);
    }

    #[DataProvider('invalidContexts')]
    public function test_invalid_or_incomplete_context_is_rejected(
        string $role,
        ?int $branchId,
        ?int $participantId,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new RlsContext($role, $branchId, $participantId);
    }

    /**
     * @return iterable<string, array{string, ?int, ?int}>
     */
    public static function invalidContexts(): iterable
    {
        yield 'unknown role' => ['owner', null, null];
        yield 'branch admin without branch' => ['branch_admin', null, null];
        yield 'staff without branch' => ['staff', null, null];
        yield 'participant without branch' => ['participant', null, 4];
        yield 'participant without participant id' => ['participant', 2, null];
        yield 'zero branch id' => ['branch_admin', 0, null];
        yield 'negative participant id' => ['participant', 2, -1];
    }
}
