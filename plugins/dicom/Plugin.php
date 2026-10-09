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
use Reporion\Plugin\Dicom\Sr\ReportContent;
use Reporion\Plugin\Dicom\Sr\Writer;
use Reporion\Plugin\PluginInterface;
use Reporion\Service\NewReport;
use Reporion\Service\PrintView;
use Reporion\Service\SiteDevices;
use Reporion\Service\TempUploads;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Exams;
use Reporion\Support\ReportPath;

/**
 * DICOM (PACS query) — patient and exam details from each site's PACS.
 *
 *   GET  /x/dicom/worklist     studies by site, modality and date range → "Start"
 *                              opens /new?prefill=dicom&ref={site}:{modality}:{uid}
 *   GET  /x/dicom/file         pick a DICOM file; POST (the file as the body) keeps it in data/tmp
 *                              and answers with /new?prefill=dicom-file&ref={token}, which reads
 *                              its header once, fills the form (patient, day, exam, study UID) and
 *                              removes it; callers who create reports
 *   GET  /x/dicom/study/{pid}  the report's PACS tab: the report's patient at its site
 *                              (by CNP, then name; the day narrows it)
 *   POST /x/dicom/study/{pid}  with `uid`: link the chosen study, filling what the report
 *                              is missing; without: the tab's search form (name, CNP, day)
 *   GET  /x/dicom/sr/{pid}     a signed report as a DICOM SR file (Basic Text SR, TID 2000
 *                              layout), for any signed-in reader; 404 to anyone else, 409 draft
 *   POST /x/dicom/send/{pid}   the signed report's SR stored to its site's PACS, into its
 *                              linked study (phase 22b, SrSender); writers of the report only
 *   GET  /x/dicom/echo         owner only: C-ECHO to every configured PACS
 *   POST /x/dicom/device/{pid} owner only: the report's PACS scanner (its pacs_device) linked
 *                              to a device of its site — a new one or an existing one — and
 *                              the report's blank device filled with it (2026-10-07)
 *
 * The worklist is for callers who create reports, the PACS tab for callers
 * who may write the report (404 otherwise, never 403).
 */
final class Plugin implements PluginInterface
{
    /** The prefill source of a report started from an uploaded file */
    public const FILE_SOURCE = 'dicom-file';

    private const UPLOAD_BUCKET = 'dicom';

    /** The biggest file taken: a study's slice is well under this (PHP's post_max_size is 32M) */
    private const MAX_UPLOAD = 30 * 1024 * 1024;

    /** For tests: replaces the process runner (see Scu) */
    public static ?Closure $runner = null;

    /** For tests: "today" */
    public static ?DateTimeImmutable $today = null;

    private Pacs $pacs;
    private Scu $scu;
    private SrSender $sender;
    private IndexInterface $index;
    private StorageInterface $storage;
    private AuditLog $audit;
    private SiteDevices $devices;
    private TempUploads $uploads;
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
        $this->devices = $container->get(SiteDevices::class);
        $this->uploads = $container->get(TempUploads::class);
        $this->pacs = new Pacs($this->scu, $this->storage, $this->index, $container->get(NewReport::class), $settings, self::$today, $this->devices);

        // Phase 22b: the sites whose PACS row ticks "send SR"
        $srSites = [];
        foreach (\is_array($settings['servers'] ?? null) ? $settings['servers'] : [] as $code => $row) {
            if (\is_array($row) && ($row['send_sr'] ?? false) === true) {
                $srSites[] = (string) $code;
            }
        }
        $this->sender = new SrSender($this->scu, $this->pacs, $this->storage, $srSites);

        $hooks->on('report.prefill', $this->prefill(...));
        $hooks->on('maintenance.tasks', fn (): array => [new BulkLinkTask($this->pacs, $this->storage, $this->audit)]);
        $hooks->route('GET', '/file', $this->file(...));
        $hooks->route('POST', '/file', $this->file(...));
        $hooks->route('GET', '/worklist', $this->worklist(...));
        $hooks->route('POST', '/worklist', $this->worklist(...));
        $hooks->route('GET', '/study/{pid}', $this->study(...));
        $hooks->route('POST', '/study/{pid}', $this->link(...));
        $hooks->route('GET', '/sr/{pid}', $this->sr(...));
        $hooks->route('POST', '/send/{pid}', $this->send(...));
        $hooks->route('GET', '/echo', $this->echo(...));
        $hooks->route('POST', '/device/{pid}', $this->device(...));
    }

    /** @return ?array<string, mixed> */
    public function prefill(string $source, string $ref, User $principal): ?array
    {
        if ($source === self::FILE_SOURCE) {
            return $this->prefillFromFile($ref, $principal);
        }
        if ($source !== Pacs::SOURCE) {
            return null;
        }
        try {
            return $this->pacs->prefill($ref, $principal);
        } catch (DicomException) {
            return null;
        }
    }

    /** The uploaded file behind $token, read once and removed whether or not it parses */
    private function prefillFromFile(string $token, User $principal): ?array
    {
        $bytes = $this->uploads->take(self::UPLOAD_BUCKET, $token);
        if ($bytes === null) {
            return null;
        }
        try {
            return $this->pacs->fileFields(Header::parse($bytes)['elements'], $principal);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * GET: the page to pick a file on. POST: the file itself as the request body (no multipart,
     * as the editor's image upload) — checked to be DICOM, kept under data/tmp until the form
     * reads it, answered with the address of the filled form. Callers who create reports; 404 otherwise.
     *
     * @param array<string, string> $params
     */
    public function file(Request $request, array $params, ?User $principal): Response
    {
        if ($principal === null || !NewReport::canCreateReports($principal)) {
            throw new PageNotFoundException();
        }
        if ($request->method !== 'POST') {
            return $this->page($request, $principal, 'file.php', ['maxMb' => intdiv(self::MAX_UPLOAD, 1024 * 1024)], t('dicom.file.title'));
        }
        $fail = static fn (int $status, string $message): Response => new Response($status, (string) json_encode(['error' => $message], JSON_UNESCAPED_UNICODE), ['Content-Type' => 'application/json']);
        if ($request->body === '') {
            return $fail(422, t('dicom.file.err_empty'));
        }
        if (\strlen($request->body) > self::MAX_UPLOAD) {
            return $fail(413, t('dicom.file.err_too_large', [(string) intdiv(self::MAX_UPLOAD, 1024 * 1024)]));
        }
        try {
            Header::parse($request->body);
        } catch (InvalidArgumentException) {
            return $fail(422, t('dicom.file.err_not_dicom'));
        }
        $token = $this->uploads->put(self::UPLOAD_BUCKET, $request->body);
        $this->audit->record('dicom.file', $principal->username, $request, extra: ['bytes' => \strlen($request->body)]);

        return new Response(201, (string) json_encode(['url' => $request->basePath . '/new?prefill=' . self::FILE_SOURCE . '&ref=' . $token]), ['Content-Type' => 'application/json']);
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
        // A name or a CNP, one field (posted only: never in a URL, D1)
        $patient = $posted && \is_string($fields['patient'] ?? null) ? mb_substr(trim($fields['patient']), 0, 120) : '';
        // Only once asked: opening the page shows the filter, the Query button runs findscu
        // ?fill=1 (the new-report form's button): site and modality are put in the form, nothing is asked yet
        $filled = ($request->query['fill'] ?? '') === '1';
        $queried = $servers !== [] && !$filled && ($posted || array_intersect_key($fields, ['site' => 1, 'modality' => 1, 'from' => 1, 'to' => 1]) !== []);
        $list = $queried ? $this->pacs->worklist($principal, $site, $from, $to, $modality, $patient) : ['rows' => [], 'errors' => []];

        return $this->page($request, $principal, 'worklist.php', [
            'list' => $list,
            'servers' => $servers,
            'site' => $site,
            'modalities' => $modalities,
            'modality' => $modality,
            'patient' => $patient,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'invalidRange' => $invalid,
            'queried' => $queried,
            'isOwner' => $principal->isOwner,
        ], t('dicom.worklist.title'));
    }

    /** @param array<string, string> $params */
    /**
     * @param array<string, string>                                          $params
     * @param ?array{outcome: string, log: string, sent: int, of: int} $sent a send just tried (its log shown to the owner only)
     */
    public function study(Request $request, array $params, ?User $principal, ?string $error = null, ?string $site = null, ?string $day = null, ?array $patient = null, ?array $sent = null, ?string $deviceError = null): Response
    {
        $page = $this->writableReport($params['pid'], $principal);
        \assert($principal !== null);
        $site ??= \is_string($request->query['site'] ?? null) && $request->query['site'] !== '' ? $request->query['site'] : null;
        $day ??= self::day($request->query['day'] ?? null)?->format('Y-m-d');
        $failed = $error !== null || $deviceError !== null || ($sent !== null && $sent['outcome'] !== 'ok');
        $lookup = null;
        // After a refused send, not another trip to a PACS that may be down; nothing to ask, nothing asked
        if ($sent === null && $error !== 'need-one') {
            try {
                $lookup = $this->pacs->lookup($page, $site, $day, $patient);
            } catch (DicomException $e) {
                $error ??= $e->getMessage();
            }
        }

        return $this->page($request, $principal, 'study.php', [
            'page' => $page,
            'own' => $patient ?? Pacs::pagePatient($page),
            'lookup' => $lookup,
            'dayShown' => $lookup['day'] ?? ($day ?? Pacs::studyDay($page)),
            'servers' => $this->pacs->servers(),
            'error' => $error,
            'done' => \in_array($request->query['done'] ?? null, ['0', '1'], true) ? $request->query['done'] === '1' : null,
            'sr' => $this->sender->state($page),
            'srSent' => $sent ?? (($request->query['sent'] ?? '') === '1' ? ['outcome' => 'ok', 'log' => '', 'sent' => 0, 'of' => 0] : null),
            'srLog' => $principal->isOwner,
            'scanner' => $this->scanner($page, $principal),
            // A multi-exam report: the exams a study may still go to (index => title)
            'examChoices' => Pacs::examsWithoutStudy($page->frontmatter),
            'deviceSaved' => ($request->query['device'] ?? '') === '1',
            'deviceError' => $deviceError,
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
        // The exam picked on this study's row (a multi-exam report)
        $picked = \is_array($fields['exam'] ?? null) ? ($fields['exam'][$uid] ?? null) : null;
        $exam = \is_string($picked) && ctype_digit($picked) ? (int) $picked : null;
        try {
            $updated = $this->pacs->link($page, $principal->username, $site, $uid, exam: $exam);
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
        $day = self::day($fields['day'] ?? null)?->format('Y-m-d') ?? '';
        // A name or a CNP with no day: every date. All three empty would be every study of the PACS — refused
        $error = $day === '' && $patient['name'] === '' && $patient['cnp'] === '' ? 'need-one' : null;

        return $this->study($request, $params, $principal, $error, $site !== '' ? $site : null, $day, $patient);
    }

    /**
     * The signed report as a DICOM SR file. The file names the patient, as
     * the report's own PDF does (D1 as amended), so it is for signed-in
     * readers only — an anonymous caller, an invisible report and a page that
     * is no report are the same 404. A revision that is not signed is 409:
     * an SR is the verified document, never a draft.
     *
     * @param array<string, string> $params
     */
    public function sr(Request $request, array $params, ?User $principal): Response
    {
        $row = $principal !== null ? $this->index->findByPid($params['pid'], $principal) : null;
        if ($row === null || !ReportPath::isReport((string) $row['path'])) {
            throw new PageNotFoundException();
        }
        $record = $this->storage->read((string) $row['path']);
        $signature = ReportContent::signature($record);
        if ($signature === null) {
            return new Response(409, t('dicom.sr.unsigned'), ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        }
        $bytes = Writer::file(ReportContent::SOP_CLASS, ReportContent::instanceUid($record), ReportContent::dataset(
            $record,
            ['name' => display_name($signature['by']), 'at' => $signature['at']],
        ));
        $this->audit->record('export', $principal->username, $request, $record->pid, $record->path, $record->rev, extra: ['format' => 'dcm']);

        return new Response(200, $bytes, [
            'Content-Type' => 'application/dicom',
            'Content-Disposition' => 'attachment; filename="' . PrintView::fileName($record, 'dcm') . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The signed report's SR stored to its site's PACS (SrSender), audited
     * `report.deliver` by pid — never the path's patient segment in a URL.
     * Done: back to the PACS tab; refused: the tab again with the reason
     * (and, for the owner, storescu's log).
     *
     * @param array<string, string> $params
     */
    public function send(Request $request, array $params, ?User $principal): Response
    {
        $page = $this->writableReport($params['pid'], $principal);
        \assert($principal !== null);
        $result = $this->sender->send($page, $principal->username);
        if ($result['of'] > 0) {
            $this->audit->record('report.deliver', $principal->username, $request, $page->pid, $page->path, $page->rev, extra: [
                'to' => 'pacs', 'outcome' => $result['outcome'], 'studies' => $result['of'], 'sent' => $result['sent'],
            ]);
        }
        if ($result['outcome'] === 'ok') {
            return Response::redirect($request->basePath . '/x/dicom/study/' . rawurlencode($page->pid) . '?sent=1#sr');
        }

        return $this->study($request, $params, $principal, null, null, null, null, $result);
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
    /**
     * The report's PACS scanner and what the site's devices make of it, for
     * the tab's Scanner box; null when the report names no scanner (not
     * linked yet, or the PACS sent none) or its site is not configured
     *
     * @return ?array{name: string, site: string, code: string, label: string, reportDevice: string, suggest: string, devices: array<string, string>, canLink: bool}
     */
    private function scanner(PageRecord $page, User $principal): ?array
    {
        $fm = Exams::flat($page->frontmatter);
        $name = \is_scalar($fm['pacs_device'] ?? null) ? trim((string) $fm['pacs_device']) : '';
        $site = \is_scalar($fm['site'] ?? null) ? (string) $fm['site'] : '';
        if ($name === '' || !$this->devices->has($site)) {
            return null;
        }
        $code = $this->devices->forPacs($site, $name) ?? '';
        $names = $this->devices->names($site);

        return [
            'name' => $name,
            'site' => $site,
            'code' => $code,
            'label' => $code !== '' ? ($names[$code] ?? '') : '',
            'reportDevice' => \is_scalar($fm['device'] ?? null) ? (string) $fm['device'] : '',
            'suggest' => $this->devices->suggestCode($site, (string) (((array) ($fm['modality'] ?? []))[0] ?? '')),
            'devices' => $names,
            // Sites and devices are the instance's settings: an owner's (D35)
            'canLink' => $principal->isOwner,
        ];
    }

    /**
     * POST /x/dicom/device/{pid} — the Scanner box: `as=new` (code, name) or
     * `as=existing` (device). The scanner name is the report's own
     * `pacs_device`, never one sent by the form. A writer who is not an
     * owner gets the same 404 as for a report they cannot write.
     *
     * @param array<string, string> $params
     */
    public function device(Request $request, array $params, ?User $principal): Response
    {
        $page = $this->writableReport($params['pid'], $principal);
        \assert($principal !== null);
        $scanner = $this->scanner($page, $principal);
        if ($scanner === null || !$scanner['canLink']) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $create = ($fields['as'] ?? '') === 'new';
        $code = (string) ($create ? ($fields['code'] ?? '') : ($fields['existing'] ?? ''));
        try {
            $this->devices->link($scanner['site'], $code, (string) ($fields['name'] ?? ''), $scanner['name'], $create);
        } catch (InvalidArgumentException $e) {
            return $this->study($request, $params, $principal, null, null, null, null, null, $e->getMessage());
        }
        $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'sites', 'keys' => ['devices'], 'via' => Pacs::SOURCE]);
        // This report's device, when it has none and is one exam (a multi-exam report's devices are its exams')
        // The code as the site stores it (a code typed in another case is that device)
        $code = $this->devices->forPacs($scanner['site'], $scanner['name']) ?? trim($code);
        if ($scanner['reportDevice'] === '' && !Exams::isMulti($page->frontmatter)) {
            $saved = $this->storage->save($page->path, ['device' => $code] + $page->frontmatter, $page->body, $page->rev, $principal->username, 'device from the PACS scanner');
            $this->audit->record('page.save', $principal->username, $request, $saved->pid, $saved->path, $saved->rev, extra: ['via' => Pacs::SOURCE]);
        }

        return Response::redirect($request->basePath . '/x/dicom/study/' . rawurlencode($page->pid) . '?device=1#scanner');
    }

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
