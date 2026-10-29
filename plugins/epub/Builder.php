<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Epub;

use DateTimeImmutable;
use Reporion\Support\Zip;

/**
 * An EPUB 3 package (with an NCX for older readers) from already-rendered
 * XHTML fragments. Pure assembly: no I/O, no page access. The container is
 * Support\Zip with every entry stored and `mimetype` first, as the format
 * requires.
 */
final class Builder
{
    private const CSS = "body { font-family: serif; line-height: 1.4; margin: 1em; }\nh1, h2, h3, h4 { font-family: sans-serif; line-height: 1.2; }\npre, code { font-family: monospace; font-size: 0.9em; }\npre { white-space: pre-wrap; }\ntable { border-collapse: collapse; }\nth, td { border: 1px solid #888; padding: 0.2em 0.5em; }\nblockquote { margin-left: 1em; padding-left: 0.8em; border-left: 3px solid #bbb; }\n";

    /**
     * @param array{title: string, html: string}       $front    the front matter: the book's title and body
     * @param list<array{title: string, html: string}> $chapters in reading order
     * @param array<string, mixed>                     $meta     Meta::from(); a language in it wins over $language
     */
    public static function build(array $front, array $chapters, string $language, string $identifier, DateTimeImmutable $modified, string $contentsTitle, array $meta = []): string
    {
        if (($meta['language'] ?? '') !== '') {
            $language = (string) $meta['language'];
        }
        $files = ['mimetype' => 'application/epub+zip'];
        $files['META-INF/container.xml'] = self::xml('<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
        $files['OEBPS/style.css'] = self::CSS;

        $docs = [['id' => 'front', 'file' => 'front.xhtml', 'title' => $front['title']]];
        $files['OEBPS/front.xhtml'] = self::page($front['title'], $language, $front['html']);
        foreach ($chapters as $i => $chapter) {
            $id = sprintf('ch%03d', $i + 1);
            $docs[] = ['id' => $id, 'file' => $id . '.xhtml', 'title' => $chapter['title']];
            $files['OEBPS/' . $id . '.xhtml'] = self::page($chapter['title'], $language, $chapter['html']);
        }

        $nav = '';
        $ncx = '';
        foreach ($docs as $n => $doc) {
            $nav .= '<li><a href="' . $doc['file'] . '">' . self::e($doc['title']) . '</a></li>';
            $ncx .= '<navPoint id="n' . ($n + 1) . '" playOrder="' . ($n + 1) . '"><navLabel><text>' . self::e($doc['title']) . '</text></navLabel><content src="' . $doc['file'] . '"/></navPoint>';
        }
        $files['OEBPS/nav.xhtml'] = self::xml('<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="' . self::e($language) . '"><head><meta charset="utf-8"/><title>' . self::e($contentsTitle) . '</title></head><body><nav epub:type="toc" id="toc"><h1>' . self::e($contentsTitle) . '</h1><ol>' . $nav . '</ol></nav></body></html>');
        $files['OEBPS/toc.ncx'] = self::xml('<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head><meta name="dtb:uid" content="' . self::e($identifier) . '"/></head><docTitle><text>' . self::e($front['title']) . '</text></docTitle><navMap>' . $ncx . '</navMap></ncx>');

        $manifest = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/><item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/><item id="css" href="style.css" media-type="text/css"/>';
        $spine = '';
        foreach ($docs as $doc) {
            $manifest .= '<item id="' . $doc['id'] . '" href="' . $doc['file'] . '" media-type="application/xhtml+xml"/>';
            $spine .= '<itemref idref="' . $doc['id'] . '"/>';
        }
        $files['OEBPS/content.opf'] = self::xml('<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid" xml:lang="' . self::e($language) . '"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="uid">' . self::e($identifier) . '</dc:identifier><dc:title>' . self::e($front['title']) . '</dc:title><dc:language>' . self::e($language) . '</dc:language>' . self::describe($meta) . '<meta property="dcterms:modified">' . $modified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') . '</meta></metadata><manifest>' . $manifest . '</manifest><spine toc="ncx"><itemref idref="nav" linear="no"/>' . $spine . '</spine></package>');

        return Zip::build($files, $modified);
    }

    /** The optional dc: elements of the book's Meta */
    private static function describe(array $meta): string
    {
        $out = '';
        foreach ((array) ($meta['authors'] ?? []) as $author) {
            $out .= '<dc:creator>' . self::e((string) $author) . '</dc:creator>';
        }
        foreach (['publisher' => 'publisher', 'description' => 'description', 'rights' => 'rights'] as $key => $element) {
            if (($meta[$key] ?? '') !== '') {
                $out .= '<dc:' . $element . '>' . self::e((string) $meta[$key]) . '</dc:' . $element . '>';
            }
        }
        foreach ((array) ($meta['subjects'] ?? []) as $subject) {
            $out .= '<dc:subject>' . self::e((string) $subject) . '</dc:subject>';
        }

        return $out;
    }

    /** A urn:uuid that is the same for the same page every time */
    public static function identifier(string $pid): string
    {
        $h = sha1('reporion-epub:' . $pid);

        return 'urn:uuid:' . substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-5' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    private static function page(string $title, string $language, string $html): string
    {
        return self::xml('<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="' . self::e($language) . '"><head><meta charset="utf-8"/><title>' . self::e($title) . '</title><link rel="stylesheet" type="text/css" href="style.css"/></head><body><h1>' . self::e($title) . '</h1>' . $html . '</body></html>');
    }

    private static function xml(string $root): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $root . "\n";
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
