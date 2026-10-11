<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

/**
 * The request path the other HTTP tests skip: they hand Kernel a Request and
 * read the Response object, so Request::fromGlobals() and Response::send() —
 * real headers, real cookies, a real status line — run in none of them. Here
 * `php -S` serves a router that does what public/index.php does with a test
 * config (index.php itself reads conf/local.php, the live one), and the test
 * talks HTTP to it.
 */
final class EntryPointTest extends HttpTestCase
{
    private string $dir;
    private int $port;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->createPage('docs:private-note', 'private', 'Private note', "# Private note\n\nOnly for the signed in.\n");

        $root = \dirname(__DIR__, 2);
        $this->dir = sys_get_temp_dir() . '/reporion-entry-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/router.php', "<?php\ndeclare(strict_types=1);\n"
            . '$uri = parse_url($_SERVER[\'REQUEST_URI\'], PHP_URL_PATH);' . "\n"
            . 'if ($uri !== \'/\' && is_file(' . var_export($root . '/public', true) . ' . $uri)) { return false; }' . "\n"
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . ";\n"
            . '$config = ' . var_export($this->config, true) . ";\n"
            . "// public/index.php, with this config\n"
            . 'Reporion\Kernel::boot($config)->handle(Reporion\Http\Request::fromGlobals())->send();' . "\n");

        $this->port = random_int(20000, 40000);
        $this->server = proc_open(
            [PHP_BINARY, '-d', 'ffi.enable=1', '-S', '127.0.0.1:' . $this->port, '-t', $root . '/public', $this->dir . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        ) ?: null;
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $this->port) === false; ++$i) {
            usleep(100000);
        }
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function testSignInReadAPrivatePageAndSignOutOverHttp(): void
    {
        [$status, , $body] = $this->http('GET', '/login');
        self::assertSame(200, $status);
        self::assertStringContainsString('action="/login"', $body);

        [$status] = $this->http('GET', '/docs:private-note');
        self::assertSame(404, $status, 'anonymous: 404, never 403 (invariant 9)');

        [$status, $headers] = $this->http('POST', '/login', 'username=owner&password=correct-horse');
        self::assertSame(302, $status);
        self::assertSame('/', $headers['location'][0] ?? null);
        $cookie = $headers['set-cookie'][0] ?? '';
        self::assertMatchesRegularExpression('/^reporion=[^;]+;/', $cookie, 'the session cookie reaches the browser');
        self::assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        $session = strtok($cookie, ';');

        [$status, $headers, $body] = $this->http('GET', '/docs:private-note', null, $session);
        self::assertSame(200, $status);
        self::assertStringStartsWith('text/html', $headers['content-type'][0] ?? '');
        self::assertStringContainsString('Only for the signed in.', $body);

        [$status, $headers] = $this->http('POST', '/logout', '', $session);
        self::assertSame(302, $status);
        self::assertSame('/login', $headers['location'][0] ?? null);
        self::assertMatchesRegularExpression('/^reporion=[^;]*;.*(Max-Age=0|expires=Thu, 01 Jan 1970)/i', $headers['set-cookie'][0] ?? '', 'the cookie is cleared');
    }

    public function testAMissingRouteIsA404WithoutAPath(): void
    {
        [$status, , $body] = $this->http('GET', '/no:such:page');
        self::assertSame(404, $status);
        self::assertStringNotContainsString($this->dataRoot, $body, 'no filesystem path to an anonymous caller');
    }

    /** @return array{0: int, 1: array<string, list<string>>, 2: string} status, headers by lower-case name, body */
    private function http(string $method, string $path, ?string $body = null, string $cookie = ''): array
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
        ]]);
        $text = (string) @file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $context);
        /** @var list<string> $lines */
        $lines = $http_response_header ?? [];
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $lines[0] ?? '', $m) === 1 ? (int) $m[1] : 0;
        $byName = [];
        foreach (\array_slice($lines, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $byName[strtolower($name)][] = $value;
        }

        return [$status, $byName, $text];
    }
}
