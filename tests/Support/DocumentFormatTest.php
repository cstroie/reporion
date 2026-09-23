<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\DocumentFormat;
use RuntimeException;

final class DocumentFormatTest extends TestCase
{
    public function testEncodeThenParseRoundTrips(): void
    {
        $document = DocumentFormat::encode(['title' => 'x', 'visibility' => 'private'], "body text\n");

        [$frontmatter, $body] = DocumentFormat::parse($document);

        self::assertSame('x', $frontmatter['title']);
        self::assertSame('private', $frontmatter['visibility']);
        self::assertSame("body text\n", $body);
    }

    public function testParseRejectsADocumentWithNoFrontmatterBlock(): void
    {
        $this->expectException(RuntimeException::class);
        DocumentFormat::parse('just some text, no frontmatter at all');
    }

    public function testParseRejectsAYamlListWhereFrontmatterShouldBe(): void
    {
        // PHP can't distinguish a YAML list from a mapping by is_array()
        // alone — this is the case that actually discriminates the check.
        $this->expectException(RuntimeException::class);
        DocumentFormat::parse("---\n- a\n- b\n---\n\nbody\n");
    }

    /**
     * The regex requires at least one line inside the frontmatter block
     * (matching Storage\FlatFile's own identical parseDocument() regex,
     * which this mirrors exactly) — "---\n---\n\n" with nothing between
     * the fences doesn't match at all, same as it never has in FlatFile.
     * Not a new limitation this class introduces.
     */
    public function testParseRejectsTrulyEmptyFrontmatterBlock(): void
    {
        $this->expectException(RuntimeException::class);
        DocumentFormat::parse("---\n---\n\nbody\n");
    }
}
