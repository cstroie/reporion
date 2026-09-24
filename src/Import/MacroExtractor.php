<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Import;

/**
 * Extracts ~~...~~ macros from DokuWiki text. Three types:
 * - ~~LLM_TEMPLATE:path~~ → template macro
 * - ~~LLM_PREVIOUS:path~~ or ~~LLM_PREVIOUS_REPORT:path~~ → previous macro
 * - anything else → unknown macro (kept in body, counted for the report)
 *
 * The body is returned with macro text stripped: if a line is ONLY the macro,
 * the entire line is removed; otherwise the macro is stripped in place, leaving
 * surrounding prose intact (matching fixture behavior from docs/architecture-import.md).
 */
final class MacroExtractor
{
    /**
     * @return array{body: string, template: ?string, priors: list<string>, unknown: list<string>}
     */
    public static function extract(string $text): array
    {
        $template = null;
        $priors = [];
        $unknown = [];

        $body = preg_replace_callback(
            '/~~([^~]+)~~/',
            function ($m) use (&$template, &$priors, &$unknown): string {
                $content = $m[1];

                if (str_starts_with($content, 'LLM_TEMPLATE:')) {
                    $template = substr($content, \strlen('LLM_TEMPLATE:'));
                    return '';
                }

                if (str_starts_with($content, 'LLM_PREVIOUS:')) {
                    $priors[] = substr($content, \strlen('LLM_PREVIOUS:'));
                    return '';
                }

                if (str_starts_with($content, 'LLM_PREVIOUS_REPORT:')) {
                    $priors[] = substr($content, \strlen('LLM_PREVIOUS_REPORT:'));
                    return '';
                }

                // Unknown macro — keep it verbatim in body, but count it
                $unknown[] = $content;
                return $m[0];
            },
            $text,
            -1,
            $count
        );

        // Clean up lines that became empty after macro stripping
        $body = preg_replace('/^\s*\n/', '', $body);
        $body = trim($body) . "\n";

        return [
            'body' => $body,
            'template' => $template,
            'priors' => array_unique($priors),
            'unknown' => array_unique($unknown),
        ];
    }
}
