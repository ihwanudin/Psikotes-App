<?php

declare(strict_types=1);

namespace App\Domain\Narrative;

use InvalidArgumentException;

/** @phpstan-type EvidenceRow array{aspect: string, zone: 'OK'|'GREY'|'BELUM'|null, risk_note_required: bool} */
final readonly class IntegrationTargetInterestRiskEvidence
{
    /** @var list<string> */
    private const ASPECTS = ['D1', 'D2', 'D3', 'D4', 'D5'];

    /** @var array<string, string|null> */
    private const TARGET_ASPECTS = [
        'KAIGO' => 'D4',
        'KENSETSU' => 'D3',
        'NOUGYOU' => 'D1',
        'SEIZOU' => 'D2',
        'GAISHOKU' => 'D5',
        'UMUM' => null,
    ];

    /**
     * @param  array<mixed>  $aspects
     * @return list<EvidenceRow>
     */
    public function derive(string $fieldCode, array $aspects): array
    {
        if (! array_key_exists($fieldCode, self::TARGET_ASPECTS)) {
            throw new InvalidArgumentException('Integration target-interest field is invalid.');
        }

        $validated = $this->validateAspects($aspects);
        $targetAspect = self::TARGET_ASPECTS[$fieldCode];

        if ($targetAspect === null) {
            foreach ($validated as $aspect) {
                if ($aspect['standard'] !== null || $aspect['zone'] !== null) {
                    throw new InvalidArgumentException('UMUM target interests must all be unassessed.');
                }
            }

            return $this->evidenceRows($validated, null);
        }

        foreach ($validated as $aspect) {
            $isTarget = $aspect['aspect'] === $targetAspect;
            if ($isTarget) {
                if ($aspect['standard'] !== 3 || $aspect['zone'] === null) {
                    throw new InvalidArgumentException('The configured target interest must be assessed at standard three.');
                }

                continue;
            }

            if ($aspect['standard'] !== null || $aspect['zone'] !== null) {
                throw new InvalidArgumentException('Only the configured target interest may be assessed.');
            }
        }

        return $this->evidenceRows($validated, $targetAspect);
    }

    /**
     * @param  list<array{aspect: string, level: int, standard: 3|null, zone: 'OK'|'GREY'|'BELUM'|null, review_required: bool}>  $aspects
     * @return list<EvidenceRow>
     */
    private function evidenceRows(array $aspects, ?string $targetAspect): array
    {
        return array_map(
            static function (array $aspect) use ($targetAspect): array {
                $zone = $aspect['aspect'] === $targetAspect ? $aspect['zone'] : null;

                return [
                    'aspect' => $aspect['aspect'],
                    'zone' => $zone,
                    'risk_note_required' => in_array($zone, ['GREY', 'BELUM'], true),
                ];
            },
            $aspects,
        );
    }

    /**
     * @param  array<mixed>  $aspects
     * @return list<array{
     *     aspect: string,
     *     level: int,
     *     standard: 3|null,
     *     zone: 'OK'|'GREY'|'BELUM'|null,
     *     review_required: bool
     * }>
     */
    private function validateAspects(array $aspects): array
    {
        if (! array_is_list($aspects) || count($aspects) !== count(self::ASPECTS)) {
            throw new InvalidArgumentException('Integration target-interest evidence requires D1-D5 exactly once.');
        }

        $validated = [];
        foreach ($aspects as $position => $record) {
            if (! is_array($record)
                || ! $this->hasExactKeys($record, ['aspect', 'level', 'standard', 'zone', 'review_required'])) {
                throw new InvalidArgumentException('Integration target-interest record shape is invalid.');
            }

            $aspect = $record['aspect'];
            $level = $record['level'];
            $standard = $record['standard'];
            $zone = $record['zone'];
            $reviewRequired = $record['review_required'];

            if (! is_string($aspect) || $aspect !== self::ASPECTS[$position]) {
                throw new InvalidArgumentException('Integration target interests must use canonical D1-D5 order.');
            }
            if (! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException('Integration target-interest level is invalid.');
            }
            if ($standard !== null && (! is_int($standard) || $standard !== 3)) {
                throw new InvalidArgumentException('Integration target-interest standard is invalid.');
            }
            if ($zone !== null && (! is_string($zone) || ! in_array($zone, ['OK', 'GREY', 'BELUM'], true))) {
                throw new InvalidArgumentException('Integration target-interest zone is invalid.');
            }
            if (($standard === null) !== ($zone === null)) {
                throw new InvalidArgumentException('Integration target-interest standard and zone are inconsistent.');
            }
            if ($standard === 3 && $zone !== $this->zoneFor($level)) {
                throw new InvalidArgumentException('Integration target-interest level contradicts its zone.');
            }
            if (! is_bool($reviewRequired)) {
                throw new InvalidArgumentException('Integration target-interest review flag must be boolean.');
            }
            if ($reviewRequired) {
                throw new InvalidArgumentException('Integration target-interest review omissions must be resolved upstream.');
            }

            /** @var 'OK'|'GREY'|'BELUM'|null $zone */
            $validated[] = [
                'aspect' => $aspect,
                'level' => $level,
                'standard' => $standard,
                'zone' => $zone,
                'review_required' => $reviewRequired,
            ];
        }

        return $validated;
    }

    /** @return 'OK'|'GREY'|'BELUM' */
    private function zoneFor(int $level): string
    {
        return match (true) {
            $level >= 3 => 'OK',
            $level === 2 => 'GREY',
            default => 'BELUM',
        };
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expected
     */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }
}
