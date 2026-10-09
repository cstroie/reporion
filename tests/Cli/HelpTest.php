<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\Application;
use Reporion\Cli\Output;

/**
 * Every bin/reporion command answers --help with what it does and its
 * options, without building the command (so without opening the index), and
 * every run says what it does before it does it — except with --json, whose
 * stdout must stay the JSON document.
 */
final class HelpTest extends TestCase
{
    /** Every command the application registers, with the dicom plugin enabled */
    private const COMMANDS = [
        'doctor', 'serve', 'ai:check', 'index:verify', 'integrity:verify', 'index:rebuild', 'index:vectors',
        'pages:apply-patient-csv', 'pages:summarize', 'pages:tag', 'pacs:link', 'journal:replay', 'trash:purge',
        'page:new', 'page:move', 'user:create', 'dicom:header',
    ];

    private string $dataRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-help-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    public function testEveryCommandHasHelpAndHelpOpensNoIndex(): void
    {
        $config = $this->config();
        $names = array_keys(Application::boot($config)->commands());
        foreach (self::COMMANDS as $command) {
            self::assertContains($command, $names, $command . ' is registered');
        }

        foreach (self::COMMANDS as $command) {
            [$code, $out] = $this->runCli($config, [$command, '--help']);
            self::assertSame(0, $code, $command);
            self::assertStringStartsWith('Usage: bin/reporion ' . $command, $out, $command);
            self::assertStringContainsString("\n\n", $out, $command . ' has a summary');
        }
        self::assertFileDoesNotExist($this->dataRoot . '/index.sqlite', '--help never builds a command, so it opens no index');
    }

    public function testTopLevelHelpListsEveryCommandWithItsSummary(): void
    {
        [$code, $out] = $this->runCli($this->config(), ['--help']);

        self::assertSame(0, $code);
        foreach (self::COMMANDS as $command) {
            self::assertStringContainsString('  ' . $command, $out, $command);
        }
    }

    public function testARunSaysWhatItDoesFirstButNotUnderJson(): void
    {
        $config = $this->config();
        $summary = Application::boot($config)->commands()['index:verify']->summary;

        [, $plain] = $this->runCli($config, ['index:verify']);
        self::assertSame($summary, strtok($plain, "\n"), 'the summary line comes before the work');

        [$code, $json] = $this->runCli($config, ['index:verify', '--json']);
        self::assertSame(0, $code);
        self::assertStringStartsWith('{', $json, 'stdout stays the JSON document');
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'auth' => ['session_secret' => 'x'],
            'paths' => [
                'data' => $this->dataRoot,
                'index' => $this->dataRoot . '/index.sqlite',
            ],
            'plugins' => ['enabled' => ['dicom']],
            'site' => ['timezone' => 'UTC', 'base_url' => ''],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $args   argv beyond bin/reporion
     *
     * @return array{0: int, 1: string} exit code and stdout
     */
    private function runCli(array $config, array $args): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $code = Application::boot($config, new Output($stdout, $stderr))->run(['bin/reporion', ...$args]);
        rewind($stdout);

        return [$code, (string) stream_get_contents($stdout)];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
