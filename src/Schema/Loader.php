<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Schema;

use RuntimeException;

/**
 * Loads `conf/schema/*.json` (docs/architecture-storage-index.md §7) and
 * resolves each modality's field map to the shape `Validator` needs. Only
 * `Schema\Validator::missingForSign()` calls this today — the "drives the
 * editor form / index columns / search facets" parts of the schema's
 * stated purpose are not built yet and this class does not anticipate
 * them.
 *
 * `extends` is resolved one level only: every shipped `conf/schema/*.json`
 * extends `base`, nothing extends anything else, and nothing chains two
 * levels deep. A deeper chain is a configuration error worth failing loud
 * on (RuntimeException), not a general resolver worth writing for a case
 * no file uses.
 */
final class Loader
{
    public function __construct(
        private readonly string $schemaDir,
    ) {
    }

    /**
     * Merged field definitions for `base` plus every modality in
     * $modalities that has its own schema file — a combined study (D29:
     * `modality` is a list) must satisfy every listed modality's
     * sign-requirements, not just one. A modality with no schema file
     * (`PET`, `other`, or any future addition) silently contributes
     * nothing beyond `base` — not an error, since a missing file must
     * never make a report un-signable for a reason nobody intended.
     *
     * @param list<string> $modalities
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldsFor(array $modalities): array
    {
        $fields = $this->resolve('base');

        foreach ($modalities as $modality) {
            $name = strtolower($modality);
            if ($name === 'base' || !is_file($this->pathFor($name))) {
                continue;
            }
            $fields = array_replace($fields, $this->resolve($name));
        }

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolve(string $name): array
    {
        $decoded = $this->readJson($name);
        $fields = $decoded['fields'];

        $extends = $decoded['extends'] ?? null;
        if ($extends === null) {
            return $fields;
        }
        if (!\is_string($extends) || $extends === $name) {
            throw new RuntimeException("Invalid \"extends\" in schema file: {$name}");
        }

        $parent = $this->readJson($extends);
        if (($parent['extends'] ?? null) !== null) {
            // The one level this loader supports is already used by
            // $name -> $extends; $extends must not itself extend anything.
            throw new RuntimeException("Schema \"extends\" chains deeper than one level: {$name} -> {$extends}");
        }

        return array_replace($parent['fields'], $fields);
    }

    /**
     * @return array{fields: array<string, array<string, mixed>>, extends?: string}
     */
    private function readJson(string $name): array
    {
        $path = $this->pathFor($name);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Missing schema file: {$name}");
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !\is_array($decoded['fields'] ?? null)) {
            throw new RuntimeException("Malformed schema file: {$name}");
        }

        return $decoded;
    }

    private function pathFor(string $name): string
    {
        return $this->schemaDir . '/' . $name . '.json';
    }
}
