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
use Reporion\Service\Render;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Roadmap phase 25: a template's `reference:` page reaches every report
 * made from it, once per page, filtered by what the caller can read; the
 * Details panel picks it; a moved reference page is followed.
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
        $this->storage->create('radiology:spine:tlics', ['title' => 'TLICS', 'visibility' => 'private'], "## Grade\n\nThoracolumbar injury score.\n", 'owner');
        $this->storage->create('radiology:spine:ao', ['title' => 'AO Spine', 'visibility' => 'private'], "x\n", 'owner');
        $this->storage->create('teaching:secret', ['title' => 'Secret', 'visibility' => 'private'], "x\n", 'owner');
        $this->storage->create('templates:ct:coloana', ['title' => 'CT coloană', 'visibility' => 'private', 'reference' => 'radiology:spine:tlics'], "x\n", 'owner');
        $this->storage->create('templates:ct:coloana-2', ['title' => 'CT coloană 2', 'visibility' => 'private', 'reference' => 'radiology:spine:tlics'], "x\n", 'owner');
        $this->storage->create('templates:ct:torace', ['title' => 'CT torace', 'visibility' => 'private', 'reference' => 'radiology:spine:ao'], "x\n", 'owner');
        $this->storage->create('templates:ct:secret', ['title' => 'Secret', 'visibility' => 'private', 'reference' => 'teaching:secret'], "x\n", 'owner');
        $this->storage->create('templates:ct:gone', ['title' => 'Gone', 'visibility' => 'private', 'reference' => 'radiology:gone'], "x\n", 'owner');
    }

    public function testEachExamGetsItsTemplatesPageOnce(): void
    {
        $references = new References($this->storage, $this->index, new Render());
        $single = $references->forReport(['exam_title' => 'CT coloană', 'template' => 'templates:ct:coloana'], $this->owner());
        self::assertSame('radiology:spine:tlics', $single[0]['path']);
        self::assertSame('TLICS', $single[0]['title']);
        self::assertSame(['CT coloană'], $single[0]['exams']);
        self::assertStringContainsString('Grade', $single[0]['html'], 'the page rendered for the panel');
        self::assertStringNotContainsString(' id="', $single[0]['html'], 'no anchors to collide with the report\'s');

        $multi = $references->forReport([
            'template' => 'templates:ct:coloana',
            'exams' => [['title' => 'CT coloană'], ['title' => 'CT dorsală', 'template' => 'templates:ct:coloana-2'], ['title' => 'CT torace', 'template' => 'templates:ct:torace'], ['title' => 'CT cap']],
        ], $this->owner());
        self::assertSame(['radiology:spine:tlics', 'radiology:spine:ao'], array_column($multi, 'path'), 'one page shared by two exams is listed once');
        self::assertSame(['CT coloană', 'CT dorsală'], $multi[0]['exams']);
    }

    public function testWhatTheCallerCannotReadIsLeftOutSilently(): void
    {
        $references = new References($this->storage, $this->index);
        $viewer = new User('ed', 'x', false, [new Grant('templates', GrantRole::Viewer), new Grant('radiology', GrantRole::Viewer)], true, 'now', 'now');
        self::assertSame([], $references->forReport(['template' => 'templates:ct:secret'], $viewer));
        self::assertSame([], $references->forReport(['template' => 'templates:ct:gone'], $this->owner()), 'a page that does not exist');

        $noTemplates = new User('ed', 'x', false, [new Grant('radiology', GrantRole::Viewer)], true, 'now', 'now');
        self::assertSame([], $references->forReport(['template' => 'templates:ct:coloana'], $noTemplates), 'an unreadable template gives nothing');
        self::assertSame([], $references->forReport(['template' => 'radiology:spine:ao'], $this->owner()), 'only templates: pages are templates');
    }

    public function testParseKeepsACleanPathOnly(): void
    {
        self::assertSame('radiology:a', References::parse(' /radiology:a '));
        self::assertNull(References::parse('no-colon'));
        self::assertNull(References::parse('<script>'));
        self::assertNull(References::parse(['radiology:a']), 'one page, not a list');
    }

    public function testTheDetailsPanelPicksOnePage(): void
    {
        $fields = new FrontmatterFields(new Loader(\dirname(__DIR__, 2) . '/conf/schema'), $this->index, [], ['radiology']);
        $owner = $this->owner();
        $field = static fn (array $fm): array => array_values(array_filter($fields->forPage('templates:ct:x', $fm, $owner)['fields'], static fn (array $f): bool => $f['key'] === 'reference'))[0];

        $set = $field(['reference' => 'radiology:spine:tlics']);
        self::assertSame('select', $set['widget']);
        self::assertSame('radiology:spine:tlics', $set['value']);
        self::assertSame(['radiology:spine:ao', 'radiology:spine:tlics'], array_column($set['options'], 'value'), 'offered: the reference namespaces');

        $gone = $field(['reference' => 'radiology:gone']);
        self::assertSame('radiology:gone', $gone['options'][0]['value'], 'the current one stays selectable');
        self::assertStringContainsString('not found', $gone['options'][0]['label']);

        $current = ['reference' => 'teaching:secret'];
        self::assertSame(['reference' => 'radiology:spine:ao'], $fields->changesFrom(['reference' => 'radiology:spine:ao'], ['reference'], $current, 'templates:ct:x'));
        self::assertSame(['reference' => 'teaching:secret'], $fields->changesFrom(['reference' => 'teaching:secret'], ['reference'], $current, 'templates:ct:x'), 'the one already set is kept');
        self::assertSame(['reference' => 'teaching:secret'], $fields->changesFrom(['reference' => 'teaching:other'], ['reference'], $current, 'templates:ct:x'), 'a new one outside the namespaces is refused');
        self::assertSame(['reference' => null], $fields->changesFrom(['reference' => ''], ['reference'], $current, 'templates:ct:x'));

        self::assertNotContains('reference', array_column($fields->forPage('templates:snippets:ct:x', [], null)['fields'], 'key'), 'a snippet is not a template');
        self::assertNotContains('reference', array_column($fields->forPage('docs:x', [], null)['fields'], 'key'));
    }

    public function testAMovedReferencePageIsFollowed(): void
    {
        (new PageMoves($this->storage, new AuditLog($this->dataRoot . '/audit')))->move('radiology:spine:ao', 'radiology:spine:ao-spine', 'owner');

        self::assertSame('radiology:spine:ao-spine', $this->storage->read('templates:ct:torace')->frontmatter['reference']);
        self::assertSame(['title' => 'X', 'reference' => 'a:b'], PageMoves::rewriteReferences(['title' => 'X', 'reference' => 'a:b'], ['c:d' => 'e:f']));
    }

    private function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }
}
