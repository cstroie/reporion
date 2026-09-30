<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Plugin\Container;
use Reporion\Plugin\Hooks;
use Reporion\Plugin\PluginInterface;
use Reporion\Service\NewReport;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;

/**
 * DICOM (PACS query) — patient and exam details from each site's PACS.
 *
 *   GET  /x/dicom/worklist     studies by site, modality and date range → "Start"
 *                              opens /new?prefill=dicom&ref={site}:{modality}:{uid}
 *   GET  /x/dicom/study/{pid}  the report's PACS tab: the report's patient at its site
 *                              (by CNP, then name; the day narrows it)
 *   POST /x/dicom/study/{pid}  with `uid`: link the chosen study, filling what the report
 *                              is missing; without: the tab's search form (name, CNP, day)
 *   GET  /x/dicom/echo         owner only: C-ECHO to every configured PACS
 *
 * The worklist is for callers who create reports, the PACS tab for callers
 * who may write the report (404 otherwise, never 403).
 */
final class Plugin implements PluginInterface
{
    /** For tests: replaces the process runner (see Scu) */
    public static ?Closure $runner = null;

    /** For tests: "today" */
    public static ?DateTimeImmutable $today = null;

    private Pacs $pacs;
    private Scu $scu;
    private IndexInterface $index;
    private StorageInterface $storage;
    private AuditLog $audit;
    private string $templates;

    /** @var array<string, mixed> */
    private array $settings;

    public function register(Hooks $hooks, Container $container): void
    {
        $settings = $this->settings = $container->settings();
        $this->index = $container->get(IndexInterface::class);
        $this->storage = $container->get(StorageInterface::class);
        $this->audit = $container->get(AuditLog::class);
        $this->templates = $container->dir() . '/templates';
        $this->scu = new Scu((string) $settings['findscu'], (int) $settings['timeout'], self::$runner);
        $this->pacs = new Pacs($this->scu, $this->storage, $this->index, $container->get(NewReport::class), $settings, self::$today);

        $hooks->on('report.prefill', $this->prefill(...));
        $hooks->on('maintenance.tasks', fn (): array => [new BulkLinkTask($this->pacs, $this->storage, $this->audit)]);
        $hooks->route('GET', '/worklist', $this->worklist(...));
        $hooks->route('POST', '/worklist', $this->worklist(...));
        $hooks->route('GET', '/study/{pid}', $this->study(...));
        $hooks->route('POST', '/study/{pid}', $this->link(...));
        $hooks->route('GET', '/echo', $this->echo(...));
    }

    /** @return ?array<string, mixed> */
    public function prefill(string $source, string $ref, User $principal): ?array
    {
        if ($source !== Pacs::SOURCE) {
            return null;
        }
        try {
            return $this->pacs->prefill($ref);
        } catch (DicomException) {
            return null;
        }
    }

    /** @param array<string, string> $params */
    public function worklist(Request $request, array $params, ?User $principal): Response
    {
        if ($principal === null || !NewReport::canCreateReports($principal)) {
            throw new PageNotFoundException();
        }
        $servers = $this->pacs->servers();
        $today = $this->pacs->today();
        // The filter is a POST when it is sent from the form, so a patient's name is never in a URL or
        // an access log (D1); a GET (a bookmark, the first visit) has no name
        $posted = $request->method === 'POST';
        $fields = $request->query;
        if ($posted) {
            parse_str($request->body, $fields);
        }
        $to = self::day($fields['to'] ?? null) ?? $today;
        $from = self::day($fields['from'] ?? null) ?? $to->modify('-' . max(0, (int) $this->settings['lookback_days']) . ' days');
        $invalid = $from > $to || $from < $to->modify('-31 days');
        if ($invalid) {
            $from = $to;
        }
        $site = \is_string($fields['site'] ?? null) && isset($servers[$fields['site']]) ? $fields['site'] : null;
        $modalities = $this->pacs->modalities();
        $modality = \is_string($fields['modality'] ?? null) && \in_array($fields['modality'], $modalities, true) ? $fields['modality'] : null;
        $name = $posted && \is_string($fields['name'] ?? null) ? mb_substr(trim($fields['name']), 0, 120) : '';
        // Only once asked: opening the page shows the filter, the Query button runs findscu
        $queried = $servers !== [] && ($posted || array_intersect_key($fields, ['site' => 1, 'modality' => 1, 'from' => 1, 'to' => 1]) !== []);
        $list = $queried ? $this->pacs->worklist($principal, $site, $from, $to, $modality, $name) : ['rows' => [], 'errors' => []];

        return $this->page($request, $principal, 'worklist.php', [
            'list' => $list,
            'servers' => $servers,
            'site' => $site,
            'modalities' => $modalities,
            'modality' => $modality,
            'name' => $name,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'invalidRange' => $invalid,
            'queried' => $queried,
            'isOwner' => $principal->isOwner,
        ], t('dicom.worklist.title'));
    }

    /** @param array<string, string> $params */
    public function study(Request $request, array $params, ?User $principal, ?string $error = null, ?string $site = null, ?string $day = null, ?array $patient = null): Response
    {
        $page = $this->writableReport($params['pid'], $principal);
        \assert($principal !== null);
        $site ??= \is_string($request->query['site'] ?? null) && $request->query['site'] !== '' ? $request->query['site'] : null;
        $day ??= self::day($request->query['day'] ?? null)?->format('Y-m-d');
        $failed = $error !== null;
        $lookup = null;
        try {
            $lookup = $this->pacs->lookup($page, $site, $day, $patient);
        } catch (DicomException $e) {
            $error ??= $e->getMessage();
        }

        return $this->page($request, $principal, 'study.php', [
            'page' => $page,
            'own' => $patient ?? Pacs::pagePatient($page),
            'lookup' => $lookup,
            'dayShown' => $lookup['day'] ?? ($day ?? Pacs::studyDay($page)),
            'servers' => $this->pacs->servers(),
            'error' => $error,
            'done' => \in_array($request->query['done'] ?? null, ['0', '1'], true) ? $request->query['done'] === '1' : null,
        ] + ChromeVars::pageHeaderFromRow((array) $this->index->findByPid($page->pid, $principal), $principal, 'plugin:dicom'), t('dicom.study.title'), $failed ? 422 : 200);
    }

    /** @param array<string, string> $params */
    public function link(Request $request, array $params, ?User $principal): Response
    {
        $page = $this->writableReport($params['pid'], $principal);
        \assert($principal !== null);
        parse_str($request->body, $fields);
        $site = \is_string($fields['site'] ?? null) ? $fields['site'] : '';
        if (!isset($fields['uid'])) {
            return $this->search($request, $params, $principal, $site, $fields);
        }
        $uid = \is_string($fields['uid'] ?? null) ? $fields['uid'] : '';
        $day = self::day($fields['day'] ?? null)?->format('Y-m-d');
        try {
            $updated = $this->pacs->link($page, $principal->username, $site, $uid);
        } catch (DicomException $e) {
            return $this->study($request, $params, $principal, $e->getMessage(), $site, $day);
        } catch (InvalidArgumentException $e) {
            return $this->study($request, $params, $principal, $e->getMessage(), $site, $day);
        }
        if ($updated !== null) {
            $this->audit->record('page.save', $principal->username, $request, $page->pid, $page->path, $updated->rev, extra: ['via' => Pacs::SOURCE]);
        }

        // Site code, day and a flag — never a name or a CNP — in the URL (D1)
        return Response::redirect($request->basePath . '/x/dicom/study/' . rawurlencode($page->pid)
            . '?site=' . rawurlencode($site) . ($day !== null ? '&day=' . $day : '') . '&done=' . (int) ($updated !== null));
    }

    /**
     * The tab's form: the name and CNP to look for (prefilled from the report,
     * editable) and an optional day. A POST, so a patient's name or CNP is
     * never in a URL or an access log (D1); the answer is this page, not a
     * redirect.
     *
     * @param array<string, string> $params
     * @param array<mixed>          $fields
     */
    private function search(Request $request, array $params, ?User $principal, string $site, array $fields): Response
    {
        $text = static fn (mixed $v, int $max): string => \is_string($v) ? mb_substr(trim($v), 0, $max) : '';
        $patient = ['name' => $text($fields['name'] ?? null, 120), 'cnp' => preg_replace('/\s+/', '', $text($fields['cnp'] ?? null, 32)) ?? ''];

        return $this->study($request, $params, $principal, null, $site !== '' ? $site : null, self::day($fields['day'] ?? null)?->format('Y-m-d') ?? '', $patient);
    }

    /**
     * C-ECHO to every configured PACS, or to `?site=` only (the Test button
     * of that site's row); a failure shows echoscu's log.
     *
     * @param array<string, string> $params
     */
    public function echo(Request $request, array $params, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $servers = $this->pacs->servers();
        $only = \is_string($request->query['site'] ?? null) && $request->query['site'] !== '' ? $request->query['site'] : null;
        $results = [];
        $logs = [];
        foreach ($only !== null ? array_intersect_key($servers, [$only => true]) : $servers as $code => $server) {
            try {
                $this->scu->echo($server);
                $results[$code] = null;
            } catch (DicomException $e) {
                $results[$code] = $e->getMessage();
                $logs[$code] = $e->log;
            }
        }

        return $this->page($request, $principal, 'echo.php', [
            'results' => $results,
            'logs' => $logs,
            'servers' => $servers,
            'unconfigured' => $only !== null && !isset($servers[$only]) ? $only : null,
        ], t('dicom.echo.title'));
    }

    /** The report behind $pid, when $principal may write it — else 404 (never 403) */
    private function writableReport(string $pid, ?User $principal): PageRecord
    {
        $row = $principal !== null ? $this->index->findByPid($pid, $principal) : null;
        if ($row === null || !ReportPath::isReport((string) $row['path']) || !$principal->canWrite((string) $row['path'])) {
            throw new PageNotFoundException();
        }

        return $this->storage->read((string) $row['path']);
    }

    private static function day(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }

    /** @param array<string, mixed> $vars */
    private function page(Request $request, User $principal, string $template, array $vars, string $title, int $status = 200): Response
    {
        return Response::html(View::page(
            $this->templates . '/' . $template,
            $vars + ['basePath' => $request->basePath] + ChromeVars::shell($request, $principal, $this->index, 'reports'),
            $title,
        ), $status);
    }
}
