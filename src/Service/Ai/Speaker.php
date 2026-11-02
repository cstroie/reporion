<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\AiException;
use Reporion\Support\Wav;

/**
 * The speech model of the audio overview (docs/library.md, phase 8): a server from Admin → AI
 * (`ai.speech_server`, 1–6), the model on it (`ai.speech_model`) and two voices
 * (`ai.speech_voices`: the first host, the second), one for the whole instance, as the embedding
 * model is (Embedder). Its `POST /audio/speech` under that server's own address, key, time limit and
 * egress rule; never failed over to another server (a voice is the server's own). Asks for WAV,
 * so the turns can be joined without re-encoding (Support\Wav). A server that refuses `wav` as a
 * `response_format` (OpenAI's own, and others, offer only mp3 and pcm) is asked for raw PCM
 * instead (OpenAI's: 24 kHz, 16-bit, mono, signed little-endian), wrapped in a WAV header here,
 * and is asked for PCM from then on.
 */
final class Speaker
{
    /** Raw PCM as OpenAI-compatible servers send it: 24 kHz, 16-bit, mono */
    private const PCM_RATE = 24000;

    private bool $pcm = false;

    /**
     * @param list<string> $voices two: the first host's and the second's ('' the server's default)
     */
    public function __construct(
        private readonly OpenAiCompatibleProvider $provider,
        public readonly string $model,
        public readonly array $voices,
        public readonly string $serverName,
    ) {
    }

    /**
     * The speaker Admin → AI sets up; null when the assistant is off, or no server or model is
     * chosen, or that server has no address
     *
     * @param array<string, mixed> $config the effective config
     */
    public static function fromConfig(array $config): ?self
    {
        $ai = \is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $slot = is_numeric($ai['speech_server'] ?? null) ? (int) $ai['speech_server'] : 0;
        $model = \is_string($ai['speech_model'] ?? null) ? trim($ai['speech_model']) : '';
        if (($ai['enabled'] ?? false) !== true || $slot < 1 || $slot > AiConfig::SLOTS || $model === '') {
            return null;
        }
        $server = AiConfig::fromConfig($config, $slot);
        if ($server->endpoint === '') {
            return null;
        }
        $voices = array_values(array_filter(array_map('trim', explode(',', \is_string($ai['speech_voices'] ?? null) ? $ai['speech_voices'] : '')), static fn (string $v): bool => $v !== ''));
        $voices = [$voices[0] ?? '', $voices[1] ?? ($voices[0] ?? '')];

        return new self(new OpenAiCompatibleProvider($server, new EgressGuard()), $model, $voices, $server->serverName);
    }

    /**
     * $text, already safe to send (Context::speechText()), spoken by host $speaker (0 or 1), as WAV
     *
     * @throws AiException
     */
    public function speak(string $text, int $speaker): string
    {
        $voice = $this->voices[$speaker === 1 ? 1 : 0];
        if (!$this->pcm) {
            try {
                return $this->provider->speech($text, $this->model, $voice, 'wav');
            } catch (AiException $e) {
                // Only a refusal of the request itself is answered by asking for another format; if
                // that fails too, the first refusal is the one told
                if (!\in_array($e->status, [400, 422], true)) {
                    throw $e;
                }
                try {
                    $wav = Wav::fromPcm($this->provider->speech($text, $this->model, $voice, 'pcm'), self::PCM_RATE);
                } catch (AiException) {
                    throw $e;
                }
                $this->pcm = true;

                return $wav;
            }
        }

        return Wav::fromPcm($this->provider->speech($text, $this->model, $voice, 'pcm'), self::PCM_RATE);
    }
}
