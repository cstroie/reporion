<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Support\Devices;

/**
 * The sites' devices for a plugin (the dicom plugin's scanner box,
 * 2026-10-07): read a site's devices, find the one a PACS scanner name is
 * linked to, and link one — the only write a plugin gets into the
 * instance's settings, never the rest of them. Writes go through
 * InstanceSettings (data/settings.yaml, disk authoritative).
 */
final class SiteDevices
{
    /**
     * @param array<string, mixed> $sites the config's `sites` (Admin → Sites, after InstanceSettings::applyTo())
     */
    public function __construct(
        private readonly InstanceSettings $settings,
        private array $sites,
    ) {
    }

    /** Whether $site is a configured site */
    public function has(string $site): bool
    {
        return \is_array($this->sites[$site] ?? null);
    }

    /**
     * Code → name of the site's devices
     *
     * @return array<string, string>
     */
    public function names(string $site): array
    {
        return Devices::names($this->site($site));
    }

    /** The device a PACS scanner name is linked to at $site, or null */
    public function forPacs(string $site, string $pacsName): ?string
    {
        return Devices::forPacs($this->site($site), $pacsName);
    }

    /** A free code for a new device of $modality at $site (GA-MR-04) */
    public function suggestCode(string $site, string $modality): string
    {
        $entry = $this->site($site);
        $fallback = \is_string($entry['accession_code'] ?? null) && $entry['accession_code'] !== '' ? $entry['accession_code'] : $site;

        return Devices::suggestCode($entry, $modality, $fallback);
    }

    /**
     * Links the scanner to a device of $site — a new one ($create) or an
     * existing one — and keeps the in-memory copy in step
     *
     * @throws InvalidArgumentException with a message for the form
     */
    public function link(string $site, string $code, string $name, string $pacsName, bool $create): void
    {
        $this->settings->linkPacsDevice($this->sites, $site, $code, $name, $pacsName, $create);
        $stored = $this->settings->load();
        if (\is_array($stored['sites'] ?? null)) {
            $this->sites = $stored['sites'];
        }
    }

    /** @return array<string, mixed> */
    private function site(string $site): array
    {
        return \is_array($this->sites[$site] ?? null) ? $this->sites[$site] : [];
    }
}
