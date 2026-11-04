<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use Reporion\Audit\AuditLog;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Analyses;
use Reporion\Service\Library\Citations;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\Merge;
use Reporion\Service\PageMoves;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** The sources are made up (docs/library.md) */
final class MergeTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;
    private Library $library;
    private Merge $merge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $this->library = new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot));
        $audit = new AuditLog($this->dataRoot . '/audit');
        $analyses = new Analyses($this->storage, $this->index);
        $this->merge = new Merge($this->library, $this->storage, $audit, new PageMoves($this->storage, $audit, 'library', $analyses), $analyses);
    }

    private static function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }

    private function source(string $key, array $csl, array $extra = [], string $body = ''): void
    {
        $this->storage->create('library:' . $key, ['title' => $csl['title'], 'visibility' => 'private', 'csl' => $csl] + $extra, $body, 'owner');
    }

    public function testTheOtherIsFoldedIntoTheSurvivorAndEveryCitationFollows(): void
    {
        $this->source('keep2020', ['title' => 'A made-up study of lesions', 'DOI' => '10.9999/x', 'issued' => ['date-parts' => [[2020]]]], ['tags' => ['mri']]);
        $this->source('dup2020', ['title' => 'A made-up study of lesions', 'DOI' => '10.9999/x', 'PMID' => '123', 'container-title' => 'J Synth', 'abstract' => str_repeat('word ', 50), 'issued' => ['date-parts' => [[2020]]]],
            ['tags' => ['lesions', 'mri'], 'original' => ['sha256' => str_repeat('a', 64), 'ext' => 'pdf', 'bytes' => 10, 'name' => 'x.pdf', 'pages' => 2, 'text' => true]], "The text of the article.\n");
        $this->storage->create('library:dup2020:source-points', ['title' => 'Points', 'visibility' => 'private', 'analysis' => ['prompt' => 'source-points', 'of' => 'x']], 'Points.', 'owner');
        $this->storage->create('wiki:note', ['title' => 'N', 'visibility' => 'private'], "See [@dup2020, p. 2] and [@keep2020; @dup2020].\n", 'owner');
        $this->storage->create('reports:mri:site:signed', ['title' => 'R', 'visibility' => 'private'], "Prior art [@dup2020].\n", 'owner');
        $this->storage->sign('reports:mri:site:signed', 'dr.a', []);
        $dupPid = $this->storage->read('library:dup2020')->pid;

        $done = $this->merge->merge('library:keep2020', 'library:dup2020', self::owner(), 'owner');

        $keep = $this->storage->read('library:keep2020');
        self::assertSame('123', $keep->frontmatter['csl']['PMID'], 'the fields it lacked');
        self::assertSame('J Synth', $keep->frontmatter['csl']['container-title']);
        self::assertSame(['mri', 'lesions'], $keep->frontmatter['tags']);
        self::assertSame(str_repeat('a', 64), $keep->frontmatter['original']['sha256'], 'the file it lacked');
        self::assertSame("The text of the article.\n", $keep->body);
        self::assertSame(1, $done['relinked']);
        self::assertSame(1, $done['left_signed']);
        self::assertSame("See [@keep2020, p. 2] and [@keep2020; @keep2020].\n", $this->storage->read('wiki:note')->body);

        // The other is in the trash, its analysis with it, and its old path leads to the survivor
        try {
            $this->storage->read('library:dup2020');
            self::fail('the other is gone');
        } catch (PageNotFoundException) {
        }
        self::assertSame('library:keep2020', $this->storage->redirectTarget('library:dup2020'));
        self::assertSame(1, $done['subpages']);
        self::assertContains($dupPid, array_column($this->storage->trash(), 'pid'));
        // A signed page keeps its [@dup2020]: it still finds the record, through the redirect
        self::assertSame("Prior art [@dup2020].\n", $this->storage->read('reports:mri:site:signed')->body);
        self::assertSame('A made-up study of lesions', (new Citations($this->storage, 'library'))->record('dup2020')['title']);
    }

    public function testAbstractAndRevisionAreMadeOnlyWhenSomethingChangedAndNothingIsLost(): void
    {
        $csl = ['title' => 'A made-up study of lesions', 'DOI' => '10.9999/x', 'issued' => ['date-parts' => [[2020]]]];
        $this->source('keep2020', $csl + ['container-title' => 'J Synth', 'PMID' => '1']);
        $this->source('dup2020', $csl);
        $rev = $this->storage->read('library:keep2020')->rev;

        $done = $this->merge->merge('library:keep2020', 'library:dup2020', self::owner(), 'owner');

        self::assertSame([], $done['changed']);
        self::assertSame($rev, $this->storage->read('library:keep2020')->rev, 'nothing to add: no revision');
        self::assertSame('library:keep2020', $this->storage->redirectTarget('library:dup2020'));
    }

    public function testTheGraverNoticeSurvives(): void
    {
        $csl = ['title' => 'A made-up study of lesions', 'DOI' => '10.9999/x', 'issued' => ['date-parts' => [[2020]]]];
        $this->source('keep2020', $csl, ['notice' => ['status' => 'corrected', 'items' => [['type' => 'correction']], 'found' => '2026-11-04', 'from' => 'pubmed']]);
        $this->source('dup2020', $csl, ['notice' => ['status' => 'retracted', 'items' => [['type' => 'retraction']], 'found' => '2026-11-04', 'from' => 'pubmed']]);

        $this->merge->merge('library:keep2020', 'library:dup2020', self::owner(), 'owner');

        self::assertSame('retracted', $this->storage->read('library:keep2020')->frontmatter['notice']['status']);
    }

    public function testBothMustBeSourcesTheCallerWritesAndNotTheSame(): void
    {
        $csl = ['title' => 'A made-up study of lesions', 'issued' => ['date-parts' => [[2020]]]];
        $this->source('a2020', $csl);
        $this->source('b2020', $csl);
        $this->storage->create('wiki:plain', ['title' => 'Plain', 'visibility' => 'private'], 'x', 'owner');
        $editor = new User('ed', 'x', false, [new Grant('library:a2020', GrantRole::Editor)], false, 'now', 'now');

        foreach ([['library:a2020', 'library:a2020'], ['library:a2020', 'wiki:plain']] as [$into, $from]) {
            try {
                $this->merge->merge($into, $from, self::owner(), 'owner');
                self::fail('refused');
            } catch (LibraryException $e) {
                self::assertSame(LibraryException::INVALID, $e->reason);
            }
        }
        $this->expectException(PageNotFoundException::class);
        $this->merge->merge('library:a2020', 'library:b2020', $editor, 'ed');
    }

    public function testDuplicatesAreFoundBySharedIdentifierFileOrTitleAndYear(): void
    {
        $owner = self::owner();
        $this->source('a2020', ['title' => 'A made-up study of lesions', 'DOI' => '10.9999/X', 'issued' => ['date-parts' => [[2020]]]]);
        $this->source('b2020', ['title' => 'Another title altogether', 'DOI' => 'https://doi.org/10.9999/x', 'issued' => ['date-parts' => [[2021]]]]);
        $this->source('c2019', ['title' => 'Imaging in Made-up Lesions', 'issued' => ['date-parts' => [[2019]]]]);
        $this->source('d2019', ['title' => 'imaging in made-up lesions', 'issued' => ['date-parts' => [[2019]]]]);
        $this->source('e2019', ['title' => 'Imaging in made-up lesions', 'issued' => ['date-parts' => [[2018]]]]);
        $this->source('f2022', ['title' => 'Unrelated and alone', 'issued' => ['date-parts' => [[2022]]]]);
        $this->storage->create('library:a2020:abstract', ['title' => 'A', 'visibility' => 'private', 'csl' => ['title' => 'A made-up study of lesions', 'DOI' => '10.9999/x']], 'x', 'owner');

        $groups = $this->library->duplicateGroups($owner);

        self::assertCount(2, $groups);
        $keys = array_map(static function (array $g): array {
            $keys = array_column($g['sources'], 'key');
            sort($keys);

            return $keys;
        }, $groups);
        self::assertSame([['a2020', 'b2020'], ['c2019', 'd2019']], $keys);
        self::assertSame(['doi'], $groups[0]['by']);
        self::assertSame(['title'], $groups[1]['by']);
        self::assertSame([], $this->library->duplicateGroups(new User('x', 'x', false, [], false, 'now', 'now')), 'a caller who reads nothing sees nothing');
    }
}
