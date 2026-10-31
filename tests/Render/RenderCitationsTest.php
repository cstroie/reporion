<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Markdown\CitationSource;
use Reporion\Service\Render;

/** `[@key]` citations and the References section (docs/library.md, phase 3); the works are made up */
final class RenderCitationsTest extends TestCase
{
    private static function render(): Render
    {
        return new Render(new class implements CitationSource {
            public function record(string $key): ?array
            {
                return match ($key) {
                    'nobody2024' => ['type' => 'article-journal', 'title' => 'A study', 'author' => [['family' => 'Nobody', 'given' => 'Ann'], ['family' => 'Other', 'given' => 'Bo']], 'container-title' => 'J Rad', 'issued' => ['date-parts' => [[2024]]]],
                    'abel2020' => ['type' => 'article-journal', 'title' => 'Another', 'author' => [['family' => 'Abel', 'given' => 'Zed']], 'issued' => ['date-parts' => [[2020]]]],
                    default => null,
                };
            }

            public function path(string $key): string
            {
                return 'library:' . $key;
            }
        });
    }

    public function testTheReferencesHeadingFollowsTheLibrarysLanguageWhenThePageHasNone(): void
    {
        $source = new class implements CitationSource {
            public function record(string $key): ?array
            {
                return ['type' => 'article-journal', 'title' => 'A study', 'issued' => ['date-parts' => [[2024]]]];
            }

            public function path(string $key): string
            {
                return 'library:' . $key;
            }
        };

        self::assertStringContainsString('<h2 id="references">References</h2>', (new Render($source, 'en'))->toHtml('A [@nobody2024].')->html);
        self::assertStringContainsString('<h2 id="references">Bibliografie</h2>', (new Render($source, 'en'))->toHtml('A [@nobody2024].', language: 'ro')->html, 'the page\'s own wins');
        self::assertStringContainsString('<h2 id="references">Bibliografie</h2>', (new Render($source))->toHtml('A [@nobody2024].')->html);
    }

    public function testNumberedByFirstCitationWithTheReferencesBelow(): void
    {
        $html = self::render()->toHtml("A [@nobody2024] and [@abel2020] and again [@nobody2024].")->html;

        self::assertStringContainsString('<span class="wk-cite">[<a class="wk-cite-ref" href="#ref-nobody2024">1</a>]</span>', $html);
        self::assertStringContainsString('href="#ref-abel2020">2</a>', $html);
        self::assertSame(3, substr_count($html, 'class="wk-cite-ref"'));
        self::assertMatchesRegularExpression('~<ol class="wk-reflist wk-reflist-vancouver"><li id="ref-nobody2024">Nobody A, Other B\. A study\. J Rad\. 2024\.</li><li id="ref-abel2020">~', $html);
        self::assertStringContainsString('<h2 id="references">Bibliografie</h2>', $html, 'the notebook default is Romanian');
        self::assertStringContainsString('<h2 id="references">References</h2>', self::render()->toHtml('A [@abel2020].', language: 'en')->html);
        self::assertStringContainsString('>References</h2>', self::render()->body('A [@abel2020].', ['language' => 'en-GB'])->html);
    }

    public function testALocatorBelongsToItsSource(): void
    {
        $html = self::render()->toHtml('See [@nobody2024, p. 12; @abel2020].')->html;

        self::assertStringContainsString('>1</a>, p. 12; <a', $html);
        self::assertStringContainsString('>2</a>]', $html);
    }

    public function testApaIsAuthorDateAndTheListAlphabetical(): void
    {
        $html = self::render()->toHtml('A [@nobody2024, p. 3] B [@abel2020].', citationStyle: 'apa')->html;

        self::assertStringContainsString('(<a class="wk-cite-ref" href="#ref-nobody2024">Nobody &amp; Other, 2024</a>, p. 3)', $html);
        self::assertStringContainsString('>Abel, 2020</a>)', $html);
        self::assertLessThan(strpos($html, 'id="ref-nobody2024"'), strpos($html, 'id="ref-abel2020"'));
        self::assertStringContainsString('<ul class="wk-reflist wk-reflist-apa">', $html);
    }

    public function testAPageNamesItsStyleInItsFrontmatter(): void
    {
        $html = self::render()->body('A [@abel2020].', ['citation_style' => 'ama'])->html;

        self::assertStringContainsString('wk-reflist-ama', $html);
    }

    public function testASourceLinksOnlyWhenTheReaderMayOpenIt(): void
    {
        $render = self::render();

        self::assertStringContainsString('<a class="wk-ref-src" href="/w/library:abel2020">', $render->toHtml('A [@abel2020].', '/w', citeLinks: true)->html);
        self::assertStringNotContainsString('wk-ref-src', $render->toHtml('A [@abel2020].', '/w')->html);
        // print and export: never, whatever the reader may do
        self::assertStringNotContainsString('wk-ref-src', $render->toHtml('A [@abel2020].', '/w', unlinkPages: true, citeLinks: true)->html);
        self::assertStringContainsString('Abel Z. Another. 2020.', $render->toHtml('A [@abel2020].', '/w', unlinkPages: true)->html);
    }

    public function testAnUnknownKeyIsMarkedAndWarnedAbout(): void
    {
        $result = self::render()->toHtml('A [@gone2000] and [@abel2020].');

        self::assertStringContainsString('<span class="wk-cite-missing"', $result->html);
        self::assertStringContainsString('@gone2000', $result->html);
        self::assertSame(['Not in the library: @gone2000'], $result->warnings);
        self::assertStringContainsString('id="ref-abel2020"', $result->html);
        self::assertStringNotContainsString('ref-gone2000', $result->html);
    }

    public function testNothingKnownGivesNoReferencesSection(): void
    {
        self::assertStringNotContainsString('wk-references', self::render()->toHtml('Only [@gone2000].')->html);
    }

    public function testWithoutASourceACitationIsShownAsTyped(): void
    {
        $html = (new Render())->toHtml('A [@abel2020, p. 4].')->html;

        self::assertStringContainsString('<span class="wk-cite wk-cite-raw">[@abel2020, p. 4]</span>', $html);
        self::assertStringNotContainsString('wk-references', $html);
    }

    public function testAColonAfterACitationIsProseAndADefinitionLineIsADefinition(): void
    {
        $html = self::render()->toHtml("Two grades are described [@abel2020]: low and high.")->html;
        self::assertStringContainsString('</span>: low and high.', $html);
        self::assertStringContainsString('wk-reflist', $html);

        $html = self::render()->toHtml("[x]: https://example.test/\n\nSee [x] and [@abel2020].")->html;
        self::assertStringContainsString('<a href="https://example.test/">x</a>', $html, 'an ordinary link definition still works');
        self::assertStringNotContainsString('[x]: https', $html, 'and is not shown');
    }

    public function testWhatIsNotACitationStaysCommonMark(): void
    {
        $html = self::render()->toHtml("[@abel2020](https://example.test/) and `[@abel2020]` and [@Bad Key] and [@x].")->html;

        self::assertStringNotContainsString('wk-cite', $html);
        self::assertStringNotContainsString('wk-references', $html);
        self::assertStringContainsString('<code>[@abel2020]</code>', $html);
    }

    public function testATextPageIsNotParsed(): void
    {
        $html = self::render()->body('A [@abel2020].', ['format' => 'text'])->html;

        self::assertStringNotContainsString('wk-cite', $html);
        self::assertStringContainsString('[@abel2020]', $html);
    }
}
