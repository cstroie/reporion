<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

/**
 * What Extractor read from one file: the cleaned text (Support\SourceText) and the raw signals
 * Identify guesses the article from.
 */
final class Extracted
{
    /**
     * @param array<string, string>                          $info      pdfinfo's fields (Title, Author, Subject, Keywords, …)
     * @param list<array{size: float, top: int, text: string}> $lines   page 1's text lines with their font size (pdftohtml)
     */
    public function __construct(
        public readonly string $body,
        public readonly string $references,
        public readonly int $pages,
        public readonly bool $hasText,
        public readonly string $head,
        public readonly array $info = [],
        public readonly string $xmp = '',
        public readonly array $lines = [],
        // Read by OCR (phase 8): a scan's text, held to a stricter patient guard (Library::holdsPatientData())
        public readonly bool $ocr = false,
    ) {
    }

    /** @return array<string, mixed> for the temporary upload, read back by fromArray() */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['body'] ?? ''),
            (string) ($a['references'] ?? ''),
            (int) ($a['pages'] ?? 0),
            (bool) ($a['hasText'] ?? false),
            (string) ($a['head'] ?? ''),
            array_map('strval', \is_array($a['info'] ?? null) ? $a['info'] : []),
            (string) ($a['xmp'] ?? ''),
            array_values(array_filter(\is_array($a['lines'] ?? null) ? $a['lines'] : [], 'is_array')),
            (bool) ($a['ocr'] ?? false),
        );
    }
}
