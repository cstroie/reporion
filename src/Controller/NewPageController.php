<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Storage\StorageInterface;
use Reporion\Support\DocumentFormat;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * GET/POST /new — the create half of the write UI (`Controller\EditorController`
 * is the edit half). Same raw-document textarea as the editor, for the same
 * reason: no per-field form to silently drop whatever it doesn't show.
 * Prefilled with a minimal scaffold, not empty — `Support\DocumentFormat::parse()`
 * (mirroring `Storage\FlatFile`'s own regex) requires at least one line
 * inside the frontmatter fences, so a genuinely empty block would fail to
 * parse the moment someone submitted it unchanged.
 *
 * Two ways to name the page. Under `reports:` (and by default) it is the
 * mockup's segmented `reports:{modality}:{site}:{yymmdd}-{name}` builder —
 * four plain inputs the server assembles, so creation works without
 * JavaScript. Anywhere else (`?ns=` outside `reports:`, or `?mode=path`) it
 * is one text field for the whole colon path, because the builder's fixed
 * shape would silently re-root the page under `reports:`.
 */
final class NewPageController
{
    private const SCAFFOLD = "---\ntitle: \nvisibility: private\n---\n\n";

    /** The builder's inputs, in path order. */
    private const SEGMENTS = ['modality', 'site', 'date', 'name'];

    public function __construct(
        private readonly StorageInterface $storage,
    ) {
    }

    public function form(Request $request, ?User $principal): Response
    {
        if ($principal === null || !$principal->hasAnyWriteAccess()) {
            return Response::notFound();
        }

        $ns = \is_string($request->query['ns'] ?? null) ? trim($request->query['ns'], ': ') : '';
        $segments = ($request->query['mode'] ?? null) === 'path' ? null : self::segmentsFromNamespace($ns);
        $path = $ns === '' ? '' : $ns . ':';

        return $this->render($request, error: null, path: $path, document: self::SCAFFOLD, segments: $segments);
    }

    public function create(Request $request, ?User $principal): Response
    {
        // Same coarse-then-specific ordering as PagesApiController::create()
        // and for the same reason: the target namespace lives in a form
        // field, not a route parameter, so there is nothing to run
        // canWrite() against until $path is known to be non-empty.
        if ($principal === null || !$principal->hasAnyWriteAccess()) {
            return Response::notFound();
        }

        parse_str($request->body, $fields);
        $document = \is_string($fields['document'] ?? null) ? $fields['document'] : self::SCAFFOLD;
        $segments = null;
        if (($fields['builder'] ?? null) === '1') {
            $segments = [];
            foreach (self::SEGMENTS as $name) {
                $segments[$name] = \is_string($fields[$name] ?? null) ? trim($fields[$name]) : '';
            }
            if (\in_array('', [$segments['modality'], $segments['site'], $segments['date'], $segments['name']], true)) {
                return $this->render($request, error: t('new.err_segments'), path: '', document: $document, segments: $segments);
            }
            $path = 'reports:' . $segments['modality'] . ':' . $segments['site'] . ':' . $segments['date'] . '-' . $segments['name'];
        } else {
            $path = \is_string($fields['path'] ?? null) ? trim($fields['path']) : '';
        }

        if ($path === '') {
            return $this->render($request, error: t('new.err_path_required'), path: $path, document: $document, segments: $segments);
        }
        if (!$principal->canWrite($path)) {
            return Response::notFound();
        }

        try {
            [$frontmatter, $body] = DocumentFormat::parse($document);
        } catch (RuntimeException | ParseException $e) {
            return $this->render($request, error: t('editor.err_parse', [$e->getMessage()]), path: $path, document: $document, segments: $segments);
        }

        try {
            $record = $this->storage->create($path, $frontmatter, $body, $principal->username);
        } catch (InvalidArgumentException) {
            return $this->render($request, error: t('new.err_invalid_path'), path: $path, document: $document, segments: $segments);
        }

        // Redirect to the path Storage actually allocated, never the
        // submitted one: create() appends -2/-3 on a collision
        // (docs/FORMATS.md §1) and returns the real path — redirecting to
        // $path here would silently 404 the moment a collision happened.
        return Response::redirect($request->basePath . '/' . $record->path . '/edit');
    }

    /**
     * @param array<string, string>|null $segments the builder's inputs, or null for the plain path field
     */
    private function render(Request $request, ?string $error, string $path, string $document, ?array $segments): Response
    {
        return Response::html(View::render(
            \dirname(__DIR__, 2) . '/templates/new.php',
            [
                'error' => $error,
                'path' => $path,
                'document' => $document,
                'segments' => $segments,
                'basePath' => $request->basePath,
            ]
        ));
    }

    /**
     * Builder prefill for a `?ns=` under `reports:` (at most modality and
     * site deep); null — the plain path field — for any other namespace.
     *
     * @return array<string, string>|null
     */
    private static function segmentsFromNamespace(string $ns): ?array
    {
        $parts = $ns === '' ? [] : explode(':', $ns);
        if ($parts !== [] && array_shift($parts) !== 'reports') {
            return null;
        }
        if (\count($parts) > 2) {
            return null;
        }

        return ['modality' => $parts[0] ?? '', 'site' => $parts[1] ?? '', 'date' => '', 'name' => ''];
    }
}
