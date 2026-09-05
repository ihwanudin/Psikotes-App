<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use InvalidArgumentException;

/** Internal zero-price routing result; never settlement authority or an HTTP response. */
final readonly class CheckoutZeroPriceResult
{
    /** @var list<string> */
    public array $activatedTestTypes;

    /** @param array<array-key, mixed> $activatedTestTypes */
    public function __construct(
        public string $state,
        array $activatedTestTypes,
    ) {
        if (! in_array($state, ['settled', 'not_applicable'], true)
            || ($state === 'not_applicable' && $activatedTestTypes !== [])) {
            throw new InvalidArgumentException('Invalid zero-price checkout result.');
        }
        foreach ($activatedTestTypes as $type) {
            if (! is_string($type) || trim($type) === '') {
                throw new InvalidArgumentException('Invalid zero-price checkout result.');
            }
        }
        $this->activatedTestTypes = array_values($activatedTestTypes);
    }
}
