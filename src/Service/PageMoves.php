<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Audit\AuditLog;
use Reporion\Http\Request;
use Reporion\Storage\FlatFile;
use Reporion\Storage\PageRecord;
use RuntimeException;
use Throwable;

/**
 * Moving a page, and the link fixups that come with it (decided
 * 2026-09-26): the redirect stub keeps every old link working, and links in
 * *unsigned* pages are also rewritten to the new path — each as an ordinary
 * new revision attributed to whoever moved the page. A signed report is
 * never edited for this: that would turn it back into a draft (D3), and its
 * links keep resolving through the stub.
 *
 * Links are found by scanning page bodies, not the index (which records no
 * body links): markdown targets written as `ns:page`, `/ns:page`, or the
 * importer's `ns/page`, optionally with a `#fragment`, keep their form.
 */
final class PageMoves
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Moves, fixes links, and audits all of it (page.move, plus a page.save
     * per fixed page); $request is null from the CLI.
     *
     * @return array{moved: PageRecord, fixed: list<PageRecord>, skippedSigned: int}
     */
    public function move(string $from, string $to, string $actor, ?Request $request = null): array
    {
        $moved = $this->storage->move($from, $to, $actor);
        $fixed = [];
        $skippedSigned = 0;

        foreach ($this->storage->allPaths() as $path) {
            if ($path === $moved->path) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            $body = self::rewrite($page->body, $from, $moved->path);
            if ($body === $page->body) {
                continue;
            }
            if ($page->status === 'signed') {
                ++$skippedSigned;
                continue;
            }
            try {
                $fixed[] = $this->storage->save($path, $page->frontmatter, $body, $page->rev, $actor, 'link to moved page');
            } catch (RuntimeException) {
                // Someone saved it in between: its link still resolves through the stub
            }
        }

        $this->audit->record('page.move', $actor, $request, $moved->pid, $moved->path, $moved->rev, extra: [
            'from_hash' => AuditLog::pathHash($from),
            'links_fixed' => \count($fixed),
            'links_left_signed' => $skippedSigned,
        ]);
        foreach ($fixed as $page) {
            $this->audit->record('page.save', $actor, $request, $page->pid, $page->path, $page->rev, extra: ['reason' => 'link-fixup']);
        }

        return ['moved' => $moved, 'fixed' => $fixed, 'skippedSigned' => $skippedSigned];
    }

    /** $body with every markdown link to $from pointed at $to, in the form it was written */
    public static function rewrite(string $body, string $from, string $to): string
    {
        $forms = [
            $from => $to,
            '/' . $from => '/' . $to,
            str_replace(':', '/', $from) => str_replace(':', '/', $to),
        ];

        return (string) preg_replace_callback(
            '/\]\(\s*([^)\s#]+)(#[^)\s]*)?(\s+"[^"]*")?\s*\)/',
            static function (array $m) use ($forms): string {
                if (!isset($forms[$m[1]])) {
                    return $m[0];
                }

                return '](' . $forms[$m[1]] . ($m[2] ?? '') . ($m[3] ?? '') . ')';
            },
            $body
        );
    }
}
