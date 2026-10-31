<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Closure;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\DescriptionList\DescriptionListExtension;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\Extension\DescriptionList\Node\DescriptionList;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node as CommonMarkNode;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use League\CommonMark\Util\HtmlFilter;
use Reporion\Service\Markdown\CitationNode;
use Reporion\Service\Markdown\CitationSource;
use Reporion\Service\Markdown\DialectExtension;
use Reporion\Support\BodyFormat;
use Reporion\Support\Citation;
use Reporion\Support\Exams;
use Reporion\Support\InternalLink;
use Reporion\Support\MediaRef;
use Reporion\Support\Slug;

/**
 * Canonical rendering (CLAUDE.md invariant 4): the page view, print, PDF,
 * ODT and the index all go through toHtml(), and so does the editor's live
 * preview (POST /render; marked.js was dropped, D17 amended 2026-10-15). The
 * dialect: generic CommonMark plus tables, definition lists, task lists, bare
 * URL links, ==highlight==, ^super^/~sub~script, abbreviations, `:::` callouts
 * and table captions/column spans (Service\Markdown); no raw HTML passthrough,
 * no footnotes, no strikethrough, no custom macros. This class is the dialect:
 * it does not read config, because the dialect is not a site setting.
 */
final class Render
{
    private readonly MarkdownParser $parser;
    private readonly HtmlRenderer $renderer;

    /**
     * @param ?CitationSource $citations where `[@key]` finds its record (docs/library.md); none:
     *                                   a citation is shown as typed
     */
    public function __construct(
        private readonly ?CitationSource $citations = null,
        // The References heading's language for a page with no `language` (`library.language`)
        private readonly string $defaultLanguage = 'ro',
    ) {
        $environment = new Environment([
            'html_input' => HtmlFilter::ESCAPE,
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new DescriptionListExtension());
        $environment->addExtension(new TaskListExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new HighlightExtension());
        $environment->addExtension(new DialectExtension());
        $environment->addRenderer(DescriptionList::class, new DescriptionListRenderer(), 10);

        $this->parser = new MarkdownParser($environment);
        $this->renderer = new HtmlRenderer($environment);
    }

    /**
     * @param string $basePath      where the app is mounted, for links between pages
     *                              (Support\InternalLink): Request::$basePath
     * @param bool   $unlinkPages   print and export: a link to another page keeps its
     *                              text but loses its address, which would carry that
     *                              page's path: a patient path, for a report (invariant 8)
     * @param ?Closure(string $sha256, string $ext): ?string $mediaSrc
     *                              the src for an attached image (Support\MediaRef)
     *                              print embeds the bytes; null drops the image for its
     *                              alt text. Default: {basePath}/media/{sha256}.{ext}
     * @param bool $examIds         a multi-exam report (Support\Exams): each top-level
     *                              `##` is an exam, anchored `exam-1`, `exam-2`… instead
     *                              of its text's slug: the preview does the same
     * @param bool $lines           the editor's split preview: each top-level block and
     *                              list item carries `data-line` (its first source line,
     *                              1-based, in $markdown), so the preview and the text
     *                              scroll together and a click finds its line
     * @param bool $breaks          a single line break is a line (`<br>`), not a space:
     *                              a page with `breaks: true` (Support\BodyFormat), as
     *                              the notes app writes them (D55)
     * @param string $citationStyle vancouver|apa|ama: how `[@key]` is shown and the References
     *                              section built (a page's `citation_style`, docs/library.md)
     * @param bool   $citeLinks     a cited source links to its library page: the reader may open
     *                              it (never in print or an export, `unlinkPages`)
     * @param string $language      the References heading's language: the page's `language` (ro, en;
     *                              empty or another: Romanian, the notebook default of docs/library.md)
     */
    public function toHtml(string $markdown, string $basePath = '', bool $unlinkPages = false, ?Closure $mediaSrc = null, bool $examIds = false, bool $lines = false, bool $breaks = false, string $citationStyle = 'vancouver', bool $citeLinks = false, string $language = ''): RenderResult
    {
        return $this->markdown($markdown, $basePath, $unlinkPages, $mediaSrc, $examIds, $lines, $breaks, $citationStyle, $citeLinks, $language);
    }

    /**
     * A page's body in its own format (D40, docs/FORMATS.md §3j): markdown
     * through toHtml(), or `format: text` as typed: escaped, preformatted,
     * with no table of contents, links or images to resolve.
     *
     * @param array<string, mixed> $frontmatter the page's (or that revision's) own
     * @param ?Closure(string $sha256, string $ext): ?string $mediaSrc
     */
    public function body(string $body, array $frontmatter, string $basePath = '', bool $unlinkPages = false, ?Closure $mediaSrc = null, bool $examIds = false, bool $citeLinks = false): RenderResult
    {
        if (BodyFormat::isText($frontmatter)) {
            return new RenderResult(self::textBody($body), [], []);
        }

        return $this->markdown($body, $basePath, $unlinkPages, $mediaSrc, $examIds, false, BodyFormat::hardBreaks($frontmatter), Citation::style($frontmatter['citation_style'] ?? null), $citeLinks, \is_string($frontmatter['language'] ?? null) ? $frontmatter['language'] : '');
    }

    /**
     * Every soft line break (a single newline inside a paragraph) made a hard
     * one: the same parse, so the dialect is unchanged; only how a line ends
     */
    private static function hardBreaks(Document $document): void
    {
        $soft = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof Newline && $node->getType() === Newline::SOFTBREAK) {
                $soft[] = $node;
            }
        }
        foreach ($soft as $node) {
            $node->replaceWith(new Newline(Newline::HARDBREAK));
        }
    }

    /** A text body as HTML: every character escaped, every space and line kept */
    public static function textBody(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text), "\n");

        return $text === '' ? '' : '<pre class="wk-plaintext">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>\n";
    }

    /** @param ?Closure(string $sha256, string $ext): ?string $mediaSrc */
    private function markdown(string $markdown, string $basePath, bool $unlinkPages, ?Closure $mediaSrc, bool $examIds, bool $lines, bool $breaks = false, string $citationStyle = 'vancouver', bool $citeLinks = false, string $language = ''): RenderResult
    {
        $document = $this->parser->parse($markdown);
        if ($breaks) {
            self::hardBreaks($document);
        }
        if ($lines) {
            $this->markLines($document);
        }
        $this->resolvePageLinks($document, $basePath, $unlinkPages);
        $this->resolveMedia($document, $mediaSrc ?? static fn (string $sha256, string $ext): string => $basePath . '/media/' . $sha256 . '.' . $ext);
        // Anchors first: the table of contents links to them
        $toc = $this->extractToc($document, $examIds ? explode("\n", $markdown) : null);
        [$references, $unknown] = $this->resolveCitations($document, Citation::style($citationStyle), $citeLinks && !$unlinkPages ? $basePath : null, $language);
        $html = (string) $this->renderer->renderDocument($document) . $references;
        // A fenced block with no language fence (```) renders bare <pre><code>;
        // marking it nohighlight stops highlight.js (templates/layout.php)
        // guessing a language for it.
        $html = str_replace('<pre><code>', '<pre><code class="nohighlight">', $html);

        $warnings = $this->extractWarnings($document);
        if ($unknown !== []) {
            $warnings[] = t('library.cite_unknown', [implode(', ', array_map(static fn (string $k): string => '@' . $k, $unknown))]);
        }

        return new RenderResult($html, $toc, $warnings);
    }

    /**
     * Each `[@key]` filled in from its record, and the References section for the sources cited:
     * numbered by first citation (Vancouver, AMA) or in alphabetical order (APA)
     *
     * @param ?string $linkBase the app's base path when a source links to its library page; null: no links
     *
     * @return array{0: string, 1: list<string>} the section's HTML ('' when nothing is cited), the keys no source has
     */
    private function resolveCitations(CommonMarkNode $document, string $style, ?string $linkBase, string $language): array
    {
        $nodes = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof CitationNode) {
                $nodes[] = $node;
            }
        }
        if ($nodes === [] || $this->citations === null) {
            return ['', []];
        }

        /** @var array<string, array<string, mixed>> $cited */
        $cited = [];
        $numbers = [];
        $unknown = [];
        foreach ($nodes as $node) {
            $node->style = $style;
            $node->resolved = [];
            foreach ($node->items as $item) {
                $key = $item['key'];
                $csl = $cited[$key] ?? $this->citations->record($key);
                if ($csl === null) {
                    $unknown[$key] = true;
                    $node->resolved[] = ['key' => $key, 'label' => '', 'href' => null, 'number' => null];
                    continue;
                }
                $cited[$key] = $csl;
                $numbers[$key] ??= \count($numbers) + 1;
                $node->resolved[] = ['key' => $key, 'label' => Citation::apaLabel($csl), 'href' => null, 'number' => $numbers[$key]];
            }
        }
        if ($cited === []) {
            return ['', array_keys($unknown)];
        }

        $entries = [];
        foreach ($cited as $key => $csl) {
            $text = htmlspecialchars(Citation::format($style, $csl), ENT_QUOTES);
            if ($linkBase !== null) {
                $text = '<a class="wk-ref-src" href="' . htmlspecialchars($linkBase . '/' . $this->citations->path($key), ENT_QUOTES) . '">' . $text . '</a>';
            }
            $entries[$key] = '<li id="ref-' . $key . '">' . $text . '</li>';
        }
        if ($style === 'apa') {
            uksort($entries, static fn (string $a, string $b): int => strcasecmp(Citation::format('apa', $cited[$a]), Citation::format('apa', $cited[$b])));
        } else {
            uksort($entries, static fn (string $a, string $b): int => $numbers[$a] <=> $numbers[$b]);
        }
        $tag = $style === 'apa' ? 'ul' : 'ol';
        $html = '<div class="wk-references"><h2 id="references">' . htmlspecialchars(t(strtolower(substr(trim($language !== '' ? $language : $this->defaultLanguage), 0, 2)) === 'en' ? 'library.references_en' : 'library.references_ro'), ENT_QUOTES) . '</h2>'
            . '<' . $tag . ' class="wk-reflist wk-reflist-' . $style . '">' . implode('', $entries) . '</' . $tag . '></div>' . "\n";

        return [$html, array_keys($unknown)];
    }

    private function resolvePageLinks(CommonMarkNode $document, string $basePath, bool $unlink): void
    {
        $links = [];
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            $node = $event->getNode();
            if ($event->isEntering() && $node instanceof Link && !$node instanceof Image) {
                $href = InternalLink::href($node->getUrl(), $basePath);
                if ($href !== null) {
                    $links[] = [$node, $href];
                }
            }
        }

        // Changed after the walk, not during it
        foreach ($links as [$node, $href]) {
            if (!$unlink) {
                $node->setUrl($href);
                continue;
            }
            foreach ($node->children() as $child) {
                $node->insertBefore($child);
            }
            $node->detach();
        }
    }

    /** The first source line of each top-level block and list item, as `data-line` (the split preview's sync) */
    private function markLines(Document $document): void
    {
        foreach ($document->iterator() as $node) {
            if (!$node instanceof AbstractBlock || !($node->parent() instanceof Document || $node instanceof ListItem)) {
                continue;
            }
            // A description list is built after parsing, with no line of its own: its first term has it
            $line = $node->getStartLine() ?? ($node instanceof DescriptionList ? $node->firstChild()?->getStartLine() : null);
            if ($line !== null) {
                $node->data->set('attributes/data-line', (string) $line);
            }
        }
    }

    private function resolveMedia(CommonMarkNode $document, Closure $mediaSrc): void
    {
        $images = [];
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            $node = $event->getNode();
            if ($event->isEntering() && $node instanceof Image && ($ref = MediaRef::parse($node->getUrl())) !== null) {
                $images[] = [$node, $ref];
            }
        }

        foreach ($images as [$node, $ref]) {
            $src = $mediaSrc($ref['sha256'], $ref['ext']);
            if ($src !== null) {
                $node->setUrl($src);
                continue;
            }
            $node->insertBefore(new Text($this->plainText($node)));
            $node->detach();
        }
    }

    /**
     * @return list<array{level: int, text: string, slug: string}>
     */
    /**
     * @param ?list<string> $examLines the source lines of a multi-exam report,
     *        whose `##` lines are its exams (Support\Exams); null otherwise
     */
    private function extractToc(CommonMarkNode $document, ?array $examLines): array
    {
        $toc = [];
        $seenSlugs = [];
        $exam = 0;
        // A shared `## Concluzii` after the exams is no exam: it keeps its own slug
        $sharedLine = 0;
        if ($examLines !== null) {
            $split = Exams::split(implode("\n", $examLines));
            if ($split['shared'] !== null) {
                $sharedLine = substr_count($split['head'] . implode('', array_column($split['parts'], 'text')), "\n") + 1;
            }
        }

        foreach ($document->iterator() as $node) {
            if (!$node instanceof Heading) {
                continue;
            }

            // Only a `##` line is an exam, as Support\Exams counts them, not a setext `---` heading
            if ($examLines !== null && $node->getLevel() === 2 && $node->parent() instanceof Document && (int) $node->getStartLine() !== $sharedLine
                && preg_match('/^ {0,3}##(?:[ \t]|$)/', $examLines[(int) $node->getStartLine() - 1] ?? '') === 1) {
                $slug = 'exam-' . ++$exam;
                $node->data->set('attributes/id', $slug);
                $toc[] = ['level' => 2, 'text' => $this->plainText($node), 'slug' => $slug];
                continue;
            }

            $text = $this->plainText($node);
            if ($text === '') {
                continue;
            }

            $slug = self::headingSlug($text);
            // A heading can repeat a word for word title elsewhere in the
            // same document ("Concluzie" after a "Concluzie" subsection,
            // say): de-duplicate so the toc's slugs stay usable as anchors.
            if (isset($seenSlugs[$slug])) {
                $slug .= '-' . ++$seenSlugs[$slug];
            } else {
                $seenSlugs[$slug] = 1;
            }

            // The anchor the table of contents links to
            $node->data->set('attributes/id', $slug);
            $toc[] = ['level' => $node->getLevel(), 'text' => $text, 'slug' => $slug];
        }

        return $toc;
    }

    /** A heading's anchor: its slug, or "section" for one with no letters or digits ("## ---") */
    private static function headingSlug(string $text): string
    {
        try {
            return Slug::normalize($text);
        } catch (\InvalidArgumentException) {
            return 'section';
        }
    }

    /**
     * @return list<string>
     */
    private function extractWarnings(CommonMarkNode $document): array
    {
        $warnings = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof HtmlBlock || $node instanceof HtmlInline) {
                // html_input=ESCAPE already made this safe to render; the
                // warning is so the editor can tell the author their markup
                // was not interpreted (D17: no HTML passthrough).
                $warnings[] = 'Raw HTML is not part of the dialect and was shown as text, not rendered.';
                break;
            }
        }

        return $warnings;
    }

    private function plainText(CommonMarkNode $node): string
    {
        $text = '';
        foreach ($node->iterator() as $descendant) {
            if ($descendant instanceof StringContainerInterface) {
                $text .= $descendant->getLiteral();
            }
        }

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
