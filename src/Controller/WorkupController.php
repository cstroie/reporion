<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\Workup\Workup;

/**
 * `POST /api/v1/workup {path, text?, form?, synthesis?, stream?}` (D63): the editor rail's Workup
 * for a report a radiologist may write (EditorPages: else 404, or 403 `not_radiologist`). `text`
 * is the editor's body as it stands (the stored one when absent), `form` the editor's form for a
 * report not written yet. Answers `{data: {blocks, answer, notices, context, ms, provider}}`, or with
 * `stream: true` server-sent events: `progress` {step}, `blocks`, `delta` {text}, `done`, `error`.
 * Every item is linked: a library source and a protocol by its page, a report by `/r/{pid}/{rev}`
 * (its path is never sent, invariant 8), a guide situation by `/guide/{key}`. Shown, never
 * written (D8); the synthesis is audited `ai.call` with the page's pid.
 */
final class WorkupController
{
    private const MAX_TEXT = 200_000;

    public function __construct(
        private readonly EditorPages $editorPages,
        private readonly Workup $workup,
        private readonly AiConfig $config,
    ) {
    }

    public function workup(Request $request, ?User $principal): Response
    {
        $fields = $request->json();
        $path = \is_string($fields['path'] ?? null) ? trim($fields['path'], ': ') : '';
        $text = \is_string($fields['text'] ?? null) ? $fields['text'] : '';
        $form = \is_string($fields['form'] ?? null) ? $fields['form'] : '';
        if (mb_strlen($text) > self::MAX_TEXT || mb_strlen($form) > 2 * self::MAX_TEXT) {
            return ApiResponse::error(413, 'too_long', 'The text is too long.');
        }
        $page = $this->editorPages->forReport($path, $principal, $form, $text);
        if ($page instanceof Response) {
            return $page;
        }
        \assert($principal instanceof User);
        $synthesis = ($fields['synthesis'] ?? true) !== false && $this->config->isConfigured();
        $base = $request->basePath;
        $linked = static fn (array $data): array => isset($data['blocks']) ? ['blocks' => self::linked($data['blocks'], $base)] + $data : $data;
        $seconds = 3 * Assistant::maxSeconds($this->config->longestTimeout()) + 60;

        if (($fields['stream'] ?? false) !== true) {
            @set_time_limit($seconds);
            try {
                $done = $this->workup->run($page, $principal, $request, null, $synthesis);
            } catch (AiException $e) {
                return AiController::error($e);
            }

            return ApiResponse::json(['data' => $linked($done)]);
        }

        return Response::eventStream(function () use ($page, $principal, $request, $synthesis, $linked, $seconds): void {
            set_time_limit($seconds);
            $send = static function (string $event, array $data): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            };
            try {
                $done = $this->workup->run($page, $principal, $request, static function (string $event, array $data) use ($send, $linked): void {
                    $send($event, $linked($data));
                }, $synthesis);
                $send('done', $linked($done));
            } catch (AiException $e) {
                $send('error', ['code' => $e->reason, 'message' => AiController::message($e)]);
            } catch (\Throwable $e) {
                error_log(\sprintf('%s at %s:%d', $e::class, $e->getFile(), $e->getLine()));
                $send('error', ['code' => 'internal', 'message' => AiController::message(new AiException('internal'))]);
            }
        });
    }

    /**
     * The blocks as the browser gets them: each item's link, a report's path taken out
     *
     * @param array<string, mixed> $blocks
     *
     * @return array<string, mixed>
     */
    public static function linked(array $blocks, string $base): array
    {
        $report = static function (array $item) use ($base): array {
            $item['href'] = $base . '/r/' . $item['pid'] . '/' . $item['rev'];
            unset($item['path']);

            return $item;
        };
        $blocks['library'] = array_map(static fn (array $p): array => ['href' => $base . '/' . $p['path']] + $p, $blocks['library']);
        $blocks['context']['studies'] = array_map($report, $blocks['context']['studies']);
        $blocks['similar'] = array_map($report, $blocks['similar']);
        $blocks['next']['guide'] = array_map(static fn (array $g): array => ['href' => $base . '/guide/' . $g['key']] + $g, $blocks['next']['guide']);
        $blocks['next']['protocols'] = array_map(static fn (array $p): array => ['href' => $base . '/' . $p['path']] + $p, $blocks['next']['protocols']);

        return $blocks;
    }
}
