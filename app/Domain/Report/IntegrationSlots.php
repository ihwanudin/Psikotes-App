<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

/**
 * Seven-slot integration narrative draft (S1..S7). Internal-sheet material:
 * the psychologist condenses it into the HPP cluster narrative before
 * signing; it is never printed verbatim on the participant report.
 */
final readonly class IntegrationSlots
{
    /** @var list<string> */
    public const SLOTS = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7'];

    /** @var array<string, array{title_id: string, title_jp: string}> */
    public const SLOT_TITLES = [
        'S1' => ['title_id' => 'Gambaran Umum', 'title_jp' => '全体的な様子'],
        'S2' => ['title_id' => 'Sikap & Cara Kerja', 'title_jp' => '勤務態度・仕事の進め方'],
        'S3' => ['title_id' => 'Kepribadian', 'title_jp' => 'パーソナリティ'],
        'S4' => ['title_id' => 'Kekuatan Utama', 'title_jp' => '主な強み'],
        'S5' => ['title_id' => 'Area Pengembangan', 'title_jp' => '育成すべき領域'],
        'S6' => ['title_id' => 'Kesesuaian Bidang', 'title_jp' => '分野適合性'],
        'S7' => ['title_id' => 'Saran Penempatan', 'title_jp' => '配置に関する助言'],
    ];

    /** @param array<string, array{text_id: string, text_jp: string|null}> $slots */
    private function __construct(
        private array $slots,
    ) {}

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        if (count($input) !== count(self::SLOTS)) {
            throw new InvalidArgumentException('Integration slots must contain exactly S1..S7.');
        }

        $slots = [];
        foreach (self::SLOTS as $code) {
            $row = $input[$code] ?? null;
            if (! is_array($row) || ! isset($row['text_id'])) {
                throw new InvalidArgumentException("Integration slot [{$code}] is invalid.");
            }

            if (! is_string($row['text_id']) || trim($row['text_id']) === '') {
                throw new InvalidArgumentException("Integration slot [{$code}] Indonesian text is invalid.");
            }

            $japanese = $row['text_jp'] ?? null;
            if ($japanese !== null && (! is_string($japanese) || trim($japanese) === '')) {
                throw new InvalidArgumentException("Integration slot [{$code}] Japanese text is invalid.");
            }

            $slots[$code] = ['text_id' => $row['text_id'], 'text_jp' => $japanese];
        }

        return new self($slots);
    }

    /**
     * @return list<array{code: string, title_id: string, title_jp: string, text_id: string, text_jp: string|null}>
     */
    public function toArray(): array
    {
        $rows = [];
        foreach (self::SLOTS as $code) {
            $rows[] = [
                'code' => $code,
                'title_id' => self::SLOT_TITLES[$code]['title_id'],
                'title_jp' => self::SLOT_TITLES[$code]['title_jp'],
                'text_id' => $this->slots[$code]['text_id'],
                'text_jp' => $this->slots[$code]['text_jp'],
            ];
        }

        return $rows;
    }
}
