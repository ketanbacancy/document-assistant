<?php

namespace App\Services;

use App\Exceptions\GeminiQuotaExceededException;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Equivalent of workshop's ChatGoogleGenerativeAI (Section 2.3) — a direct
 * HTTP wrapper around Gemini's generateContent REST endpoint, since there's
 * no LangChain adapter in PHP to do this for us.
 *
 * Docs: https://ai.google.dev/gemini-api/docs/text-generation
 */
class GeminiChatService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model = config('services.gemini.chat_model', 'gemini-3.5-flash-lite');
    }

    /**
     * @param array $history [['role' => 'user'|'model', 'text' => '...'], ...]
     */
    public function generate(string $systemInstruction, array $history, string $userMessage): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $response = Http::timeout(20)->post($url, $this->buildPayload($systemInstruction, $history, $userMessage));

        if ($quotaMessage = $this->quotaExceededMessage($response)) {
            throw new GeminiQuotaExceededException($quotaMessage);
        }

        if ($response->failed()) {
            throw new RuntimeException('Gemini generateContent failed: ' . $response->body());
        }

        return $response->json('candidates.0.content.parts.0.text') ?? '';
    }

    /**
     * Streams the answer as it's generated via Gemini's Server-Sent Events
     * endpoint, yielding each incremental text piece as it arrives instead
     * of waiting for the full response — this is what lets the frontend
     * render the answer progressively rather than after several seconds.
     *
     * @param array $history [['role' => 'user'|'model', 'text' => '...'], ...]
     * @return Generator<int, string>
     */
    public function generateStream(string $systemInstruction, array $history, string $userMessage): Generator
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:streamGenerateContent?alt=sse&key={$this->apiKey}";

        $response = Http::withOptions(['stream' => true])
            ->timeout(30)
            ->post($url, $this->buildPayload($systemInstruction, $history, $userMessage));

        if ($quotaMessage = $this->quotaExceededMessage($response)) {
            throw new GeminiQuotaExceededException($quotaMessage);
        }

        if ($response->failed()) {
            throw new RuntimeException('Gemini streamGenerateContent failed: ' . $response->body());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            // Google's SSE stream uses CRLF line endings; normalize to LF so
            // the "\n\n" event-boundary split below matches reliably.
            $buffer .= str_replace("\r\n", "\n", $body->read(1024));

            while (($boundary = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $boundary);
                $buffer = substr($buffer, $boundary + 2);

                foreach (explode("\n", $event) as $line) {
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $json = trim(substr($line, 5));
                    $data = $json !== '' ? json_decode($json, true) : null;
                    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

                    if ($text !== null && $text !== '') {
                        yield $text;
                    }
                }
            }
        }
    }

    /**
     * Distinguishes a 429 quota error (won't resolve for hours — the free
     * tier's daily cap) from a transient failure worth just erroring out on
     * normally. Returns a user-facing message when it's a quota error, null
     * otherwise.
     */
    private function quotaExceededMessage(Response $response): ?string
    {
        if ($response->status() !== 429 || ! str_contains($response->body(), 'RESOURCE_EXHAUSTED')) {
            return null;
        }

        $retrySeconds = null;
        foreach ($response->json('error.details') ?? [] as $detail) {
            if (($detail['@type'] ?? null) === 'type.googleapis.com/google.rpc.RetryInfo') {
                $retrySeconds = (int) rtrim($detail['retryDelay'] ?? '', 's');
            }
        }

        $when = $retrySeconds ? 'in about ' . max(1, (int) round($retrySeconds / 3600)) . ' hour(s)' : 'later';

        return "Gemini's chat quota has been used up for now — try again {$when}.";
    }

    /**
     * @param array $history [['role' => 'user'|'model', 'text' => '...'], ...]
     */
    private function buildPayload(string $systemInstruction, array $history, string $userMessage): array
    {
        $contents = [];
        foreach ($history as $turn) {
            $contents[] = [
                'role' => $turn['role'], // 'user' or 'model'
                'parts' => [['text' => $turn['text']]],
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]],
        ];

        return [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.3,
                // 'low' thinking still spends some of this budget on internal
                // reasoning before the visible answer, which was truncating
                // answers that synthesize across multiple chunks (e.g. a
                // multi-part number like "6.3 percent" cut off at "6.") —
                // raised from 400 for headroom.
                'maxOutputTokens' => 800,
                // Gemini 3.x models run an internal reasoning pass by default;
                // 'low' keeps that minimal since RAG QA answers don't need deep
                // reasoning over already-retrieved context.
                'thinkingConfig' => [
                    'thinkingLevel' => 'low',
                ],
            ],
        ];
    }
}
