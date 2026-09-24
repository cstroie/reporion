<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Import;

/**
 * Maps DokuWiki source relative paths to assigned Reporion target paths + file hash.
 * Built during import:convert, persisted as data/import/<batch>/pathmap.json.
 * Used by: import:meta (priors resolution), import:commit (imported_from), import:rollback.
 */
final class PathMap
{
    /**
     * @var array<string, array{target_path: string, sha256: string}>
     */
    private array $map = [];

    /**
     * Register a source path → target path + hash.
     */
    public function record(string $sourcePath, string $targetPath, string $sha256): void
    {
        $this->map[$sourcePath] = [
            'target_path' => $targetPath,
            'sha256' => $sha256,
        ];
    }

    /**
     * Resolve a source path (e.g. from a priors macro) to its target path.
     * Returns null if not yet mapped.
     */
    public function resolveTarget(string $sourcePath): ?string
    {
        return $this->map[$sourcePath]['target_path'] ?? null;
    }

    /**
     * Get the sha256 of a source file.
     */
    public function hash(string $sourcePath): ?string
    {
        return $this->map[$sourcePath]['sha256'] ?? null;
    }

    /**
     * Export as JSON-serializable array.
     *
     * @return array<string, array{target_path: string, sha256: string}>
     */
    public function toArray(): array
    {
        return $this->map;
    }

    /**
     * Load from a JSON file.
     *
     * @param array<string, array{target_path: string, sha256: string}> $data
     */
    public static function fromArray(array $data): self
    {
        $map = new self();
        $map->map = $data;

        return $map;
    }
}
