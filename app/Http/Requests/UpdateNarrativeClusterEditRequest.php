<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateNarrativeClusterEditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'edited_text' => ['required', 'string'],
            'baseline_version_id' => ['required', 'string', 'size:26'],
        ];
    }
}
