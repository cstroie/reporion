<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Reporion\Schema\Loader;
use RuntimeException;

final class LoaderTest extends TestCase
{
    private string $realSchemaDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realSchemaDir = \dirname(__DIR__, 2) . '/conf/schema';
    }

    public function testMrFieldsIncludeBaseAndMrOwnFields(): void
    {
        $loader = new Loader($this->realSchemaDir);

        $fields = $loader->fieldsFor(['MR']);

        self::assertArrayHasKey('title', $fields, 'base fields must be present');
        self::assertArrayHasKey('indication', $fields, 'mr.json fields must be present');
        self::assertArrayHasKey('field_strength', $fields);
    }

    public function testAnUnknownModalityFallsBackToBaseOnlyWithNoError(): void
    {
        $loader = new Loader($this->realSchemaDir);

        $fields = $loader->fieldsFor(['PET']);

        self::assertArrayHasKey('title', $fields);
        self::assertArrayNotHasKey('indication', $fields, 'PET has no schema file, so no modality-specific fields');
    }

    public function testAnEmptyModalityListIsBaseOnly(): void
    {
        $loader = new Loader($this->realSchemaDir);

        $fields = $loader->fieldsFor([]);

        self::assertArrayHasKey('title', $fields);
        self::assertArrayNotHasKey('indication', $fields);
    }

    public function testACombinedStudyUnionsFieldsFromEveryListedModality(): void
    {
        $loader = new Loader($this->realSchemaDir);

        $fields = $loader->fieldsFor(['CT', 'MR']);

        self::assertArrayHasKey('dlp', $fields, 'a CT-only field');
        self::assertArrayHasKey('field_strength', $fields, 'an MR-only field');
    }

    public function testModalityNameIsCaseInsensitive(): void
    {
        $loader = new Loader($this->realSchemaDir);

        $fields = $loader->fieldsFor(['mr']);

        self::assertArrayHasKey('field_strength', $fields);
    }

    public function testPatientIsANestedObjectFieldWithItsOwnRequiredSubField(): void
    {
        $loader = new Loader($this->realSchemaDir);

        $fields = $loader->fieldsFor(['MR']);

        self::assertSame('object', $fields['patient']['type']);
        self::assertTrue($fields['patient']['fields']['name']['required']);
    }

    public function testDeeperThanOneLevelOfExtendsThrows(): void
    {
        $dir = sys_get_temp_dir() . '/reporion-schema-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/base.json', json_encode(['fields' => ['a' => ['type' => 'text']]]));
        file_put_contents($dir . '/mid.json', json_encode(['extends' => 'base', 'fields' => ['b' => ['type' => 'text']]]));
        file_put_contents($dir . '/leaf.json', json_encode(['extends' => 'mid', 'fields' => ['c' => ['type' => 'text']]]));

        try {
            $loader = new Loader($dir);

            $this->expectException(RuntimeException::class);
            $loader->fieldsFor(['leaf']);
        } finally {
            unlink($dir . '/base.json');
            unlink($dir . '/mid.json');
            unlink($dir . '/leaf.json');
            rmdir($dir);
        }
    }

    public function testMissingBaseFileThrows(): void
    {
        $dir = sys_get_temp_dir() . '/reporion-schema-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);

        try {
            $loader = new Loader($dir);

            $this->expectException(RuntimeException::class);
            $loader->fieldsFor([]);
        } finally {
            rmdir($dir);
        }
    }
}
