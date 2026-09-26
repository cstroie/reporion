<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * Images pasted or dropped into the editor (D27/D10, decided 2026-09-26):
 * stored once by content, attached to a page, served only to a caller who
 * can open a page they are attached to.
 */
final class MediaTest extends HttpTestCase
{
    private const PAGE = 'reports:mri:mioveni:260101-test-subject';

    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('reader', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Viewer)]);
    }

    public function testAnEditorAttachesAnImageAndGetsItsMarkdown(): void
    {
        $this->createPage(self::PAGE, 'private', 'RM', 'text');
        $png = self::png(3, 2);
        $sha = hash('sha256', $png);

        $response = $this->upload('owner', $png, 'Scan 1.png');

        self::assertSame(201, $response->status);
        $json = json_decode($response->body, true);
        self::assertSame($sha, $json['sha256']);
        self::assertSame('png', $json['ext']);
        self::assertSame([3, 2], [$json['w'], $json['h']]);
        self::assertSame('![Scan 1](media:' . $sha . '.png)', $json['markdown']);
        self::assertFileExists($this->dataRoot . '/media/' . date('Y') . '/' . $sha . '.png');

        $manifest = json_decode((string) file_get_contents($this->dataRoot . '/pages/reports/mri/mioveni/260101-test-subject/media.json'), true);
        self::assertSame('Scan 1.png', $manifest[0]['name']);
        self::assertSame('owner', $manifest[0]['by']);

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"media.attach"', $audit);
        self::assertStringNotContainsString('test-subject', $audit, 'the audit trail stores path_hash, never the path (invariant 8)');
    }

    public function testTheSameBytesTwiceAreStoredAndListedOnce(): void
    {
        $this->createPage(self::PAGE, 'private', 'RM', 'text');
        $png = self::png(1, 1);

        $this->upload('owner', $png, 'a.png');
        $this->upload('owner', $png, 'b.png');

        self::assertCount(1, glob($this->dataRoot . '/media/*/*.png') ?: []);
        self::assertCount(1, json_decode((string) file_get_contents($this->dataRoot . '/pages/reports/mri/mioveni/260101-test-subject/media.json'), true));
    }

    public function testUploadsAreRefusedWithoutWriteAccessOrForBadInput(): void
    {
        $this->createPage(self::PAGE, 'private', 'RM', 'text');

        self::assertSame(404, $this->upload(null, self::png(1, 1), 'a.png')->status, 'anonymous');
        self::assertSame(404, $this->upload('reader', self::png(1, 1), 'a.png')->status, 'a viewer cannot write');
        self::assertSame(422, $this->upload('owner', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'a.svg')->status, 'SVG can carry script');
        self::assertSame(422, $this->upload('owner', '', 'a.png')->status);
        self::assertSame(404, $this->upload('owner', self::png(1, 1), 'a.png', 'reports:nope')->status);

        $this->config['media']['max_bytes'] = 10;
        self::assertSame(413, $this->upload('owner', self::png(1, 1), 'a.png')->status);
        self::assertSame([], glob($this->dataRoot . '/media/*/*') ?: []);
    }

    public function testAFileIsServedOnlyToWhoeverCanOpenAPageItIsAttachedTo(): void
    {
        $this->createPage(self::PAGE, 'private', 'RM', 'text');
        $png = self::png(2, 2);
        $url = '/media/' . hash('sha256', $png) . '.png';
        $this->upload('owner', $png, 'a.png');

        $owner = $this->get($url, 'owner');
        self::assertSame(200, $owner->status);
        self::assertSame($png, $owner->body);
        self::assertSame('image/png', $owner->headers['Content-Type']);
        self::assertSame('nosniff', $owner->headers['X-Content-Type-Options']);
        self::assertStringStartsWith('private', $owner->headers['Cache-Control']);

        self::assertSame(200, $this->get($url, 'reader')->status, 'a viewer granted reports');
        self::assertSame(404, $this->get($url, null)->status, 'anonymous: the page is private');
        self::assertSame(404, $this->get('/media/' . str_repeat('0', 64) . '.png', 'owner')->status, 'no such file');

        $this->createPage('site:note', 'public', 'Note', 'public');
        $this->upload('owner', $png, 'a.png', 'site:note');
        self::assertSame(200, $this->get($url, null)->status, 'now attached to a public page too');
    }

    public function testThePageShowsItAndThePrintEmbedsOnlyWhatIsAttached(): void
    {
        $png = self::png(2, 2);
        $sha = hash('sha256', $png);
        $other = hash('sha256', self::png(5, 5));
        $this->createPage(self::PAGE, 'private', 'RM', "![Scan](media:{$sha}.png)\n\n![Elsewhere](media:{$other}.png)\n");
        $this->upload('owner', $png, 'scan.png');

        $view = $this->get('/' . self::PAGE, 'owner', '/reporion');
        self::assertStringContainsString('<img src="/reporion/media/' . $sha . '.png" alt="Scan" />', $view->body);

        $print = $this->get('/' . self::PAGE . '/print', 'owner');
        self::assertStringContainsString('<img src="data:image/png;base64,' . base64_encode($png) . '" alt="Scan" />', $print->body);
        self::assertStringContainsString('Elsewhere', $print->body);
        self::assertStringNotContainsString($other, $print->body, 'not attached here: its alt text only');
    }

    public function testAccessSurvivesAnIndexRebuild(): void
    {
        $this->createPage(self::PAGE, 'private', 'RM', 'text');
        $png = self::png(1, 1);
        $this->upload('owner', $png, 'a.png');

        unlink((string) $this->config['paths']['index']);
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $storage = new FlatFile($this->dataRoot, $index);
        $index->rebuild((static function () use ($storage) {
            foreach ($storage->allPaths() as $path) {
                yield $storage->snapshotOf($path);
            }
        })());

        self::assertSame(200, $this->get('/media/' . hash('sha256', $png) . '.png', 'owner')->status);
    }

    /** D16: what becomes visible includes the images, which become fetchable by everyone */
    public function testThePublishConfirmationCountsAttachedImages(): void
    {
        $this->createPage('site:note', 'private', 'Note', 'text');
        $this->upload('owner', self::png(1, 1), 'a.png', 'site:note');

        $response = Kernel::boot($this->config)->handle(new Request(
            'PATCH',
            '/api/v1/pages/site:note/meta',
            cookies: ['reporion' => $this->cookie('owner')],
            body: (string) json_encode(['meta' => ['visibility' => 'public'], 'base_rev' => 1]),
        ));

        self::assertSame(409, $response->status);
        self::assertSame(1, json_decode($response->body, true)['preview']['attachedMedia'] ?? null);
    }

    private function upload(?string $user, string $bytes, string $name, string $page = self::PAGE): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/api/v1/media',
            query: ['page' => $page, 'name' => $name],
            cookies: $user === null ? [] : ['reporion' => $this->cookie($user)],
            body: $bytes,
        ));
    }

    private function get(string $path, ?string $user, string $basePath = ''): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            'GET',
            $path,
            cookies: $user === null ? [] : ['reporion' => $this->cookie($user)],
            basePath: $basePath,
        ));
    }

    private function cookie(string $user): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);
    }

    private static function png(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($image, 40, 80, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
