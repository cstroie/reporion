<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use InvalidArgumentException;
use Reporion\Service\InstanceSettings;
use Reporion\Service\TagDictionary;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * A hand-edited settings.yaml or tags.yaml with a typo reads as empty (the
 * site stays up); a save from the admin screen must then be refused rather
 * than write back a file holding only the one key it changed.
 */
final class UnparsableSettingsTest extends StorageTestCase
{
    private const BROKEN = "site:\n  title: Clinic\nai: [unclosed\n";

    public function testASaveOverAnUnparsableSettingsFileIsRefusedAndTheFileKept(): void
    {
        file_put_contents($this->dataRoot . '/settings.yaml', self::BROKEN);
        $settings = new InstanceSettings($this->dataRoot);

        self::assertSame([], $settings->load(), 'reading still falls back');
        try {
            $settings->savePluginsEnabled(['dicom']);
            self::fail('the save must be refused');
        } catch (InvalidArgumentException) {
        }
        self::assertSame(self::BROKEN, file_get_contents($this->dataRoot . '/settings.yaml'));
    }

    public function testASaveOverAnUnparsableTagDictionaryIsRefusedAndTheFileKept(): void
    {
        file_put_contents($this->dataRoot . '/tags.yaml', self::BROKEN);
        $dictionary = new TagDictionary($this->dataRoot, $this->dataRoot . '/no-seed.yaml');

        try {
            $dictionary->set('glioma', 'diagnosis', '', '');
            self::fail('the save must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertSame('unparsable', $e->getMessage());
        }
        self::assertSame(self::BROKEN, file_get_contents($this->dataRoot . '/tags.yaml'));
    }

    public function testAMissingSettingsFileIsStillWritable(): void
    {
        $settings = new InstanceSettings($this->dataRoot);
        $settings->savePluginsEnabled(['dicom']);

        self::assertNotSame([], $settings->load());
    }
}
