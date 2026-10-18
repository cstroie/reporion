<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\StorageInterface;

/**
 * The proposed reserved prompts (docs/prompts/*.txt, 2026-10-15) made into
 * prompt pages `ai:profiles:{profile}:special:{id}`, when the owner asks for
 * them at install (the Configure step's tick box). A page that is there
 * already is left as it is. The pages are ordinary private pages, edited
 * like any other; nothing reads the files afterwards. Turning them on sends
 * nothing: the assistant stays off until a server is set up (D15).
 */
final class DefaultPrompts
{
    /** File name (without .txt) → reserved prompt id; their titles */
    public const PROMPTS = ['summary' => 'Summary', 'tags' => 'Tags', 'tag-review' => 'Tag review', 'recall-expand' => 'Recall: expand', 'recall-missing' => 'Recall: what is missing'];

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
        $created = [];
        foreach (self::PROMPTS as $id => $title) {
            $file = $this->dir . '/' . $id . '.txt';
            $body = is_file($file) ? trim((string) file_get_contents($file)) : '';
            if ($body === '') {
                continue;
            }
            $path = Actions::NS . ':' . $profile . ':' . Actions::SPECIAL_NS . ':' . $id;
            if ($this->exists($path)) {
                continue;
            }
            $this->storage->create($path, ['title' => $title, 'visibility' => 'private'], $body . "\n", $actor);
            $created[] = $path;
        }

        return $created;
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
