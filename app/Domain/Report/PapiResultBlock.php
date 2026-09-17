<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

final readonly class PapiResultBlock
{
    /**
     * Twenty Kostick scales. Sixteen feed the working-characteristics
     * clusters; G, I, X, and Z stay qualitative material for the
     * psychologist and never receive a level.
     *
     * @var list<string>
     */
    public const SCALES = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'I', 'K', 'L', 'N', 'O', 'P', 'R', 'S', 'T', 'V', 'W', 'X', 'Z'];

    /** @var list<string> */
    public const QUALITATIVE_SCALES = ['G', 'I', 'X', 'Z'];

    /** @param array<string, array{raw: int, level: int|null, qualitative: bool}> $scales */
    private function __construct(
        private array $scales,
    ) {}

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        if (! isset($input['scales']) || ! is_array($input['scales'])) {
            throw new InvalidArgumentException('PAPI result block input is invalid.');
        }

        if (count($input['scales']) !== count(self::SCALES)) {
            throw new InvalidArgumentException('PAPI result block must contain all twenty scales.');
        }

        $scales = [];
        foreach (self::SCALES as $code) {
            $row = $input['scales'][$code] ?? null;
            if (! is_array($row)
                || ! array_key_exists('raw', $row)
                || ! array_key_exists('level', $row)
                || ! array_key_exists('qualitative', $row)) {
                throw new InvalidArgumentException("PAPI result scale [{$code}] is invalid.");
            }

            if (! is_int($row['raw']) || $row['raw'] < 0 || $row['raw'] > 9) {
                throw new InvalidArgumentException("PAPI result scale [{$code}] raw score is invalid.");
            }

            $qualitative = $row['qualitative'] === true;
            if ($qualitative !== in_array($code, self::QUALITATIVE_SCALES, true)) {
                throw new InvalidArgumentException("PAPI result scale [{$code}] qualitative flag is inconsistent.");
            }

            $level = $row['level'];
            if ($qualitative) {
                if ($level !== null) {
                    throw new InvalidArgumentException("Qualitative PAPI scale [{$code}] must not carry a level.");
                }
            } elseif (! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException("PAPI result scale [{$code}] level is invalid.");
            }

            $scales[$code] = ['raw' => $row['raw'], 'level' => $level, 'qualitative' => $qualitative];
        }

        return new self($scales);
    }

    /** @return array<string, array{raw: int, level: int|null, qualitative: bool}> */
    public function toArray(): array
    {
        return $this->scales;
    }
}
