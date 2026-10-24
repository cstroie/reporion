<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Controller\NotesController;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * The notes app (D55): its JSON sign-in (/api/v1/session), the merge of a
 * stale save (PUT /api/v1/pages/{path} with merge: true) and its shell,
 * worker and manifest. Its notes are pages under users:{u}:notes.
 */
final class NotesTest extends HttpTestCase
{
    private const NOTE = 'users:alice:notes:01jabcdefghjkmnpqrstvwxyz0';

    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('alice', password_hash('alice-pass', PASSWORD_ARGON2ID), false);
        $users->create('bob', password_hash('bob-pass', PASSWORD_ARGON2ID), false);
    }

    public function testSignInWithJsonSetsTheSessionCookieAndNamesTheNotesNamespace(): void
    {
        $response = $this->request('POST', '/api/v1/session', ['username' => 'alice', 'password' => 'alice-pass']);

        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('HttpOnly', $response->headers['Set-Cookie']);
        $data = $this->decode($response)['data'];
        self::assertSame('alice', $data['username']);
        self::assertSame('users:alice:notes', $data['notes']);

        preg_match('/^reporion=([^;]*)/', $response->headers['Set-Cookie'], $m);
        $show = Kernel::boot($this->config)->handle(new Request('GET', '/api/v1/session', cookies: ['reporion' => rawurldecode($m[1])]));
        self::assertSame(200, $show->status);
        self::assertSame('alice', $this->decode($show)['data']['username']);
    }

    public function testAWrongPasswordAnUnknownAccountAndADeactivatedOneAreAll401(): void
    {
        $users = new FlatFileUserStore($this->dataRoot);
        $bob = $users->find('bob');
        self::assertNotNull($bob);
        $users->save($bob->with(active: false));

        foreach ([['alice', 'wrong'], ['nobody', 'x'], ['bob', 'bob-pass'], ['', '']] as [$username, $password]) {
            $response = $this->request('POST', '/api/v1/session', ['username' => $username, 'password' => $password]);
            self::assertSame(401, $response->status, $username);
            self::assertSame('invalid_credentials', $this->decode($response)['error']['code']);
            self::assertArrayNotHasKey('Set-Cookie', $response->headers);
        }
    }

    public function testSessionShowsNobodyAnonymousAndSignOutClearsTheCookie(): void
    {
        $show = $this->request('GET', '/api/v1/session');
        self::assertSame(401, $show->status);
        self::assertSame('not_signed_in', $this->decode($show)['error']['code']);

        $out = $this->as('alice', 'DELETE', '/api/v1/session');
        self::assertSame(204, $out->status);
        self::assertStringContainsString('Max-Age=0', $out->headers['Set-Cookie']);
    }

    public function testANoteIsAPageInTheAccountsOwnNamespaceListedForItsOwnerOnly(): void
    {
        self::assertSame(201, $this->as('alice', 'POST', '/api/v1/pages', ['path' => self::NOTE, 'body' => "# Shopping\n\nmilk\n"])->status);
        self::assertSame(404, $this->as('bob', 'POST', '/api/v1/pages', ['path' => 'users:alice:notes:x', 'body' => 'x'])->status);

        $mine = $this->decode($this->as('alice', 'GET', '/api/v1/pages?ns=users:alice:notes'))['data'];
        self::assertSame([self::NOTE], array_column($mine, 'path'));
        self::assertSame('private', $mine[0]['visibility']);
        self::assertSame([], $this->decode($this->as('bob', 'GET', '/api/v1/pages?ns=users:alice:notes'))['data']);
        self::assertSame([], $this->decode($this->as(null, 'GET', '/api/v1/pages?ns=users:alice:notes'))['data']);
    }

    public function testAStaleSaveWithMergeKeepsBothDevicesEdits(): void
    {
        $this->as('alice', 'POST', '/api/v1/pages', ['path' => self::NOTE, 'body' => "one\ntwo\nthree\n"]);
        $meta = $this->decode($this->as('alice', 'GET', '/api/v1/pages/' . self::NOTE . '?render=0'))['meta'];
        self::assertSame(200, $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $meta, 'body' => "ONE\ntwo\nthree\n", 'base_rev' => 1])->status);

        $merged = $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $meta, 'body' => "one\ntwo\nTHREE\n", 'base_rev' => 1, 'merge' => true]);

        self::assertSame(200, $merged->status, $merged->body);
        $data = $this->decode($merged);
        self::assertTrue($data['merged']);
        self::assertSame(3, $data['rev']);
        self::assertSame("ONE\ntwo\nTHREE\n", $data['body']);
    }

    public function testTheTitleIsFrontmatterAndTheListingShowsIt(): void
    {
        $this->as('alice', 'POST', '/api/v1/pages', ['path' => self::NOTE, 'meta' => ['visibility' => 'private', 'title' => 'Shopping'], 'body' => "milk\n"]);

        $row = $this->decode($this->as('alice', 'GET', '/api/v1/pages?ns=users:alice:notes'))['data'][0];
        self::assertSame('Shopping', $row['title']);
        self::assertSame('Shopping', $this->decode($this->as('alice', 'GET', '/api/v1/pages/' . self::NOTE . '?render=0'))['meta']['title']);
    }

    public function testAMergedSaveTakesATitleOnlyTheDeviceChanged(): void
    {
        $this->as('alice', 'POST', '/api/v1/pages', ['path' => self::NOTE, 'meta' => ['visibility' => 'private', 'title' => 'Old'], 'body' => "one\ntwo\nthree\n"]);
        $meta = $this->decode($this->as('alice', 'GET', '/api/v1/pages/' . self::NOTE . '?render=0'))['meta'];
        $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $meta, 'body' => "ONE\ntwo\nthree\n", 'base_rev' => 1]);

        $merged = $this->decode($this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => ['title' => 'New'] + $meta, 'body' => "one\ntwo\nTHREE\n", 'base_rev' => 1, 'merge' => true]));

        self::assertTrue($merged['merged']);
        self::assertSame('New', $merged['meta']['title']);
        self::assertSame("ONE\ntwo\nTHREE\n", $merged['body']);
    }

    public function testATitleBothSidesChangedStaysAsThePageHasIt(): void
    {
        $this->as('alice', 'POST', '/api/v1/pages', ['path' => self::NOTE, 'meta' => ['visibility' => 'private', 'title' => 'Old'], 'body' => "one\n"]);
        $meta = $this->decode($this->as('alice', 'GET', '/api/v1/pages/' . self::NOTE . '?render=0'))['meta'];
        $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => ['title' => 'Server'] + $meta, 'body' => "one\n", 'base_rev' => 1]);

        $merged = $this->decode($this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => ['title' => 'Device'] + $meta, 'body' => "one\ntwo\n", 'base_rev' => 1, 'merge' => true]));

        self::assertSame('Server', $merged['meta']['title']);
        self::assertSame("one\ntwo\n", $merged['body']);
    }

    public function testAMergedSaveRemovesATitleOnlyTheDeviceCleared(): void
    {
        $this->as('alice', 'POST', '/api/v1/pages', ['path' => self::NOTE, 'meta' => ['visibility' => 'private', 'title' => 'Old'], 'body' => "one\n"]);
        $meta = $this->decode($this->as('alice', 'GET', '/api/v1/pages/' . self::NOTE . '?render=0'))['meta'];
        $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $meta, 'body' => "one\ntwo\n", 'base_rev' => 1]);
        $cleared = $meta;
        unset($cleared['title']);

        $merged = $this->decode($this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $cleared, 'body' => "zero\none\n", 'base_rev' => 1, 'merge' => true]));

        self::assertArrayNotHasKey('title', $merged['meta']);
        self::assertSame("zero\none\ntwo\n", $merged['body']);
    }

    public function testOverlappingEditsAre409WithMarkersAndNothingSaved(): void
    {
        $this->as('alice', 'POST', '/api/v1/pages', ['path' => self::NOTE, 'body' => "one\ntwo\n"]);
        $meta = $this->decode($this->as('alice', 'GET', '/api/v1/pages/' . self::NOTE . '?render=0'))['meta'];
        $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $meta, 'body' => "one\nserver\n", 'base_rev' => 1]);

        $response = $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $meta, 'body' => "one\ndevice\n", 'base_rev' => 1, 'merge' => true]);

        self::assertSame(409, $response->status);
        $data = $this->decode($response);
        self::assertSame('conflict', $data['error']['code']);
        self::assertSame(1, $data['conflicts']);
        self::assertSame("one\n<<<<<<< server\nserver\n=======\ndevice\n>>>>>>> this device\n", $data['merged']);
        self::assertSame(2, $data['current']['rev']);
        self::assertSame(2, $this->decode($this->as('alice', 'GET', '/api/v1/pages/' . self::NOTE . '?render=0'))['rev'], 'nothing saved');
    }

    public function testWithoutTheFlagAStaleSaveIsThePlain409(): void
    {
        $this->as('alice', 'POST', '/api/v1/pages', ['path' => self::NOTE, 'body' => "one\ntwo\n"]);
        $meta = $this->decode($this->as('alice', 'GET', '/api/v1/pages/' . self::NOTE . '?render=0'))['meta'];
        $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $meta, 'body' => "ONE\ntwo\n", 'base_rev' => 1]);

        $response = $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $meta, 'body' => "one\nTWO\n", 'base_rev' => 1]);

        self::assertSame(409, $response->status);
        self::assertArrayNotHasKey('merged', $this->decode($response));
    }

    public function testABaseRevisionThePageNeverHadIsThePlain409(): void
    {
        $this->as('alice', 'POST', '/api/v1/pages', ['path' => self::NOTE, 'body' => "one\n"]);
        $meta = $this->decode($this->as('alice', 'GET', '/api/v1/pages/' . self::NOTE . '?render=0'))['meta'];

        foreach ([0, 7] as $baseRev) {
            $response = $this->as('alice', 'PUT', '/api/v1/pages/' . self::NOTE, ['meta' => $meta, 'body' => "two\n", 'base_rev' => $baseRev, 'merge' => true]);
            self::assertSame(409, $response->status, (string) $baseRev);
            self::assertArrayNotHasKey('merged', $this->decode($response));
        }
    }

    public function testTheShellIsTheSameForEveryoneAndCarriesNoAccount(): void
    {
        $anonymous = $this->request('GET', '/notes');
        $signedIn = $this->as('alice', 'GET', '/notes');

        self::assertSame(200, $anonymous->status);
        self::assertSame($anonymous->body, $signedIn->body, 'cacheable offline: nothing of the caller in it');
        self::assertStringContainsString('data-island="notes"', $anonymous->body);
        self::assertStringContainsString('/notes/manifest.webmanifest', $anonymous->body);
        self::assertStringNotContainsString('alice', $signedIn->body);
    }

    public function testTheServiceWorkerIsServedUnderNotesWithWhatItPrecaches(): void
    {
        $response = $this->request('GET', '/notes/sw.js');

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('text/javascript', $response->headers['Content-Type']);
        self::assertSame('/notes', $response->headers['Service-Worker-Allowed'], 'its scope takes in the app at /notes');
        self::assertMatchesRegularExpression('/^const PRECACHE = \[.*"\/notes".*"\/assets\/js\/notes\.js\?v=[0-9a-f]+".*\];/m', $response->body);
        self::assertStringContainsString('const VERSION = "' . NotesController::VERSION . '";', $response->body);
        self::assertStringContainsString('const CACHE = "notes-' . NotesController::VERSION . '-' . NotesController::build('') . '";', $response->body);
        self::assertStringContainsString("addEventListener('fetch'", $response->body);
    }

    public function testTheShellAndTheWorkerNameTheSameVersionAndBuild(): void
    {
        $shell = $this->request('GET', '/notes')->body;
        $worker = $this->request('GET', '/notes/sw.js')->body;
        $build = NotesController::build('');

        self::assertMatchesRegularExpression('/^[0-9]+\.[0-9]+\.[0-9]+$/', NotesController::VERSION);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $build);
        self::assertStringContainsString('"version":"' . NotesController::VERSION . '","build":"' . $build . '"', $shell, 'the page knows what it runs');
        self::assertStringContainsString('Notes ' . NotesController::VERSION . ' · build ' . $build, $shell, 'and shows it');
        self::assertStringContainsString('const BUILD = "' . $build . '";', $worker);
    }

    public function testANewWorkerWaitsUntilThePageAsksItToTakeOver(): void
    {
        $worker = $this->request('GET', '/notes/sw.js')->body;
        $install = substr($worker, (int) strpos($worker, "addEventListener('install'"), 200);

        self::assertStringNotContainsString('skipWaiting', $install, 'an open page keeps its version until it reloads');
        self::assertStringContainsString("type === 'skip-waiting'", $worker);
        self::assertStringContainsString("type === 'version'", $worker);
    }

    public function testTheStartPageAdvertisesTheAppToASignedInAccountOnly(): void
    {
        $start = $this->as('alice', 'GET', '/')->body;

        self::assertStringContainsString('<div data-notes-promo hidden>', $start, 'hidden until notes-promo.js shows it');
        self::assertStringContainsString('href="/notes"', $start, 'the card and the quick links lead to it');
        self::assertStringContainsString(t('quick.notes'), $start);
        self::assertMatchesRegularExpression('#/assets/js/notes-promo\.js\?v=[0-9a-f]+#', $start);

        $anonymous = $this->request('GET', '/')->body;
        self::assertStringNotContainsString('data-notes-promo', $anonymous);
        self::assertStringNotContainsString('href="/notes"', $anonymous);
    }

    public function testTheShellIsAnAppWithItsOwnLookNotAReporionPage(): void
    {
        $shell = $this->request('GET', '/notes')->body;

        foreach (NotesController::STYLES as $css) {
            self::assertStringContainsString('/assets/' . $css . '?v=', $shell);
        }
        self::assertStringNotContainsString('wiki.css', $shell, "none of Reporion's page components");
        self::assertStringContainsString('data-screen="boot"', $shell, 'the script puts a screen in front');
        foreach (['signin', 'list', 'editor'] as $screen) {
            self::assertStringContainsString('data-view="' . $screen . '"', $shell);
        }
    }

    public function testTheShellHasTheInstallButtonHiddenUntilTheBrowserOffersIt(): void
    {
        $shell = $this->request('GET', '/notes')->body;

        self::assertMatchesRegularExpression('/<button[^>]*data-install[^>]*hidden>/', $shell);
        self::assertStringContainsString('"notes.install_ios":', $shell);
    }

    public function testTheManifestStartsAndScopesTheAppAtNotes(): void
    {
        $response = $this->request('GET', '/notes/manifest.webmanifest');

        self::assertSame(200, $response->status);
        $manifest = $this->decode($response);
        self::assertSame('/notes', $manifest['start_url']);
        self::assertSame('/notes', $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);
    }

    public function testTheManifestNamesTheAppsOwnIconsAndEachExists(): void
    {
        $icons = $this->decode($this->request('GET', '/notes/manifest.webmanifest'))['icons'];
        $found = [];
        foreach ($icons as $icon) {
            $file = \dirname(__DIR__, 2) . strtok($icon['src'], '?');
            self::assertFileExists($file);
            $found[] = $icon['sizes'] . ' ' . $icon['purpose'];
            if ($icon['type'] === 'image/png') {
                $size = getimagesize($file);
                self::assertIsArray($size);
                self::assertSame($icon['sizes'], $size[0] . 'x' . $size[1], $icon['src']);
            }
        }

        // What Chrome asks of an installable app: 192 and 512 px, and a maskable one for Android
        self::assertContains('192x192 any', $found);
        self::assertContains('512x512 any', $found);
        self::assertContains('512x512 maskable', $found);
        self::assertStringContainsString('/assets/notes/apple-touch-icon.png', $this->request('GET', '/notes')->body);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function request(string $method, string $path, ?array $json = null): Response
    {
        return $this->as(null, $method, $path, $json);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function as(?string $username, string $method, string $path, ?array $json = null): Response
    {
        $cookies = [];
        if ($username !== null) {
            $cookies = ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username)];
        }
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);

        return Kernel::boot($this->config)->handle(new Request($method, $route, query: $params, cookies: $cookies, body: $json !== null ? (string) json_encode($json) : ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, $response->body);

        return $decoded;
    }
}
