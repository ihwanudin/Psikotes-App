<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ParticipantLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'test_number' => ['required', 'string', 'min:12', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],
            'birth_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
