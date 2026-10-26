<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the ingestion pipeline for the (single, fixed) source
 * document: Parse -> Chunk -> Embed -> Store. There's no upload endpoint —
 * ingestion is triggered by the `gpo:ingest` console command against a file
 * shipped in the repo, since this assistant answers questions about one
 * known document, not an arbitrary user-supplied one.
 */
class DocumentIngestionService
{
    public function __construct(
        private DocumentParserService $parser,
        private ChunkerService $chunker,
        private EmbeddingService $embedder,
        private ChartVisionService $chartVision,
        private ImageDescriptionService $imageDescriber,
    ) {}

    /**
     * @param ?callable(int, int, int, string): void $onChartProgress see
     *        ChartVisionService::describeCharts()
     * @param ?callable(int, int, int, string): void $onImageProgress see
     *        ImageDescriptionService::describeImages()
     */
    public function ingestFromPath(string $absolutePath, string $filename, ?callable $onChartProgress = null, ?callable $onImageProgress = null): Document
    {
        // Extension comes from the real file path, not $filename — the
        // latter is just the display name stored on the Document and may
        // not carry an extension at all (e.g. a friendly title).
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $document = Document::create([
            'filename' => $filename,
            'original_extension' => $extension,
            'status' => 'processing',
        ]);

        try {
            // 1. Parse
            $rawText = $this->parser->parse($absolutePath, $extension);

            // 2. Chunk the body text
            $chunkTexts = $this->chunker->chunk($rawText);

            // 2b. Recover chart/figure data that plain text extraction can't
            // see (bar/line chart values drawn as shapes, not text), and
            // describe actual photos/illustrations too (distinct from chart
            // data — this is what makes "what does X look like?" questions
            // answerable). Each page's description is chunked on its own,
            // never concatenated with another page's first — merging two
            // unrelated images' descriptions into one chunk dilutes the
            // embedding enough that neither becomes reliably retrievable
            // (observed: a correct answer buried at rank 89/311 because its
            // chunk also contained two other pages' unrelated photos).
            if ($extension === 'pdf') {
                $chartDescriptions = $this->chartVision->describeCharts($absolutePath, $onChartProgress);
                $imageDescriptions = $this->imageDescriber->describeImages($absolutePath, $onImageProgress);

                foreach ([...$chartDescriptions, ...$imageDescriptions] as $description) {
                    array_push($chunkTexts, ...$this->chunker->chunk($description));
                }
            }

            // 3. Embed all chunks concurrently instead of one request at a time
            $vectors = $this->embedder->embedMany($chunkTexts);

            // 4. Store chunk text + embedding together in one bulk insert
            $now = now();
            $chunkRows = [];

            foreach ($chunkTexts as $index => $chunkText) {
                $chunkRows[] = [
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'content' => $chunkText,
                    'embedding' => json_encode($vectors[$index]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! empty($chunkRows)) {
                DocumentChunk::insert($chunkRows);
            }

            $document->update([
                'status' => 'ready',
                'chunk_count' => count($chunkTexts),
            ]);
        } catch (Throwable $e) {
            Log::error('Document ingestion failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            $document->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $document->fresh();
    }

    public function deleteAllDocuments(): void
    {
        Document::query()->delete(); // chunks cascade-delete via FK constraint
    }

    /**
     * Removes just one document (by filename) ahead of a --force re-ingest,
     * leaving every other ingested document untouched.
     */
    public function deleteByFilename(string $filename): void
    {
        Document::where('filename', $filename)->delete(); // chunks cascade-delete via FK constraint
    }
}
