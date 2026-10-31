<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use RuntimeException;

/**
 * tests/fixtures/ai/fake-openai.php under `php -S` on a free port, for as
 * long as a test needs it; what the last request carried is in lastRequest().
 */
final class FakeServer
{
    /** @var resource */
    private $process;
    public readonly string $url;
    private readonly string $log;

    public function __construct()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $this->log = sys_get_temp_dir() . '/fake-openai-' . bin2hex(random_bytes(4)) . '.json';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, \dirname(__DIR__) . '/fixtures/ai/fake-openai.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['FAKE_AI_LOG' => $this->log] + getenv()
        );
        if (!\is_resource($process)) {
            throw new RuntimeException('Cannot start the fake AI server');
        }
        $this->process = $process;
        $this->url = 'http://127.0.0.1:' . $port . '/v1';
        for ($i = 0; $i < 50; ++$i) {
            $probe = @fsockopen('127.0.0.1', $port);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50000);
        }
        throw new RuntimeException('The fake AI server did not start');
    }

    /** @return array{path: string, auth: string, body: mixed} */
    public function lastRequest(): array
    {
        return json_decode((string) @file_get_contents($this->log), true) ?: ['path' => '', 'auth' => '', 'body' => null];
    }

    /** How many chat completions the server has answered (or refused) so far */
    public function requests(): int
    {
        return (int) @file_get_contents($this->log . '.count');
    }

    /**
     * The speech requests answered so far, oldest first (POST /v1/audio/speech)
     *
     * @return list<array<string, mixed>>
     */
    public function speech(): array
    {
        $lines = file((string) $this->log . '.speech', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_map(static fn (string $l): array => (array) json_decode($l, true), $lines));
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->log);
        @unlink($this->log . '.count');
        @unlink($this->log . '.speech');
    }
}
