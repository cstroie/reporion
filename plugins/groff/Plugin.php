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
use Reporion\Service\NewReport;
use Reporion\Service\PrintView;
use Reporion\Service\Render;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;
use Reporion\Support\ReportPath;

/**
 * groff export (2026-10-12, D42): a signed report as groff `ms` source
 * (`GET /x/groff/ms/{pid}`) and as a PDF typeset by groff (`/x/groff/pdf/{pid}`),
 * for the team that edits or sets its reports by hand. Like the DICOM SR
 * export it is for signed-in readers of a signed revision: an anonymous
 * caller, an invisible report and a page that is no report are the same
 * 404, an unsigned revision is 409. The preamble and the font family are the
 * plugin's settings; the body is the canonical HTML of Service\Render turned
 * into ms (Ms\HtmlToMs). The plugin writes no page (invariant 5).
 */
final class Plugin implements PluginInterface
{
    /** Tests replace the program groff runs as: (argv, output file, timeout) → [exit, stderr] */
    public static ?Closure $runner = null;

    private IndexInterface $index;

    private StorageInterface $storage;

    private AuditLog $audit;

    private Render $render;

    private NewReport $newReport;

    /** @var array<string, mixed> */
    private array $settings;

    public function register(Hooks $hooks, Container $container): void
    {
        $this->settings = $container->settings();
        $this->index = $container->get(IndexInterface::class);
        $this->storage = $container->get(StorageInterface::class);
        $this->audit = $container->get(AuditLog::class);
        $this->render = $container->get(Render::class);
        $this->newReport = $container->get(NewReport::class);

        $hooks->route('GET', '/ms/{pid}', fn (Request $request, array $params, ?User $principal): Response => $this->export($request, $params, $principal, 'ms'));
        $hooks->route('GET', '/pdf/{pid}', fn (Request $request, array $params, ?User $principal): Response => $this->export($request, $params, $principal, 'pdf'));
    }

    /**
     * @param array<string, string> $params
     */
    private function export(Request $request, array $params, ?User $principal, string $kind): Response
    {
        $row = $principal !== null ? $this->index->findByPid($params['pid'], $principal) : null;
        if ($principal === null || $row === null || !ReportPath::isReport((string) $row['path'])) {
            throw new PageNotFoundException();
        }
        $record = $this->storage->read((string) $row['path']);
        $signature = self::signature($record);
        if ($signature === null) {
            return new Response(409, t('groff.unsigned'), ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        }

        $font = (string) $this->settings['font'];
        $source = Document::build(
            $record,
            $this->site(Exams::flat($record->frontmatter), $principal),
            HtmlToMs::convert($this->render->body(ReportName::forExport($record->body, Exams::flat($record->frontmatter)), Exams::flat($record->frontmatter), unlinkPages: true, examIds: Exams::isMulti($record->frontmatter))->html),
            ['name' => display_name($signature['by']), 'parafa' => $signature['parafa'], 'at' => $signature['at']],
            rtrim((string) $this->settings['base_url'], '/') !== '' ? rtrim((string) $this->settings['base_url'], '/') . '/r/' . $record->pid . '/' . $record->rev : $request->basePath . '/r/' . $record->pid . '/' . $record->rev,
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
            // groff's own words can quote the report: the owner sees them, nobody else
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

    /**
     * The report's site, as far as a plugin can see it: its name and its devices' names
     *
     * @param array<string, mixed> $fm
     *
     * @return array{name: string, devices: array<string, string>}
     */
    private function site(array $fm, User $principal): array
    {
        $code = MetaText::text($fm['site'] ?? null);
        $site = $this->newReport->options($principal)['sites'][$code] ?? null;

        return ['name' => \is_array($site) ? (string) $site['name'] : '', 'devices' => \is_array($site) ? $site['devices'] : []];
    }

    /**
     * The signature of the current revision, or null when it is not signed
     *
     * @return ?array{by: string, parafa: string, at: string}
     */
    private static function signature(PageRecord $record): ?array
    {
        $found = null;
        foreach ((array) ($record->meta['signatures'] ?? []) as $candidate) {
            if (\is_array($candidate) && (int) ($candidate['rev'] ?? 0) === $record->rev) {
                $found = $candidate;
            }
        }
        if ($found === null) {
            return null;
        }

        return [
            'by' => (string) ($found['by'] ?? ''),
            'parafa' => \is_string($found['parafa'] ?? null) ? $found['parafa'] : '',
            'at' => MetaText::date($found['ts'] ?? null, MetaText::DATE . ' H:i'),
        ];
    }
}
