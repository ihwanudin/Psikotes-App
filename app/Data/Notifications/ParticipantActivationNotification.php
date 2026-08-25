<?php

declare(strict_types=1);

namespace App\Data\Notifications;

use InvalidArgumentException;

final readonly class ParticipantActivationNotification
{
    private const array TEST_TYPES = ['ist', 'papi', 'rmib', 'kraepelin', 'dass21'];

    /** @param list<string> $testTypes */
    public function __construct(
        public string $idempotencyKey,
        public string $phone,
        public string $testNumber,
        public array $testTypes,
    ) {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $idempotencyKey)
            || ! preg_match('/^\+?[0-9]{8,15}$/', $phone)
            || $testNumber === ''
            || strlen($testNumber) > 32
            || $testTypes === []
            || count($testTypes) !== count(array_unique($testTypes))) {
            throw new InvalidArgumentException('Participant activation notification is invalid.');
        }

        foreach ($testTypes as $testType) {
            if (! in_array($testType, self::TEST_TYPES, true)) {
                throw new InvalidArgumentException('Participant activation notification is invalid.');
            }
        }
    }
}
