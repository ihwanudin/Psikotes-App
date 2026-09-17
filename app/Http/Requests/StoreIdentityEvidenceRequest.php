<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class StoreIdentityEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $participantId = $this->session()->get('registration.participant_id');
        $authorizedUntil = $this->session()->get('registration.evidence_authorized_until');

        return is_numeric($participantId)
            && is_numeric($authorizedUntil)
            && (int) $authorizedUntil >= now()->getTimestamp();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $image = fn () => File::image()
            ->types(['jpg', 'jpeg', 'png', 'webp'])
            ->max((int) config('identity.max_upload_kilobytes', 5_000))
            ->dimensions(
                Rule::dimensions()
                    ->minWidth((int) config('identity.min_dimension', 480))
                    ->minHeight((int) config('identity.min_dimension', 480))
                    ->maxWidth((int) config('identity.max_dimension', 8_000))
                    ->maxHeight((int) config('identity.max_dimension', 8_000)),
            );

        return [
            'identity_document' => ['bail', 'required', $image()],
            'initial_selfie' => ['bail', 'required', $image()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'identity_document.required' => 'Foto KTP atau paspor wajib dipilih.',
            'identity_document.file' => 'Foto KTP atau paspor gagal diunggah.',
            'identity_document.image' => 'Foto KTP atau paspor harus berupa gambar yang valid.',
            'identity_document.mimes' => 'Foto KTP atau paspor harus berformat JPG, PNG, atau WebP.',
            'identity_document.mimetypes' => 'Isi foto KTP atau paspor tidak sesuai format gambar yang diizinkan.',
            'identity_document.max' => 'Ukuran foto KTP atau paspor tidak boleh lebih dari 5 MB.',
            'identity_document.dimensions' => 'Dimensi foto KTP atau paspor harus antara 480 dan 8.000 piksel.',
            'initial_selfie.required' => 'Selfie awal wajib dipilih.',
            'initial_selfie.file' => 'Selfie awal gagal diunggah.',
            'initial_selfie.image' => 'Selfie awal harus berupa gambar yang valid.',
            'initial_selfie.mimes' => 'Selfie awal harus berformat JPG, PNG, atau WebP.',
            'initial_selfie.mimetypes' => 'Isi selfie awal tidak sesuai format gambar yang diizinkan.',
            'initial_selfie.max' => 'Ukuran selfie awal tidak boleh lebih dari 5 MB.',
            'initial_selfie.dimensions' => 'Dimensi selfie awal harus antara 480 dan 8.000 piksel.',
        ];
    }
}
