<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Http\Request;
use Reporion\Kernel;

/**
 * GET /search (docs/architecture-api.md §1 Table 1): the first, shareable
 * result page. Visibility is whatever Index\Sqlite::search() already
 * enforces (tests/Visibility) — this suite is about the HTTP route and the
 * snippet-escaping path, not re-proving the visibility matrix.
 */
final class SearchTest extends HttpTestCase
{
    public function testEmptyQueryShowsThePromptNotAllResults(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'text');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search'));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('RM cerebral', $response->body);
    }

    public function testFindsAMatchingPublicPageForAnonymous(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'fara leziuni demielinizante');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'demielinizante']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('RM cerebral', $response->body);
        self::assertStringContainsString('<mark>demielinizante</mark>', $response->body);
    }

    /**
     * The bug this guards against: snippet() extracts raw markdown body
     * text, so a result row showed literal "##" heading markers and blank
     * lines — caught live once the result row was a single-line mockup
     * layout instead of a <p> that happened to collapse the whitespace.
     */
    public function testResultsPageShowsNoMockupSampleData(): void
    {
        // Facet counts, an AI answer and "N of M shown" were ported from the
        // mockup as literal text; none of it came from the query.
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'fara leziuni demielinizante');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'demielinizante']));

        foreach (['wk-pal-ai', 'amended', 'fts5 · 34 ms', 'load more', 'cosine', 'Aera 1.5 T', 'McDonald'] as $sample) {
            self::assertStringNotContainsString($sample, $response->body);
        }
        // Phase 19: the facets are real now — the one page's own status, counted once
        self::assertMatchesRegularExpression('#<a class="wk-facet-v" href="[^"]*status=draft"><span>draft</span><span class="wk-count">1</span></a>#', $response->body);
    }

    /** Phase 19: a facet value filters, and the pager walks the results without repeating one */
    public function testFacetsFilterAndPagesNeverRepeatARow(): void
    {
        for ($i = 1; $i <= 53; ++$i) {
            $this->createPage(sprintf('reports:mri:mioveni:p%02d', $i), 'public', 'RM ' . $i, 'leziuni demielinizante');
        }
        $this->createPage('reports:ct:mioveni:c1', 'public', 'CT 1', 'leziuni demielinizante');
        $boot = fn (array $query) => Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'demielinizante'] + $query))->body;

        $first = $boot([]);
        self::assertStringContainsString('54 result(s) match', $first);
        self::assertStringContainsString('1–50 of 54', $first);
        self::assertStringContainsString('rel="next"', $first);
        $second = $boot(['page' => '2']);
        self::assertStringContainsString('51–54 of 54', $second);
        self::assertStringNotContainsString('rel="next"', $second);
        $titles = static function (string $html): array {
            preg_match_all('#<div class="wk-row-t"><a href="[^"]*">([^<]*)</a>#', $html, $m);

            return $m[1];
        };
        $all = [...$titles($first), ...$titles($second)];
        self::assertCount(54, $all);
        self::assertSame($all, array_values(array_unique($all)), 'page 2 repeats nothing of page 1');

        $filtered = $boot(['ns' => 'reports:ct']);
        self::assertStringContainsString('1 result(s) match', $filtered);
        self::assertStringContainsString('1–1 of 1', $filtered);
    }

    public function testSnippetFlattensTableSyntax(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', "| structura | aspect |\n|---|:--:|\n| ventriculi | normali demielinizante |\n");

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'demielinizante']));

        preg_match('/<div class="wk-row-s">(.*?)<\/div>/s', $response->body, $m);
        self::assertNotEmpty($m);
        self::assertStringNotContainsString('|', $m[1]);
        self::assertStringNotContainsString('---', $m[1]);
        self::assertStringContainsString('ventriculi', $m[1]);
    }

    public function testSnippetStripsHeadingMarkersAndCollapsesBlankLines(): void
    {
        $this->createPage(
            'reports:mri:mioveni:a',
            'public',
            'RM cerebral',
            "## Indicatie\n\nControl imagistic.\n\n## Concluzie\n\nAspect stabil unieuword12345."
        );

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'unieuword12345']));

        self::assertStringNotContainsString('##', $response->body);
        self::assertStringContainsString('Aspect stabil <mark>unieuword12345</mark>.', $response->body);
    }

    public function testPrivatePageNeverAppearsInAnonymousResults(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Secret Title', 'unieuword12345');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'unieuword12345']));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('Secret Title', $response->body);
    }

    /**
     * The bug this guards against: snippet() returns raw markdown body
     * text, not HTML. Echoing it directly around genuinely-trusted <mark>
     * tags would let report text containing "<"/"&" break out as live
     * markup — caught in review before it shipped.
     */
    public function testReportBodyWithHtmlLookingTextIsEscapedInTheSnippet(): void
    {
        $this->createPage(
            'reports:mri:mioveni:a',
            'public',
            'RM cerebral',
            'valoare presiune <script>alert(1)</script> sub 10mmHg si peste 5mmHg'
        );

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'presiune']));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
        self::assertStringContainsString('&lt;script&gt;', $response->body);
    }

    /**
     * A raw, unescaped query term is FTS5 query syntax (quotes, AND/OR/NOT,
     * column filters) — a caller-supplied string must not be able to throw
     * a 500 by sending malformed syntax.
     */
    public function testMalformedQuerySyntaxDoesNotCrash(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'unieuword12345');
        $kernel = Kernel::boot($this->config);

        $response = $kernel->handle(new Request('GET', '/search', query: ['q' => '"unterminated AND (']));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('RM cerebral', $response->body);

        // The malformed query must not have wedged anything — an ordinary
        // query right after still finds the page, proving the FTS engine
        // ran rather than the route swallowing every result silently.
        $followUp = $kernel->handle(new Request('GET', '/search', query: ['q' => 'unieuword12345']));
        self::assertStringContainsString('RM cerebral', $followUp->body);
    }

    /**
     * Bug this guards against: an earlier ftsPhrase() quoted the whole term
     * as one FTS5 phrase, which only matches tokens adjacent and in order —
     * "leziuni demielinizante" would not find "leziuni multiple
     * demielinizante", breaking ordinary multi-word clinical queries.
     */
    public function testMultiWordQueryMatchesWordsThatAreNotAdjacent(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'leziuni multiple demielinizante active');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'leziuni demielinizante']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('RM cerebral', $response->body);
    }

    public function testJsonSuggestReturnsPlainDataForTheGivenTerm(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'fara leziuni demielinizante');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/api/v1/search', query: ['q' => 'demielinizante']));

        self::assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        self::assertCount(1, $decoded['data']);
        self::assertSame('reports:mri:mioveni:a', $decoded['data'][0]['path']);
        self::assertSame('RM cerebral', $decoded['data'][0]['title']);
    }

    public function testJsonSuggestSnippetHasNoSentinelMarkersOrHtml(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'fara leziuni demielinizante active');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/api/v1/search', query: ['q' => 'demielinizante']));

        $decoded = json_decode($response->body, true);
        $snippet = $decoded['data'][0]['snippet'];
        self::assertStringNotContainsString("\x02", $snippet);
        self::assertStringNotContainsString("\x03", $snippet);
        self::assertStringNotContainsString('<mark>', $snippet);
    }

    public function testJsonSuggestEmptyQueryReturnsEmptyData(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'text');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/api/v1/search'));

        self::assertSame(200, $response->status);
        self::assertSame(['data' => []], json_decode($response->body, true));
    }

    public function testJsonSuggestObeysVisibilityForAnonymous(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'RM privat', 'secretdiagnostic');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/api/v1/search', query: ['q' => 'secretdiagnostic']));

        self::assertSame(200, $response->status);
        self::assertSame(['data' => []], json_decode($response->body, true));
    }
}
