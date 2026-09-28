<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Context;
use Reporion\Service\Ai\FtsExamples;
use Reporion\Service\Ai\Redactor;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * {snippets} by full-text search (phase 15e): finished reports of the same
 * modality the caller can read, never this patient's, the best sections,
 * de-identified.
 */
final class ExamplesTest extends StorageTestCase
{
    private const PATH = 'reports:mri:mioveni:260927-popescu-ana';
    private const TEXT = "Fisură oblică a cornului posterior al meniscului medial, cu extensie la suprafața articulară.";

    private FlatFile $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        $report = static fn (string $name, string $modality, string $status, string $body): array => [[
            'title' => $name, 'visibility' => 'private', 'status' => $status, 'modality' => [$modality], 'patient' => ['name' => $name],
        ], $body];
        $pages = [
            'reports:mri:mioveni:250101-ionescu-vasile' => $report('IONESCU Vasile', 'MR', 'archived', "# IONESCU Vasile\n\n## IRM genunchi\n\nPacientul Vasile Ionescu.\n\n### Descriere\n\nFisură oblică a cornului posterior al meniscului medial, extinsă la suprafața articulară inferioară.\n\n### Concluzii\n\nFisură meniscală medială.\n"),
            'reports:mri:mioveni:250202-popescu-ana' => $report('POPESCU Ana', 'MR', 'archived', "### Descriere\n\nFisură oblică a cornului posterior al meniscului medial la aceeași pacientă.\n"),
            'reports:ct:mioveni:250303-marin-ion' => $report('MARIN Ion', 'CT', 'archived', "### Descriere\n\nFisură oblică a cornului posterior al meniscului medial pe CT.\n"),
            'reports:mri:mioveni:250404-dinu-elena' => $report('DINU Elena', 'MR', 'draft', "### Descriere\n\nFisură oblică a cornului posterior al meniscului medial, nesemnat.\n"),
            'reports:mri:campulung:250505-stan-ioana' => $report('STAN Ioana', 'MR', 'archived', "### Descriere\n\nFisură oblică a cornului posterior al meniscului medial la Câmpulung.\n"),
        ];
        foreach ($pages as $path => [$fm, $body]) {
            $this->storage->create($path, $fm, $body, 'owner');
        }
        $this->storage->create(self::PATH, ['title' => 'POPESCU Ana', 'visibility' => 'private', 'modality' => ['MR'], 'patient' => ['name' => 'POPESCU Ana']], "# POPESCU Ana\n\n## IRM genunchi\n\n" . self::TEXT . "\n", 'owner');
    }

    public function testOnlyFinishedSameModalityReportsOfOtherPatientsTheCallerCanRead(): void
    {
        $mioveniOnly = new User('m', 'x', false, [new Grant('reports:mri:mioveni', GrantRole::Editor)], true, 'now', 'now');
        $redactor = new Redactor();
        $snippets = (new FtsExamples($this->index, $this->storage))->for($this->storage->read(self::PATH), self::TEXT, $mioveniOnly, $redactor);

        $all = implode("\n", $snippets);
        self::assertStringContainsString('extinsă la suprafața articulară inferioară', $all, 'the matching section of another patient\'s finished report');
        self::assertStringNotContainsString('aceeași pacientă', $all, 'never this patient\'s own reports');
        self::assertStringNotContainsString('pe CT', $all, 'the same modality only');
        self::assertStringNotContainsString('nesemnat', $all, 'finished reports only');
        self::assertStringNotContainsString('Câmpulung', $all, 'only what the caller can read');
        self::assertStringNotContainsString('Vasile', $all, 'de-identified');
        self::assertStringNotContainsString('Ionescu', $all);
        self::assertStringStartsWith('### Descriere', $snippets[0], 'the best-matching section first');
    }

    public function testContextFillsSnippets(): void
    {
        $owner = new User('owner', 'x', true, [], true, 'now', 'now');
        $action = new Action('create', 'Create', '', '', 'insert', "<exemple>{snippets}</exemple>\n{text}", '');

        $prompt = (new Context($this->storage, $this->index, new FtsExamples($this->index, $this->storage)))->build($action, $this->storage->read(self::PATH), self::TEXT, $owner);

        self::assertStringContainsString('<exemplu id="1">', $prompt->user);
        self::assertMatchesRegularExpression('/^\d+ snippets$/', $prompt->contextSet[1]);
        foreach (['Vasile', 'Ionescu', 'Popescu', 'POPESCU', 'Ioana', 'reports:'] as $identifier) {
            self::assertStringNotContainsString($identifier, $prompt->user, $identifier);
        }
    }
}
