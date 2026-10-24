<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Http\TemplateLintText;
use Reporion\Support\DocumentFormat;
use Reporion\Support\TemplateLint;
use Reporion\Support\Templates;
use Throwable;

/**
 * bin/reporion templates:lint [--ns=<templates:…>] [--json]
 *
 * Every report template's problems for the Create action (Support\TemplateLint,
 * 2026-10-24): read from the page files like `doctor` does, so no database is
 * opened and nothing is written. Exit 1 when a template has a problem.
 */
final class TemplatesLintCommand implements CommandInterface
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Lists what the report templates do that the Create action reads badly: unclosed brackets, choices outside brackets, dashes, brackets in headings, vague choices, the heading shape. Writes nothing.',
            usage: '[--ns=<templates:…>] [--json]',
            options: [
                '--ns=<templates:…>' => 'only the templates under this namespace (default: all of templates)',
                '--json' => 'print the problems as JSON',
            ],
            details: 'Exit 0 when no template has a problem, 1 otherwise. Snippets (templates:snippets) and page templates (templates:pages) are not checked.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $ns = Templates::NS;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--ns=')) {
                $ns = trim(substr($arg, 5), ' :');
            }
        }
        if ($ns !== Templates::NS && !str_starts_with($ns, Templates::NS . ':')) {
            $output->error('--ns must be templates or a namespace under it');

            return 2;
        }

        $found = [];
        $checked = 0;
        foreach (self::pageFiles($this->dataDir . '/pages/' . str_replace(':', '/', $ns), $ns) as $path => $file) {
            try {
                [$frontmatter, $body] = DocumentFormat::parse((string) file_get_contents($file));
            } catch (Throwable) {
                continue; // an unparseable page is integrity:verify's to report
            }
            if (!TemplateLint::applies($path, $frontmatter)) {
                continue;
            }
            ++$checked;
            $problems = TemplateLint::problems($frontmatter, $body);
            if ($problems !== []) {
                $found[$path] = $problems;
            }
        }
        ksort($found);

        if (\in_array('--json', $args, true)) {
            $output->line((string) json_encode(['checked' => $checked, 'templates' => array_map(
                static fn (array $problems): array => array_map(static fn (array $p): array => $p + ['message' => TemplateLintText::message($p)], $problems),
                $found,
            )], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $found === [] ? 0 : 1;
        }
        foreach ($found as $path => $problems) {
            $output->line($path);
            foreach ($problems as $problem) {
                $output->line('  ' . TemplateLintText::message($problem));
            }
        }
        $output->line(\sprintf('%d template(s) checked, %d with problems', $checked, \count($found)));

        return $found === [] ? 0 : 1;
    }

    /** @return iterable<string, string> path => file, for each page file under $root */
    private static function pageFiles(string $root, string $ns): iterable
    {
        if (!is_dir($root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.md')) {
                yield $ns . ':' . str_replace('/', ':', substr($item->getPathname(), \strlen($root) + 1, -3)) => $item->getPathname();
            }
        }
    }
}
