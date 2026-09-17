<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\PayerType;
use InvalidArgumentException;
use LogicException;

/** Validated canonical selection; scope must come from a server-mapped principal. */
final readonly class AssessmentBillSelection
{
    /** @var list<array{assessmentParticipantId: int, consultationRequested: bool}> */
    public array $items;

    /** @param array<mixed> $items */
    public function __construct(public int $organizationId, array $items, public PayerType $payer, public ?int $participantId = null)
    {
        $limit = config('assessment_billing.max_items');
        if (! is_int($limit) || $limit < 1) {
            throw new LogicException('Invalid assessment billing batch limit.');
        }
        if ($organizationId < 1 || ! array_is_list($items) || $items === [] || count($items) > $limit
            || ($payer === PayerType::SelfPay && (count($items) !== 1 || $participantId === null || $participantId < 1))
            || ($payer === PayerType::Organization && $participantId !== null)) {
            throw new InvalidArgumentException('INVALID_BILL_SELECTION');
        }
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item) || count($item) !== 2 || ! is_int($item['assessmentParticipantId'] ?? null)
                || $item['assessmentParticipantId'] < 1 || ! is_bool($item['consultationRequested'] ?? null)
                || isset($seen[$item['assessmentParticipantId']])) {
                throw new InvalidArgumentException('INVALID_BILL_SELECTION');
            }
            $seen[$item['assessmentParticipantId']] = true;
        }
        usort($items, fn (array $a, array $b): int => $a['assessmentParticipantId'] <=> $b['assessmentParticipantId']);
        // Fixed key order also canonicalizes differently ordered JSON object keys.
        $this->items = array_map(fn (array $item): array => [
            'assessmentParticipantId' => $item['assessmentParticipantId'],
            'consultationRequested' => $item['consultationRequested'],
        ], $items);
    }
}
