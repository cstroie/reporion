<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Templates;
use Throwable;

/**
 * A report's exam templates as Checklists and References read them: a page
 * under `templates:` the caller can reach (Index::findByPath(), invariant 6),
 * else null. Each is looked up and read once per caller for this object's
 * life — one request — however many exams name it and however many services
 * ask: the editor asks for every exam's template twice (checklist, reference).
 */
final class TemplatePages
{
    /** @var array<string, ?PageRecord> by caller and template path */
    private array $read = [];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
    ) {
    }

    public function read(string $template, ?User $principal): ?PageRecord
    {
        if ($template === '' || !str_starts_with($template, Templates::NS . ':')) {
            return null;
        }
        $key = ($principal?->username ?? '') . "\0" . $template;
        if (!\array_key_exists($key, $this->read)) {
            $this->read[$key] = null;
            if ($this->index->findByPath($template, $principal) !== null) {
                try {
                    $this->read[$key] = $this->storage->read($template);
                } catch (Throwable) {
                    // Unreadable: no template, as for one the caller cannot reach
                }
            }
        }

        return $this->read[$key];
    }
}
