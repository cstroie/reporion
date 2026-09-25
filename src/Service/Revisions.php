<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Exception\PageNotFoundException;
use Reporion\Schema\Loader;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Canonical;
use Reporion\Support\DocumentFormat;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Read-only views of a page's stored revisions — `/{path}@{rev}` and the
 * `/r/{pid}/{rev}` verification permalink exports cite (D3). Nothing here
 * writes: history is append-only (invariant 3) and a revision is only ever
 * read from its own rev/NNNN.md.gz bytes.
 */
final class Revisions
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly Loader $schemas,
    ) {
    }

    /**
     * Revision $rev of $current's page as a record of its own: that
     * revision's frontmatter and body, the page's current identity and
     * visibility (access is decided by the page as it is now), the revlog up
     * to $rev, and a status of `signed` only when $rev itself was signed.
     *
     * @throws PageNotFoundException when $rev is not a revision of the page
     */
    public function record(PageRecord $current, int $rev): PageRecord
    {
        if ($rev < 1 || $rev > $current->rev) {
            throw new PageNotFoundException();
        }
        if ($rev === $current->rev) {
            return $current;
        }

        try {
            [$frontmatter, $body] = DocumentFormat::parse($this->storage->readRevision($current->path, $rev));
        } catch (RuntimeException | ParseException) {
            throw new PageNotFoundException();
        }

        $revlog = array_values(array_filter(
            $current->revlog,
            static fn (array $entry): bool => (int) $entry['n'] <= $rev
        ));
        $status = match (true) {
            $this->signature($current, $rev) !== null => 'signed',
            $current->status === 'archived' => 'archived',
            default => 'draft',
        };

        return new PageRecord(
            $current->pid,
            $current->path,
            $rev,
            $status,
            $current->visibility,
            $frontmatter,
            $body,
            $revlog,
            $current->meta,
        );
    }

    /**
     * The signature record for $rev, if that revision was signed, with
     * `matches`: whether the digest recomputed from the stored revision
     * bytes equals the recorded one — computed the way Storage\FlatFile::sign()
     * computed it, with today's schema field order.
     *
     * @return array{by: string, ts: string, alg: string, digest: string, parafa: ?string, matches: bool}|null
     */
    public function signature(PageRecord $current, int $rev): ?array
    {
        $found = null;
        foreach ((array) ($current->meta['signatures'] ?? []) as $signature) {
            if (\is_array($signature) && (int) ($signature['rev'] ?? 0) === $rev) {
                $found = $signature;
            }
        }
        if ($found === null) {
            return null;
        }

        $matches = false;
        try {
            [$frontmatter, $body] = DocumentFormat::parse($this->storage->readRevision($current->path, $rev));
            $modalities = array_values(array_filter((array) ($frontmatter['modality'] ?? []), \is_string(...)));
            $digest = hash('sha256', Canonical::bytes($frontmatter, $body, $this->schemas->fieldsFor($modalities)));
            $matches = hash_equals((string) ($found['digest'] ?? ''), $digest);
        } catch (RuntimeException | ParseException | PageNotFoundException) {
            $matches = false;
        }

        return [
            'by' => (string) ($found['by'] ?? ''),
            'ts' => (string) ($found['ts'] ?? ''),
            'alg' => (string) ($found['alg'] ?? 'sha256'),
            'digest' => (string) ($found['digest'] ?? ''),
            'parafa' => isset($found['parafa']) && \is_string($found['parafa']) ? $found['parafa'] : null,
            'matches' => $matches,
        ];
    }
}
