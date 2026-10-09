<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Http\Request;
use Reporion\Storage\PageRecord;
use Reporion\Support\Conclusion;
use Reporion\Support\ReportName;
use Reporion\Support\SummaryLine;

/**
 * The report's `summary` from the assistant: one phrase for the whole
 * report, whatever its exams (2026-10-09 — not one per exam glued
 * together). The text sent is every conclusion, each under the exam it
 * belongs to ("IRM Genunchi Drept:" …), so the model sees both sides of a
 * bilateral study and can name the dominant finding once; a report with no
 * conclusion worth it (Conclusion::MIN) is sent whole. How short and what
 * to pick is the `summary` prompt page's to say; the answer is tidied to
 * one line (SummaryLine::tidy()).
 */
final class ReportSummary
{
    public function __construct(
        private readonly Assistant $assistant,
    ) {
    }

    /**
     * What is sent: the conclusions under their exams, or the whole text
     *
     * @return array{text: string, source: string}
     */
    public static function text(PageRecord $page): array
    {
        $fm = $page->frontmatter;
        $body = ReportName::withoutNameHeading($page->body, $fm);
        $report = ReportName::examTitle($fm);
        $sections = Conclusion::parts($body);
        if ($sections === [] || mb_strlen(implode('', array_column($sections, 'text'))) < Conclusion::MIN) {
            return ['text' => $body, 'source' => 'text'];
        }
        $blocks = array_map(static function (array $s) use ($report): string {
            $exam = !$s['shared'] && trim($s['exam']) !== '' ? trim($s['exam']) : $report;

            return ($exam !== '' ? $exam . ":\n" : '') . $s['text'];
        }, $sections);

        return ['text' => implode("\n\n", $blocks), 'source' => 'conclusion'];
    }

    /**
     * @return array{summary: string, ms: int, usage: array<string, int>, contextSet: list<string>, provider: string}
     *
     * @throws AiException the assistant's reason, or `empty` when it gave no line
     */
    public function run(Action $action, PageRecord $page, User $user, ?Request $request = null): array
    {
        $sent = self::text($page);
        $answer = '';
        $done = $this->assistant->run($action, $page, $sent['text'], $sent['source'], null, '', $user, $request, static function (string $piece) use (&$answer): void {
            $answer .= $piece;
        });
        $summary = SummaryLine::tidy($answer);
        if ($summary === '') {
            throw new AiException('empty', 'The assistant gave no summary');
        }

        return ['summary' => $summary, 'ms' => $done['ms'], 'usage' => $done['usage'], 'contextSet' => $done['contextSet'], 'provider' => $done['provider']];
    }
}
