<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\PageNotFoundException;
use Reporion\Service\Guide\Guide;
use Reporion\Service\Workup\Workup;
use Reporion\Storage\FileTree;
use Reporion\Support\GuideCodes;
use Reporion\Support\ReportPath;

/**
 * bin/reporion guide:measure [--limit=<n>] [--json]: how well the reports' indications match the
 * imaging guide (D62 step 4), so the Checks warning's thresholds are set from the archive, not
 * guessed. Reads every report, writes nothing, sends nothing, prints **counts only**: never a
 * path, a name or a report's words (invariant 8). For each report with an indication: whether a
 * situation matched, its coverage and lead (Guide::strongest()), and how many would be warned at
 * each pair of thresholds, with the current ones (Guide::MIN_COVERAGE, MIN_MARGIN) marked.
 */
final class GuideMeasureCommand implements CommandInterface
{
    private const COVERAGES = [0.4, 0.5, 0.6, 0.7, 0.8];
    private const MARGINS = [0.0, 0.15, 0.25, 0.4, 0.6];

    public function __construct(
        private readonly FileTree $storage,
        private readonly Guide $guide,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Counts how well the reports\' indications match the imaging guide, to set the Checks warning\'s thresholds.',
            usage: '[--limit=<n>] [--json]',
            options: [
                '--limit=<n>' => 'read at most n reports (the newest paths last; default all)',
                '--json' => 'print the counts as JSON',
            ],
            details: 'Reads only and prints counts only: no path, name or text. Needs the guide loaded (guide:load). Run as the web server\'s user on a live install.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $limit = 0;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
                $limit = (int) $m[1];
            } elseif ($arg !== '--json') {
                $output->error('Unknown option ' . $arg);

                return 1;
            }
        }
        $json = \in_array('--json', $args, true);
        if ($this->guide->info() === null) {
            $output->error('The guide is not loaded: bin/reporion guide:load <GHID.csv> first.');

            return 1;
        }
        if (!$json) {
            $output->line('Matching the reports\' indications to the imaging guide (reads only, counts only):');
        }
        $c = ['reports' => 0, 'with_indication' => 0, 'with_modality_type' => 0, 'matched' => 0, 'coverage' => array_fill_keys(['0.0-0.2', '0.2-0.4', '0.4-0.6', '0.6-0.8', '0.8-1.0'], 0), 'margin' => array_fill_keys(['0.0-0.15', '0.15-0.25', '0.25-0.4', '0.4-0.6', '0.6-1.0'], 0), 'this_row' => array_fill_keys(array_keys(GuideCodes::INDICATIONS), 0) + ['none' => 0], 'warn' => []];
        $points = [];
        foreach ($this->storage->allPaths() as $path) {
            if (!ReportPath::isReport($path)) {
                continue;
            }
            if ($limit > 0 && $c['reports'] >= $limit) {
                break;
            }
            try {
                $page = $this->storage->read($path);
            } catch (PageNotFoundException) {
                continue;
            }
            ++$c['reports'];
            $facts = Workup::facts($page);
            if ($facts['indication'] === '') {
                continue;
            }
            ++$c['with_indication'];
            if (array_filter(array_map(GuideCodes::typeOfModality(...), $facts['modalities'])) !== []) {
                ++$c['with_modality_type'];
            }
            $match = $this->guide->strongest($facts['indication'], $facts['regions'], $facts['modalities'], $facts['age']);
            if ($match === null) {
                continue;
            }
            ++$c['matched'];
            ++$c['coverage'][self::bucket($match['coverage'], array_keys($c['coverage']))];
            ++$c['margin'][self::bucket($match['margin'], array_keys($c['margin']))];
            $rows = array_values(array_filter($match['situation']['exams'], static fn (array $x): bool => $x['this']));
            $codes = array_values(array_unique(array_map(static fn (array $x): string => $x['indication_code'] !== '' ? $x['indication_code'] : 'see', $rows)));
            ++$c['this_row'][$codes === [] ? 'none' : (\count($codes) === 1 ? $codes[0] : 'see')];
            $discouraged = $rows !== [] && \count(array_filter($rows, static fn (array $x): bool => \in_array($x['indication_code'], GuideCodes::DISCOURAGED, true))) === \count($rows);
            $points[] = [$match['coverage'], $match['margin'], $discouraged];
        }
        foreach (self::COVERAGES as $cov) {
            foreach (self::MARGINS as $mar) {
                $c['warn'][\sprintf('%.2f/%.2f', $cov, $mar)] = \count(array_filter($points, static fn (array $p): bool => $p[2] && $p[0] >= $cov && $p[1] >= $mar));
            }
        }
        $c['current'] = \sprintf('%.2f/%.2f', Guide::MIN_COVERAGE, Guide::MIN_MARGIN);
        if ($json) {
            $output->line((string) json_encode($c, JSON_PRETTY_PRINT));

            return 0;
        }
        $output->line(\sprintf('reports %d, with an indication %d, of an exam type the guide has %d, matched a situation %d', $c['reports'], $c['with_indication'], $c['with_modality_type'], $c['matched']));
        $output->line('coverage of the indication\'s words: ' . self::pairs($c['coverage']));
        $output->line('lead over the next situation:       ' . self::pairs($c['margin']));
        $output->line('this exam\'s row in the match:       ' . self::pairs($c['this_row']));
        $output->line('reports a Check would warn, by coverage (rows) and lead (columns); * the current thresholds:');
        $output->line('        ' . implode('', array_map(static fn (float $m): string => str_pad(\sprintf('%.2f', $m), 8, ' ', STR_PAD_LEFT), self::MARGINS)));
        foreach (self::COVERAGES as $cov) {
            $cells = array_map(static fn (float $mar): string => str_pad((string) $c['warn'][\sprintf('%.2f/%.2f', $cov, $mar)] . (\sprintf('%.2f/%.2f', $cov, $mar) === $c['current'] ? '*' : ' '), 8, ' ', STR_PAD_LEFT), self::MARGINS);
            $output->line(str_pad(\sprintf('%.2f', $cov), 8) . implode('', $cells));
        }

        return 0;
    }

    /** @param list<string> $ranges "a-b" keys, in order */
    private static function bucket(float $value, array $ranges): string
    {
        foreach ($ranges as $range) {
            [, $to] = array_map('floatval', explode('-', $range));
            if ($value < $to) {
                return $range;
            }
        }

        return (string) end($ranges);
    }

    /** @param array<string, int> $counts */
    private static function pairs(array $counts): string
    {
        return implode('  ', array_map(static fn (string $k, int $v): string => $k . ': ' . $v, array_keys($counts), $counts));
    }
}
