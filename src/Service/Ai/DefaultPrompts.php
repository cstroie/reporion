<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\StorageInterface;

/**
 * The proposed reserved prompts (docs/prompts/*.txt, 2026-10-15) made into
 * prompt pages `ai:profiles:{profile}:special:{id}`, when the owner asks for
 * them at install (the Configure step's tick box); since 2026-10-19 the
 * proposed rail actions too (docs/prompts/actions/*.txt, among them *Ask*,
 * whose `{prompt}` gives the rail its question box) as `ai:profiles:{profile}:{id}`,
 * and the profile's own page with their table (actions/table.md) when the
 * profile has none: an existing table is never rewritten, its rows are the
 * owner's to add. A page that is there already is left as it is. The pages are ordinary private pages, edited
 * like any other; nothing reads the files afterwards. Turning them on sends
 * nothing: the assistant stays off until a server is set up (D15).
 */
final class DefaultPrompts
{
    /** File name (without .txt) → reserved prompt id; their titles */
    public const PROMPTS = ['summary' => 'Summary', 'tags' => 'Tags', 'tags-review' => 'Tags review', 'recall-expand' => 'Recall: expand', 'recall-missing' => 'Recall: what is missing'];

    /** File name (without .txt) under actions/ → rail action id; their titles */
    public const ACTIONS = ['ask' => 'Ask about this report', 'grammar' => 'Grammar', 'shorten' => 'Shorten', 'patient' => 'For the patient', 'english' => 'English'];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $dir,
    ) {
    }

    /**
     * @return list<string> the paths of the pages created
     */
    public function install(string $profile, string $actor): array
    {
        $ns = Actions::NS . ':' . $profile;
        $created = [];
        foreach (self::PROMPTS as $id => $title) {
            $created[] = $this->page($ns . ':' . Actions::SPECIAL_NS . ':' . $id, $title, $this->dir . '/' . $id . '.txt', $actor);
        }
        foreach (self::ACTIONS as $id => $title) {
            $created[] = $this->page($ns . ':' . $id, $title, $this->dir . '/actions/' . $id . '.txt', $actor);
        }
        $created[] = $this->page($ns, 'Prompts: ' . $profile, $this->dir . '/actions/table.md', $actor);

        return array_values(array_filter($created, static fn (?string $path): bool => $path !== null));
    }

    /** One page from one file, unless the file is empty or the page is there; its path when made */
    private function page(string $path, string $title, string $file, string $actor): ?string
    {
        $body = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ($body === '' || $this->exists($path)) {
            return null;
        }
        $this->storage->create($path, ['title' => $title, 'visibility' => 'private'], $body . "\n", $actor);

        return $path;
    }

    private function exists(string $path): bool
    {
        try {
            $this->storage->read($path);

            return true;
        } catch (PageNotFoundException) {
            return false;
        }
    }
}
