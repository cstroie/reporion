<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin\Groff;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Groff\Ms\HtmlToMs;
use Reporion\Plugin\Loader;
use Reporion\Service\Render;

/** The canonical HTML of a body as ms lines (fictitious text only, invariant 10). */
final class HtmlToMsTest extends TestCase
{
    /** Every request the converter may write; nothing else may start a line with a dot */
    private const REQUESTS = '/^\.(PP|LP|QP|SH [123]|IP .*|RS|RE|DS L|DE|ft (CR|P)|sp|br|TS|TE)$/';

    protected function setUp(): void
    {
        Loader::registerAutoload(\dirname(__DIR__, 3) . '/plugins', 'groff');
    }

    /** @return list<string> */
    private function ms(string $markdown, array $fm = []): array
    {
        return HtmlToMs::convert((new Render())->body($markdown, $fm, unlinkPages: true)->html);
    }

    public function testHeadingsParagraphsAndInlineFonts(): void
    {
        $lines = $this->ms("## Exam\n\n### Descriere\n\nLesion of **12 mm**, *soft*, `x_1` and ***both***.\n\n#### Detail\n");

        self::assertSame(['.SH 1', 'Exam', '.SH 2', 'Descriere', '.PP', 'Lesion of \\f[B]12 mm\\f[R], \\f[I]soft\\f[R], \\f[CR]x_1\\f[R] and \\f[I]\\f[BI]both\\f[I]\\f[R].', '.SH 3', 'Detail'], $lines);
    }

    public function testListsNestAndNumber(): void
    {
        $lines = $this->ms("- one\n  - inner\n- two\n\n1. first\n2. second\n");

        self::assertSame(['.IP \\(bu 2', 'one', '.RS', '.IP \\(bu 2', 'inner', '.RE', '.IP \\(bu 2', 'two', '.IP "1." 3', 'first', '.IP "2." 3', 'second'], $lines);
    }

    public function testATableBecomesATblWithATextBlockPerCell(): void
    {
        $lines = $this->ms("| A | B |\n|---|---|\n| 1 | x.y |\n");

        self::assertSame(['.TS', 'allbox;', 'lbw(8c) lbw(8c)', 'lw(8c) lw(8c).', "T{\nA\nT}\tT{\nB\nT}", "T{\n1\nT}\tT{\nx.y\nT}", '.TE'], $lines);
    }

    public function testCodeAndQuotesAndRules(): void
    {
        $lines = $this->ms("> quoted\n\n---\n\n```\n.so /etc/passwd\n  a\\b -c\n```\n");

        self::assertSame(['.QP', 'quoted', '.sp', '.DS L', '.ft CR', '\\&.so /etc/passwd', '  a\\eb \\-c', '.ft P', '.DE'], $lines);
    }

    public function testATextPageIsALiteralBlock(): void
    {
        $lines = $this->ms("first\n  .second\n", ['format' => 'text']);

        self::assertSame(['.DS L', '.ft CR', 'first', '  .second', '.ft P', '.DE'], $lines);
    }

    public function testLinksKeepTheirTextAndShowAnExternalAddress(): void
    {
        $lines = $this->ms("See [the site](https://example.org/x) and [same](https://example.org/y) and [page](docs:other).\n");

        self::assertSame(['.PP', 'See the site (\\%https://example.org/x) and same (\\%https://example.org/y) and page.'], $lines);
    }

    public function testHostileTextNeverStartsARequest(): void
    {
        $body = "## .so /etc/passwd\n\n.sy id\n\n'cmd\n\n- .nr x 1\n\n> .pso id\n\nline one  \n.line two\n\n| .a | 'b |\n|---|---|\n| .c | \\X'x' |\n\n\\fB \\*[x] \\(bu \\\\\n";
        foreach ($this->ms($body) as $line) {
            foreach (explode("\n", $line) as $one) {
                if (str_starts_with($one, '.') || str_starts_with($one, "'")) {
                    self::assertMatchesRegularExpression(self::REQUESTS, $one, 'a line that starts with a dot is one of ours: ' . $one);
                }
            }
        }
        self::assertStringNotContainsString("\\fB", implode("\n", $this->ms("\\fB bold\n")), 'a typed escape is escaped');
    }

    public function testTheCorpusFixturesConvertWithoutAnyForeignRequest(): void
    {
        foreach (['report-corpus', 'multi-exam', 'tables', 'lists', 'emphasis', 'blockquote-and-code', 'headings'] as $name) {
            $lines = $this->ms((string) file_get_contents(\dirname(__DIR__, 2) . '/fixtures/render/' . $name . '.md'));
            self::assertNotSame([], $lines, $name);
            foreach ($lines as $line) {
                foreach (explode("\n", $line) as $one) {
                    if ($one !== '' && ($one[0] === '.' || $one[0] === "'")) {
                        self::assertMatchesRegularExpression(self::REQUESTS, $one, $name . ': ' . $one);
                    }
                }
            }
        }
    }
}
