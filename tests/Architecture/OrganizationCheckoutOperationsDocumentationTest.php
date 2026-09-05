<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class OrganizationCheckoutOperationsDocumentationTest extends TestCase
{
    private string $root;

    private string $document;

    private string $normalizedDocument;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
        $contents = file_get_contents($this->root.'/docs/ORGANIZATION_CHECKOUT_OPERATIONS.md');

        self::assertIsString($contents);
        $this->document = $contents;
        $normalized = preg_replace('/\s+/', ' ', $contents);
        self::assertIsString($normalized);
        $this->normalizedDocument = $normalized;
    }

    public function test_every_local_markdown_link_resolves_inside_the_repository(): void
    {
        $matches = [];
        $count = preg_match_all('/\[[^]]+]\((?<target>[^)]+)\)/', $this->document, $matches);

        self::assertIsInt($count);
        self::assertGreaterThan(0, $count);

        $documentDirectory = $this->root.'/docs';
        $canonicalRoot = realpath($this->root);
        self::assertIsString($canonicalRoot);

        foreach ($matches['target'] as $target) {
            self::assertIsString($target);

            $target = trim($target, "<> \t\n\r\0\x0B");
            $path = preg_split('/[?#]/', $target, 2)[0] ?? '';

            if ($path === '' || str_starts_with($target, '#') || parse_url($target, PHP_URL_SCHEME) !== null) {
                continue;
            }

            $resolved = realpath($documentDirectory.'/'.rawurldecode($path));

            self::assertIsString($resolved, "Runbook link does not resolve: {$target}");
            self::assertTrue(
                $resolved === $canonicalRoot || str_starts_with($resolved, $canonicalRoot.DIRECTORY_SEPARATOR),
                "Runbook link escapes the repository: {$target}",
            );
        }
    }

    public function test_claimed_payment_actions_and_coordinator_exist_at_the_documented_boundary(): void
    {
        $classes = [
            'ClaimAssessmentBillInvoice',
            'IssueAssessmentBillInvoice',
            'PersistAssessmentInvoiceOutcome',
            'CoordinateAssessmentInvoiceReconciliation',
        ];

        foreach ($classes as $class) {
            self::assertStringContainsString($class, $this->document);

            $path = $this->root."/app/Actions/Payments/{$class}.php";
            self::assertFileExists($path, "Documented payment class is missing: {$class}");

            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertMatchesRegularExpression(
                '/\b(?:final\s+readonly\s+|final\s+|readonly\s+)?class\s+'.preg_quote($class, '/').'\b/',
                $source,
                "Documented payment class declaration is missing: {$class}",
            );
        }
    }

    public function test_runbook_remains_provisional_and_fail_closed(): void
    {
        self::assertStringContainsString('**Status: DRAFT / PROVISIONAL (P18).**', $this->document);
        self::assertStringContainsString('Dokumen ini belum menutup acceptance', $this->normalizedDocument);
        self::assertStringContainsString('Seluruh switch checkout', $this->normalizedDocument);
        self::assertStringContainsString('writer tetap **OFF**', $this->normalizedDocument);
        self::assertStringContainsString('Tidak ada aktivasi global implisit.', $this->normalizedDocument);
        self::assertStringContainsString('hanya untuk sumber yang disetujui secara eksplisit', $this->normalizedDocument);
        self::assertStringContainsString('tidak boleh jatuh kembali ke provisioning v1', $this->normalizedDocument);
        self::assertStringContainsString('bukan memasuki endpoint v1', $this->normalizedDocument);
        self::assertStringContainsString('jangan mencentang acceptance P18', $this->normalizedDocument);
    }

    public function test_runbook_forbids_automatic_release_or_reinvoice_and_preserves_history_on_rollback(): void
    {
        self::assertStringContainsString('tidak melakukan auto-release maupun auto-reinvoice', $this->normalizedDocument);
        self::assertStringContainsString('Jangan auto-release, auto-reinvoice', $this->normalizedDocument);
        self::assertStringContainsString(
            'Rollback yang aman adalah rollback **aplikasi/traffic**, bukan penghapusan state',
            $this->normalizedDocument,
        );
        self::assertStringContainsString(
            'Simpan marker `checkout-v2`, bill, item, charge, intent, lease, audit, outbox, consent, dan histori.',
            $this->normalizedDocument,
        );
        self::assertStringContainsString('jangan rollback migration/policy privacy audit consent', $this->normalizedDocument);
        self::assertStringContainsString(
            'Redact data peserta, signature, token, URL bukti/invoice',
            $this->normalizedDocument,
        );
    }

    public function test_reconciliation_command_job_and_scheduler_are_explicitly_not_wired(): void
    {
        self::assertStringContainsString(
            'Kode secara eksplisit belum mendaftarkan command, job, route, atau scheduler.',
            $this->normalizedDocument,
        );
        self::assertStringContainsString(
            'tidak ada command/job/scheduler yang diasumsikan aktif tanpa wiring dan review',
            $this->normalizedDocument,
        );
        self::assertStringContainsString(
            '**jangan** memakai command `payments:reconcile-xendit` sebagai pengganti',
            $this->normalizedDocument,
        );
    }
}
