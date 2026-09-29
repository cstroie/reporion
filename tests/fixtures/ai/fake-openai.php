<?php

// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only: a tiny OpenAI-compatible server for `php -S` (tests/Ai).
// GET /v1/models; POST /v1/chat/completions streaming a fixed answer whose
// <think> block is split across chunks. The model name picks a behaviour:
// "fail-401" answers 401, "fail-400" a 400 with a reason, "fail-429" always
// 429 (Retry-After: 0), "flaky-429" 429 on every other request, "slow" sleeps past a short timeout. Every request
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
if ($model === 'slow') {
    sleep(3);
}
header('Content-Type: text/event-stream');
$chunks = ['<thi', 'nk>reasoning here</th', 'ink>Concluzie: ', 'fără ', 'leziuni.'];
foreach ($chunks as $chunk) {
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => $chunk]]]]) . "\n\n";
    flush();
}
echo 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 7]]) . "\n\n";
echo "data: [DONE]\n\n";
