<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * The provider for a server, by the protocol it speaks (`AiConfig::$api`,
 * 2026-11-19): Anthropic's Messages API, Ollama's native API, or any
 * OpenAI-compatible server. The one place a provider is chosen.
 */
final class Providers
{
    public static function for(AiConfig $config, ?EgressGuard $egress = null): ProviderInterface
    {
        $egress ??= new EgressGuard();

        return match ($config->api) {
            'anthropic' => new AnthropicProvider($config, $egress),
            default => new OpenAiCompatibleProvider($config, $egress),
        };
    }

    /** The server's embedding side, or null when its protocol has none (Anthropic) */
    public static function embedder(AiConfig $config, ?EgressGuard $egress = null): ?EmbeddingProvider
    {
        $provider = self::for($config, $egress);

        return $provider instanceof EmbeddingProvider ? $provider : null;
    }
}
