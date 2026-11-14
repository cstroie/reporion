<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Service\Library\Abstracts;
use Reporion\Service\Library\Analyses;
use Reporion\Storage\StorageInterface;
use Reporion\Support\HeadPage;
use Reporion\Support\NamespaceSort;
use Reporion\Support\ReportPath;

/**
 * What a head page (D61, Support\HeadPage) shows of the namespace it owns:
 * its direct pages and sub-namespaces, as its `sort` orders them, read
 * through the listing predicate (invariant 6: a page the caller may not list
 * is not there). Left out: `_index`/`_template`, reports, pages with a
 * patient block, and a library source's analyses and abstract (their own card).
 * A sub-namespace with no head the caller sees is a plain entry to `/{ns}:`.
 */
final class HeadChildren
{
    public const MAX_DEPTH = 4;

    public function __construct(
        private readonly IndexInterface $index,
        private readonly StorageInterface $storage,
        private readonly Render $render,
    ) {
    }

    /**
     * The tree under $head: each node `{path, title, summary, priority, head: bool, ns: ?string, children}`,
     * children filled to $depth levels (1: direct only)
     *
     * @return list<array<string, mixed>>
     */
    public function tree(string $head, ?User $principal, int $depth = 1): array
    {
        if (!HeadPage::applies($head)) {
            return [];
        }
        $ns = HeadPage::namespaceOf($head);
        $meta = [];
        foreach ($this->index->listNamespaceMeta($ns, $principal) as $row) {
            $meta[$row['path']] = $row['meta'];
        }
        $rows = array_values(array_filter(
            $this->index->listNamespace($ns, $principal),
            static fn (array $row): bool => self::shown((string) $row['path'], $meta[$row['path']] ?? []),
        ));
        $byPath = [];
        foreach ($rows as $row) {
            $byPath[(string) $row['path']] = $row;
        }
        // A sub-namespace without a page of its name the caller lists: an entry of its own
        foreach ($this->index->listSubnamespaces($ns, $principal) as $sub) {
            $path = $ns . ':' . $sub['name'];
            if (!isset($byPath[$path]) && HeadPage::applies($path)) {
                $byPath[$path] = ['path' => $path, 'title' => '', 'summary' => null, 'updated' => '', 'folder' => true];
            }
        }
        $sorted = NamespaceSort::sort(array_values($byPath), NamespaceSort::modeFor($this->index, $ns, $principal) ?? 'name');
        $subs = array_flip(array_map(static fn (array $s): string => $ns . ':' . $s['name'], $this->index->listSubnamespaces($ns, $principal)));

        $nodes = [];
        foreach ($sorted as $row) {
            $path = (string) $row['path'];
            $isHead = isset($subs[$path]);
            $nodes[] = [
                'path' => $path,
                'title' => trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : ReportPath::leaf($path),
                'summary' => isset($row['summary']) && $row['summary'] !== '' ? (string) $row['summary'] : null,
                'priority' => \is_string($meta[$path]['priority'] ?? null) ? $meta[$path]['priority'] : null,
                'page' => !($row['folder'] ?? false),
                'head' => $isHead,
                'children' => $isHead && $depth > 1 ? $this->tree($path, $principal, min($depth, self::MAX_DEPTH) - 1) : [],
            ];
        }

        return $nodes;
    }

    /**
     * The `chapters` presentation: each direct child page rendered, sub-heads nested to $depth,
     * heading ids prefixed per chapter so they never collide with the head's
     *
     * @return array{chapters: list<array{path: string, title: string, id: string, html: string, level: int}>, toc: list<array{level: int, text: string, slug: string}>}
     */
    public function chapters(string $head, ?User $principal, string $basePath, int $depth = 2, int $level = 2, string $prefix = 'ch'): array
    {
        $chapters = [];
        $toc = [];
        $n = 0;
        foreach ($this->tree($head, $principal) as $node) {
            $id = $prefix . '-' . ++$n;
            $html = '';
            if ($node['page']) {
                $record = $this->storage->read($node['path']);
                if ($record === null) {
                    continue;
                }
                $rendered = $this->render->body(self::withoutTitle($record->body), $record->frontmatter, $basePath);
                $html = self::demote((string) preg_replace('/(<h[1-6][^>]*?\sid=")/', '$1' . $id . '-', $rendered->html), $level - 1);
                $toc[] = ['level' => $level, 'text' => $node['title'], 'slug' => $id];
                foreach ($rendered->toc as $entry) {
                    if ($entry['level'] === 2) {
                        $toc[] = ['level' => $level + 1, 'text' => $entry['text'], 'slug' => $id . '-' . $entry['slug']];
                    }
                }
            } else {
                $toc[] = ['level' => $level, 'text' => $node['title'], 'slug' => $id];
            }
            $chapters[] = ['path' => $node['path'], 'title' => $node['title'], 'id' => $id, 'html' => $html, 'level' => $level, 'page' => $node['page']];
            if ($node['head'] && $depth > 1) {
                $nested = $this->chapters($node['path'], $principal, $basePath, $depth - 1, min($level + 1, 6), $id);
                array_push($chapters, ...$nested['chapters']);
                array_push($toc, ...$nested['toc']);
            }
        }

        return ['chapters' => $chapters, 'toc' => $toc];
    }

    /** $html with every heading $by levels down (at most h6): a chapter's sections sit under its title */
    public static function demote(string $html, int $by): string
    {
        return $by <= 0 ? $html : (string) preg_replace_callback('#<(/?)h([1-6])(?=[\s>])#', static fn (array $m): string => '<' . $m[1] . 'h' . min(6, (int) $m[2] + $by), $html);
    }

    /** $body without its leading `#` title: the chapter heading carries it */
    public static function withoutTitle(string $body): string
    {
        return (string) preg_replace('/\A\s*#[ \t]+[^\n]*\n?/', '', $body, 1);
    }

    /** @param array<string, mixed> $meta */
    private static function shown(string $path, array $meta): bool
    {
        return !HeadPage::isLegacy($path) && !str_ends_with($path, ':_template') && !ReportPath::isReport($path)
            && !isset($meta['patient']) && !Analyses::isAnalysis($meta) && !Abstracts::isAbstract($meta);
    }
}
