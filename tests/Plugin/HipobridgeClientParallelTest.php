<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Hipobridge\Client;
use Reporion\Plugin\Hipobridge\HisException;
use Reporion\Plugin\Loader;

/**
 * Client::getMany() over real HTTP: a stand-in HippoBridge (php -S with
 * several workers, each answer held back 0.6 s) shows the requests sent side
 * by side through curl_multi and each answered as get() would answer it.
 */
final class HipobridgeClientParallelTest extends TestCase
{
    private string $dir;
    private int $port;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('curl_multi_init')) {
            self::markTestSkipped('the curl extension is not loaded');
        }
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'hipobridge');
        $this->dir = sys_get_temp_dir() . '/reporion-fakehis-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/router.php', <<<'PHP'
            <?php
            usleep(600000);
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/fhir/Locked') { http_response_code(401); exit; }
            if ($path === '/fhir/Gone') { http_response_code(404); exit; }
            header('Content-Type: application/fhir+json');
            echo json_encode(['resourceType' => 'Bundle', 'path' => $path, 'q' => $_GET['q'] ?? '', 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '']);
            PHP);
        $this->port = random_int(20000, 40000);
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->dir . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4'],
        ) ?: null;
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $this->port) === false; ++$i) {
            usleep(100000);
        }
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            // Its workers first: killing only the master leaves them running
            exec('pkill -KILL -P ' . (int) proc_get_status($this->server)['pid']);
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function testRequestsGoSideBySideAndAreAnsweredAsGetWould(): void
    {
        $client = new Client('http://127.0.0.1:' . $this->port, 'svc', 'secret', 5);

        $began = microtime(true);
        $out = $client->getMany([
            'ct' => ['/fhir/Schedule', ['q' => 'ct']],
            'mr' => ['/fhir/Schedule', ['q' => 'mr']],
            'locked' => ['/fhir/Locked'],
            'gone' => ['/fhir/Gone'],
        ]);
        $took = microtime(true) - $began;

        self::assertSame(['ct', 'mr', 'locked', 'gone'], array_keys($out), 'keyed and ordered as asked');
        self::assertIsArray($out['ct']);
        self::assertSame(['/fhir/Schedule', 'ct', 'Basic ' . base64_encode('svc:secret')], [$out['ct']['path'], $out['ct']['q'], $out['ct']['auth']]);
        self::assertIsArray($out['mr']);
        self::assertSame('mr', $out['mr']['q']);
        self::assertInstanceOf(HisException::class, $out['locked']);
        self::assertSame('auth', $out['locked']->getMessage());
        self::assertSame('OperationOutcome', \is_array($out['gone']) ? $out['gone']['resourceType'] : null, 'a 404 as get() gives it');
        self::assertLessThan(1.8, $took, 'four 0.6 s answers: about 0.6 s side by side, 2.4 s one after another');

        $down = (new Client('http://127.0.0.1:1', 'svc', 'secret', 2))->getMany(['a' => ['/fhir/Schedule'], 'b' => ['/fhir/Schedule']]);
        self::assertInstanceOf(HisException::class, $down['a']);
        self::assertSame('unreachable', $down['a']->getMessage());
    }
}
