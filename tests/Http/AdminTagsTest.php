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
 * Admin → Tags (decided 2026-09-26): counts, rename and merge rewrite the
 * frontmatter of unsigned pages as new revisions; signed reports keep
 * their tags; owner-only, 404 for everyone else.
 */
final class AdminTagsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Editor)]);
    }

    public function testTheScreenListsTagsWithCountsForTheOwnerOnly(): void
    {
        $this->page('reports:a', ['SM', 'follow-up']);
        $this->page('reports:b', ['SM']);

        $response = $this->request('GET', '/admin/tags', 'owner');
        self::assertSame(200, $response->status);
        self::assertMatchesRegularExpression('~>SM</a></td>\s*<td[^>]*>.*?</td>\s*<td class="wk-mono"[^>]*>2</td>~', $response->body);
        self::assertMatchesRegularExpression('~>follow-up</a></td>\s*<td[^>]*>.*?</td>\s*<td class="wk-mono"[^>]*>1</td>~', $response->body);

        self::assertSame(404, $this->request('GET', '/admin/tags', 'editor')->status);
        self::assertSame(404, $this->request('GET', '/admin/tags', null)->status);
        self::assertSame(404, $this->request('POST', '/admin/tags/rename', 'editor', 'from=SM&to=x')->status);
    }

    public function testRenamingRewritesUnsignedPagesAndLeavesSignedOnes(): void
    {
        $this->page('reports:a', ['SM', 'follow-up']);
        $this->page('reports:b', ['SM']);
        $this->page('reports:c', ['SM'], sign: true);

        $response = $this->request('POST', '/admin/tags/rename', 'owner', 'from=SM&to=scleroza-multipla');

        self::assertSame(302, $response->status);
        self::assertStringEndsWith('/admin/tags?changed=2&signed=1', $response->headers['Location']);
        $storage = $this->storage();
        self::assertSame(['scleroza-multipla', 'follow-up'], $storage->read('reports:a')->frontmatter['tags']);
        self::assertSame(2, $storage->read('reports:a')->rev);
        self::assertSame(['SM'], $storage->read('reports:c')->frontmatter['tags'], 'signed: untouched (D3)');
        self::assertSame('signed', $storage->read('reports:c')->status);
        self::assertStringContainsString('"reason":"tag-rename"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        $screen = $this->request('GET', '/admin/tags?changed=2&signed=1', 'owner');
        self::assertStringContainsString('2 page(s) rewritten.', $screen->body);
        self::assertStringContainsString('1 signed report(s) kept the old tag', $screen->body);
    }

    public function testMergingFoldsSeveralTagsIntoOneWithoutDuplicates(): void
    {
        $this->page('reports:a', ['pirads', 'PI-RADS']);
        $this->page('reports:b', ['pi-rads', 'other']);

        $this->request('POST', '/admin/tags/merge', 'owner', 'from[]=pirads&from[]=pi-rads&into=PI-RADS');

        $storage = $this->storage();
        self::assertSame(['PI-RADS'], $storage->read('reports:a')->frontmatter['tags']);
        self::assertSame(['PI-RADS', 'other'], $storage->read('reports:b')->frontmatter['tags']);
    }

    public function testABadNameOrAnEmptySelectionIsRefusedAndChangesNothing(): void
    {
        $this->page('reports:a', ['SM']);

        self::assertSame(422, $this->request('POST', '/admin/tags/rename', 'owner', 'from=SM&to=' . rawurlencode('a, b'))->status);
        self::assertSame(422, $this->request('POST', '/admin/tags/merge', 'owner', 'into=x')->status);
        self::assertSame(1, $this->storage()->read('reports:a')->rev);
    }

    public function testTheDictionaryIsSeededFromTheShippedSynonymsAndSavedToDisk(): void
    {
        $this->page('reports:a', ['SM']);

        $screen = $this->request('GET', '/admin/tags', 'owner')->body;
        self::assertMatchesRegularExpression('~>hernie</a></td>\s*<td[^>]*>.*?</td>\s*<td class="wk-mono wk-dim"[^>]*>0</td>\s*<td class="wk-mono wk-dim"[^>]*>hernia, herniar~', $screen, 'conf/synonyms.txt seeds it, first term as the tag');
        self::assertFileDoesNotExist($this->dataRoot . '/tags.yaml', 'reading writes nothing');

        $saved = $this->request('POST', '/admin/tags/dictionary', 'owner', http_build_query(['tag' => 'SM', 'group' => 'diagnosis', 'icd10' => 'g35', 'synonyms' => 'scleroza multipla, SM, multiple sclerosis, scleroza multipla']));
        self::assertSame(302, $saved->status);
        self::assertStringEndsWith('/admin/tags?saved=1', $saved->headers['Location']);

        $file = $this->dataRoot . '/tags.yaml';
        $stored = \Symfony\Component\Yaml\Yaml::parseFile($file);
        self::assertSame(['group' => 'diagnosis', 'icd10' => 'G35', 'synonyms' => ['scleroza multipla', 'multiple sclerosis']], $stored['SM'], 'upper-cased code; the tag itself and repeats dropped');
        self::assertArrayHasKey('hernie', $stored, 'the seed is kept once the file is written');
        self::assertStringContainsString('"action":"tags.dictionary"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        // Disk is authoritative: a fresh index knows nothing, the screen still does
        @unlink($this->dataRoot . '/index.sqlite');
        self::assertMatchesRegularExpression('~>SM</a></td>\s*<td[^>]*>diagnosis</td>~', $this->request('GET', '/admin/tags', 'owner')->body);

        $this->request('POST', '/admin/tags/dictionary', 'owner', http_build_query(['tag' => 'SM', 'group' => '', 'icd10' => '', 'synonyms' => '']));
        self::assertArrayNotHasKey('SM', \Symfony\Component\Yaml\Yaml::parseFile($file), 'an all-blank entry is removed');
    }

    public function testABadEntryIsRefusedAndShownBackAsTyped(): void
    {
        $bad = $this->request('POST', '/admin/tags/dictionary', 'owner', http_build_query(['tag' => 'SM', 'group' => str_repeat('g', 40), 'icd10' => 'G35', 'synonyms' => 'x']));

        self::assertSame(422, $bad->status);
        self::assertStringContainsString('A group is at most 32 characters.', $bad->body);
        self::assertStringContainsString('name="icd10" maxlength="16" value="G35"', $bad->body, 'what was typed stays');
        self::assertFileDoesNotExist($this->dataRoot . '/tags.yaml');
        self::assertSame(404, $this->request('POST', '/admin/tags/dictionary', 'editor', 'tag=SM&group=x')->status);
    }

    public function testAMergedNameBecomesASynonymAndSearchFindsItsPages(): void
    {
        $this->page('reports:a', ['pirads'], body: "Leziune PI-RADS 4.\n");
        $this->page('reports:b', ['PI-RADS', 'pi-rads']);
        $this->request('POST', '/admin/tags/dictionary', 'owner', http_build_query(['tag' => 'pirads', 'group' => 'diagnosis', 'icd10' => 'C61', 'synonyms' => 'prostate score']));

        $screen = $this->request('GET', '/admin/tags', 'owner')->body;
        self::assertStringContainsString('Suggested merges', $screen);
        self::assertStringContainsString('href="/admin/tags?into=PI-RADS&amp;from=PI-RADS%2Cpi-rads%2Cpirads#merge"', $screen, 'one spelling folded; the one on most pages first');
        $prefilled = $this->request('GET', '/admin/tags?into=PI-RADS&from=pi-rads', 'owner')->body;
        self::assertStringContainsString('name="into" required value="PI-RADS"', $prefilled);
        self::assertStringContainsString('value="pi-rads" checked', $prefilled);
        self::assertStringNotContainsString('value="pirads" checked', $prefilled, 'a suggestion only prefills');

        $this->request('POST', '/admin/tags/merge', 'owner', 'from[]=pirads&from[]=pi-rads&into=PI-RADS');

        $stored = \Symfony\Component\Yaml\Yaml::parseFile($this->dataRoot . '/tags.yaml');
        self::assertSame(['group' => 'diagnosis', 'icd10' => 'C61', 'synonyms' => ['pirads', 'prostate score']], $stored['PI-RADS'], "the merged tag's details move to the target, its name a synonym");
        self::assertArrayNotHasKey('pirads', $stored);
        self::assertStringContainsString('href="/reports:a"', $this->request('GET', '/search?q=' . rawurlencode('prostate score'), 'owner')->body, 'a synonym finds the text that says PI-RADS (D28)');
        self::assertStringNotContainsString('href="/reports:a"', $this->request('GET', '/search?q=prostate', 'owner')->body, 'one word of it alone is no synonym');
    }

    /** @param list<string> $tags */
    private function page(string $path, array $tags, bool $sign = false, string $body = "text\n"): void
    {
        $storage = $this->storage();
        $storage->create($path, ['title' => 'T', 'visibility' => 'private', 'tags' => $tags], $body, 'owner');
        if ($sign) {
            $storage->sign($path, 'owner', []);
        }
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function request(string $method, string $path, ?string $user, string $body = ''): Response
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);

        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $route,
            query: array_map('strval', $params),
            cookies: $user === null ? [] : ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)],
            body: $body,
        ));
    }
}
