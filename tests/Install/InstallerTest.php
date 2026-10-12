<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Install;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Install\Check;
use Reporion\Install\ConfigWriter;
use Reporion\Install\Environment;
use Reporion\Install\Installer;
use Reporion\Install\Requirements;
use Reporion\Install\ServerConfig;
use RuntimeException;

/**
 * The web installer (D43): when it may run at all, who holds it, what it
 * writes, and how it judges the browser's probe. The whole flow through
 * HTTP (Composer, the browser probe) is checked by hand on a throwaway
 * copy — see docs/BUILD_LOG.md.
 */
final class InstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/reporion-install-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/conf', 0775, true);
        mkdir($this->root . '/public', 0775, true);
        $repo = \dirname(__DIR__, 2);
        copy($repo . '/conf/local.php.example', $this->root . '/conf/local.php.example');
        copy($repo . '/composer.json', $this->root . '/composer.json');
        symlink($repo . '/migrations', $this->root . '/migrations');
        symlink($repo . '/vendor', $this->root . '/vendor');
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function testAnInstanceWithItsConfigurationIsInstalledUnlessAnInstallIsInProgress(): void
    {
        self::assertFalse(Installer::isInstalled($this->root));

        file_put_contents($this->root . '/conf/local.php', '<?php return [];');
        // A hand-made install (or this box's own): no owner check, no way back in
        self::assertTrue(Installer::isInstalled($this->root));

        file_put_contents($this->root . '/' . Installer::STATE_FILE, '{}');
        self::assertFalse(Installer::isInstalled($this->root));
    }

    public function testTheFirstBrowserHoldsTheInstall(): void
    {
        $installer = new Installer($this->root);
        $installer->claim('first', [Check::pass('PHP version', '8.1')], 'http://localhost');

        self::assertTrue($installer->owns('first'));
        self::assertFalse($installer->owns('second'));
        self::assertTrue($installer->heldByOther('second'));
        self::assertTrue(is_dir($this->root . '/data'));
        self::assertSame(02770, fileperms($this->root . '/data') & 07777);

        $this->expectException(RuntimeException::class);
        (new Installer($this->root))->claim('second', [], 'http://localhost');
    }

    public function testAnAbandonedInstallCanBeTakenOverOnlyBeforeItsOwnerExists(): void
    {
        $installer = new Installer($this->root);
        $installer->claim('first', [], 'http://localhost');
        $this->age(7200);
        self::assertFalse((new Installer($this->root))->heldByOther('second'));

        $installer = new Installer($this->root);
        $installer->markDone('configure');
        $this->age(7200);
        self::assertTrue((new Installer($this->root))->heldByOther('second'));
    }

    public function testTheLogHoldsNoSecret(): void
    {
        $installer = $this->secured();
        $errors = $installer->configure($this->form());
        self::assertSame([], $errors);

        $log = (string) file_get_contents($this->root . '/data/' . Installer::LOG_FILE);
        $config = require $this->root . '/conf/local.php';
        $secret = (string) $config['auth']['session_secret'];
        self::assertSame(64, \strlen($secret));
        self::assertStringNotContainsString($secret, $log);
        self::assertStringNotContainsString('a-long-password', $log);
        self::assertStringNotContainsString('$argon2id$', $log);
        self::assertSame(0640, fileperms($this->root . '/data/' . Installer::LOG_FILE) & 0777);
        self::assertSame(0640, fileperms($this->root . '/conf/local.php') & 0777);
    }

    public function testConfigureWritesTheConfigurationTheOwnerAndTheIndex(): void
    {
        $installer = $this->secured();
        self::assertSame([], $installer->configure($this->form()));

        $config = require $this->root . '/conf/local.php';
        self::assertSame('Imaging', $config['site']['title']);
        self::assertSame('https://reports.example.test/reporion', $config['site']['base_url']);
        self::assertSame('Europe/Bucharest', $config['site']['timezone']);

        $owner = (new FlatFileUserStore($this->root . '/data'))->find('owner');
        self::assertNotNull($owner);
        self::assertTrue($owner->isOwner);
        self::assertTrue(password_verify('a-long-password', $owner->passwordHash));
        self::assertFileExists($this->root . '/data/index.sqlite');
        self::assertStringContainsString('reports.example.test', (string) file_get_contents($this->root . '/data/settings.yaml'));
        self::assertTrue($installer->done('configure'));

        // A re-run keeps the session secret and updates the same owner
        self::assertSame([], $installer->configure(['password' => 'another-password', 'password2' => 'another-password'] + $this->form()));
        $again = require $this->root . '/conf/local.php';
        self::assertSame($config['auth']['session_secret'], $again['auth']['session_secret']);
        $owner = (new FlatFileUserStore($this->root . '/data'))->find('owner');
        self::assertNotNull($owner);
        self::assertTrue(password_verify('another-password', $owner->passwordHash));

        // …and never creates a second owner
        self::assertNotSame([], $installer->configure(['username' => 'other'] + $this->form()));
    }

    public function testConfigureRefusesABadForm(): void
    {
        $installer = $this->secured();
        $errors = $installer->configure([
            'site_title' => '',
            'base_url' => 'javascript:alert(1)',
            'timezone' => 'Mars/Olympus',
            'username' => 'Bad Name',
            'password' => 'short',
            'password2' => 'short',
        ]);

        self::assertCount(5, $errors);
        self::assertFileDoesNotExist($this->root . '/conf/local.php');
        self::assertFalse($installer->done('configure'));
    }

    public function testTheProbeFailsWhenPrivateFilesAreServed(): void
    {
        $installer = new Installer($this->root);
        $installer->claim('token', [], 'http://localhost');
        $installer->update(['canary' => 'c4n4ry']);
        $good = [
            Installer::ASSET_PROBE => ['status' => 200, 'body' => ':root { --color-bg: #000; }'],
            'data/.install-probe.txt' => ['status' => 404, 'body' => 'Not found'],
        ];

        self::assertFalse(Check::anyFailed($installer->judgeLeaks($good)));

        $leaky = ['data/.install-probe.txt' => ['status' => 200, 'body' => 'c4n4ry']] + $good;
        $checks = $installer->judgeLeaks($leaky);
        self::assertSame(Check::FAIL, $checks[0]->status);
        self::assertStringContainsString('data/.install-probe.txt', $checks[0]->detail);

        // requests that never completed prove nothing: not "private", and the reason is shown
        $failed = [
            Installer::ASSET_PROBE => ['status' => 0, 'body' => '', 'error' => "Failed to fetch\n — page https://x"],
            'composer.json' => ['status' => 0, 'body' => '', 'error' => 'Failed to fetch'],
        ] + $good;
        $checks = $installer->judgeLeaks($failed);
        self::assertSame(Check::FAIL, $checks[0]->status);
        self::assertStringContainsString('composer.json', $checks[0]->detail);
        self::assertSame(Check::FAIL, $checks[1]->status);
        self::assertStringContainsString('Failed to fetch', $checks[1]->detail);

        self::assertSame(Check::PASS, $installer->judgeRouting([Installer::LOGIN_PROBE => ['status' => 200, 'body' => '<input name="password">']])->status);
        self::assertSame(Check::FAIL, $installer->judgeRouting([Installer::LOGIN_PROBE => ['status' => 404, 'body' => '']])->status);
    }

    /**
     * Nothing secret is written before the probe has seen data/ is not
     * served (root mode on a server not configured yet), whatever a
     * request asks for
     */
    public function testTheStepsRunInOrder(): void
    {
        $installer = new Installer($this->root);
        $installer->claim('token', [], 'http://localhost');

        try {
            $installer->configure($this->form());
            self::fail('configure() ran before the Security step');
        } catch (RuntimeException) {
        }
        self::assertFileDoesNotExist($this->root . '/conf/local.php');
        self::assertDirectoryDoesNotExist($this->root . '/data/users');
        self::assertSame('dependencies', $installer->current());

        foreach ([static fn () => $installer->secure(), static fn () => $installer->verify([]), static fn () => $installer->finish(false)] as $step) {
            try {
                $step();
                self::fail('a step ran out of order');
            } catch (RuntimeException) {
            }
        }
        self::assertSame(['requirements', 'dependencies', 'secure', 'configure', 'verify'], Installer::STEPS);
    }

    public function testFinishEndsTheInstall(): void
    {
        file_put_contents($this->root . '/conf/local.php', '<?php return [];');
        $installer = new Installer($this->root);
        $installer->claim('token', [], 'http://localhost');
        $installer->markDone('configure');
        self::assertFalse(Installer::isInstalled($this->root));

        $installer->finish(false);

        self::assertTrue(Installer::isInstalled($this->root));
        self::assertStringContainsString('install finished', (string) file_get_contents($this->root . '/data/' . Installer::LOG_FILE));
    }

    public function testConfigWriterKeepsTheExampleAndItsComments(): void
    {
        $example = (string) file_get_contents($this->root . '/conf/local.php.example');
        $php = ConfigWriter::render($example, [
            'site.title' => "O'Brien Imaging",
            'site.base_url' => 'https://x.test',
            'site.timezone' => 'UTC',
            'auth.session_secret' => str_repeat('ab', 32),
        ]);
        $file = $this->root . '/conf/rendered.php';
        file_put_contents($file, $php);
        $config = require $file;

        self::assertSame("O'Brien Imaging", $config['site']['title']);
        self::assertSame(str_repeat('ab', 32), $config['auth']['session_secret']);
        self::assertSame('reporion', $config['auth']['session_name']);
        self::assertStringContainsString('HMAC key for the signed session cookie', $php);

        $this->expectException(RuntimeException::class);
        ConfigWriter::render('<?php return [];', ['site.title' => 'x']);
    }

    public function testRequirementsNameTheFixForWhatIsMissing(): void
    {
        $env = new Environment(80012, '8.0.12', ['ffi'], false, 'no fts5', 'FFI API is restricted by "ffi.enable" configuration directive', [
            'memory_limit' => '128M', 'max_execution_time' => '30', 'post_max_size' => '8M', 'upload_max_filesize' => '2M', 'open_basedir' => '', 'disable_functions' => 'proc_open', 'ffi.enable' => 'preload',
        ], 'www-data', 'fpm-fcgi', 'lighttpd/1.4.63');
        $requirements = new Requirements($this->root, $env);

        self::assertSame(Check::FAIL, $requirements->phpVersion()->status);
        $extensions = $requirements->extensions();
        self::assertSame(Check::FAIL, $extensions->status);
        self::assertStringContainsString('php8.0-intl', $extensions->fix);
        self::assertSame(Check::FAIL, $requirements->argon2()->status);
        self::assertSame(Check::FAIL, $requirements->fts5()->status);
        self::assertStringContainsString('php_admin_value[ffi.enable] = 1', $requirements->ffi()->fix);
        self::assertSame(Check::WARN, $requirements->limits()->status);
        // vendor/ is there (a link to the repo's); with it gone and proc_open disabled, Composer is the user's
        unlink($this->root . '/vendor');
        self::assertSame(Check::FAIL, $requirements->vendor()->status);
        self::assertStringContainsString('composer install --no-dev', $requirements->vendor()->fix);
        self::assertSame(Check::PASS, $requirements->dataDirectory()->status);
    }

    public function testBytesReadsPhpIniShorthand(): void
    {
        self::assertSame(256 * 1024 * 1024, Requirements::bytes('256M'));
        self::assertSame(2 * 1024 * 1024 * 1024, Requirements::bytes('2G'));
        self::assertSame(-1, Requirements::bytes('-1'));
    }

    public function testServerConfigInRootModeRoutesEverythingButThePublicFilesToTheFrontController(): void
    {
        $lighttpd = ServerConfig::lighttpd('/srv/reporion', '/reporion', true);
        self::assertStringContainsString('url.rewrite-once', $lighttpd);
        self::assertStringContainsString('"/reporion/index.php/$1$2"', $lighttpd);
        self::assertStringContainsString('alias.url += ( "/reporion/" => "/srv/reporion/public/" )', ServerConfig::lighttpd('/srv/reporion', '/reporion', false));
        self::assertStringContainsString('server.document-root = "/srv/reporion/public"', ServerConfig::lighttpd('/srv/reporion', '', false));
        self::assertStringContainsString('location ~ ^/reporion/(data|conf|', ServerConfig::nginx('/srv/reporion', '/reporion', true, '/run/php/php8.1-fpm.sock'));
        self::assertSame('/reporion', ServerConfig::basePath('/reporion/install.php'));
        self::assertSame('', ServerConfig::basePath('/install.php'));
    }

    /** An install past its Security step, as configure() requires */
    private function secured(): Installer
    {
        $installer = new Installer($this->root);
        $installer->claim('token', [], 'http://localhost');
        $installer->markDone('dependencies');
        $installer->markDone('secure');

        return $installer;
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'site_title' => 'Imaging',
            'base_url' => 'https://reports.example.test/reporion/',
            'timezone' => 'Europe/Bucharest',
            'username' => 'owner',
            'display_name' => 'Dr. Owner',
            'password' => 'a-long-password',
            'password2' => 'a-long-password',
        ];
    }

    private function age(int $seconds): void
    {
        $file = $this->root . '/' . Installer::STATE_FILE;
        $state = json_decode((string) file_get_contents($file), true);
        $state['touched'] = time() - $seconds;
        file_put_contents($file, json_encode($state));
    }
}
