<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     * @param ?\Closure(): void $stream writes the body itself, a piece at a
     *        time (the assistant's Server-Sent Events, phase 15c); $body is
     *        then unused
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly ?\Closure $stream = null,
    ) {
    }

    /**
     * A response written as it is produced: Server-Sent Events, not buffered
     * by PHP (lighttpd needs `server.stream-response-body = 2` too —
     * docs/deploy-lighttpd.md).
     *
     * @param \Closure(): void $stream
     */
    public static function eventStream(\Closure $stream): self
    {
        return new self(200, '', [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ], $stream);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function notFound(string $body = 'Not found'): self
    {
        return self::html($body, 404);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => $value], $this->stream);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if ($this->stream !== null) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ($this->stream)();

            return;
        }
        echo $this->body;
    }
}
