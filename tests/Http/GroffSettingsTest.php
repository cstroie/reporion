<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;
use Reporion\Plugin\Loader;
use Reporion\Service\InstanceSettings;

/** The groff plugin's settings in Admin → Plugins: the multi-line preamble (a textarea setting) and what it refuses. */
final class GroffSettingsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'groff');
        $this->config['plugins'] = ['enabled' => ['groff']];
        (new FlatFileUserStore($this->dataRoot))->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
    }

    public function testThePreambleIsATextareaWithTheShippedDefault(): void
    {
        $screen = $this->request('GET', '/admin/plugins')->body;

        self::assertMatchesRegularExpression('#<textarea class="input wk-mono wk-text-sm" name="preamble"[^>]*>\.nr PO 2c\n\.nr LL 17c\n\.nr PS 11p\n\.nr VS 14p\n\.nr PD 4p\n\.ds FAM \{font\}</textarea>#', $screen);
    }

    public function testASavedPreambleKeepsItsLines(): void
    {
        $preamble = ".\\\" my page\n.nr LL 16c\n.ds FAM BT";
        $saved = $this->request('POST', '/admin/plugins/groff/settings', $this->fields(['preamble' => $preamble, 'font' => 'BT']));

        self::assertSame(302, $saved->status);
        $stored = (new InstanceSettings($this->dataRoot))->load()['plugins']['settings']['groff'];
        self::assertSame($preamble, $stored['preamble']);
        self::assertSame('BT', $stored['font']);
        self::assertStringContainsString("\n.nr LL 16c\n", $this->request('GET', '/admin/plugins')->body);
    }

    public function testALineThatCouldReadAFileOrRunACommandIsRefused(): void
    {
        foreach ([".nr LL 16c\n.so /etc/passwd", ".sy id", ".pso id", ".ds X \\X'pdf: x'", 'plain text'] as $bad) {
            $response = $this->request('POST', '/admin/plugins/groff/settings', $this->fields(['preamble' => $bad]));

            self::assertSame(422, $response->status, $bad);
            self::assertArrayNotHasKey('groff', (new InstanceSettings($this->dataRoot))->load()['plugins']['settings'] ?? [], 'nothing was saved');
        }
    }

    public function testTheFontAndTheToolPathKeepTheirPatterns(): void
    {
        foreach (['font' => ['-Tps', 'a b', 'x;rm'], 'groff' => ['groff', '/usr/bin/groff; id', '../groff']] as $key => $values) {
            foreach ($values as $bad) {
                self::assertSame(422, $this->request('POST', '/admin/plugins/groff/settings', $this->fields([$key => $bad]))->status, $key . '=' . $bad);
            }
        }
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function fields(array $override): array
    {
        return $override + ['groff' => '/usr/bin/groff', 'font' => 'T', 'preamble' => ".nr LL 17c\n.ds FAM {font}", 'timeout' => '30'];
    }

    /** @param array<string, string> $fields */
    private function request(string $method, string $path, array $fields = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: http_build_query($fields)));
    }
}
