<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Report\ReportIdentity;
use App\Services\ReportRendering\FixtureReportDataset;
use App\Services\ReportRendering\ReportDocumentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class ReportDocumentPublisherTest extends TestCase
{
    private ReportIdentity $identity;

    private ReportDocumentPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('reports');
        CarbonImmutable::setTestNow('2026-09-19 08:00:00');
        $this->identity = ReportIdentity::fromArray(
            FixtureReportDataset::hppDraft()->toViewData()['identity'],
        );
        $this->publisher = new ReportDocumentPublisher();
    }

    public function test_unknown_document_type_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown');

        $this->publisher->publish('summary', $this->identity, '%PDF-1.4 x');
    }

    public function test_non_pdf_binary_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PDF binary');

        $this->publisher->publish('hpp', $this->identity, 'plainly not a pdf');
    }

    public function test_publish_stores_privately_and_issues_a_short_lived_url(): void
    {
        $pdf = '%PDF-1.4 fixture-report-binary';

        $result = $this->publisher->publish('hpp', $this->identity, $pdf);

        $this->assertMatchesRegularExpression(
            '/^reports\/hpp\/[a-z0-9]{2}\/[a-z0-9]{62}\.pdf$/D',
            $result['object_key'],
        );
        $this->assertNotSame('', $result['url']);
        $this->assertSame(
            CarbonImmutable::now()->addMinutes(ReportDocumentPublisher::TEMPORARY_URL_MINUTES)->toIso8601String(),
            $result['expires_at'],
        );
        $this->assertSame(strlen($pdf), $result['size']);
        $this->assertSame(hash('sha256', $pdf), $result['checksum']);

        Storage::disk('reports')->assertExists($result['object_key']);
        Storage::disk('reports')->assertExists(
            $result['object_key'],
            $pdf,
        );
    }

    public function test_internal_document_type_uses_its_own_key_prefix(): void
    {
        $result = $this->publisher->publish('internal', $this->identity, '%PDF-1.4 internal');

        $this->assertStringStartsWith('reports/internal/', $result['object_key']);
        Storage::disk('reports')->assertExists($result['object_key']);
    }

    public function test_object_keys_are_random_not_deterministic(): void
    {
        $pdf = '%PDF-1.4 same-input-twice';

        $first = $this->publisher->publish('hpp', $this->identity, $pdf);
        $second = $this->publisher->publish('hpp', $this->identity, $pdf);

        $this->assertNotSame($first['object_key'], $second['object_key']);
        Storage::disk('reports')->assertExists($first['object_key']);
        Storage::disk('reports')->assertExists($second['object_key']);
    }

    public function test_object_key_leaks_nothing_from_the_report_identity(): void
    {
        $result = $this->publisher->publish('hpp', $this->identity, '%PDF-1.4 x');

        $values = $this->identity->toArray();

        foreach ([
            $values['participant_name'],
            $values['test_number'],
            $values['report_number'],
            str_replace('-', '', $values['report_number']),
        ] as $secret) {
            $this->assertStringNotContainsString(strtolower($secret), $result['object_key']);
        }
    }
}
