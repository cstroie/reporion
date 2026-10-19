<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin;

use InvalidArgumentException;
use Reporion\Http\Request;
use Reporion\Http\Response;

/**
 * The hooks a plugin can listen on, and the routes it can add. Only hooks a
 * real plugin uses exist (CLAUDE.md working agreement): each with its
 * contract written down here and in docs/architecture-api.md §5:
 *
 *   report.prefill (string $source, string $ref, User $principal): ?array
 *     The guided new-report form's fields (Service\NewReport::FIELDS) for
 *     `/new?prefill={source}&ref={ref}`, or null when $source is not this
 *     plugin's (or $ref is unknown: a 404). The form still validates them;
 *     nothing is written. A listener whose server does not answer throws
 *     Exception\PrefillUnavailableException with a user-facing reason: the
 *     plain form shows it (503) instead of a 404.
 *
 *   maintenance.tasks (): list<Service\Maintenance\MaintenanceTask>
 *     Maintenance tasks the plugin adds to bin/reporion (Cli\PluginTaskCommand): the
 *     answers of every listener are merged (Hooks::all()). Not offered in Admin →
 *     Maintenance: its labels are core strings.
 *
 *   ask.sources (string $question, User $principal): list<array>
 *     Passages for Ask the archive (D53) from outside the wiki: the answers of every
 *     listener are merged (Hooks::all()). Each is {text: string, date: ?string, label:
 *     string, heading?: string, names: list<string>, cnp: string, extra?: list<string>}
 *     (`names`, `cnp` and `extra`, other identifiers the source states, are the patient the
 *     text is about, for the redactor only: they never reach the prompt, the screen,
 *     the audit or a log) or {notice: string}, a line the screen shows when a source
 *     could not be read. A listener answers nothing for a caller it does not serve.
 *     Core numbers the passages after the wiki's and de-identifies them.
 *
 * Routes live under the plugin's own prefix, `/x/{plugin-id}/…`, and are
 * mounted before the page catch-all.
 */
final class Hooks
{
    public const EVENTS = ['report.prefill', 'maintenance.tasks', 'ask.sources'];

    /** @var array<string, list<array{priority: int, seq: int, listener: callable}>> */
    private array $listeners = [];

    /** @var list<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    private int $seq = 0;

    /** The plugin registering right now: its routes go under /x/{id} */
    private string $pluginId = '';

    public function forPlugin(string $id): void
    {
        $this->pluginId = $id;
    }

    /**
     * Lower priorities run first; equal ones in registration order.
     */
    public function on(string $event, callable $listener, int $priority = 50): void
    {
        if (!\in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('Unknown hook');
        }
        $this->listeners[$event][] = ['priority' => $priority, 'seq' => $this->seq++, 'listener' => $listener];
    }

    /** The first listener's answer that is not null, or null. */
    public function first(string $event, mixed ...$args): mixed
    {
        $listeners = $this->listeners[$event] ?? [];
        usort($listeners, static fn (array $a, array $b): int => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);
        foreach ($listeners as $entry) {
            $answer = ($entry['listener'])(...$args);
            if ($answer !== null) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * Every listener's answer, merged: each returns a list (or null).
     *
     * @return list<mixed>
     */
    public function all(string $event, mixed ...$args): array
    {
        $listeners = $this->listeners[$event] ?? [];
        usort($listeners, static fn (array $a, array $b): int => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);
        $out = [];
        foreach ($listeners as $entry) {
            $answer = ($entry['listener'])(...$args);
            if (\is_array($answer)) {
                array_push($out, ...array_values($answer));
            }
        }

        return $out;
    }

    /**
     * A route under /x/{plugin-id}. $pattern is relative ("/worklist",
     * "/priors/{pid}"); the handler gets the request, the route
     * parameters and the signed-in user, or null.
     *
     * @param 'GET'|'POST' $method
     * @param callable(Request, array<string, string>, ?\Reporion\Auth\User): Response $handler
     */
    public function route(string $method, string $pattern, callable $handler): void
    {
        if ($this->pluginId === '' || !str_starts_with($pattern, '/')) {
            throw new InvalidArgumentException('A plugin route needs its plugin and a leading /');
        }
        $this->routes[] = ['method' => $method, 'pattern' => '/x/' . $this->pluginId . $pattern, 'handler' => $handler];
    }

    /** @return list<array{method: string, pattern: string, handler: callable}> */
    public function routes(): array
    {
        return $this->routes;
    }
}
