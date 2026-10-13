<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\PageKind;
use Reporion\Support\ReportPath;

/**
 * What a page is for, from its path alone (docs/namespaces.md).
 */
final class PageKindTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'report' => ['reports:mri:mioveni:260926-popescu-ana', PageKind::REPORT];
        yield 'collision form' => ['reports:mri:mioveni:260926-popescu-ana-2', PageKind::REPORT];
        yield 'a site description under reports' => ['reports:mri:mioveni', PageKind::PAGE];
        yield 'report-shaped but elsewhere' => ['docs:260926-popescu-ana', PageKind::PAGE];
        yield 'template' => ['templates:mri:genunchi', PageKind::TEMPLATE];
        yield 'shared snippet' => ['templates:snippets:norm', PageKind::SNIPPET];
        yield 'modality snippet' => ['templates:snippets:mri:norm', PageKind::SNIPPET];
        yield 'the snippets namespace page' => ['templates:snippets', PageKind::SNIPPET];
        yield 'a namespace that only starts with the word' => ['templatesx:a', PageKind::PAGE];
        yield 'prompt' => ['ai:profiles:reports:summary', PageKind::PROMPT];
        yield 'reference' => ['radiology:msk:genunchi', PageKind::REFERENCE];
        yield 'normal reports are references' => ['radiology:normal:us', PageKind::REFERENCE];
        yield 'article, not a reference namespace' => ['docs:presentations:radiology:spine', PageKind::PAGE];
        yield 'ordinary' => ['site:home', PageKind::PAGE];
    }

    /**
     * @dataProvider paths
     */
    public function testAPathIsOfOneKind(string $path, string $kind): void
    {
        self::assertSame($kind, PageKind::of($path, ['radiology']));
    }

    public function testWithoutReferenceNamespacesNothingIsAReference(): void
    {
        self::assertSame(PageKind::PAGE, PageKind::of('radiology:msk:genunchi'));
    }

    public function testAReportPathIsBuiltInOnePlace(): void
    {
        $path = ReportPath::pathFor('mri', 'mioveni', '260926', 'popescu-ana');

        self::assertSame('reports:mri:mioveni:260926-popescu-ana', $path);
        self::assertTrue(ReportPath::isReport($path));
    }
}
