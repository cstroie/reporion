<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * Shared Kernel::boot() config fixture. Kernel reads new config keys
 * unconditionally as routes get added (site.home_page, then auth.* each
 * broke every hand-rolled config array in this directory in turn) — one
 * shared fixture means a new key only needs adding here.
 */
abstract class HttpTestCase extends TestCase
{
    protected string $dataRoot;

    /** @var array<string, mixed> */
    protected array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-http-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);

        $this->config = [
            'paths' => [
                'data' => $this->dataRoot,
                'index' => $this->dataRoot . '/index.sqlite',
            ],
            'auth' => [
                'session_secret' => 'test-secret',
                'session_name' => 'reporion',
                'session_lifetime' => 3600,
            ],
            'site' => [
                'home_page' => 'site:home',
            ],
            'pages' => [
                'trash_purge_days' => 30,
            ],
        ];
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    protected function createPage(string $path, string $visibility, string $title, string $body): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create($path, ['title' => $title, 'visibility' => $visibility], $body, 'owner');
    }

    /**
     * Seeds a real account in data/users/ — D35: login reads only that
     * store now, there is no config fallback to seed a caller instead.
     */
    protected function createOwner(string $username = 'owner', string $password = 'correct-horse'): void
    {
        (new FlatFileUserStore($this->dataRoot))->create($username, password_hash($password, PASSWORD_ARGON2ID), true);
    }

    /**
     * The first Save of a report the guided form opened in the editor (not
     * written until then, 2026-10-10): the editor's form posted as a browser
     * would — every named field, checked boxes and selected options only.
     *
     * @param array<string, string> $override fields to set before posting, e.g. the body
     */
    protected function saveOpened(Response $opened, string $cookie, array $override = []): Response
    {
        TestCase::assertSame(200, $opened->status, 'the editor, nothing written yet');
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $opened->body);
        $form = (new DOMXPath($doc))->query('//form[@data-island="editor"]')->item(0);
        TestCase::assertInstanceOf(DOMElement::class, $form);
        $pairs = [];
        foreach ((new DOMXPath($doc))->query('(.//input | .//select | .//textarea)[not(ancestor::template)]', $form) as $field) {
            \assert($field instanceof DOMElement);
            $name = $field->getAttribute('name');
            if ($name === '' || $field->hasAttribute('disabled')) {
                continue;
            }
            $type = strtolower($field->getAttribute('type'));
            if ($field->tagName === 'input' && \in_array($type, ['checkbox', 'radio'], true) && !$field->hasAttribute('checked')) {
                continue;
            }
            if ($field->tagName === 'input' && \in_array($type, ['submit', 'button', 'file'], true)) {
                continue;
            }
            if ($field->tagName === 'select') {
                foreach ($field->getElementsByTagName('option') as $option) {
                    if ($option->hasAttribute('selected')) {
                        $pairs[] = rawurlencode($name) . '=' . rawurlencode($option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent);
                    }
                }
                continue;
            }
            $value = $field->tagName === 'textarea' ? $field->textContent : $field->getAttribute('value');
            if (\array_key_exists($name, $override)) {
                $value = $override[$name];
                unset($override[$name]);
            }
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        foreach ($override as $name => $value) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        $action = html_entity_decode($form->getAttribute('action'), ENT_QUOTES);

        return Kernel::boot($this->config)->handle(new Request('POST', rawurldecode((string) parse_url($action, PHP_URL_PATH)), cookies: ['reporion' => $cookie], body: implode('&', $pairs)));
    }

    /** saveOpened() when $response is the editor on a guided report not written yet; else $response as it is */
    protected function savedIfOpened(Response $response, string $cookie): Response
    {
        return str_contains($response->body, 'name="carried"') ? $this->saveOpened($response, $cookie) : $response;
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
}
