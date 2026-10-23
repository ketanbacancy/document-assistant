<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Embeddings run locally via Ollama instead of a hosted API — no account,
 * no quota, no cost, works offline. Ollama's /api/embed natively batches
 * multiple texts in one request, so unlike the earlier Gemini-based version
 * of this service, there's no need for pooling, pacing, or quota handling:
 * a local service has none of those constraints.
 *
 * Docs: https://github.com/ollama/ollama/blob/main/docs/api.md#generate-embeddings
 */
class EmbeddingService
{
    // Keeps individual requests a modest size; Ollama has no per-request
    // limit we're working around here, this is just sane batching.
    private const BATCH_SIZE = 50;

    private string $host;
    private string $model;

    public function __construct()
    {
        $this->host = rtrim(config('services.ollama.host'), '/');
        $this->model = config('services.ollama.embedding_model', 'nomic-embed-text');
    }

    /**
     * @return float[] the embedding vector
     */
    public function embed(string $text): array
    {
        return $this->embedMany([$text])[0];
    }

    /**
     * @param string[] $texts
     * @return float[][] one vector per input text, same order as $texts
     */
    public function embedMany(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $vectors = [];

        foreach (array_chunk(array_values($texts), self::BATCH_SIZE) as $batch) {
            $response = Http::timeout(60)->post("{$this->host}/api/embed", [
                'model' => $this->model,
                'input' => $batch,
            ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Ollama embedding request failed (is Ollama running at ' . $this->host . '?): ' . $response->body()
                );
            }

            $embeddings = $response->json('embeddings');

            if (! is_array($embeddings) || count($embeddings) !== count($batch)) {
                throw new RuntimeException('Ollama returned an unexpected embeddings response: ' . $response->body());
            }

            array_push($vectors, ...$embeddings);
        }

        return $vectors;
    }
}
