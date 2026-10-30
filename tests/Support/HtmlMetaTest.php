<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\HtmlMeta;

/** A web page's own record (docs/library.md, phase 7): the pages are made up */
final class HtmlMetaTest extends TestCase
{
    public function testAJournalPagesCitationTags(): void
    {
        $html = '<html><head><title>Journal site | Article</title>'
            . '<meta name="citation_title" content="Grading test lesions: a made-up study">'
            . '<meta name="citation_author" content="Abel, Ann"><meta name="citation_author" content="Bob van Baker">'
            . "<meta content='2020/03/07' name='citation_publication_date'>"
            . '<meta name="citation_journal_title" content="J Synth Imaging"><meta name="citation_volume" content="3">'
            . '<meta name="citation_firstpage" content="11"><meta name="citation_lastpage" content="14">'
            . '<meta name="citation_doi" content="doi:10.1234/ABC.1"><meta name="citation_pmid" content="1234567">'
            . '</head><body><nav>Home Journals About</nav></body></html>';

        self::assertSame([
            'title' => 'Grading test lesions: a made-up study',
            'author' => [['family' => 'Abel', 'given' => 'Ann'], ['family' => 'Baker', 'given' => 'Bob van']],
            'issued' => ['date-parts' => [[2020, 3, 7]]],
            'container-title' => 'J Synth Imaging',
            'volume' => '3',
            'PMID' => '1234567',
            'page' => '11-14',
            'DOI' => '10.1234/abc.1',
        ], HtmlMeta::csl($html));
    }

    public function testAnOrdinaryPageGivesItsTitle(): void
    {
        self::assertSame(['title' => 'Fazekas scale & more'], HtmlMeta::csl("<html><head><title>\n  Fazekas scale &amp; more </title></head></html>"));
        self::assertSame(['title' => 'Shared title', 'container-title' => 'A site'], HtmlMeta::csl('<meta property="og:title" content="Shared title"><meta property="og:site_name" content="A site"><title>Other</title>'));
        self::assertSame([], HtmlMeta::csl('plain text, no head'));
    }
}
