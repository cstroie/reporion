<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Audit\AuditLog;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\FrontmatterFields;
use Reporion\Service\PageMoves;
use Reporion\Service\References;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Roadmap phase 25: a template's `references:` reach every report made
 * from it, per exam, filtered by what the caller can read; the Details
 * panel edits the list; a moved reference page is followed.
 */
final class ReferencesTest extends StorageTestCase
{
    private Sqlite $index;
    private FlatFile $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        $this->storage->create('radiology:spine:tlics', ['title' => 'TLICS', 'visibility' => 'private', 'summary' => 'Thoracolumbar injury score.'], "x\n", 'owner');
        $this->storage->create('radiology:spine:ao', ['title' => 'AO Spine', 'visibility' => 'private'], "x\n", 'owner');
        $this->storage->create('teaching:secret', ['title' => 'Secret', 'visibility' => 'private'], "x\n", 'owner');
        $this->storage->create('templates:ct:coloana', ['title' => 'CT coloană', 'visibility' => 'private', 'references' => ['radiology:spine:tlics', 'teaching:secret', 'radiology:gone', 'radiology:spine:tlics']], "x\n", 'owner');
        $this->storage->create('templates:ct:torace', ['title' => 'CT torace', 'visibility' => 'private', 'references' => ['radiology:spine:ao']], "x\n", 'owner');
    }

    public function testEachExamGetsItsTemplatesReadablePages(): void
    {
        $single = (new References($this->storage, $this->index))->forReport(['exam_title' => 'CT coloană', 'template' => 'templates:ct:coloana'], $this->owner());
        self::assertSame([['exam' => 0, 'title' => 'CT coloană', 'template' => 'templates:ct:coloana', 'pages' => [
            ['path' => 'radiology:spine:tlics', 'title' => 'TLICS', 'summary' => 'Thoracolumbar injury score.'],
            ['path' => 'teaching:secret', 'title' => 'Secret', 'summary' => ''],
        ]]], $single, 'a missing page left out, a repeat once');

        $multi = (new References($this->storage, $this->index))->forReport([
            'template' => 'templates:ct:coloana',
            'exams' => [['title' => 'CT coloană'], ['title' => 'CT torace', 'template' => 'templates:ct:torace'], ['title' => 'CT cap']],
        ], $this->owner());
        self::assertSame([0, 1], array_column($multi, 'exam'), 'the report\'s template stands for the first exam');
        self::assertSame('radiology:spine:ao', $multi[1]['pages'][0]['path']);
    }

    public function testWhatTheCallerCannotReadIsLeftOutSilently(): void
    {
        $editor = new User('ed', 'x', false, [new Grant('templates', GrantRole::Viewer), new Grant('radiology', GrantRole::Viewer)], true, 'now', 'now');
        $pages = (new References($this->storage, $this->index))->forReport(['template' => 'templates:ct:coloana'], $editor)[0]['pages'];
        self::assertSame(['radiology:spine:tlics'], array_column($pages, 'path'));

        $noTemplates = new User('ed', 'x', false, [new Grant('radiology', GrantRole::Viewer)], true, 'now', 'now');
        self::assertSame([], (new References($this->storage, $this->index))->forReport(['template' => 'templates:ct:coloana'], $noTemplates), 'an unreadable template gives nothing');

        self::assertSame([], (new References($this->storage, $this->index))->forReport(['template' => 'radiology:spine:ao'], $this->owner()), 'only templates: pages are templates');
    }

    public function testParseKeepsCleanPathsOnly(): void
    {
        self::assertSame(['radiology:a', 'radiology:b'], References::parse([' radiology:a ', '/radiology:b', 'radiology:a', 'no-colon', '<script>', 42, ['x']]));
        self::assertSame(['radiology:a'], References::parse('radiology:a'));
        self::assertCount(References::MAX, References::parse(array_map(static fn (int $i): string => "radiology:p$i", range(1, 50))));
    }

    public function testTheDetailsPanelEditsATemplatesList(): void
    {
        $fields = new FrontmatterFields(new Loader(\dirname(__DIR__, 2) . '/conf/schema'), $this->index, [], ['radiology']);
        $template = $this->storage->read('templates:ct:coloana');

        $field = array_values(array_filter($fields->forPage('templates:ct:coloana', $template->frontmatter, $this->owner())['fields'], static fn (array $f): bool => $f['key'] === 'references'))[0];
        self::assertSame('pages', $field['widget']);
        self::assertSame(['radiology:spine:tlics', 'teaching:secret', 'radiology:gone'], $field['value']);
        self::assertSame(['radiology:gone'], $field['missing']);
        self::assertSame(['radiology:spine:ao'], array_column($field['options'], 'value'), 'offered: the reference namespaces, less what is listed');

        // Untick teaching:secret, keep radiology:gone, add one inside and one outside the namespaces
        $changes = $fields->changesFrom(['references' => ['radiology:spine:tlics', 'radiology:gone', 'radiology:spine:ao', 'teaching:other', '']], ['references'], $template->frontmatter, 'templates:ct:coloana');
        self::assertSame(['references' => ['radiology:spine:tlics', 'radiology:gone', 'radiology:spine:ao']], $changes);
        self::assertSame(['references' => null], $fields->changesFrom(['references' => ['']], ['references'], $template->frontmatter, 'templates:ct:coloana'));

        $snippet = $fields->forPage('templates:snippets:ct:x', [], $this->owner());
        self::assertNotContains('references', array_column($snippet['fields'], 'key'), 'a snippet is not a template');
        self::assertNotContains('references', array_column($fields->forPage('docs:x', [], $this->owner())['fields'], 'key'));
    }

    public function testAMovedReferencePageIsFollowed(): void
    {
        (new PageMoves($this->storage, new AuditLog($this->dataRoot . '/audit')))->move('radiology:spine:ao', 'radiology:spine:ao-spine', 'owner');

        self::assertSame(['radiology:spine:ao-spine'], $this->storage->read('templates:ct:torace')->frontmatter['references']);
        self::assertSame(['title' => 'X', 'references' => ['a:b']], PageMoves::rewriteReferences(['title' => 'X', 'references' => ['a:b']], ['c:d' => 'e:f']));
    }

    private function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }
}
