<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use Carbon\CarbonInterface;
use SensitiveParameter;

final readonly class CheckoutHandoffIssueResult
{
    private ?string $rawToken;

    public function __construct(
        public bool $replayed,
        public bool $reissueRequired,
        public string $handoffPublicId,
        public int $issueNumber,
        public CarbonInterface $expiresAt,
        #[SensitiveParameter]
        ?string $rawToken,
    ) {
        $this->rawToken = $rawToken;
    }

    public function rawToken(): ?string
    {
        return $this->rawToken;
    }

    /** @return array{replayed:bool,reissueRequired:bool,handoffPublicId:string,issueNumber:int,expiresAt:string} */
    public function descriptor(): array
    {
        return [
            'replayed' => $this->replayed,
            'reissueRequired' => $this->reissueRequired,
            'handoffPublicId' => $this->handoffPublicId,
            'issueNumber' => $this->issueNumber,
            'expiresAt' => $this->expiresAt->toISOString(),
        ];
    }
}
