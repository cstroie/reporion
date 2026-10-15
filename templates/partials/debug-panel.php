<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The collapsed Debug panel in a page's footer: blocks of preformatted text
 * a controller or plugin collected for an owner (Service\DebugPanels decides
 * who sees it; this partial is only included when there is something).
 *
 * Variables in scope: list<array{title: string, text: string}> $debug.
 */

declare(strict_types=1);

/** @var list<array{title: string, text: string}> $debug */

$de = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
?>
<details class="wk-debug">
<summary><i class="ph ph-bug" aria-hidden="true"></i><?= $de(t('newr.debug')) ?></summary>
<?php foreach ($debug as $block): ?>
<h3 class="wk-eyebrow"><?= $de($block['title'] ?? '') ?></h3>
<pre class="wk-mono"><?= $de($block['text'] ?? '') ?></pre>
<?php endforeach; ?>
</details>
