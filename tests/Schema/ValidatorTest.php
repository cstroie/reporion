<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Reporion\Schema\Loader;
use Reporion\Schema\Validator;

final class ValidatorTest extends TestCase
{
    private Loader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new Loader(\dirname(__DIR__, 2) . '/conf/schema');
    }

    public function testACompleteReportHasNothingMissing(): void
    {
        $fields = $this->loader->fieldsFor(['MR']);

        $missing = Validator::missingForSign($this->completeMrFields(), $fields);

        self::assertSame([], $missing);
    }

    public function testMissingSummaryBlocksSigning(): void
    {
        $fields = $this->loader->fieldsFor(['MR']);
        $values = $this->completeMrFields();
        unset($values['summary']);

        $missing = Validator::missingForSign($values, $fields);

        self::assertContains('summary', $missing);
    }

    public function testEachModalitysOwnRequiredForSignFieldIsChecked(): void
    {
        // Every shipped modality schema declares "indication" required_for
        // sign — one test per file, per the review guidance, so a typo in
        // any one of them (e.g. "required_fr") fails loudly and specifically.
        foreach (['ct', 'mg', 'mr', 'us', 'xr'] as $modality) {
            $fields = $this->loader->fieldsFor([strtoupper($modality)]);
            $values = $this->completeMrFields();
            unset($values['indication']);

            $missing = Validator::missingForSign($values, $fields);

            self::assertContains('indication', $missing, "modality {$modality} must require indication to sign");
        }
    }

    public function testMgAlsoRequiresBirads(): void
    {
        $fields = $this->loader->fieldsFor(['MG']);
        $values = $this->completeMrFields();
        unset($values['birads']);

        $missing = Validator::missingForSign($values, $fields);

        self::assertContains('birads', $missing);
    }

    /**
     * The discriminating case: "indication" is required_for sign in BOTH
     * ct.json and mr.json, so a test that only removes it would still pass
     * with a broken union that resolved just one of the two modalities.
     * "birads" exists only in mg.json — if a CT+MG union silently dropped
     * MG's fields, this would miss it.
     */
    public function testACombinedStudyMustSatisfyBothModalitiesRequirements(): void
    {
        $fields = $this->loader->fieldsFor(['CT', 'MG']);
        $values = $this->completeMrFields();
        unset($values['birads']);

        $missing = Validator::missingForSign($values, $fields);

        self::assertContains('birads', $missing, 'a CT+MG union must still enforce MG-only fields');
    }

    public function testWhitespaceOnlyTextCountsAsMissing(): void
    {
        $fields = $this->loader->fieldsFor(['MR']);
        $values = $this->completeMrFields();
        $values['summary'] = "   \n  ";

        $missing = Validator::missingForSign($values, $fields);

        self::assertContains('summary', $missing);
    }

    public function testEmptyListCountsAsMissingForARequiredListField(): void
    {
        $fields = $this->loader->fieldsFor(['MR']);
        $values = $this->completeMrFields();
        $values['region'] = [];

        $missing = Validator::missingForSign($values, $fields);

        self::assertContains('region', $missing);
    }

    public function testNestedPatientNameReportsAsADottedPath(): void
    {
        $fields = $this->loader->fieldsFor(['MR']);
        $values = $this->completeMrFields();
        unset($values['patient']['name']);

        $missing = Validator::missingForSign($values, $fields);

        self::assertContains('patient.name', $missing);
    }

    public function testStatusAndVisibilityLiveOutsideFrontmatterButAreStillChecked(): void
    {
        // status/visibility are meta.json-tracked, never written into
        // current.md's frontmatter block — the caller merges them into the
        // $fields array passed here (see Validator's own docblock); a
        // caller that forgets to would see every sign attempt blocked.
        $fields = $this->loader->fieldsFor(['MR']);
        $values = $this->completeMrFields();
        unset($values['status'], $values['visibility']);

        $missing = Validator::missingForSign($values, $fields);

        self::assertContains('status', $missing);
        self::assertContains('visibility', $missing);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeMrFields(): array
    {
        return [
            'title' => 'RM cerebral nativ',
            'modality' => ['MR'],
            'region' => ['neuro'],
            'site' => 'mioveni',
            'study_date' => '2026-09-23',
            'summary' => 'Fara leziuni active.',
            'visibility' => 'private',
            'status' => 'draft',
            'patient' => ['name' => 'Ionescu Maria'],
            'indication' => 'Cefalee cronica.',
            'birads' => '2',
        ];
    }
}
