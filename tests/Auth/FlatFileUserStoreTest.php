<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Auth;

use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Auth\FlatFileGroupStore;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Group;
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

    public function testAccountFilesAreNotWorldReadable(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $user = $store->create('mihai', '$argon2id$fake', false);
        self::assertSame('0640', substr(sprintf('%04o', fileperms($this->dataRoot . '/users/mihai.json')), -4));

        chmod($this->dataRoot . '/users/mihai.json', 0644);
        $store->save($user);
        self::assertSame('0640', substr(sprintf('%04o', fileperms($this->dataRoot . '/users/mihai.json')), -4), 'a save tightens an older 0644 file');
    }

    public function testStampCodeSurvivesASaveAndAnOldRecordHasNone(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $user = $store->create('mihai', '$argon2id$fake', false);
        self::assertSame('', $store->find('mihai')?->stamp);

        $store->save($user->with(stamp: '  A12345 '));
        self::assertSame('A12345', $store->find('mihai')?->stamp);
        self::assertSame('A12345', $store->find('mihai')?->with(title: 'x')->stamp, 'with() keeps it');
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
            // A caller-supplied updatedAt must never reach disk: save()
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

    /** A radiologist signs (D37, amended 2026-10-13): kept on disk, and still needs the write grant */
    public function testTheRadiologistFlagSurvivesTheRoundTripAndStillNeedsTheWriteGrant(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $created = $store->create('mihai', '$argon2id$fake', false, [new Grant('reports:mri', GrantRole::Editor)]);
        self::assertFalse($created->radiologist);
        self::assertFalse($created->canSign('reports:mri:a'), 'a writer who is not a radiologist does not sign');

        $store->save($created->with(radiologist: true));
        $reloaded = $store->find('mihai');
        self::assertTrue($reloaded?->radiologist);
        self::assertTrue($reloaded?->canSign('reports:mri:a'));
        self::assertFalse($reloaded?->canSign('reports:ct:a'), 'the grant still decides where');

        $viewer = $store->create('ana', '$argon2id$fake', false, [new Grant('reports:mri', GrantRole::Viewer)]);
        self::assertFalse($viewer->with(radiologist: true)->canSign('reports:mri:a'), 'a radiologist who can only read does not sign');

        $store->save($reloaded->with(radiologist: false));
        self::assertFalse($store->find('mihai')?->radiologist);
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
        // prefix alone: only a real namespace boundary (":") counts.
        self::assertFalse($grant->covers('reports:mrix'));
    }

    public function testOwnerCanWriteAnyNamespaceRegardlessOfGrants(): void
    {
        $owner = new User('root', 'x', true, [], true, 'now', 'now', radiologist: true);

        self::assertTrue($owner->canWrite('reports:mri'));
        self::assertTrue($owner->canWrite('anything:at:all'));

        // An owner who is not a radiologist reads reports, never writes them (D47)
        $admin = new User('admin', 'x', true, [], true, 'now', 'now');
        self::assertTrue($admin->canRead('reports:mri:mioveni'));
        self::assertFalse($admin->canWrite('reports:mri:mioveni'));
        self::assertTrue($admin->canWrite('anything:at:all'));
    }

    public function testEditorGrantAllowsWriteViewerGrantDoesNot(): void
    {
        $editor = new User('e', 'x', false, [new Grant('reports:mri', GrantRole::Editor)], true, 'now', 'now', radiologist: true);
        $viewer = new User('v', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)], true, 'now', 'now', radiologist: true);

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

    /**
     * Every account can write somewhere: its own namespace (D46), so the coarse gate is true for all of
     * them; what is not an account (a pseudouser, a name no page could live under) has no such place.
     */
    public function testHasAnyWriteAccessIsTrueForEveryAccountAndFalseForWhatIsNotOne(): void
    {
        $owner = new User('root', 'x', true, [], true, 'now', 'now');
        $editor = new User('e', 'x', false, [new Grant('reports:mri', GrantRole::Editor)], true, 'now', 'now', radiologist: true);
        $viewer = new User('v', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)], true, 'now', 'now', radiologist: true);
        $noGrants = new User('n', 'x', false, [], true, 'now', 'now');

        self::assertTrue($owner->hasAnyWriteAccess());
        self::assertTrue($editor->hasAnyWriteAccess());
        self::assertTrue($viewer->hasAnyWriteAccess(), 'a viewer elsewhere still has a place of their own');
        self::assertTrue($noGrants->hasAnyWriteAccess());
        self::assertFalse($viewer->canWrite('reports:mri:mioveni:a'), 'but a viewer grant stays read-only where it was given');
        self::assertFalse((new User('rev', 'x', false, [], true, 'now', 'now'))->hasAnyWriteAccess(), 'a reserved name has no namespace');
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

    public function testSignatureDetailsRoundTripAndOldRecordsReadAsEmpty(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $store->create('signer', 'x', false, [], 'Dr. Test', 'Medic primar');
        self::assertSame('Dr. Test', $store->find('signer')?->displayName);
        self::assertSame('Medic primar', $store->find('signer')?->title);
        self::assertSame('Dr. Test', $store->find('signer')?->signatureName());

        // A record written before the fields existed
        file_put_contents($this->dataRoot . '/users/legacy.json', json_encode([
            'username' => 'legacy', 'password_hash' => 'x', 'is_owner' => false, 'grants' => [],
            'active' => true, 'created' => 'now', 'updated' => 'now',
        ]));
        $legacy = $store->find('legacy');
        self::assertSame('', $legacy?->displayName);
        self::assertSame('legacy', $legacy?->signatureName());
    }

    public function testFullNameSignsAndShortNameIsWhatTheAppUses(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $account = $store->create('ana', 'x', false, [], 'Ana');
        self::assertSame('Ana', $account->signatureName(), 'no full name: the short name signs');

        $store->save($account->with(fullName: '  Dr. Ana Maria Popescu '));
        $reloaded = $store->find('ana');
        self::assertSame('Dr. Ana Maria Popescu', $reloaded?->fullName, 'trimmed and stored as full_name');
        self::assertSame('Dr. Ana Maria Popescu', $reloaded?->signatureName());
        self::assertSame('Ana', $reloaded?->shortName());
        self::assertSame('Ana', $reloaded?->displayName, 'the short name is untouched');

        // Another change keeps the full name; a bare account falls back to the username
        $store->save($reloaded->with(title: 'Medic primar'));
        self::assertSame('Dr. Ana Maria Popescu', $store->find('ana')?->fullName);
        $bare = $store->create('bare', 'x', false);
        self::assertSame('bare', $bare->shortName());
        self::assertSame('bare', $bare->signatureName());
    }

    public function testPinsRoundTripSurviveOtherChangesAndAreCleanedOnRead(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $user = $store->create('pinner', 'x', false, []);
        $store->save($user->with(pins: ['reports:ct', 'templates:mri']));

        // Another change to the account keeps them
        $saved = $store->find('pinner');
        self::assertNotNull($saved);
        $store->save($saved->with(active: false, displayName: 'Dr. Test'));
        self::assertSame(['reports:ct', 'templates:mri'], $store->find('pinner')?->pins);

        // A hand-edited record: a page-shaped leaf, junk and a repeat are dropped, not fatal
        file_put_contents($this->dataRoot . '/users/hand.json', json_encode([
            'username' => 'hand', 'password_hash' => 'x', 'is_owner' => false, 'grants' => [],
            'active' => true, 'created' => 'now', 'updated' => 'now',
            'pins' => ['reports:mri:', 'reports:mri:site:260101-test-name', 7, '../x', 'reports:mri'],
        ]));
        self::assertSame(['reports:mri'], $store->find('hand')?->pins);
    }

    /** D47: groups are stored by name on the account, resolved on read, never folded into its own grants */
    public function testGroupsResolveOnReadAndASaveKeepsOnlyTheAccountsOwnFields(): void
    {
        $groups = new FlatFileGroupStore($this->dataRoot);
        $groups->save(new Group('writers', 'Writers', [new Grant('wiki', GrantRole::Editor)], false, '', ''));
        $groups->save(new Group('radiologists', '', [new Grant('reports', GrantRole::Editor)], true, '', ''));
        self::assertSame(['radiologists', 'writers'], array_map(static fn (Group $g): string => $g->name, $groups->all()));

        $store = new FlatFileUserStore($this->dataRoot);
        $store->save($store->create('ana', 'x', false, [new Grant('notes', GrantRole::Viewer)])->with(groups: ['radiologists', 'writers', 'missing', 'Bad Name']));

        $ana = $store->find('ana');
        self::assertNotNull($ana);
        self::assertSame(['radiologists', 'writers', 'missing'], $ana->groups, 'an invalid name is dropped; a missing group stays named, it only resolves to nothing');
        self::assertTrue($ana->isRadiologist());
        self::assertFalse($ana->radiologist, 'radiologist through a group, not on the account');
        self::assertTrue($ana->canWrite('wiki:x'));
        self::assertTrue($ana->canSign('reports:mri:s:260101-x'));

        // Saving the resolved account back writes only its own grant
        $store->save($ana->with(displayName: 'Dr. Ana'));
        $raw = json_decode((string) file_get_contents($this->dataRoot . '/users/ana.json'), true);
        self::assertSame([['namespace' => 'notes', 'role' => 'viewer']], $raw['grants']);
        self::assertFalse($raw['radiologist']);
        self::assertSame(['radiologists', 'writers', 'missing'], $raw['groups']);

        // A group change reaches the member on the next read; a deleted group is simply gone
        $groups->delete('radiologists');
        $ana = (new FlatFileUserStore($this->dataRoot))->find('ana');
        self::assertFalse($ana?->isRadiologist());
        self::assertFalse($ana?->canRead('reports:mri'));
    }
}
