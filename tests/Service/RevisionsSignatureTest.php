<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use PHPUnit\Framework\TestCase;
use Reporion\Schema\Loader;
use Reporion\Service\Revisions;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * Revisions::signature(): a signed revision that cannot be read is not a
 * mismatch. Fixture data is fictitious (invariant 10).
 */
final class RevisionsSignatureTest extends TestCase
{
    public function testAnUnreadableSignedRevisionIsNeitherAMatchNorAMismatch(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('readRevision')->willThrowException(new \RuntimeException('entry unreadable at /secret/path'));
        $page = new PageRecord('01JB8X4MT7QK2V9Z0C3R5H6ND', 'reports:a', 2, 'signed', 'private', [], '', [], [
            'signatures' => [['rev' => 2, 'by' => 'dr', 'ts' => '2026-09-27T10:00:00+03:00', 'digest' => 'abc']],
        ]);
        $log = tempnam(sys_get_temp_dir(), 'reporion-log');
        $previous = ini_set('error_log', $log);
        try {
            $signature = (new Revisions($storage, new Loader(\dirname(__DIR__, 2) . '/resources/schema')))->signature($page, 2);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertNotNull($signature);
        self::assertFalse($signature['matches']);
        self::assertTrue($signature['unreadable']);
        self::assertStringContainsString('could not be read to check its signature: RuntimeException', $logged);
        self::assertStringNotContainsString('/secret/path', $logged);
    }
}
