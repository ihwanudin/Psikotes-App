<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

final readonly class AspectLevelEntry
{
    public const ZONE_OK = 'OK';

    public const ZONE_GREY = 'GREY';

    public const ZONE_BELUM = 'BELUM';

    /** @var list<string> */
    private const CRITICAL_ASPECTS = ['A1', 'B2', 'C4', 'C5'];

    private function __construct(
        public string $code,
        public string $labelId,
        public string $labelJp,
        public int $level,
        public ?int $standard,
    ) {}

    public static function create(
        string $code,
        string $labelId,
        string $labelJp,
        int $level,
        ?int $standard,
    ): self {
        if (preg_match('/^[ABCD][1-9]$/', $code) !== 1) {
            throw new InvalidArgumentException('Aspect code is invalid.');
        }

        if ($level < 1 || $level > 5) {
            throw new InvalidArgumentException('Aspect level must be within 1..5.');
        }

        if ($standard !== null && ($standard < 1 || $standard > 5)) {
            throw new InvalidArgumentException('Aspect standard must be null or within 1..5.');
        }

        if (trim($labelId) === '' || trim($labelJp) === '') {
            throw new InvalidArgumentException('Aspect labels must not be empty.');
        }

        return new self($code, $labelId, $labelJp, $level, $standard);
    }

    public function cluster(): string
    {
        return $this->code[0];
    }

    /**
     * Grey Area zone against the field standard: OK when the level meets the
     * standard, GREY when exactly one level below, BELUM from two levels below.
     * Aspects without a standard (non-target interest areas) carry no zone.
     */
    public function zone(): ?string
    {
        if ($this->standard === null) {
            return null;
        }

        return match (true) {
            $this->level >= $this->standard => self::ZONE_OK,
            $this->level === $this->standard - 1 => self::ZONE_GREY,
            default => self::ZONE_BELUM,
        };
    }

    public function isCritical(): bool
    {
        return in_array($this->code, self::CRITICAL_ASPECTS, true);
    }

    /**
     * Participant-facing HPP projection: aspect codes, standards, and any raw
     * provenance must never be printed on the participant report.
     *
     * @return array{label_id: string, label_jp: string, level: int, zone: string|null}
     */
    public function toHppRow(): array
    {
        return [
            'label_id' => $this->labelId,
            'label_jp' => $this->labelJp,
            'level' => $this->level,
            'zone' => $this->zone(),
        ];
    }

    /** @return array{code: string, cluster: string, label_id: string, label_jp: string, level: int, standard: int|null, zone: string|null, critical: bool} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'cluster' => $this->cluster(),
            'label_id' => $this->labelId,
            'label_jp' => $this->labelJp,
            'level' => $this->level,
            'standard' => $this->standard,
            'zone' => $this->zone(),
            'critical' => $this->isCritical(),
        ];
    }
}
