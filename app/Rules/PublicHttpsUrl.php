<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PublicHttpsUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::accepts($value)) {
            $fail('URL callback harus berupa URL HTTPS publik yang valid.');
        }
    }

    public static function accepts(mixed $value): bool
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $normalizedHost = is_string($host) ? strtolower($host) : '';
        $ipAddress = is_string($host) ? filter_var($host, FILTER_VALIDATE_IP) : false;
        $isPrivateIp = $ipAddress !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        return ($parts['scheme'] ?? null) === 'https' && is_string($host) && $host !== ''
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && ($ipAddress !== false || str_contains($normalizedHost, '.'))
            && ! in_array($normalizedHost, ['localhost', 'metadata.google.internal', 'metadata.azure.internal'], true)
            && ! str_ends_with($normalizedHost, '.local') && ! str_ends_with($normalizedHost, '.internal')
            && ! $isPrivateIp;
    }
}
