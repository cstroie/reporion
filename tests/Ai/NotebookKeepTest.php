<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Library\KeptAnswers;
use Reporion\Storage\FileTree;
use Reporion\Tests\Http\HttpTestCase;

/** Ask the notebook's kept answers (2026-11-11): POST /api/v1/notebook/keep; the articles are made up */
final class NotebookKeepTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->config['ai'] = ['enabled' => true, 'fallback_profile' => 'wiki', 'servers' => [['endpoint' => 'http://127.0.0.1:9', 'tiers' => ['normal' => ['model' => 'normal-model']]]]];
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('writer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Editor), new Grant('library', GrantRole::Editor)]);
        $users->create('libreader', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Editor), new Grant('library', GrantRole::Viewer)]);
        $storage = $this->storage();
        $storage->create('ai:profiles:wiki:special:ask-notebook', ['title' => 'Ask', 'format' => 'text', 'visibility' => 'private'], "{sources}\n<q>{question}</q>\n", 'owner');
        $csl = ['type' => 'article-journal', 'title' => 'A made-up guideline', 'issued' => ['date-parts' => [[2018]]]];
        $storage->create('library:guide2018', ['title' => 'A made-up guideline', 'visibility' => 'private', 'csl' => $csl], "*— p. 3 —*\n\nAge groups for head exams.\n", 'owner');
        $storage->create('wiki:doses', ['title' => 'Doses', 'visibility' => 'private'], "# Doses\n\n[@guide2018]\n", 'owner');
        $storage->create('wiki:patient', ['title' => 'P', 'visibility' => 'private', 'patient' => ['name' => 'X Y']], "Cites [@guide2018].\n", 'owner');
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function cookie(string $user): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);
    }

    /** @param array<string, mixed> $body */
    private function keep(?string $user, array $body): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/notebook/keep', cookies: $user === null ? [] : ['reporion' => $this->cookie($user)], body: (string) json_encode($body)));
    }

    public function testEachKeptAnswerIsAnEntryOfThePageBesideTheNotebook(): void
    {
        $first = $this->keep('writer', ['path' => 'wiki:doses', 'question' => "What are the age bands\nfor head CT?", 'answer' => 'Four groups [@guide2018, p. 3].']);
        self::assertSame(201, $first->status, $first->body);
        $data = json_decode($first->body, true)['data'];
        self::assertSame('wiki:doses:answers', $data['path']);
        self::assertSame('/wiki:doses:answers', $data['href']);
        self::assertTrue($data['created']);

        $second = $this->keep('writer', ['path' => 'wiki:doses', 'question' => '## Which phantom?', 'answer' => '16 cm [@guide2018] and [@elsewhere2020].']);
        self::assertSame(200, $second->status, $second->body);
        self::assertSame(1, json_decode($second->body, true)['data']['dropped'], 'a key the notebook does not cite is taken out');

        $kept = $this->storage()->read('wiki:doses:answers');
        self::assertSame(2, $kept->rev);
        self::assertSame('private', $kept->frontmatter['visibility']);
        self::assertSame('answers', $kept->frontmatter['analysis']['prompt']);
        self::assertSame($this->storage()->read('wiki:doses')->pid, $kept->frontmatter['analysis']['of']);
        self::assertStringContainsString("## What are the age bands for head CT?\n\nFour groups [@guide2018, p. 3].", $kept->body, 'one line, the citation kept');
        self::assertStringContainsString("## Which phantom?\n\n16 cm [@guide2018] and", $kept->body, 'no heading marks of its own');
        self::assertStringNotContainsString('elsewhere2020', $kept->body);
        self::assertLessThan(strpos($kept->body, 'Which phantom'), strpos($kept->body, 'age bands'), 'in the order kept');
        self::assertSame("# Doses\n\n[@guide2018]\n", $this->storage()->read('wiki:doses')->body, 'the notebook itself is not written (D8)');
        self::assertStringContainsString('assisted: answers', json_encode($kept->revlog) ?: '');
    }

    public function testOnlyAnEditorOfTheNotebookAndOfTheLibraryKeepsAndNeverAPatientText(): void
    {
        $body = ['path' => 'wiki:doses', 'question' => 'Q?', 'answer' => 'A.'];
        foreach ([null, 'libreader'] as $user) {
            self::assertSame(404, $this->keep($user, $body)->status, $user ?? 'anonymous');
        }
        self::assertSame(404, $this->keep('owner', ['path' => 'wiki:patient'] + $body)->status, 'a page naming a patient is no notebook');
        self::assertSame(422, $this->keep('writer', ['answer' => ''] + $body)->status);
        self::assertSame(422, $this->keep('writer', ['question' => str_repeat('a', KeptAnswers::MAX_QUESTION + 1)] + $body)->status);
        self::assertSame(422, $this->keep('writer', ['answer' => 'Patient ' . \Reporion\Tests\Support\CnpTest::make(1, '800115') . '.'] + $body)->status, 'a made-up CNP');
        try {
            $this->storage()->read('wiki:doses:answers');
            self::fail('nothing kept');
        } catch (\Reporion\Exception\PageNotFoundException) {
        }

        $this->storage()->create('wiki:doses:answers', ['title' => 'Mine', 'visibility' => 'private'], "My own page.\n", 'owner');
        self::assertSame(409, $this->keep('writer', $body)->status, 'a page there that is not a kept-answers page is never written over');
    }

    public function testThePanelLinksTheKeptAnswersOnceThereAreAny(): void
    {
        $view = fn (): string => Kernel::boot($this->config)->handle(new Request('GET', '/wiki:doses', cookies: ['reporion' => $this->cookie('writer')]))->body;

        self::assertMatchesRegularExpression('~<p class="wk-text-sm" data-notebook-kept hidden>~', $view());
        $this->keep('writer', ['path' => 'wiki:doses', 'question' => 'Q?', 'answer' => 'A.']);
        self::assertMatchesRegularExpression('~<p class="wk-text-sm" data-notebook-kept><a href="/wiki:doses:answers">~', $view());
    }
}
