<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Storage\PageRecord;
use Reporion\Support\ReportName;

/**
 * "A new report like this one" (POST /pages/{path}/duplicate, the page's
 * Duplicate action, bin/reporion page:new --template=): the source's body
 * and its exam fields, never its patient fields (decided 2026-09-26).
 *
 * The copy always starts as a private draft: a duplicate is a new
 * document, whatever the source's visibility or signature.
 */
final class Duplicates
{
    /** Carried over by default: what describes the exam, not the patient */
    public const DEFAULT_KEEP = ['title', 'exam_title', 'modality', 'region', 'site', 'device', 'protocol', 'template'];

    /** Never carried over, whatever is asked for */
    private const NEVER = ['patient', 'accession', 'study_date', 'summary', 'status', 'visibility', 'imported_from', 'import_batch', 'review', 'priors'];

    /**
     * @param list<string> $keep frontmatter keys to carry over
     *
     * @return array{0: array<string, mixed>, 1: string} frontmatter and body for Storage::create()
     */
    public static function document(PageRecord $source, array $keep = self::DEFAULT_KEEP): array
    {
        $frontmatter = [];
        foreach ($keep as $key) {
            if (!\in_array($key, self::NEVER, true) && \array_key_exists($key, $source->frontmatter)) {
                $frontmatter[$key] = $source->frontmatter[$key];
            }
        }
        $frontmatter['visibility'] = 'private';
        // A report titled by its patient (D30): the copy takes the exam title,
        // and loses the name heading, since the patient does not come along
        if (\array_key_exists('title', $frontmatter)) {
            $frontmatter['title'] = ReportName::examTitle($source->frontmatter);
        }

        return [$frontmatter, ReportName::withoutNameHeading($source->body, $source->frontmatter)];
    }
}
