<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Hipobridge;

use Closure;
use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\SystemActor;
use Reporion\Auth\User;
use Reporion\Controller\AiController;
use Reporion\Exception\AiException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\PrefillUnavailableException;
use Reporion\Http\ApiResponse;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Plugin\Container;
use Reporion\Plugin\Hooks;
use Reporion\Plugin\PluginInterface;
use Reporion\Service\Ai\ArchiveAnswer;
use Reporion\Service\ArchiveAsk;
use Reporion\Service\NewReport;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;

/**
 * HippoBridge (Hipocrate HIS): the first real plugin (TODO.md idea 1).
 *
 *   GET  /x/hipobridge/worklist      recent performed exams → "Start"
 *                                    opens /new?prefill=hipobridge&ref={slug}.{id}
 *   GET  /x/hipobridge/priors/{pid}  the report's patient in the HIS and their exams
 *   POST /x/hipobridge/priors/{pid}  import the chosen ones, fill the report's blanks
 *   GET  /x/hipobridge/ask           Ask HippoBridge: a question answered from the HIS's texts
 *   POST /x/hipobridge/ask           {question} → {data: {answer, sources, context, ms, provider}}
 *
 * The worklist is for callers who create reports; the priors screens for
 * callers who may write the report, Ask HippoBridge for callers who create
 * reports (all 404 otherwise, never 403): HIS texts are not behind the wiki's
 * visibility predicate (invariant 6), so the gate is the control. The HIS is
 * only ever read.
 */
final class Plugin implements PluginInterface
{
    /** For tests: replaces the HTTP transport (see Client) */
    public static ?Closure $transport = null;

    private His $his;
    private Ask $ask;
    private ?ArchiveAnswer $answers = null;
    private bool $configured = false;
    private IndexInterface $index;
    private StorageInterface $storage;
    private AuditLog $audit;
    private string $templates;

    public function register(Hooks $hooks, Container $container): void
    {
        $settings = $container->settings();
        $this->index = $container->get(IndexInterface::class);
        $this->storage = $container->get(StorageInterface::class);
        $this->audit = $container->get(AuditLog::class);
        $this->templates = $container->dir() . '/templates';
        $this->his = new His(
            new Client((string) $settings['url'], (string) $settings['username'], (string) $settings['password'], (int) $settings['timeout'], self::$transport),
            $this->storage,
            $this->index,
            $container->get(NewReport::class),
            $settings,
        );

        $this->configured = (string) $settings['url'] !== '' && (string) $settings['username'] !== '';
        $this->ask = new Ask(new Client((string) $settings['url'], (string) $settings['username'], (string) $settings['password'], (int) $settings['timeout'], self::$transport), $this->storage);
        try {
            $this->answers = $container->get(ArchiveAnswer::class);
        } catch (InvalidArgumentException) {
            // No assistant built (the CLI): the screen says so
        }

        $hooks->on('report.prefill', $this->prefill(...));
        $hooks->route('GET', '/worklist', $this->worklist(...));
        $hooks->route('GET', '/priors/{pid}', $this->priors(...));
        $hooks->route('POST', '/priors/{pid}', $this->importPriors(...));
        $hooks->route('GET', '/ask', $this->askPage(...));
        $hooks->route('POST', '/ask', $this->askAnswer(...));
    }

    /** @return ?array<string, mixed> */
    public function prefill(string $source, string $ref, User $principal): ?array
    {
        if ($source !== His::SOURCE) {
            return null;
        }
        try {
            return $this->his->prefill($ref, $principal);
        } catch (HisException $e) {
            // The HIS did not answer: said on the form, not a 404 as for an unknown ref
            throw new PrefillUnavailableException(t('hipobridge.err.' . $e->reason()), 0, $e);
        }
    }

    /** @param array<string, string> $params */
    public function worklist(Request $request, array $params, ?User $principal): Response
    {
        if ($principal === null || !NewReport::canCreateReports($principal)) {
            throw new PageNotFoundException();
        }
        $error = null;
        $list = ['from' => '', 'to' => '', 'rows' => []];
        // The new-report form passes the modality it is on; a HIS has one site, so ?site= has nothing to narrow
        $asked = \is_string($request->query['modality'] ?? null) ? strtoupper($request->query['modality']) : '';
        $modality = \in_array($asked, Fhir::MODALITIES, true) ? $asked : null;
        try {
            $list = $this->his->worklist($principal, $modality);
        } catch (HisException $e) {
            $error = $e->reason();
        }

        return $this->page($request, $principal, 'worklist.php', ['list' => $list, 'error' => $error, 'modality' => $modality], t('hipobridge.worklist.title'));
    }

    /** @param array<string, string> $params */
    public function priors(Request $request, array $params, ?User $principal, ?string $error = null, ?string $patientId = null): Response
    {
        $page = $this->writableReport($params['pid'], $principal);
        \assert($principal !== null);
        $patientId ??= \is_string($request->query['patient'] ?? null) && $request->query['patient'] !== '' ? $request->query['patient'] : null;
        $failed = $error !== null;
        $done = \is_string($request->query['done'] ?? null) && preg_match('/^(\d+)\.(\d+)\.(\d+)(?:\.(\d+))?$/', $request->query['done'], $m) === 1
            ? ['created' => (int) $m[1], 'empty' => (int) $m[2], 'updated' => $m[3] === '1', 'linked' => (int) ($m[4] ?? 0)] : null;
        $lookup = null;
        try {
            $lookup = $this->his->lookup($page, $principal, $patientId);
        } catch (HisException $e) {
            $error ??= $e->reason();
        }

        return $this->page($request, $principal, 'priors.php', [
            'page' => $page,
            'own' => His::pagePatient($page),
            'lookup' => $lookup,
            'error' => $error,
            'done' => $done,
        ] + ChromeVars::pageHeaderFromRow((array) $this->index->findByPid($page->pid, $principal), $principal, 'plugin:hipobridge'), t('hipobridge.priors.title'), $failed ? 422 : 200);
    }

    /** @param array<string, string> $params */
    public function importPriors(Request $request, array $params, ?User $principal): Response
    {
        $page = $this->writableReport($params['pid'], $principal);
        \assert($principal !== null);
        parse_str($request->body, $fields);
        $patientId = \is_string($fields['patient'] ?? null) ? $fields['patient'] : '';
        $refs = array_values(array_filter((array) ($fields['import'] ?? []), 'is_string'));
        $thisRef = \is_string($fields['this'] ?? null) && $fields['this'] !== '' ? $fields['this'] : null;
        if ($patientId === '') {
            throw new PageNotFoundException();
        }
        try {
            $result = $this->his->import($page, $principal, $patientId, $refs, $thisRef);
        } catch (HisException $e) {
            return $this->priors($request, $params, $principal, $e->reason(), $patientId);
        } catch (InvalidArgumentException $e) {
            return $this->priors($request, $params, $principal, $e->getMessage(), $patientId);
        }
        foreach ($result['created'] as $record) {
            $this->audit->record('page.create', SystemActor::IMPORTER, $request, $record->pid, $record->path, $record->rev, extra: ['via' => His::SOURCE, 'on_behalf_of' => $principal->username]);
        }
        if ($result['updated'] !== null) {
            $this->audit->record('page.save', SystemActor::IMPORTER, $request, $page->pid, $page->path, $result['updated']->rev, extra: ['via' => His::SOURCE, 'on_behalf_of' => $principal->username]);
        }

        // The HIS patient id and counts: never a name: in the URL (D1)
        return Response::redirect($request->basePath . '/x/hipobridge/priors/' . rawurlencode($page->pid)
            . '?patient=' . rawurlencode($patientId) . '&done=' . \count($result['created']) . '.' . $result['empty'] . '.' . (int) ($result['updated'] !== null) . '.' . \count($result['linked']));
    }

    /** @param array<string, string> $params */
    public function askPage(Request $request, array $params, ?User $principal): Response
    {
        if ($principal === null || !NewReport::canCreateReports($principal)) {
            throw new PageNotFoundException();
        }

        return $this->page($request, $principal, 'ask.php', [
            'question' => mb_substr(trim(\is_string($request->query['q'] ?? null) ? $request->query['q'] : ''), 0, ArchiveAsk::MAX_QUESTION),
            'available' => $this->answers?->available() === true && $this->configured,
            'maxQuestion' => ArchiveAsk::MAX_QUESTION,
        ], t('hipobridge.ask.title'));
    }

    /**
     * The question answered from the HIS's texts: the words HippoBridge is
     * asked are content words only (Ask::terms()); the passages go to the
     * `ask-archive` prompt de-identified by the patients they are about
     * (ArchiveAnswer); the response and the `ai.call` audit line carry no
     * name or CNP (invariant 8).
     *
     * @param array<string, string> $params
     */
    public function askAnswer(Request $request, array $params, ?User $principal): Response
    {
        if ($principal === null || !NewReport::canCreateReports($principal)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if ($this->answers === null || !$this->answers->available() || !$this->configured) {
            return ApiResponse::error(503, 'not_configured', t('hipobridge.ask.unavailable'));
        }
        $fields = $request->json();
        $question = \is_string($fields['question'] ?? null) ? trim($fields['question']) : '';
        if ($question === '' || mb_strlen($question) > ArchiveAsk::MAX_QUESTION) {
            return ApiResponse::error(422, 'invalid_question', t('ask.err_question'));
        }
        try {
            $passages = $this->ask->passages($question);
        } catch (HisException $e) {
            return ApiResponse::error(502, 'his_' . str_replace('-', '_', $e->reason()), t('hipobridge.err.' . $e->reason()));
        }
        if ($passages === []) {
            return ApiResponse::json(['data' => ['answer' => null, 'sources' => []]]);
        }
        $payload = array_map(static fn (array $p): array => [
            'n' => $p['n'], 'text' => $p['text'], 'date' => $p['date'], 'names' => $p['names'], 'cnp' => $p['cnp'],
        ], $passages);
        $kinds = array_column($passages, 'kind', 'n');
        // What the model reads, not what the HIS holds: no name or CNP on the page either
        $shown = array_map(static fn (array $p): array => [
            'n' => $p['n'],
            'text' => $p['text'],
            'study_date' => $p['date'],
            'modality' => t('hipobridge.ask.kind.' . $kinds[$p['n']]),
            'heading' => '',
            'mine' => false,
        ], $this->answers->shown($payload));
        // Room for the Assistant's retries
        @set_time_limit($this->answers->seconds());
        try {
            $done = $this->answers->answer($question, $payload, $principal, $request);
        } catch (AiException $e) {
            return AiController::error($e);
        }

        return ApiResponse::json(['data' => [
            'answer' => $done['answer'],
            'sources' => $shown,
            'context' => $done['context'],
            'ms' => $done['ms'],
            'provider' => $done['provider'],
        ]]);
    }

    /** The report behind $pid, when $principal may write it: else 404 (never 403) */
    private function writableReport(string $pid, ?User $principal): PageRecord
    {
        $row = $principal !== null ? $this->index->findByPid($pid, $principal) : null;
        if ($row === null || !ReportPath::isReport((string) $row['path']) || !$principal->canWrite((string) $row['path'])) {
            throw new PageNotFoundException();
        }

        return $this->storage->read((string) $row['path']);
    }

    /** @param array<string, mixed> $vars */
    private function page(Request $request, User $principal, string $template, array $vars, string $title, int $status = 200): Response
    {
        return Response::html(View::page(
            $this->templates . '/' . $template,
            $vars + ['basePath' => $request->basePath] + ChromeVars::shell($request, $principal, $this->index, ReportPath::ROOT),
            $title,
        ), $status);
    }
}
