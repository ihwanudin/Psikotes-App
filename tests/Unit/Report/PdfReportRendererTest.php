<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Services\ReportRendering\BladeReportRenderer;
use App\Services\ReportRendering\FixtureReportDataset;
use App\Services\ReportRendering\PdfReportRenderer;
use RuntimeException;
use Tests\TestCase;

final class PdfReportRendererTest extends TestCase
{
    public function test_empty_html_is_rejected(): void
    {
        $renderer = PdfReportRenderer::withPdfFactory(
            static fn (string $html): string => '%PDF-1.7 should-not-matter',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty HTML');

        $renderer->render('   ');
    }

    public function test_factory_output_that_is_not_a_pdf_is_rejected(): void
    {
        $renderer = PdfReportRenderer::withPdfFactory(
            static fn (string $html): string => '<html>not a pdf</html>',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return a PDF binary');

        $renderer->render('<html><body>x</body></html>');
    }

    public function test_successful_render_returns_the_factory_binary_untouched(): void
    {
        $binary = '%PDF-1.4 fake-but-valid-magic-bytes';
        $renderer = PdfReportRenderer::withPdfFactory(
            static fn (string $html): string => $binary,
        );

        $this->assertSame($binary, $renderer->render('<html><body>ok</body></html>'));
    }

    public function test_real_dompdf_renders_the_fixture_hpp_html_into_a_pdf(): void
    {
        $html = BladeReportRenderer::make()->renderHpp(FixtureReportDataset::hppDraft());

        $pdf = PdfReportRenderer::make()->render($html);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1024, strlen($pdf));
    }

    public function test_real_dompdf_renders_the_fixture_internal_html_into_a_pdf(): void
    {
        $html = BladeReportRenderer::make()->renderInternal(FixtureReportDataset::internalDraft());

        $pdf = PdfReportRenderer::make()->render($html);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1024, strlen($pdf));
    }
}
