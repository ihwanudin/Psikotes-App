<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

/**
 * Participant HPP draft (Laporan Hasil Pemeriksaan Psikologis). Structurally
 * excludes DASS subscale scores, raw scores, aspect codes, and the validity
 * checklist: those live only on the internal sheet. Fixture-driven until
 * the F5 review/signature contract is final.
 */
final readonly class HppReportDraft
{
    /** @var list<string> */
    public const RECOMMENDATION_LABELS = ['DISARANKAN', 'DIPERTIMBANGKAN', 'TIDAK DISARANKAN'];

    /** @var list<string> */
    private const CLUSTERS = ['A', 'B', 'C', 'D'];

    /** @param array<string, string> $clusterNarratives */
    private function __construct(
        private ReportIdentity $identity,
        private ReportAspectGrid $aspectGrid,
        private int $iq,
        private string $iqCategory,
        private array $clusterNarratives,
        private ?DassScreeningSummary $dassScreening,
        private string $recommendationLabel,
        private string $rationale,
        private ?string $accompanimentConditions,
        private ?PsychologistSignature $psychologist,
    ) {}

    /**
     * @param  array<string, string>  $clusterNarratives
     * @param  array{name: string, silp_number: string, str_number: string, facility_name: string, facility_address: string, signature_note: string|null, signed_at: string|null}|null  $psychologist
     */
    public static function create(
        ReportIdentity $identity,
        ReportAspectGrid $aspectGrid,
        int $iq,
        string $iqCategory,
        array $clusterNarratives,
        ?DassScreeningSummary $dassScreening,
        string $recommendationLabel,
        string $rationale,
        ?string $accompanimentConditions,
        ?array $psychologist,
    ): self {
        if ($iq < 40 || $iq > 160) {
            throw new InvalidArgumentException('HPP report draft IQ is invalid.');
        }

        if (trim($iqCategory) === '') {
            throw new InvalidArgumentException('HPP report draft IQ category is invalid.');
        }

        if (count($clusterNarratives) !== count(self::CLUSTERS)) {
            throw new InvalidArgumentException('HPP report draft needs all four cluster narratives.');
        }

        foreach (self::CLUSTERS as $cluster) {
            $narrative = $clusterNarratives[$cluster] ?? null;
            if (! is_string($narrative) || trim($narrative) === '') {
                throw new InvalidArgumentException("HPP report draft cluster narrative [{$cluster}] is invalid.");
            }
        }

        if (! in_array($recommendationLabel, self::RECOMMENDATION_LABELS, true)) {
            throw new InvalidArgumentException('HPP report draft recommendation label is unknown.');
        }

        if (trim($rationale) === '') {
            throw new InvalidArgumentException('HPP report draft recommendation rationale is invalid.');
        }

        if ($accompanimentConditions !== null && trim($accompanimentConditions) === '') {
            throw new InvalidArgumentException('HPP report draft accompaniment conditions are invalid.');
        }

        if ($recommendationLabel === 'DIPERTIMBANGKAN' && $accompanimentConditions === null) {
            throw new InvalidArgumentException('DIPERTIMBANGKAN requires written accompaniment conditions (G9).');
        }

        return new self(
            $identity,
            $aspectGrid,
            $iq,
            $iqCategory,
            $clusterNarratives,
            $dassScreening,
            $recommendationLabel,
            $rationale,
            $accompanimentConditions,
            PsychologistSignature::fromNullable($psychologist),
        );
    }

    public function psychologist(): ?PsychologistSignature
    {
        return $this->psychologist;
    }

    /**
     * Blade-ready projection. Contains no aspect codes, no standards, no
     * DASS subscale numbers, and no session validity detail.
     *
     * @return array<string, mixed>
     */
    public function toViewData(): array
    {
        return [
            'identity' => $this->identity->toArray(),
            'iq' => ['iq' => $this->iq, 'category' => $this->iqCategory],
            'aspect_rows' => $this->aspectGrid->hppRows(),
            'cluster_narratives' => $this->clusterNarratives,
            // Null when no screening result exists: the report says so
            // explicitly instead of implying "no findings" (G4/T-07 keeps
            // DASS out of zone/label either way).
            'dass' => $this->dassScreening?->toArray(),
            'recommendation' => [
                'label' => $this->recommendationLabel,
                'rationale' => $this->rationale,
                'accompaniment_conditions' => $this->accompanimentConditions,
            ],
            'psychologist' => $this->psychologist?->toArray(),
        ];
    }
}
