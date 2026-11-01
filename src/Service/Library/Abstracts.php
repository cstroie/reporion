<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\SourceAbstract;

/**
 * A library source's abstract, kept as a page beside it (docs/library.md, owner's request
 * 2026-11-01): `library:{key}:abstract`, private, its body the abstract alone, frontmatter
 * `abstract` {of (the source's pid), from (`record`: the record's `csl.abstract`, which only a
 * PubMed or CrossRef lookup, an import or a hand edit fills, when it has MIN_RECORD_WORDS words;
 * `text`: the article's own, read by Support\SourceAbstract), at}. Made when a source is added (Library), by `library:abstracts` for
 * those added before; no AI. A writer may correct it: the marker stays and the text is then what
 * is used. The short analyses read it instead of the whole article (SourceAnalysis::ABSTRACT_TASKS).
 * It moves and goes to the trash with its source (Analyses::under()). A page at that path that
 * is not an abstract page, or one signed, is never written over.
 */
final class Abstracts
{
    /** The frontmatter key that marks an abstract page, and the page's last segment */
    public const KEY = 'abstract';

    /** A record's abstract shorter than this is a stub (CrossRef holds truncated ones): the text's is looked for instead */
    public const MIN_RECORD_WORDS = 40;

    /** The longest abstract kept, as Library::cleanCsl() keeps `csl.abstract` */
    private const MAX_CHARS = 10000;

    public function __construct(
        private readonly StorageInterface $storage,
        // Each write audited `page.create` / `page.save` with `with: abstract` (null: tests that do not look)
        private readonly ?AuditLog $audit = null,
    ) {
    }

    public static function pathFor(string $source): string
    {
        return $source . ':' . self::KEY;
    }

    /** @param array<string, mixed> $frontmatter */
    public static function isAbstract(array $frontmatter): bool
    {
        return \is_array($frontmatter[self::KEY] ?? null);
    }

    /**
     * The abstract a source has: its record's, else its text's when the text marks it plainly
     *
     * @return ?array{text: string, from: string}
     */
    public static function propose(PageRecord $source): ?array
    {
        $csl = \is_array($source->frontmatter['csl'] ?? null) ? $source->frontmatter['csl'] : [];
        $record = \is_string($csl['abstract'] ?? null) ? trim($csl['abstract']) : '';
        if ($record !== '' && preg_match_all('/[\p{L}\p{N}]+/u', $record) >= self::MIN_RECORD_WORDS) {
            $found = ['text' => $record, 'from' => 'record'];
        } else {
            $text = SourceAbstract::fromText($source->body);
            if ($text === null) {
                return null;
            }
            $found = ['text' => $text, 'from' => 'text'];
        }
        $found['text'] = trim(mb_substr($found['text'], 0, self::MAX_CHARS));

        return Library::holdsPatientData($found['text']) ? null : $found;
    }

    /**
     * The abstract page of $source written: made, or a new revision when its text changed
     *
     * @param ?array{text: string, from: string} $found propose()'s, when the caller has it
     * @param ?string $onBehalfOf the person, when $actor is a pseudouser (D41)
     *
     * @return ?array{path: string, rev: int, created: bool, from: string} null: no abstract, nothing changed, or a page there that is not one
     */
    public function write(PageRecord $source, string $actor, ?array $found = null, ?string $onBehalfOf = null): ?array
    {
        $found ??= self::propose($source);
        if ($found === null) {
            return null;
        }
        $path = self::pathFor($source->path);
        $title = \is_string($source->frontmatter['title'] ?? null) && $source->frontmatter['title'] !== '' ? $source->frontmatter['title'] : substr($source->path, (int) strrpos($source->path, ':') + 1);
        $frontmatter = ['title' => 'Abstract: ' . $title, 'visibility' => 'private', self::KEY => ['of' => $source->pid, 'from' => $found['from'], 'at' => date('c')]];
        $body = $found['text'] . "\n";
        $note = 'library: abstract (' . $found['from'] . ')';
        try {
            $there = $this->storage->read($path);
        } catch (PageNotFoundException) {
            $made = $this->storage->create($path, $frontmatter, $body, $actor, $note, exclusive: true);
            $this->audit?->record('page.create', $actor, null, $made->pid, $made->path, $made->rev, extra: ['with' => 'abstract', 'from' => $found['from']] + ($onBehalfOf !== null ? ['on_behalf_of' => $onBehalfOf] : []));

            return ['path' => $made->path, 'rev' => $made->rev, 'created' => true, 'from' => $found['from']];
        }
        if (!self::isAbstract($there->frontmatter) || $there->status === 'signed' || trim($there->body) === trim($body)) {
            return null;
        }
        $saved = $this->storage->save($path, $frontmatter, $body, $there->rev, $actor, $note);
        $this->audit?->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['with' => 'abstract', 'from' => $found['from']] + ($onBehalfOf !== null ? ['on_behalf_of' => $onBehalfOf] : []));

        return ['path' => $saved->path, 'rev' => $saved->rev, 'created' => false, 'from' => $found['from']];
    }

    /** Whether $source has its abstract page (one that is its, with text) */
    public function has(PageRecord $source): bool
    {
        return $this->of($source) !== null;
    }

    /**
     * The abstract the analyses read: the page's text, as a writer may have corrected it
     *
     * @return ?array{text: string, from: string, path: string}
     */
    public function of(PageRecord $source): ?array
    {
        try {
            $page = $this->storage->read(self::pathFor($source->path));
        } catch (PageNotFoundException) {
            return null;
        }
        $marker = $page->frontmatter[self::KEY] ?? null;
        if (!\is_array($marker) || ($marker['of'] ?? null) !== $source->pid || trim($page->body) === '') {
            return null;
        }

        return ['text' => trim($page->body), 'from' => \is_string($marker['from'] ?? null) ? $marker['from'] : 'text', 'path' => $page->path];
    }
}
