<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use Reporion\Index\IndexInterface;
use Reporion\Index\PageSnapshot;

/**
 * Test double standing in for Index\Sqlite (not built yet — build order
 * step 2). Records every call so Storage tests can assert FlatFile invoked
 * the indexer as the last durable step of each write.
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
}
