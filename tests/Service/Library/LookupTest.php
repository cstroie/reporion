<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Library\Lookup;

/** The lookups answer from canned responses (nothing is sent anywhere); the records are made up */
final class LookupTest extends TestCase
{
    private const CROSSREF = <<<'JSON'
{"status":"ok","message":{"type":"journal-article","title":["A <i>made-up</i> study of test lesions"],"author":[{"given":"Ann","family":"Nobody"},{"name":"Synthetic Imaging Group"}],"container-title":["Journal of Synthetic Imaging"],"short-container-title":["J Synth Imaging"],"volume":"3","issue":"2","page":"11-14","publisher":"Nowhere Press","issued":{"date-parts":[[2024,5,1]]},"ISSN":["0000-0000"],"abstract":"<jats:p>First part.</jats:p><jats:p>Second &amp; last.</jats:p>"}}
JSON;

    private const PUBMED = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE PubmedArticleSet PUBLIC "-//NLM//DTD PubMedArticle, 1st January 2019//EN" "https://dtd.nlm.nih.gov/ncbi/pubmed/out/pubmed_190101.dtd">
<PubmedArticleSet><PubmedArticle><MedlineCitation><PMID>12345678</PMID><Article>
<Journal><ISSN>0000-0000</ISSN><JournalIssue><Volume>3</Volume><Issue>2</Issue><PubDate><Year>2024</Year></PubDate></JournalIssue><Title>Journal of Synthetic Imaging</Title><ISOAbbreviation>J Synth Imaging</ISOAbbreviation></Journal>
<ArticleTitle>A made-up study of test lesions.</ArticleTitle><Pagination><MedlinePgn>11-4</MedlinePgn></Pagination>
<Abstract><AbstractText Label="BACKGROUND">Why.</AbstractText><AbstractText Label="RESULTS">What.</AbstractText></Abstract>
<AuthorList><Author><LastName>Nobody</LastName><ForeName>Ann</ForeName><Initials>A</Initials></Author><Author><CollectiveName>Synthetic Imaging Group</CollectiveName></Author></AuthorList>
</Article><MeshHeadingList><MeshHeading><DescriptorName>Magnetic Resonance Imaging</DescriptorName></MeshHeading><MeshHeading><DescriptorName>Lesions</DescriptorName></MeshHeading></MeshHeadingList></MedlineCitation>
<PubmedData><ArticleIdList><ArticleId IdType="pubmed">12345678</ArticleId><ArticleId IdType="doi">10.9999/JSI.2024.0001</ArticleId><ArticleId IdType="pmc">PMC1234567</ArticleId></ArticleIdList></PubmedData></PubmedArticle></PubmedArticleSet>
XML;

    /** @var list<string> */
    private array $asked = [];

    private function lookup(?string $answer): Lookup
    {
        return new Lookup(function (string $url) use ($answer): ?string {
            $this->asked[] = $url;

            return $answer;
        });
    }

    public function testCrossrefIsMappedToARecord(): void
    {
        $found = $this->lookup(self::CROSSREF)->crossref('https://doi.org/10.9999/JSI.2024.0001');

        self::assertSame(['https://api.crossref.org/works/10.9999%2Fjsi.2024.0001'], $this->asked, 'only the DOI is sent, folded');
        $csl = $found['csl'];
        self::assertSame(['article-journal', 'A made-up study of test lesions', 'Journal of Synthetic Imaging', 'J Synth Imaging'], [$csl['type'], $csl['title'], $csl['container-title'], $csl['container-title-short']]);
        self::assertSame([['family' => 'Nobody', 'given' => 'Ann'], ['literal' => 'Synthetic Imaging Group']], $csl['author']);
        self::assertSame([[2024, 5, 1]], $csl['issued']['date-parts']);
        self::assertSame(['10.9999/jsi.2024.0001', '3', '2', '11-14', '0000-0000'], [$csl['DOI'], $csl['volume'], $csl['issue'], $csl['page'], $csl['ISSN']]);
        self::assertSame("First part.\n\nSecond & last.", $csl['abstract']);
    }

    public function testPubmedIsMappedToARecordWithMeshAsTags(): void
    {
        $found = $this->lookup(self::PUBMED)->pubmed('12345678');

        self::assertSame(['https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id=12345678'], $this->asked);
        $csl = $found['csl'];
        self::assertSame('A made-up study of test lesions.', $csl['title']);
        self::assertSame([['family' => 'Nobody', 'given' => 'Ann'], ['literal' => 'Synthetic Imaging Group']], $csl['author']);
        self::assertSame(['12345678', '10.9999/jsi.2024.0001', 'PMC1234567', '11-4', [[2024]]], [$csl['PMID'], $csl['DOI'], $csl['PMCID'], $csl['page'], $csl['issued']['date-parts']]);
        self::assertSame("BACKGROUND: Why.\n\nRESULTS: What.", $csl['abstract']);
        self::assertSame(['Magnetic Resonance Imaging', 'Lesions'], $found['tags']);
    }

    public function testThePmidPubmedHasForADoi(): void
    {
        self::assertSame('12345678', $this->lookup('{"esearchresult":{"idlist":["12345678"]}}')->pmidOf('10.9999/jsi.2024.0001'));
        self::assertStringContainsString('term=%2210.9999%2Fjsi.2024.0001%22%5BAID%5D', $this->asked[0]);
        self::assertNull($this->lookup('{"esearchresult":{"idlist":["1","2"]}}')->pmidOf('10.9999/x'), 'two hits are no answer');
        self::assertNull($this->lookup('{"esearchresult":{"idlist":[]}}')->pmidOf('10.9999/x'));
    }

    public function testNoSuchRecordIsNullAndAFailureIsAnException(): void
    {
        self::assertNull($this->lookup('')->crossref('10.9999/none'));
        self::assertNull($this->lookup('')->pubmed('99999999'));
        self::assertNull($this->lookup('<PubmedArticleSet/>')->pubmed('99999999'));
        self::assertNull($this->lookup('{"status":"ok"}')->crossref('10.9999/none'));
        foreach ([fn (Lookup $l) => $l->crossref('10.9999/x'), fn (Lookup $l) => $l->pubmed('1'), fn (Lookup $l) => $l->pmidOf('10.9999/x')] as $call) {
            try {
                $call($this->lookup(null));
                self::fail('a failure is an exception');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\RuntimeException::class);
        $this->lookup('not json at all')->crossref('10.9999/x');
    }

    public function testAnIdThatIsNotOneSendsNothing(): void
    {
        $lookup = $this->lookup('{}');
        self::assertNull($lookup->pubmed('12; DROP'));
        self::assertNull($lookup->crossref('not a doi'));
        self::assertNull($lookup->pmidOf('not a doi'));
        self::assertSame([], $this->asked);
    }

    public function testAnXmlWithAnEntityDeclarationIsRefusedAndNothingIsReadFromTheDisk(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'xxe') ?: '';
        file_put_contents($secret, 'SECRET-CONTENT');
        $xml = '<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file://' . $secret . '">]><PubmedArticleSet><PubmedArticle><MedlineCitation><Article><ArticleTitle>&x;</ArticleTitle></Article></MedlineCitation></PubmedArticle></PubmedArticleSet>';
        try {
            $this->lookup($xml)->pubmed('1');
            self::fail('refused');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        } finally {
            unlink($secret);
        }
    }

    public function testTheRealFetchGoesToTheTwoServicesOnly(): void
    {
        $fetch = Lookup::http(1);

        self::assertNull($fetch('http://api.crossref.org/works/10.1/x'), 'not https');
        self::assertNull($fetch('https://example.test/works/10.1/x'), 'not one of the two');
        self::assertNull($fetch('file:///etc/passwd'));
    }
}
