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
use RuntimeException;
use ZipArchive;

/**
 * ODT export (docs/architecture-api.md, decided 2026-09-26): the same HTML
 * the print view and the PDF render (templates/print/report.php), turned
 * into an OpenDocument text through PHPWord — not a second template. The
 * report stays editable in LibreOffice, which is what ODT is for.
 *
 * PHPWord reads inline styles only, so the handful of print.css rules that
 * carry meaning (muted labels, bold values, headings, the draft band) are
 * copied onto the elements as inline style first. PHPWord writes the zip
 * with its bundled PclZip when PHP has no zip extension.
 */
final class OdtExport
{
    /** Headings as styled paragraphs: PHPWord's heading styles do not survive into ODT */
    private const HEADINGS = [
        'h1' => 'font-size: 14pt; font-weight: bold;',
        'h2' => 'font-size: 8.5pt; font-weight: bold; color: #333333;',
        'h3' => 'font-size: 9.5pt; font-weight: bold;',
    ];

    /** The text column: 210 mm − 2 × 18 mm, in CSS px at 96 dpi */
    private const MAX_IMAGE_PX = 657;

    /** print.css, as inline styles PHPWord understands (class → style) */
    private const CLASS_STYLES = [
        'lh-name' => 'font-size: 11pt; font-weight: bold;',
        'lh-right' => 'text-align: right; font-size: 8pt; color: #555555;',
        'lh-sub' => 'font-size: 8pt; color: #555555;',
        'pt-k' => 'color: #555555; font-size: 8.5pt;',
        'pt-k2' => 'color: #555555; font-size: 8.5pt;',
        'pt-v' => 'font-weight: bold; font-size: 8.5pt;',
        'draft-band' => 'text-align: center; color: #666666; font-size: 8pt; font-weight: bold;',
        'sig-right' => 'text-align: right; font-size: 7.5pt; color: #555555;',
        'verify' => 'font-family: DejaVu Sans Mono; font-size: 7pt;',
    ];

    public function render(string $html): string
    {
        if (!class_exists(ZipArchive::class)) {
            Settings::setZipClass(Settings::PCLZIP);
        }

        $word = new PhpWord();
        $word->setDefaultFontName('DejaVu Sans');
        $word->setDefaultFontSize(9.5);
        $section = $word->addSection([
            'marginTop' => Converter::cmToTwip(1.8),
            'marginBottom' => Converter::cmToTwip(2.0),
            'marginLeft' => Converter::cmToTwip(1.8),
            'marginRight' => Converter::cmToTwip(1.8),
        ]);
        Html::addHtml($section, self::xhtmlBody($html), false, false);
        self::nameFontStyles($word, $section);

        $file = tempnam(sys_get_temp_dir(), 'reporion-odt-');
        if ($file === false) {
            throw new RuntimeException('Cannot create a temporary file');
        }
        try {
            IOFactory::createWriter($word, 'ODText')->save($file);
            $bytes = file_get_contents($file);
        } finally {
            @unlink($file);
        }
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('ODT writer produced nothing');
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
        // PHPWord takes font properties from inline text, not from the cell
        // or block around it: text-align stays on the element, the rest goes
        // on a span wrapping its content
        foreach (iterator_to_array($xpath->query('//*[@class]') ?: []) as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            $style = '';
            foreach (preg_split('/\s+/', $element->getAttribute('class')) ?: [] as $class) {
                $style .= self::CLASS_STYLES[$class] ?? '';
            }
            if ($style !== '') {
                self::applyStyle($doc, $element, $style);
            }
        }
        foreach (self::HEADINGS as $tag => $style) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $heading) {
                if ($tag === 'h2') {
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
        foreach (iterator_to_array($doc->getElementsByTagName('th')) as $th) {
            if ($th instanceof DOMElement) {
                self::applyStyle($doc, $th, 'font-weight: bold;');
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
     * PHPWord's ODT writer drops inline text styles inside table cells —
     * which is where the letterhead, the patient block and the signature
     * live — but keeps named ones. So every inline font style becomes a
     * named style.
     */
    private static function nameFontStyles(PhpWord $word, AbstractContainer|Row|Table|Cell $container): void
    {
        $children = match (true) {
            $container instanceof Table => $container->getRows(),
            $container instanceof Row => $container->getCells(),
            default => $container->getElements(),
        };
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

    private static function applyStyle(DOMDocument $doc, DOMElement $element, string $style): void
    {
        $align = preg_match('/text-align:\s*(\w+);?/', $style, $m) === 1 ? $m[1] : null;
        $font = trim((string) preg_replace('/text-align:\s*\w+;?/', '', $style));
        if ($align !== null) {
            $element->setAttribute('style', 'text-align: ' . $align . ';' . $element->getAttribute('style'));
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
