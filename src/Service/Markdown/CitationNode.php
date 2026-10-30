<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * `[@iwata2019]`, `[@iwata2019, p. 12]`, `[@a; @b]` (pandoc's syntax, docs/library.md): one or
 * more sources cited at one place. Service\Render fills `resolved` (number or author-year, and
 * the page of the source) once the document is parsed; until then, and for a key no source has,
 * it is shown as typed.
 */
final class CitationNode extends AbstractInline
{
    /**
     * @param list<array{key: string, locator: string}> $items
     * @param list<array{key: string, label: string, href: ?string, number: ?int}>|null $resolved set by Render
     */
    public function __construct(
        public readonly array $items,
        public readonly string $source,
        public ?array $resolved = null,
        public string $style = 'vancouver',
    ) {
        parent::__construct();
    }
}
