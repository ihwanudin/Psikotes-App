<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use InvalidArgumentException;

final readonly class ReviewedEligibilityDecision
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /** @param array<mixed> $decision */
    private function __construct(private array $decision) {}

    /**
     * @param  array<mixed>  $levelOverrides
     * @param  array<mixed>|null  $labelOverride
     */
    public static function create(
        EligibilityDecisionSnapshot $baseline,
        array $levelOverrides,
        ?array $labelOverride = null,
    ): self {
        if (! array_is_list($levelOverrides)) {
            throw new InvalidArgumentException('Reviewed eligibility level overrides must be a list.');
        }

        $systemDecision = $baseline->toArray();
        $systemLevels = self::levelsFrom($systemDecision);
        $finalLevels = $systemLevels;
        $seenAspects = [];
        $canonicalOverridesByAspect = [];

        foreach ($levelOverrides as $override) {
            if (! is_array($override)) {
                throw new InvalidArgumentException('Reviewed eligibility level override is invalid.');
            }

            $canonical = self::canonicalLevelOverride($override);
            $aspect = $canonical['provenance']['aspect'];
            if (! $canonical['changed']) {
                throw new InvalidArgumentException('Reviewed eligibility level overrides must change a level.');
            }
            if (isset($seenAspects[$aspect]) || $canonical['system_level'] !== $systemLevels[$aspect]) {
                throw new InvalidArgumentException('Reviewed eligibility level override does not match its baseline.');
            }

            $seenAspects[$aspect] = true;
            $canonicalOverridesByAspect[$aspect] = $canonical;
            $finalLevels[$aspect] = $canonical['final_level'];
        }

        $canonicalOverrides = [];
        foreach (self::ASPECTS as $aspect) {
            if (isset($canonicalOverridesByAspect[$aspect])) {
                $canonicalOverrides[] = $canonicalOverridesByAspect[$aspect];
            }
        }

        $recalculated = $baseline->recalculateWithLevels($finalLevels)->toArray();
        $recommendation = $recalculated['recommendation'];

        if ($recommendation['publication_blocked'] === true) {
            if ($labelOverride !== null) {
                throw new InvalidArgumentException('A blocked eligibility decision cannot have a label override.');
            }
            $finalDecision = $recommendation;
        } else {
            $finalLabel = $recommendation['label'];
            if ($labelOverride !== null) {
                $canonicalLabelOverride = self::canonicalLabelOverride($labelOverride);
                if (! $canonicalLabelOverride['changed']) {
                    throw new InvalidArgumentException('Reviewed eligibility label override must change the label.');
                }
                if ($canonicalLabelOverride['system_label'] !== $finalLabel) {
                    throw new InvalidArgumentException('Reviewed eligibility label override does not match the recalculated label.');
                }
                $labelOverride = $canonicalLabelOverride;
                $finalLabel = $canonicalLabelOverride['final_label'];
            }

            $finalDecision = [
                'type' => 'reviewed_recommendation',
                'publication_blocked' => false,
                'system_label' => $recommendation['label'],
                'label' => $finalLabel,
            ];
        }

        return new self([
            'type' => 'reviewed_eligibility_decision',
            'publication_blocked' => $recalculated['publication_blocked'],
            'system_levels' => $systemLevels,
            'final_levels' => $finalLevels,
            'system_decision' => $systemDecision,
            'recalculated_decision' => $recalculated,
            'final_decision' => $finalDecision,
            'level_overrides' => $canonicalOverrides,
            'label_override' => $labelOverride,
        ]);
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return $this->decision;
    }

    /**
     * @param  array<mixed>  $decision
     * @return array<string, int>
     */
    private static function levelsFrom(array $decision): array
    {
        $levels = [];
        foreach (self::ASPECTS as $aspect) {
            $level = $decision['zone']['aspects'][$aspect]['level'] ?? null;
            if (! is_int($level)) {
                throw new InvalidArgumentException('Eligibility baseline levels are invalid.');
            }
            $levels[$aspect] = $level;
        }

        return $levels;
    }

    /**
     * @param  array<mixed>  $override
     * @return array<mixed>
     */
    private static function canonicalLevelOverride(array $override): array
    {
        $aspect = $override['provenance']['aspect'] ?? null;
        if (! is_string($aspect) || ! in_array($aspect, self::ASPECTS, true)) {
            throw new InvalidArgumentException('Reviewed eligibility level override aspect is invalid.');
        }

        $canonical = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => $aspect,
            'system_level' => $override['system_level'] ?? null,
            'final_level' => $override['final_level'] ?? null,
            'reason' => $override['reason'] ?? null,
        ]);
        if ($override !== $canonical) {
            throw new InvalidArgumentException('Reviewed eligibility level override is not an exact policy output.');
        }

        return $canonical;
    }

    /**
     * @param  array<mixed>  $override
     * @return array<mixed>
     */
    private static function canonicalLabelOverride(array $override): array
    {
        $canonical = (new ProfessionalOverridePolicy)->labelOverride([
            'system_label' => $override['system_label'] ?? null,
            'final_label' => $override['final_label'] ?? null,
            'reason' => $override['reason'] ?? null,
        ]);
        if ($override !== $canonical) {
            throw new InvalidArgumentException('Reviewed eligibility label override is not an exact policy output.');
        }

        return $canonical;
    }
}
