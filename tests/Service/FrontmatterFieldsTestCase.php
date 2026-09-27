<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\FrontmatterFields;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

abstract class FrontmatterFieldsTestCase extends StorageTestCase
{
    protected const PATH = 'reports:mri:mioveni:260927-popescu-ana';

    protected FlatFile $storage;
    protected FrontmatterFields $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $index);
        $this->fields = new FrontmatterFields(
            new Loader(\dirname(__DIR__, 2) . '/conf/schema'),
            $index,
            ['mioveni' => ['name' => 'Mioveni', 'devices' => ['MV-MR-01' => 'Siemens Aera 1.5T']]]
        );
    }
}
