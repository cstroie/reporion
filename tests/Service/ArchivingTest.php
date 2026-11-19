<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Audit\AuditLog;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Exception\NotRadiologistException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Service\Archiving;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\RecordingIndex;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * D65: a report that will not be signed here is archived, and back, by a
 * radiologist with the write grant; audited by pid.
 */
final class ArchivingTest extends StorageTestCase
{
    private const PATH = 'reports:mri:site:260101-test';

    public function testARadiologistWithTheWriteGrantArchivesAndUnarchives(): void
    {
        [$storage, $archiving] = $this->setUpArchiving();
        $record = $storage->create(self::PATH, ['modality' => 'MR'], 'body', 'owner');
        $rad = $this->user('rad', GrantRole::Editor, radiologist: true);

        $archived = $archiving->archive($record, $rad, 1, null);
        self::assertSame('archived', $archived->status);
        $back = $archiving->unarchive($archived, $rad, 1, null);
        self::assertSame('draft', $back->status);

        $audit = (string) file_get_contents((string) glob($this->dataRoot . '/audit/*')[0]);
        self::assertStringContainsString('"page.archive"', $audit);
        self::assertStringContainsString('"page.unarchive"', $audit);
        self::assertStringNotContainsString('260101-test', $audit, 'the path never reaches the audit (invariant 8)');
    }

    public function testANonRadiologistOwnerIsRefusedAndAViewerOrANonReportFindsNothing(): void
    {
        [$storage, $archiving] = $this->setUpArchiving();
        $record = $storage->create(self::PATH, ['modality' => 'MR'], 'body', 'owner');
        $page = $storage->create('docs:note', [], 'body', 'owner');

        try {
            $archiving->archive($record, new User('own', '', true, [], true, '', ''), 1, null);
            self::fail('an owner who is not a radiologist');
        } catch (NotRadiologistException) {
        }
        foreach ([[$record, $this->user('view', GrantRole::Viewer, radiologist: true)], [$page, new User('own', '', true, [], true, '', '', radiologist: true)]] as [$target, $who]) {
            try {
                $archiving->archive($target, $who, 1, null);
                self::fail('not found');
            } catch (PageNotFoundException) {
            }
        }
        self::assertSame('draft', $storage->read(self::PATH)->status);
    }

    /** @return array{FileTree, Archiving} */
    private function setUpArchiving(): array
    {
        $storage = new FileTree($this->dataRoot, new RecordingIndex());

        return [$storage, new Archiving($storage, new AuditLog($this->dataRoot . '/audit'))];
    }

    private function user(string $name, GrantRole $role, bool $radiologist): User
    {
        return new User($name, '', false, [new Grant('reports', $role)], true, '', '', radiologist: $radiologist);
    }
}
