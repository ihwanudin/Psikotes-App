<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class RelativeCallbackPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::accepts($value)) {
            $fail('Path callback harus berupa path relatif yang dimulai dengan / tanpa query atau fragmen.');
        }
    }

    public static function accepts(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, '/') && ! str_starts_with($value, '//')
            && ! str_contains($value, '://') && ! str_contains($value, '..')
            && ! str_contains($value, '?') && ! str_contains($value, '#');
    }
}
