<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\User;
use Reporion\Cli\DoctorCommand;
use Reporion\Cli\Output;

final class DoctorCommandTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/reporion-doctor-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function testTemplatesOutsideTheShapeAreAWarningAndNeverAFailure(): void
    {
        $this->createOwnerAccount();
        foreach (['ct/a' => "---\ntitle: Old\n---\n\nText.\n", 'ct/b' => "---\nvisibility: private\n---\n\n# Name\n\n## Exam\n", 'snippets/s' => "---\n---\n\nx\n"] as $page => $document) {
            @mkdir(\dirname($this->dataDir . '/pages/templates/' . $page), 0775, true);
            file_put_contents($this->dataDir . '/pages/templates/' . $page . '.md', $document);
        }
        \Reporion\Storage\Databases::openState($this->dataDir . '/reporion.sqlite');

        [$exitCode, $output] = $this->runCommand(new DoctorCommand($this->config()));

        self::assertSame(0, $exitCode, 'a WARN never fails the run');
        self::assertStringContainsString('[WARN] Template pages in the D45 shape: 1 of 2 templates', $output);
    }

    /** D54 §9: the permanent database, never silently re-created over pages */
    public function testAMissingProgramNarrowsWhatTheLibraryReadsAndOnlyWarns(): void
    {
        $this->createOwnerAccount();
        $config = $this->config();
        $config['library']['tools'] = ['pdftotext' => '/nonexistent/pdftotext', 'pandoc' => '/nonexistent/pandoc', 'soffice' => '/nonexistent/soffice'];

        [$exitCode, $output] = $this->runCommand(new DoctorCommand($config));

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[WARN] Library can read uploaded files: not PDF (install poppler-utils); Word, OpenDocument, RTF, HTML and EPUB (install pandoc); .doc and office slides and sheets (install LibreOffice)', $output);
    }

    public function testTheLibraryLookupIsReportedOffOrOn(): void
    {
        $this->createOwnerAccount();
        [, $output] = $this->runCommand(new DoctorCommand($this->config()));
        self::assertStringContainsString('[PASS] Library online lookups (CrossRef, PubMed) and web clip: off', $output);

        $config = $this->config();
        $config['library']['lookup'] = true;
        [$exitCode, $output] = $this->runCommand(new DoctorCommand($config));
        self::assertSame(0, $exitCode, 'never a failure');
        self::assertMatchesRegularExpression('/\[(PASS|WARN)\] Library online lookups \(CrossRef, PubMed\) and web clip: lookups on/', $output);
    }

    public function testAMissingOrDamagedStateDatabaseBesidePagesFails(): void
    {
        $this->createOwnerAccount();
        [$exitCode, $output] = $this->runCommand(new DoctorCommand($this->config()));
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[PASS] State database (data/reporion.sqlite) present and sound: not created yet', $output);

        mkdir($this->dataDir . '/pages/docs', 0775, true);
        file_put_contents($this->dataDir . '/pages/docs/a.md', "---\ntitle: A\n---\n\nx\n");
        [$exitCode, $output] = $this->runCommand(new DoctorCommand($this->config()));
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] State database (data/reporion.sqlite) present and sound: missing while data/pages/ holds pages', $output);
        self::assertFileDoesNotExist($this->dataDir . '/reporion.sqlite', 'doctor creates nothing');

        file_put_contents($this->dataDir . '/reporion.sqlite', str_repeat('not a database ', 100));
        [$exitCode, $output] = $this->runCommand(new DoctorCommand($this->config()));
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] State database (data/reporion.sqlite) present and sound', $output);

        unlink($this->dataDir . '/reporion.sqlite');
        \Reporion\Storage\Databases::openState($this->dataDir . '/reporion.sqlite');
        [, $output] = $this->runCommand(new DoctorCommand($this->config()));
        self::assertStringContainsString('[PASS] State database (data/reporion.sqlite) present and sound', $output);
    }

    public function testReportsWithAnExamWithoutATemplateAreAWarning(): void
    {
        $this->createOwnerAccount();
        $reports = [
            'mri/mioveni/a' => ['exams' => [['title' => 'A', 'template' => 'templates:mri:a'], ['title' => 'B']]],
            'mri/mioveni/b' => ['exams' => [['title' => 'C', 'template' => 'templates:mri:c']]],
            'mri/mioveni/c' => ['exam_title' => 'Old', 'template' => 'templates:mri:old'],
            'mri/mioveni/d' => ['exams' => [['title' => 'Signed']]],
        ];
        foreach ($reports as $page => $frontmatter) {
            @mkdir(\dirname($this->dataDir . '/pages/reports/' . $page), 0775, true);
            file_put_contents($this->dataDir . '/pages/reports/' . $page . '.md', \Reporion\Support\DocumentFormat::encode($frontmatter, "x\n"));
        }
        // The signed one, as the state database says (D54)
        \Reporion\Storage\Databases::openState($this->dataDir . '/reporion.sqlite')->exec(
            "INSERT INTO page (pid, path, rev, status, created, created_by, file_sha, file_mtime, file_bytes)
             VALUES ('01JB8X4MT7QK2V9Z0C3R5H6ND0', 'reports:mri:mioveni:d', 1, 'signed', 't', 'u', 's', 0, 0)"
        );

        [$exitCode, $output] = $this->runCommand(new DoctorCommand($this->config()));

        self::assertSame(0, $exitCode, 'a WARN never fails the run');
        self::assertStringContainsString('[WARN] Report exams with their own template: 1 of 3 unsigned reports', $output, 'B has none; the older flat one has its own; a signed one is left alone');
    }

    public function testAllPassingConfigExitsZero(): void
    {
        $this->createOwnerAccount();
        $command = new DoctorCommand($this->config());

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[PASS] PHP version', $output);
        self::assertStringContainsString('[PASS] At least one active owner account exists', $output);
        self::assertStringContainsString('[PASS] data/ writable', $output);
        self::assertStringContainsString('[PASS] AI settings in the current shape', $output);
    }

    public function testTheLastIntegrityRunIsReported(): void
    {
        $this->createOwnerAccount();
        [, $output] = $this->runCommand(new DoctorCommand($this->config()));
        self::assertStringContainsString('[WARN] Archive integrity verified in the last 8 days: never run', $output);

        mkdir($this->dataDir . '/maintenance/runs', 0775, true);
        $run = static fn (string $finished, int $exit, int $problems): string => (string) json_encode(['task' => 'integrity:verify', 'finished' => $finished, 'exit' => $exit, 'summary' => ['problems' => $problems]]);
        file_put_contents($this->dataDir . '/maintenance/runs/20260101-000000-aaaaaa.json', $run(date('c', strtotime('-20 days')), 0, 0));
        [, $output] = $this->runCommand(new DoctorCommand($this->config()));
        self::assertStringContainsString('last run 20 days ago', $output);

        file_put_contents($this->dataDir . '/maintenance/runs/20260102-000000-bbbbbb.json', $run(date('c'), 1, 3));
        [, $output] = $this->runCommand(new DoctorCommand($this->config()));
        self::assertStringContainsString('found 3 problem(s)', $output);

        file_put_contents($this->dataDir . '/maintenance/runs/20260103-000000-cccccc.json', $run(date('c'), 0, 0));
        [$exit, $output] = $this->runCommand(new DoctorCommand($this->config()));
        self::assertStringContainsString('[PASS] Archive integrity verified in the last 8 days: last run 0 day(s) ago, all intact', $output);
    }

    public function testNoOwnerAccountFails(): void
    {
        // No createOwnerAccount() call: data/users/ stays empty.
        $command = new DoctorCommand($this->config());

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] At least one active owner account exists', $output);
    }

    public function testAnOwnerAccountThatIsDeactivatedStillFails(): void
    {
        $this->createOwnerAccount(active: false);
        $command = new DoctorCommand($this->config());

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] At least one active owner account exists', $output);
    }

    public function testEmptySessionSecretFails(): void
    {
        $config = $this->config();
        $config['auth']['session_secret'] = '';
        $command = new DoctorCommand($config);

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] Session secret configured', $output);
    }

    public function testInvalidTimezoneFails(): void
    {
        $config = $this->config();
        $config['site']['timezone'] = 'Not/A/Real/Zone';
        $command = new DoctorCommand($config);

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] Timezone configured', $output);
    }

    public function testOldFlatAiKeysWarn(): void
    {
        $this->createOwnerAccount();
        file_put_contents($this->dataDir . '/settings.yaml', "ai:\n  endpoint: 'http://127.0.0.1:8080/v1'\n  profiles: { reports: reports }\n");
        $config = $this->config();
        $config['ai'] = ['model' => 'm'];

        [$exitCode, $output] = $this->runCommand(new DoctorCommand($config));

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[WARN] AI settings in the current shape', $output);
        self::assertStringContainsString('conf/local.php: ai.model', $output);
        self::assertStringContainsString('data/settings.yaml: ai.endpoint', $output);
        self::assertStringContainsString('data/settings.yaml: ai.profiles', $output);
    }

    public function testAnUnparseableSettingsOrTagsFileFails(): void
    {
        $this->createOwnerAccount();
        file_put_contents($this->dataDir . '/tags.yaml', "SM: {group: diagnosis\n");

        [$exitCode, $output] = $this->runCommand(new DoctorCommand($this->config()));

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] data/settings.yaml and data/tags.yaml parse', $output);
        self::assertStringContainsString('data/tags.yaml (line', $output);
        self::assertStringNotContainsString('data/settings.yaml (line', $output);
    }

    /**
     * The bug this guards against: is_writable() alone would not have
     * caught tonight's real permission mismatch (a directory group-writable
     * for www-data while a specific file inside it was still costin-owned,
     * 644). A real write-and-delete is the only check narrow enough to
     * match what actually broke.
     */
    public function testUnwritableDataDirectoryFails(): void
    {
        chmod($this->dataDir, 0555);
        $command = new DoctorCommand($this->config());

        try {
            [$exitCode, $output] = $this->runCommand($command);
        } finally {
            // Restore regardless of assertion outcome, or a failed
            // assertion here leaves tearDown() unable to remove the
            // directory and a stray fixture behind.
            chmod($this->dataDir, 0775);
        }

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] data/ writable', $output);
    }

    public function testMissingDataDirectoryFails(): void
    {
        $config = $this->config();
        $config['paths']['data'] = $this->dataDir . '/does-not-exist';
        $command = new DoctorCommand($config);

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] data/ writable', $output);
        self::assertStringContainsString('[FAIL] data/audit writable', $output);
        self::assertStringContainsString('INSTALL.md', $output);
        self::assertDirectoryDoesNotExist($this->dataDir . '/does-not-exist', 'doctor must not create data/ as a side effect');
    }

    public function testArgon2AndTheRequiredExtensionsPass(): void
    {
        [, $output] = $this->runCommand(new DoctorCommand($this->config()));

        self::assertStringContainsString('[PASS] Argon2id password hashing', $output);
        self::assertMatchesRegularExpression('/\[PASS\] PHP extensions: .*\bgd\b/', $output);
        self::assertMatchesRegularExpression('/\[PASS\] PHP extensions: .*\biconv\b/', $output);
    }

    public function testWorldReadableLocalConfigWarns(): void
    {
        $file = $this->dataDir . '/local.php';
        file_put_contents($file, "<?php return [];\n");

        chmod($file, 0644);
        [$exitCode, $output] = $this->runCommand(new DoctorCommand($this->config(), $file));
        self::assertSame(1, $exitCode, 'only the missing owner account fails');
        self::assertStringContainsString('[WARN] conf/local.php not world-readable: mode 0644', $output);

        chmod($file, 0640);
        [, $output] = $this->runCommand(new DoctorCommand($this->config(), $file));
        self::assertStringContainsString('[PASS] conf/local.php not world-readable: mode 0640', $output);
    }

    /**
     * The bug this guards against: an earlier version of this check only
     * looked at the HTTP status code, which is ambiguous: the app's own
     * /{path} route matches "/.git/config" too and 404s on it exactly like
     * a correctly-configured docroot would. This spins up a real server
     * exposing an actual .git/config (mirroring exactly what leaked earlier
     * tonight) and proves the content signature, not just the status code,
     * is what FAILs the check.
     */
    public function testDetectsARealExposedGitConfigLiveOverHttp(): void
    {
        $docroot = $this->dataDir . '/fake-docroot';
        mkdir($docroot . '/.git', 0775, true);
        file_put_contents($docroot . '/.git/config', "[core]\n\trepositoryformatversion = 0\n");

        $port = $this->freePort();
        $process = proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        usleep(300_000); // let the built-in server finish starting

        try {
            $config = $this->config();
            $config['site']['base_url'] = "http://127.0.0.1:{$port}";
            $command = new DoctorCommand($config);

            [$exitCode, $output] = $this->runCommand($command);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] Docroot is public/ only', $output);
    }

    public function testDetectsAnExposedComposerJsonWhenTheDocrootIsTheRepositoryRoot(): void
    {
        $docroot = $this->dataDir . '/fake-docroot';
        mkdir($docroot, 0775, true);
        file_put_contents($docroot . '/composer.json', '{"name": "reporion/reporion"}');

        $port = $this->freePort();
        $process = proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        usleep(300_000);

        try {
            $config = $this->config();
            $config['site']['base_url'] = "http://127.0.0.1:{$port}";
            [$exitCode, $output] = $this->runCommand(new DoctorCommand($config));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] Docroot is public/ only', $output);
        self::assertStringContainsString('/composer.json served as plain text', $output);
        self::assertStringNotContainsString('/.git/config served', $output);
    }

    public function testUnreachableBaseUrlWarnsRatherThanFails(): void
    {
        $this->createOwnerAccount();
        $config = $this->config();
        $config['site']['base_url'] = 'http://127.0.0.1:1'; // reserved, nothing listens
        $command = new DoctorCommand($config);

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[WARN] Docroot is public/ only', $output);
        self::assertStringContainsString('[WARN] Front controller reachable', $output);
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $port = (int) substr((string) stream_socket_get_name($socket, false), (int) strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        return $port;
    }

    private function createOwnerAccount(bool $active = true): void
    {
        $store = new FlatFileUserStore($this->dataDir);
        $user = $store->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        if (!$active) {
            $store->save(new User(
                $user->username,
                $user->passwordHash,
                $user->isOwner,
                $user->grants,
                false,
                $user->createdAt,
                $user->updatedAt,
            ));
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCommand(DoctorCommand $command): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $exitCode = $command->run([], new Output($stdout, $stderr));

        rewind($stdout);
        $output = (string) stream_get_contents($stdout);
        fclose($stdout);
        fclose($stderr);

        return [$exitCode, $output];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'auth' => [
                'session_secret' => 'x',
            ],
            'paths' => [
                'data' => $this->dataDir,
            ],
            'site' => [
                'timezone' => 'Europe/Bucharest',
                'base_url' => '',
            ],
        ];
    }
}
