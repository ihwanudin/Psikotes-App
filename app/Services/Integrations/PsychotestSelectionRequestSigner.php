<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use InvalidArgumentException;

final class PsychotestSelectionRequestSigner
{
    private const string CONTRACT = 'generic-assessment-result';

    private const string CONTRACT_VERSION = '1';

    private const string PATH = '/api/v2/integrations/psychotest/results';

    public function sign(
        string $timestamp,
        string $method,
        string $path,
        string $rawQuery,
        string $body,
        string $secret,
    ): string {
        if (preg_match('/\A[1-9][0-9]{0,10}\z/', $timestamp) !== 1
            || $method !== 'POST'
            || $path !== self::PATH
            || strlen($secret) < 32) {
            throw new InvalidArgumentException('PSYCHOTEST_SELECTION_SIGNATURE_INPUT_INVALID');
        }

        return hash_hmac('sha256', implode("\n", [
            'psychotest-selection-hmac:v2',
            $timestamp,
            self::CONTRACT,
            self::CONTRACT_VERSION,
            $method,
            $path,
            $this->canonicalQuery($rawQuery),
            hash('sha256', $body),
        ]), $secret);
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $query) === 1) {
            throw new InvalidArgumentException('PSYCHOTEST_SELECTION_SIGNATURE_INPUT_INVALID');
        }

        $pairs = [];
        foreach (explode('&', $query) as $part) {
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $key = rawurldecode($rawKey);
            $value = rawurldecode($rawValue);
            if (! mb_check_encoding($key, 'UTF-8')
                || ! mb_check_encoding($value, 'UTF-8')
                || preg_match('/[\x00-\x1F\x7F]/u', $key.$value) === 1) {
                throw new InvalidArgumentException('PSYCHOTEST_SELECTION_SIGNATURE_INPUT_INVALID');
            }
            $pairs[] = [rawurlencode($key), rawurlencode($value)];
        }
        usort($pairs, static fn (array $left, array $right): int => $left <=> $right);

        return implode('&', array_map(static fn (array $pair): string => $pair[0].'='.$pair[1], $pairs));
    }
}
