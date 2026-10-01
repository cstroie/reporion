<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Service\Snippets;
use Reporion\Support\Templates;

/**
 * The quick-navigation list (2026-10-01): one list, read by the top nav's
 * pin menu, the drawer and the palette's empty state, so the three never
 * drift apart. Three groups —
 *
 * - fixed: home and the root namespace;
 * - pinned: the account's own pins (Auth\Pins), in the order pinned;
 * - related: worked out from the namespace on screen, no setup — under
 *   `reports:{mod}…` the modality's templates and snippets, under
 *   `templates:{mod}` / `templates:snippets:{mod}` the other two and the
 *   modality's reports. Only namespaces the caller can see something in
 *   (listSubnamespaces(), invariant 6), at most two queries.
 *
 * Signed-in callers only: an anonymous visitor gets no list.
 */
final class QuickNav
{
    public const REPORTS = 'reports';

    /**
     * @return array{fixed: list<array{href: string, label: string, icon: string}>, pinned: list<array{href: string, label: string, icon: string}>, related: list<array{href: string, label: string, icon: string}>, here: string, herePinned: bool}
     */
    public static function links(?User $principal, IndexInterface $index, string $ns): array
    {
        if ($principal === null) {
            return ['fixed' => [], 'pinned' => [], 'related' => [], 'here' => '', 'herePinned' => false];
        }

        $pinned = array_map(static fn (string $pin): array => self::link($pin, 'push-pin'), $principal->pins);
        $related = [];
        foreach (self::relatedTo($ns, $index, $principal) as [$candidate, $icon]) {
            if ($candidate !== $ns && !\in_array($candidate, $principal->pins, true)) {
                $related[] = self::link($candidate, $icon);
            }
        }

        return [
            'fixed' => [
                ['href' => '/', 'label' => t('quick.home'), 'icon' => 'house'],
                ['href' => '/:', 'label' => t('quick.root'), 'icon' => 'tree-structure'],
            ],
            'pinned' => $pinned,
            'related' => $related,
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
