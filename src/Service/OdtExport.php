<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Row;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;
use RuntimeException;
use ZipArchive;

/**
 * ODT export (docs/architecture-api.md, decided 2026-09-26): the same HTML
 * the print view and the PDF render (templates/print/report.php), turned
 * into an OpenDocument text through PHPWord, not a second template. The
 * report stays editable in LibreOffice, which is what ODT is for.
 *
 * The same prepared document is also written as DOCX (Word2007 writer,
 * `render($html, 'docx')`, 2026-10-19): one conversion, two containers, so
 * the two files carry the same content.
 *
 * PHPWord reads inline styles only, so the handful of print.css rules that
 * carry meaning (muted labels, bold values, headings, the draft band) are
 * copied onto the elements as inline style first. PHPWord writes the zip
 * with its bundled PclZip when PHP has no zip extension.
 */
final class OdtExport
{
    /**
     * Headings as styled paragraphs: PHPWord's heading styles do not survive
     * into ODT. As print.css (docs/FORMATS.md §11): ## an exam, ### a section
     * label in grey capitals, #### a quieter one, ##### grey italic.
     */
    private const HEADINGS = [
        'h1' => 'font-size: 15pt; margin-top: 6pt; margin-bottom: 6pt;',
        'h2' => 'font-size: 13pt; color: #1a1a1f; margin-top: 16pt; margin-bottom: 6pt; border-bottom: 1pt #D5D5DE solid;',
        'h3' => 'font-size: 9pt; font-weight: bold; color: #6b6b78; letter-spacing: 1pt; margin-top: 10pt; margin-bottom: 3pt;',
        'h4' => 'font-size: 9pt; color: #6b6b78; letter-spacing: 1pt; margin-top: 8pt; margin-bottom: 3pt;',
        'h5' => 'font-size: 9.5pt; font-style: italic; color: #6b6b78; margin-top: 6pt; margin-bottom: 2pt;',
        'h6' => 'font-size: 9.5pt; font-style: italic; color: #6b6b78; margin-top: 6pt; margin-bottom: 2pt;',
    ];

    /** print.css text-transform: uppercase, which PHPWord does not carry: the text itself is uppercased */
    private const UPPERCASE_HEADINGS = ['h3', 'h4'];
    private const UPPERCASE_CLASSES = ['lh-name', 'pt-k', 'pt-k2'];

    /** The text column: 210 mm − 2 × 20 mm, in CSS px at 96 dpi */
    private const MAX_IMAGE_PX = 643;

    /** The text column, 17 cm, in twips */
    private const TEXT_WIDTH_TWIP = 9638;

    /** Arial: on every Word, and LibreOffice has a metric twin (Liberation Sans) */
    private const FONT = 'Arial';

    /** Space after a body paragraph, list item or table, as print.css's 2.4 mm */
    private const SPACE_AFTER = 'margin-bottom: 7pt;';

    /** Properties that belong to the paragraph or cell itself; the rest of a style goes on a span (PHPWord reads fonts from inline text) */
    private const BLOCK_PROPERTIES = ['text-align', 'margin-top', 'margin-bottom', 'line-height', 'width', 'border-top', 'border-bottom', 'border-left', 'border-right'];

    /** print.css, as inline styles PHPWord understands (class → style) */
    private const CLASS_STYLES = [
        'lh-left' => 'width: 62%;',
        'lh-name' => 'font-size: 13pt; font-weight: bold; letter-spacing: 1pt; margin-bottom: 2pt;',
        'lh-right' => 'width: 38%; text-align: right; font-size: 9pt; color: #6b6b78;',
        'lh-sub' => 'font-size: 9pt; color: #6b6b78;',
        'lh-rule' => 'border-bottom: 3pt #1A1A1F solid; margin-bottom: 10pt;',
        'doc-title' => 'font-size: 20pt; margin-top: 4pt; margin-bottom: 10pt;',
        'pt-k' => 'width: 30mm; color: #6b6b78; font-size: 8.5pt; letter-spacing: 1pt;',
        'pt-k2' => 'width: 40mm; color: #6b6b78; font-size: 8.5pt; letter-spacing: 1pt;',
        'pt-v' => 'font-size: 10pt;',
        'pt-strong' => 'font-weight: bold;',
        'draft-band' => 'text-align: center; color: #6b6b78; font-size: 9pt; font-weight: bold; letter-spacing: 2pt; margin-bottom: 10pt;',
        'sig-left' => 'width: 60%; text-align: center;',
        'sig-right' => 'width: 40%; text-align: right; font-size: 9pt; color: #6b6b78;',
        'sig-sub' => 'color: #6b6b78;',
        'sig-title' => 'font-size: 9pt;',
        'verify' => 'font-family: Courier New; font-size: 8pt;',
    ];

    /** @param 'odt'|'docx' $format */
    public function render(string $html, string $format = 'odt'): string
    {
        if (!class_exists(ZipArchive::class)) {
            Settings::setZipClass(Settings::PCLZIP);
        }
        // PHPWord writes text into content.xml as it is unless told to
        // escape it: a report's "<5 mm" or "&" would break the file
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord();
        $word->setDefaultFontName(self::FONT);
        $word->setDefaultFontSize(11);
        $word->setDefaultParagraphStyle(['lineHeight' => 1.2, 'spaceAfter' => 0]);
        $section = $word->addSection([
            'marginTop' => Converter::cmToTwip(2.0),
            'marginBottom' => Converter::cmToTwip(2.0),
            'marginLeft' => Converter::cmToTwip(2.0),
            'marginRight' => Converter::cmToTwip(2.0),
        ]);
        Html::addHtml($section, self::xhtmlBody($html), false, false);
        self::nameFontStyles($word, $section);

        $file = tempnam(sys_get_temp_dir(), 'reporion-' . $format . '-');
        if ($file === false) {
            throw new RuntimeException('Cannot create a temporary file');
        }
        try {
            IOFactory::createWriter($word, $format === 'docx' ? 'Word2007' : 'ODText')->save($file);
            $bytes = file_get_contents($file);
        } finally {
            @unlink($file);
        }
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException(strtoupper($format) . ' writer produced nothing');
        }

        return $bytes;
    }

    /**
     * The <body> of $html as well-formed XHTML (PHPWord parses XML), with
     * the print stylesheet's meaningful rules inlined and the browser-only
     * parts (the print button) dropped.
     */
    private static function xhtmlBody(string $html): string
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//*[contains(concat(" ", @class, " "), " print-action ")] | //script | //style') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
        // A text page's body (D40): PHPWord has no <pre>, so one monospace
        // paragraph per line, its spaces kept as no-break spaces
        foreach (iterator_to_array($xpath->query('//pre[contains(concat(" ", @class, " "), " wk-plaintext ")]') ?: []) as $pre) {
            foreach (explode("\n", (string) $pre->textContent) as $line) {
                $paragraph = $doc->createElement('p');
                $paragraph->appendChild($doc->createTextNode($line === '' ? "\u{00A0}" : str_replace(' ', "\u{00A0}", $line)));
                $paragraph->setAttribute('style', 'margin: 0;');
                self::applyStyle($doc, $paragraph, 'font-family: Courier New; font-size: 10pt;');
                $pre->parentNode?->insertBefore($paragraph, $pre);
            }
            $pre->parentNode?->removeChild($pre);
        }
        // A table's <caption> (Service\Markdown\TableCaption): PHPWord drops it, so it becomes a paragraph above the table
        foreach (iterator_to_array($doc->getElementsByTagName('caption')) as $caption) {
            $table = $caption->parentNode;
            $paragraph = $doc->createElement('p');
            while ($caption->firstChild !== null) {
                $paragraph->appendChild($caption->firstChild);
            }
            $table?->parentNode?->insertBefore($paragraph, $table);
            $table?->removeChild($caption);
        }
        // The letterhead's lines are divs, which PHPWord runs together: paragraphs
        foreach (iterator_to_array($xpath->query('//div[contains(concat(" ", @class, " "), " lh-name ") or contains(concat(" ", @class, " "), " lh-sub ") or contains(concat(" ", @class, " "), " lh-rule ")]') ?: []) as $div) {
            $paragraph = $doc->createElement('p');
            $paragraph->setAttribute('class', $div instanceof DOMElement ? $div->getAttribute('class') : '');
            while ($div->firstChild !== null) {
                $paragraph->appendChild($div->firstChild);
            }
            if ($paragraph->firstChild === null) {
                $paragraph->appendChild($doc->createTextNode("\u{00A0}"));
            }
            $div->parentNode?->replaceChild($paragraph, $div);
        }
        // Source indentation around a <br> and at the edges of a cell would print as spaces
        foreach (iterator_to_array($xpath->query('//td') ?: []) as $cell) {
            if ($cell->firstChild instanceof \DOMText) {
                $cell->firstChild->nodeValue = ltrim((string) $cell->firstChild->nodeValue);
            }
            if ($cell->lastChild instanceof \DOMText) {
                $cell->lastChild->nodeValue = rtrim((string) $cell->lastChild->nodeValue);
            }
        }
        foreach (iterator_to_array($xpath->query('//td//text()[preceding-sibling::*[1][self::br]]') ?: []) as $text) {
            $text->nodeValue = ltrim((string) $text->nodeValue);
        }
        // The signature block is opened by one
        foreach (iterator_to_array($xpath->query('//table[contains(concat(" ", @class, " "), " sig ")]//td') ?: []) as $cell) {
            if ($cell instanceof DOMElement) {
                $cell->setAttribute('style', 'border-top: 1pt #D5D5DE solid;');
            }
        }
        // The patient block is closed by a hairline, as in print.css
        foreach (iterator_to_array($xpath->query('//tr[contains(concat(" ", @class, " "), " pt-last ")]/td') ?: []) as $cell) {
            if ($cell instanceof DOMElement) {
                $cell->setAttribute('style', 'border-bottom: 1pt #D5D5DE solid;');
            }
        }
        // PHPWord takes font properties from inline text, not from the cell
        // or block around it: text-align stays on the element, the rest goes
        // on a span wrapping its content
        foreach (iterator_to_array($xpath->query('//*[@class]') ?: []) as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            $style = '';
            $classes = preg_split('/\s+/', $element->getAttribute('class')) ?: [];
            foreach ($classes as $class) {
                $style .= self::CLASS_STYLES[$class] ?? '';
            }
            if (array_intersect($classes, self::UPPERCASE_CLASSES) !== []) {
                foreach (iterator_to_array($xpath->query('.//text()', $element) ?: []) as $text) {
                    $text->nodeValue = mb_strtoupper((string) $text->nodeValue);
                }
            }
            if ($style !== '') {
                self::applyStyle($doc, $element, $style);
            }
        }
        foreach (self::HEADINGS as $tag => $style) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $heading) {
                if (\in_array($tag, self::UPPERCASE_HEADINGS, true)) {
                    // print.css text-transform: uppercase
                    foreach (iterator_to_array($xpath->query('.//text()', $heading) ?: []) as $text) {
                        $text->nodeValue = mb_strtoupper((string) $text->nodeValue);
                    }
                }
                $paragraph = $doc->createElement('p');
                while ($heading->firstChild !== null) {
                    $paragraph->appendChild($heading->firstChild);
                }
                $heading->parentNode?->replaceChild($paragraph, $heading);
                self::applyStyle($doc, $paragraph, $style);
            }
        }
        // Body text: room after each paragraph and list item, as print.css's margins
        foreach (iterator_to_array($xpath->query('//div[contains(concat(" ", @class, " "), " body ")]//*[self::p or self::li or self::dd or self::dt][not(ancestor::td) and not(ancestor::th)]') ?: []) as $block) {
            if ($block instanceof DOMElement) {
                $block->setAttribute('style', self::SPACE_AFTER . $block->getAttribute('style'));
            }
        }
        // A paragraph's worth of air after a table and before the signature
        foreach (iterator_to_array($xpath->query('//div[contains(concat(" ", @class, " "), " body ")]//table | //table[contains(concat(" ", @class, " "), " sig ")]') ?: []) as $table) {
            $isSignature = $table instanceof DOMElement && str_contains(' ' . $table->getAttribute('class') . ' ', ' sig ');
            $gap = $doc->createElement('p');
            $gap->setAttribute('style', 'margin-bottom: 0;');
            $gap->appendChild($doc->createTextNode("\u{00A0}"));
            if ($isSignature) {
                $gap->setAttribute('style', 'margin-bottom: 8pt;');
                $table->parentNode?->insertBefore($gap, $table);
            } else {
                $table->parentNode?->insertBefore($gap, $table->nextSibling);
            }
        }
        // A report's tables (class-less, inside .body): a heavy rule under the header, hairlines between rows
        foreach (iterator_to_array($xpath->query('//div[contains(concat(" ", @class, " "), " body ")]//th') ?: []) as $th) {
            if ($th instanceof DOMElement) {
                $th->setAttribute('style', 'border-bottom: 2pt #1A1A1F solid;');
                self::applyStyle($doc, $th, 'font-weight: bold; font-size: 10pt;');
            }
        }
        foreach (iterator_to_array($xpath->query('//div[contains(concat(" ", @class, " "), " body ")]//td') ?: []) as $td) {
            if ($td instanceof DOMElement) {
                $td->setAttribute('style', 'border-bottom: 1pt #D5D5DE solid;');
                self::applyStyle($doc, $td, 'font-size: 10pt;');
            }
        }
        // Full-width tables, as in print.css
        foreach (iterator_to_array($xpath->query('//table') ?: []) as $table) {
            if ($table instanceof DOMElement) {
                $table->setAttribute('style', 'width: 100%;' . $table->getAttribute('style'));
            }
        }
        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            if ($img instanceof DOMElement) {
                self::placeImage($img);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $xhtml = '';
        foreach (iterator_to_array($body?->childNodes ?? []) as $child) {
            $xhtml .= $doc->saveXML($child);
        }

        return $xhtml;
    }

    /**
     * PHPWord's ODT writer drops inline text styles inside table cells
     * which is where the letterhead, the patient block and the signature
     * live, but keeps named ones. So every inline font style becomes a
     * named style.
     */
    private static function nameFontStyles(PhpWord $word, AbstractContainer|Row|Table|Cell $container): void
    {
        $children = match (true) {
            $container instanceof Table => $container->getRows(),
            $container instanceof Row => $container->getCells(),
            default => $container->getElements(),
        };
        if ($container instanceof Table && $container->getStyle() instanceof TableStyle) {
            $container->getStyle()->setCellMarginTop(Converter::pointToTwip(2));
            $container->getStyle()->setCellMarginBottom(Converter::pointToTwip(2));
            $container->getStyle()->setCellMarginRight(Converter::pointToTwip(6));
            $container->getStyle()->setCellMarginLeft(0);
            self::sizeColumns($container);
        }
        foreach ($children as $element) {
            if ($element instanceof Text && $element->getFontStyle() instanceof Font) {
                $font = $element->getFontStyle();
                $props = array_filter([
                    'bold' => $font->isBold() ?: null,
                    'italic' => $font->isItalic() ?: null,
                    'allCaps' => $font->isAllCaps() ?: null,
                    'color' => $font->getColor(),
                    'size' => $font->getSize(),
                    'name' => $font->getName(),
                    'underline' => $font->getUnderline() !== Font::UNDERLINE_NONE ? $font->getUnderline() : null,
                ], static fn (mixed $value): bool => $value !== null);
                if ($props !== []) {
                    $name = 'rp' . substr(md5(serialize($props)), 0, 10);
                    $word->addFontStyle($name, $props);
                    $element->setFontStyle($name, $element->getParagraphStyle());
                }
            }
            if ($element instanceof AbstractContainer || $element instanceof Table || $element instanceof Row) {
                self::nameFontStyles($word, $element);
            }
        }
    }

    /**
     * A table whose first row sizes its cells (the patient block, the letterhead
     * and the signature) keeps those columns, in whole twips: PHPWord writes a
     * percentage as it is and the cells nobody sized as 5000, so the columns
     * are worked out here, the unsized ones sharing what is left.
     */
    private static function sizeColumns(Table $table): void
    {
        $first = $table->getRows()[0] ?? null;
        $columns = [];
        $sized = false;
        foreach ($first === null ? [] : $first->getCells() as $cell) {
            $style = $cell->getStyle();
            $width = (float) $cell->getWidth();
            $unit = $style->getUnit();
            if ($unit === TblWidth::TWIP && $width > 0) {
                $columns[] = (int) round($width);
            } elseif ($unit === TblWidth::PERCENT && $width > 0 && $width !== 5000.0) {
                $columns[] = (int) round($width / 5000 * self::TEXT_WIDTH_TWIP);
            } else {
                $columns[] = null;
            }
            $sized = $sized || end($columns) !== null;
            for ($i = 1, $span = max(1, (int) $style->getGridSpan()); $i < $span; ++$i) {
                $columns[] = null;
            }
        }
        if (!$sized) {
            return;
        }
        $open = \count(array_filter($columns, static fn (?int $c): bool => $c === null));
        $rest = $open > 0 ? max(0, intdiv(self::TEXT_WIDTH_TWIP - (int) array_sum($columns), $open)) : 0;
        $columns = array_map(static fn (?int $c): int => $c ?? $rest, $columns);

        $style = $table->getStyle();
        if ($style instanceof TableStyle) {
            $style->setLayout(TableStyle::LAYOUT_FIXED);
            $style->setWidth(self::TEXT_WIDTH_TWIP);
            $style->setUnit(TblWidth::TWIP);
        }
        foreach ($table->getRows() as $row) {
            $index = 0;
            foreach ($row->getCells() as $cell) {
                $span = max(1, (int) $cell->getStyle()->getGridSpan());
                $width = (int) array_sum(\array_slice($columns, $index, $span));
                $cell->getStyle()->setWidth($width);
                $cell->getStyle()->setUnit(TblWidth::TWIP);
                // The table grid reads the cell's own width, which has no setter
                \Closure::bind(static function (Cell $cell, int $width): void {
                    $cell->width = $width;
                }, null, Cell::class)($cell, $width);
                $index += $span;
            }
        }
    }

    private static function applyStyle(DOMDocument $doc, DOMElement $element, string $style): void
    {
        $block = '';
        $font = '';
        foreach (array_filter(array_map('trim', explode(';', $style))) as $declaration) {
            $property = strtolower(trim(explode(':', $declaration, 2)[0]));
            if (\in_array($property, self::BLOCK_PROPERTIES, true)) {
                $block .= $declaration . ';';
            } else {
                $font .= $declaration . ';';
            }
        }
        if ($block !== '') {
            $element->setAttribute('style', $block . $element->getAttribute('style'));
        }
        if ($font === '' || $element->firstChild === null) {
            return;
        }
        $span = $doc->createElement('span');
        $span->setAttribute('style', $font);
        while ($element->firstChild !== null) {
            $span->appendChild($element->firstChild);
        }
        $element->appendChild($span);
    }

    /**
     * A paragraph holding only an image becomes the image itself: PHPWord
     * writes an image inside a paragraph as a paragraph inside a paragraph,
     * which LibreOffice drops. Wider than the text column: scaled down.
     */
    private static function placeImage(DOMElement $img): void
    {
        $src = $img->getAttribute('src');
        $info = str_starts_with($src, 'data:image/')
            ? @getimagesizefromstring((string) base64_decode(substr($src, (int) strpos($src, ',') + 1)))
            : false;
        if (\is_array($info) && $info[0] > 0) {
            $width = min($info[0], self::MAX_IMAGE_PX);
            $img->setAttribute('width', (string) $width);
            $img->setAttribute('height', (string) (int) round($info[1] * $width / $info[0]));
        }

        $parent = $img->parentNode;
        if ($parent instanceof DOMElement && $parent->tagName === 'p' && trim($parent->textContent) === '' && $parent->getElementsByTagName('img')->length === 1) {
            $parent->parentNode?->replaceChild($img, $parent);
        }
    }
}
