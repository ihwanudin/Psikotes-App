<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class StoreManualPaymentProofRequest extends FormRequest
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
        return [
            'payment_proof' => [
                'bail',
                'required',
                File::types(['jpg', 'jpeg', 'png', 'pdf'])
                    ->max((int) config('payments.manual_proof_max_kilobytes', 5_000)),
                'extensions:jpg,jpeg,png,pdf',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_proof.required' => 'Bukti transfer wajib dipilih.',
            'payment_proof.file' => 'Bukti transfer gagal diunggah.',
            'payment_proof.mimes' => 'Isi bukti transfer harus berupa JPG, PNG, atau PDF yang valid.',
            'payment_proof.max' => 'Ukuran bukti transfer tidak boleh lebih dari 5 MB.',
            'payment_proof.extensions' => 'Nama bukti transfer harus berekstensi JPG, PNG, atau PDF.',
        ];
    }
}
