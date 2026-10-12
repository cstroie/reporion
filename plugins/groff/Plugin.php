<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Groff;

use Closure;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Plugin\Container;
use Reporion\Plugin\Hooks;
use Reporion\Plugin\PluginInterface;
use Reporion\Plugin\Groff\Ms\Document;
use Reporion\Plugin\Groff\Ms\HtmlToMs;
use Reporion\Service\PrintView;
use Reporion\Service\Render;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;

/**
 * groff export (2026-10-12, D42): an ordinary page as groff `ms` source
 * (`GET /x/groff/ms/{pid}`) and as a PDF typeset by groff (`/x/groff/pdf/{pid}`)
 * — nice PDF files from Markdown notes and documents. It never handles
 * patient data: a report (Support\ReportPath), a page that carries a patient
 * block, an invisible page and an anonymous caller are all the same 404
 * (invariants 6 and 9). The current revision is exported, signed or not: only
 * reports are signed. The preamble and the font family are the plugin's
 * settings; the body is the canonical HTML of Service\Render turned into ms
 * (Ms\HtmlToMs). The plugin writes no page (invariant 5).
 */
final class Plugin implements PluginInterface
{
    /** Tests replace the program groff runs as: (argv, output file, timeout) → [exit, stderr] */
    public static ?Closure $runner = null;

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

        $hooks->route('GET', '/ms/{pid}', fn (Request $request, array $params, ?User $principal): Response => $this->export($request, $params, $principal, 'ms'));
        $hooks->route('GET', '/pdf/{pid}', fn (Request $request, array $params, ?User $principal): Response => $this->export($request, $params, $principal, 'pdf'));
    }

    /**
     * @param array<string, string> $params
     */
    private function export(Request $request, array $params, ?User $principal, string $kind): Response
    {
        $row = $principal !== null ? $this->index->findByPid($params['pid'], $principal) : null;
        if ($principal === null || $row === null || ReportPath::isReport((string) $row['path'])) {
            throw new PageNotFoundException();
        }
        $record = $this->storage->read((string) $row['path']);
        // Patient data is not this plugin's business: a page with a patient block is refused like a report
        if (isset($record->frontmatter['patient'])) {
            throw new PageNotFoundException();
        }

        $font = (string) $this->settings['font'];
        $title = Document::title($record);
        $source = Document::build(
            $record,
            HtmlToMs::convert($this->render->body(Document::withoutTitleHeading($record->body, $title), $record->frontmatter, unlinkPages: true)->html),
            (string) $this->settings['preamble'],
            $font,
        );

        if ($kind === 'ms') {
            $this->audit->record('export', $principal->username, $request, $record->pid, $record->path, $record->rev, extra: ['format' => 'ms']);

            return new Response(200, $source, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . PrintView::fileName($record, 'ms', true) . '"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        try {
            $pdf = (new Groff((string) $this->settings['groff'], $font, (int) $this->settings['timeout'], self::$runner))->pdf($source);
        } catch (GroffException $e) {
            // groff's own words can quote the page: the owner sees them, nobody else
            $text = t('groff.err.' . $e->getMessage()) . ($principal->isOwner && $e->log !== '' ? "\n\n" . t('groff.err.log') . "\n" . $e->log : '');

            return new Response($e->getMessage() === 'timeout' ? 504 : 503, $text, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        }
        $this->audit->record('export', $principal->username, $request, $record->pid, $record->path, $record->rev, extra: ['format' => 'groff-pdf']);

        return new Response(200, $pdf, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . PrintView::fileName($record, 'pdf', true) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
