<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Epub;

use DateTimeImmutable;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Plugin\Container;
use Reporion\Plugin\Hooks;
use Reporion\Plugin\PluginInterface;
use Reporion\Service\PrintView;
use Reporion\Service\Render;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\NamespaceSort;
use Reporion\Support\ReportPath;

/**
 * EPUB export: a namespace as a book (`GET /x/epub/{pid}`). The page named
 * like the namespace (or its `_index`) is the front matter, every page directly
 * in the namespace a chapter, in the namespace's sort order. Chapters come from
 * IndexInterface::listNamespace() under the caller's visibility (invariant 6).
 * It never handles patient data: a report or a page with a patient block is a
 * 404 as the entry page and is left out as a chapter. No media is embedded.
 * Anonymous, invisible and chapterless are the same 404 (invariant 9). The
 * plugin writes no page (invariant 5).
 */
final class Plugin implements PluginInterface
{
    private IndexInterface $index;

    private StorageInterface $storage;

    private AuditLog $audit;

    private Render $render;

    /** @var array<string, mixed> */
    private array $settings;

    public function register(Hooks $hooks, Container $container): void
    {
        $this->settings = $container->settings();
        $this->index = $container->get(IndexInterface::class);
        $this->storage = $container->get(StorageInterface::class);
        $this->audit = $container->get(AuditLog::class);
        $this->render = $container->get(Render::class);

        $hooks->route('GET', '/{pid}', fn (Request $request, array $params, ?User $principal): Response => $this->export($request, $params, $principal));
    }

    /**
     * @param array<string, string> $params
     */
    private function export(Request $request, array $params, ?User $principal): Response
    {
        $row = $principal !== null ? $this->index->findByPid($params['pid'], $principal) : null;
        if ($principal === null || $row === null || ReportPath::isReport((string) $row['path'])) {
            throw new PageNotFoundException();
        }
        $record = $this->storage->read((string) $row['path']);
        if (isset($record->frontmatter['patient'])) {
            throw new PageNotFoundException();
        }

        // `ns:_index` describes the namespace `ns`; any other page is the namespace of its own name
        $segments = explode(':', $record->path);
        $ns = end($segments) === '_index' ? implode(':', \array_slice($segments, 0, -1)) : $record->path;
        $rows = $ns === '' ? [] : NamespaceSort::sort($this->index->listNamespace($ns, $principal), NamespaceSort::modeFor($this->index, $ns, $principal));

        $chapters = [];
        foreach ($rows as $child) {
            $path = (string) $child['path'];
            if ($path === $record->path || str_ends_with($path, ':_index') || ReportPath::isReport($path)) {
                continue;
            }
            $page = $this->storage->read($path);
            if (isset($page->frontmatter['patient'])) {
                continue;
            }
            $chapters[] = $this->section($page);
        }
        if ($chapters === []) {
            throw new PageNotFoundException();
        }

        $title = Titles::of($record);
        $epub = Builder::build(
            ['title' => $title, 'html' => $this->section($record)['html']],
            $chapters,
            (string) ($this->settings['language'] ?? 'ro'),
            Builder::identifier($record->pid),
            new DateTimeImmutable(),
            t('epub.contents'),
        );
        $this->audit->record('export', $principal->username, $request, $record->pid, $record->path, $record->rev, extra: ['format' => 'epub', 'chapters' => \count($chapters)]);

        return new Response(200, $epub, [
            'Content-Type' => 'application/epub+zip',
            'Content-Disposition' => 'attachment; filename="' . PrintView::fileName($record, 'epub', true) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{title: string, html: string} */
    private function section(PageRecord $page): array
    {
        $title = Titles::of($page);
        $html = $this->render->body(Titles::withoutHeading($page->body, $title), $page->frontmatter, unlinkPages: true)->html;

        return ['title' => $title, 'html' => Xhtml::fragment($html)];
    }
}
