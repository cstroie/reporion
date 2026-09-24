<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Import;

use RuntimeException;

/**
 * Configuration for the generic (non-report) page importer.
 * Loaded from data/page-import-map.json, instance-specific.
 *
 * Maps DokuWiki top-level directories to Reporion namespaces, with skipping rules
 * and default visibility for all imported content.
 */
final class PageImportMap
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
        $this->validate();
    }

    /**
     * Resolve a top-level directory to a Reporion namespace, or null if it should be skipped.
     * Directories listed in skip_dirs or not in namespace_map (and no default mapping)
     * return null (skip the entire directory).
     */
    public function namespaceFor(string $topDir): ?string
    {
        if (\in_array($topDir, $this->config['skip_dirs'] ?? [], true)) {
            return null;
        }

        $mapping = $this->config['namespace_map'] ?? [];
        if (isset($mapping[$topDir])) {
            return $mapping[$topDir];
        }

        // Use the top directory name as the namespace by default
        // (unless it's explicitly marked to skip)
        return $topDir;
    }

    /**
     * Check if a relative path should be skipped (per skip_paths config).
     */
    public function isSkippedPath(string $relpath): bool
    {
        $skipPaths = $this->config['skip_paths'] ?? [];
        foreach ($skipPaths as $skipPath) {
            if (str_starts_with($relpath, $skipPath . '/') || $relpath === $skipPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default visibility for all imported pages.
     */
    public function defaultVisibility(): string
    {
        return $this->config['default_visibility'] ?? 'private';
    }

    private function validate(): void
    {
        if (!\is_array($this->config)) {
            throw new RuntimeException('Page import map must be an object');
        }

        if (!isset($this->config['namespace_map'])) {
            throw new RuntimeException('Page import map missing "namespace_map" key');
        }
        if (!\is_array($this->config['namespace_map'])) {
            throw new RuntimeException('Page import map "namespace_map" must be an object');
        }

        if (!isset($this->config['skip_dirs'])) {
            throw new RuntimeException('Page import map missing "skip_dirs" key');
        }
        if (!\is_array($this->config['skip_dirs'])) {
            throw new RuntimeException('Page import map "skip_dirs" must be an array');
        }

        if (!isset($this->config['default_visibility'])) {
            throw new RuntimeException('Page import map missing "default_visibility" key');
        }
        if (!\in_array($this->config['default_visibility'], ['private', 'unlisted', 'public'], true)) {
            throw new RuntimeException('Page import map "default_visibility" must be one of: private, unlisted, public');
        }

        if (!isset($this->config['skip_paths'])) {
            throw new RuntimeException('Page import map missing "skip_paths" key');
        }
        if (!\is_array($this->config['skip_paths'])) {
            throw new RuntimeException('Page import map "skip_paths" must be an array');
        }
    }
}
