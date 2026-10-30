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
use Reporion\Kernel;

/**
 * The library's endpoints (docs/library.md, phase 1): upload → proposed record → confirmed page,
 * the original served to who may see the page. The article is made up.
 */
final class LibraryTest extends HttpTestCase
{
    private const ARTICLE = "# A made-up study of test lesions\n\nJ Synth Imaging 2024;3:11-14. doi:10.1234/jti.2024.0001\n\nGrade 3 was the most common.\n";

    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('library', GrantRole::Editor)]);
        $users->create('reader', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('library', GrantRole::Viewer)]);
        $users->create('other', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Editor)]);
    }

    public function testAnEditorUploadsConfirmsAndTheOriginalIsServed(): void
    {
        $uploaded = $this->upload('editor', self::ARTICLE, 'study.md');
        self::assertSame(201, $uploaded->status);
        $proposal = json_decode($uploaded->body, true);
        self::assertSame('A made-up study of test lesions', $proposal['csl']['title']);
        self::assertSame('madeup2024', $proposal['key']);

        $confirmed = $this->call('editor', 'POST', '/api/v1/library', ['token' => $proposal['token'], 'csl' => $proposal['csl'], 'key' => 'study2024']);
        self::assertSame(201, $confirmed->status);
        $page = json_decode($confirmed->body, true);
        self::assertSame(['library:study2024', 'study2024', '/library:study2024'], [$page['path'], $page['key'], $page['href']]);

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"library.add"', $audit);

        $file = $this->call('reader', 'GET', '/library/file/' . $page['pid']);
        self::assertSame(200, $file->status);
        self::assertSame(self::ARTICLE, $file->body);
        self::assertSame('attachment; filename="study2024.md"', $file->headers['Content-Disposition']);
        self::assertSame('nosniff', $file->headers['X-Content-Type-Options']);
        self::assertSame(404, $this->call(null, 'GET', '/library/file/' . $page['pid'])->status, 'anonymous: never');
        self::assertSame(404, $this->call('other', 'GET', '/library/file/' . $page['pid'])->status, 'no grant on the library');
    }

    public function testOnlyAnEditorOfTheLibraryUploadsOrConfirms(): void
    {
        foreach ([null, 'reader', 'other'] as $user) {
            self::assertSame(404, $this->upload($user, self::ARTICLE, 'study.md')->status, $user ?? 'anonymous');
            self::assertSame(404, $this->call($user, 'POST', '/api/v1/library', ['token' => str_repeat('0', 32), 'csl' => ['title' => 'X y z']])->status);
        }
        self::assertSame([], glob($this->dataRoot . '/tmp/library/*') ?: []);
    }

    public function testRefusalsAreSaidWithTheirOwnStatus(): void
    {
        self::assertSame(415, $this->upload('owner', 'MZ', 'tool.exe')->status);
        $this->config['library']['max_mb'] = 1;
        self::assertSame(413, $this->upload('owner', str_repeat('a', 1024 * 1024 + 1), 'big.txt')->status);

        $expired = $this->call('owner', 'POST', '/api/v1/library', ['token' => str_repeat('0', 32), 'csl' => ['title' => 'X y z']]);
        self::assertSame(410, $expired->status);
        self::assertSame('expired', json_decode($expired->body, true)['error']['code']);

        $first = json_decode($this->upload('owner', self::ARTICLE, 'a.md')->body, true);
        $this->call('owner', 'POST', '/api/v1/library', ['token' => $first['token'], 'csl' => $first['csl']]);
        $again = json_decode($this->upload('owner', self::ARTICLE, 'b.md')->body, true);
        self::assertSame('file', $again['duplicate']['by']);
        $duplicate = $this->call('owner', 'POST', '/api/v1/library', ['token' => $again['token'], 'csl' => $again['csl']]);
        self::assertSame(409, $duplicate->status);
        self::assertSame('library:madeup2024', json_decode($duplicate->body, true)['error']['fields']['path']);
    }

    public function testTheVisibilityScreenRefusesToPublishALibrarySource(): void
    {
        $proposal = json_decode($this->upload('owner', self::ARTICLE, 'study.md')->body, true);
        $page = json_decode($this->call('owner', 'POST', '/api/v1/library', ['token' => $proposal['token'], 'csl' => $proposal['csl']])->body, true);

        $patched = $this->call('owner', 'PATCH', '/api/v1/pages/' . $page['path'] . '/meta', ['meta' => ['visibility' => 'public'], 'base_rev' => 1, 'acknowledge' => true]);

        self::assertSame(422, $patched->status);
        self::assertSame(['invalid_meta', 'A library source is always private'], array_values(array_intersect_key(json_decode($patched->body, true)['error'], ['code' => 1, 'message' => 1])));
        self::assertSame('private', (new \Reporion\Storage\FileTree($this->dataRoot, new \Reporion\Tests\Storage\RecordingIndex()))->read($page['path'])->visibility);
    }

    public function testTheEditorRefusesToPublishALibrarySourceAndKeepsTheText(): void
    {
        $proposal = json_decode($this->upload('owner', self::ARTICLE, 'study.md')->body, true);
        $page = json_decode($this->call('owner', 'POST', '/api/v1/library', ['token' => $proposal['token'], 'csl' => $proposal['csl']])->body, true);

        $refused = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/' . $page['path'] . '/edit',
            cookies: ['reporion' => $this->cookie('owner')],
            body: http_build_query(['body' => 'edited text', 'base_rev' => 1, 'visibility' => 'public', 'acknowledge' => '1']),
        ));

        self::assertSame(422, $refused->status);
        self::assertStringContainsString('A library source is always private', $refused->body);
        self::assertStringContainsString('edited text', $refused->body);
    }

    public function testTheLibraryViewListsWhatTheCallerMaySeeAndOffersTheUploadToAnEditor(): void
    {
        $this->add('editor', self::ARTICLE, 'study.md', ['grading']);
        $this->add('editor', "# Another made-up study of lesions\n\n© 2023\n", 'b.md', ['other']);

        $editor = $this->call('editor', 'GET', '/library');
        self::assertSame(200, $editor->status);
        self::assertStringContainsString('data-island="library-add"', $editor->body);
        self::assertStringContainsString('A made-up study of test lesions', $editor->body);
        self::assertStringContainsString('Another made-up study of lesions', $editor->body);

        $reader = $this->call('reader', 'GET', '/library?tag=grading&sort=year');
        self::assertSame(200, $reader->status);
        self::assertStringNotContainsString('data-island="library-add"', $reader->body, 'a viewer reads, never adds');
        self::assertStringContainsString('A made-up study of test lesions', $reader->body);
        self::assertStringNotContainsString('Another made-up study of lesions', $reader->body, 'filtered by its tag');

        $other = $this->call('other', 'GET', '/library');
        self::assertSame(200, $other->status);
        self::assertStringNotContainsString('made-up study', $other->body, 'no grant on the library: nothing in it');
        self::assertSame(404, $this->call(null, 'GET', '/library')->status);
    }

    public function testASourcePageShowsItsReferenceAndAWriterCorrectsItsRecord(): void
    {
        $page = $this->add('editor', self::ARTICLE, 'study.md');

        $view = $this->call('reader', 'GET', '/' . $page['path']);
        self::assertSame(200, $view->status);
        self::assertStringContainsString('data-island="library-record"', $view->body);
        self::assertStringContainsString('A made-up study of test lesions. 2024. doi:10.1234/jti.2024.0001', html_entity_decode($view->body, ENT_QUOTES));
        self::assertStringNotContainsString('data-lib-edit', $view->body, 'a viewer cannot edit the record');
        self::assertStringContainsString('data-lib-edit', $this->call('editor', 'GET', '/' . $page['path'])->body);

        $csl = ['title' => 'A corrected title', 'author' => [['family' => 'Nobody', 'given' => 'Ann']], 'issued' => ['date-parts' => [[2024]]]];
        self::assertSame(404, $this->call('reader', 'PUT', '/api/v1/library/' . $page['pid'] . '/record', ['csl' => $csl, 'base_rev' => 1])->status);
        $saved = $this->call('editor', 'PUT', '/api/v1/library/' . $page['pid'] . '/record', ['csl' => $csl, 'base_rev' => 1, 'tags' => ['grading']]);
        self::assertSame(200, $saved->status);
        self::assertSame(2, json_decode($saved->body, true)['rev']);
        self::assertSame(409, $this->call('editor', 'PUT', '/api/v1/library/' . $page['pid'] . '/record', ['csl' => $csl, 'base_rev' => 1])->status, 'a stale revision');
        self::assertStringContainsString('Nobody A. A corrected title. 2024.', html_entity_decode($this->call('reader', 'GET', '/' . $page['path'])->body, ENT_QUOTES));
    }

    public function testAnUnlistedSourceIsRefusedSoNoAnonymousLinkReadsIt(): void
    {
        $page = $this->add('editor', self::ARTICLE, 'study.md');

        $patched = $this->call('editor', 'PATCH', '/api/v1/pages/' . $page['path'] . '/meta', ['meta' => ['visibility' => 'unlisted'], 'base_rev' => 1]);

        self::assertSame(422, $patched->status);
        self::assertSame(404, $this->call(null, 'GET', '/' . $page['path'])->status);
        self::assertSame(404, $this->call(null, 'GET', '/api/v1/pages/' . $page['path'])->status);
    }

    public function testAReadTokenCannotAddOrCorrect(): void
    {
        $page = $this->add('editor', self::ARTICLE, 'study.md');
        $store = new FlatFileUserStore($this->dataRoot);
        [$user, $token] = (new \Reporion\Auth\ApiTokens($store))->create($store->find('editor'), 'script', 'read');
        $store->save($user);
        $config = $this->config;
        $bearer = static fn (string $method, string $path, string $body, array $query = []): Response => Kernel::boot($config)->handle(
            new Request($method, $path, query: $query, body: $body, authorization: 'Bearer ' . $token),
        );

        self::assertSame(403, $bearer('POST', '/api/v1/library/uploads', "# Another made-up study\n\n© 2020\n", ['name' => 'x.md'])->status);
        self::assertSame(403, $bearer('POST', '/api/v1/library', (string) json_encode(['token' => str_repeat('0', 32), 'csl' => ['title' => 'X y z']]))->status);
        self::assertSame(403, $bearer('POST', '/api/v1/library/import', "@article{k, title = {A made-up one}, year = {2020}}")->status);
        self::assertSame(403, $bearer('PUT', '/api/v1/library/' . $page['pid'] . '/record', (string) json_encode(['csl' => ['title' => 'X y z'], 'base_rev' => 1]))->status);
        self::assertSame([], glob($this->dataRoot . '/tmp/library/*') ?: []);
    }

    public function testSavingTheTextFromTheEditorKeepsTheRecordAndTheOriginal(): void
    {
        $page = $this->add('editor', self::ARTICLE, 'study.md');
        $storage = new \Reporion\Storage\FileTree($this->dataRoot, new \Reporion\Tests\Storage\RecordingIndex());
        $before = $storage->read($page['path'])->frontmatter;
        $cookie = $this->cookie('editor');

        $curated = $this->saveOpened($this->call('editor', 'GET', '/' . $page['path'] . '/edit'), $cookie, ['body' => "Grade 3 was the most common (corrected).\n"]);
        self::assertSame(302, $curated->status, 'curated save');
        $raw = $this->call('editor', 'GET', '/' . $page['path'] . '/edit?raw=1');
        self::assertSame(200, $raw->status);
        preg_match('/<textarea[^>]*name="document"[^>]*>(.*?)<\/textarea>/s', $raw->body, $m);
        $document = html_entity_decode($m[1] ?? '', ENT_QUOTES);
        self::assertStringContainsString('csl:', $document);
        // The raw form posts to ?raw=1 (saveOpened() keeps the action's path only)
        $saved = Kernel::boot($this->config)->handle(new Request('POST', '/' . $page['path'] . '/edit', query: ['raw' => '1'], cookies: ['reporion' => $cookie],
            body: http_build_query(['document' => str_replace('(corrected)', '(corrected twice)', $document), 'base_rev' => 2])));
        self::assertSame(302, $saved->status, 'raw save');

        $after = $storage->read($page['path']);
        self::assertSame(3, $after->rev);
        self::assertStringContainsString('(corrected twice)', $after->body);
        self::assertSame($before['csl'], $after->frontmatter['csl']);
        self::assertSame($before['original'], $after->frontmatter['original']);
        self::assertSame('private', $after->visibility);
    }

    public function testANotebookCitesASourceAndEachReaderGetsTheReferenceItMayHave(): void
    {
        $source = $this->add('editor', self::ARTICLE, 'study.md');
        $key = $source['key'];
        $this->createPage('wiki:notebook', 'public', 'A notebook', "Grading is common [@{$key}, p. 2] and unknown [@nobody1999].\n");
        $this->createPage('wiki:private-notebook', 'private', 'Private', "Same [@{$key}].\n");

        $owner = $this->call('owner', 'GET', '/wiki:private-notebook');
        self::assertSame(200, $owner->status);
        self::assertStringContainsString('<h2 id="references">Bibliografie</h2>', $owner->body);
        self::assertStringContainsString('class="wk-ref-src" href="/library:' . $key . '"', $owner->body, 'a reader of the library follows the citation');

        // Reads the notebook, has no grant on the library: the reference without the link
        $other = $this->call('other', 'GET', '/wiki:private-notebook');
        self::assertSame(200, $other->status);
        self::assertStringContainsString('A made-up study of test lesions', $other->body);
        self::assertStringNotContainsString('wk-ref-src', $other->body);

        // An anonymous reader of the public notebook: the reference, no link into the library (owner, 2026-10-30)
        $public = $this->call(null, 'GET', '/wiki:notebook');
        self::assertSame(200, $public->status);
        self::assertStringContainsString('A made-up study of test lesions', $public->body);
        self::assertStringNotContainsString('wk-ref-src', $public->body);
        self::assertStringNotContainsString('href="/library:', $public->body);
        self::assertStringNotContainsString('Not in the library', $public->body, 'a missing key is said to a signed-in reader, not to a visitor');
        self::assertStringNotContainsString('Grade 3 was the most common', $public->body, 'the source\'s text stays private');
        self::assertSame(404, $this->call(null, 'GET', '/library:' . $key)->status);

        // The library lists how many pages cite each source (the citation is a link in the index)
        self::assertMatchesRegularExpression('~<td class="wk-mono"><a href="/library:' . $key . '#backlinks"[^>]*>2</a></td>~', $this->call('owner', 'GET', '/library')->body);

        // The editor's preview numbers them the same way
        $preview = json_decode($this->call('other', 'POST', '/api/v1/render', ['markdown' => "A [@{$key}].", 'citation_style' => 'apa'])->body, true);
        self::assertStringContainsString('wk-reflist-apa', $preview['html']);
    }

    public function testTheLookupSettingReachesTheUpload(): void
    {
        // No identifier in the text: nothing is sent, but the answer says the lookup is on
        $article = "# A made-up study with no identifier\n\nText only.\n";
        self::assertNull(json_decode($this->upload('editor', $article, 'a.md')->body, true)['lookup'], 'off by default');

        $this->config['library']['lookup'] = true;
        self::assertSame('none', json_decode($this->upload('editor', $article, 'b.md')->body, true)['lookup']);
    }

    public function testTickingTheLookupInAdminKeepsTheOtherLibrarySettingsOfConf(): void
    {
        $this->config['library'] = ['max_mb' => 1, 'tools' => ['pandoc' => '/usr/bin/pandoc']];
        $saved = Kernel::boot($this->config)->handle(new Request('POST', '/admin/settings/library', cookies: ['reporion' => $this->cookie('owner')], body: 'library_lookup=1'));
        self::assertSame(302, $saved->status);

        // The saved switch is read together with conf's: the size limit and the tool paths are still there
        $article = "# A made-up study with no identifier\n\nText only.\n";
        self::assertSame('none', json_decode($this->upload('editor', $article, 'c.md')->body, true)['lookup']);
        self::assertSame(413, $this->upload('editor', str_repeat('a', 1024 * 1024 + 1), 'big.txt')->status);
    }

    public function testAnEditorImportsAReferenceManagersExportAndOthersCannot(): void
    {
        $bib = "@article{Abel2020, author = {Abel, Zed}, title = {Imported made-up study}, journal = {J Synth}, year = {2020}, doi = {10.9999/jsi.2020.0001}}\n@book{bad, title = {   }}\n";
        foreach ([null, 'reader', 'other'] as $user) {
            self::assertSame(404, $this->importAs($user, $bib)->status, $user ?? 'anonymous');
        }
        self::assertFalse(is_file($this->dataRoot . '/pages/library/abel2020.md'));

        $imported = $this->importAs('editor', $bib, 'batch');
        self::assertSame(201, $imported->status);
        $json = json_decode($imported->body, true);
        self::assertSame(['abel2020'], array_column($json['added'], 'key'));
        self::assertSame('/library:abel2020', $json['added'][0]['href']);
        self::assertSame([], $json['skipped'], 'an entry with no title is no entry');
        self::assertSame(200, $this->importAs('editor', $bib)->status);

        self::assertSame(415, $this->importAs('editor', 'Not a bibliography')->status);
        $this->config['library']['max_mb'] = 1;
        self::assertSame(413, $this->importAs('editor', str_repeat('a', 1024 * 1024 + 1))->status);

        // It is a source like any: private, listed, exportable, cited
        self::assertStringContainsString('Imported made-up study', $this->call('reader', 'GET', '/library')->body);
        self::assertStringContainsString('Abel', $this->call('reader', 'GET', '/library/export?format=ris')->body);
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"library.import"', $audit);
    }

    private function importAs(?string $user, string $body, string $tags = ''): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/api/v1/library/import',
            query: $tags !== '' ? ['tags' => $tags] : [],
            cookies: $user === null ? [] : ['reporion' => $this->cookie($user)],
            body: $body,
        ));
    }

    public function testTheExportIsWhatTheCallerMayReadAndNothingElse(): void
    {
        $source = $this->add('editor', self::ARTICLE, 'study.md', ['grading']);
        $key = $source['key'];

        // Anonymous: nothing, as a page that is not there
        self::assertSame(404, $this->call(null, 'GET', '/library/export?format=bibtex')->status);
        // A reader of the library: the record, in each format
        $bib = $this->call('reader', 'GET', '/library/export?format=bibtex');
        self::assertSame(200, $bib->status);
        self::assertStringContainsString('@article{' . $key . ',', $bib->body);
        self::assertStringContainsString('A made-up study of test lesions', $bib->body);
        self::assertStringContainsString('attachment; filename="library.bib"', $bib->headers['Content-Disposition']);
        self::assertSame('nosniff', $bib->headers['X-Content-Type-Options']);
        self::assertStringContainsString('TY  - JOUR', $this->call('reader', 'GET', '/library/export?format=ris')->body);
        self::assertSame($key, json_decode($this->call('reader', 'GET', '/library/export?format=csl')->body, true)[0]['id']);
        self::assertStringContainsString('A made-up study of test lesions', $this->call('reader', 'GET', '/library/export?format=text&style=apa')->body);
        self::assertSame(422, $this->call('reader', 'GET', '/library/export?format=pdf')->status);
        // Narrowed by a tag or a key
        self::assertStringContainsString('@article', $this->call('reader', 'GET', '/library/export?format=bibtex&tag=grading')->body);
        self::assertSame('', trim($this->call('reader', 'GET', '/library/export?format=bibtex&tag=none')->body));
        self::assertSame('', trim($this->call('reader', 'GET', '/library/export?format=bibtex&key=other2020')->body));
        // Signed in with no grant on the library: an empty list, not the records
        self::assertSame('', trim($this->call('other', 'GET', '/library/export?format=bibtex')->body));
        self::assertSame('', trim($this->call('other', 'GET', '/library/export?format=bibtex&key=' . $key)->body));
    }

    public function testANotebooksExportIsTheSourcesItCitesAndNeedsTheNotebookToBeReadable(): void
    {
        $key = $this->add('editor', self::ARTICLE, 'study.md')['key'];
        $this->createPage('wiki:notebook', 'private', 'A notebook', "Cites [@{$key}] and [@gone2000].\n");

        $export = $this->call('other', 'GET', '/library/export?format=bibtex&page=wiki:notebook');
        self::assertSame(200, $export->status);
        self::assertStringContainsString('@article{' . $key . ',', $export->body, 'its References section prints the record for this reader too');
        self::assertStringNotContainsString('gone2000', $export->body);
        self::assertSame(404, $this->call('reader', 'GET', '/library/export?format=bibtex&page=wiki:notebook')->status, 'no grant on the notebook');
        self::assertSame(404, $this->call(null, 'GET', '/library/export?format=bibtex&page=wiki:notebook')->status);
        self::assertSame(404, $this->call('owner', 'GET', '/library/export?format=bibtex&page=wiki:missing')->status);

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"library.export"', $audit);
        self::assertStringNotContainsString('notebook', substr($audit, (int) strpos($audit, 'library.export')), 'the page path is not logged');
    }

    /**
     * @param list<string> $tags
     *
     * @return array<string, mixed> the confirmed page
     */
    private function add(string $user, string $bytes, string $name, array $tags = []): array
    {
        $proposal = json_decode($this->upload($user, $bytes, $name)->body, true);

        return json_decode($this->call($user, 'POST', '/api/v1/library', ['token' => $proposal['token'], 'csl' => $proposal['csl'], 'tags' => $tags])->body, true);
    }

    private function upload(?string $user, string $bytes, string $name): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/api/v1/library/uploads',
            query: ['name' => $name],
            cookies: $user === null ? [] : ['reporion' => $this->cookie($user)],
            body: $bytes,
        ));
    }

    /** @param array<string, mixed> $body */
    private function call(?string $user, string $method, string $url, array $body = []): Response
    {
        $cookies = $user !== null ? ['reporion' => $this->cookie($user)] : [];

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return Kernel::boot($this->config)->handle(new Request($method, (string) parse_url($url, PHP_URL_PATH), query: array_map('strval', $query), cookies: $cookies, body: $body !== [] ? (string) json_encode($body) : ''));
    }

    private function cookie(string $user): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);
    }
}
