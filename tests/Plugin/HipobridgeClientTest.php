<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Hipobridge\Client;
use Reporion\Plugin\Hipobridge\HisException;
use Reporion\Plugin\Loader;

/**
 * A resource id goes into the request path, and it comes from a request or
 * from an earlier HIS answer: one that is no plain id is refused before
 * anything is sent, so it can never point the request at another resource.
 */
final class HipobridgeClientTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'hipobridge');
    }

    private function client(): Client
    {
        return new Client('http://his.invalid', 'svc', 'secret', 5, function (string $url): array {
            $this->sent[] = $url;

            return [200, '{"resourceType":"Patient","id":"1"}'];
        });
    }

    public function testAPlainIdIsSent(): void
    {
        $client = $this->client();
        $client->get('/fhir/Patient/12345');
        $client->get('/fhir/ServiceRequest/a.b-c_9');
        $client->get('/fhir/Patient', ['q' => 'x']);

        self::assertSame(['http://his.invalid/fhir/Patient/12345', 'http://his.invalid/fhir/ServiceRequest/a.b-c_9', 'http://his.invalid/fhir/Patient?q=x'], $this->sent);
    }

    public function testAnIdThatIsAPathOrAQueryIsNeverSent(): void
    {
        $client = $this->client();
        foreach (['..', '.', '1/../../admin', '1?x=y', '1#x', '%2e%2e', '', '1 2', "1\n"] as $id) {
            try {
                $client->get('/fhir/Patient/' . $id);
                self::fail('sent /fhir/Patient/' . $id);
            } catch (HisException $e) {
                self::assertSame('bad-response', $e->getMessage());
            }
        }

        self::assertSame([], $this->sent);
    }

    public function testGetManyRefusesOnlyTheBadOne(): void
    {
        $out = $this->client()->getMany(['ok' => ['/fhir/Patient/7'], 'bad' => ['/fhir/Patient/../Schedule']]);

        self::assertSame('Patient', $out['ok']['resourceType'] ?? null);
        self::assertInstanceOf(HisException::class, $out['bad']);
        self::assertSame(['http://his.invalid/fhir/Patient/7'], $this->sent);
    }
}
