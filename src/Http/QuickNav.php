<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Service\Snippets;
use Reporion\Support\ReportPath;
use Reporion\Support\Templates;

/**
 * The quick-navigation list (2026-10-01): one list, read by the top nav's
 * pin menu and the palette's empty state, so the two never
 * drift apart. Three groups
 *
 * - fixed: home, the account's own pages and the notes app (D55), the library (for a reader of it), the root
 *   namespace and Ask the archive when it is there (D53);
 * - pinned: the account's own pins (Auth\Pins), in the order pinned;
 * - views: the account's saved views (Auth\SavedViews), named searches;
 * - related: worked out from the namespace on screen, no setup: under
 *   `reports:{mod}…` the modality's templates and snippets, under
 *   `templates:{mod}` / `templates:snippets:{mod}` the other two and the
 *   modality's reports. Only namespaces the caller can see something in
 *   (listSubnamespaces(), invariant 6), at most two queries.
 *
 * Signed-in callers only: an anonymous visitor gets no list.
 */
final class QuickNav
{
    public const REPORTS = ReportPath::ROOT;

    /**
     * @return array{fixed: list<array{href: string, label: string, icon: string}>, pinned: list<array{href: string, label: string, icon: string, priority?: string}>, views: list<array{href: string, label: string, icon: string}>, related: list<array{href: string, label: string, icon: string, priority?: string}>, here: string, herePinned: bool}
     */
    public static function links(?User $principal, IndexInterface $index, string $ns, bool $askable = false): array
    {
        if ($principal === null) {
            return ['fixed' => [], 'pinned' => [], 'views' => [], 'related' => [], 'here' => '', 'herePinned' => false];
        }

        $pinned = array_map(static fn (string $pin): array => self::link($pin, 'push-pin'), $principal->pins);
        $related = [];
        foreach (self::relatedTo($ns, $index, $principal) as [$candidate, $icon]) {
            if ($candidate !== $ns && !\in_array($candidate, $principal->pins, true)) {
                $related[] = self::link($candidate, $icon);
            }
        }

        // Every account's own pages (D46)
        $own = $principal->personalNamespace();
        $fixed = [
            ['href' => '/', 'label' => t('quick.home'), 'icon' => 'house'],
            ...($own !== null ? [['href' => '/' . $own . ':', 'label' => t('quick.mine'), 'icon' => 'user']] : []),
            // The notes app keeps its notes in the account's own namespace (D55)
            ...($own !== null ? [['href' => '/notes', 'label' => t('quick.notes'), 'icon' => 'note-pencil']] : []),
            ['href' => '/:', 'label' => t('quick.root'), 'icon' => 'tree-structure'],
            ...($askable ? [['href' => '/ask', 'label' => t('quick.ask'), 'icon' => 'chats-circle']] : []),
            // The library (docs/library.md), for whoever may read it
            ...($principal->canRead('library') ? [['href' => '/library', 'label' => t('quick.library'), 'icon' => 'books']] : []),
        ];

        // Each namespace's `priority` (low|medium|high, its description page's
        // frontmatter) tints its chip, as it tints the namespace card: one
        // findByPaths(), the caller's visibility applied (invariant 6)
        $paths = array_values(array_unique(array_column([...$pinned, ...$related], 'label')));
        $rows = $paths === [] ? [] : $index->findByPaths($paths, $principal);
        $tint = static function (array $link) use ($rows): array {
            $meta = json_decode((string) ($rows[$link['label']]['meta_json'] ?? ''), true);
            $priority = \is_array($meta) && \is_string($meta['priority'] ?? null) ? trim($meta['priority']) : '';

            return $link + ['priority' => \in_array($priority, ['low', 'medium', 'high'], true) ? $priority : ''];
        };

        return [
            'fixed' => $fixed,
            'pinned' => array_map($tint, $pinned),
            // Saved views (2026-10-19): named searches, `/search?{query}`
            'views' => array_map(static fn (array $view): array => ['href' => '/search?' . $view['query'], 'label' => $view['name'], 'icon' => 'funnel'], $principal->views),
            'related' => array_map($tint, $related),
            'here' => $ns,
            'herePinned' => \in_array($ns, $principal->pins, true),
        ];
    }

    /**
     * The modality namespaces that go with $ns, those the caller can see.
     *
     * @return list<array{0: string, 1: string}> namespace, icon
     */
    private static function relatedTo(string $ns, IndexInterface $index, User $principal): array
    {
        $segments = $ns === '' ? [] : explode(':', $ns);
        $mod = match (true) {
            ($segments[0] ?? '') === self::REPORTS => $segments[1] ?? null,
            ($segments[0] ?? '') !== Templates::NS => null,
            ($segments[1] ?? '') === 'snippets' => $segments[2] ?? null,
            default => $segments[1] ?? null,
        };
        if ($mod === null) {
            return [];
        }

        $candidates = [
            [self::REPORTS, self::REPORTS . ':' . $mod, 'files'],
            [Templates::NS, Templates::NS . ':' . $mod, 'file-text'],
            [Snippets::NS, Snippets::NS . ':' . $mod, 'lightning'],
        ];
        $seen = [];
        $related = [];
        foreach ($candidates as [$parent, $candidate, $icon]) {
            if ($candidate === $ns || str_starts_with($ns, $candidate . ':')) {
                continue; // where the caller already is, or inside it
            }
            $seen[$parent] ??= array_column($index->listSubnamespaces($parent, $principal), 'name');
            if (\in_array($mod, $seen[$parent], true)) {
                $related[] = [$candidate, $icon];
            }
        }

        return $related;
    }

    /** @return array{href: string, label: string, icon: string} */
    private static function link(string $ns, string $icon): array
    {
        return ['href' => '/' . $ns . ':', 'label' => $ns, 'icon' => $icon];
    }
}
