<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\RecallTerms;
use Reporion\Support\ReportName;
use Reporion\Support\ReportPath;

/**
 * What must never reach a prompt (invariant 8, D1) and taking it out: a
 * report's patient name (whole, and each part of three letters or more,
 * matched without regard to case or diacritics), its CNP, its accession
 * numbers and its page path, and, on sight, anything shaped like a CNP,
 * an accession or a report page name, the D30 name heading, links to other
 * pages (their paths name patients) and the imported `~~META: … ~~` block
 * (it carries the name, the record number and the diagnosis). Frontmatter
 * never goes either: a report's or a prompt page's (owner, 2026-09-27):
 * any `---` … `---` block of YAML lines is taken out.
 */
final class Redactor
{
    public const PATIENT = '[pacient]';

    /** @var list<string> folded name parts */
    private array $names = [];
    /** @var list<string> exact identifiers: CNP, accessions, paths */
    private array $exact = [];

    /**
     * Collects a report's identifiers (call once per document that may go
     * into a prompt: the report, its priors, examples).
     *
     * A report's name is taken from wherever it is written, not from
     * `patient.name` alone (2026-10-10): its path's name words (D1:
     * `{yymmdd}-{slug(name)}`), and its `#` heading (D30) when that shares a
     * word with either, so a report whose fields disagree (a name
     * corrected in one place only) cannot send the spelling left in another.
     *
     * @param array<string, mixed> $frontmatter
     * @param string               $body the document's body, for its name heading
     */
    public function learn(array $frontmatter, string $path, string $body = ''): void
    {
        $patient = \is_array($frontmatter['patient'] ?? null) ? $frontmatter['patient'] : [];
        $known = $this->learnName(MetaText::text($patient['name'] ?? null));
        $exact = [MetaText::text($patient['cnp'] ?? null), ...array_map(MetaText::text(...), array_column(Exams::of($frontmatter), 'accession'))];
        // Only a report's path names its patient (D1); a template's leaf is an ordinary word
        if (ReportPath::isReport($path)) {
            $segments = explode(':', $path);
            $exact[] = $path;
            $exact[] = (string) end($segments);
            $slug = explode('-', (string) end($segments));
            array_shift($slug);
            $known = [...$known, ...$this->learnName(implode(' ', array_filter($slug, static fn (string $w): bool => ctype_alpha($w))))];
            // The name heading, recognised by a word it shares with the name
            // known so far: an exam's `#` shares none and stays words
            if (preg_match('/\A\s*#[ \t]+(.+?)[ \t#]*(?:\R|\z)/u', self::withoutFrontmatter($body), $m) === 1
                && array_intersect(self::nameParts($m[1]), $known) !== []) {
                $this->learnName($m[1]);
            }
        }
        foreach ($exact as $value) {
            if ($value !== '' && mb_strlen($value) >= 4 && !\in_array($value, $this->exact, true)) {
                $this->exact[] = $value;
            }
        }
    }

    /**
     * Learns a name's parts of three letters or more
     *
     * @return list<string> its folded parts
     */
    private function learnName(string $name): array
    {
        $parts = self::nameParts($name);
        foreach ($parts as $part) {
            if (!\in_array($part, $this->names, true)) {
                $this->names[] = $part;
            }
        }

        return $parts;
    }

    /** @return list<string> a name's folded parts of three letters or more */
    private static function nameParts(string $name): array
    {
        return array_values(array_filter(
            array_map(self::fold(...), preg_split('/[\s,.\-]+/u', $name) ?: []),
            static fn (string $part): bool => mb_strlen($part) >= 3,
        ));
    }

    /**
     * $text with every identifier learned so far, and every identifier-shaped
     * string, taken out. $frontmatter, when given, drops that report's name
     * heading first (D30).
     *
     * @param array<string, mixed> $frontmatter
     */
    public function redact(string $text, array $frontmatter = []): string
    {
        $text = self::withoutFrontmatter($text);
        if ($frontmatter !== []) {
            $text = ReportName::withoutNameHeading($text, $frontmatter);
        }
        // The imported DokuWiki metadata block (TODO idea 10): all identifiers
        $text = (string) preg_replace('/^~~META:.*?^~~[ \t]*$\R?/ms', '', $text);
        // Links to pages keep their words, lose their path
        $text = (string) preg_replace('~\[([^\]]*)\]\(/?[a-z0-9][a-z0-9_-]*(?::[a-z0-9][a-z0-9_.-]*)+(?:#[^)\s]*)?\)~i', '$1', $text);
        foreach ($this->exact as $value) {
            $text = str_ireplace($value, self::label($value), $text);
        }
        $text = (string) preg_replace('/(?<!\d)[1-9]\d{12}(?!\d)/', '[CNP]', $text);
        $text = (string) preg_replace('/\b[A-Z][A-Z0-9]{0,11}-[A-Z]{1,5}-\d{2}-\d{3,}\b/', '[nr]', $text);
        $text = (string) preg_replace('/\b\d{6}-[a-z]+(?:-[a-z0-9]+)*\b/', '[raport]', $text);
        if ($this->names !== []) {
            $text = (string) preg_replace_callback('/\p{L}[\p{L}\'’-]*/u', function (array $m): string {
                foreach (preg_split('/[\'’-]/u', $m[0]) ?: [] as $part) {
                    if (\in_array(self::fold($part), $this->names, true)) {
                        return self::PATIENT;
                    }
                }

                return $m[0];
            }, $text);
            $text = (string) preg_replace('/\[pacient\](?:[ \t]+\[pacient\])+/', self::PATIENT, $text);
        }

        return $text;
    }

    /**
     * The last check before anything leaves (Context's final guard): whether
     * any identifier learned still appears in $text.
     */
    public function leaks(string $text): bool
    {
        foreach ($this->exact as $value) {
            if (stripos($text, $value) !== false) {
                return true;
            }
        }
        if ($this->names === []) {
            return false;
        }
        preg_match_all('/\p{L}+/u', $text, $words);
        foreach ($words[0] as $word) {
            if (\in_array(self::fold($word), $this->names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Contact details that name no patient but still point at a person: an
     * email, a phone number (nine digits or more), a street address. For a
     * text from outside the wiki (a HIS epicrisis holds relatives', employers'
     * and clinicians' details that learn() never heard of); a report's own text
     * is not run through it, where a long number is a measurement or a code.
     */
    public static function scrubContacts(string $text): string
    {
        $text = (string) preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', '[email]', $text);
        // A phone or any run of nine or more digits, with the usual separators; a decimal or a date is shorter
        $text = (string) preg_replace('/(?<![\d.,\/-])\+?\(?\d(?:[\s().-]{0,2}\d){8,}(?![\d])/', '[telefon]', $text);
        $street = '(?i:strada|str|bulevardul|bd|bdul|aleea|calea|șoseaua|soseaua|șos|sos|piața|piata|splaiul|intrarea)';

        return (string) preg_replace('/\b' . $street . '\.?\s+\p{Lu}[\p{L}.\'-]*(?:\s+\p{Lu}[\p{L}.\'-]*){0,3}(?:\s+\d+[A-Za-z]?)?(?:\s*,?\s*(?i:nr|bl|sc|ap|et)\.?\s*[\dA-Za-z]{1,4})*/u', '[adresă]', $text);
    }

    /**
     * $text without frontmatter: the leading `---` block, and any other
     * `---` … `---` pair whose lines are all YAML (`key: value`, indented,
     * `- item`, blank): a document pasted whole into a selection, say.
     */
    public static function withoutFrontmatter(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // The document's own: everything up to the closing fence, whatever it holds
        if (preg_match('/^\s*---[ \t]*\n.*?\n---[ \t]*(?:\n|$)/s', $text, $m) === 1) {
            $text = substr($text, \strlen($m[0]));
        }
        $yaml = '(?:[A-Za-z_][\w-]*[ \t]*:(?:[ \t].*)?|[ \t]+.*|-(?:[ \t].*)?|[ \t]*)';

        return (string) preg_replace('/^---[ \t]*\n(?:' . $yaml . '\n)*?[A-Za-z_][\w-]*[ \t]*:.*\n(?:' . $yaml . '\n)*?---[ \t]*$\n?/m', '', $text);
    }

    private static function label(string $value): string
    {
        return match (true) {
            preg_match('/^\d{13}$/', $value) === 1 => '[CNP]',
            str_contains($value, ':') || preg_match('/^\d{6}-/', $value) === 1 => '[raport]',
            default => '[nr]',
        };
    }

    /** Lower case without diacritics: how names are compared */
    public static function fold(string $text): string
    {
        return RecallTerms::fold($text);
    }
}
