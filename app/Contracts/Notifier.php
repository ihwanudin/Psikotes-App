<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Notifications\ParticipantActivationNotification;

interface Notifier
{
    public function send(ParticipantActivationNotification $notification): void;

    public function channel(): string;
}
