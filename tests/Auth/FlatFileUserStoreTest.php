<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Auth;

use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Exception\UserAlreadyExistsException;
use Reporion\Exception\UserNotFoundException;
use RuntimeException;

final class FlatFileUserStoreTest extends TestCase
{
    private string $dataRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-auth-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    public function testFindReturnsNullForAnUnknownUsername(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);

        self::assertNull($store->find('nobody'));
    }

    public function testCreateWritesAFileAndFindReadsItBack(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);

        $created = $store->create('mihai', '$argon2id$fake', false, [
            new Grant('reports:mri', GrantRole::Editor),
        ]);

        self::assertFileExists($this->dataRoot . '/users/mihai.json');
        self::assertTrue($created->active);
        self::assertSame($created->createdAt, $created->updatedAt);

        $found = $store->find('mihai');
        self::assertNotNull($found);
        self::assertSame('mihai', $found->username);
        self::assertSame('$argon2id$fake', $found->passwordHash);
        self::assertFalse($found->isOwner);
        self::assertTrue($found->active);
        self::assertCount(1, $found->grants);
        self::assertSame('reports:mri', $found->grants[0]->namespace);
        self::assertSame(GrantRole::Editor, $found->grants[0]->role);
        self::assertSame($created->createdAt, $found->createdAt);
    }

    public function testCreateRejectsADuplicateUsername(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $store->create('mihai', '$argon2id$fake', false);

        $this->expectException(UserAlreadyExistsException::class);
        $store->create('mihai', '$argon2id$other', false);
    }

    public function testCreateRejectsAnUnsafeUsername(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);

        $this->expectException(InvalidArgumentException::class);
        $store->create('../../etc/passwd', '$argon2id$fake', false);
    }

    public function testSaveOverwritesAnExistingRecordAndBumpsUpdatedAt(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $created = $store->create('mihai', '$argon2id$fake', false);

        $promoted = $store->save(new User(
            $created->username,
            $created->passwordHash,
            true,
            [],
            $created->active,
            $created->createdAt,
            // A caller-supplied updatedAt must never reach disk — save()
            // recomputes it, so an obviously-wrong value here proves that.
            '1970-01-01T00:00:00+00:00',
        ));

        self::assertTrue($promoted->isOwner);
        self::assertSame($created->createdAt, $promoted->createdAt);
        self::assertNotSame('1970-01-01T00:00:00+00:00', $promoted->updatedAt);

        $reloaded = $store->find('mihai');
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isOwner);
        self::assertSame($created->createdAt, $reloaded->createdAt);
    }

    public function testSaveOnAnUnknownUserThrows(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);

        $this->expectException(UserNotFoundException::class);
        $store->save(new User('ghost', 'x', false, [], true, 'now', 'now'));
    }

    public function testAllListsEveryAccountInSortedOrder(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $store->create('zoe', 'x', false);
        $store->create('anca', 'x', false);
        $store->create('mihai', 'x', true);

        $usernames = array_map(static fn ($u) => $u->username, iterator_to_array($store->all()));

        self::assertSame(['anca', 'mihai', 'zoe'], $usernames);
    }

    public function testAllOnAMissingUsersDirectoryYieldsNothing(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);

        self::assertSame([], iterator_to_array($store->all()));
    }

    public function testGrantCoversItsOwnNamespaceAndEverythingNestedUnderIt(): void
    {
        $grant = new Grant('reports:mri', GrantRole::Editor);

        self::assertTrue($grant->covers('reports:mri'));
        self::assertTrue($grant->covers('reports:mri:mioveni'));
        self::assertFalse($grant->covers('reports:ct'));
        // "reports:mrix" must not match a "reports:mri" grant by string
        // prefix alone — only a real namespace boundary (":") counts.
        self::assertFalse($grant->covers('reports:mrix'));
    }

    public function testOwnerCanWriteAnyNamespaceRegardlessOfGrants(): void
    {
        $owner = new User('root', 'x', true, [], true, 'now', 'now');

        self::assertTrue($owner->canWrite('reports:mri'));
        self::assertTrue($owner->canWrite('anything:at:all'));
    }

    public function testEditorGrantAllowsWriteViewerGrantDoesNot(): void
    {
        $editor = new User('e', 'x', false, [new Grant('reports:mri', GrantRole::Editor)], true, 'now', 'now');
        $viewer = new User('v', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)], true, 'now', 'now');

        self::assertTrue($editor->canWrite('reports:mri:mioveni'));
        self::assertTrue($editor->canRead('reports:mri:mioveni'));
        self::assertFalse($viewer->canWrite('reports:mri:mioveni'));
        self::assertTrue($viewer->canRead('reports:mri:mioveni'));
    }

    public function testNoGrantMeansNoAccessOutsideThatNamespace(): void
    {
        $editor = new User('e', 'x', false, [new Grant('reports:mri', GrantRole::Editor)], true, 'now', 'now');

        self::assertFalse($editor->canRead('reports:ct'));
        self::assertFalse($editor->canWrite('reports:ct'));
    }

    public function testHasAnyWriteAccessIsTrueForOwnerAndAnyEditorGrantFalseOtherwise(): void
    {
        $owner = new User('root', 'x', true, [], true, 'now', 'now');
        $editor = new User('e', 'x', false, [new Grant('reports:mri', GrantRole::Editor)], true, 'now', 'now');
        $viewer = new User('v', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)], true, 'now', 'now');
        $noGrants = new User('n', 'x', false, [], true, 'now', 'now');

        self::assertTrue($owner->hasAnyWriteAccess());
        self::assertTrue($editor->hasAnyWriteAccess());
        self::assertFalse($viewer->hasAnyWriteAccess(), 'a viewer grant is read-only everywhere, not a coarse write pass');
        self::assertFalse($noGrants->hasAnyWriteAccess());
    }

    public function testUserRejectsAGrantsListContainingSomethingOtherThanAGrant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // @phpstan-ignore-next-line intentionally violating the list<Grant> contract
        new User('u', 'x', false, ['reports:mri'], true, 'now', 'now');
    }

    public function testGrantRejectsAnInvalidNamespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Grant('', GrantRole::Editor);
    }

    public function testGrantRejectsAnEmptyNamespaceSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Grant('reports::mri', GrantRole::Editor);
    }

    public function testCorruptUserRecordThrowsRatherThanReturningGarbage(): void
    {
        mkdir($this->dataRoot . '/users', 0775, true);
        file_put_contents($this->dataRoot . '/users/broken.json', '{not json');

        $store = new FlatFileUserStore($this->dataRoot);

        $this->expectException(RuntimeException::class);
        $store->find('broken');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
