<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\AiException;

/**
 * The one HTTP path of every AI provider (OpenAI-compatible, Anthropic,
 * Ollama): the server's address checked against the egress rule before every
 * request, its time limit, one more try after a 429 (or Anthropic's 529,
 * overloaded), and a refusal turned into an AiException with the server's own
 * explanation. The answer is an open stream the provider reads.
 */
final class HttpClient
{
    /** Tries for a request the server refused with 429/529, and the longest wait between them */
    private const ATTEMPTS = 2;
    private const MAX_WAIT_S = 5;

    /** @param list<string> $headers the provider's own request headers */
    public function __construct(
        private readonly AiConfig $config,
        private readonly EgressGuard $egress,
        private readonly array $headers,
        /** The address paths are added to; '' for the server's endpoint (Ollama's is its root) */
        private readonly string $base = '',
    ) {
    }

    /**
     * @return resource
     *
     * @throws AiException
     */
    public function open(string $method, string $path, ?string $body, ?int $timeout = null, bool $fromRoot = false)
    {
        $timeout ??= $this->config->timeout;
        // Only an address is needed: listing a server's models comes before
        // choosing one (Admin → AI, phase 33c); whether the assistant is on
        // is its callers' to say
        if ($this->config->endpoint === '') {
            throw new AiException('not_configured', 'The AI assistant is not configured');
        }
        // $fromRoot: the server's root, without the endpoint's `/v1` (some speech servers list voices there)
        $base = $this->base !== '' ? $this->base : $this->config->endpoint;
        $url = ($fromRoot ? preg_replace('#/v\d+/?$#', '', $base) : $base) . $path;
        $this->egress->assertAllowed($url, $this->config->externalAck);

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $this->headers),
                'content' => $body ?? '',
                'timeout' => (float) $timeout,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
        ]);
        // A 429 is often a shared free quota for a few seconds, a 529 a busy moment: one more try
        for ($attempt = 1; ; ++$attempt) {
            $handle = @fopen($url, 'r', false, $context);
            if ($handle === false) {
                throw new AiException('unreachable', 'The AI server cannot be reached');
            }
            stream_set_timeout($handle, $timeout);
            $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
            $status = self::status($headers);
            if ($status >= 200 && $status < 300) {
                return $handle;
            }
            $error = json_decode((string) stream_get_contents($handle, 4096), true);
            fclose($handle);
            if (($status === 429 || $status === 529) && $attempt < self::ATTEMPTS) {
                usleep(self::retryAfter($headers) * 1000);
                continue;
            }
            $message = self::errorMessage($error);
            $reason = match (true) {
                $status === 401 || $status === 403 => 'unauthorized',
                $status === 429 => 'rate_limited',
                default => 'provider_error',
            };
            throw new AiException($reason, 'The AI server answered ' . $status, $status, 'HTTP ' . $status . ($message !== '' ? ': ' . $message : ''));
        }
    }

    /** Whether the stream stopped on the time limit rather than at its end */
    public static function timedOut($handle): bool
    {
        return (stream_get_meta_data($handle)['timed_out'] ?? false) === true;
    }

    /**
     * The server's own explanation from an error body. OpenAI's and
     * Anthropic's `{error: {message}}`, or a bare `{error: "…"}`: on one
     * line, capped.
     */
    public static function errorMessage(mixed $error): string
    {
        $message = \is_array($error) ? ($error['error']['message'] ?? $error['error'] ?? $error['message'] ?? '') : '';
        if (!\is_string($message)) {
            return '';
        }
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300) . '…' : $message;
    }

    /**
     * How long to wait before the retry, in milliseconds: the server's
     * Retry-After when it is short, else two seconds.
     *
     * @param mixed $headers the stream's response headers
     */
    private static function retryAfter(mixed $headers): int
    {
        foreach (\is_array($headers) ? $headers : [] as $header) {
            if (\is_string($header) && preg_match('/^Retry-After:\s*(\d+)\s*$/i', $header, $m) === 1) {
                return min((int) $m[1], self::MAX_WAIT_S) * 1000;
            }
        }

        return 2000;
    }

    /** @param mixed $headers the stream's response headers */
    private static function status(mixed $headers): int
    {
        $status = 0;
        foreach (\is_array($headers) ? $headers : [] as $header) {
            // The last status line wins (after a redirect or a 100 Continue)
            if (\is_string($header) && preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }
}
