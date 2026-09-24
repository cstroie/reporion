<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Import;

use PHPUnit\Framework\TestCase;
use Reporion\Import\PageImportMap;
use RuntimeException;

final class PageImportMapTest extends TestCase
{
    public function testNamespaceForMappedDirectory(): void
    {
        $map = new PageImportMap([
            'namespace_map' => ['documents' => 'docs', 'bookmarks' => 'bookmarks'],
            'skip_dirs' => ['wiki', 'playground'],
            'default_visibility' => 'private',
            'skip_paths' => [],
        ]);

        self::assertSame('docs', $map->namespaceFor('documents'));
        self::assertSame('bookmarks', $map->namespaceFor('bookmarks'));
    }

    public function testNamespaceForUnmappedDirectoryUsesDirectoryNameByDefault(): void
    {
        $map = new PageImportMap([
            'namespace_map' => ['documents' => 'docs'],
            'skip_dirs' => [],
            'default_visibility' => 'private',
            'skip_paths' => [],
        ]);

        self::assertSame('radiology', $map->namespaceFor('radiology'));
        self::assertSame('code', $map->namespaceFor('code'));
    }

    public function testNamespaceForSkippedDirectoryReturnsNull(): void
    {
        $map = new PageImportMap([
            'namespace_map' => [],
            'skip_dirs' => ['wiki', 'playground', 'reports'],
            'default_visibility' => 'private',
            'skip_paths' => [],
        ]);

        self::assertNull($map->namespaceFor('wiki'));
        self::assertNull($map->namespaceFor('playground'));
        self::assertNull($map->namespaceFor('reports'));
    }

    public function testIsSkippedPathReturnsTrueForConfiguredPaths(): void
    {
        $map = new PageImportMap([
            'namespace_map' => [],
            'skip_dirs' => [],
            'default_visibility' => 'private',
            'skip_paths' => ['bookmarks/passwords', 'radiology/private'],
        ]);

        self::assertTrue($map->isSkippedPath('bookmarks/passwords'));
        self::assertTrue($map->isSkippedPath('bookmarks/passwords/nested.txt'));
        self::assertTrue($map->isSkippedPath('radiology/private/file.txt'));
    }

    public function testIsSkippedPathReturnsFalseForNonConfiguredPaths(): void
    {
        $map = new PageImportMap([
            'namespace_map' => [],
            'skip_dirs' => [],
            'default_visibility' => 'private',
            'skip_paths' => ['bookmarks/passwords'],
        ]);

        self::assertFalse($map->isSkippedPath('bookmarks/public/file.txt'));
        self::assertFalse($map->isSkippedPath('radiology/teaching.txt'));
    }

    public function testDefaultVisibilityFromConfig(): void
    {
        $mapPrivate = new PageImportMap([
            'namespace_map' => [],
            'skip_dirs' => [],
            'default_visibility' => 'private',
            'skip_paths' => [],
        ]);
        self::assertSame('private', $mapPrivate->defaultVisibility());

        $mapPublic = new PageImportMap([
            'namespace_map' => [],
            'skip_dirs' => [],
            'default_visibility' => 'public',
            'skip_paths' => [],
        ]);
        self::assertSame('public', $mapPublic->defaultVisibility());
    }

    public function testValidationRequiresNamespaceMap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('namespace_map');

        new PageImportMap([
            'skip_dirs' => [],
            'default_visibility' => 'private',
            'skip_paths' => [],
        ]);
    }

    public function testValidationRequiresSkipDirs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('skip_dirs');

        new PageImportMap([
            'namespace_map' => [],
            'default_visibility' => 'private',
            'skip_paths' => [],
        ]);
    }

    public function testValidationRequiresDefaultVisibility(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('default_visibility');

        new PageImportMap([
            'namespace_map' => [],
            'skip_dirs' => [],
            'skip_paths' => [],
        ]);
    }

    public function testValidationRequiresValidVisibilityValue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private, unlisted, public');

        new PageImportMap([
            'namespace_map' => [],
            'skip_dirs' => [],
            'default_visibility' => 'invalid',
            'skip_paths' => [],
        ]);
    }

    public function testValidationRequiresSkipPaths(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('skip_paths');

        new PageImportMap([
            'namespace_map' => [],
            'skip_dirs' => [],
            'default_visibility' => 'private',
        ]);
    }
}
