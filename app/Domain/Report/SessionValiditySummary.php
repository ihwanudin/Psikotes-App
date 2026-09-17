<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

/**
 * Session validity summary for the internal sheet: the ten SPEC §8 checks,
 * the resulting V1/V2/V3 status, and the mandatory procedure note for V2.
 * The checklist itself is never printed on the participant HPP.
 */
final readonly class SessionValiditySummary
{
    public const STATUS_V1 = 'V1';

    public const STATUS_V2 = 'V2';

    public const STATUS_V3 = 'V3';

    /** @var list<string> */
    public const ITEM_KEYS = [
        'identity_verified',
        'camera_active',
        'no_tab_switch',
        'no_second_person',
        'responses_complete',
        'subtest_time_plausible',
        'no_straight_lining',
        'connection_stable',
        'papi_social_desirability',
        'kraepelin_human_tempo',
    ];

    /** @var array<string, string> */
    public const ITEM_LABELS = [
        'identity_verified' => 'Verifikasi identitas',
        'camera_active' => 'Kamera aktif',
        'no_tab_switch' => 'Pindah tab/jendela',
        'no_second_person' => 'Suara/orang kedua',
        'responses_complete' => 'Kelengkapan respons',
        'subtest_time_plausible' => 'Waktu per subtes wajar',
        'no_straight_lining' => 'Pola tak seragam (straight-lining)',
        'connection_stable' => 'Kestabilan koneksi',
        'papi_social_desirability' => 'Social desirability PAPI',
        'kraepelin_human_tempo' => 'Tempo Kraepelin manusiawi',
    ];

    /** @param array<string, bool> $items */
    private function __construct(
        public string $status,
        private array $items,
        private ?string $procedureNote,
    ) {}

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        if (count($input) !== 3
            || ! array_key_exists('status', $input)
            || ! array_key_exists('items', $input)
            || ! array_key_exists('procedure_note', $input)) {
            throw new InvalidArgumentException('Session validity summary input is invalid.');
        }

        if (! is_string($input['status']) || ! in_array($input['status'], [self::STATUS_V1, self::STATUS_V2, self::STATUS_V3], true)) {
            throw new InvalidArgumentException('Session validity summary status is unknown.');
        }

        if (! is_array($input['items']) || count($input['items']) !== count(self::ITEM_KEYS)) {
            throw new InvalidArgumentException('Session validity summary must contain all ten checks.');
        }

        $items = [];
        foreach (self::ITEM_KEYS as $key) {
            $passed = $input['items'][$key] ?? null;
            if (! is_bool($passed)) {
                throw new InvalidArgumentException("Session validity check [{$key}] is invalid.");
            }

            $items[$key] = $passed;
        }

        $procedureNote = $input['procedure_note'];
        if ($procedureNote !== null && (! is_string($procedureNote) || trim($procedureNote) === '')) {
            throw new InvalidArgumentException('Session validity procedure note is invalid.');
        }

        if ($input['status'] === self::STATUS_V2 && $procedureNote === null) {
            throw new InvalidArgumentException('Session validity V2 requires a non-empty procedure note.');
        }

        return new self($input['status'], $items, $procedureNote);
    }

    public function isPublicationBlocked(): bool
    {
        return $this->status === self::STATUS_V3;
    }

    /** @return array{status: string, items: array<string, bool>, procedure_note: string|null} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'items' => $this->items,
            'procedure_note' => $this->procedureNote,
        ];
    }
}
