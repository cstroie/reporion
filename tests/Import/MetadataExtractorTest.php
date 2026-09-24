<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Import;

use PHPUnit\Framework\TestCase;
use Reporion\Import\ImportMap;
use Reporion\Import\MetadataExtractor;
use Reporion\Import\AccessionAllocator;

final class MetadataExtractorTest extends TestCase
{
    private ImportMap $importMap;
    private AccessionAllocator $allocator;

    protected function setUp(): void
    {
        $this->importMap = new ImportMap([
            'folder_to_site' => [
                'mri/scuc' => ['site' => 'scuc', 'modality' => ['MR']],
                'ct/scuc' => ['site' => 'scuc', 'modality' => ['CT']],
            ],
            'device_by_site_modality' => [
                'scuc:MR' => 'SCUC-MR-01',
                'scuc:CT' => 'SCUC-CT-01',
            ],
            'title_keywords' => [
                'modality' => ['IRM' => 'MR', 'CT' => 'CT'],
                'region' => ['cerebral' => 'neuro', 'torace' => 'chest'],
            ],
            'date_formats' => ['d.m.Y', 'd.m.y'],
            'timezone' => 'Europe/Bucharest',
            'skip_paths' => [],
        ]);

        $this->allocator = new AccessionAllocator(sys_get_temp_dir(), [], 'Europe/Bucharest');
    }

    public function testPatientNameFromH2(): void
    {
        $markdown = <<<'EOF'
## PATIENT JOHN

### IRM Cerebral

Some findings.
EOF;

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertEquals('PATIENT JOHN', $result['frontmatter']['patient']['name']);
    }

    public function testTitleFromH2(): void
    {
        $markdown = "## Patient Name\n\n### Exam Type\n\nBody";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertEquals('Patient Name', $result['frontmatter']['title']);
    }

    public function testModalityFromTitle(): void
    {
        $markdown = "## Patient Name\n\n### IRM Cerebral\n\nFindings";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertContains('MR', $result['frontmatter']['modality']);
    }

    public function testRegionFromTitle(): void
    {
        $markdown = "## Patient\n\n### IRM Cerebral\n\nCerebral findings";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertContains('neuro', $result['frontmatter']['region']);
    }

    public function testStudyDateFromFilename(): void
    {
        $markdown = "## Patient\n\n### Exam\n\nDate: 25.05.2021";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210525-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertNotEmpty($result['frontmatter']['study_date']);
        $this->assertIsString($result['frontmatter']['study_date']);
    }

    public function testStudyDateTwoDigitYear(): void
    {
        // Two-digit year parsing - should handle 21 as 2021
        $markdown = "## Patient\n\n### Exam\n\nDate: 25.05.21";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210525-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertNotEmpty($result['frontmatter']['study_date']);
    }

    public function testSiteFromFolderMapping(): void
    {
        $markdown = "## Patient\n\nBody";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertEquals('scuc', $result['frontmatter']['site']);
    }

    public function testUnmappedSiteQueuesReview(): void
    {
        $markdown = "## Patient\n\nBody";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'unknown/folder/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertNull($result['frontmatter']['site'] ?? null);

        $siteReview = array_filter($result['review'], fn ($item) => $item['field'] === 'site');
        $this->assertNotEmpty($siteReview);
    }

    public function testMissingSummaryQueuesReview(): void
    {
        $markdown = "## Patient\n\n### Exam\n\nBody without conclusion section";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertEquals('', $result['frontmatter']['summary']);

        $summaryReview = array_filter($result['review'], fn ($item) => $item['field'] === 'summary');
        $this->assertNotEmpty($summaryReview);
    }

    public function testSummaryExtraction(): void
    {
        $markdown = <<<'EOF'
## Patient

### Findings

Some findings.

### Concluzii

Fractura na...
Fara alte...
EOF;

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $summary = $result['frontmatter']['summary'];

        $this->assertNotEmpty($summary);
        $this->assertStringContainsString('Fractura', $summary);
    }

    public function testTemplateField(): void
    {
        $markdown = "## Patient\n\nBody";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            'reports:ct:templates:standard',
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertEquals('reports:ct:templates:standard', $result['frontmatter']['template']);
    }

    public function testPriorsRecorded(): void
    {
        $markdown = "## Patient\n\nBody";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'mri/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            ['reports:mri:scuc:210101-prior']
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $this->assertIsArray($result['frontmatter']['priors']);
        $this->assertCount(1, $result['frontmatter']['priors']);
    }

    public function testFrontmatterStructure(): void
    {
        $markdown = "## Patient\n\n### CT Exam\n\nBody";

        $extractor = new MetadataExtractor(
            $this->importMap,
            'ct/scuc/210101-patient.txt',
            'abc123',
            $markdown,
            null,
            []
        );

        $result = $extractor->extract(sys_get_temp_dir(), sys_get_temp_dir(), $this->allocator);
        $frontmatter = $result['frontmatter'];

        $this->assertArrayHasKey('title', $frontmatter);
        $this->assertArrayHasKey('modality', $frontmatter);
        $this->assertArrayHasKey('region', $frontmatter);
        $this->assertArrayHasKey('site', $frontmatter);
        $this->assertArrayHasKey('device', $frontmatter);
        $this->assertArrayHasKey('study_date', $frontmatter);
        $this->assertArrayHasKey('accession', $frontmatter);
        $this->assertArrayHasKey('patient', $frontmatter);
        $this->assertArrayHasKey('visibility', $frontmatter);
        $this->assertArrayHasKey('status', $frontmatter);
    }
}
