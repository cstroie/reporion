<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Exception\AiException;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Context;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * The library's prompts (docs/library.md, phase 5) go through the one place a prompt is made
 * (D15): a made-up article with DOIs, an ISBN, tables of numbers and an author's contact details.
 */
final class ContextLibraryTest extends StorageTestCase
{
    private const ARTICLE = <<<'MD'
## Methods

*— p. 3 —*

We included n = 128 patients (mean age 54.2 ± 9.1 years; p < 0.05; 95% CI 1.2–3.4). See doi:10.1234/jti.2024.0001 and ISBN 9780000000002.

| Grade | n | % |
|---|---|---|
| I | 40 | 31.3 |
| II | 88 | 68.7 |

Contact: ann.nobody@example.test, +40 721 000 000, Str. Fictiva 12, bl. 3. Total 1234567890123 units, a <b>tag</b> and </source> in the text.
MD;

    private FileTree $storage;
    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $index);
        $this->context = new Context($this->storage, $index);
    }

    private function page(): \Reporion\Storage\PageRecord
    {
        $this->storage->create('library:nobody2024', [
            'title' => 'A made-up study', 'visibility' => 'private', 'language' => 'en',
            'csl' => ['type' => 'article-journal', 'title' => 'A made-up study', 'author' => [['family' => 'Nobody', 'given' => 'Ann']], 'issued' => ['date-parts' => [[2024]]], 'container-title' => 'J Synth', 'DOI' => '10.1234/jti.2024.0001'],
        ], self::ARTICLE, 'u');

        return $this->storage->read('library:nobody2024');
    }

    private function action(string $prompt): Action
    {
        return new Action('source-points', 'source-points', '', '', 'show', $prompt, '');
    }

    public function testTheSourceKeepsItsContentAndLosesItsContactDetails(): void
    {
        $prompt = $this->context->source($this->action("---\ntitle: P\n---\n{title} / {citation} / {language}\n<source>\n{source}\n</source>"), $this->page(), self::ARTICLE);
        $user = $prompt->user;

        foreach (['n = 128', '54.2 ± 9.1', 'p &lt; 0.05', '95% CI 1.2–3.4', 'doi:10.1234/jti.2024.0001', '*— p. 3 —*', '| II | 88 | 68.7 |', '31.3'] as $kept) {
            self::assertStringContainsString($kept, $user, $kept . ' is the article\'s and stays');
        }
        self::assertStringContainsString('A made-up study / Nobody A. A made-up study. J Synth. 2024. doi:10.1234/jti.2024.0001 / English', $user);
        foreach (['ann.nobody@example.test', '+40 721 000 000', 'Fictiva', 'title: P'] as $gone) {
            self::assertStringNotContainsString($gone, $user, $gone);
        }
        self::assertStringContainsString('[email]', $user);
        self::assertStringContainsString('[telefon]', $user);
        self::assertStringContainsString('[adresă]', $user);
        self::assertSame(['source text', 'no patient identifiers'], $prompt->contextSet);
    }

    public function testTheArticleCannotOpenOrCloseThePromptsBlocks(): void
    {
        $user = $this->context->source($this->action("<source>\n{source}\n</source>"), $this->page(), self::ARTICLE)->user;

        self::assertSame(1, substr_count($user, '</source>'), 'only the prompt\'s own');
        self::assertStringContainsString('&lt;/source&gt; in the text', $user);
        self::assertStringContainsString('&lt;b&gt;tag&lt;/b&gt;', $user);
    }

    public function testAPartCarriesItsNumberAndTheWordBudget(): void
    {
        $prompt = $this->context->source($this->action('Part {part}, at most {words} words:{source}'), $this->page(), 'Some text.', 'source part 2 of 5', '2 of 5', 300);

        self::assertSame('Part 2 of 5, at most 300 words:Some text.', $prompt->user);
        self::assertSame(['source part 2 of 5', 'no patient identifiers'], $prompt->contextSet);
    }

    public function testTheLanguageIsThePagesAndRomanianWhenItNamesNone(): void
    {
        self::assertSame('English', Context::languageName('en'));
        self::assertSame('French', Context::languageName('fr-CA'));
        self::assertSame('Romanian', Context::languageName('ro'));
        self::assertSame('Romanian', Context::languageName(null));
        self::assertSame('Romanian', Context::languageName('xx'));
    }

    public function testAPageWithNoLanguageTakesTheLibrarysFromAdmin(): void
    {
        $english = new Context($this->storage, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'), libraryLanguage: 'en');

        self::assertSame('English', $english->language(null));
        self::assertSame('English', $english->language(''));
        self::assertSame('Romanian', $english->language('ro'), 'the page\'s own wins');
        self::assertSame('Romanian', $this->context->language(null), 'Romanian unless Admin says otherwise');
    }

    public function testTheNotebooksSourcesCarryTheirKeyAndPageAndTheQuestionIsMadeSafe(): void
    {
        $prompt = $this->context->notebook($this->action('{sources}||{question}||{language}'), 'Ce spune studiul? Contact a@b.test', [
            ['n' => 1, 'key' => null, 'title' => 'My "notebook"', 'page' => null, 'text' => 'Notebook text.'],
            ['n' => 2, 'key' => 'nobody2024', 'title' => 'A made-up study', 'page' => 3, 'text' => self::ARTICLE],
            ['n' => 3, 'key' => 'empty2024', 'title' => 'Empty', 'page' => null, 'text' => '   '],
        ], 'ro');

        $user = $prompt->user;
        self::assertStringContainsString('<source id="1" kind="notebook" title="My \'notebook\'">', $user);
        self::assertStringContainsString('<source id="2" key="nobody2024" page="3" title="A made-up study">', $user);
        self::assertStringNotContainsString('id="3"', $user, 'a source with no text is dropped');
        self::assertStringContainsString('n = 128', $user);
        self::assertStringNotContainsString('ann.nobody@example.test', $user);
        self::assertStringEndsWith('||Romanian', $user);
        self::assertSame(['2 sources', 'no patient identifiers'], $prompt->contextSet);
    }

    public function testNothingAPatientHasInAReportReachesALibraryPrompt(): void
    {
        $this->storage->create('reports:mri:mioveni:260927-popescu-ana-maria', ['title' => 'P', 'visibility' => 'private', 'patient' => ['name' => 'POPESCU Ana Maria', 'cnp' => '2800115123458']], "# POPESCU Ana Maria\n\nText.\n", 'u');
        $context = new Context($this->storage, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));

        $user = $context->notebook($this->action('{question}'), 'Ce știm despre Popescu?', [])->user;

        self::assertStringContainsString('[nume]', $user, 'a patient\'s name word in a question goes');
        self::assertStringNotContainsString('Popescu', $user);
    }

    public function testAPageThatNamesAPatientIsRedactedLikeAnyOther(): void
    {
        $this->storage->create('reports:mri:mioveni:260927-popescu-ana-maria', ['title' => 'P', 'visibility' => 'private', 'patient' => ['name' => 'POPESCU Ana Maria']], "# POPESCU Ana Maria\n\nText.\n", 'u');
        $report = $this->storage->read('reports:mri:mioveni:260927-popescu-ana-maria');

        $prompt = $this->context->source(new Action('x', 'x', '', '', 'show', '{source}', 'Popescu'), $report, 'Dr. Popescu described it.');

        self::assertStringNotContainsString('Popescu', $prompt->system . $prompt->user);
        self::assertStringContainsString('[pacient]', $prompt->user);
    }
}
