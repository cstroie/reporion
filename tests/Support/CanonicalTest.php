<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Canonical;
use Symfony\Component\Yaml\Yaml;

final class CanonicalTest extends TestCase
{
    public function testKeysAreReorderedToMatchSchemaDeclarationOrder(): void
    {
        $schema = ['title' => ['type' => 'text'], 'modality' => ['type' => 'enum', 'list' => true]];

        $bytes = Canonical::bytes(['modality' => ['MR'], 'title' => 'x'], 'body', $schema);

        self::assertMatchesRegularExpression('/^---\ntitle: x\nmodality: \[MR\]\n---/', $bytes);
    }

    public function testUnknownKeysKeepOriginalRelativeOrderAfterSchemaKnownOnes(): void
    {
        $schema = ['title' => ['type' => 'text']];

        $bytes = Canonical::bytes(['zeta' => '1', 'title' => 'x', 'alpha' => '2'], 'body', $schema);

        self::assertMatchesRegularExpression('/^---\ntitle: x\nzeta:.*\nalpha:.*\n---/s', $bytes);
    }

    public function testNestedObjectFieldsAreReorderedTooRecursively(): void
    {
        $schema = ['patient' => ['type' => 'object', 'fields' => ['name' => ['type' => 'text'], 'sex' => ['type' => 'enum']]]];

        $bytes = Canonical::bytes(['patient' => ['sex' => 'F', 'name' => 'Ionescu Maria']], 'body', $schema);

        self::assertStringContainsString("{ name: 'Ionescu Maria', sex: F }", $bytes);
    }

    public function testEmptySchemaLeavesKeyOrderAsSubmitted(): void
    {
        $bytes = Canonical::bytes(['zeta' => '1', 'alpha' => '2'], 'body', []);

        self::assertMatchesRegularExpression('/^---\nzeta:.*\nalpha:.*\n---/s', $bytes);
    }

    public function testNullValuedFieldsAreOmittedNotWritten(): void
    {
        $bytes = Canonical::bytes(['title' => 'x', 'accession' => null], 'body', []);

        self::assertStringNotContainsString('accession', $bytes);
    }

    public function testNullValuedNestedFieldsAreOmittedToo(): void
    {
        $bytes = Canonical::bytes(['patient' => ['name' => 'x', 'born' => null]], 'body', []);

        self::assertStringNotContainsString('born', $bytes);
    }

    public function testListsAreFlowStyle(): void
    {
        $bytes = Canonical::bytes(['region' => ['neuro', 'spine']], 'body', []);

        self::assertStringContainsString('region: [neuro, spine]', $bytes);
    }

    public function testCrlfLineEndingsAreNormalisedToLf(): void
    {
        $bytes = Canonical::bytes([], "line one\r\nline two\r\n", []);

        self::assertStringNotContainsString("\r", $bytes);
        self::assertStringContainsString("line one\nline two\n", $bytes);
    }

    public function testTrailingWhitespacePerLineIsStripped(): void
    {
        $bytes = Canonical::bytes([], "line one   \nline two\t\n", []);

        self::assertStringContainsString("line one\nline two\n", $bytes);
        self::assertStringNotContainsString('line one   ', $bytes);
    }

    public function testBodyEndsWithExactlyOneTrailingNewlineRegardlessOfInput(): void
    {
        $withMany = Canonical::bytes([], "text\n\n\n\n", []);
        $withNone = Canonical::bytes([], 'text', []);

        self::assertStringEndsWith("text\n", $withMany);
        self::assertStringEndsWith("text\n", $withNone);
        self::assertStringNotContainsString("text\n\n", $withMany);
    }

    /**
     * The acceptance criterion docs/FORMATS.md §8 states explicitly:
     * re-canonicalising already-canonical bytes must be a no-op. Round
     * trips through the same parser FlatFile::parseDocument() uses
     * (frontmatter YAML + body), against a realistic schema with a nested
     * object field, an unknown field and a null value all present at once.
     */
    public function testReCanonicalisingSignedBytesIsANoOp(): void
    {
        $schema = [
            'title' => ['type' => 'text'],
            'modality' => ['type' => 'enum', 'list' => true],
            'region' => ['type' => 'enum', 'list' => true],
            'patient' => ['type' => 'object', 'fields' => [
                'name' => ['type' => 'text'],
                'born' => ['type' => 'int'],
                'sex' => ['type' => 'enum'],
            ]],
        ];
        $frontmatter = [
            'patient' => ['born' => null, 'name' => 'Ionescu Maria', 'sex' => 'F'],
            'modality' => ['MR'],
            'title' => 'RM cerebral nativ',
            'region' => ['neuro'],
            'not_in_schema' => 'kept anyway',
        ];
        $body = "Text cu spatii la final   \r\nA doua linie\r\n\r\n\n";

        $first = Canonical::bytes($frontmatter, $body, $schema);

        [$frontmatterAgain, $bodyAgain] = self::reparse($first);
        $second = Canonical::bytes($frontmatterAgain, $bodyAgain, $schema);

        self::assertSame($first, $second);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private static function reparse(string $document): array
    {
        preg_match('/^---\n(.*?\n)---\n\n?(.*)$/s', $document, $m);
        $frontmatter = Yaml::parse($m[1]);

        return [\is_array($frontmatter) ? $frontmatter : [], $m[2]];
    }
}
