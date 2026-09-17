<?php

declare(strict_types=1);

namespace App\Domain\Report;

/**
 * Internal psychologist worksheet draft (Lembar Kerja Internal): raw
 * instrument blocks, aspect grid with codes and standards, session
 * validity checklist, the seven-slot integration draft, the detailed
 * DASS-21 subscale table, and review notes. Fixture-driven until the
 * F5 review/signature contract is final.
 */
final readonly class InternalReportDraft
{
    /** @param list<string> $reviewNotes */
    private function __construct(
        private ReportIdentity $identity,
        private IstResultBlock $ist,
        private KraepelinResultBlock $kraepelin,
        private PapiResultBlock $papi,
        private RmibResultBlock $rmib,
        private ReportAspectGrid $aspectGrid,
        private SessionValiditySummary $validity,
        private IntegrationSlots $integrationSlots,
        private DassInternalDetail $dassDetail,
        private array $reviewNotes,
        private ?PsychologistSignature $psychologist,
    ) {}

    /**
     * @param  array<mixed>  $ist
     * @param  array<mixed>  $kraepelin
     * @param  array<mixed>  $papi
     * @param  array<mixed>  $rmib
     * @param  list<array{code: string, label_id: string, label_jp: string, level: int, standard: int|null}>  $aspectRows
     * @param  array<mixed>  $validity
     * @param  array<mixed>  $integrationSlots
     * @param  array<mixed>  $dassDetail
     * @param  list<string>  $reviewNotes
     * @param  array{name: string, sipp_number: string, signature_note: string|null, signed_at: string|null}|null  $psychologist
     */
    public static function create(
        ReportIdentity $identity,
        array $ist,
        array $kraepelin,
        array $papi,
        array $rmib,
        array $aspectRows,
        array $validity,
        array $integrationSlots,
        array $dassDetail,
        array $reviewNotes,
        ?array $psychologist,
    ): self {
        foreach ($reviewNotes as $note) {
            if (! is_string($note) || trim($note) === '') {
                throw new InvalidArgumentException('Internal report review notes are invalid.');
            }
        }

        return new self(
            $identity,
            IstResultBlock::fromArray($ist),
            KraepelinResultBlock::fromArray($kraepelin),
            PapiResultBlock::fromArray($papi),
            RmibResultBlock::fromArray($rmib),
            ReportAspectGrid::fromArray($aspectRows),
            SessionValiditySummary::fromArray($validity),
            IntegrationSlots::fromArray($integrationSlots),
            DassInternalDetail::fromArray($dassDetail),
            $reviewNotes,
            PsychologistSignature::fromNullable($psychologist),
        );
    }

    public function validity(): SessionValiditySummary
    {
        return $this->validity;
    }

    public function psychologist(): ?PsychologistSignature
    {
        return $this->psychologist;
    }

    /** @return array<string, mixed> */
    public function toViewData(): array
    {
        return [
            'identity' => $this->identity->toArray(),
            'ist' => $this->ist->toArray(),
            'kraepelin' => $this->kraepelin->toArray(),
            'papi' => $this->papi->toArray(),
            'rmib' => $this->rmib->rankedCategories(),
            'aspect_rows' => $this->aspectGrid->toArray(),
            'zone_counts' => $this->aspectGrid->zoneCounts(),
            'validity' => $this->validity->toArray(),
            'integration_slots' => $this->integrationSlots->toArray(),
            'dass_detail' => $this->dassDetail->toArray(),
            'review_notes' => $this->reviewNotes,
            'psychologist' => $this->psychologist?->toArray(),
        ];
    }
}
