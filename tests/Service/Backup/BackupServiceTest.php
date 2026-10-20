<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Backup;

use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Exception\BackupException;
use Reporion\Index\Sqlite;
use Reporion\Service\Backup\ArchiveReader;
use Reporion\Service\Backup\ArchiveWriter;
use Reporion\Service\Backup\BackupService;
use Reporion\Service\IndexMaintenance;
use Reporion\Storage\FileTree;

final class BackupServiceTest extends TestCase
{
    private string $base;
    private string $data;
    private string $conf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/reporion-backup-test-' . bin2hex(random_bytes(6));
        $this->data = $this->base . '/source/data';
        $this->conf = $this->base . '/source/conf';
        mkdir($this->data, 0775, true);
        mkdir($this->conf, 0775, true);
        file_put_contents($this->conf . '/local.php', "<?php return ['paths' => ['data' => '" . $this->data . "']];");
        $index = new Sqlite($this->data . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $storage = new FileTree($this->data, $index);
        $storage->create('reports:mri:site:260922-a', ['title' => 'RM A', 'modality' => 'MR', 'visibility' => 'private'], 'Text A.', 'owner');
        $storage->create('reports:ct:site:260922-b', ['title' => 'CT B', 'modality' => 'CT', 'visibility' => 'private'], 'Text B.', 'owner');
        mkdir($this->data . '/tmp', 0775);
        file_put_contents($this->data . '/tmp/upload.bin', 'in flight');
        mkdir($this->data . '/import', 0775);
        file_put_contents($this->data . '/import/source.csv', 'x');
        file_put_contents($this->data . '/settings.yaml', "site:\n  title: Staging\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
        parent::tearDown();
    }

    public function testCreateWritesAVerifiableArchiveWithoutTheCache(): void
    {
        $made = (new BackupService($this->data, $this->conf))->create();

        $archive = $this->data . '/backups/' . $made['file'];
        self::assertFileExists($archive);
        self::assertMatchesRegularExpression(BackupService::FILE_PATTERN, $made['file']);
        $read = (new BackupService($this->data, $this->conf))->verify($archive);
        self::assertSame($made['files'], $read['files']);
        $names = array_column($read['manifest']['files'], 'path');
        self::assertContains('conf/local.php', $names);
        self::assertContains('data/settings.yaml', $names);
        self::assertNotEmpty(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'data/pages/')));
        foreach ($names as $name) {
            self::assertStringNotContainsString('index.sqlite', $name);
            self::assertStringNotContainsString('vectors.sqlite', $name);
            self::assertStringNotContainsString('data/tmp', $name);
            self::assertStringNotContainsString('data/import', $name);
            self::assertStringNotContainsString('data/backups', $name);
        }
        // a second backup does not contain the first
        sleep(1);
        $second = (new BackupService($this->data, $this->conf))->create();
        self::assertSame($made['files'], $second['files']);
    }

    public function testRestoreIntoAnEmptyInstanceGivesTheSamePagesAndIndex(): void
    {
        $service = new BackupService($this->data, $this->conf);
        $archive = $this->data . '/backups/' . $service->create()['file'];
        $sourceRows = $this->rows(new PDO('sqlite:' . $this->data . '/index.sqlite'));

        $target = $this->base . '/target/data';
        $targetConf = $this->base . '/target/conf';
        mkdir($target . '/users', 0775, true);
        file_put_contents($target . '/users/fresh.json', '{}');
        mkdir($targetConf, 0775, true);
        $restored = (new BackupService($target, $targetConf))->restore($archive);

        self::assertNotNull($restored['moved_to']);
        self::assertFileExists($target . '/' . $restored['moved_to'] . '/users/fresh.json');
        self::assertFileDoesNotExist($target . '/users/fresh.json');
        self::assertFileExists($target . '/settings.yaml');
        self::assertDirectoryDoesNotExist($target . '/tmp/restore-' . date('Ymd'));

        $index = new Sqlite($target . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $storage = new FileTree($target, $index);
        self::assertSame(2, (new IndexMaintenance($storage, $index, dataRoot: '', auditDir: ''))->rebuild());
        self::assertSame($sourceRows, $this->rows(new PDO('sqlite:' . $target . '/index.sqlite')));
        // conf/local.php is the archive's, not installed: left beside, for a person
        self::assertSame('kept', $restored['conf']);
        self::assertFileExists($targetConf . '/local.php.from-backup');
        self::assertFileDoesNotExist($targetConf . '/local.php');
    }

    public function testRestoreRefusesAnInstanceWithPagesUnlessReplace(): void
    {
        $service = new BackupService($this->data, $this->conf);
        $archive = $this->data . '/backups/' . $service->create()['file'];
        file_put_contents($this->data . '/settings.yaml', "site:\n  title: Changed\n");

        try {
            $service->restore($archive);
            self::fail('a restore over pages needs --replace');
        } catch (BackupException $e) {
            self::assertStringContainsString('--replace', $e->getMessage());
        }
        self::assertStringContainsString('Changed', (string) file_get_contents($this->data . '/settings.yaml'));

        $restored = $service->restore($archive, ['replace' => true]);
        self::assertStringContainsString('Staging', (string) file_get_contents($this->data . '/settings.yaml'));
        self::assertStringContainsString('Changed', (string) file_get_contents($this->data . '/' . $restored['moved_to'] . '/settings.yaml'));
        // the backups directory stays where it is
        self::assertDirectoryExists($this->data . '/backups');
    }

    public function testConfIsInstalledOnlyWhenAskedAndForTheSameDataDirectory(): void
    {
        $service = new BackupService($this->data, $this->conf);
        $archive = $this->data . '/backups/' . $service->create()['file'];
        file_put_contents($this->conf . '/local.php', '<?php return [];');

        self::assertSame('installed', $service->restore($archive, ['replace' => true, 'with_conf' => true])['conf']);
        self::assertStringContainsString($this->data, (string) file_get_contents($this->conf . '/local.php'));
        self::assertFileExists($this->conf . '/local.php.from-backup' === '' ? '' : $this->conf . '/local.php');

        $otherConf = $this->base . '/other/conf';
        mkdir($otherConf, 0775, true);
        $other = $this->base . '/other/data';
        mkdir($other, 0775, true);
        self::assertSame('paths-differ', (new BackupService($other, $otherConf))->restore($archive, ['with_conf' => true])['conf']);
        self::assertFileDoesNotExist($otherConf . '/local.php');
        self::assertFileExists($otherConf . '/local.php.from-backup');
    }

    public function testAFailureBeforeTheSwapLeavesTheInstanceUntouched(): void
    {
        $service = new BackupService($this->data, $this->conf);
        $archive = $this->data . '/backups/' . $service->create()['file'];

        try {
            $service->restore($archive, ['replace' => true, 'after_stage' => static function (): void {
                throw new BackupException('stop');
            }]);
            self::fail('expected the failure');
        } catch (BackupException) {
        }
        self::assertFileExists($this->data . '/settings.yaml');
        self::assertDirectoryExists($this->data . '/pages');
        self::assertSame([], glob($this->data . '/.pre-restore-*') ?: []);
        self::assertSame([], glob($this->data . '/tmp/restore-*') ?: []);
    }

    public function testATamperedArchiveIsRefusedBeforeAnythingIsReplaced(): void
    {
        $service = new BackupService($this->data, $this->conf);
        $archive = $this->data . '/backups/' . $service->create()['file'];
        $tar = (string) gzdecode((string) file_get_contents($archive));
        $at = strpos($tar, 'title: Staging');
        self::assertNotFalse($at);
        $tar[$at + 7] = 'X';
        file_put_contents($archive, gzencode($tar));

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('checksum');
        try {
            $service->restore($archive, ['replace' => true]);
        } finally {
            self::assertStringContainsString('Staging', (string) file_get_contents($this->data . '/settings.yaml'));
            self::assertSame([], glob($this->data . '/.pre-restore-*') ?: []);
        }
    }

    public function testATruncatedArchiveIsRefused(): void
    {
        $service = new BackupService($this->data, $this->conf);
        $archive = $this->data . '/backups/' . $service->create()['file'];
        $tar = (string) gzdecode((string) file_get_contents($archive));
        file_put_contents($archive, gzencode(substr($tar, 0, (int) (\strlen($tar) / 2))));

        $this->expectException(BackupException::class);
        $service->verify($archive);
    }

    /**
     * @dataProvider badEntries
     */
    public function testEntriesOutsideTheAllowedTreeAreRefusedBeforeExtraction(string $name): void
    {
        $file = $this->base . '/bad.tar.gz';
        $writer = new ArchiveWriter($file);
        $writer->addString($name, 'evil');
        $writer->finish([]);
        $dest = $this->base . '/dest';
        mkdir($dest);

        try {
            (new ArchiveReader($file))->extract($dest);
            self::fail('entry accepted: ' . $name);
        } catch (BackupException $e) {
            self::assertStringContainsString('refusing', $e->getMessage());
        }
        self::assertSame([], glob($dest . '/*') ?: []);
        self::assertFileDoesNotExist($this->base . '/evil');
    }

    /** @return array<string, array{string}> */
    public static function badEntries(): array
    {
        return [
            'parent' => ['../evil'],
            'inside' => ['data/../../evil'],
            'absolute' => ['/etc/evil'],
            'elsewhere' => ['conf/other.php'],
            'plugins' => ['plugins/x/Plugin.php'],
            'backslash' => ['data\\..\\evil'],
        ];
    }

    public function testLongNamesSurviveTheRoundTrip(): void
    {
        $file = $this->base . '/long.tar.gz';
        $source = $this->base . '/payload.txt';
        file_put_contents($source, str_repeat('x', 1300));
        $names = [
            'data/pages/' . str_repeat('a', 60) . '/' . str_repeat('b', 60) . '/c.txt',
            'data/' . str_repeat('d', 150) . '/' . str_repeat('e', 120) . '/f.txt',
            'data/' . str_repeat('g', 200) . '/' . str_repeat('h', 200) . '.txt',
        ];
        $writer = new ArchiveWriter($file);
        $writer->addDirectory('data');
        foreach ($names as $name) {
            $writer->addFile($name, $source);
        }
        $writer->finish([]);
        $dest = $this->base . '/long';
        mkdir($dest);
        $read = (new ArchiveReader($file))->extract($dest);

        self::assertSame(3, $read['files']);
        foreach ($names as $name) {
            self::assertSame(str_repeat('x', 1300), file_get_contents($dest . '/' . $name));
        }
    }

    public function testManyFilesAndEmptyFiles(): void
    {
        mkdir($this->data . '/media/2026', 0775, true);
        for ($i = 0; $i < 600; ++$i) {
            file_put_contents($this->data . '/media/2026/' . sprintf('%064x', $i) . '.bin', $i % 7 === 0 ? '' : str_repeat('z', $i));
        }
        $service = new BackupService($this->data, $this->conf);
        $made = $service->create();

        self::assertSame($made['files'], $service->verify($this->data . '/backups/' . $made['file'])['files']);
        self::assertGreaterThan(600, $made['files']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo): array
    {
        return $pdo->query('SELECT pid, path, rev, title FROM pages ORDER BY pid')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
