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
 * No path builder matching the mockup's `reports:{modality}:{site}:{yymmdd}-{name}`
 * segmented input — one plain text field for the whole colon path. Building
 * a segmented, schema-aware builder is real scope on its own; a plain field
 * is what unblocks page creation from the browser today.
 */
final class NewPageController
{
    private const SCAFFOLD = "---\ntitle: \nvisibility: private\n---\n\n";

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
        $path = $ns === '' ? '' : $ns . ':';

        return $this->render($request, error: null, path: $path, document: self::SCAFFOLD);
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
        $path = \is_string($fields['path'] ?? null) ? trim($fields['path']) : '';
        $document = \is_string($fields['document'] ?? null) ? $fields['document'] : self::SCAFFOLD;

        if ($path === '') {
            return $this->render($request, error: t('new.err_path_required'), path: $path, document: $document);
        }
        if (!$principal->canWrite($path)) {
            return Response::notFound();
        }

        try {
            [$frontmatter, $body] = DocumentFormat::parse($document);
        } catch (RuntimeException | ParseException $e) {
            return $this->render($request, error: t('editor.err_parse', [$e->getMessage()]), path: $path, document: $document);
        }

        try {
            $record = $this->storage->create($path, $frontmatter, $body, $principal->username);
        } catch (InvalidArgumentException) {
            return $this->render($request, error: t('new.err_invalid_path'), path: $path, document: $document);
        }

        // Redirect to the path Storage actually allocated, never the
        // submitted one: create() appends -2/-3 on a collision
        // (docs/FORMATS.md §1) and returns the real path — redirecting to
        // $path here would silently 404 the moment a collision happened.
        return Response::redirect($request->basePath . '/' . $record->path . '/edit');
    }

    private function render(Request $request, ?string $error, string $path, string $document): Response
    {
        return Response::html(View::render(
            \dirname(__DIR__, 2) . '/templates/new.php',
            [
                'error' => $error,
                'path' => $path,
                'document' => $document,
                'basePath' => $request->basePath,
            ]
        ));
    }
}
