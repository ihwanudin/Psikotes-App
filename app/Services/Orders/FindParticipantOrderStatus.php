<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Data\Orders\ParticipantOrderStatus;
use App\Models\Order;

final readonly class FindParticipantOrderStatus
{
    public function handle(int $participantId): ?ParticipantOrderStatus
    {
        $order = Order::query()
            ->with('paymentMethod')
            ->where('participant_id', $participantId)
            ->latest('id')
            ->first();

        return $order === null ? null : ParticipantOrderStatus::fromOrder($order);
    }
}
