<?php

declare(strict_types=1);

namespace CouponFind\Services;

use CouponFind\Core\Database;
use CouponFind\Core\Env;
use CouponFind\Support\Http;

/**
 * Multi-provider AI abstraction with an automatic fallback chain.
 *
 * Order and enablement come from the `ai_providers` table (admin-controlled),
 * falling back to AI_PROVIDER_ORDER from the environment. If a provider errors
 * or is missing its API key, the manager transparently tries the next one:
 *
 *     Groq  ->  Gemini  ->  OpenAI
 *
 * Used for query rewriting on low-confidence searches. The whole feature is
 * optional — if no provider succeeds, callers fall back to deterministic logic.
 */
final class AiProviderManager
{
    public function __construct(private ?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /** @return array<int,array{slug:string,model:string}> ordered enabled providers */
    public function chain(): array
    {
        try {
            $rows = $this->db->all(
                'SELECT slug, model FROM ai_providers WHERE is_enabled = 1 ORDER BY priority ASC'
            );
            if ($rows) {
                return array_map(fn ($r) => ['slug' => $r['slug'], 'model' => $r['model']], $rows);
            }
        } catch (\Throwable) {
            // fall through to env ordering
        }

        $order = array_filter(array_map('trim', explode(',', Env::string('AI_PROVIDER_ORDER', 'groq,gemini,openai'))));
        return array_map(fn ($slug) => ['slug' => $slug, 'model' => $this->defaultModel($slug)], $order);
    }

    /**
     * Run a chat-style completion across the fallback chain.
     * @return array{provider:string,text:string}|null
     */
    public function complete(string $system, string $user, float $temperature = 0.2): ?array
    {
        foreach ($this->chain() as $p) {
            $text = $this->callProvider($p['slug'], $p['model'], $system, $user, $temperature);
            if ($text !== null) {
                $this->markOk($p['slug']);
                return ['provider' => $p['slug'], 'text' => $text];
            }
        }
        return null;
    }

    /**
     * Rewrite a messy query into structured search intent JSON.
     * @return array{keywords?:string,merchant?:string,discount_type?:string,discount_value?:float}|null
     */
    public function rewriteQuery(string $raw): ?array
    {
        $system = 'You are a search query normalizer for a coupon search engine. '
            . 'Given a possibly misspelled user query, respond with ONLY compact JSON: '
            . '{"keywords": string, "merchant": string|null, "discount_type": "percent"|"amount"|"free_shipping"|null, "discount_value": number|null}. '
            . 'Fix spelling. Extract the brand/merchant if present. No prose.';

        $result = $this->complete($system, $raw, 0.0);
        if ($result === null) {
            return null;
        }
        $json = $this->extractJson($result['text']);
        return is_array($json) ? $json : null;
    }

    private function callProvider(string $slug, ?string $model, string $system, string $user, float $temp): ?string
    {
        try {
            return match ($slug) {
                'groq'   => $this->callOpenAiCompatible(
                    'https://api.groq.com/openai/v1/chat/completions',
                    Env::string('GROQ_API_KEY'),
                    $model ?: Env::string('GROQ_MODEL', 'llama-3.3-70b-versatile'),
                    $system, $user, $temp
                ),
                'openai' => $this->callOpenAiCompatible(
                    'https://api.openai.com/v1/chat/completions',
                    Env::string('OPENAI_API_KEY'),
                    $model ?: Env::string('OPENAI_MODEL', 'gpt-4o-mini'),
                    $system, $user, $temp
                ),
                'gemini' => $this->callGemini(
                    $model ?: Env::string('GEMINI_MODEL', 'gemini-1.5-flash'),
                    Env::string('GEMINI_API_KEY'),
                    $system, $user, $temp
                ),
                default  => null,
            };
        } catch (\Throwable $e) {
            $this->markError($slug, $e->getMessage());
            return null;
        }
    }

    private function callOpenAiCompatible(string $url, string $apiKey, string $model, string $system, string $user, float $temp): ?string
    {
        if ($apiKey === '') {
            return null;
        }
        $res = Http::postJson($url, [
            'model'       => $model,
            'temperature' => $temp,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ], ['Authorization' => 'Bearer ' . $apiKey], 15);

        if (!$res['ok']) {
            $this->markError($model, 'HTTP ' . $res['status']);
            return null;
        }
        $text = $res['json']['choices'][0]['message']['content'] ?? null;
        return is_string($text) ? trim($text) : null;
    }

    private function callGemini(string $model, string $apiKey, string $system, string $user, float $temp): ?string
    {
        if ($apiKey === '') {
            return null;
        }
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
        $res = Http::postJson($url, [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => [['parts' => [['text' => $user]]]],
            'generationConfig'  => ['temperature' => $temp],
        ], [], 15);

        if (!$res['ok']) {
            return null;
        }
        $text = $res['json']['candidates'][0]['content']['parts'][0]['text'] ?? null;
        return is_string($text) ? trim($text) : null;
    }

    private function extractJson(string $text): ?array
    {
        // Strip code fences and locate the JSON object.
        $text = preg_replace('/```(?:json)?/i', '', $text) ?? $text;
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    private function defaultModel(string $slug): string
    {
        return match ($slug) {
            'groq'   => Env::string('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'gemini' => Env::string('GEMINI_MODEL', 'gemini-1.5-flash'),
            'openai' => Env::string('OPENAI_MODEL', 'gpt-4o-mini'),
            default  => '',
        };
    }

    private function markOk(string $slug): void
    {
        try {
            $this->db->execute('UPDATE ai_providers SET last_ok_at = NOW(), last_error = NULL WHERE slug = ?', [$slug]);
        } catch (\Throwable) {
        }
    }

    private function markError(string $slug, string $error): void
    {
        try {
            $this->db->execute('UPDATE ai_providers SET last_error = ? WHERE slug = ?', [substr($error, 0, 255), $slug]);
        } catch (\Throwable) {
        }
    }
}
