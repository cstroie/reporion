<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The helpers every settings panel (partials/settings/*.php) uses. Variables in scope:
 * array<string, mixed> $values; array<string, bool> $fromFile; string $saved; ?string $settingsError, $errorSection; string $basePath
 * (Controller\SettingsPanels::vars()).
 */

declare(strict_types=1);

/** @var array<string, mixed> $values */
/** @var array<string, bool> $fromFile */
/** @var bool $hasFile */
/** @var string $gateSuggestion */
/** @var array{last_attempt: int, last_success: int, last_exit: int, snapshot: string, files: int, bytes: int} $backupState */
/** @var string $saved */
/** @var ?string $settingsError */
/** @var ?string $errorSection */
/** @var string $basePath */
/** @var string $mcpUrl */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$source = static fn (string $key): string => $fromFile[$key] ?? false ? '' : ' <span class="wk-mono wk-dim wk-text-xs">' . htmlspecialchars(t('admin.settings.from_local'), ENT_QUOTES) . '</span>';
$notice = static function (string $section) use ($saved, $settingsError, $errorSection, $e): string {
    if ($errorSection === $section && $settingsError !== null) {
        return '<div class="wk-notice wk-mb-3 wk-notice-error" role="alert"><i class="ph ph-warning-octagon"></i><div>' . $e($settingsError) . '</div></div>';
    }

    return $saved === $section ? '<div class="wk-notice wk-mb-3 wk-notice-ok" role="status"><i class="ph ph-check"></i><div>' . $e(t('admin.settings.saved')) . '</div></div>' : '';
};
$text = static fn (string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES);
$checked = static fn (string $key): string => ($values[$key] ?? false) ? ' checked' : '';
$field = static fn (string $key): string => str_replace('.', '_', $key);
$modalityMap = \is_array($values['reports.modality_namespaces'] ?? null) && $values['reports.modality_namespaces'] !== []
    ? $values['reports.modality_namespaces']
    : \Reporion\Service\NewReport::DEFAULT_MODALITY_NAMESPACES;
$icon = (string) ($values['site.icon'] ?? '');
?>
