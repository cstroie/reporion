<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin\Groff;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Groff\Ms\Document;
use Reporion\Plugin\Loader;
use Reporion\Storage\PageRecord;

/** The whole ms file of an ordinary page, built from made-up data (invariant 10). */
final class DocumentTest extends TestCase
{
    private const PREAMBLE = ".nr LL 17c\n.ds FAM {font}\n.so /etc/passwd\n\n.\\\" a comment\n.sy id";

    protected function setUp(): void
    {
        Loader::registerAutoload(\dirname(__DIR__, 3) . '/plugins', 'groff');
        reporion_plugin_strings(require \dirname(__DIR__, 3) . '/plugins/groff/lang/en.php');
    }

    /** @param array<string, mixed> $fm */
    private function record(array $fm = [], string $path = 'docs:procedures:contrast'): PageRecord
    {
        return new PageRecord(
            'PID', $path, 3, 'draft', 'private',
            $fm + ['title' => 'Contrast procedure', 'summary' => 'How the contrast is given.'],
            "# Contrast procedure\n\nText.\n",
            [['n' => 1, 'ts' => '2026-09-20T09:00:00+03:00', 'by' => 'ana'], ['n' => 3, 'ts' => '2026-10-01T10:30:00+03:00', 'by' => 'bob']],
            [],
        );
    }

    /** @return list<string> */
    private function lines(?PageRecord $record = null): array
    {
        return explode("\n", rtrim(Document::build($record ?? $this->record(), ['.PP', 'Text.'], self::PREAMBLE, 'BT'), "\n"));
    }

    public function testTheFileFollowsTheHouseStyle(): void
    {
        $lines = $this->lines();

        self::assertSame('.\\" Contrast procedure — exported from Reporion, rev 3', $lines[0]);
        self::assertSame('.\\" -*- mode: troff; coding: utf-8 -*-', $lines[1]);
        self::assertSame(['.TL', 'Contrast procedure', '.AU', 'bob', '.AI', 'rev 3 · 2026-10-01', '.AB no', 'How the contrast is given.', '.AE', '.PP', 'Text.'], \array_slice($lines, (int) array_search('.TL', $lines, true)));
    }

    public function testThereIsNoPatientBlockAndNoSignature(): void
    {
        $source = implode("\n", $this->lines());

        foreach (['Pacient', 'Parafa', 'semnat', 'Buletin', 'Indicație'] as $word) {
            self::assertStringNotContainsString($word, $source);
        }
    }

    public function testThePathIsNotInTheFile(): void
    {
        $source = implode("\n", $this->lines());

        self::assertStringNotContainsString('docs:procedures', $source);
        self::assertStringNotContainsString('procedures', $source);
    }

    public function testWithoutATitleThePageIsTypesetUnderTheEndOfItsAddress(): void
    {
        $lines = $this->lines($this->record(['title' => '', 'summary' => '']));

        self::assertSame(['.TL', 'contrast', '.AU', 'bob', '.AI', 'rev 3 · 2026-10-01', '.PP', 'Text.'], \array_slice($lines, (int) array_search('.TL', $lines, true)));
    }

    public function testTheTitleHeadingIsNotRepeatedInTheBody(): void
    {
        self::assertSame("Text.\n", Document::withoutTitleHeading("# Contrast  procedure \n\nText.\n", 'Contrast procedure'));
        self::assertSame("# Other\n\nText.\n", Document::withoutTitleHeading("# Other\n\nText.\n", 'Contrast procedure'));
        self::assertSame("## Contrast procedure\n", Document::withoutTitleHeading("## Contrast procedure\n", 'Contrast procedure'), 'only a first-level heading');
    }

    public function testThePreambleIsCheckedAgainAndTheFontFilled(): void
    {
        $lines = $this->lines();

        self::assertContains('.nr LL 17c', $lines);
        self::assertContains('.ds FAM BT', $lines, '{font} is the setting');
        self::assertContains('.\\" a comment', $lines);
        self::assertNotContains('.so /etc/passwd', $lines, 'a request that reads a file never gets in');
        self::assertNotContains('.sy id', $lines);
        self::assertSame(2, count(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '.\\" (a preamble line was left out'))));
    }

    public function testThePluginManifestAndThePhpGuardUseTheSamePattern(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/plugins/groff/plugin.json'), true);

        self::assertSame(Document::PREAMBLE_LINE, $manifest['settings']['preamble']['line_pattern']);
        $default = Document::preambleLines($manifest['settings']['preamble']['default'], 'T');
        self::assertSame(['.nr PO 2c', '.nr LL 17c', '.nr PS 11p', '.nr VS 14p', '.nr PD 4p', '.ds FAM T'], $default, 'the shipped preamble passes its own guard');
    }

    public function testBothMenuEntriesAreForOrdinaryPages(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/plugins/groff/plugin.json'), true);

        self::assertSame(['page', 'page'], array_column($manifest['ui']['export_action'], 'for'));
    }
}
