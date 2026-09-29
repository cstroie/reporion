<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Schema\Loader;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;
use Reporion\Support\Templates;

/**
 * The editor's Details panel (roadmap phase 14): a curated, schema-driven
 * view of a page's frontmatter — the first UI-facing use of `Schema\Loader`,
 * which until now only fed `Schema\Validator::missingForSign()`. Every page
 * gets a small fixed set of fields (`BASE`); a report
 * (`Support\ReportPath::isReport()`) gets those plus the report ones
 * (`REPORT`, `PATIENT`) and `indication` when the page's modalities'
 * schema declares it (every shipped modality schema does; `base.json`
 * does not, so its presence is checked, not hardcoded).
 *
 * A field this class does not curate is never silently dropped: every
 * other frontmatter key present on the page is returned as "extra", read
 * only — the medic edits it in raw mode, the one place that already
 * parses arbitrary YAML safely (decided with the owner 2026-09-27, over
 * an inline second YAML box). Modality-specific fields beyond `indication`
 * (`field_strength`, `contrast`, `dlp`, `birads`, …) are extra too for now
 * — a deliberately narrow first version (TODO.md idea 11).
 *
 * `exams`, `status`, `pid`, `imported_from`, `import_batch`, `review` and `order_ref`
 * are never curated and never listed as extra either — each has its own
 * place already (the exam tabs, sign/archive/revert, import bookkeeping)
 * and showing them here would just be noise, or, for `pid`, a field that
 * must never be hand-edited. `accession` and `visibility` are shown
 * read-only, next to the curated fields, each with a link to the one
 * place it is actually changed: `accession` in raw mode (D20 says it
 * stays editable there), `visibility` at `/{path}/visibility` — the
 * existing D16 acknowledgement screen (`Controller\VisibilityController`,
 * `Service\Publishing`), reused rather than a second copy of that flow
 * built into this form.
 */
final class FrontmatterFields
{
    /** Curated on every page: key => widget kind */
    private const BASE = ['title' => 'text', 'tags' => 'list', 'summary' => 'textarea'];

    /** Curated in addition, only on a report — `template` means nothing on
     *  a namespace description or any other non-report page (TODO 13) */
    private const REPORT = ['template' => 'select', 'modality' => 'checkboxes', 'region' => 'checkboxes', 'site' => 'select', 'device' => 'select', 'study_date' => 'date', 'referrer' => 'text', 'protocol' => 'text'];

    /** Curated in addition, only on a non-report page — the mirror of
     *  REPORT above: `priority` (TODO 13, the sub-namespace card tint,
     *  Controller\NamespaceController) means nothing on a report */
    private const NAMESPACE = ['priority' => 'select'];

    /** The one `object` field the schema has, and its own widgets */
    private const PATIENT = ['name' => 'text', 'born' => 'text', 'sex' => 'select', 'cnp' => 'text'];

    /** Never curated, never listed as "extra" — each has its own place already */
    private const NEVER = ['exams', 'status', 'pid', 'imported_from', 'import_batch', 'review', 'order_ref'];

    /** @var array<string, list<array{path: string, title: string}>> templatesFor() cache, keyed by path + principal, for the one request this instance lives in */
    private array $templatesCache = [];

    public function __construct(
        private readonly Loader $schemas,
        private readonly IndexInterface $index,
        private readonly array $sites,
    ) {
    }

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return array{
     *     fields: list<array{key: string, label: string, widget: string, value: mixed, options: list<array{value: string, label: string}>, required: bool}>,
     *     patient: ?list<array{key: string, label: string, widget: string, value: string, options: list<array{value: string, label: string}>, required: bool}>,
     *     accession: ?string,
     *     visibility: string,
     *     extra: array<string, mixed>,
     * }
     */
    public function forPage(string $path, array $frontmatter, ?User $principal): array
    {
        $isReport = ReportPath::isReport($path);
        $widgets = $isReport ? [...self::BASE, ...self::REPORT] : [...self::BASE, ...self::NAMESPACE];
        $modalities = array_values(array_filter((array) ($frontmatter['modality'] ?? []), \is_string(...)));
        $schemaFields = $this->schemas->fieldsFor($modalities);
        if ($isReport && isset($schemaFields['indication'])) {
            $widgets['indication'] = 'textarea';
        }

        $fields = [];
        foreach ($widgets as $key => $widget) {
            $fields[] = $this->field($key, $widget, $schemaFields[$key] ?? [], $frontmatter[$key] ?? null, $path, $principal);
        }

        $patientRaw = \is_array($frontmatter['patient'] ?? null) ? $frontmatter['patient'] : [];
        $patient = null;
        if ($isReport) {
            $patient = [];
            foreach (self::PATIENT as $sub => $widget) {
                $patient[] = [
                    'key' => 'patient.' . $sub,
                    'label' => t('details.patient_' . $sub),
                    'widget' => $widget,
                    'value' => MetaText::text($patientRaw[$sub] ?? null),
                    'options' => $sub === 'sex' ? [['value' => 'F', 'label' => t('details.sex_f')], ['value' => 'M', 'label' => t('details.sex_m')]] : [],
                    'required' => $sub === 'name',
                ];
            }
        }

        $shown = [...array_keys($widgets), 'patient', 'visibility', 'accession', ...self::NEVER];
        $extra = array_diff_key($frontmatter, array_flip($shown));

        return [
            'fields' => $fields,
            'patient' => $patient,
            'accession' => $isReport ? (MetaText::text($frontmatter['accession'] ?? null) ?: null) : null,
            'visibility' => MetaText::text($frontmatter['visibility'] ?? null) ?: 'private',
            'extra' => $extra,
        ];
    }

    /**
     * @param array<string, mixed> $def Schema\Loader::fieldsFor()'s entry for this key, or [] when unknown to it
     */
    private function field(string $key, string $widget, array $def, mixed $value, string $path, ?User $principal): array
    {
        $options = match (true) {
            $key === 'site' => array_map(static fn (string $code, array $site): array => ['value' => $code, 'label' => (string) ($site['name'] ?? '') !== '' ? (string) $site['name'] : $code], array_keys($this->sites), array_values($this->sites)),
            $key === 'device' => $this->deviceOptions(),
            $key === 'template' => array_map(static fn (array $tpl): array => ['value' => $tpl['path'], 'label' => $tpl['title']], $this->templatesFor($path, $principal)),
            $key === 'priority' => [['value' => '', 'label' => t('details.priority_unset')], ['value' => 'low', 'label' => t('details.priority_low')], ['value' => 'medium', 'label' => t('details.priority_medium')], ['value' => 'high', 'label' => t('details.priority_high')]],
            \is_array($def['values'] ?? null) => array_map(static fn (string $v): array => ['value' => $v, 'label' => $v], array_map('strval', $def['values'])),
            default => [],
        };

        return [
            'key' => $key,
            'label' => t('details.' . $key),
            'widget' => $widget,
            'value' => $widget === 'checkboxes' ? array_map('strval', (array) $value) : ($widget === 'list' ? implode(', ', array_map('strval', (array) $value)) : ($widget === 'date' ? MetaText::date($value, 'Y-m-d') : MetaText::text($value))),
            'options' => $options,
            'required' => ($def['required'] ?? false) === true || (\is_array($def['required_for'] ?? null) && \in_array('sign', $def['required_for'], true)),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function deviceOptions(): array
    {
        $options = [];
        foreach ($this->sites as $code => $site) {
            $siteName = (string) ($site['name'] ?? '') !== '' ? (string) $site['name'] : (string) $code;
            foreach (\is_array($site['devices'] ?? null) ? $site['devices'] : [] as $deviceCode => $deviceName) {
                $options[] = ['value' => (string) $deviceCode, 'label' => $siteName . ' · ' . (string) $deviceName];
            }
        }

        return $options;
    }

    /**
     * The posted Details panel as changes for `Publishing::merge()`: only a
     * field named in `$shown` is ever touched — a field the form did not
     * render (a different page's shape, or JS-narrowed out) leaves that key
     * exactly as it is on disk. `$shown` carries the dotted key of every
     * field the form rendered, patient sub-fields included, whatever that
     * field's posted value: this is what tells "cleared" (shown, posted
     * empty) apart from "never rendered" (absent from $shown entirely) for
     * a checkbox or an empty multi-select, which post nothing either way.
     *
     * @param array<string, mixed> $fm     the posted `fm[...]` array
     * @param list<string>         $shown  the posted `fm_shown[]` dotted keys
     * @param array<string, mixed> $current the page's current frontmatter
     *
     * @return array<string, mixed> ready for Publishing::merge()
     */
    public function changesFrom(array $fm, array $shown, array $current, string $path): array
    {
        $isReport = ReportPath::isReport($path);
        $widgets = $isReport ? [...self::BASE, ...self::REPORT] : [...self::BASE, ...self::NAMESPACE];
        $modalities = array_values(array_filter((array) ($fm['modality'] ?? $current['modality'] ?? []), \is_string(...)));
        $schemaFields = $this->schemas->fieldsFor($modalities);
        if ($isReport && isset($schemaFields['indication'])) {
            $widgets['indication'] = 'textarea';
        }

        $changes = [];
        foreach ($widgets as $key => $widget) {
            if (!\in_array($key, $shown, true)) {
                continue;
            }
            $changes[$key] = $this->valueFrom($widget, $fm[$key] ?? null, \is_array($schemaFields[$key]['values'] ?? null));
        }

        if ($isReport) {
            $patient = \is_array($current['patient'] ?? null) ? $current['patient'] : [];
            $touched = false;
            foreach (array_keys(self::PATIENT) as $sub) {
                if (!\in_array('patient.' . $sub, $shown, true)) {
                    continue;
                }
                $touched = true;
                $raw = \is_string($fm['patient'][$sub] ?? null) ? trim($fm['patient'][$sub]) : '';
                if ($raw === '') {
                    unset($patient[$sub]);
                } else {
                    $patient[$sub] = $sub === 'born' && ctype_digit($raw) ? (int) $raw : $raw;
                }
            }
            if ($touched) {
                $changes['patient'] = $patient === [] ? null : $patient;
            }
        }

        return $changes;
    }

    private function valueFrom(string $widget, mixed $raw, bool $hasOptions): mixed
    {
        if ($widget === 'checkboxes') {
            $items = array_values(array_filter(array_map(static fn (mixed $v): string => \is_string($v) ? trim($v) : '', (array) $raw), static fn (string $s): bool => $s !== ''));

            return $items === [] ? null : $items;
        }
        if ($widget === 'list') {
            $items = array_values(array_filter(array_map('trim', explode(',', \is_string($raw) ? $raw : '')), static fn (string $s): bool => $s !== ''));

            return $items === [] ? null : $items;
        }
        $text = \is_string($raw) ? trim($raw) : '';

        return $text === '' ? null : $text;
    }

    /** Same picker EditorController's toolbar Insert Template button already builds */
    public function templatesFor(string $path, ?User $principal): array
    {
        $segments = explode(':', $path);
        $ns = ReportPath::isReport($path) && isset($segments[1]) ? Templates::NS . ':' . $segments[1] : Templates::NS;

        $cacheKey = $ns . '#' . ($principal?->username ?? '');
        if (isset($this->templatesCache[$cacheKey])) {
            return $this->templatesCache[$cacheKey];
        }

        $templates = array_map(
            static fn (array $row): array => ['path' => (string) $row['path'], 'title' => (string) ($row['template_label'] ?? null ?: $row['title'] ?: $row['path'])],
            array_values(array_filter(
                $this->index->listRecent($principal, ['ns' => $ns], 200),
                static fn (array $row): bool => !Snippets::isSnippetPath((string) $row['path'])
            ))
        );
        usort($templates, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $this->templatesCache[$cacheKey] = $templates;
    }
}
