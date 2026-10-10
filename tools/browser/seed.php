<?php

// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only: a fixture data directory for browser checks (tools/browser/start.sh).
// Synthetic patients only (invariant 10). Never point it at data/.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Reporion\Auth\FlatFileUserStore;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

$dir = $argv[1] ?? '';
$live = realpath(dirname(__DIR__, 2) . '/data');
if ($dir === '' || ($live !== false && str_starts_with((string) realpath(dirname($dir)) . '/' . basename($dir), $live))) {
    fwrite(STDERR, "usage: php tools/browser/seed.php <fixture dir outside the checkout>\n");
    exit(1);
}
$index = new Sqlite($dir . '/index.sqlite', dirname(__DIR__, 2) . '/migrations');
$storage = new FlatFile($dir, $index);
(new FlatFileUserStore($dir))->create('owner', password_hash('owner-password', PASSWORD_ARGON2ID), true, [], 'Dr. Test Owner', 'Medic primar');

$storage->create('site:home', ['title' => 'Home', 'visibility' => 'public'], "# Home\n\nFixture instance.\n", 'owner');
$storage->create('templates:mri:genunchi', ['title' => 'IRM Genunchi', 'visibility' => 'private', 'modality' => ['MR'], 'region' => ['msk']], "Meniscuri normale.\n", 'owner');
$storage->create('reports:mri:mioveni:260927-test-single', [
    'title' => 'TEST Patient Doi', 'exam_title' => 'IRM cerebral', 'visibility' => 'private', 'modality' => ['MR'], 'region' => ['neuro'],
    'site' => 'mioveni', 'study_date' => '2026-09-27', 'accession' => 'MV-MR-26-0003', 'patient' => ['name' => 'TEST Patient Doi', 'sex' => 'M', 'born' => 1975],
], "# TEST Patient Doi\n\n## IRM cerebral\n\nFără leziuni.\n\n### Concluzii\n\nNormal.\n", 'owner');
$storage->create('reports:mri:mioveni:260927-test-multi', [
    'title' => 'TEST Patient Unu', 'exam_title' => 'IRM genunchi drept + IRM genunchi stâng', 'visibility' => 'private', 'modality' => ['MR'], 'region' => ['msk'],
    'site' => 'mioveni', 'study_date' => '2026-09-27', 'template' => 'templates:mri:genunchi',
    'exams' => [['title' => 'IRM genunchi drept', 'region' => ['msk'], 'accession' => 'MV-MR-26-0001'], ['title' => 'IRM genunchi stâng', 'region' => ['msk'], 'accession' => 'MV-MR-26-0002']],
    'patient' => ['name' => 'TEST Patient Unu', 'sex' => 'F', 'born' => 1985],
], "# TEST Patient Unu\n\n**Gonalgie**\n\n## IRM genunchi drept\n\nA.\n\n### Concluzii\n\nB.\n\n## IRM genunchi stâng\n\nC.\n\n### Concluzii\n\nD.\n", 'owner');
$storage->create('templates:snippets:norm', ['title' => 'Normal', 'visibility' => 'private'], "Aspect normal.\n", 'owner');
// The assistant's prompt pages (phase 15), answered by tests/fixtures/ai/fake-openai.php
// The profile's action table (phase 15, table-sourced 2026-09-28): without it the rail has no Assistant
$storage->create('ai:profiles:reports', ['title' => 'Reports', 'visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | Write the conclusion | 🏁 | append |\n| quality | Check | Check the text | ✔️ | show |\n", 'owner');
$storage->create('ai:profiles:reports:system', ['title' => 'System', 'visibility' => 'private'], "Ești radiolog.\n", 'owner');
$storage->create('ai:profiles:reports:conclusion', ['title' => 'Conclusion', 'label' => 'Conclusion', 'icon' => '🏁', 'result' => 'append', 'order' => 10, 'visibility' => 'private'], "<raport>{text}</raport>\n", 'owner');
$storage->create('ai:profiles:reports:quality', ['title' => 'Check', 'label' => 'Check', 'icon' => '✔️', 'result' => 'show', 'order' => 20, 'visibility' => 'private'], "{text}\n", 'owner');
// The revision note of a Save with *What changed?* empty (2026-10-10), on its default lite alias
$storage->create('ai:profiles:reports:commit', ['title' => 'Commit', 'visibility' => 'private'], "Summarize the following diff as a single short commit message.\n\nDIFF:\n{diff}\n", 'owner');
echo "seeded $dir\n";
