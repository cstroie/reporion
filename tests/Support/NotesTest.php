<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Storage\FileTree;
use Reporion\Support\Notes;

final class NotesTest extends TestCase
{
    public function testAnAccountsNotesNamespace(): void
    {
        self::assertSame('users:alice:notes', Notes::namespaceFor('alice'));
        self::assertTrue(Notes::isNotesNamespace('users:alice:notes'));
        self::assertTrue(Notes::isNotesNamespace('users:dr.ion-pop:notes'));
    }

    public function testOtherNamespacesAreNot(): void
    {
        foreach (['users:alice', 'users:alice:other', 'users:alice:notes:x', 'notes', 'users::notes', 'reports:mri:notes', 'users:Alice:notes', 'users:alice:notes:'] as $ns) {
            self::assertFalse(Notes::isNotesNamespace($ns), $ns);
        }
    }

    public function testANoteIsADirectChildOfANotesNamespace(): void
    {
        self::assertTrue(Notes::isNote('users:alice:notes:01jabcdefghjkmnpqrstvwxyz0'));
        self::assertTrue(Notes::isNote('users:alice:notes:shopping-list'), 'a hand-made slug is still a note');
        self::assertFalse(Notes::isNote('users:alice:notes'), 'the namespace itself');
        self::assertFalse(Notes::isNote('users:alice:notes:trips:paris'), 'nested deeper');
        self::assertFalse(Notes::isNote('users:alice:diary:today'));
        self::assertFalse(Notes::isNote('reports:mri:site:261008-name'));
    }

    public function testANewPathIsAFreshLowercaseUlidInTheNamespace(): void
    {
        $path = Notes::newPath('users:alice:notes');

        self::assertMatchesRegularExpression('/^users:alice:notes:[0-9a-hjkmnp-tv-z]{26}$/', $path);
        self::assertTrue(Notes::isNote($path));
        self::assertTrue(FileTree::isValidPath($path));
        self::assertNotSame($path, Notes::newPath('users:alice:notes'));
    }
}
