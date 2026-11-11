<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * A notebook's draft composed from what is already kept (2026-11-11, owner's request): no model
 * is asked. Each source the notebook cites that the caller reads gives one part, under its title
 * and `[@key]`: its kept key points, else its kept summary, else its abstract page; then the
 * notebook's own Studio texts (overview, timeline, comparison, glossary, questions) and its kept
 * answers (KeptAnswers). Everything is read under the caller (invariant 6: Analyses::shown(),
 * abstractShown(), NotebookAsk::cited()). The draft goes to the editor, never into a page: the
 * writer saves it (`assisted: compose`, D8), as it puts the assistant's kept texts together.
 */
final class Compose
{
    /** What a source gives, the first one kept */
    private const SOURCE_PARTS = ['source-points', 'summary'];

    /** The notebook's Studio texts that read as part of a page, in this order (the check, template, cards and audio do not) */
    private const STUDIO_PARTS = ['studio-overview' => 'studio.overview', 'studio-timeline' => 'studio.timeline', 'studio-compare' => 'studio.compare', 'studio-glossary' => 'studio.glossary', 'studio-faq' => 'studio.faq'];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly Analyses $analyses,
        private readonly NotebookAsk $notebooks,
    ) {
    }

    /**
     * @return array{text: string, parts: int} parts 0: nothing kept yet
     */
    public function draft(PageRecord $notebook, User $principal): array
    {
        $sections = [];
        $sources = [];
        foreach ($this->notebooks->cited($notebook, $principal) as $cited) {
            try {
                $record = $this->storage->read($cited['path']);
            } catch (PageNotFoundException) {
                continue;
            }
            $text = null;
            foreach ($this->analyses->shown($record, $principal, self::SOURCE_PARTS) as $kept) {
                $text = $kept['text'];
                break;
            }
            $text ??= $this->analyses->abstractShown($record, $principal)['text'] ?? null;
            if ($text !== null && trim($text) !== '') {
                $sources[] = '### ' . $cited['title'] . ' [@' . $cited['key'] . "]\n\n" . self::demote($text, 3);
            }
        }
        if ($sources !== []) {
            $sections[] = '## ' . t('compose.sources') . "\n\n" . implode("\n\n", $sources);
        }
        $kept = [];
        foreach ($this->analyses->shown($notebook, $principal, [...array_keys(self::STUDIO_PARTS), KeptAnswers::PROMPT]) as $part) {
            $kept[$part['prompt']] = $part['text'];
        }
        foreach (self::STUDIO_PARTS as $prompt => $label) {
            if (trim($kept[$prompt] ?? '') !== '') {
                $sections[] = '## ' . t($label) . "\n\n" . self::demote($kept[$prompt], 2);
            }
        }
        if (trim($kept[KeptAnswers::PROMPT] ?? '') !== '') {
            $sections[] = '## ' . t('compose.answers') . "\n\n" . self::demote($kept[KeptAnswers::PROMPT], 2);
        }

        // A source counts as a part, not the heading that gathers them
        $parts = \count($sources) + \count($sections) - ($sources === [] ? 0 : 1);

        return ['text' => $sections === [] ? '' : implode("\n\n", $sections) . "\n", 'parts' => $parts];
    }

    /**
     * $text with its headings moved below level $level (its highest one becomes $level + 1, none
     * past 6), a `#` page title included; fenced code is left as it is
     */
    public static function demote(string $text, int $level): string
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $fence = null;
        $top = 7;
        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $fence = $fence === null ? $m[1][0] : ($m[1][0] === $fence ? null : $fence);
                continue;
            }
            if ($fence === null && preg_match('/^(#{1,6})\s/', $line, $m) === 1) {
                $top = min($top, \strlen($m[1]));
            }
        }
        if ($top === 7 || $top > $level) {
            return trim($text);
        }
        $shift = $level + 1 - $top;
        $fence = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $fence = $fence === null ? $m[1][0] : ($m[1][0] === $fence ? null : $fence);
                continue;
            }
            if ($fence === null && preg_match('/^(#{1,6})(\s.*)$/', $line, $m) === 1) {
                $lines[$i] = str_repeat('#', min(6, \strlen($m[1]) + $shift)) . $m[2];
            }
        }

        return implode("\n", $lines);
    }
}
