<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Brings a report body to the one heading shape every report uses
 * (decided 2026-09-27, docs/FORMATS.md §11):
 *
 *     # {patient name}        D30's name heading
 *     ## {exam}               one per exam (roadmap phase 12)
 *     ### {section}           Indicație, Descriere, Concluzii…
 *     #### {sub-part}         deeper structure inside a section
 *
 * The imported archive has the name at `##` or `###` and the exam and its
 * sections side by side at the next level. Only the run of `#` on a
 * heading line ever changes — never a word of text — so the rendered text
 * is identical before and after. A shared conclusion after two or more
 * exams (one conclusion for all of them, the archive's usual shape) becomes
 * `## Concluzii`, a sibling of the exams.
 *
 * Anything the rules cannot place with certainty is left untouched and
 * reported for review: a first heading that is not the patient's name,
 * a repeated name heading, exams at different levels, a heading before the
 * first exam or above the section level, a setext heading, a heading in a
 * quote or list, or sections with no exam heading at all.
 */
final class HeadingNormalizer
{
    public const NORMALIZED = 'normalized';
    public const UNCHANGED = 'unchanged';
    public const REVIEW = 'review';

    private const EXAM = '/^(irm|rm|mri|ct|angio|uro|colangio|entero|ecografie|ecograf|us|rx|radiografie|mamografie|pet)\b/iu';
    private const SECTION = '/^(indica[tțţ]i[ei]|tehnic[aă]|descriere|concluzi[ei]|recomand[aă]ri?|date pacient|examinare|protocol|comparativ|compara[tțţ]ie|analiz[aă] comparativ[aă]|istoric|anamnez[aă]|rezultat|observa[tțţ]ii)\b/iu';
    private const CONCLUSION = '/^concluzi[ei]\b/iu';
    /** A body part with no modality in front ("Coloană cervicală", "Torace"): an exam, most likely */
    private const ANATOMY = '/^(coloan[aă]|torace|abdomen|pelvis|bazin|cerebral|craniu|cervical|toracal|lombar|genunchi|um[aă]r|[sș]old|glezn[aă]|cot|pumn|m[aâ]n[aă]|picior|gamb[aă]|coaps[aă]|orbite?|sinusuri|g[aâ]t|cord|s[aâ]n|mamar|rinichi|ficat)\b/iu';
    private const ATX = '/^( {0,3})(#{1,6})(?=[ \t]|$)/';

    private static ?MarkdownParser $parser = null;

    /**
     * @param array<string, mixed> $frontmatter the patient block names the name heading
     *
     * @return array{outcome: string, body: string, exams: list<string>, shape: string, reasons: list<string>}
     */
    public static function normalize(string $body, array $frontmatter): array
    {
        $headings = self::headings($body);
        $name = self::fold(\is_array($frontmatter['patient'] ?? null) ? (string) ($frontmatter['patient']['name'] ?? '') : '');
        $reasons = [];

        $classes = [];
        foreach ($headings as $i => $h) {
            $classes[$i] = match (true) {
                $name !== '' && self::fold($h['text']) === $name => 'N',
                preg_match(self::SECTION, $h['text']) === 1 => 'S',
                preg_match(self::EXAM, $h['text']) === 1 => 'E',
                default => 'O',
            };
        }
        $shape = implode(' ', array_map(static fn (array $h, string $c): string => $c . $h['level'], $headings, $classes));
        // By reference: the reasons are collected after this is defined
        $result = static function (string $outcome, string $newBody = '', array $exams = []) use ($body, $shape, &$reasons): array {
            return [
                'outcome' => $outcome,
                'body' => $outcome === self::NORMALIZED ? $newBody : $body,
                'exams' => $exams,
                'shape' => $shape,
                'reasons' => array_values(array_unique($reasons)),
            ];
        };

        if ($headings === []) {
            return $result(self::UNCHANGED);
        }
        foreach ($headings as $h) {
            if (!$h['top']) {
                $reasons[] = 'heading inside a quote or list';
            } elseif (!$h['atx']) {
                $reasons[] = 'setext heading';
            }
            if (preg_match('/\p{L}/u', $h['text']) !== 1) {
                $reasons[] = 'heading without letters';
            }
        }
        if ($classes[0] !== 'N') {
            $reasons[] = $name === '' ? 'no patient name to match' : 'first heading is not the patient name';
        } elseif (trim(implode("\n", \array_slice(self::lines($body), 0, $headings[0]['line']))) !== '') {
            $reasons[] = 'text before the name heading';
        }
        if (\count(array_keys($classes, 'N', true)) > 1) {
            $reasons[] = 'the name heading repeats';
        }
        if ($reasons !== []) {
            return $result(self::REVIEW);
        }

        $exams = array_keys($classes, 'E', true);
        $rest = array_keys(array_filter($classes, static fn (string $c): bool => $c === 'S' || $c === 'O'));
        $target = [0 => 1];
        if ($exams === []) {
            if ($rest !== []) {
                $reasons[] = 'sections with no exam heading';

                return $result(self::REVIEW);
            }
        } else {
            $examLevel = $headings[$exams[0]]['level'];
            foreach ($exams as $i) {
                if ($headings[$i]['level'] !== $examLevel) {
                    $reasons[] = 'exam headings at different levels';
                }
            }
            if ($headings[0]['level'] >= $examLevel) {
                $reasons[] = 'the name heading is not above the exams';
            }
            if ($rest !== [] && min($rest) < $exams[0]) {
                $reasons[] = 'a heading before the first exam';
            }
            foreach ($rest as $i) {
                if ($headings[$i]['level'] === $examLevel && preg_match(self::ANATOMY, $headings[$i]['text']) === 1) {
                    $reasons[] = 'an exam heading without its modality';
                }
            }

            // One conclusion after the last of several exams, beside them: shared
            $conclusions = array_values(array_filter($rest, static fn (int $i): bool => preg_match(self::CONCLUSION, $headings[$i]['text']) === 1));
            $shared = \count($exams) >= 2 && \count($conclusions) === 1 && $conclusions[0] > max($exams)
                && $headings[$conclusions[0]]['level'] === $examLevel ? $conclusions[0] : null;

            $sectionLevels = array_map(static fn (int $i): int => $headings[$i]['level'], array_values(array_diff($rest, [$shared])));
            $sectionLevel = $sectionLevels === [] ? $examLevel + 1 : min($sectionLevels);
            if ($sectionLevel !== $examLevel && $sectionLevel !== $examLevel + 1) {
                $reasons[] = 'sections not directly under the exams';
            }
            if ($reasons !== []) {
                return $result(self::REVIEW);
            }

            foreach ($exams as $i) {
                $target[$i] = 2;
            }
            foreach ($rest as $i) {
                $target[$i] = $i === $shared ? 2 : min(6, 3 + $headings[$i]['level'] - $sectionLevel);
            }
        }

        $lines = self::lines($body);
        foreach ($target as $i => $level) {
            $n = $headings[$i]['line'];
            $lines[$n] = (string) preg_replace(self::ATX, '${1}' . str_repeat('#', $level), $lines[$n], 1);
        }
        $newBody = implode("\n", $lines);
        $examTitles = array_map(static fn (int $i): string => $headings[$i]['text'], $exams);

        return $result($newBody === $body ? self::UNCHANGED : self::NORMALIZED, $newBody, $examTitles);
    }

    /**
     * The document's headings in order: 0-based line, level, plain text,
     * whether it is an ATX heading (`#`) and whether it sits at the top
     * level (not in a quote or list).
     *
     * @return list<array{line: int, level: int, text: string, atx: bool, top: bool}>
     */
    private static function headings(string $body): array
    {
        self::$parser ??= self::parser();
        $lines = self::lines($body);
        $found = [];
        foreach (self::$parser->parse($body)->iterator() as $node) {
            if (!$node instanceof Heading) {
                continue;
            }
            $line = (int) $node->getStartLine() - 1;
            $found[] = [
                'line' => $line,
                'level' => $node->getLevel(),
                'text' => trim(self::plainText($node)),
                'atx' => preg_match(self::ATX, $lines[$line] ?? '') === 1,
                'top' => $node->parent() instanceof Document,
            ];
        }

        return $found;
    }

    private static function parser(): MarkdownParser
    {
        $environment = new Environment([]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        return new MarkdownParser($environment);
    }

    private static function plainText(Node $node): string
    {
        $text = '';
        foreach ($node->iterator() as $child) {
            if ($child instanceof StringContainerInterface) {
                $text .= $child->getLiteral();
            }
        }

        return $text;
    }

    /** @return list<string> */
    private static function lines(string $body): array
    {
        return explode("\n", $body);
    }

    private static function fold(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
