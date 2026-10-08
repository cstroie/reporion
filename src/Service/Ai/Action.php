<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * One assistant action (roadmap phase 15; row-sourced since 2026-09-28): a
 * row in the profile's own page's first table (`Support\ProfileTable`) says
 * how it shows in the rail, what its answer does, and its order; the id's
 * own page `ai:profiles:{profile}:{id}` supplies the prompt as its body.
 * `system` is the profile's system prompt with the action's own appendage
 * (`…:system:{id}`), if any.
 */
final class Action
{
    public const RESULTS = ['show', 'append', 'replace', 'insert'];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $tooltip,
        public readonly string $icon,
        public readonly string $result,
        public readonly string $prompt,
        public readonly string $system,
        /** The model alias (AiConfig::TIERS) the action runs on */
        public readonly string $model = AiConfig::DEFAULT_TIER,
        /** The server (by its Admin → AI name) it runs on; null is the one in use */
        public readonly ?string $server = null,
        /** The answer's token cap for this action; 0 leaves it to the server's setting */
        public readonly int $maxTokens = 0,
        /** Whether a `---` row of the profile table comes before it: the rail draws a line */
        public readonly bool $sectionStart = false,
    ) {
    }

    /** @return array{id: string, label: string, tooltip: string, icon: string, result: string, section: bool} what the editor needs */
    public function forEditor(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'tooltip' => $this->tooltip, 'icon' => $this->icon, 'result' => $this->result, 'section' => $this->sectionStart];
    }
}
