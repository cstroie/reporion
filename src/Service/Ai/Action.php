<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * One assistant action (roadmap phase 15): a page `ai:profiles:{profile}:{id}`
 * whose frontmatter says how it shows in the rail and what its answer does,
 * and whose body is the user prompt; `system` is the profile's system
 * prompt with the action's own appendage (`…:system:{id}`), if any.
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
        public readonly int $order,
        public readonly string $prompt,
        public readonly string $system,
    ) {
    }

    /** @return array{id: string, label: string, tooltip: string, icon: string, result: string} what the editor needs */
    public function forEditor(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'tooltip' => $this->tooltip, 'icon' => $this->icon, 'result' => $this->result];
    }
}
