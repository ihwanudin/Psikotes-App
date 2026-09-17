<?php

declare(strict_types=1);

namespace App\Domain\Review;

use InvalidArgumentException;
use LogicException;

final readonly class G7ReviewSet
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /**
     * @param  array<string, G7AspectResolution>  $byAspect
     */
    private function __construct(private array $byAspect) {}

    /**
     * Creates a signing-ready set. Required G7 reviews must already be resolved.
     *
     * @param  array<mixed>  $resolutions
     */
    public static function fromResolutions(array $resolutions): self
    {
        if (! array_is_list($resolutions) || count($resolutions) !== count(self::ASPECTS)) {
            throw new InvalidArgumentException('A G7 review set must contain exactly 18 resolution artifacts.');
        }

        $byAspect = [];
        foreach ($resolutions as $resolution) {
            if (! $resolution instanceof G7AspectResolution) {
                throw new InvalidArgumentException('A G7 review set accepts only typed resolution artifacts.');
            }

            $aspect = $resolution->aspect();
            if (! in_array($aspect, self::ASPECTS, true) || array_key_exists($aspect, $byAspect)) {
                throw new InvalidArgumentException('A G7 review set contains a noncanonical or duplicate aspect.');
            }

            if (! $resolution->isResolved()) {
                throw new InvalidArgumentException('Every required G7 review must be resolved before signing.');
            }

            $byAspect[$aspect] = $resolution;
        }

        $canonicalOrder = [];
        foreach (self::ASPECTS as $aspect) {
            if (! array_key_exists($aspect, $byAspect)) {
                throw new InvalidArgumentException('A G7 review set is missing one or more canonical aspects.');
            }

            $canonicalOrder[$aspect] = $byAspect[$aspect];
        }

        return new self($canonicalOrder);
    }

    /** @return list<G7AspectResolution> */
    public function resolutions(): array
    {
        return array_values($this->byAspect);
    }

    public function resolutionFor(string $aspect): G7AspectResolution
    {
        if (! array_key_exists($aspect, $this->byAspect)) {
            throw new InvalidArgumentException('G7 aspect is not canonical.');
        }

        return $this->byAspect[$aspect];
    }

    /** @return array<string, int> */
    public function finalLevels(): array
    {
        $levels = [];
        foreach ($this->byAspect as $aspect => $resolution) {
            $finalLevel = $resolution->finalLevel();
            if ($finalLevel === null) {
                throw new LogicException('A signing-ready G7 review set cannot contain an unresolved aspect.');
            }

            $levels[$aspect] = $finalLevel;
        }

        return $levels;
    }
}
