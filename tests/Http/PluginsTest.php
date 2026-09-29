<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\InstanceSettings;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Support\CnpTest;
use Symfony\Component\Yaml\Yaml;

/**
 * The plugin loader (docs/architecture-api.md §5), through a fixture plugin
 * written to a temporary plugins/ directory: discovery, enabling, routes
 * under /x/{id}, the report.prefill hook, the interface slots, settings in
 * Admin → Plugins, and a plugin that fails without taking the site down.
 * Names and CNPs are made up (invariant 10).
 */
final class PluginsTest extends HttpTestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginsDir = $this->dataRoot . '/plugins-fixture';
        mkdir($this->pluginsDir);
        $this->config['paths']['plugins'] = $this->pluginsDir;
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'accession_code' => 'MV', 'devices' => []]];
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Viewer)]);
        $this->writeFixturePlugin();
    }

    public function testADisabledPluginIsListedButRunsNothing(): void
    {
        self::assertSame(404, $this->get('owner', '/x/demo/hello')->status);

        $admin = $this->get('owner', '/admin/plugins');
        self::assertSame(200, $admin->status);
        self::assertStringContainsString('Demo plugin', $admin->body);
        self::assertStringContainsString('<input type="radio" name="enabled" value="0" checked>', $admin->body, 'the Enabled | Disabled control shows it off');
    }

    public function testAnEnabledPluginAddsItsRoutesUnderItsOwnPrefix(): void
    {
        $this->config['plugins']['enabled'] = ['demo'];

        $response = $this->get('owner', '/x/demo/hello');

        self::assertSame(200, $response->status);
        self::assertSame('hello owner, greeting=salut', $response->body, 'the handler gets the principal and its own settings, defaults applied');
    }

    public function testThePrefillHookFillsTheGuidedFormAndTheOrderRefReachesTheFrontmatter(): void
    {
        $this->config['plugins']['enabled'] = ['demo'];

        $form = $this->get('owner', '/new?prefill=demo&ref=77');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('value="IONESCU Maria"', $form->body);
        self::assertStringContainsString('name="order_ref" value="demo:Order/77"', $form->body);
        self::assertStringContainsString('From demo', $form->body, 'the new_report slot');

        self::assertSame(404, $this->get('owner', '/new?prefill=other&ref=1')->status, 'no plugin answers for that source');
        self::assertSame(404, $this->get('viewer', '/new?prefill=demo&ref=77')->status, 'only callers who create reports');

        $cnp = CnpTest::make(2, '800115');
        $created = $this->post('owner', '/new', [
            'guided' => '1', 'action' => 'create', 'name' => 'IONESCU Maria', 'cnp' => $cnp, 'date' => '2026-09-20',
            'modality' => 'CT', 'site' => 'mioveni', 'regions' => ['chest'], 'title' => 'CT torace', 'order_ref' => 'demo:Order/77',
        ]);
        self::assertSame(302, $created->status);
        $page = $this->storage()->read('reports:ct:mioveni:260920-ionescu-maria');
        self::assertSame('demo:Order/77', $page->frontmatter['order_ref']);

        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $owner = (new FlatFileUserStore($this->dataRoot))->find('owner');
        self::assertSame(['demo:Order/77' => 'reports:ct:mioveni:260920-ionescu-maria'], $index->findByOrderRefs(['demo:Order/77', 'demo:Order/78'], $owner));
        self::assertSame([], $index->findByOrderRefs(['demo:Order/77'], null), 'through the listing predicate: a private page is not found anonymously');

        $bad = $this->post('owner', '/new', [
            'guided' => '1', 'action' => 'create', 'name' => 'IONESCU Maria', 'date' => '2026-09-21',
            'modality' => 'CT', 'site' => 'mioveni', 'regions' => ['chest'], 'title' => 'CT torace', 'order_ref' => 'not a ref',
        ]);
        self::assertSame(302, $bad->status);
        self::assertArrayNotHasKey('order_ref', $this->storage()->read('reports:ct:mioveni:260921-ionescu-maria')->frontmatter, 'a malformed ref is dropped, never stored');
    }

    public function testThePageActionShowsOnReportsForWritersOnly(): void
    {
        $this->config['plugins']['enabled'] = ['demo'];
        $record = $this->storage()->create('reports:ct:mioveni:260920-ionescu-maria', ['title' => 'IONESCU Maria', 'visibility' => 'private'], "# IONESCU Maria\n", 'owner');
        $this->storage()->create('docs:note', ['title' => 'Note', 'visibility' => 'private'], "Text\n", 'owner');

        $page = $this->get('owner', '/reports:ct:mioveni:260920-ionescu-maria');
        self::assertStringContainsString('/x/demo/page/' . $record->pid, $page->body);
        self::assertStringContainsString('Demo action', $page->body);
        self::assertStringNotContainsString('Demo action', $this->get('owner', '/docs:note')->body, 'reports only');
        self::assertStringNotContainsString('Demo action', $this->get('viewer', '/reports:ct:mioveni:260920-ionescu-maria')->body, 'writers only');
    }

    public function testThePageTabShowsOnReportsForWritersOnly(): void
    {
        $this->config['plugins']['enabled'] = ['demo'];
        $record = $this->storage()->create('reports:ct:mioveni:260920-ionescu-maria', ['title' => 'IONESCU Maria', 'visibility' => 'private'], "# IONESCU Maria\n", 'owner');
        $this->storage()->create('docs:note', ['title' => 'Note', 'visibility' => 'private'], "Text\n", 'owner');

        $page = $this->get('owner', '/reports:ct:mioveni:260920-ionescu-maria');
        self::assertMatchesRegularExpression('#<a class="wk-tab" data-busy data-on="" href="[^"]*/x/demo/tab/' . $record->pid . '">Demo tab</a>#', $page->body);
        self::assertStringNotContainsString('Demo tab', $this->get('owner', '/docs:note')->body, 'reports only');
        self::assertStringNotContainsString('Demo tab', $this->get('viewer', '/reports:ct:mioveni:260920-ionescu-maria')->body, 'writers only');
    }

    public function testAPluginThatFailsToRegisterIsSkippedAndReported(): void
    {
        file_put_contents($this->pluginsDir . '/demo/plugin.json', (string) json_encode(['id' => 'demo', 'name' => 'Demo plugin', 'api' => 1, 'settings' => ['fail' => ['type' => 'bool', 'default' => true]]]));
        $this->config['plugins']['enabled'] = ['demo'];

        self::assertSame(200, $this->get('owner', '/admin/users')->status, 'the site keeps working');
        self::assertSame(404, $this->get('owner', '/x/demo/hello')->status);
        self::assertStringContainsString('failed to load (InvalidArgumentException)', $this->get('owner', '/admin/plugins')->body);
    }

    public function testAnInvalidManifestIsListedAndNeverRun(): void
    {
        mkdir($this->pluginsDir . '/broken');
        file_put_contents($this->pluginsDir . '/broken/plugin.json', (string) json_encode(['id' => 'other', 'api' => 1]));
        $this->config['plugins']['enabled'] = ['broken'];

        $admin = $this->get('owner', '/admin/plugins');
        self::assertStringContainsString('not a valid plugin', $admin->body);
        self::assertStringContainsString('plugin.json id must be its directory name', $admin->body);

        mkdir($this->pluginsDir . '/sneaky');
        file_put_contents($this->pluginsDir . '/sneaky/plugin.json', (string) json_encode(['id' => 'sneaky', 'api' => 1, 'ui' => ['page_tab' => ['label' => 'sneaky.tab', 'href' => '/x/demo/tab/{pid}']]]));
        self::assertStringContainsString('plugin.json has an invalid ui slot', $this->get('owner', '/admin/plugins')->body, 'a tab links only into its own routes');
    }

    public function testTheOwnerEnablesAndConfiguresAPluginFromAdmin(): void
    {
        self::assertSame(404, $this->post('viewer', '/admin/plugins/demo/toggle', [])->status, 'owner only');

        self::assertSame(302, $this->post('owner', '/admin/plugins/demo/toggle', ['enabled' => '1'])->status);
        self::assertSame(302, $this->post('owner', '/admin/plugins/demo/toggle', ['enabled' => '1'])->status);
        $this->config = Kernel::withInstanceSettings($this->config);
        self::assertSame(['demo'], $this->config['plugins']['enabled'], 'sets the state asked for — twice is still on, never a flip');
        self::assertStringContainsString('<input type="radio" name="enabled" value="1" checked>', $this->get('owner', '/admin/plugins')->body);
        self::assertSame(200, $this->get('owner', '/x/demo/hello')->status, 'from the next request');
        self::assertStringNotContainsString('HIJACKED', $this->get('owner', '/admin/plugins')->body, "a plugin's strings stay under its own prefix");

        self::assertSame(302, $this->post('owner', '/admin/plugins/demo/settings', ['greeting' => 'buna', 'days' => '5', 'token' => 's3cret'])->status);
        $stored = Yaml::parseFile($this->dataRoot . '/' . InstanceSettings::FILE);
        self::assertSame(['greeting' => 'buna', 'days' => 5, 'token' => 's3cret', 'fail' => false], $stored['plugins']['settings']['demo']);
        $this->config = Kernel::withInstanceSettings($this->config);
        self::assertSame('hello owner, greeting=buna', $this->get('owner', '/x/demo/hello')->body);

        $admin = $this->get('owner', '/admin/plugins');
        self::assertStringNotContainsString('s3cret', $admin->body, 'a secret is never shown back');
        self::assertSame(302, $this->post('owner', '/admin/plugins/demo/settings', ['greeting' => 'buna', 'days' => '5', 'token' => ''])->status);
        self::assertSame('s3cret', Yaml::parseFile($this->dataRoot . '/' . InstanceSettings::FILE)['plugins']['settings']['demo']['token'], 'blank keeps the stored secret');

        self::assertSame(422, $this->post('owner', '/admin/plugins/demo/settings', ['greeting' => 'x', 'days' => 'many'])->status);

        self::assertSame(302, $this->post('owner', '/admin/plugins/demo/toggle', ['enabled' => '0'])->status);
        $this->config = Kernel::withInstanceSettings($this->config);
        self::assertSame([], $this->config['plugins']['enabled']);
    }

    private function writeFixturePlugin(): void
    {
        mkdir($this->pluginsDir . '/demo/lang', 0775, true);
        file_put_contents($this->pluginsDir . '/demo/plugin.json', (string) json_encode([
            'id' => 'demo', 'name' => 'Demo plugin', 'version' => '0.1.0', 'api' => 1,
            'hooks' => ['report.prefill'],
            'settings' => [
                'greeting' => ['type' => 'text', 'default' => 'salut'],
                'days' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 30],
                'token' => ['type' => 'secret'],
                'fail' => ['type' => 'bool', 'default' => false],
            ],
            'ui' => [
                'page_action' => ['label' => 'demo.action', 'icon' => 'star', 'href' => '/x/demo/page/{pid}'],
                'page_tab' => ['label' => 'demo.tab', 'icon' => 'star', 'href' => '/x/demo/tab/{pid}'],
                'new_report' => ['label' => 'demo.new', 'icon' => 'star', 'href' => '/x/demo/hello'],
            ],
        ]));
        file_put_contents($this->pluginsDir . '/demo/lang/en.php', "<?php return ['demo.action' => 'Demo action', 'demo.tab' => 'Demo tab', 'demo.new' => 'From demo', 'nav.admin' => 'HIJACKED'];\n");
        file_put_contents($this->pluginsDir . '/demo/Plugin.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Reporion\Plugin\Demo;

            use Reporion\Http\Request;
            use Reporion\Http\Response;
            use Reporion\Plugin\Container;
            use Reporion\Plugin\Hooks;
            use Reporion\Plugin\PluginInterface;

            final class Plugin implements PluginInterface
            {
                public function register(Hooks $hooks, Container $c): void
                {
                    $settings = $c->settings();
                    if ($settings['fail'] === true) {
                        $hooks->on('no.such.hook', static fn () => null);
                    }
                    $hooks->route('GET', '/hello', static fn (Request $r, array $p, $user): Response
                        => Response::html('hello ' . ($user?->username ?? 'anon') . ', greeting=' . $settings['greeting']));
                    $hooks->on('report.prefill', static fn (string $source, string $ref, $user): ?array
                        => $source === 'demo' ? ['name' => 'IONESCU Maria', 'date' => '2026-09-20', 'modality' => 'CT', 'site' => 'mioveni', 'order_ref' => 'demo:Order/' . $ref] : null);
                }
            }
            PHP);
    }

    /** @param array<string, mixed> $fields */
    private function post(string $user, string $path, array $fields): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', $path, cookies: ['reporion' => $this->cookie($user)], body: http_build_query($fields)));
    }

    private function get(string $user, string $path): Response
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);

        return Kernel::boot($this->config)->handle(new Request('GET', $route, query: array_map('strval', $params), cookies: ['reporion' => $this->cookie($user)]));
    }

    private function cookie(string $user): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
