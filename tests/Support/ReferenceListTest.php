<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\ReferenceList;

/** What a library source cites (docs/library.md, phase 7): the references are made up */
final class ReferenceListTest extends TestCase
{
    private const RECORDS = [
        ['key' => 'abel2020', 'path' => 'library:abel2020', 'title' => 'Abel', 'csl' => ['title' => 'Grading white matter lesions in a synthetic cohort', 'DOI' => '10.1234/ABC.1', 'issued' => ['date-parts' => [[2020]]]]],
        ['key' => 'cole2019', 'path' => 'library:cole2019', 'title' => 'Cole', 'csl' => ['title' => 'Made-up staging of test tumours in children', 'PMID' => '1234567', 'issued' => ['date-parts' => [[2019]]]]],
        ['key' => 'dunn2018', 'path' => 'library:dunn2018', 'title' => 'Dunn', 'csl' => ['title' => 'Imaging of lesions in adults and children alike', 'issued' => ['date-parts' => [[2018]]]]],
        ['key' => 'short2017', 'path' => 'library:short2017', 'title' => 'Short', 'csl' => ['title' => 'MRI', 'issued' => ['date-parts' => [[2017]]]]],
    ];

    public function testTheReferenceListIsTheFenceUnderReferences(): void
    {
        $body = "Text.\n\n## References\n\n```text\n1. One.\n2. Two.\n```\n";

        self::assertSame("1. One.\n2. Two.", ReferenceList::of($body));
        self::assertSame('', ReferenceList::of("Text with no list.\n"));
    }

    public function testNumberedEntriesKeepTheirWrappedLines(): void
    {
        $entries = ReferenceList::entries("1. Abel A, Baker B. Grading white matter\nlesions in a synthetic cohort. 2020.\n[2] Cole C. Made-up staging. 2019.\n3) Dunn D. Something else entirely. 2018.");

        self::assertSame([
            'Abel A, Baker B. Grading white matter lesions in a synthetic cohort. 2020.',
            'Cole C. Made-up staging. 2019.',
            'Dunn D. Something else entirely. 2018.',
        ], $entries);
        self::assertSame(['Abel A. A first long entry here.', 'Baker B. A second long entry.'], ReferenceList::entries("Abel A. A first long\nentry here.\n\nBaker B. A second long entry."));
    }

    public function testEntriesAreMatchedByDoiPmidOrTitleAndYearOnly(): void
    {
        $matched = ReferenceList::match([
            'Abel A. Something else. J Test. 2020. doi:10.1234/abc.1.',
            'Cole C. A different title. PMID: 1234567',
            'Dunn D. Imaging of lesions in adults and children alike. J Test. 2018;3:1-2.',
            'Dunn D. Imaging of lesions in adults and children alike. J Test. 2021;3:1-2.',
            'Short S. MRI. 2017.',
            'Nobody N. Not in the library. 2015. https://doi.org/10.9999/none',
        ], self::RECORDS);

        self::assertSame(['doi', 'pmid', 'title', null, null, null], array_map(static fn (array $e): ?string => $e['match']['by'] ?? null, $matched));
        self::assertSame('abel2020', $matched[0]['match']['key'], 'a DOI ending the entry, its full stop not part of it');
        self::assertSame('10.9999/none', $matched[5]['doi']);
    }
}
