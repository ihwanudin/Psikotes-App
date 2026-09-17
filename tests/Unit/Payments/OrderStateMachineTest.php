<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Services\Payments\Exceptions\InvalidOrderTransition;
use App\Services\Payments\OrderStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderStateMachineTest extends TestCase
{
    #[DataProvider('providerStatuses')]
    public function test_each_provider_status_is_idempotent_when_replayed(PaymentStatus $providerStatus): void
    {
        $machine = new OrderStateMachine;

        $first = $machine->apply(OrderStatus::Pending, $providerStatus);
        $replay = $machine->apply($first->status, $providerStatus);

        $this->assertSame($providerStatus->value, $first->status->value);
        $this->assertSame($first->status, $replay->status);
        $this->assertFalse($replay->changed);
        $this->assertFalse($replay->unlocksEntitlements);
    }

    public function test_only_the_first_valid_paid_transition_unlocks_entitlements(): void
    {
        $machine = new OrderStateMachine;

        $transition = $machine->apply(OrderStatus::Pending, PaymentStatus::Paid);

        $this->assertSame(OrderStatus::Paid, $transition->status);
        $this->assertTrue($transition->changed);
        $this->assertTrue($transition->unlocksEntitlements);
    }

    #[DataProvider('invalidTerminalTransitions')]
    public function test_terminal_status_cannot_be_reordered(
        OrderStatus $current,
        PaymentStatus $incoming,
    ): void {
        $this->expectException(InvalidOrderTransition::class);

        (new OrderStateMachine)->apply($current, $incoming);
    }

    /** @return iterable<string, array{PaymentStatus}> */
    public static function providerStatuses(): iterable
    {
        foreach (PaymentStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    /** @return iterable<string, array{OrderStatus, PaymentStatus}> */
    public static function invalidTerminalTransitions(): iterable
    {
        yield 'paid cannot expire' => [OrderStatus::Paid, PaymentStatus::Expired];
        yield 'paid cannot cancel' => [OrderStatus::Paid, PaymentStatus::Cancelled];
        yield 'expired cannot become paid' => [OrderStatus::Expired, PaymentStatus::Paid];
        yield 'cancelled cannot become paid' => [OrderStatus::Cancelled, PaymentStatus::Paid];
        yield 'manually rejected cannot become paid' => [OrderStatus::Rejected, PaymentStatus::Paid];
    }
}
