<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * What a page is for, told from its path alone (docs/namespaces.md): the one
 * place that knows the namespaces of the kinds. A report is a page under
 * `reports:` with a report name (Support\ReportPath); a snippet or a template
 * is under `templates:`; a prompt under `ai:profiles:`; a reference is any
 * other page under one of the reference namespaces (`references.namespaces`,
 * default `radiology`); the rest are ordinary pages.
 */
final class PageKind
{
    public const REPORT = 'report';
    public const TEMPLATE = 'template';
    public const SNIPPET = 'snippet';
    public const PROMPT = 'prompt';
    public const REFERENCE = 'reference';
    public const PAGE = 'page';

    /** The assistant's prompt pages: `ai:profiles:{profile}:{id}` */
    public const PROMPTS_NS = 'ai:profiles';

    /**
     * @param list<string> $referenceNamespaces
     */
    public static function of(string $path, array $referenceNamespaces = []): string
    {
        if (ReportPath::isReport($path)) {
            return self::REPORT;
        }
        if (self::within($path, Templates::NS)) {
            return self::within($path, Templates::NS . ':snippets') ? self::SNIPPET : self::TEMPLATE;
        }
        if (self::within($path, self::PROMPTS_NS)) {
            return self::PROMPT;
        }
        foreach ($referenceNamespaces as $ns) {
            if ($ns !== '' && self::within($path, $ns)) {
                return self::REFERENCE;
            }
        }

        return self::PAGE;
    }

    /** $path is $ns's descendant or $ns itself: the namespace's own page */
    private static function within(string $path, string $ns): bool
    {
        return $path === $ns || str_starts_with($path, $ns . ':');
    }
}
