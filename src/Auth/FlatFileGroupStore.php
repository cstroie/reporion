<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use DateTimeImmutable;
use FilesystemIterator;
use Reporion\Storage\AtomicWriter;
use RuntimeException;

/**
 * `data/groups/{name}.json`, one file per group (D47) — the same single
 * atomic overwrite as FlatFileUserStore, and for the same reason no journal:
 * one group is one file.
 */
final class FlatFileGroupStore implements GroupStoreInterface
{
    public function __construct(
        private readonly string $dataRoot,
    ) {
    }

    public function find(string $name): ?Group
    {
        if (!Group::isValidName($name)) {
            return null;
        }
        $file = $this->path($name);

        return is_file($file) ? $this->decode($file) : null;
    }

    public function all(): array
    {
        $root = $this->dataRoot . '/groups';
        if (!is_dir($root)) {
            return [];
        }

        $names = [];
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $item) {
            $file = $item->getFilename();
            if ($item->isFile() && str_ends_with($file, '.json') && Group::isValidName(substr($file, 0, -5))) {
                $names[] = substr($file, 0, -5);
            }
        }
        sort($names);

        return array_map(fn (string $n): Group => $this->decode($this->path($n)), $names);
    }

    public function save(Group $group): Group
    {
        $root = $this->dataRoot . '/groups';
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create groups directory');
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
        $saved = new Group(
            $group->name,
            trim($group->displayName),
            $group->grants,
            $group->radiologist,
            $group->createdAt !== '' ? $group->createdAt : $now,
            $now,
        );

        $json = json_encode([
            'name' => $saved->name,
            'display_name' => $saved->displayName,
            'grants' => array_map(
                static fn (Grant $g): array => ['namespace' => $g->namespace, 'role' => $g->role->value],
                $saved->grants
            ),
            'radiologist' => $saved->radiologist,
            'created' => $saved->createdAt,
            'updated' => $saved->updatedAt,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        AtomicWriter::put($this->path($saved->name), $json . "\n");

        return $saved;
    }

    public function delete(string $name): void
    {
        Group::assertValidName($name);
        $file = $this->path($name);
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException('Cannot delete group');
        }
    }

    private function decode(string $file): Group
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Group record unreadable');
        }
        $d = json_decode($raw, true);
        if (!\is_array($d) || !\is_string($d['name'] ?? null)) {
            throw new RuntimeException('Corrupt group record: ' . basename($file));
        }

        $grants = [];
        foreach ((array) ($d['grants'] ?? []) as $g) {
            $role = \is_array($g) ? GrantRole::tryFrom((string) ($g['role'] ?? '')) : null;
            if ($role === null || !\is_string($g['namespace'] ?? null)) {
                throw new RuntimeException('Corrupt group record: ' . basename($file));
            }
            $grants[] = new Grant($g['namespace'], $role);
        }

        return new Group(
            $d['name'],
            \is_string($d['display_name'] ?? null) ? $d['display_name'] : '',
            $grants,
            ($d['radiologist'] ?? false) === true,
            \is_string($d['created'] ?? null) ? $d['created'] : '',
            \is_string($d['updated'] ?? null) ? $d['updated'] : '',
        );
    }

    private function path(string $name): string
    {
        return $this->dataRoot . '/groups/' . $name . '.json';
    }
}
