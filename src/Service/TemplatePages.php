<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\PageKind;
use Reporion\Support\Templates;
use RuntimeException;

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
        if ($template === '' || !\in_array(PageKind::of($template), [PageKind::TEMPLATE, PageKind::SNIPPET], true)) {
            return null;
        }
        $key = ($principal?->username ?? '') . "\0" . $template;
        if (!\array_key_exists($key, $this->read)) {
            $this->read[$key] = null;
            // A moved template: a signed report keeps the old path in its `template`, which
            // is now a redirect stub — followed once, so its checklist and reference still show
            $path = $this->index->findByPath($template, $principal) !== null ? $template : $this->storage->redirectTarget($template);
            if ($path !== null && $path !== $template && PageKind::of($path) !== PageKind::TEMPLATE) {
                $path = null;
            }
            if ($path !== null && $this->index->findByPath($path, $principal) !== null) {
                try {
                    $this->read[$key] = $this->storage->read($path);
                } catch (RuntimeException) {
                    // Unreadable: no template, as for one the caller cannot reach
                }
            }
        }

        return $this->read[$key];
    }
}
