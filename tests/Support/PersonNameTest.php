<?php

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reporion\Support\PersonName;

final class PersonNameTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'comma and capitals' => ['TURLEA, Viorela', 'Turlea Viorela'],
            'all lower' => ['turlea viorela', 'Turlea Viorela'],
            'spaces' => ["  POPESCU   Ana  Maria ", 'Popescu Ana Maria'],
            'hyphen' => ['POPESCU-ION Ana', 'Popescu-Ion Ana'],
            'diacritics' => ['ȘTEFAN, Ioana', 'Ștefan Ioana'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('names')]
    public function testNormalize(string $in, string $out): void
    {
        self::assertSame($out, PersonName::normalize($in));
    }
}
