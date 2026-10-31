<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Closure;
use Reporion\Exception\AiException;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\Ai\Speaker;
use Reporion\Service\Backup\ProcessRun;
use Reporion\Support\AudioScript;
use Reporion\Support\Wav;

/**
 * The audio overview (docs/library.md, phase 8): Studio's two-voice script (`studio-audio`) read
 * aloud by the speech model of Admin → AI (Speaker), a turn a request, and joined into one file.
 * Every line is made safe first (Context::speechText(): de-identified, the final guard), all of
 * them before the first is sent, so a line that leaks sends nothing at all. The turns come back
 * as WAV and are joined as WAV (Support\Wav), then encoded to MP3 by ffmpeg when it is there (an
 * argument list, a private temp dir, a timeout); without it the WAV is kept (about 3 MB a minute).
 */
final class AudioOverview
{
    /** Seconds ffmpeg may take to encode */
    private const ENCODE_SECONDS = 180;

    /**
     * @param ?Closure(list<string>, int, array<string, string>): array{0: int, 1: string} $runner
     */
    public function __construct(
        private readonly Speaker $speaker,
        private readonly Assistant $assistant,
        private readonly string $tmpRoot,
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly ?Closure $runner = null,
    ) {
    }

    /**
     * @param Closure(int, int): void $progress called with (turn, turns) before each request
     *
     * @return array{bytes: string, ext: string, seconds: int, turns: int, cut: bool}
     *
     * @throws AiException identifier_leak (nothing sent), the speech server's failure, or provider_error for audio that does not join
     */
    public function make(string $script, Closure $progress): array
    {
        ['turns' => $turns, 'cut' => $cut] = AudioScript::turns($script);
        if ($turns === []) {
            throw new AiException('provider_error', 'The script has no turns to read');
        }
        // Every line made safe before any is sent
        $lines = [];
        foreach ($turns as $turn) {
            $text = $this->assistant->speechText($turn['text']);
            if ($text !== '') {
                $lines[] = ['speaker' => $turn['speaker'], 'text' => $text];
            }
        }
        $wavs = [];
        foreach ($lines as $i => $line) {
            $progress($i + 1, \count($lines));
            @set_time_limit(300);
            $wavs[] = $this->speaker->speak($line['text'], $line['speaker']);
        }
        try {
            $wav = Wav::join($wavs);
            $seconds = (int) round(Wav::seconds($wav));
        } catch (\InvalidArgumentException $e) {
            throw new AiException('provider_error', 'The speech server did not answer with PCM WAV audio of one format', null, $e->getMessage());
        }
        $mp3 = $this->mp3($wav);

        return ['bytes' => $mp3 ?? $wav, 'ext' => $mp3 !== null ? 'mp3' : 'wav', 'seconds' => $seconds, 'turns' => \count($lines), 'cut' => $cut];
    }

    /** $wav as MP3 by ffmpeg; null when it is missing or fails */
    private function mp3(string $wav): ?string
    {
        $dir = $this->tmpRoot . '/audio-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true)) {
            return null;
        }
        try {
            file_put_contents($dir . '/in.wav', $wav);
            $argv = [$this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-i', $dir . '/in.wav', '-codec:a', 'libmp3lame', '-q:a', '4', $dir . '/out.mp3'];
            [$exit] = $this->runner !== null ? ($this->runner)($argv, self::ENCODE_SECONDS, []) : ProcessRun::run($argv, self::ENCODE_SECONDS);
            $mp3 = $exit === 0 ? @file_get_contents($dir . '/out.mp3') : false;

            return \is_string($mp3) && $mp3 !== '' ? $mp3 : null;
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}
