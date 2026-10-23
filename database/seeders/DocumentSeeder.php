<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\DocumentChunk;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds the already-ingested documents and their chunks (text + embedding
 * vectors) from database/seeders/data/*.json, so a fresh clone gets a
 * working, queryable database immediately instead of re-running
 * `document:ingest` — which for a PDF with charts means re-rendering every
 * figure page and re-reading it with the local vision model, a multi-hour,
 * CPU-bound job. Conversations and messages are deliberately NOT seeded
 * here: they're live, per-environment chat data, not ingestion output.
 *
 * Regenerate the data files after ingesting a new document, then re-commit
 * them: php artisan document:export-seed
 */
class DocumentSeeder extends Seeder
{
    private const DOCUMENTS_PATH = __DIR__ . '/data/documents.json';

    private const CHUNKS_PATH = __DIR__ . '/data/document_chunks.json';

    public function run(): void
    {
        // Children first — document_chunks has an FK to documents.
        DocumentChunk::query()->delete();
        Document::query()->delete();

        $documents = json_decode(file_get_contents(self::DOCUMENTS_PATH), true);

        foreach ($documents as $document) {
            Document::insert($this->normalizeTimestamps($document));
        }

        $chunks = json_decode(file_get_contents(self::CHUNKS_PATH), true);

        foreach (array_chunk($chunks, 200) as $batch) {
            DocumentChunk::insert(array_map(function (array $chunk) {
                $chunk = $this->normalizeTimestamps($chunk);
                // The export round-trips through Eloquent's array cast, so
                // 'embedding' is already a decoded PHP array here — re-encode
                // it to the JSON string the column actually stores.
                $chunk['embedding'] = json_encode($chunk['embedding']);

                return $chunk;
            }, $batch));
        }

        $this->command?->info('Seeded ' . count($documents) . ' document(s), ' . count($chunks) . ' chunk(s).');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeTimestamps(array $row): array
    {
        $row['created_at'] = Carbon::parse($row['created_at'])->format('Y-m-d H:i:s');
        $row['updated_at'] = Carbon::parse($row['updated_at'])->format('Y-m-d H:i:s');

        return $row;
    }
}
