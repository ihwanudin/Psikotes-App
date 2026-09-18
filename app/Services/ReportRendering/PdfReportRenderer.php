<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;

/**
 * Pure HTML-to-PDF transformer for the F6 draft documents. Performs no
 * storage, database, or network I/O: it accepts the HTML string produced
 * by BladeReportRenderer and returns the PDF binary, so it stays testable
 * without faking any filesystem. Fixture-driven until the F5
 * review/signature contract is final.
 */
final class PdfReportRenderer
{
    /** @var callable(string): string */
    private $pdfFactory;

    /** @param callable(string): string $pdfFactory */
    private function __construct(
        callable $pdfFactory,
    ) {
        $this->pdfFactory = $pdfFactory;
    }

    public static function make(): self
    {
        return new self(
            static fn (string $html): string => Pdf::loadHTML($html)->output(),
        );
    }

    /** @param callable(string): string $pdfFactory */
    public static function withPdfFactory(callable $pdfFactory): self
    {
        return new self($pdfFactory);
    }

    public function render(string $html): string
    {
        if (trim($html) === '') {
            throw new RuntimeException('PDF report renderer requires a non-empty HTML document.');
        }

        $pdf = ($this->pdfFactory)($html);

        if (! is_string($pdf) || $pdf === '' || ! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('PDF report renderer factory did not return a PDF binary.');
        }

        return $pdf;
    }
}
