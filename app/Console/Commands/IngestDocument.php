<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentIngestionService;
use Illuminate\Console\Command;

/**
 * Ingests one document into the local vector store. There's no upload UI —
 * documents are added to storage/app/seed-documents/ and ingested here, one
 * at a time; chat is document-scoped (see Conversation::document_id), so
 * ingesting a new document never touches ones already indexed.
 */
class IngestDocument extends Command
{
    protected $signature = 'document:ingest
        {path : Absolute path, or a filename inside storage/app/seed-documents/}
        {name? : Display name to store; defaults to the file\'s basename}
        {--force : Re-ingest even if a document with this name is already indexed}';

    protected $description = 'Ingest a PDF/DOCX/TXT document into the local vector store';

    public function handle(DocumentIngestionService $ingestionService): int
    {
        $path = $this->resolvePath($this->argument('path'));

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $filename = $this->argument('name') ?? basename($path);

        $existing = Document::where('filename', $filename)->where('status', 'ready')->first();

        if ($existing && ! $this->option('force')) {
            $this->info("Already ingested: \"{$filename}\" ({$existing->chunk_count} chunks). Pass --force to re-ingest.");

            return self::SUCCESS;
        }

        if ($existing) {
            $this->info("Re-ingesting — clearing the existing \"{$filename}\" first...");
            $ingestionService->deleteByFilename($filename);
        }

        $this->info("Parsing \"{$filename}\" and scanning for figures...");

        $document = $ingestionService->ingestFromPath($path, $filename, function (int $current, int $total, int $pageNumber, string $status) {
            $label = match ($status) {
                'processing' => 'reading chart(s)...',
                'found' => 'chart data found',
                'none' => 'no chart data',
                'skipped' => 'skipped (render/describe failed)',
            };

            $this->line("  [{$current}/{$total}] page {$pageNumber} — {$label}");
        });

        $this->info('Chunking and embedding the full text locally via Ollama...');

        if ($document->status === 'ready') {
            $this->info("Done — indexed {$document->chunk_count} chunks.");

            return self::SUCCESS;
        }

        $this->error("Ingestion failed: {$document->error_message}");

        return self::FAILURE;
    }

    private function resolvePath(string $path): string
    {
        if (file_exists($path)) {
            return $path;
        }

        return storage_path('app/seed-documents/' . $path);
    }
}
