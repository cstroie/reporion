<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Dossier;

use Closure;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\LibraryException;
use Reporion\Http\Request;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Workup\Workup;
use Reporion\Storage\PageRecord;

/**
 * The Dossier (D64): a patient's folder (a scanned or printed PDF, or a photographed page) for the
 * report in front, read page by page (Extractor::readPages(): text layer, else tesseract), then one
 * synthesis by a reserved prompt (`dossier`, `dossier-onco`, `dossier-neuro`): an overview, a
 * stub of the report's history and comparison, what to check. Nothing is kept: the file and its
 * text live for the request only (a private temporary directory, removed); no page, row, index
 * entry or log holds them. Shown, never written (D8).
 */
final class Dossier
{
    /** The prompts the rail offers, in order: the general one first */
    public const PROMPTS = ['dossier', 'dossier-onco', 'dossier-neuro'];

    /** The most pages a folder may have */
    public const MAX_PAGES = 60;

    /** The most bytes of the file */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /** The most characters of the folder's text the prompt carries; the rest is left out, with a notice */
    public const MAX_CHARS = 60000;

    public function __construct(
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly Extractor $extractor,
    ) {
    }

    /** @return list<string> the dossier prompts the profile serving $path has */
    public function available(string $path): array
    {
        return array_values(array_filter(self::PROMPTS, fn (string $id): bool => $this->actions->special($path, $id) !== null));
    }

    /**
     * The folder read, then the synthesis. $event (a stream): `progress` {page, pages, ocr} while
     * reading, `progress` {step: answering, notices}, `delta` {text}.
     *
     * @return array{answer: string, pages: int, ocr: int, chars: int, notices: list<string>, context: list<string>, ms: int, provider: string}
     *
     * @throws LibraryException the file's (unsupported, scan_too_long, no_ocr, timeout, failed, no_text)
     * @throws AiException      no_prompt, busy, identifier_leak, the provider's
     */
    public function run(PageRecord $page, User $principal, string $bytes, string $ext, string $prompt, ?Request $request = null, ?Closure $event = null): array
    {
        $event ??= static function (string $name, array $data): void {
        };
        $action = \in_array($prompt, self::PROMPTS, true) ? $this->actions->special($page->path, $prompt) : null;
        if ($action === null) {
            throw new AiException('no_prompt', 'No dossier prompt for this page');
        }
        $ocr = 0;
        $read = $this->extractor->readPages($bytes, $ext, self::MAX_PAGES, static function (int $n, int $count, bool $isOcr) use ($event, &$ocr): void {
            $ocr += $isOcr ? 1 : 0;
            $event('progress', ['page' => $n, 'pages' => $count, 'ocr' => $isOcr]);
        });
        [$pages, $cut] = self::within($read, self::MAX_CHARS);
        $chars = array_sum(array_map('mb_strlen', $pages));
        if ($chars < 20) {
            throw new LibraryException(LibraryException::NO_TEXT);
        }
        $notices = $cut ? [t('dossier.cut', [number_format(self::MAX_CHARS)])] : [];
        $event('progress', ['step' => 'answering', 'notices' => $notices]);
        $done = $this->assistant->runDossier($action, $page, Workup::facts($page), $pages, $principal, $request, static function (string $piece) use ($event): void {
            $event('delta', ['text' => $piece]);
        });

        return ['answer' => trim($done['result']), 'pages' => \count($read), 'ocr' => $ocr, 'chars' => $chars, 'notices' => $notices, 'context' => $done['contextSet'], 'ms' => $done['ms'], 'provider' => $done['provider']];
    }

    /**
     * The pages within $max characters, in order, the one that crosses it cut short; and whether
     * anything was left out
     *
     * @param list<string> $pages
     *
     * @return array{0: list<string>, 1: bool}
     */
    public static function within(array $pages, int $max): array
    {
        $out = [];
        $left = $max;
        foreach ($pages as $text) {
            $text = trim((string) preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $text));
            $length = mb_strlen($text);
            if ($length > $left) {
                if ($left > 200) {
                    $out[] = mb_substr($text, 0, $left) . '…';
                }

                return [$out, true];
            }
            $out[] = $text;
            $left -= $length;
        }

        return [$out, false];
    }
}
