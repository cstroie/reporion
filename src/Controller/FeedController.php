<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use DateTimeImmutable;
use Exception;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;

/**
 * GET /feed.atom and GET /feed/{ns}.atom — Atom feeds of public pages in
 * explicitly allowed namespaces only (decided 2026-09-26):
 *
 * - `feeds.namespaces` in conf/local.php lists them; empty means no feeds;
 * - `reports` and anything under it is never a feed, whatever the config
 *   says — a report's path names the patient (invariant 8);
 * - entries are what an anonymous caller could list — public pages only —
 *   and additionally never a page that carries patient data
 *   (Index::listFeed()).
 *
 * Feeds are anonymous by construction: a signed-in reader gets the same
 * public-only feed.
 */
final class FeedController
{
    /** Never a feed, whatever conf says */
    private const REPORT_ROOT = 'reports';

    /** @var list<string> */
    private readonly array $namespaces;

    /**
     * @param list<string> $configured conf['feeds']['namespaces']
     */
    public function __construct(
        private readonly IndexInterface $index,
        array $configured,
        private readonly string $baseUrl,
        private readonly string $siteTitle,
    ) {
        $allowed = [];
        foreach ($configured as $ns) {
            $ns = trim((string) $ns, " \t:");
            if ($ns !== '' && $ns !== self::REPORT_ROOT && !str_starts_with($ns, self::REPORT_ROOT . ':')) {
                $allowed[] = $ns;
            }
        }
        $this->namespaces = array_values(array_unique($allowed));
    }

    public function all(Request $request): Response
    {
        if ($this->namespaces === []) {
            throw new PageNotFoundException();
        }

        return $this->atom($request, $this->namespaces, '/feed.atom', $this->siteTitle);
    }

    public function one(Request $request, string $ns): Response
    {
        $ns = trim($ns, ':');
        if (!\in_array($ns, $this->namespaces, true)) {
            throw new PageNotFoundException();
        }

        return $this->atom($request, [$ns], '/feed/' . $ns . '.atom', $this->siteTitle . ' — ' . $ns);
    }

    /**
     * @param list<string> $namespaces
     */
    private function atom(Request $request, array $namespaces, string $self, string $title): Response
    {
        $base = $this->baseUrl !== '' ? $this->baseUrl : $request->basePath;
        $rows = $this->index->listFeed($namespaces);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $updated = $rows !== [] ? self::atomDate((string) $rows[0]['updated']) : (new DateTimeImmutable('now'))->format(DATE_ATOM);

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n"
            . '<title>' . $e($title) . '</title>' . "\n"
            . '<id>' . $e($base . $self) . '</id>' . "\n"
            . '<link rel="self" href="' . $e($base . $self) . '"/>' . "\n"
            . '<link href="' . $e($base . '/') . '"/>' . "\n"
            . '<updated>' . $updated . '</updated>' . "\n";
        foreach ($rows as $row) {
            $xml .= '<entry>' . "\n"
                . '<title>' . $e((string) ($row['title'] ?: $row['path'])) . '</title>' . "\n"
                . '<link href="' . $e($base . '/' . $row['path']) . '"/>' . "\n"
                . '<id>urn:reporion:' . $e((string) $row['pid']) . '</id>' . "\n"
                . '<updated>' . self::atomDate((string) $row['updated']) . '</updated>' . "\n"
                . '<author><name>' . $e((string) $row['updated_by']) . '</name></author>' . "\n"
                . (($row['summary'] ?? '') !== '' ? '<summary>' . $e((string) $row['summary']) . '</summary>' . "\n" : '')
                . '</entry>' . "\n";
        }
        $xml .= '</feed>' . "\n";

        return new Response(200, $xml, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }

    private static function atomDate(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (Exception) {
            return (new DateTimeImmutable('now'))->format(DATE_ATOM);
        }
    }
}
