<?php

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Kernel;

final class KernelDefaultsTest extends TestCase
{
    private string $data;
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        $this->data = sys_get_temp_dir() . '/reporion-kd-' . bin2hex(random_bytes(4));
        mkdir($this->data);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        @rmdir($this->data);
    }

    public function testAMinimalConfigGetsTheBuiltInDefaults(): void
    {
        $config = Kernel::withInstanceSettings(['auth' => ['session_secret' => 'x'], 'paths' => ['data' => $this->data]]);

        self::assertSame('site:home', $config['site']['home_page']);
        self::assertSame(30, $config['pages']['trash_purge_days']);
        self::assertSame('reporion', $config['auth']['session_name']);
        self::assertSame($this->data . '/index.sqlite', $config['paths']['index']);
        self::assertSame($this->data . '/audit', $config['paths']['audit']);
    }

    public function testWithoutATimezoneTheSystemOneIsUsedElseUtc(): void
    {
        $config = Kernel::withInstanceSettings(['auth' => ['session_secret' => 'x'], 'paths' => ['data' => $this->data]]);

        self::assertContains($config['site']['timezone'], timezone_identifiers_list());
        self::assertSame($config['site']['timezone'], date_default_timezone_get());
    }

    public function testAConfiguredTimezoneWins(): void
    {
        $config = Kernel::withInstanceSettings(['site' => ['timezone' => 'Asia/Tokyo'], 'paths' => ['data' => $this->data]]);

        self::assertSame('Asia/Tokyo', date_default_timezone_get());
        self::assertSame('Asia/Tokyo', $config['site']['timezone']);
    }
}
