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
 * highlight; *Send to page* appends the quote to a page the writer chooses, as a blockquote citing
 * the source and the page of the article it is on, `> quote [@key, p. N]`, with the comment's own
 * words under it. One new revision of that page, by the writer, never of a report, a page naming a
 * patient, a library source or a signed page. Nothing is generated (no assistant).
 */
final class Highlights
{
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
        $row = $this->index->findByPid($sourcePid, $principal);
        if ($row === null || !str_starts_with((string) $row['path'], $this->libraryNamespace . ':')) {
            throw new PageNotFoundException($sourcePid);
        }
        $source = $this->storage->read((string) $row['path']);
        if (!LibrarySource::isSource($source->frontmatter)) {
            throw new PageNotFoundException($sourcePid);
        }
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
        $to = trim($to, ': ');
        if ($to === '' || !$principal->canWrite($to) || $this->index->findByPath($to, $principal) === null) {
            throw new PageNotFoundException($to);
        }
        $target = $this->storage->read($to);
        if (ReportPath::isReport($target->path) || isset($target->frontmatter['patient']) || LibrarySource::isSource($target->frontmatter) || $target->status === 'signed') {
            throw new LibraryException(LibraryException::INVALID);
        }
        $key = substr($source->path, \strlen($this->libraryNamespace) + 1);
        $page = self::pageOf($source->body, $quote);
        $block = '> ' . self::escape($quote) . ' [@' . $key . ($page !== null ? ', p. ' . $page : '') . ']';
        $note = trim((string) preg_replace('/\s+/u', ' ', $comment['text']));
        $body = rtrim($target->body) . "\n\n" . $block . "\n" . ($note !== '' ? "\n" . self::escape($note) . "\n" : '');
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
