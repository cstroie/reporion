<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Index\PageSnapshot;

/**
 * Test double standing in for Index\Sqlite. Records every write call so
 * Storage tests can assert FlatFile invoked the indexer as the last durable
 * step of each write; the read methods are stubs (empty/null) since no
 * Storage test exercises them — Index\Sqlite's own tests cover those.
 */
final class RecordingIndex implements IndexInterface
{
    /** @var list<PageSnapshot> */
    public array $indexed = [];

    /** @var list<string> */
    public array $removed = [];

    public function index(PageSnapshot $snapshot): void
    {
        $this->indexed[] = $snapshot;
    }

    public function remove(string $pid): void
    {
        $this->removed[] = $pid;
    }

    public function findByPath(string $path, ?User $principal): ?array
    {
        return null;
    }

    public function listNamespace(string $ns, ?User $principal): array
    {
        return [];
    }

    public function listSubnamespaces(string $ns, ?User $principal): array
    {
        return [];
    }

    public function listSitemap(?User $principal): array
    {
        return [];
    }

    public function search(string $term, ?User $principal): array
    {
        return [];
    }
}
