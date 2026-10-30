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
use Reporion\Service\Library\NotebookAsk;
use Reporion\Service\Library\SourceAnalysis;
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
        private readonly string $libraryNamespace,
        private readonly FileTree $storage,
        private readonly IndexInterface $index,
    ) {
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
        $run = fn (\Closure $progress): array => $this->analysis->run($task, $page, $principal, $request, $progress);

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

        return isset($page->frontmatter['patient']) || ReportPath::isReport($page->path) ? null : $page;
    }
}
