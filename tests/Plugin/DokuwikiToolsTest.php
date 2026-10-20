<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Plugin\Loader;
use Reporion\Storage\FileTree;
use Reporion\Tests\Http\HttpTestCase;
use RuntimeException;

/**
 * plugins/dokuwiki: the editor's DokuWiki conversion tools. The tools themselves run in node
 * (tools/run-dokuwiki-tools.js, over plugins/dokuwiki/assets/tools.js); the plugin's one route and
 * the editor's Tools section go through the real Kernel. Names are made up (invariant 10).
 */
final class DokuwikiToolsTest extends HttpTestCase
{
    private const DOC = "---\ntitle: Test\nmodality:\n  - MR\n---\n\n";
    private const PAGE = 'docs:procedures:contrast';

    protected function setUp(): void
    {
        parent::setUp();
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('node is required to run the dokuwiki tools test.');
        }
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dokuwiki');
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true, [], 'Dr. Test Owner');
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('docs', GrantRole::Viewer)]);
        $this->storage()->create(self::PAGE, ['title' => 'Contrast procedure', 'visibility' => 'private'], "# Contrast\n\nText.\n", 'owner');
    }

    public function testDokuLinksBecomeMarkdownOverTheWholeBodyNotTheFrontmatterOrCode(): void
    {
        $text = self::DOC . "See [[http://example.com|Example]], [[http://a.org]], [[:reports:mri:page_one|Page]], [[radiology:knee]], [[a@b.ro|Mail]] and `[[http://code.com|c]]`.\n\n```\n[[http://fence.com|f]]\n```\n";
        [$run] = $this->run1('dokuLinks', [$text, 0, 0]);

        self::assertSame(
            self::DOC . "See [Example](http://example.com), <http://a.org>, [Page](reports:mri:page_one), [knee](radiology:knee), [Mail](mailto:a@b.ro) and `[[http://code.com|c]]`.\n\n```\n[[http://fence.com|f]]\n```\n",
            $run['text']
        );
        self::assertSame(5, $run['result']['converted']);
        self::assertSame(0, $run['result']['skipped']);
    }

    public function testDokuLinksActOnTheSelectionOnlyAndCountWhatTheyLeave(): void
    {
        $text = self::DOC . '[[http://a.org|A]] [[page]] [[wp>Foo|x]] [[http://b.org|B]]';
        $from = \strlen(self::DOC);
        [$run] = $this->run1('dokuLinks', [$text, $from, $from + 40]);

        self::assertSame(self::DOC . '[A](http://a.org) [[page]] [[wp>Foo|x]] [[http://b.org|B]]', $run['text']);
        self::assertSame(1, $run['result']['converted']);
        self::assertSame(2, $run['result']['skipped']);
    }

    public function testDokuLinksWithNothingToConvertIsNoEdit(): void
    {
        [$run] = $this->run1('dokuLinks', [self::DOC . 'Plain [text](http://a.org).', 0, 0]);

        self::assertSame(self::DOC . 'Plain [text](http://a.org).', $run['text']);
        self::assertSame(0, $run['result']['converted']);
    }

    public function testDokuMarkupHeadingsInlinesAndLists(): void
    {
        $text = self::DOC . "====== Titlu ======\n===== Sub =====\n== five ==\n==marked==\n\nText //italic// at http://x.com/a//b, ''mono'', H<sub>2</sub>O cm<sup>2</sup> end\\\\\nnext\n\n  * one\n    * two\n      - num\n  - last\n\n`//code//` and [[http://a.b//c|x]]\n```\n//fence//\n  * not a list\n```\n";
        [$run] = $this->run1('dokuMarkup', [$text, 0, 0]);

        self::assertSame(
            self::DOC . "# Titlu\n## Sub\n##### five\n==marked==\n\nText *italic* at http://x.com/a//b, `mono`, H~2~O cm^2^ end\\\nnext\n\n- one\n  - two\n    1. num\n1. last\n\n`//code//` and [[http://a.b//c|x]]\n```\n//fence//\n  * not a list\n```\n",
            $run['text']
        );
        self::assertSame(0, $run['result']['skipped']);
    }

    public function testDokuMarkupLeavesMarkdownListsAndCountsThem(): void
    {
        $text = self::DOC . "- a\n  * nested markdown\n";
        [$run] = $this->run1('dokuMarkup', [$text, 0, 0]);

        self::assertSame($text, $run['text']);
        self::assertSame(0, $run['result']['converted']);
        self::assertSame(1, $run['result']['skipped']);
    }

    public function testCodeAndFileBlocksBecomeFencedBlocks(): void
    {
        $text = self::DOC . "Intro\n<code python rules1.py>\ndef f():\n    return //x//\n</code>\n\n<code>\nplain ``` ticks\n</code>\n<file - a.txt>\nraw ====== H ======\n</file>\n<code html4strict>\n<b>x</b>\n</code>\n====== H ======\n";
        [$run] = $this->run1('dokuMarkup', [$text, 0, 0]);

        self::assertSame(
            self::DOC . "Intro\n```python\ndef f():\n    return //x//\n```\n\n````\nplain ``` ticks\n````\n```\nraw ====== H ======\n```\n```html\n<b>x</b>\n```\n# H\n",
            $run['text']
        );
        self::assertSame(5, $run['result']['converted'], 'four blocks and the heading; what is inside a block is not touched');
    }

    public function testACodeBlockStartsAndEndsOnItsOwnLines(): void
    {
        [$run] = $this->run1('dokuMarkup', [self::DOC . "see <code php>echo 1;</code> done\n", 0, 0]);

        self::assertSame(self::DOC . "see \n```php\necho 1;\n```\n done\n", $run['text']);
    }

    public function testDefinitionListsBecomeTermAndDefinitionLines(): void
    {
        $text = self::DOC . "Servers:\n  ; Orthanc\n  : http://192.168.3.206:8042/\n  ; RDP\n  : ''mstsc /v:host.example.org:33899''\n  : second\nafter\n: stray\n";
        [$run] = $this->run1('dokuMarkup', [$text, 0, 0]);

        self::assertSame(
            self::DOC . "Servers:\n\nOrthanc\n: http://192.168.3.206:8042/\n\nRDP\n: `mstsc /v:host.example.org:33899`\n: second\n\nafter\n: stray\n",
            $run['text']
        );
    }

    public function testTablesBecomeMarkdownPipeTables(): void
    {
        $text = self::DOC . "Before\n^ Region ^ Size ^  Note  ^\n| Liver | 3 cm //big// | [[http://a.org|link]] |\n^ Spleen | ||\n|  right  | x |  c  |\nafter\n";
        [$run] = $this->run1('dokuTables', [$text, 0, 0]);

        self::assertSame(
            self::DOC . "Before\n\n| Region | Size | Note |\n| :---: | --- | :---: |\n| Liver | 3 cm *big* | [link](http://a.org) |\n| **Spleen** |  | << |\n| right | x | c |\n\nafter\n",
            $run['text']
        );
        self::assertSame(0, $run['result']['skipped']);
    }

    public function testATableWithoutAHeadRowGetsAnEmptyOneAndAlignmentFollowsTheSpaces(): void
    {
        [$run] = $this->run1('dokuTables', [self::DOC . "|  a  |  b |\n| c  | d |\n", 0, 0]);

        self::assertSame(self::DOC . "|  |  |\n| :---: | ---: |\n| a | b |\n| c | d |\n", $run['text']);
    }

    public function testATableWithARowSpanOrAMarkdownSeparatorIsLeftAlone(): void
    {
        $text = self::DOC . "^ x ^ y ^\n| :::  | 2 |\n\n| a | b |\n|---|---|\n| 1 | 2 |\n";
        [$run] = $this->run1('dokuTables', [$text, 0, 0]);

        self::assertSame($text, $run['text']);
        self::assertSame(0, $run['result']['converted']);
        self::assertSame(1, $run['result']['skipped'], 'the row span is counted; the markdown table is simply not DokuWiki');
    }

    public function testAPipeInsideALinkDoesNotSplitACell(): void
    {
        [$run] = $this->run1('dokuTables', [self::DOC . "^ A ^ B ^\n| [[ns:page|Page]] | `x|y` |\n", 0, 0]);

        self::assertSame(self::DOC . "| A | B |\n| --- | --- |\n| [Page](ns:page) | `x|y` |\n", $run['text']);
    }

    public function testTheScriptIsServedToSignedInCallersWhilePluginIsEnabled(): void
    {
        $this->config['plugins'] = ['enabled' => ['dokuwiki']];
        $response = $this->get('viewer', '/x/dokuwiki/js/tools.js');

        self::assertSame(200, $response->status);
        self::assertSame('text/javascript; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringContainsString('dokuTables', $response->body);
        self::assertSame(404, $this->anonymous('/x/dokuwiki/js/tools.js')->status);

        $this->config['plugins'] = ['enabled' => []];
        self::assertSame(404, $this->get('viewer', '/x/dokuwiki/js/tools.js')->status, 'switched off: no script');
    }

    public function testTheEditorOffersTheToolsOnlyWhilePluginIsEnabled(): void
    {
        $this->config['plugins'] = ['enabled' => ['dokuwiki']];
        $on = $this->get('owner', '/' . self::PAGE . '/edit')->body;

        foreach (['dokuLinks', 'dokuMarkup', 'dokuTables'] as $tool) {
            self::assertStringContainsString('data-edit-tool="' . $tool . '"', $on);
        }
        self::assertStringContainsString('src="/x/dokuwiki/js/tools.js"', $on);
        self::assertStringContainsString('DokuWiki tables to Markdown', $on);

        $this->config['plugins'] = ['enabled' => []];
        $off = $this->get('owner', '/' . self::PAGE . '/edit')->body;

        self::assertStringNotContainsString('dokuwiki', $off);
        self::assertStringContainsString('data-edit-tool="cleanText"', $off, 'the editor keeps its own tools');
        self::assertStringContainsString('data-edit-tool="unitsDegrees"', $off);
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function get(string $user, string $path): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);

        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: ['reporion' => $cookie]));
    }

    private function anonymous(string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path));
    }

    /**
     * @param list<mixed> $args
     *
     * @return list<array{result: mixed, text: string}>
     */
    private function run1(string $fn, array $args): array
    {
        $process = proc_open(
            ['node', \dirname(__DIR__, 2) . '/tools/run-dokuwiki-tools.js'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!\is_resource($process)) {
            throw new RuntimeException('Could not start node');
        }
        fwrite($pipes[0], (string) json_encode([['fn' => $fn, 'args' => $args]]));
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('dokuwiki tools harness failed: ' . $err);
        }

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
}
