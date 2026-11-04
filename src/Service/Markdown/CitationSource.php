<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

/**
 * Where Service\Render finds the record a `[@key]` names (docs/library.md). Bibliographic facts
 * only, whoever reads: the library page's text and original stay behind its visibility.
 */
interface CitationSource
{
    /**
     * @return ?array<string, mixed> the source's CSL-JSON record, null when no source has the key
     */
    public function record(string $key): ?array;

    /**
     * What became of the article after it was published (Support\SourceNotice): `retracted`, `concern`,
     * `corrected`, or null for nothing to report (or no such source). Shown on screen to a reader who can open the library
     * page, never in print or an export.
     */
    public function notice(string $key): ?string;

    /** The page path of the source, to link to for a reader who may open it */
    public function path(string $key): string;
}
