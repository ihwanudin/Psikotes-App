<?php

declare(strict_types=1);

namespace App\Data;

use InvalidArgumentException;

final readonly class IdentityMatchResult
{
    private const OUTCOMES = ['pending', 'match', 'mismatch', 'error'];

    public function __construct(
        public string $outcome,
        public ?float $confidence = null,
        public ?string $marker = null,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Unsupported identity match outcome.');
        }

        if ($confidence !== null && ($confidence < 0 || $confidence > 1)) {
            throw new InvalidArgumentException('Identity match confidence must be between zero and one.');
        }

        if ($marker !== null && mb_strlen($marker) > 120) {
            throw new InvalidArgumentException('Identity match marker may not exceed 120 characters.');
        }
    }

    public static function pending(?string $marker = 'manual-review-required'): self
    {
        return new self('pending', null, $marker);
    }

    public static function match(float $confidence, ?string $marker = null): self
    {
        return new self('match', $confidence, $marker);
    }

    public static function mismatch(float $confidence, ?string $marker = null): self
    {
        return new self('mismatch', $confidence, $marker);
    }

    public static function error(string $marker = 'matcher-error'): self
    {
        return new self('error', null, $marker);
    }
}
