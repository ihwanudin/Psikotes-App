<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class IstRawScoreCalculator
{
    /** @var array<string, string> */
    private array $answerKeys;

    /** @var array<int, array<string, int>> */
    private array $geScores;

    /** @var array<string, int> */
    private array $emptyScores;

    /**
     * @param  array<mixed>  $keys
     * @param  array<mixed>  $geDictionary
     */
    public function __construct(array $keys, array $geDictionary)
    {
        $answerKeys = [];
        $geScores = [];
        $emptyScores = [];

        foreach ($keys as $definition) {
            if (! is_array($definition)
                || ! isset($definition['subtest'], $definition['item'], $definition['key'])
                || ! is_string($definition['subtest'])
                || $definition['subtest'] === ''
                || $definition['subtest'] === 'GE'
                || ! is_int($definition['item'])
                || $definition['item'] < 1
                || ! is_string($definition['key'])) {
                throw new InvalidArgumentException('IST key definition is invalid.');
            }

            $coordinate = self::coordinate($definition['subtest'], $definition['item']);

            if (array_key_exists($coordinate, $answerKeys)) {
                throw new InvalidArgumentException('IST key item is duplicated.');
            }

            $answerKeys[$coordinate] = $definition['key'];
            $emptyScores[$definition['subtest']] = 0;
        }

        foreach ($geDictionary as $definition) {
            if (! is_array($definition)
                || ! isset($definition['item'], $definition['answers'])
                || ! is_int($definition['item'])
                || $definition['item'] < 1
                || ! is_array($definition['answers'])) {
                throw new InvalidArgumentException('IST GE definition is invalid.');
            }

            $item = $definition['item'];

            if (array_key_exists($item, $geScores)) {
                throw new InvalidArgumentException('IST GE item is duplicated.');
            }

            $answers = [];

            foreach ($definition['answers'] as $answerDefinition) {
                if (! is_array($answerDefinition)
                    || ! isset($answerDefinition['answer'], $answerDefinition['score'])
                    || ! is_string($answerDefinition['answer'])
                    || trim($answerDefinition['answer']) === ''
                    || ! is_int($answerDefinition['score'])
                    || ! in_array($answerDefinition['score'], [0, 1, 2], true)) {
                    throw new InvalidArgumentException('IST GE answer definition is invalid.');
                }

                $answer = self::normalizeGeAnswer($answerDefinition['answer']);

                if (array_key_exists($answer, $answers)) {
                    throw new InvalidArgumentException('IST GE answer is duplicated after normalization.');
                }

                $answers[$answer] = $answerDefinition['score'];
            }

            $geScores[$item] = $answers;
            $emptyScores['GE'] = 0;
        }

        if ($answerKeys === [] && $geScores === []) {
            throw new InvalidArgumentException('IST scoring data must contain at least one item.');
        }

        $this->answerKeys = $answerKeys;
        $this->geScores = $geScores;
        $this->emptyScores = $emptyScores;
    }

    /**
     * @param  array<mixed>  $responses
     * @return array<string, int>
     */
    public function calculate(array $responses): array
    {
        $scores = $this->emptyScores;
        $seen = [];

        foreach ($responses as $response) {
            if (! is_array($response)
                || ! isset($response['subtest'], $response['item'], $response['answer'])
                || ! is_string($response['subtest'])
                || ! is_int($response['item'])
                || ! is_string($response['answer'])) {
                throw new InvalidArgumentException(
                    'IST response must contain string subtest and answer plus an integer item.',
                );
            }

            $coordinate = self::coordinate($response['subtest'], $response['item']);

            if (array_key_exists($coordinate, $seen)) {
                throw new InvalidArgumentException('IST response item is duplicated.');
            }

            if ($response['subtest'] === 'GE') {
                if (! array_key_exists($response['item'], $this->geScores)) {
                    throw new InvalidArgumentException('IST response item is outside the supplied scoring data.');
                }

                $answer = self::normalizeGeAnswer($response['answer']);
                $scores['GE'] += $this->geScores[$response['item']][$answer] ?? 0;
            } else {
                if (! array_key_exists($coordinate, $this->answerKeys)) {
                    throw new InvalidArgumentException('IST response item is outside the supplied scoring data.');
                }

                $scores[$response['subtest']] += (int) ($response['answer'] === $this->answerKeys[$coordinate]);
            }

            $seen[$coordinate] = true;
        }

        if (count($seen) !== count($this->answerKeys) + count($this->geScores)) {
            throw new InvalidArgumentException('IST responses must contain every configured item exactly once.');
        }

        return $scores;
    }

    private static function coordinate(string $subtest, int $item): string
    {
        return $subtest."\0".$item;
    }

    private static function normalizeGeAnswer(string $answer): string
    {
        return strtolower(trim($answer));
    }
}
