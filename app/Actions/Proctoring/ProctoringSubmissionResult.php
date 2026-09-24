<?php

declare(strict_types=1);

namespace App\Actions\Proctoring;

final readonly class ProctoringSubmissionResult
{
    public function __construct(
        public bool $accepted,
        public bool $replayed,
        public ?string $publicId = null,
        public ?string $errorCode = null,
    ) {}
}
