<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Assistant;
use Reporion\Audit\AuditLog;
use Reporion\Service\Library\Analyses;
use Reporion\Service\Library\AudioOverview;
use Reporion\Support\AudioScript;
use Reporion\Service\Library\NotebookAsk;
use Reporion\Storage\PageRecord;
use Reporion\Service\Library\SourceAnalysis;
use Reporion\Service\Library\Studio;
use Reporion\Http\TemplateLintText;
use Reporion\Support\ReportPath;
use Reporion\Storage\FileTree;
use Reporion\Support\LibrarySource;

/**
 * The assistant on the library (docs/library.md, phase 5):
 *
 * - `POST /api/v1/library/{pid}/analyze {task, stream?}`: one analysis of a source
 *   (Service\Library\SourceAnalysis). `stream: true` answers as server-sent events (`progress`
 *   {step, steps} before each request, then `done` {result, parts, requests, ms, provider} or
 *   `error` {code, message}) so a long source does not look dead to the web server; otherwise
 *   one JSON document. Shown, never written; applying the summary is the page's own metadata
 *   change (D8).
 *
 * - `POST /api/v1/notebook/ask {path, question}`: Ask the notebook (Service\Library\NotebookAsk): the
 *   answer from the notebook's own text and the sources it cites that the caller may read,
 *   `[@key, p. N]` citations of sent sources only. A report, or a page naming a patient, is no
 *   notebook (404, as the EPUB and groff exports). An editor of the notebook and of the library.
 *
 * - `POST /api/v1/notebook/studio {path, task, questions?, stream?}`: one Studio text of a notebook
 *   (Service\Library\Studio, docs/library.md phase 6): overview, glossary, faq, timeline, compare
 *   (`questions`: the table's columns, a list or one per line), check, template. Streamed as
 *   analyze; `done` carries {task, result, template?, notices, sources, whole, requests, dropped, ms,
 *   provider}. The same gate as Ask the notebook. Shown, never written: the template page is made
 *   by the writer through `POST /api/v1/pages` (D8).
 *
 * An editor of the source's namespace only (the owner's rule: the assistant is for editors), any
 * other caller 404; the profile is the one serving the source's own path (D15).
 */
final class LibraryAiController
{
    public function __construct(
        private readonly AiConfig $config,
        private readonly Assistant $assistant,
        private readonly SourceAnalysis $analysis,
        private readonly NotebookAsk $notebooks,
        private readonly Studio $studio,
        private readonly string $libraryNamespace,
        private readonly FileTree $storage,
        private readonly IndexInterface $index,
        private readonly ?Analyses $analyses = null,
        private readonly ?AuditLog $audit = null,
        // The audio overview (phase 8): null when no speech model is set in Admin → AI
        private readonly ?AudioOverview $audio = null,
    ) {
    }

    /** Whether Studio can read a script aloud: a speech model is set */
    public function speaks(): bool
    {
        return $this->audio !== null;
    }

    /**
     * The result kept as the page's analysis named by its prompt (Service\Library\Analyses: the
     * owner's decision, 2026-10-31), the writer's revision, audited as theirs with the prompt as
     * `assisted`; a failure to keep it is said, never the end of the answer
     *
     * @param array<string, mixed> $extra
     *
     * @return ?array{path: string, href: string, created: bool}
     */
    private function keep(PageRecord $of, string $prompt, string $label, string $text, User $by, string $provider, ?Request $request, array $extra = []): ?array
    {
        if ($this->analyses === null || trim($text) === '') {
            return null;
        }
        try {
            $saved = $this->analyses->save($of, $prompt, $label, $text, $by, $provider, $extra);
        } catch (\Throwable $e) {
            error_log(\sprintf('reporion: analysis not kept: %s at %s:%d', $e::class, $e->getFile(), $e->getLine()));

            return null;
        }
        if ($saved === null) {
            return null;
        }
        $this->audit?->record($saved['created'] ? 'page.create' : 'page.save', $by->username, $request, $saved['pid'], $saved['path'], $saved['rev'], extra: ['assisted' => [$prompt]]);

        return ['path' => $saved['path'], 'href' => ($request?->basePath ?? '') . '/' . $saved['path'], 'created' => $saved['created']];
    }

    public function analyze(Request $request, string $pid, ?User $principal): Response
    {
        $row = $principal !== null ? $this->index->findByPid($pid, $principal) : null;
        if ($principal === null || $row === null || !$principal->canWrite((string) $row['path'])) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        try {
            $page = $this->storage->read((string) $row['path']);
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if (!LibrarySource::isSource($page->frontmatter)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if (!$this->config->isConfigured()) {
            return ApiResponse::error(503, 'not_configured', t('ai.err.not_configured'));
        }
        $fields = $request->json();
        $task = \is_string($fields['task'] ?? null) ? $fields['task'] : '';
        if (!isset(SourceAnalysis::TASKS[$task]) || !\in_array($task, $this->analysis->available($page->path), true)) {
            return ApiResponse::error(422, 'unknown_task', t('library.ai.unknown_task'));
        }
        try {
            $plan = $this->analysis->plan($page);
        } catch (LibraryException) {
            return ApiResponse::error(413, 'too_long', t('library.ai.too_long', [(string) SourceAnalysis::MAX_PARTS]));
        }
        // Every request has the Assistant's retries: room for all of them
        $seconds = $plan['requests'] * Assistant::maxSeconds($this->config->timeout) + 60;
        $run = function (\Closure $progress) use ($task, $page, $principal, $request): array {
            $done = $this->analysis->run($task, $page, $principal, $request, $progress);

            return $done + ['saved' => $this->keep($page, SourceAnalysis::TASKS[$task], t('library.ai.' . $task), $done['result'], $principal, $done['provider'], $request)];
        };

        if (($fields['stream'] ?? false) !== true) {
            @set_time_limit($seconds);
            try {
                $done = $run(static function (int $step, int $steps): void {
                });
            } catch (AiException $e) {
                return AiController::error($e);
            } catch (LibraryException $e) {
                return ApiResponse::error($e->reason === LibraryException::TOO_LARGE ? 413 : 422, $e->reason, t('library.ai.' . ($e->reason === LibraryException::TOO_LARGE ? 'too_long' : 'no_text'), [(string) SourceAnalysis::MAX_PARTS]));
            }

            return ApiResponse::json(['data' => $done]);
        }

        return Response::eventStream(function () use ($run, $seconds): void {
            set_time_limit($seconds);
            $send = static function (string $event, array $data): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            };
            try {
                $done = $run(static fn (int $step, int $steps) => $send('progress', ['step' => $step, 'steps' => $steps]));
                $send('done', $done);
            } catch (AiException $e) {
                $send('error', ['code' => $e->reason, 'message' => AiController::message($e)]);
            } catch (LibraryException $e) {
                $send('error', ['code' => $e->reason, 'message' => t('library.ai.' . ($e->reason === LibraryException::TOO_LARGE ? 'too_long' : 'no_text'), [(string) SourceAnalysis::MAX_PARTS])]);
            } catch (\Throwable $e) {
                error_log(\sprintf('%s at %s:%d', $e::class, $e->getFile(), $e->getLine()));
                $send('error', ['code' => 'internal', 'message' => AiController::message(new AiException('internal'))]);
            }
        });
    }

    /** The longest question */
    public const MAX_QUESTION = 1000;

    public function askNotebook(Request $request, ?User $principal): Response
    {
        $fields = $request->json();
        $path = \is_string($fields['path'] ?? null) ? trim($fields['path'], ': ') : '';
        $page = $principal !== null && $path !== '' ? $this->notebook($path, $principal) : null;
        if ($principal === null || $page === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if (!$this->config->isConfigured() || !$this->notebooks->available($page->path)) {
            return ApiResponse::error(503, 'not_configured', t('notebook.unavailable'));
        }
        $question = \is_string($fields['question'] ?? null) ? trim($fields['question']) : '';
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION) {
            return ApiResponse::error(422, 'invalid_question', t('notebook.err_question'));
        }
        // The search words, then the answer
        @set_time_limit(2 * Assistant::maxSeconds($this->config->timeout) + 60);
        try {
            $done = $this->notebooks->ask($page, $question, $principal, $request);
        } catch (AiException $e) {
            return AiController::error($e);
        }
        foreach ($done['sources'] as $i => $source) {
            $done['sources'][$i]['href'] = $request->basePath . '/' . $source['path'];
        }

        return ApiResponse::json(['data' => $done]);
    }

    public function studio(Request $request, ?User $principal): Response
    {
        $fields = $request->json();
        $path = \is_string($fields['path'] ?? null) ? trim($fields['path'], ': ') : '';
        $page = $principal !== null && $path !== '' ? $this->notebook($path, $principal) : null;
        if ($principal === null || $page === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if (!$this->config->isConfigured()) {
            return ApiResponse::error(503, 'not_configured', t('ai.err.not_configured'));
        }
        $task = \is_string($fields['task'] ?? null) ? $fields['task'] : '';
        if (!isset(Studio::TASKS[$task]) || !\in_array($task, $this->studio->available($page->path), true)) {
            return ApiResponse::error(422, 'unknown_task', t('studio.unknown_task'));
        }
        if ($task === 'audio' && $this->audio === null) {
            return ApiResponse::error(422, 'unknown_task', t('studio.audio_off'));
        }
        $questions = [];
        if ($task === 'compare') {
            try {
                $questions = Studio::questions($fields['questions'] ?? null);
            } catch (LibraryException) {
                return ApiResponse::error(422, 'invalid_questions', t('studio.err_questions', [(string) Studio::MAX_QUESTIONS, (string) Studio::MAX_QUESTION]));
            }
        }
        try {
            $plan = $this->studio->plan($task, $page, $principal);
        } catch (LibraryException $e) {
            return match ($e->reason) {
                LibraryException::TOO_LARGE => ApiResponse::error(413, 'too_long', t($task === 'check' ? 'studio.too_long_check' : 'studio.too_long', [(string) Studio::MAX_REQUESTS])),
                LibraryException::NO_TEXT => ApiResponse::error(422, 'no_text', t('studio.no_page_text')),
                LibraryException::TOOL_MISSING => ApiResponse::error(503, 'not_configured', t('studio.no_part_prompt')),
                default => ApiResponse::error(422, 'no_sources', t('studio.no_sources')),
            };
        }
        $seconds = $plan['requests'] * Assistant::maxSeconds($this->config->timeout) + 60
            // The audio overview's turns, each a speech request after the script
            + ($task === 'audio' ? AudioScript::MAX_TURNS * ($this->config->timeout + 15) : 0);
        $basePath = $request->basePath;
        $run = function (\Closure $progress, ?\Closure $speaking = null) use ($task, $plan, $page, $principal, $request, $questions, $basePath): array {
            $done = $this->studio->run($plan, $page, $principal, $request, $questions, $progress);
            foreach ($done['sources'] as $i => $source) {
                $done['sources'][$i]['href'] = $basePath . '/' . $source['path'];
            }
            if ($done['template'] !== null) {
                $done['template']['problems'] = array_map(static fn (array $p): string => TemplateLintText::message($p), $done['template']['problems']);
                $done['template']['reference'] = $page->path;
            }
            $extra = [
                'language' => $page->frontmatter['language'] ?? null,
                'citation_style' => $page->frontmatter['citation_style'] ?? null,
            ];
            // The audio overview: the script read aloud, the file beside the library's originals; a failure keeps the script
            $done['audio'] = null;
            if ($task === 'audio' && $this->audio !== null && trim($done['result']) !== '') {
                try {
                    $made = $this->audio->make($done['result'], $speaking ?? static function (int $step, int $steps): void {
                    });
                    $extra['audio'] = $this->storage->storeOriginal($made['bytes'], $made['ext']) + ['seconds' => $made['seconds'], 'turns' => $made['turns']];
                    if ($made['cut']) {
                        $done['notices'][] = t('studio.audio_cut');
                    }
                } catch (AiException $e) {
                    $done['notices'][] = t('studio.audio_failed', [AiController::message($e)]);
                }
            }
            // Kept beside the notebook, reading as it reads (its language, its citation style)
            $done['saved'] = $this->keep($page, Studio::TASKS[$task], t('studio.' . $task), $done['result'], $principal, $done['provider'], $request, $extra);
            if (isset($extra['audio']) && $done['saved'] !== null) {
                $kept = $this->index->findByPath($done['saved']['path'], $principal);
                $done['audio'] = $kept !== null ? ['href' => $basePath . '/library/audio/' . $kept['pid'], 'seconds' => $extra['audio']['seconds'], 'ext' => $extra['audio']['ext']] : null;
            }

            return $done;
        };

        if (($fields['stream'] ?? false) !== true) {
            @set_time_limit($seconds);
            try {
                $done = $run(static function (int $step, int $steps): void {
                });
            } catch (AiException $e) {
                return AiController::error($e);
            }

            return ApiResponse::json(['data' => $done]);
        }

        return Response::eventStream(function () use ($run, $seconds): void {
            set_time_limit($seconds);
            $send = static function (string $event, array $data): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            };
            try {
                $send('done', $run(
                    static fn (int $step, int $steps) => $send('progress', ['step' => $step, 'steps' => $steps]),
                    static fn (int $step, int $steps) => $send('speaking', ['step' => $step, 'steps' => $steps]),
                ));
            } catch (AiException $e) {
                $send('error', ['code' => $e->reason, 'message' => AiController::message($e)]);
            } catch (\Throwable $e) {
                error_log(\sprintf('%s at %s:%d', $e::class, $e->getFile(), $e->getLine()));
                $send('error', ['code' => 'internal', 'message' => AiController::message(new AiException('internal'))]);
            }
        });
    }

    /**
     * The notebook a caller may ask: a page they read and write, never a report or a page naming
     * a patient, by someone who also writes in the library (the owner's rule for the assistant)
     */
    public function notebook(string $path, User $principal): ?\Reporion\Storage\PageRecord
    {
        if (ReportPath::isReport($path) || !$principal->canWrite($path) || !$principal->canWrite($this->libraryNamespace)) {
            return null;
        }
        $row = $this->index->findByPath($path, $principal);
        if ($row === null) {
            return null;
        }
        try {
            $page = $this->storage->read((string) $row['path']);
        } catch (PageNotFoundException) {
            return null;
        }

        // A saved analysis is no notebook of its own
        return isset($page->frontmatter['patient']) || ReportPath::isReport($page->path) || Analyses::isAnalysis($page->frontmatter) ? null : $page;
    }
}
