<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\Output;
use Reporion\Cli\TemplatesLintCommand;

/**
 * bin/reporion templates:lint: report templates read from their files, no
 * database, snippets and page templates left out. Fixtures are fictitious.
 */
final class TemplatesLintCommandTest extends TestCase
{
    private string $dataRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-lint-test-' . bin2hex(random_bytes(6));
        $this->page('templates/mri/genunchi.md', "# Genunchi\n\n## IRM Genunchi\n\n[Meniscurile sunt normale. / Meniscul [medial / lateral] este rupt.]\n");
        $this->page('templates/ct/cerebral.md', "# Cap\n\n## CT Cerebral\n\nSistem ventricular [normal / modificări] — simetric.\n");
        $this->page('templates/snippets/norm.md', "Text — cu liniuță.\n");
        $this->page('templates/pages/meeting.md', "# Ședință\n\nA / B.\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataRoot));
        parent::tearDown();
    }

    public function testItListsTheTemplatesWithProblemsAndExitsOne(): void
    {
        [$code, $out] = $this->run_([]);

        self::assertSame(1, $code);
        self::assertStringContainsString("templates:ct:cerebral\n", $out);
        self::assertStringContainsString('line 5, a dash', $out);
        self::assertStringNotContainsString('genunchi', $out);
        self::assertStringNotContainsString('snippets', $out);
        self::assertStringNotContainsString('meeting', $out);
        self::assertStringContainsString('2 template(s) checked, 1 with problems', $out);
        self::assertFileDoesNotExist($this->dataRoot . '/index.sqlite');
    }

    public function testANamespaceAndJson(): void
    {
        [$code, $out] = $this->run_(['--ns=templates:mri', '--json']);

        self::assertSame(0, $code);
        self::assertSame(['checked' => 1, 'templates' => []], json_decode($out, true));

        [, $out] = $this->run_(['--ns=templates:ct', '--json']);
        $codes = array_column(json_decode($out, true)['templates']['templates:ct:cerebral'], 'code');
        self::assertSame(['dash', 'vague'], $codes);
    }

    private function page(string $relative, string $body): void
    {
        $file = $this->dataRoot . '/pages/' . $relative;
        @mkdir(\dirname($file), 0775, true);
        file_put_contents($file, "---\nvisibility: private\n---\n" . $body);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private function run_(array $args): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $code = (new TemplatesLintCommand($this->dataRoot))->run($args, new Output($stdout, $stderr));
        rewind($stdout);

        return [$code, (string) stream_get_contents($stdout)];
    }
}
