<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Models\Admin;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final readonly class AssessmentBillProofUpload
{
    public function __construct(
        public Admin|AssessmentPrincipal $uploader,
        public string $billReference,
        public UploadedFile $proof,
        public ?string $expectedCurrentProofFingerprint,
    ) {
        if (! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $billReference)
            || ($expectedCurrentProofFingerprint !== null
                && ! preg_match('/^[0-9a-f]{64}$/D', $expectedCurrentProofFingerprint))) {
            throw new InvalidArgumentException('Invalid assessment bill proof upload.');
        }
    }
}
