<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Ulid;

/**
 * A page's comments (D52, 2026-10-19, Notion's): a second opinion, a
 * teaching note, a question to a colleague, kept beside the page
 * (`comments.json`, Storage), never in its text: not a revision, never
 * signed, never in print, PDF, ODT or the API's `meta`. Whoever may read
 * the page may comment, resolve and reopen; a comment is deleted by its
 * author or an owner. Audited by pid and comment id, never the words
 * (invariant 8: a comment may name the patient).
 */
final class Comments
{
    public const MAX_TEXT = 4_000;

    public const MAX_QUOTE = 300;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ?AuditLog $audit = null,
    ) {
    }

    /**
     * @return list<array{id: string, by: string, ts: string, text: string, quote: string, rev: int, resolved: ?array{by: string, ts: string}}>
     */
    public function of(string $path): array
    {
        return $this->storage->comments($path);
    }

    /**
     * @throws InvalidArgumentException an empty or too long text
     *
     * @return array<string, mixed> the comment
     */
    public function add(string $path, string $pid, int $rev, User $by, string $text, string $quote, ?Request $request = null): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            throw new InvalidArgumentException('A comment is 1 to ' . self::MAX_TEXT . ' characters');
        }
        $quote = trim((string) preg_replace('/\s+/u', ' ', $quote));
        $comment = [
            'id' => Ulid::generate(),
            'by' => $by->username,
            'ts' => date('c'),
            'text' => $text,
            'quote' => mb_strlen($quote) > self::MAX_QUOTE ? rtrim(mb_substr($quote, 0, self::MAX_QUOTE)) . '…' : $quote,
            'rev' => $rev,
            'resolved' => null,
        ];
        $this->storage->updateComments($path, static fn (array $all): array => [...$all, $comment]);
        $this->audit?->record('comment.add', $by->username, $request, $pid, $path, $rev, extra: ['comment' => $comment['id']]);

        return $comment;
    }

    /** Resolved, or open again; false when there is no such comment */
    public function resolve(string $path, string $pid, string $id, User $by, bool $resolved, ?Request $request = null): bool
    {
        $found = false;
        $this->storage->updateComments($path, static function (array $all) use ($id, $by, $resolved, &$found): array {
            foreach ($all as $i => $comment) {
                if ($comment['id'] === $id) {
                    $found = true;
                    $all[$i]['resolved'] = $resolved ? ['by' => $by->username, 'ts' => date('c')] : null;
                }
            }

            return $all;
        });
        if ($found) {
            $this->audit?->record($resolved ? 'comment.resolve' : 'comment.reopen', $by->username, $request, $pid, $path, extra: ['comment' => $id]);
        }

        return $found;
    }

    /**
     * @return 'deleted'|'missing'|'forbidden' deleted, no such comment, or not the author's nor an owner's to delete
     */
    public function delete(string $path, string $pid, string $id, User $by, ?Request $request = null): string
    {
        $outcome = 'missing';
        $this->storage->updateComments($path, static function (array $all) use ($id, $by, &$outcome): array {
            foreach ($all as $i => $comment) {
                if ($comment['id'] !== $id) {
                    continue;
                }
                if ($comment['by'] !== $by->username && !$by->isOwner) {
                    $outcome = 'forbidden';

                    return $all;
                }
                $outcome = 'deleted';
                unset($all[$i]);
            }

            return array_values($all);
        });
        if ($outcome === 'deleted') {
            $this->audit?->record('comment.delete', $by->username, $request, $pid, $path, extra: ['comment' => $id]);
        }

        return $outcome;
    }
}
