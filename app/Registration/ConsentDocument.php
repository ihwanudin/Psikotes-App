<?php

declare(strict_types=1);

namespace App\Registration;

use InvalidArgumentException;

final readonly class ConsentDocument
{
    private function __construct(
        public string $type,
        public string $version,
        public string $title,
        public string $text,
        public string $hash,
    ) {}

    public static function for(string $type): self
    {
        $document = config("consent.documents.{$type}");

        if (! is_array($document)
            || ! is_string($document['version'] ?? null)
            || ! is_string($document['title'] ?? null)
            || ! is_string($document['text'] ?? null)) {
            throw new InvalidArgumentException("Unknown or invalid consent document [{$type}].");
        }

        return new self(
            $type,
            $document['version'],
            $document['title'],
            $document['text'],
            hash('sha256', $document['text']),
        );
    }

    /** @return array{version: string, title: string, text: string} */
    public function toPublicArray(): array
    {
        return [
            'version' => $this->version,
            'title' => $this->title,
            'text' => $this->text,
        ];
    }
}
