<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\LibraryException;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\Dossier\Dossier;
use Reporion\Service\Library\Extractor;

/**
 * `POST /api/v1/dossier {path, text?, form?, prompt, name, file, stream?}` (D64): the editor
 * rail's Dossier for a report a radiologist may write (EditorPages: else 404, or 403
 * `not_radiologist`). `file` is the patient's folder in base64 (a PDF, or an image), `name` its
 * file name (for the type only), `prompt` one of Dossier::PROMPTS. `text`/`form` as Workup's: the
 * editor's report as it stands, so the redactor learns the name typed but not saved yet.
 * Answers `{data: {answer, pages, ocr, chars, notices, context, ms, provider}}`, or with
 * `stream: true` server-sent events: `progress` {page, pages, ocr} | {step}, `delta` {text},
 * `done`, `error`. Nothing is kept (the file and its text live for the request only); the
 * synthesis is audited `ai.call` with the page's pid. Shown, never written (D8).
 */
final class DossierController
{
    private const MAX_TEXT = 200_000;

    public function __construct(
        private readonly EditorPages $editorPages,
        private readonly Dossier $dossier,
        private readonly AiConfig $config,
    ) {
    }

    public function read(Request $request, ?User $principal): Response
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
        if (!$this->config->isConfigured()) {
            return ApiResponse::error(422, 'no_prompt', t('dossier.err.no_prompt'));
        }
        $prompt = \is_string($fields['prompt'] ?? null) ? $fields['prompt'] : Dossier::PROMPTS[0];
        $name = \is_string($fields['name'] ?? null) ? $fields['name'] : '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'pdf' && !\in_array($ext, Extractor::IMAGES, true)) {
            return ApiResponse::error(422, 'unsupported', t('dossier.err.unsupported'));
        }
        $encoded = \is_string($fields['file'] ?? null) ? $fields['file'] : '';
        if (\strlen($encoded) > intdiv(Dossier::MAX_BYTES * 4, 3) + 4) {
            return ApiResponse::error(413, 'too_large', t('dossier.err.too_large', [(string) intdiv(Dossier::MAX_BYTES, 1024 * 1024)]));
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '') {
            return ApiResponse::error(422, 'invalid', t('dossier.err.failed'));
        }
        $stream = ($fields['stream'] ?? false) === true;
        unset($encoded, $fields);
        $seconds = Dossier::MAX_PAGES * (2 * Extractor::OCR_PAGE_SECONDS + 10) + 3 * Assistant::maxSeconds($this->config->longestTimeout()) + 60;

        if (!$stream) {
            @set_time_limit($seconds);
            try {
                return ApiResponse::json(['data' => $this->dossier->run($page, $principal, $bytes, $ext, $prompt, $request)]);
            } catch (LibraryException $e) {
                return ApiResponse::error(422, $e->reason, self::fileMessage($e));
            } catch (AiException $e) {
                return $e->reason === 'no_prompt' ? ApiResponse::error(422, 'no_prompt', t('dossier.err.no_prompt')) : AiController::error($e);
            }
        }

        return Response::eventStream(function () use ($page, $principal, $request, $bytes, $ext, $prompt, $seconds): void {
            set_time_limit($seconds);
            $send = static function (string $event, array $data): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            };
            try {
                $send('done', $this->dossier->run($page, $principal, $bytes, $ext, $prompt, $request, $send));
            } catch (LibraryException $e) {
                $send('error', ['code' => $e->reason, 'message' => self::fileMessage($e)]);
            } catch (AiException $e) {
                $send('error', ['code' => $e->reason, 'message' => $e->reason === 'no_prompt' ? t('dossier.err.no_prompt') : AiController::message($e)]);
            } catch (\Throwable $e) {
                error_log(\sprintf('%s at %s:%d', $e::class, $e->getFile(), $e->getLine()));
                $send('error', ['code' => 'internal', 'message' => AiController::message(new AiException('internal'))]);
            }
        });
    }

    private static function fileMessage(LibraryException $e): string
    {
        return match ($e->reason) {
            LibraryException::SCAN_TOO_LONG => t('dossier.err.too_long', [(string) Dossier::MAX_PAGES]),
            LibraryException::NO_OCR, LibraryException::TOOL_MISSING => t('dossier.err.no_ocr'),
            LibraryException::TIMEOUT => t('dossier.err.timeout'),
            LibraryException::NO_TEXT => t('dossier.err.no_text'),
            LibraryException::UNSUPPORTED => t('dossier.err.unsupported'),
            default => t('dossier.err.failed'),
        };
    }
}
