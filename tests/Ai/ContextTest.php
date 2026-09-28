<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Context;
use Reporion\Service\Ai\Redactor;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * The chokepoint (D15, invariant 8): whatever an action's prompt asks for,
 * no patient name, CNP, accession or page path reaches the provider — not
 * the report's own, not its prior's, not an example's.
 */
final class ContextTest extends StorageTestCase
{
    private const PATH = 'reports:mri:mioveni:260927-popescu-ana-maria';
    private const PRIOR = 'reports:mri:mioveni:250310-popescu-ana-maria';
    private const OTHER = 'reports:mri:mioveni:250101-ionescu-vasile';

    private FlatFile $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        $this->storage->create('templates:mri:genunchi', ['title' => 'IRM Genunchi', 'visibility' => 'private'], "Genunchi: meniscuri normale.\n", 'owner');
        $this->storage->create(self::PRIOR, [
            'title' => 'POPESCU Ana Maria', 'visibility' => 'private', 'study_date' => '2025-03-10', 'accession' => 'MV-MR-25-0100',
            'patient' => ['name' => 'POPESCU Ana Maria', 'cnp' => '2800115123458'],
        ], "## POPESCU Ana Maria\n\n### IRM genunchi\n\nFisură meniscală, pacienta Popescu revine.\n", 'owner');
        $this->storage->create(self::OTHER, [
            'title' => 'IONESCU Vasile', 'visibility' => 'private', 'patient' => ['name' => 'IONESCU Vasile'],
        ], "# IONESCU Vasile\n\n## IRM genunchi\n\nMenisc normal. Vasile Ionescu.\n", 'owner');
        $this->storage->create(self::PATH, [
            'title' => 'POPESCU Ana Maria', 'exam_title' => 'IRM genunchi stâng', 'visibility' => 'private',
            'modality' => ['MR'], 'region' => ['msk'], 'study_date' => '2026-09-27', 'accession' => 'MV-MR-26-0412',
            'template' => 'templates:mri:genunchi', 'priors' => [self::PRIOR], 'ai_examples' => [self::OTHER],
            'patient' => ['name' => 'POPESCU Ana Maria', 'cnp' => '2800115123458', 'sex' => 'F', 'born' => 1980],
        ], "# POPESCU Ana Maria\n\n## IRM genunchi stâng\n\nText.\n", 'owner');
    }

    public function testNoFrontmatterReachesThePromptFromTheReportOrThePromptPages(): void
    {
        $action = new Action('conclusion', 'Conclusion', '', '', 'append',
            "---\ntitle: Conclusion\nresult: append\n---\n<raport>\n{text}\n</raport>\n<sablon>{template}</sablon>\nScrie concluzia.",
            "---\nlabel: System\n---\nEști radiolog.");
        $text = "---\ntitle: 'X'\nvisibility: private\npid: 01JABCDEFGHJKMNPQRSTVWXYZ0\nstatus: draft\n---\n\n## IRM\n\nMenisc fisurat.\n\n---\n\nsite: mioveni\ndevice: MV-MR-01\n---\n\nFinal.";

        $prompt = (new Context($this->storage, $this->index))->build($action, $this->storage->read(self::PATH), $text, $this->owner());
        $all = $prompt->system . "\n" . $prompt->user;

        foreach (['title:', 'visibility:', 'pid:', '01JABCDEFGHJKMNPQRSTVWXYZ0', 'status:', 'site:', 'device:', 'result:', 'label:', '---'] as $key) {
            self::assertStringNotContainsString($key, $all, $key);
        }
        self::assertStringContainsString('Menisc fisurat.', $all);
        self::assertStringContainsString('Final.', $all);
        self::assertStringContainsString('Genunchi: meniscuri normale.', $all, 'the template goes by its body');
        self::assertStringStartsWith('Ești radiolog.', $prompt->system);
        self::assertStringStartsWith('<raport>', $prompt->user);
        self::assertSame("Intro.\n\n---\n\nText.\n\n---\n\nEnd.", Redactor::withoutFrontmatter("Intro.\n\n---\n\nText.\n\n---\n\nEnd."), 'thematic breaks stay');
    }

    public function testNothingIdentifyingReachesThePrompt(): void
    {
        $action = new Action('compare', 'Compare', '', '', 'append',
            "<curent>{current_date}\n{text}</curent>\n<anterior>{previous_date}\n{previous}</anterior>\n<sablon>{template}</sablon>\n<exemple>{examples}</exemple>\n{exam} · {sex}, {age} ani · {prompt}",
            'Ești radiolog. {action}');
        $text = "# POPESCU Ana Maria\n\nPacienta Popescu Ana-Maria (CNP 2800115123458, nr. MV-MR-26-0412), vezi [anterior](reports:mri:mioveni:250310-popescu-ana-maria) și 250310-popescu-ana-maria.\n~~META:\n&name = Popescu Ana\n&fo = 1234/2025\n~~\nMenisc medial fisurat.";

        $prompt = (new Context($this->storage, $this->index))->build($action, $this->storage->read(self::PATH), $text, $this->owner(), 'exam 1', 1, 'Pentru Popéscu, scurt.');
        $all = $prompt->system . "\n" . $prompt->user;

        foreach (['popescu', 'Popescu', 'POPESCU', 'Ana', 'Maria', 'Ionescu', 'Vasile', '2800115123458', 'MV-MR-26-0412', 'MV-MR-25-0100', '250310-popescu', 'reports:', '1234/2025', '~~META'] as $identifier) {
            self::assertStringNotContainsString($identifier, $all, $identifier . ' must not reach the prompt');
        }
        self::assertStringContainsString('Menisc medial fisurat.', $all, 'the findings do');
        self::assertStringContainsString('Fisură meniscală', $all, 'the prior is there, de-identified');
        self::assertStringContainsString('Genunchi: meniscuri normale.', $all, 'the template word "genunchi" is not an identifier');
        self::assertStringContainsString('Menisc normal.', $all, 'the example is there');
        self::assertStringContainsString('<curent>27.09.2026', $all);
        self::assertStringContainsString('<anterior>10.03.2025', $all);
        self::assertStringContainsString('IRM genunchi stâng · feminin, 46 ani · Pentru [pacient], scurt.', $all);
        self::assertStringContainsString('Ești radiolog. compare', $prompt->system);
        self::assertSame(['exam 1', 'template', 'prior', '1 examples', 'no patient identifiers'], $prompt->contextSet);
    }

    public function testWhatTheCallerCannotReadStaysOut(): void
    {
        $viewerElsewhere = new User('v', 'x', false, [new Grant('reports:ct', GrantRole::Viewer)], true, 'now', 'now');
        $action = new Action('compare', 'Compare', '', '', 'show', '{previous}|{template}', '');

        $prompt = (new Context($this->storage, $this->index))->build($action, $this->storage->read(self::PATH), 'x', $viewerElsewhere);

        self::assertSame('( fără examinare anterioară )|( fără șablon )', $prompt->user);
    }

    public function testThePromptIsRefusedWhenAnIdentifierWouldStillLeave(): void
    {
        // A prompt page that writes the name itself — the redaction runs on
        // what was filled in, the final guard on everything
        $action = new Action('bad', 'Bad', '', '', 'show', 'Pacient: Popescu. {text}', '');

        try {
            (new Context($this->storage, $this->index))->build($action, $this->storage->read(self::PATH), 'x', $this->owner());
            self::fail('expected identifier_leak');
        } catch (AiException $e) {
            self::assertSame('identifier_leak', $e->reason);
            self::assertStringNotContainsString('Popescu', $e->getMessage());
        }
    }

    public function testRedactionIgnoresCaseAndDiacriticsButNotShortOrOrdinaryWords(): void
    {
        $redactor = new Redactor();
        $redactor->learn(['patient' => ['name' => 'Șerban Ion-Ăna Li']], 'reports:ct:x:260101-serban-ion');

        self::assertSame('[pacient] și Li a venit; indicație: durere.', $redactor->redact('SERBAN Ion-Ana și Li a venit; indicație: durere.'));
        self::assertFalse($redactor->leaks('[pacient] și Li a venit'), 'two-letter parts are not identifiers');
        self::assertTrue($redactor->leaks('scrie serban'));
    }

    private function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }
}
