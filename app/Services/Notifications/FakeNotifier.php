<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\Notifier;
use App\Data\Notifications\ParticipantActivationNotification;

final class FakeNotifier implements Notifier
{
    /** @var array<string, ParticipantActivationNotification> */
    private array $notifications = [];

    public function send(ParticipantActivationNotification $notification): void
    {
        $this->notifications[$notification->idempotencyKey] ??= $notification;
    }

    public function channel(): string
    {
        return 'fake';
    }

    /** @return list<ParticipantActivationNotification> */
    public function delivered(): array
    {
        return array_values($this->notifications);
    }
}
