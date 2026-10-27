<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\ClientAddress;

final class ClientAddressTest extends TestCase
{
    public function testNoTrustedProxyMeansTheConnectionsAddress(): void
    {
        self::assertSame('203.0.113.9', ClientAddress::resolve('203.0.113.9', '198.51.100.1', []));
        self::assertSame('10.0.0.5', ClientAddress::resolve('10.0.0.5', '198.51.100.1', []), 'a header is not believed unless the proxy is listed');
    }

    public function testAnUnlistedConnectionCannotSpeakForAClient(): void
    {
        self::assertSame('203.0.113.9', ClientAddress::resolve('203.0.113.9', '10.0.0.7', ['10.0.0.0/8']));
    }

    public function testTheClientIsTheFirstUnlistedHopFromTheRight(): void
    {
        $trusted = ['10.0.0.5', '192.168.0.0/16', '2001:db8::/32'];
        self::assertSame('198.51.100.1', ClientAddress::resolve('10.0.0.5', '198.51.100.1', $trusted));
        self::assertSame('198.51.100.1', ClientAddress::resolve('10.0.0.5', '203.0.113.99, 198.51.100.1, 192.168.4.4', $trusted), 'what a client put in front is not its address');
        self::assertSame('10.0.0.5', ClientAddress::resolve('10.0.0.5', '', $trusted));
        self::assertSame('10.0.0.5', ClientAddress::resolve('10.0.0.5', '192.168.1.1', $trusted), 'every hop is ours');
        self::assertSame('10.0.0.5', ClientAddress::resolve('10.0.0.5', '198.51.100.1, garbage', $trusted), 'garbage in the chain: none of it believed');
        self::assertSame('2001:4860::1', ClientAddress::resolve('2001:db8::1', '2001:4860::1', $trusted));
    }

    public function testCleanKeepsAddressesAndRangesOnly(): void
    {
        self::assertSame(['10.0.0.5', '192.168.0.0/16', '::1', '2001:db8::/32'], ClientAddress::clean(['10.0.0.5', ' 192.168.0.0/16 ', 'nonsense', '10.0.0.0/33', '10.0.0.0/x', 5, '::1', '2001:db8::/32']));
    }
}
