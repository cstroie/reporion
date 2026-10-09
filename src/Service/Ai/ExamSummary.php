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
 * The `summary` field from the assistant, one exam at a time (2026-10-09):
 * each conclusion is asked about on its own — its exam's, or a shared one —
 * each answer tidied to a line (SummaryLine::tidy()), and the lines put
 * together led by what was examined (SummaryLine::byExam()): "IRM Genunchi
 * Drept: Aspect normal. IRM Genunchi Stâng: Minim edem…". A one-exam report
 * gets its exam in front too. The label is the code's, not the model's, so
 * it is there whatever the prompt page says. A report with no conclusion
 * worth summarising (Conclusion::MIN) is asked about as a whole, once.
 */
final class ExamSummary
{
    public function __construct(
        private readonly Assistant $assistant,
    ) {
    }

    /**
     * What to ask about, in order: a label (the exam title) and its text
     *
     * @return list<array{label: string, text: string, source: string}>
     */
    public static function parts(PageRecord $page): array
    {
        $fm = $page->frontmatter;
        $body = ReportName::withoutNameHeading($page->body, $fm);
        $report = ReportName::examTitle($fm);
        $sections = Conclusion::parts($body);
        if ($sections === [] || mb_strlen(implode('', array_column($sections, 'text'))) < Conclusion::MIN) {
            return [['label' => $report, 'text' => $body, 'source' => 'text']];
        }

        return array_map(static fn (array $s): array => [
            'label' => !$s['shared'] && trim($s['exam']) !== '' ? trim($s['exam']) : $report,
            'text' => $s['text'],
            'source' => 'conclusion',
        ], $sections);
    }

    /**
     * Asks once per part and puts the lines together.
     *
     * @param \Closure(string): void|null $emit receives each part's line as it is ready
     *
     * @return array{summary: string, ms: int, usage: array<string, int>, contextSet: list<string>, provider: string}
     *
     * @throws AiException the assistant's reason, or `empty` when no part gave a line
     */
    public function run(Action $action, PageRecord $page, User $user, ?Request $request = null, ?\Closure $emit = null): array
    {
        $lines = [];
        $ms = 0;
        $usage = [];
        $contextSet = [];
        $provider = '';
        foreach (self::parts($page) as $part) {
            $answer = '';
            $done = $this->assistant->run($action, $page, $part['text'], $part['source'], null, '', $user, $request, static function (string $piece) use (&$answer): void {
                $answer .= $piece;
            });
            $line = SummaryLine::tidy($answer);
            if ($line !== '') {
                $lines[] = ['label' => $part['label'], 'text' => $line];
                if ($emit !== null) {
                    $emit(SummaryLine::byExam([end($lines)]));
                }
            }
            $ms += $done['ms'];
            foreach ($done['usage'] as $key => $n) {
                $usage[$key] = ($usage[$key] ?? 0) + $n;
            }
            $contextSet = array_values(array_unique([...$contextSet, ...$done['contextSet']]));
            $provider = $done['provider'];
        }
        $summary = SummaryLine::byExam($lines);
        if ($summary === '') {
            throw new AiException('empty', 'The assistant gave no summary');
        }
        if (\count($lines) > 1) {
            $contextSet[] = \count($lines) . ' exams';
        }

        return ['summary' => $summary, 'ms' => $ms, 'usage' => $usage, 'contextSet' => $contextSet, 'provider' => $provider];
    }
}
