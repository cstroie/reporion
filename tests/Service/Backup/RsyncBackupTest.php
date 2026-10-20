<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Backup;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Service\Backup\RsyncBackup;
use Reporion\Service\Backup\RsyncConfig;
use Reporion\Service\InstanceSettings;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\RsyncBackupTask;

/**
 * The periodic rsync backup (D49) against fake rsync and ssh programs that
 * log their argument lists and answer from files: what is run, in which
 * order, what is pruned, and that nothing the programs print reaches a
 * result (a page directory names the patient, invariant 8).
 */
final class RsyncBackupTest extends TestCase
{
    private const PATIENT = '261008-popescu-ion';

    private string $base;
    private string $data;
    private string $rsync;
    private string $ssh;
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/reporion-rsync-test-' . bin2hex(random_bytes(6));
        $this->data = $this->base . '/data';
        mkdir($this->data . '/maintenance', 0775, true);
        $this->log = $this->base . '/calls.log';
        $this->rsync = $this->base . '/rsync';
        $this->ssh = $this->base . '/ssh';
        $b = $this->base;
        file_put_contents($this->rsync, <<<SH
#!/bin/sh
printf 'rsync %s\\n' "\$*" >> $b/calls.log
echo "rsync: send_files failed to open /srv/data/pages/reports/mri/x/y/" . self::PATIENT . "/current.md: Permission denied (13)" >&2
case "\$*" in *--list-only*) cat $b/rsync-list 2>/dev/null; exit 0;; esac
echo "building file list" ; echo "reports/x/" . self::PATIENT . "/current.md"
echo "Number of files: 1,234 (reg: 1,000, dir: 234)"
echo "Number of regular files transferred: 12"
echo "Total file size: 5,678,901 bytes"
echo "Total bytes sent: 4,321"
[ -f $b/rsync.exit ] && exit "\$(cat $b/rsync.exit)"
exit 0
SH);
        file_put_contents($this->ssh, <<<'SH'
#!/bin/sh
for last; do :; done
printf 'ssh %s\n' "$last" >> LOG
case "$last" in
  "ls -1"*) cat BASE/listing 2>/dev/null ;;
esac
echo "ssh: could not resolve PATIENT" >&2
[ -f BASE/ssh.exit ] && exit "$(cat BASE/ssh.exit)"
exit 0
SH);
        file_put_contents($this->ssh, str_replace(['LOG', 'BASE', 'PATIENT'], [$this->log, $this->base, self::PATIENT], (string) file_get_contents($this->ssh)));
        chmod($this->rsync, 0755);
        chmod($this->ssh, 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $over
     */
    private function config(array $over = []): RsyncConfig
    {
        return RsyncConfig::fromSettings(['backup' => $over + ['enabled' => true, 'host' => 'backup.example.org', 'port' => 2222, 'user' => 'backup', 'remote_dir' => '/srv/backups/reporion', 'ssh_key' => '', 'keep' => 'week', 'interval_hours' => 24]]);
    }

    private function backup(RsyncConfig $config): RsyncBackup
    {
        return new RsyncBackup($config, $this->data, $this->base . '/conf/local.php', $this->rsync, $this->ssh);
    }

    /** @return list<string> */
    private function calls(): array
    {
        return is_file($this->log) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))) : [];
    }

    public function testAHostOrPathThatWouldReachSshOrTheShellIsRefused(): void
    {
        foreach (['-oProxyCommand=x', 'a b', 'host;rm', "h'ost", ''] as $host) {
            self::assertFalse(RsyncConfig::validHost($host), $host);
        }
        foreach (['/srv/../etc', "/srv/it's", '/', '..', '/a b', '-rf', ''] as $dir) {
            self::assertFalse(RsyncConfig::validRemoteDir($dir), $dir);
        }
        self::assertTrue(RsyncConfig::validRemoteDir('/srv/backups/reporion'));
        self::assertTrue(RsyncConfig::validRemoteDir('backups/reporion'));
        self::assertFalse(RsyncConfig::validKeyPath('/home/a/key file'));
        self::assertTrue(RsyncConfig::validKeyPath(''));
        // A hand-edited settings file is read through the same checks
        $config = RsyncConfig::fromSettings(['backup' => ['host' => '-oProxyCommand=x', 'user' => 'a', 'remote_dir' => '/x']]);
        self::assertFalse($config->configured());
    }

    public function testASnapshotIsRsyncedToAPartialDirectoryThenRenamedAndLatestMoved(): void
    {
        $result = $this->backup($this->config())->run(false, strtotime('2026-10-17 04:07:09'));

        self::assertTrue($result['ok']);
        self::assertSame('2026-10-17T040709', $result['snapshot']);
        self::assertSame(1234, $result['files']);
        self::assertSame(12, $result['transferred']);
        self::assertSame(5678901, $result['bytes']);
        self::assertSame(4321, $result['sent']);

        $calls = $this->calls();
        self::assertSame("ssh mkdir -p -- '/srv/backups/reporion/snapshots'", $calls[0]);
        self::assertStringStartsWith('rsync -a --no-owner --no-group -z --stats --timeout=300 --link-dest=../latest ', $calls[1]);
        self::assertStringContainsString('-e ' . $this->ssh . ' -p 2222 -o BatchMode=yes', $calls[1]);
        self::assertStringContainsString('--exclude=/index.sqlite*', $calls[1]);
        self::assertStringContainsString('--exclude=/vectors.sqlite*', $calls[1]);
        self::assertStringEndsWith($this->data . '/ backup@backup.example.org:/srv/backups/reporion/snapshots/2026-10-17T040709.partial/', $calls[1]);
        self::assertSame("ssh mv -- '/srv/backups/reporion/snapshots/2026-10-17T040709.partial' '/srv/backups/reporion/snapshots/2026-10-17T040709' && ln -sfn -- '2026-10-17T040709' '/srv/backups/reporion/snapshots/latest'", $calls[2]);

        $state = RsyncBackup::state($this->data);
        self::assertSame(strtotime('2026-10-17 04:07:09'), $state['last_success']);
        self::assertSame('2026-10-17T040709', $state['snapshot']);
        self::assertSame(0, $state['last_exit']);
    }

    public function testNothingTheProgramsPrintReachesTheResultOrTheStateOrTheReport(): void
    {
        file_put_contents($this->base . '/rsync.exit', '23');
        $result = $this->backup($this->config())->run(false);

        self::assertFalse($result['ok']);
        self::assertSame(['rsync', [23]], $result['error']);
        self::assertStringNotContainsString(self::PATIENT, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::PATIENT, (string) file_get_contents($this->data . '/' . RsyncBackup::STATE));
        // A failed copy is never promoted
        foreach ($this->calls() as $call) {
            self::assertStringNotContainsString('ln -sfn', $call);
        }
        self::assertSame(23, RsyncBackup::state($this->data)['last_exit']);

        file_put_contents($this->data . '/settings.yaml', "backup:\n  host: backup.example.org\n  user: backup\n  remote_dir: /srv/backups/reporion\n");
        $report = (new RsyncBackupTask($this->data, $this->base . '/conf/local.php', $this->rsync, $this->ssh))->run(MaintenanceTask::APPLY, 'cli', []);
        self::assertSame(1, $report->exit());
        self::assertStringNotContainsString(self::PATIENT, $report->toJson());
        self::assertStringContainsString('status 23', implode(' ', $report->notes()));
    }

    public function testFilesThatVanishedWhileItRanAreAWarningNotAFailure(): void
    {
        file_put_contents($this->base . '/rsync.exit', '24');
        $result = $this->backup($this->config())->run(false);

        self::assertTrue($result['ok']);
        self::assertSame(['vanished'], $result['warnings']);
        self::assertNotSame('', $result['snapshot']);
    }

    public function testASshFailureStopsBeforeAnythingIsCopied(): void
    {
        file_put_contents($this->base . '/ssh.exit', '255');
        $result = $this->backup($this->config())->run(false);

        self::assertSame(['ssh', [255]], $result['error']);
        self::assertSame([], array_filter($this->calls(), static fn (string $c): bool => str_starts_with($c, 'rsync')));
    }

    public function testADryRunStoresNothingAndWritesNoState(): void
    {
        $result = $this->backup($this->config())->run(true);

        self::assertTrue($result['ok']);
        self::assertSame('', $result['snapshot']);
        $calls = $this->calls();
        self::assertCount(2, $calls);
        self::assertStringContainsString(' -n ', $calls[1]);
        self::assertStringNotContainsString('--link-dest', $calls[1]);
        self::assertFileDoesNotExist($this->data . '/' . RsyncBackup::STATE);
    }

    public function testOldSnapshotsAreRemovedAfterAWeekButTheNewestAndTheCurrentStay(): void
    {
        $now = strtotime('2026-10-17 04:00:00');
        file_put_contents($this->base . '/listing', implode("\n", [
            '2026-09-01T040000', '2026-10-01T040000', '2026-10-09T040000', '2026-10-12T040000', '2026-10-16T040000',
            '2026-10-10T030000.partial', '2026-10-17T035500.partial', 'latest', 'notes.txt', '../etc',
        ]) . "\n");
        $result = $this->backup($this->config(['keep' => 'week']))->run(false, $now);

        // 09-01, 10-01, 10-09 (8 days) and the old partial go; 10-12, 10-16 and the new one stay; the unknown names are never passed on
        self::assertSame(4, $result['pruned']);
        $rm = array_values(array_filter($this->calls(), static fn (string $c): bool => str_starts_with($c, 'ssh rm ')));
        self::assertCount(1, $rm);
        self::assertStringContainsString("'/srv/backups/reporion/snapshots/2026-09-01T040000'", $rm[0]);
        self::assertStringContainsString("'/srv/backups/reporion/conf/local.php.2026-10-09T040000'", $rm[0]);
        self::assertStringContainsString("'/srv/backups/reporion/snapshots/2026-10-10T030000.partial'", $rm[0]);
        self::assertStringNotContainsString('2026-10-12', $rm[0]);
        self::assertStringNotContainsString('2026-10-16', $rm[0]);
        self::assertStringNotContainsString('latest', $rm[0]);
        self::assertStringNotContainsString('notes.txt', $rm[0]);
        self::assertStringNotContainsString('..', $rm[0]);
    }

    public function testAMonthKeepsWhatAWeekDrops(): void
    {
        file_put_contents($this->base . '/listing', "2026-09-01T040000\n2026-10-01T040000\n2026-10-09T040000\n");
        $result = $this->backup($this->config(['keep' => 'month']))->run(false, strtotime('2026-10-17 04:00:00'));

        self::assertSame(1, $result['pruned']);
        $rm = implode("\n", array_filter($this->calls(), static fn (string $c): bool => str_starts_with($c, 'ssh rm ')));
        self::assertStringContainsString('2026-09-01T040000', $rm);
        self::assertStringNotContainsString('2026-10-01', $rm);
    }

    public function testKeepingForEverNeverListsOrRemoves(): void
    {
        $this->backup($this->config(['keep' => 'all']))->run(false);

        foreach ($this->calls() as $call) {
            self::assertStringNotContainsString('ls -1', $call);
            self::assertStringNotContainsString('rm -rf', $call);
        }
    }

    public function testTheConfigIsCopiedOnlyWhenAskedFor(): void
    {
        mkdir($this->base . '/conf');
        file_put_contents($this->base . '/conf/local.php', '<?php return [];');
        $this->backup($this->config())->run(false, strtotime('2026-10-17 04:00:00'));
        self::assertCount(0, array_filter($this->calls(), static fn (string $c): bool => str_contains($c, 'local.php')));

        @unlink($this->log);
        $this->backup($this->config(['include_conf' => true]))->run(false, strtotime('2026-10-17 04:00:00'));
        $calls = $this->calls();
        self::assertStringContainsString("'/srv/backups/reporion/conf'", $calls[0]);
        self::assertCount(1, array_filter($calls, static fn (string $c): bool => str_contains($c, 'backup@backup.example.org:/srv/backups/reporion/conf/local.php.2026-10-17T040000')));
    }

    public function testDueFollowsTheScheduleTheIntervalAndTheLastAttempt(): void
    {
        $now = strtotime('2026-10-17 12:00:00');
        $config = $this->config(['interval_hours' => 24]);
        self::assertTrue(RsyncBackup::due($config, $this->data, $now), 'never run');
        self::assertFalse(RsyncBackup::due($this->config(['enabled' => false]), $this->data, $now), 'off');
        self::assertFalse(RsyncBackup::due(new RsyncConfig(enabled: true), $this->data, $now), 'no target');

        file_put_contents($this->data . '/' . RsyncBackup::STATE, json_encode(['last_success' => $now - 23 * 3600, 'last_attempt' => $now - 23 * 3600]));
        self::assertFalse(RsyncBackup::due($config, $this->data, $now), '23 h ago');
        file_put_contents($this->data . '/' . RsyncBackup::STATE, json_encode(['last_success' => $now - 24 * 3600 + 120, 'last_attempt' => $now - 24 * 3600 + 120]));
        self::assertTrue(RsyncBackup::due($config, $this->data, $now), 'cron slack');
        file_put_contents($this->data . '/' . RsyncBackup::STATE, json_encode(['last_success' => $now - 30 * 3600, 'last_attempt' => $now - 600]));
        self::assertFalse(RsyncBackup::due($config, $this->data, $now), 'a failed attempt ten minutes ago');
    }

    /** @param array<string, mixed> $over */
    private function daemon(array $over = []): RsyncBackup
    {
        return $this->backup(RsyncConfig::fromSettings(['backup' => $over + ['enabled' => true, 'transport' => 'daemon', 'host' => '192.168.1.20', 'module' => 'reporion', 'remote_dir' => 'lab/site', 'keep' => 'week']]));
    }

    public function testADaemonTargetNeedsAHostAndAModuleNotAnAccount(): void
    {
        self::assertTrue(RsyncConfig::fromSettings(['backup' => ['transport' => 'daemon', 'host' => 'nas', 'module' => 'bk']])->configured());
        self::assertFalse(RsyncConfig::fromSettings(['backup' => ['transport' => 'daemon', 'host' => 'nas']])->configured());
        self::assertFalse(RsyncConfig::fromSettings(['backup' => ['transport' => 'ssh', 'host' => 'nas', 'module' => 'bk']])->configured());
        self::assertSame(873, RsyncConfig::fromSettings(['backup' => ['transport' => 'daemon']])->port);
        self::assertFalse(RsyncConfig::validModule('bk/../x'));
        self::assertFalse(RsyncConfig::validModule('-x'));
    }

    public function testADaemonSnapshotUsesNoSshAndIsMarkedCompleteAfterTheCopy(): void
    {
        file_put_contents($this->data . '/' . RsyncBackup::STATE, json_encode(['last_success' => 1, 'snapshot' => '2026-10-16T040000']));
        $result = $this->daemon(['port' => 8730, 'user' => 'bk', 'password_file' => $this->base . '/pw'])->run(false, strtotime('2026-10-17 04:00:00'));
        file_put_contents($this->base . '/pw', 'x');

        self::assertSame(['password_unreadable', []], $result['error']);

        $result = $this->daemon(['port' => 8730, 'user' => 'bk', 'password_file' => $this->base . '/pw'])->run(false, strtotime('2026-10-17 04:00:00'));
        self::assertTrue($result['ok']);
        self::assertSame('2026-10-17T040000', $result['snapshot']);
        $calls = $this->calls();
        foreach ($calls as $call) {
            self::assertStringStartsWith('rsync ', $call, 'no ssh and no remote shell');
        }
        // 1: the directories (an empty tree sent to the module's root), 2: the copy, 3: the marker
        self::assertStringContainsString('rsync://bk@192.168.1.20:8730/reporion/', $calls[0]);
        self::assertStringContainsString('--password-file=' . $this->base . '/pw', $calls[0]);
        self::assertStringContainsString('--link-dest=../2026-10-16T040000 ', $calls[1]);
        self::assertStringContainsString('--contimeout=20', $calls[1]);
        self::assertStringEndsWith($this->data . '/ rsync://bk@192.168.1.20:8730/reporion/lab/site/snapshots/2026-10-17T040000/', $calls[1]);
        self::assertStringEndsWith('.reporion-complete rsync://bk@192.168.1.20:8730/reporion/lab/site/snapshots/2026-10-17T040000/.reporion-complete', $calls[2]);
        self::assertSame(2026, (int) substr(RsyncBackup::state($this->data)['snapshot'], 0, 4));
        self::assertSame([], glob($this->data . '/tmp/rsync-*') ?: [], 'the scratch trees are removed');
    }

    public function testADaemonSnapshotWithNoPreviousOneLinksAgainstNothing(): void
    {
        $this->daemon()->run(false, strtotime('2026-10-17 04:00:00'));

        self::assertStringNotContainsString('--link-dest', $this->calls()[1]);
    }

    public function testADaemonPrunesWithAnEmptyDirectoryAndAnIncludeForExactlyTheOldNames(): void
    {
        file_put_contents($this->base . '/rsync-list', implode("\n", [
            'drwxr-xr-x          4,096 2026/10/17 04:00:00 .',
            'drwxr-xr-x          4,096 2026/09/01 04:00:00 2026-09-01T040000',
            'drwxr-xr-x          4,096 2026/10/16 04:00:00 2026-10-16T040000',
            'drwxr-xr-x          4,096 2026/10/10 04:00:00 evil name',
            '-rw-r--r--              5 2026/10/10 04:00:00 2026-08-01T040000',
        ]) . "\n");
        $result = $this->daemon(['include_conf' => true])->run(false, strtotime('2026-10-17 04:00:00'));

        self::assertSame(1, $result['pruned']);
        $deletes = array_values(array_filter($this->calls(), static fn (string $c): bool => str_contains($c, '--delete')));
        self::assertCount(2, $deletes);
        self::assertStringContainsString('--include=/2026-09-01T040000/*** --exclude=* ', $deletes[0]);
        self::assertStringEndsWith('/lab/site/snapshots/', $deletes[0]);
        self::assertStringContainsString('--include=/local.php.2026-09-01T040000 --exclude=* ', $deletes[1]);
        self::assertStringNotContainsString('2026-10-16', implode(' ', $deletes));
        self::assertStringNotContainsString('evil', implode(' ', $deletes));
    }

    public function testTheSettingsFormKeepsTheDaemonFields(): void
    {
        $settings = new InstanceSettings($this->data);
        $settings->save(['backup.transport' => 'daemon', 'backup.module' => 'reporion', 'backup.password_file' => '/var/www/.pw', 'backup.host' => '10.0.0.5']);
        $config = RsyncConfig::fromSettings($settings->load());
        self::assertSame(['daemon', 'reporion', '/var/www/.pw'], [$config->transport, $config->module, $config->passwordFile]);
        $this->expectException(InvalidArgumentException::class);
        $settings->save(['backup.transport' => 'ftp']);
    }

    public function testTheSettingsFormValidatesAndStoresTheBackupFields(): void
    {
        $settings = new InstanceSettings($this->data);
        $settings->save(['backup.enabled' => '1', 'backup.host' => 'backup.example.org', 'backup.port' => '2222', 'backup.user' => 'backup', 'backup.remote_dir' => '/srv/b', 'backup.ssh_key' => '', 'backup.bwlimit' => '', 'backup.include_conf' => '', 'backup.keep' => 'month', 'backup.interval_hours' => '12']);
        $config = RsyncConfig::fromSettings($settings->load());
        self::assertTrue($config->enabled);
        self::assertSame(2222, $config->port);
        self::assertSame('month', $config->keep);
        self::assertSame(12, $config->intervalHours);
        self::assertSame(0, $config->bwlimit);
        self::assertFalse($config->includeConf);

        foreach (['backup.host' => '-oProxyCommand=x', 'backup.remote_dir' => '/a/../b', 'backup.port' => '70000', 'backup.keep' => 'forever', 'backup.interval_hours' => '0', 'backup.ssh_key' => 'relative/key'] as $key => $bad) {
            try {
                $settings->save([$key => $bad]);
                self::fail($key . ' accepted');
            } catch (InvalidArgumentException) {
                self::assertSame(12, RsyncConfig::fromSettings($settings->load())->intervalHours);
            }
        }
    }
}
