<?php
/**
 * AI question generation via a plain curl call to Anthropic or OpenAI.
 * No SDK. Returns normalized question specs. Requires an API key in config
 * ('anthropic_api_key' or 'openai_api_key'); otherwise ai_available() is false.
 */

declare(strict_types=1);

function ai_available(): bool
{
    return trim((string)cfg('anthropic_api_key','')) !== '' || trim((string)cfg('openai_api_key','')) !== '';
}

/** Per-user daily quota (LLM calls cost money). 0 = unlimited. */
function ai_daily_limit(): int
{
    return (int) cfg('ai_daily_limit', 20);
}

function ai_generations_today(int $uid): int
{
    return (int) DB::scalar(
        "SELECT COUNT(*) FROM ai_generations WHERE user_id=? AND created_at >= ?",
        [$uid, now_ts() - 86400]
    );
}

/**
 * Generate questions. Returns a list of normalized question specs:
 *   ['type','text','options','correct','points','explanation']
 * Throws RuntimeException on failure (no key, bad response, network error).
 */
function ai_generate_questions(string $material, int $n, string $type): array
{
    $n = max(1, min(50, $n));
    $material = trim($material);
    if ($material === '') throw new RuntimeException('Please provide some source material.');

    $typeInstr = [
        'mcq_single' => 'multiple-choice with exactly 4 options and one correct answer',
        'mcq_multi'  => 'multiple-choice with 4-5 options and one OR MORE correct answers',
        'true_false' => 'true/false statements',
        'short_answer' => 'short-answer questions with a concise expected answer',
    ][$type] ?? 'multiple-choice with 4 options and one correct answer';

    $prompt = "You are a quiz author. From the material below, create exactly {$n} {$typeInstr}.\n"
        . "Return ONLY a JSON array (no markdown, no prose). Each item:\n"
        . '{"text":"question","options":["A","B","C","D"],"correct":[0],"explanation":"why"}' . "\n"
        . "For true_false use options [\"True\",\"False\"] and correct [0] or [1].\n"
        . "For short_answer set options to [] and put accepted answers in \"correct\" as strings.\n"
        . "\nMATERIAL:\n" . mb_substr($material, 0, 12000);

    $anthropicKey = trim((string)cfg('anthropic_api_key',''));
    $openaiKey    = trim((string)cfg('openai_api_key',''));

    if ($anthropicKey !== '') {
        $raw = _ai_call_anthropic($anthropicKey, $prompt);
    } elseif ($openaiKey !== '') {
        $raw = _ai_call_openai($openaiKey, $prompt);
    } else {
        throw new RuntimeException('AI generation is not configured (no API key).');
    }

    // Extract JSON array from the model text
    $json = _ai_extract_json($raw);
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('AI returned an unparseable response.');

    $out = [];
    foreach ($data as $q) {
        if (empty($q['text'])) continue;
        $correct = $q['correct'] ?? [];
        if (!is_array($correct)) $correct = [$correct];
        $out[] = [
            'type' => $type,
            'text' => (string)$q['text'],
            'options' => is_array($q['options'] ?? null) ? array_map('strval', $q['options']) : [],
            'correct' => $correct,
            'points' => 1,
            'explanation' => (string)($q['explanation'] ?? ''),
        ];
    }
    if (!$out) throw new RuntimeException('AI did not return any usable questions.');
    return $out;
}

function _ai_http(string $url, array $headers, array $body): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('Network error contacting the AI service: ' . $err);
    if ($code >= 400) throw new RuntimeException('AI service returned HTTP ' . $code . '.');
    return (string)$resp;
}

function _ai_call_anthropic(string $key, string $prompt): string
{
    $model = (string) cfg('anthropic_model', 'claude-3-5-haiku-latest');
    $resp = _ai_http('https://api.anthropic.com/v1/messages', [
        'content-type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ], [
        'model' => $model,
        'max_tokens' => 4096,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);
    $j = json_decode($resp, true);
    return $j['content'][0]['text'] ?? '';
}

function _ai_call_openai(string $key, string $prompt): string
{
    $model = (string) cfg('openai_model', 'gpt-4o-mini');
    $resp = _ai_http('https://api.openai.com/v1/chat/completions', [
        'content-type: application/json',
        'authorization: Bearer ' . $key,
    ], [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);
    $j = json_decode($resp, true);
    return $j['choices'][0]['message']['content'] ?? '';
}

/** Pull the first JSON array out of a model response (handles ```json fences). */
function _ai_extract_json(string $text): string
{
    $text = trim($text);
    // strip code fences
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    if ($start !== false && $end !== false && $end > $start) {
        return substr($text, $start, $end - $start + 1);
    }
    return $text;
}
