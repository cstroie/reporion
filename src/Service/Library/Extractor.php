<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Exception\LibraryException;
use Reporion\Service\Backup\ProcessRun;
use Reporion\Support\SourceText;

/**
 * An uploaded document's text (docs/library.md, pipeline step 2). Every tool runs as an argument
 * list in a private temporary directory, with a timeout, its stderr discarded (as groff, D42):
 * txt/md as they are; pdf through pdftotext, pdfinfo and pdftohtml (page 1's font sizes for the
 * title); docx/odt/rtf/html/epub through pandoc; doc/ppt/pptx/xls/xlsx/odp through LibreOffice
 * first (doc to docx, the others to pdf).
 */
final class Extractor
{
    /** extension => how it is read */
    public const TYPES = [
        'pdf' => 'pdf', 'txt' => 'text', 'md' => 'markdown', 'markdown' => 'markdown',
        'docx' => 'pandoc', 'odt' => 'pandoc', 'rtf' => 'pandoc', 'html' => 'pandoc', 'htm' => 'pandoc', 'epub' => 'pandoc',
        'doc' => 'office', 'ppt' => 'office', 'pptx' => 'office', 'odp' => 'office', 'xls' => 'office', 'xlsx' => 'office', 'ods' => 'office',
    ];

    /** The programs used, by name; each may be set to a full path */
    public const TOOLS = ['pdftotext', 'pdfinfo', 'pdftohtml', 'pandoc', 'soffice'];

    private const PANDOC_FORMATS = ['docx' => 'docx', 'odt' => 'odt', 'rtf' => 'rtf', 'html' => 'html', 'htm' => 'html', 'epub' => 'epub'];

    /** Fewer characters than this per page on average: a scan with no text layer */
    private const SCAN_CHARS_PER_PAGE = 25;

    /** How much of the start of the text Identify reads */
    private const HEAD_BYTES = 12000;

    /** @var array<string, string> */
    private readonly array $tools;

    /**
     * @param array<string, string> $tools name => program (default: the name, found on PATH)
     * @param \Closure(list<string>, int, array<string, string>): array{0: int, 1: string}|null $runner
     */
    public function __construct(
        private readonly string $tmpRoot,
        array $tools = [],
        private readonly int $timeout = 120,
        private readonly ?\Closure $runner = null,
    ) {
        $resolved = [];
        foreach (self::TOOLS as $name) {
            $resolved[$name] = \is_string($tools[$name] ?? null) && $tools[$name] !== '' ? $tools[$name] : $name;
        }
        $this->tools = $resolved;
    }

    public static function extensionOf(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return isset(self::TYPES[$ext]) ? $ext : null;
    }

    /** @throws LibraryException */
    public function extract(string $bytes, string $ext): Extracted
    {
        $type = self::TYPES[$ext] ?? throw new LibraryException(LibraryException::UNSUPPORTED);
        if ($type === 'text' || $type === 'markdown') {
            return $this->fromText(self::utf8($bytes), $type === 'markdown');
        }

        $dir = $this->tmpDir();
        try {
            $in = $dir . '/in.' . $ext;
            file_put_contents($in, $bytes);
            if ($type === 'office') {
                [$in, $ext] = $this->convertOffice($dir, $in, $ext);
                $type = $ext === 'pdf' ? 'pdf' : 'pandoc';
            }

            return $type === 'pdf' ? $this->fromPdf($dir, $in) : $this->fromPandoc($dir, $in, $ext);
        } finally {
            self::removeTree($dir);
        }
    }

    /** @return array<string, bool> each tool, and whether it runs */
    public function check(): array
    {
        $found = [];
        foreach ($this->tools as $name => $program) {
            $args = $name === 'soffice' || $name === 'pandoc' ? [$program, '--version'] : [$program, '-v'];
            [$exit] = $this->run($args, 20);
            $found[$name] = $exit >= 0 && $exit !== 127;
        }

        return $found;
    }

    private function fromText(string $text, bool $markdown): Extracted
    {
        $clean = $markdown ? SourceText::fromMarkdown($text) : SourceText::fromPages([$text]);

        return new Extracted($clean['body'], $clean['references'], 1, trim($text) !== '', substr($text, 0, self::HEAD_BYTES));
    }

    private function fromPdf(string $dir, string $in): Extracted
    {
        $out = $dir . '/out.txt';
        $this->must([$this->tools['pdftotext'], '-enc', 'UTF-8', '-eol', 'unix', $in, $out]);
        $text = (string) @file_get_contents($out);
        $pages = explode("\f", $text);
        if (\count($pages) > 1 && trim((string) end($pages)) === '') {
            array_pop($pages);
        }

        [$exit, $infoText] = $this->run([$this->tools['pdfinfo'], '-enc', 'UTF-8', $in], 30);
        $info = $exit === 0 ? self::parseInfo($infoText) : [];
        [$exit, $xmp] = $this->run([$this->tools['pdfinfo'], '-meta', $in], 30);
        [$exit2, $xml] = $this->run([$this->tools['pdftohtml'], '-xml', '-i', '-q', '-f', '1', '-l', '1', '-stdout', $in], 30);

        $chars = \strlen((string) preg_replace('/\s+/', '', $text));
        $count = max(1, (int) ($info['Pages'] ?? \count($pages)));
        $clean = SourceText::fromPages($pages);

        return new Extracted(
            $clean['body'],
            $clean['references'],
            $count,
            $chars >= self::SCAN_CHARS_PER_PAGE * $count,
            substr($text, 0, self::HEAD_BYTES),
            $info,
            $exit === 0 ? substr($xmp, 0, 65536) : '',
            $exit2 === 0 ? self::parseLayout($xml) : [],
        );
    }

    private function fromPandoc(string $dir, string $in, string $ext): Extracted
    {
        $out = $dir . '/out.md';
        // gfm without raw HTML; images dropped below: the library keeps the text, the original keeps the rest
        $this->must([$this->tools['pandoc'], '-f', self::PANDOC_FORMATS[$ext], '-t', 'gfm-raw_html', '--wrap=none', '-o', $out, $in]);
        $markdown = (string) @file_get_contents($out);
        $markdown = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)(\{[^}]*\})?/', '', $markdown);
        $clean = SourceText::fromMarkdown($markdown);

        return new Extracted($clean['body'], $clean['references'], 1, trim($markdown) !== '', substr($markdown, 0, self::HEAD_BYTES));
    }

    /** @return array{0: string, 1: string} the converted file and its extension */
    private function convertOffice(string $dir, string $in, string $ext): array
    {
        $to = $ext === 'doc' ? 'docx' : 'pdf';
        // LibreOffice needs a profile directory it may write: its own, in the temporary one
        $this->must(
            [$this->tools['soffice'], '--headless', '--norestore', '-env:UserInstallation=file://' . $dir . '/profile', '--convert-to', $to, '--outdir', $dir . '/conv', $in],
            ['HOME' => $dir],
        );
        $converted = $dir . '/conv/in.' . $to;
        if (!is_file($converted)) {
            throw new LibraryException(LibraryException::FAILED);
        }

        return [$converted, $to];
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    private function must(array $argv, array $env = []): void
    {
        [$exit] = $this->run($argv, $this->timeout, $env);
        if ($exit === -2) {
            throw new LibraryException(LibraryException::TIMEOUT);
        }
        if ($exit === -1 || $exit === 127) {
            throw new LibraryException(LibraryException::TOOL_MISSING);
        }
        if ($exit !== 0) {
            throw new LibraryException(LibraryException::FAILED);
        }
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string}
     */
    private function run(array $argv, int $timeout, array $env = []): array
    {
        return $this->runner !== null ? ($this->runner)($argv, $timeout, $env) : ProcessRun::run($argv, $timeout, $env);
    }

    /** @return array<string, string> */
    public static function parseInfo(string $text): array
    {
        $info = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z ]*?):\s+(.*)$/', $line, $m) === 1) {
                $info[$m[1]] = trim($m[2]);
            }
        }

        return $info;
    }

    /**
     * Page 1's text lines from `pdftohtml -xml`, with their font size, top to bottom
     *
     * @return list<array{size: float, top: int, text: string}>
     */
    public static function parseLayout(string $xml): array
    {
        $sizes = [];
        preg_match_all('/<fontspec\s+id="(\d+)"\s+size="([\d.]+)"/', $xml, $fonts, PREG_SET_ORDER);
        foreach ($fonts as $f) {
            $sizes[$f[1]] = (float) $f[2];
        }
        $fragments = [];
        preg_match_all('/<text\s+top="(\d+)"\s+left="(-?\d+)"\s+width="(\d+)"\s+height="(\d+)"\s+font="(\d+)"[^>]*>(.*?)<\/text>/s', $xml, $texts, PREG_SET_ORDER);
        foreach ($texts as $t) {
            $text = html_entity_decode(strip_tags($t[6]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (trim($text) !== '') {
                $fragments[] = ['top' => (int) $t[1], 'left' => (int) $t[2], 'width' => (int) $t[3], 'height' => (int) $t[4], 'size' => $sizes[$t[5]] ?? 0.0, 'text' => $text];
            }
        }
        usort($fragments, static fn (array $a, array $b): int => [$a['top'], $a['left']] <=> [$b['top'], $b['left']]);

        // Fragments that overlap vertically are one line (a superscript affiliation mark sits a little higher)
        $lines = [];
        foreach ($fragments as $f) {
            $last = \count($lines) - 1;
            if ($last >= 0) {
                $line = $lines[$last];
                $overlap = min($line['bottom'], $f['top'] + $f['height']) - max($line['top'], $f['top']);
                if ($overlap >= 0.5 * min($f['height'], $line['bottom'] - $line['top'])) {
                    $lines[$last]['parts'][] = $f;
                    $lines[$last]['bottom'] = max($line['bottom'], $f['top'] + $f['height']);
                    continue;
                }
            }
            $lines[] = ['top' => $f['top'], 'bottom' => $f['top'] + $f['height'], 'parts' => [$f]];
        }

        $result = [];
        foreach ($lines as $line) {
            usort($line['parts'], static fn (array $a, array $b): int => $a['left'] <=> $b['left']);
            $text = '';
            $end = null;
            $chars = [];
            foreach ($line['parts'] as $part) {
                if ($end !== null && $part['left'] - $end > 2 && !str_ends_with($text, ' ') && !str_starts_with($part['text'], ' ')) {
                    $text .= ' ';
                }
                $text .= $part['text'];
                $end = $part['left'] + $part['width'];
                $key = (string) $part['size'];
                $chars[$key] = ($chars[$key] ?? 0) + mb_strlen($part['text']);
            }
            // The line's size is the one most of its characters are in
            arsort($chars);
            $result[] = ['size' => (float) array_key_first($chars), 'top' => $line['top'], 'text' => trim((string) preg_replace('/\s+/u', ' ', $text))];
        }

        return $result;
    }

    /** Text that is not UTF-8 is taken as Windows-1250 (Romanian files of old editors), then Latin-1 */
    private static function utf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $bytes);

        return \is_string($converted) && $converted !== '' ? $converted : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
    }

    private function tmpDir(): string
    {
        $dir = $this->tmpRoot . '/library-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new LibraryException(LibraryException::FAILED);
        }

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
