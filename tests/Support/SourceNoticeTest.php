<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\SourceNotice;

final class SourceNoticeTest extends TestCase
{
    /** The shape CrossRef gives `updated-by` (checked against a real retracted paper, 2026-11-04) */
    private const UPDATED_BY = [
        ['DOI' => '10.9999/CORR.2004', 'type' => 'correction', 'label' => 'Correction', 'source' => 'retraction-watch', 'updated' => ['date-parts' => [[2004, 3, 6]]]],
        ['DOI' => '10.9999/RETR.2010', 'type' => 'retraction', 'label' => 'Retraction', 'source' => 'retraction-watch', 'updated' => ['date-parts' => [[2010, 2, 6]]]],
        ['DOI' => '10.9999/NEWER', 'type' => 'new_version', 'label' => 'New version', 'updated' => ['date-parts' => [[2012]]]],
    ];

    public function testCrossrefsUpdatesAreFoldedToThreeTypesAndOtherKindsIgnored(): void
    {
        self::assertSame([
            ['type' => 'correction', 'date' => '2004-03-06', 'doi' => '10.9999/corr.2004'],
            ['type' => 'retraction', 'date' => '2010-02-06', 'doi' => '10.9999/retr.2010'],
        ], SourceNotice::fromCrossref(self::UPDATED_BY));
        self::assertSame([], SourceNotice::fromCrossref(null));
        self::assertSame([['type' => 'concern', 'date' => '2020-05']], SourceNotice::fromCrossref([['type' => 'expression_of_concern', 'updated' => ['date-parts' => [[2020, 5]]]]]));
    }

    public function testPubmedsMarksAreFolded(): void
    {
        self::assertSame([['type' => 'retraction'], ['type' => 'concern'], ['type' => 'correction']], SourceNotice::fromPubmed(
            ['Journal Article', 'Retracted Publication'],
            ['CommentIn', 'RetractionIn', 'ExpressionOfConcernIn', 'ErratumIn', 'RetractionIn'],
        ));
        self::assertSame([], SourceNotice::fromPubmed(['Journal Article'], ['CommentIn']));
    }

    public function testTheWorstItemSetsTheStatusAndPubmedsBareItemOfAGivenTypeAddsNothing(): void
    {
        $items = [...SourceNotice::fromCrossref(self::UPDATED_BY), ...SourceNotice::fromPubmed(['Retracted Publication'], ['ErratumIn'])];

        $notice = SourceNotice::build($items, ['crossref', 'pubmed'], '2026-11-04');

        self::assertSame('retracted', $notice['status']);
        self::assertSame(['retraction', 'correction'], array_column($notice['items'], 'type'), 'the detailed ones; the bare PubMed duplicates dropped');
        self::assertSame('crossref+pubmed', $notice['from']);
        self::assertNull(SourceNotice::build([], ['crossref'], '2026-11-04'));
        self::assertSame('concern', SourceNotice::build([['type' => 'concern'], ['type' => 'correction']], ['pubmed'], '2026-11-04')['status']);
        self::assertSame('corrected', SourceNotice::build([['type' => 'correction']], ['pubmed'], '2026-11-04')['status']);
    }

    public function testAStoredNoticeIsReadSafelyAndComparedWithoutItsDate(): void
    {
        $notice = SourceNotice::build([['type' => 'retraction', 'date' => '2010-02-06']], ['crossref'], '2026-11-04');
        $later = ['found' => '2027-01-01'] + $notice;

        self::assertTrue(SourceNotice::same($notice, $later));
        self::assertTrue(SourceNotice::same(null, null));
        self::assertFalse(SourceNotice::same($notice, null));
        self::assertSame($notice, SourceNotice::clean($notice));
        self::assertNull(SourceNotice::clean(['status' => 'bogus', 'items' => []]));
        self::assertNull(SourceNotice::clean('retracted'));
        self::assertSame([], SourceNotice::clean(['status' => 'retracted', 'items' => [['type' => 'x'], 'nope']])['items']);
    }
}
