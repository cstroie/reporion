<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Epub;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Service\Render's HTML as well-formed XHTML for an EPUB content document:
 * parsed leniently, then written back as XML. No images, scripts, styles,
 * forms or event handlers go in (the export embeds no media), and a link that
 * is not http(s), mailto or a same-chapter anchor loses its address and stays
 * as text: a page link would point at nothing inside the book.
 */
final class Xhtml
{
    private const DROP = ['script', 'style', 'img', 'picture', 'svg', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'video', 'audio'];

    public static function fragment(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        self::clean($body);
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveXML($child);
        }

        return $out;
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $name = strtolower($child->tagName);
            if (\in_array($name, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $attr = strtolower($attribute->name);
                if (str_starts_with($attr, 'on') || $attr === 'style' || str_contains($attr, ':') || ($attr === 'src')) {
                    $child->removeAttribute($attribute->name);
                }
            }
            self::clean($child);
            if ($name === 'a') {
                $href = trim($child->getAttribute('href'));
                if ($href !== '' && preg_match('~^(https?:|mailto:|#)~i', $href) !== 1) {
                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                }
            }
        }
    }
}
