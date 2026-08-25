<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Data\Notifications\ParticipantActivationNotification;
use App\Services\Notifications\FakeNotifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NotifierContractTest extends TestCase
{
    public function test_fake_notifier_delivers_each_idempotency_key_once(): void
    {
        $notifier = new FakeNotifier;
        $notification = $this->notification();

        $notifier->send($notification);
        $notifier->send($notification);

        $this->assertSame('fake', $notifier->channel());
        $this->assertCount(1, $notifier->delivered());
        $this->assertSame($notification, $notifier->delivered()[0]);
    }

    public function test_activation_notification_rejects_invalid_or_duplicate_fields(): void
    {
        foreach ([
            ['phone' => 'not-a-phone'],
            ['testNumber' => ''],
            ['testTypes' => ['ist', 'ist']],
            ['testTypes' => ['unknown']],
            ['idempotencyKey' => 'not-an-ulid'],
        ] as $override) {
            try {
                $this->notification($override);
                $this->fail('Invalid notification data should be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, mixed> $override */
    private function notification(array $override = []): ParticipantActivationNotification
    {
        return new ParticipantActivationNotification(
            idempotencyKey: $override['idempotencyKey'] ?? '01K3H9M5YXB62D9QK7E5V2G8Z1',
            phone: $override['phone'] ?? '+6281234567890',
            testNumber: $override['testNumber'] ?? 'LSI-202608-000001-ABCDEF',
            testTypes: $override['testTypes'] ?? ['ist', 'papi'],
        );
    }
}
