<?php

declare(strict_types=1);

namespace App\Domain\Review;

use InvalidArgumentException;

final class ProfessionalOverridePolicy
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /** @var list<string> */
    private const LABELS = ['DISARANKAN', 'DIPERTIMBANGKAN', 'TIDAK_DISARANKAN'];

    /**
     * @param  array<mixed>  $input
     * @return array{
     *     type: 'professional_override',
     *     changed: bool,
     *     audit_required: bool,
     *     recalculation_required: bool,
     *     system_level: int,
     *     final_level: int,
     *     reason: string|null,
     *     provenance: array{policy: 'G6', override_type: 'level', aspect: string, reason_character_count: int}
     * }
     */
    public function levelOverride(array $input): array
    {
        if (! $this->hasExactKeys($input, ['aspect', 'system_level', 'final_level', 'reason'])
            || ! is_string($input['aspect'])
            || ! in_array($input['aspect'], self::ASPECTS, true)
            || ! is_int($input['system_level'])
            || $input['system_level'] < 1
            || $input['system_level'] > 5
            || ! is_int($input['final_level'])
            || $input['final_level'] < 1
            || $input['final_level'] > 5
            || ($input['reason'] !== null && ! is_string($input['reason']))) {
            throw new InvalidArgumentException('Professional level override input is invalid.');
        }

        $changed = $input['system_level'] !== $input['final_level'];
        $reason = $this->validatedReason($changed, $input['reason']);

        return [
            'type' => 'professional_override',
            'changed' => $changed,
            'audit_required' => $changed,
            'recalculation_required' => $changed,
            'system_level' => $input['system_level'],
            'final_level' => $input['final_level'],
            'reason' => $reason,
            'provenance' => [
                'policy' => 'G6',
                'override_type' => 'level',
                'aspect' => $input['aspect'],
                'reason_character_count' => $reason === null ? 0 : mb_strlen($reason),
            ],
        ];
    }

    /**
     * @param  array<mixed>  $input
     * @return array{
     *     type: 'professional_override',
     *     changed: bool,
     *     audit_required: bool,
     *     recalculation_required: bool,
     *     system_label: 'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN',
     *     final_label: 'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN',
     *     reason: string|null,
     *     provenance: array{policy: 'G6', override_type: 'label', aspect: null, reason_character_count: int}
     * }
     */
    public function labelOverride(array $input): array
    {
        if (! $this->hasExactKeys($input, ['system_label', 'final_label', 'reason'])
            || ! is_string($input['system_label'])
            || ! in_array($input['system_label'], self::LABELS, true)
            || ! is_string($input['final_label'])
            || ! in_array($input['final_label'], self::LABELS, true)
            || ($input['reason'] !== null && ! is_string($input['reason']))) {
            throw new InvalidArgumentException('Professional label override input is invalid.');
        }

        $changed = $input['system_label'] !== $input['final_label'];
        $reason = $this->validatedReason($changed, $input['reason']);

        return [
            'type' => 'professional_override',
            'changed' => $changed,
            'audit_required' => $changed,
            'recalculation_required' => false,
            'system_label' => $input['system_label'],
            'final_label' => $input['final_label'],
            'reason' => $reason,
            'provenance' => [
                'policy' => 'G6',
                'override_type' => 'label',
                'aspect' => null,
                'reason_character_count' => $reason === null ? 0 : mb_strlen($reason),
            ],
        ];
    }

    private function validatedReason(bool $changed, ?string $reason): ?string
    {
        $canonicalReason = $this->canonicalReason($reason);

        if (! $changed) {
            if ($canonicalReason !== null && $canonicalReason !== '') {
                throw new InvalidArgumentException('An unchanged override must not contain a reason.');
            }
            $canonicalReason = null;
        } elseif ($canonicalReason === null || mb_strlen($canonicalReason) < 20) {
            throw new InvalidArgumentException('A changed override requires a reason of at least 20 characters.');
        }

        return $canonicalReason;
    }

    private function canonicalReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        if (! mb_check_encoding($reason, 'UTF-8')) {
            throw new InvalidArgumentException('Professional override reason must be valid UTF-8.');
        }

        $trimmed = preg_replace('/^\s+|\s+$/u', '', $reason);
        if (! is_string($trimmed)) {
            throw new InvalidArgumentException('Professional override reason is invalid.');
        }

        return $trimmed;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }
}
