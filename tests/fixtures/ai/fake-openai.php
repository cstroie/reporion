<?php

// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only: a tiny OpenAI-compatible server for `php -S` (tests/Ai).
// GET /v1/models; POST /v1/embeddings (phase 34e); POST /v1/chat/completions streaming a fixed answer whose
// <think> block is split across chunks. The model name picks a behaviour:
// "fail-401" answers 401, "fail-400" a 400 with a reason, "fail-mid-stream"
// 200 then an error event, "cut-mid-stream" 200 then text and no end, "tags" a tag list, "fail-429" always 429 (Retry-After: 0), "flaky-429" 429 on every other request, "flaky-500" a 500 on the first two requests, "slow" sleeps past a short timeout. Every request
// body is written to $_ENV FAKE_AI_LOG (or the file next to this script),
// so a test can check exactly what was sent.

declare(strict_types=1);

$log = getenv('FAKE_AI_LOG') ?: sys_get_temp_dir() . '/fake-openai-last.json';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
file_put_contents($log, json_encode(['path' => $path, 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'body' => json_decode($body, true)]));

if ($path === '/api/v1/models') {
    // LM Studio's own API, not the OpenAI-compatible one: no `data` list
    header('Content-Type: application/json');
    echo json_encode(['models' => [['key' => 'test-model', 'type' => 'llm']]]);

    return;
}
if ($path === '/v1/models') {
    header('Content-Type: application/json');
    echo json_encode(['data' => [['id' => 'test-model'], ['id' => 'other-model']]]);

    return;
}
if ($path === '/v1/embeddings') {
    // Deterministic: a bag of words hashed into 16 dimensions, so texts that
    // share words are near; model "fail-embed" answers 500, "picky-embed" a
    // 400 for any request holding the word "gradul" (a text it will not take)
    $request = json_decode($body, true);
    if (($request['model'] ?? '') === 'fail-embed') {
        http_response_code(500);

        return;
    }
    if (($request['model'] ?? '') === 'picky-embed' && str_contains(json_encode($request['input'] ?? [], JSON_UNESCAPED_UNICODE), 'gradul')) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['message' => 'input too long']]);

        return;
    }
    $data = [];
    foreach ((array) ($request['input'] ?? []) as $i => $text) {
        $vector = array_fill(0, 16, 0.0);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $vector[crc32($word) % 16] += 1.0;
        }
        $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => $vector];
    }
    header('Content-Type: application/json');
    echo json_encode(['object' => 'list', 'data' => $data, 'model' => $request['model'] ?? '']);

    return;
}
if ($path === '/v1/audio/speech') {
    // The audio overview (phase 8): a tenth of a second of silence as 16-bit mono WAV at 8 kHz; each request
    // logged, one JSON line each, beside the log; voice "stereo" answers in another format (joining must refuse it)
    $request = json_decode($body, true);
    file_put_contents($log . '.speech', json_encode($request, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    $channels = ($request['voice'] ?? '') === 'stereo' ? 2 : 1;
    $pcm = str_repeat("\0\0", 800 * $channels);
    header('Content-Type: audio/wav');
    echo 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, 8000, 8000 * 2 * $channels, 2 * $channels, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;

    return;
}
if ($path !== '/v1/chat/completions') {
    http_response_code(404);

    return;
}
$request = json_decode($body, true);
$model = is_array($request) ? (string) ($request['model'] ?? '') : '';
if ($model === 'fail-401') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'bad key']]);

    return;
}
if ($model === 'fail-400') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['code' => 'invalid_request_error', 'message' => "`temperature` and `top_p` cannot both be specified\nfor this model. Please use only one."]]);

    return;
}
$counter = $log . '.count';
$count = (int) @file_get_contents($counter) + 1;
file_put_contents($counter, (string) $count);
if ($model === 'fail-429' || ($model === 'flaky-429' && $count % 2 === 1)) {
    http_response_code(429);
    header('Retry-After: 0');
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'Provider returned error']]);

    return;
}
if ($model === 'flaky-500' && $count <= 2) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'try again']]);

    return;
}
if ($model === 'slow') {
    sleep(3);
}
header('Content-Type: text/event-stream');
if ($model === 'cut-mid-stream') {
    // The connection just stops: no [DONE], no finish_reason
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Concluzie: fără']]]]) . "\n\n";

    return;
}
if ($model === 'fail-mid-stream') {
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Concluzie: ']]]]) . "\n\n";
    echo 'data: ' . json_encode(['error' => ['message' => 'Upstream model overloaded', 'code' => 502]]) . "\n\n";

    return;
}
// Studio (docs/library.md, phase 6): a prompt page saying STUDIO-ROW gets a comparison row, STUDIO-TEMPLATE a template
$sent = json_encode($request['messages'] ?? [], JSON_UNESCAPED_UNICODE);
if (str_contains($sent, 'STUDIO-ROW')) {
    $model = 'studio-row';
} elseif (str_contains($sent, 'STUDIO-TEMPLATE')) {
    $model = 'studio-template';
} elseif (str_contains($sent, 'STUDIO-CARDS')) {
    $model = 'studio-cards';
} elseif (str_contains($sent, 'STUDIO-AUDIO')) {
    $model = 'studio-audio';
}
$chunks = match ($model) {
    'studio-audio' => ["A: Today, white matter lesions.\n", "B: How are they **graded** [@abel2020]?\n", "B: And in whom?\n", "A: From 0 to 3, in 128 patients.\n"],
    'studio-cards' => ["Q: What grades does Fazekas give?\nA: 0 to 3 [@abel2020, p. 4]\n\n", "Q: Where?\nA: Deep white matter.\n"],
    'studio-row' => ["1. Prospective | cohort [@abel2020, p. 4] [@ghost2000]\n", "2. 128\n"],
    'studio-template' => ["```markdown\n# Art: Substanța albă\n## IRM cerebral\n### Tehnica\nSecvențe [T2 / FLAIR] [@abel2020].\n```\n", "=== checklist ===\n", "# Leziuni\n- Grad Fazekas | fazekas, grad\n", "Număr de leziuni\n"],
    'tags' => ['<think>x</think>IRM, genunchi', ', Fractură, fractura, menisc.'],
    // tags:review (2026-10-15): one good proposal per line, then ones its checks must leave out
    'tags-review' => [
        '<think>x</think>{"op": "generalise", "from": ["leziune corn anterior menisc"], "to": "Leziune meniscală", "reason": "segment"}' . "\n",
        '{"op": "drop", "from": ["de urmărit"], "reason": "not a finding"}' . "\n",
        '{"op": "merge", "from": ["no-such-tag"], "to": "x", "reason": "unknown"}' . "\n",
        '{"op": "drop", "from": ["rads:birads-2"], "reason": "rads"}' . "\n",
        'Done.',
    ],
    // Ask the archive's first step (D53): the question made standalone, then the words to search for
    'ask-expand' => ["Cum descriem leziunile de substanță albă la copii?\n", "leziuni\n", "substanță albă\n", "hiperintensitate\n"],
    default => ['<thi', 'nk>reasoning here</th', 'ink>Concluzie: ', 'fără ', 'leziuni.'],
};
foreach ($chunks as $chunk) {
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => $chunk]]]]) . "\n\n";
    flush();
}
echo 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 7]]) . "\n\n";
echo "data: [DONE]\n\n";
