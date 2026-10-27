<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Mcp;

use Reporion\Service\Ai\Redactor;
use Reporion\Storage\PageRecord;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;

/**
 * A patient page as the MCP server shows it (D56, phase 2): by pid, never by path, with the
 * patient's name, CNP, accessions and path taken out the way a prompt's are (Ai\Redactor), no
 * frontmatter but the clinical facets, and nothing at all when an identifier survives the
 * redaction (the leak guard, invariant 8). A report going to an MCP client is a report going
 * to that client's model: the same rule as a prompt.
 */
final class ReportView
{
    /**
     * The page de-identified, or null when an identifier is still in it
     *
     * @return ?array<string, mixed>
     */
    public static function of(PageRecord $page, string $basePath = ''): ?array
    {
        $fm = $page->frontmatter;
        $redactor = self::redactor($page);
        $exam = $redactor->redact(ReportName::examTitle($fm));
        $body = trim($redactor->redact(ReportName::withoutNameHeading($page->body, $fm), $fm));
        $summary = $redactor->redact(MetaText::text($fm['summary'] ?? null));
        $view = [
            'pid' => $page->pid,
            'rev' => $page->rev,
            'status' => $page->status,
            'exam' => $exam,
            'modality' => array_values(array_map('strval', (array) ($fm['modality'] ?? []))),
            'region' => array_values(array_map('strval', Exams::regions($fm))),
            'study_date' => MetaText::text($fm['study_date'] ?? null),
            'tags' => array_values(array_filter((array) ($fm['tags'] ?? []), 'is_string')),
            'summary' => $summary,
            'body' => $body,
            'href' => $basePath . '/r/' . $page->pid . '/' . $page->rev,
        ];
        $text = (string) json_encode($view, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $redactor->leaks($text) ? null : $view;
    }

    /** A search snippet of $page de-identified, or '' when an identifier is still in it */
    public static function snippet(PageRecord $page, string $snippet): string
    {
        $redactor = self::redactor($page);
        $clean = $redactor->redact($snippet, $page->frontmatter);

        return $redactor->leaks($clean) ? '' : $clean;
    }

    /** The exam title de-identified: a report's index title is the patient's name (D30) */
    public static function title(PageRecord $page): string
    {
        $redactor = self::redactor($page);
        $title = $redactor->redact(ReportName::examTitle($page->frontmatter));

        return $redactor->leaks($title) ? '' : $title;
    }

    private static function redactor(PageRecord $page): Redactor
    {
        $redactor = new Redactor();
        $redactor->learn($page->frontmatter, $page->path, $page->body);

        return $redactor;
    }
}
