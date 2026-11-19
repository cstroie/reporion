<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\AiException;

/**
 * A server that turns texts into vectors (phase 34e): the OpenAI-compatible
 * `/embeddings` and Ollama's `/api/embed`. Anthropic has none.
 */
interface EmbeddingProvider
{
    /**
     * One vector per text, in the order given
     *
     * @param list<string> $texts
     *
     * @return list<list<float>>
     *
     * @throws AiException
     */
    public function embeddings(array $texts, string $model): array;
}
