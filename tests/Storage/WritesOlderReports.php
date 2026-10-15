<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use Reporion\Support\DocumentFormat;

/**
 * For tests of a test case that has a `$dataRoot`: a fixture of a report in an older shape (D48).
 */
trait WritesOlderReports
{
    /**
     * Puts a report on disk in an older shape. Storage writes the current one on every
     * create and save (D48), so a fixture of an older report is the file rewritten by
     * hand: what a report saved before the change looks like to the readers.
     *
     * @param array<string, mixed> $frontmatter exactly as the older file held it
     */
    protected function rewriteAsOlder(string $path, array $frontmatter, ?string $body = null): void
    {
        $file = $this->dataRoot . '/pages/' . str_replace(':', '/', $path) . '/current.md';
        self::assertFileExists($file);
        [$current, $currentBody] = DocumentFormat::parse((string) file_get_contents($file));
        file_put_contents($file, DocumentFormat::encode($frontmatter + array_intersect_key($current, array_flip(['pid'])), $body ?? $currentBody));
    }
}
