<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Library\Extracted;
use Reporion\Service\Library\Identify;

/** The articles and people here are made up (docs/library.md) */
final class IdentifyTest extends TestCase
{
    private const XMP = <<<'XML'
        <x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF>
        <rdf:Description prism:doi="ignored-attribute">
          <dc:title><rdf:Alt><rdf:li xml:lang="x-default">A made-up study of test lesions</rdf:li></rdf:Alt></dc:title>
          <dc:creator><rdf:Seq><rdf:li>Ann Nobody</rdf:li><rdf:li>Bob B. Nobody</rdf:li></rdf:Seq></dc:creator>
          <prism:doi>10.1234/JTI.2024.0001</prism:doi>
          <prism:publicationName>Journal of Synthetic Imaging</prism:publicationName>
          <prism:volume>3</prism:volume><prism:number>2</prism:number>
          <prism:startingPage>11</prism:startingPage><prism:endingPage>14</prism:endingPage>
          <prism:coverDate>2024-05-01</prism:coverDate>
        </rdf:Description></rdf:RDF></x:xmpmeta>
        XML;

    public function testTheXmpPacketGivesTheWholeRecord(): void
    {
        $proposal = Identify::propose(new Extracted('', '', 4, true, '', xmp: self::XMP));

        self::assertSame([
            'type' => 'article-journal',
            'DOI' => '10.1234/jti.2024.0001',
            'title' => 'A made-up study of test lesions',
            'author' => [['family' => 'Nobody', 'given' => 'Ann'], ['family' => 'Nobody', 'given' => 'Bob B.']],
            'container-title' => 'Journal of Synthetic Imaging',
            'volume' => '3',
            'issue' => '2',
            'page' => '11-14',
            'issued' => ['date-parts' => [[2024, 5, 1]]],
        ], $proposal['csl']);
        self::assertSame('xmp', $proposal['how']['title']);
    }

    public function testWithoutMetadataTheLayoutAndTextGiveTitleAuthorsDoiAndYear(): void
    {
        $lines = [
            ['size' => 9.0, 'top' => 20, 'text' => 'J Synth Imaging 2024;3:11-14'],
            ['size' => 22.0, 'top' => 80, 'text' => 'A made-up study of'],
            ['size' => 22.0, 'top' => 105, 'text' => 'test lesions'],
            ['size' => 11.0, 'top' => 140, 'text' => 'Ann Nobody1, Bob Nobody2* and Cleo van Nobody1'],
            ['size' => 9.0, 'top' => 160, 'text' => '1 Department of Imaginary Radiology'],
        ];
        $head = "J Synth Imaging 2024;3:11-14\nA made-up study of\ntest lesions\nhttps://doi.org/10.1234/jti.2024.0001\n";
        $proposal = Identify::propose(
            new Extracted('', '', 4, true, $head, info: ['Title' => 'Microsoft Word - draft7.docx', 'Author' => 'Adobe Acrobat'], lines: $lines),
        );

        self::assertSame('A made-up study of test lesions', $proposal['csl']['title']);
        self::assertSame('layout', $proposal['how']['title']);
        self::assertSame([
            ['family' => 'Nobody', 'given' => 'Ann'],
            ['family' => 'Nobody', 'given' => 'Bob'],
            ['family' => 'van Nobody', 'given' => 'Cleo'],
        ], $proposal['csl']['author']);
        self::assertSame('10.1234/jti.2024.0001', $proposal['csl']['DOI']);
        self::assertSame(['date-parts' => [[2024]]], $proposal['csl']['issued']);
    }

    public function testBylinesInTheirUsualShapes(): void
    {
        self::assertSame(
            [['family' => 'Nobody', 'given' => 'A. B.'], ['family' => 'Somebody', 'given' => 'C.']],
            Identify::authors('Nobody AB, Somebody C.'),
        );
        self::assertSame(
            [['family' => 'Nobody', 'given' => 'Ann'], ['family' => 'Somebody', 'given' => 'Bob']],
            Identify::authors('Nobody, Ann; Somebody, Bob'),
        );
        self::assertSame([], Identify::authors('Department of Imaginary Radiology, Test University'));
        self::assertSame([], Identify::authors('Received 3 March 2024'));
    }

    public function testATextFileIsTitledByItsFirstHeadingElseItsFirstLineNeverItsFileName(): void
    {
        self::assertSame('Grading made simple', Identify::propose(new Extracted("# Grading made simple\n\nText.\n", '', 1, true, ''))['csl']['title']);
        self::assertSame('Notes on grading lesions', Identify::propose(new Extracted("Notes on grading lesions\n\nText.\n", '', 1, true, ''))['csl']['title']);
        $untitled = Identify::propose(new Extracted("Text.\n", '', 1, true, ''));
        self::assertSame(['Untitled document', 'none'], [$untitled['csl']['title'], $untitled['how']['title']]);
    }

    public function testAnIsbnWithoutDoiIsABook(): void
    {
        $proposal = Identify::propose(new Extracted('', '', 300, true, 'ISBN 978-0-306-40615-7 © 2019'));

        self::assertSame('book', $proposal['csl']['type']);
        self::assertSame('9780306406157', $proposal['csl']['ISBN']);
        self::assertSame(['date-parts' => [[2019]]], $proposal['csl']['issued']);
    }
}
