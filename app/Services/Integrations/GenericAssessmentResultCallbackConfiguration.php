<?php

declare(strict_types=1);

namespace App\Services\Integrations;

final readonly class GenericAssessmentResultCallbackConfiguration
{
    /** @return array<string,bool> */
    public function requirements(): array
    {
        $callbackSecret = config('selection_integration.result_callback_secret');
        $callbackKeyId = config('selection_integration.result_callback_key_id');
        $inboundSecret = config('selection_integration.client_secret');
        $timeout = config('selection_integration.result_callback_timeout_seconds');

        return [
            'SELECTION_RESULT_CALLBACK_BASE_URL_HTTPS_EXACT' => $this->isExactSelectionOrigin(
                config('selection_integration.result_callback_base_url'),
            ),
            'SELECTION_RESULT_CALLBACK_SECRET' => is_string($callbackSecret)
                && strlen($callbackSecret) >= 32,
            'SELECTION_RESULT_CALLBACK_KEY_ID' => PsychotestSelectionRequestSigner::acceptsKeyId($callbackKeyId),
            'SELECTION_RESULT_CALLBACK_SECRET_DIRECTIONAL' => ! is_string($inboundSecret)
                || ! is_string($callbackSecret)
                || ! hash_equals($inboundSecret, $callbackSecret),
            'SELECTION_RESULT_CALLBACK_TIMEOUT' => is_int($timeout)
                && $timeout >= 2 && $timeout <= 30,
        ];
    }

    public function isValid(): bool
    {
        return ! in_array(false, $this->requirements(), true);
    }

    private function isExactSelectionOrigin(mixed $value): bool
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'seleksi.beasiswajepang.id'
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && (! isset($parts['port']) || (int) $parts['port'] === 443)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }
}
