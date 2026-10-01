<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * The breadcrumb trail above a page's title, as landmark markup:
 * `<nav aria-label="Breadcrumb"><ol><li>…</li></ol></nav>`, the last item
 * `aria-current="page"`. The separators are drawn by CSS (`.wk-crumbs li +
 * li::before`), so they are not in the markup for a screen reader to read.
 *
 * An item without an href — the current page, or a level that has no page of
 * its own, like "Admin" — is plain text.
 */
final class Breadcrumb
{
    /**
     * Spaces, then every level of $ns, each linked to its listing; the caller
     * appends what comes after (the page, or the screen it is on).
     *
     * @return list<array{label: string, href: string}>
     */
    public static function namespaceTrail(string $basePath, string $ns): array
    {
        $trail = [['label' => t('ns.root_title'), 'href' => $basePath . '/:']];
        $prefix = [];
        foreach ($ns === '' ? [] : explode(':', $ns) as $segment) {
            $prefix[] = $segment;
            $trail[] = ['label' => $segment, 'href' => $basePath . '/' . implode(':', $prefix) . ':'];
        }

        return $trail;
    }

    /**
     * @param list<array{label: string, href?: ?string, icon?: ?string}> $items the trail, the current page last
     * @param string $after trusted HTML placed after the trail inside the nav (a badge, a copy button)
     */
    public static function render(array $items, string $after = ''): string
    {
        $last = \count($items) - 1;
        $html = '';
        foreach ($items as $i => $item) {
            $label = htmlspecialchars($item['label'], ENT_QUOTES);
            $icon = isset($item['icon']) && $item['icon'] !== '' ? '<i class="ph ph-' . htmlspecialchars($item['icon'], ENT_QUOTES) . '" aria-hidden="true"></i>' : '';
            if ($i === $last) {
                $inner = '<span aria-current="page">' . $label . '</span>';
            } elseif (isset($item['href']) && $item['href'] !== '') {
                $inner = '<a href="' . htmlspecialchars($item['href'], ENT_QUOTES) . '">' . $label . '</a>';
            } else {
                $inner = '<span>' . $label . '</span>';
            }
            $html .= '<li>' . $icon . $inner . '</li>';
        }

        return '<nav class="wk-crumbs wk-mono" aria-label="' . htmlspecialchars(t('nav.breadcrumb'), ENT_QUOTES) . '"><ol>' . $html . '</ol>' . $after . '</nav>';
    }
}
