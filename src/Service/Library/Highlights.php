<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\IndexInterface;
use Reporion\Service\Comments;
use Reporion\Storage\FileTree;
use Reporion\Storage\PageRecord;
use Reporion\Support\LibrarySource;
use Reporion\Support\ReportPath;
use Reporion\Support\SourceChunks;

/**
 * Highlights (docs/library.md, phase 7): a comment on a library source that quotes it is a
 * highlight; *Send to page* adds the quote to a page the writer chooses, as a blockquote citing
 * the source and the page of the article it is on, `> quote [@key, p. N]`, with the comment's own
 * words under it. A passage (2026-11-11, owner's request) is the same without a comment: the
 * text selected in the source, verbatim, up to MAX_PASSAGE characters (passage()). Both go at the
 * end of the page's `## Passages` section, made when it has none (intoSection()). One new
 * revision of that page, by the writer, never of a report, a page naming a patient, a library
 * source, a saved analysis or a signed page. Nothing is generated (no assistant).
 */
final class Highlights
{
    /** The longest passage taken verbatim */
    public const MAX_PASSAGE = 4_000;

    public function __construct(
        private readonly FileTree $storage,
        private readonly IndexInterface $index,
        private readonly Comments $comments,
        private readonly string $libraryNamespace,
    ) {
    }

    /**
     * @return array{path: string, rev: int, page: ?int}
     *
     * @throws PageNotFoundException the source, the comment or the target the caller may not reach
     * @throws LibraryException      INVALID: a comment with no quote, or a target that takes no highlight (a
     *                               report, a page naming a patient, a library source, a signed page)
     */
    public function send(string $sourcePid, string $commentId, string $to, User $principal): array
    {
        $source = $this->source($sourcePid, $principal);
        $comment = null;
        foreach ($this->comments->of($source->path) as $c) {
            if ($c['id'] === $commentId) {
                $comment = $c;
            }
        }
        if ($comment === null) {
            throw new PageNotFoundException($commentId);
        }
        $quote = self::bare($comment['quote']);
        if ($quote === '') {
            throw new LibraryException(LibraryException::INVALID);
        }

        return $this->append($source, $quote, trim((string) preg_replace('/\s+/u', ' ', $comment['text'])), $to, $principal);
    }

    /**
     * The text selected in a source, verbatim, added to the page `to` (2026-11-11): no comment
     *
     * @return array{path: string, rev: int, page: ?int}
     *
     * @throws PageNotFoundException the source or the target the caller may not reach
     * @throws LibraryException      INVALID: an empty or too long passage, or a target that takes none; PATIENT_DATA: a CNP in it
     */
    public function passage(string $sourcePid, string $quote, string $to, User $principal): array
    {
        $source = $this->source($sourcePid, $principal);
        $quote = trim((string) preg_replace('/\s+/u', ' ', $quote));
        if ($quote === '' || mb_strlen($quote) > self::MAX_PASSAGE) {
            throw new LibraryException(LibraryException::INVALID);
        }
        if (Library::holdsPatientData($quote)) {
            throw new LibraryException(LibraryException::PATIENT_DATA);
        }

        return $this->append($source, $quote, '', $to, $principal);
    }

    /**
     * The pages that cite the source and could take its passages, for the picker: the caller
     * writes them and they are not reports, pages naming a patient, library pages, saved
     * analyses or signed (under the caller's visibility, invariant 6), the latest first
     *
     * @return list<array{path: string, title: string}>
     */
    public function notebooks(string $sourcePid, User $principal, int $limit = 20): array
    {
        $out = [];
        foreach ($this->index->backlinks($sourcePid, $principal) as $row) {
            if (\count($out) >= $limit) {
                break;
            }
            $path = $row['path'];
            if (str_starts_with($path, $this->libraryNamespace . ':') || !$principal->canWrite($path)) {
                continue;
            }
            try {
                if ($this->takes($this->storage->read($path))) {
                    $out[] = ['path' => $path, 'title' => $row['title'] !== '' ? $row['title'] : $path];
                }
            } catch (PageNotFoundException) {
            }
        }

        return $out;
    }

    /**
     * $entry at the end of $body's `## {heading}` section (up to the next `#` or `##` heading,
     * fenced code aside), or in a new one at the end of the body when it has none
     */
    public static function intoSection(string $body, string $heading, string $entry): string
    {
        $lines = preg_split('/\R/u', rtrim($body)) ?: [];
        $start = null;
        $end = \count($lines);
        $fence = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $fence = $fence === null ? $m[1][0] : ($m[1][0] === $fence ? null : $fence);
                continue;
            }
            if ($fence !== null) {
                continue;
            }
            if ($start === null && preg_match('/^##\s+(.+?)\s*#*\s*$/u', $line, $m) === 1 && mb_strtolower($m[1]) === mb_strtolower($heading)) {
                $start = $i;
            } elseif ($start !== null && preg_match('/^#{1,2}\s/', $line) === 1) {
                $end = $i;
                break;
            }
        }
        $entry = trim($entry);
        if ($start === null) {
            return (trim($body) === '' ? '' : rtrim($body) . "\n\n") . '## ' . $heading . "\n\n" . $entry . "\n";
        }
        $before = rtrim(implode("\n", \array_slice($lines, 0, $end)));
        $after = trim(implode("\n", \array_slice($lines, $end)));

        return $before . "\n\n" . $entry . "\n" . ($after !== '' ? "\n" . $after . "\n" : '');
    }

    /** The library source $sourcePid as the caller reads it */
    private function source(string $sourcePid, User $principal): PageRecord
    {
        $row = $this->index->findByPid($sourcePid, $principal);
        if ($row === null || !str_starts_with((string) $row['path'], $this->libraryNamespace . ':')) {
            throw new PageNotFoundException($sourcePid);
        }
        $source = $this->storage->read((string) $row['path']);
        if (!LibrarySource::isSource($source->frontmatter)) {
            throw new PageNotFoundException($sourcePid);
        }

        return $source;
    }

    /** Whether a page takes a passage: not a report, a page naming a patient, a library source, a saved analysis or a signed page */
    private function takes(PageRecord $target): bool
    {
        return !ReportPath::isReport($target->path) && !isset($target->frontmatter['patient']) && !LibrarySource::isSource($target->frontmatter)
            && !Analyses::isAnalysis($target->frontmatter) && $target->status !== 'signed';
    }

    /**
     * `> quote [@key, p. N]` (and the note under it) at the end of the target's Passages section
     *
     * @return array{path: string, rev: int, page: ?int}
     */
    private function append(PageRecord $source, string $quote, string $note, string $to, User $principal): array
    {
        $to = trim($to, ': ');
        if ($to === '' || !$principal->canWrite($to) || $this->index->findByPath($to, $principal) === null) {
            throw new PageNotFoundException($to);
        }
        $target = $this->storage->read($to);
        if (!$this->takes($target)) {
            throw new LibraryException(LibraryException::INVALID);
        }
        $key = substr($source->path, \strlen($this->libraryNamespace) + 1);
        $page = self::pageOf($source->body, $quote);
        $block = '> ' . self::escape($quote) . ' [@' . $key . ($page !== null ? ', p. ' . $page : '') . ']';
        $body = self::intoSection($target->body, t('library.passages_heading'), $block . ($note !== '' ? "\n\n" . self::escape($note) : ''));
        $saved = $this->storage->save($target->path, $target->frontmatter, $body, $target->rev, $principal->username, t('library.highlight_note', [$key]));

        return ['path' => $saved->path, 'rev' => $saved->rev, 'page' => $page];
    }

    /** The quote as typed, without the "…" a long one was cut with */
    private static function bare(string $quote): string
    {
        return trim((string) preg_replace('/…$/u', '', trim($quote)));
    }

    /**
     * The page of the article the quote is on: the marker in force where it stands in the text
     * (whitespace folded, the markdown escapes the extracted text carries undone); null when it
     * is not found or the text has no markers
     */
    public static function pageOf(string $body, string $quote): ?int
    {
        $text = (string) preg_replace('/\s+/u', ' ', str_replace(['\\[', '\\]', '&lt;', '\\#', '\\>', '\\-', '\\*', '\\.', '\\)'], ['[', ']', '<', '#', '>', '-', '*', '.', ')'], SourceChunks::withoutReferences($body)));
        // A byte offset, as pageAt() takes it
        $at = stripos($text, (string) preg_replace('/\s+/u', ' ', $quote));
        if ($at === false) {
            return null;
        }

        return SourceChunks::pageAt($text, $at);
    }

    /** Plain text in markdown: nothing in it becomes a link, a citation, emphasis or a tag */
    private static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]<>#])/u', '\\\\$1', $text);
    }
}
