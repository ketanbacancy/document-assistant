<?php

namespace App\Services;

use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

/**
 * Replaces the external vector database (Pinecone) with a brute-force
 * in-process search: load every chunk's embedding, rank by cosine
 * similarity, take the top K. Each indexed document is a few hundred
 * chunks at most, so a linear scan is sub-millisecond even across several
 * documents — there's no scale here that would justify a dedicated vector
 * index.
 */
class VectorSearchService
{
    /**
     * @param float[] $queryVector
     * @param int|null $documentId scope the search to one document (chat is
     *        document-scoped — see Conversation::document_id); null searches
     *        every ingested document's chunks together
     * @return Collection<int, DocumentChunk> top-K chunks, each with a
     *         ->score property attached, ordered most-similar first
     */
    public function search(array $queryVector, int $topK = 4, ?int $documentId = null): Collection
    {
        return DocumentChunk::with('document')
            ->when($documentId, fn ($query) => $query->where('document_id', $documentId))
            ->get()
            ->map(function (DocumentChunk $chunk) use ($queryVector) {
                $chunk->score = $this->cosineSimilarity($queryVector, $chunk->embedding);

                return $chunk;
            })
            ->sortByDesc('score')
            ->take($topK)
            ->values();
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        foreach ($a as $i => $value) {
            $dotProduct += $value * $b[$i];
            $magnitudeA += $value * $value;
            $magnitudeB += $b[$i] * $b[$i];
        }

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($magnitudeA) * sqrt($magnitudeB));
    }
}
