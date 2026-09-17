<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use JsonException;

/** Strict transport validation only; the P15 FormRequest owns the field schema. */
final class StrictCheckoutJson
{
    public function isValidObject(string $json, int $maxBytes): bool
    {
        if ($json === '' || strlen($json) > $maxBytes || ! str_starts_with(ltrim($json), '{')) {
            return false;
        }
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return false;
        }
        if (! is_array($decoded)) {
            return false;
        }

        $offset = 0;

        return ! $this->valueHasDuplicateKey($json, $offset);
    }

    private function valueHasDuplicateKey(string $json, int &$offset): bool
    {
        $this->whitespace($json, $offset);
        $character = $json[$offset];
        if ($character === '{') {
            return $this->objectHasDuplicateKey($json, $offset);
        }
        if ($character === '[') {
            return $this->arrayHasDuplicateKey($json, $offset);
        }
        if ($character === '"') {
            $this->string($json, $offset);

            return false;
        }
        while ($offset < strlen($json) && ! in_array($json[$offset], [',', '}', ']'], true)) {
            $offset++;
        }

        return false;
    }

    private function objectHasDuplicateKey(string $json, int &$offset): bool
    {
        $offset++;
        $this->whitespace($json, $offset);
        $keys = [];
        if ($json[$offset] === '}') {
            $offset++;

            return false;
        }
        while (true) {
            $key = $this->string($json, $offset);
            if (array_key_exists($key, $keys)) {
                return true;
            }
            $keys[$key] = true;
            $this->whitespace($json, $offset);
            $offset++; // Colon; syntax was already validated by json_decode.
            if ($this->valueHasDuplicateKey($json, $offset)) {
                return true;
            }
            $this->whitespace($json, $offset);
            if ($json[$offset] === '}') {
                $offset++;

                return false;
            }
            $offset++; // Comma.
            $this->whitespace($json, $offset);
        }
    }

    private function arrayHasDuplicateKey(string $json, int &$offset): bool
    {
        $offset++;
        $this->whitespace($json, $offset);
        if ($json[$offset] === ']') {
            $offset++;

            return false;
        }
        while (true) {
            if ($this->valueHasDuplicateKey($json, $offset)) {
                return true;
            }
            $this->whitespace($json, $offset);
            if ($json[$offset] === ']') {
                $offset++;

                return false;
            }
            $offset++; // Comma.
            $this->whitespace($json, $offset);
        }
    }

    private function string(string $json, int &$offset): string
    {
        $start = $offset++;
        while (true) {
            if ($json[$offset] === '\\') {
                $offset += 2;

                continue;
            }
            if ($json[$offset++] === '"') {
                break;
            }
        }

        /** @var string $decoded */
        $decoded = json_decode(substr($json, $start, $offset - $start), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function whitespace(string $json, int &$offset): void
    {
        while ($offset < strlen($json) && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }
}
