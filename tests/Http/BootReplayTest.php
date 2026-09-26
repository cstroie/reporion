<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Http\Request;
use Reporion\Kernel;

/**
 * The first request after a crash finishes the half-done write (invariant
 * 7, decided 2026-09-26) — before, Storage::replayJournal() existed but
 * nothing ever called it.
 */
final class BootReplayTest extends HttpTestCase
{
    public function testARequestAfterACrashServesTheRecoveredRevision(): void
    {
        $this->createPage('site:note', 'public', 'Note', 'before the crash');
        // Boot replay has run before (it only takes its bearings the first time)
        Kernel::boot($this->config)->handle(new Request('GET', '/site:note'));
        $dir = $this->dataRoot . '/pages/site/note';
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        $document = str_replace('before the crash', 'written just before the crash', (string) file_get_contents($dir . '/current.md'));

        // The crash window: rev 2 is durable, current.md/meta.json/done never happened
        file_put_contents($dir . '/rev/0002.md.gz', gzencode($document, 9));
        $intent = [
            'ts' => date('Y-m-d\TH:i:sP', time() - 300), 'op' => 'save', 'pid' => $meta['pid'], 'path' => 'site:note',
            'rev' => 2, 'base_rev' => 1, 'body_sha' => hash('sha256', $document), 'actor' => 'owner', 'state' => 'intent',
        ];
        file_put_contents($this->dataRoot . '/journal/' . date('Y-m-d') . '.ndjson', json_encode($intent) . "\n", FILE_APPEND);

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/site:note'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('written just before the crash', $response->body);
    }
}
