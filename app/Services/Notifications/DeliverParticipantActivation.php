<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\Notifier;
use App\Data\Notifications\ParticipantActivationNotification;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Security\RlsContextRunner;
use App\Services\Notifications\Exceptions\NotificationDeliveryFailed;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class DeliverParticipantActivation
{
    public function __construct(
        private RlsContextRunner $runner,
        private Notifier $notifier,
        private LoggerInterface $logger,
        private RetentionPolicy $retention,
    ) {}

    public function handle(string $messageId): void
    {
        $claim = $this->claim($messageId);

        if ($claim === null) {
            return;
        }

        $channel = $this->notifier->channel();
        $failure = $claim['error_code'] === null
            ? $this->validChannelFailure($channel)
            : new NotificationDeliveryFailed($claim['error_code']);

        if ($failure !== null) {
            $this->markFailed($claim, $channel, $failure);

            throw $failure;
        }

        try {
            $notification = $claim['notification'];

            if (! $notification instanceof ParticipantActivationNotification) {
                throw new NotificationDeliveryFailed('notification_payload_invalid');
            }

            $this->notifier->send($notification);
        } catch (NotificationDeliveryFailed $exception) {
            $this->markFailed($claim, $channel, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $failure = new NotificationDeliveryFailed('notifier_unexpected_failure', $exception);
            $this->markFailed($claim, $channel, $failure);

            throw $failure;
        }

        $this->markDelivered($claim, $channel);
    }

    /**
     * @return array{
     *     message_id: string,
     *     notification: ParticipantActivationNotification|null,
     *     error_code: string|null,
     *     topic: string,
     *     order_public_id: string,
     *     branch_id: int|null,
     *     attempt: int
     * }|null
     */
    private function claim(string $messageId): ?array
    {
        return $this->runner->runAsService(function () use ($messageId): ?array {
            $message = OutboxMessage::query()
                ->where('message_id', $messageId)
                ->lockForUpdate()
                ->first();

            if ($message === null
                || $message->status === 'processed'
                || $message->attempts >= 5
                || $message->available_at->isFuture()
                || ! $message->expires_at->isFuture()
                || ($message->status === 'processing'
                    && $message->updated_at->isAfter(now()->subMinutes(10)))) {
                return null;
            }

            if (! in_array($message->status, ['pending', 'failed', 'processing'], true)) {
                return null;
            }

            $message->forceFill([
                'status' => 'processing',
                'attempts' => $message->attempts + 1,
                'last_error' => null,
            ])->save();

            $order = $message->aggregate_type === Order::class
                ? Order::query()
                    ->with(['participant', 'entitlements'])
                    ->where('public_id', $message->aggregate_id)
                    ->first()
                : null;
            $errorCode = $this->claimErrorCode($message, $order);

            try {
                $testTypes = [];

                foreach ($order?->entitlements->where('status', 'ready')->sortBy('id') ?? [] as $entitlement) {
                    $testTypes[] = $entitlement->test_type;
                }

                $notification = new ParticipantActivationNotification(
                    idempotencyKey: $message->message_id,
                    phone: (string) $order?->participant?->phone,
                    testNumber: (string) $order?->participant?->test_number,
                    testTypes: $testTypes,
                );
            } catch (InvalidArgumentException) {
                $notification = null;
                $errorCode ??= 'notification_payload_invalid';
            }

            return [
                'message_id' => $message->message_id,
                'notification' => $notification,
                'error_code' => $errorCode,
                'topic' => $message->topic,
                'order_public_id' => $message->aggregate_id,
                'branch_id' => $order?->participant?->branch_id,
                'attempt' => $message->attempts,
            ];
        });
    }

    private function claimErrorCode(OutboxMessage $message, ?Order $order): ?string
    {
        if ($message->topic !== 'participant.activation'
            || ($message->payload['schema_version'] ?? null) !== 1
            || $message->aggregate_type !== Order::class) {
            return 'notification_contract_invalid';
        }

        if ($order === null || $order->status !== OrderStatus::Paid) {
            return 'notification_order_not_paid';
        }

        return null;
    }

    private function validChannelFailure(string $channel): ?NotificationDeliveryFailed
    {
        return preg_match('/^[a-z0-9_-]{1,32}$/', $channel)
            ? null
            : new NotificationDeliveryFailed('notifier_channel_invalid');
    }

    /**
     * @param  array{message_id: string, notification: ParticipantActivationNotification|null, error_code: string|null, topic: string, order_public_id: string, branch_id: int|null, attempt: int}  $claim
     */
    private function markDelivered(array $claim, string $channel): void
    {
        $changed = $this->mark($claim, 'processed', $channel, null);

        if ($changed) {
            $this->logger->info('participant_notification_delivery', [
                'message_id' => $claim['message_id'],
                'topic' => $claim['topic'],
                'channel' => $channel,
                'attempt' => $claim['attempt'],
                'outcome' => 'delivered',
            ]);
        }
    }

    /**
     * @param  array{message_id: string, notification: ParticipantActivationNotification|null, error_code: string|null, topic: string, order_public_id: string, branch_id: int|null, attempt: int}  $claim
     */
    private function markFailed(
        array $claim,
        string $channel,
        NotificationDeliveryFailed $failure,
    ): void {
        $changed = $this->mark($claim, 'failed', $channel, $failure->errorCode);

        if ($changed) {
            $this->logger->warning('participant_notification_delivery', [
                'message_id' => $claim['message_id'],
                'topic' => $claim['topic'],
                'channel' => $channel,
                'attempt' => $claim['attempt'],
                'outcome' => 'failed',
                'error_code' => $failure->errorCode,
            ]);
        }
    }

    /**
     * @param  array{message_id: string, notification: ParticipantActivationNotification|null, error_code: string|null, topic: string, order_public_id: string, branch_id: int|null, attempt: int}  $claim
     */
    private function mark(
        array $claim,
        string $status,
        string $channel,
        ?string $errorCode,
    ): bool {
        return $this->runner->runAsService(function () use ($claim, $status, $channel, $errorCode): bool {
            $message = OutboxMessage::query()
                ->where('message_id', $claim['message_id'])
                ->lockForUpdate()
                ->first();

            if ($message === null
                || $message->status !== 'processing'
                || $message->attempts !== $claim['attempt']) {
                return false;
            }

            $now = now()->utc()->toImmutable();
            $message->forceFill([
                'status' => $status,
                'processed_at' => $status === 'processed' ? $now : null,
                'available_at' => $status === 'failed'
                    ? $now->addSeconds($this->retryDelay($claim['attempt']))
                    : $message->available_at,
                'last_error' => $errorCode,
            ])->save();

            DB::table('audit_logs')->insert([
                'branch_id' => $claim['branch_id'],
                'actor_type' => 'service',
                'actor_id' => null,
                'action' => $status === 'processed'
                    ? 'participant_notification.delivered'
                    : 'participant_notification.failed',
                'subject_type' => Order::class,
                'subject_id' => $claim['order_public_id'],
                'context' => json_encode(array_filter([
                    'topic' => $claim['topic'],
                    'channel' => $channel,
                    'attempt' => $claim['attempt'],
                    'error_code' => $errorCode,
                ], fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $now),
            ]);

            return true;
        });
    }

    private function retryDelay(int $attempt): int
    {
        return match ($attempt) {
            1 => 30,
            2 => 120,
            3 => 600,
            default => 1_800,
        };
    }
}
