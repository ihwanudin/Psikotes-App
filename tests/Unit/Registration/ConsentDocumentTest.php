<?php

declare(strict_types=1);

namespace Tests\Unit\Registration;

use App\Registration\ConsentDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ConsentDocumentTest extends TestCase
{
    #[DataProvider('documentTypes')]
    public function test_document_hash_is_derived_from_the_versioned_server_copy(string $type): void
    {
        $document = ConsentDocument::for($type);

        $this->assertSame(64, strlen($document->hash));
        $this->assertSame(hash('sha256', $document->text), $document->hash);
        $this->assertStringStartsWith('draft-', $document->version);
    }

    /** @return array<string, array{string}> */
    public static function documentTypes(): array
    {
        return [
            'psychotest' => ['psychotest'],
            'dass' => ['dass'],
        ];
    }
}
