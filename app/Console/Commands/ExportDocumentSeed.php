<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Console\Command;

/**
 * Writes every ingested document + chunk (text and embedding vectors) out to
 * database/seeders/data/*.json, the counterpart to DocumentSeeder. Run this
 * after ingesting a new document and commit the regenerated JSON files, so a
 * fresh clone's `php artisan db:seed` picks up the new document too instead
 * of only whatever was last exported.
 */
class ExportDocumentSeed extends Command
{
    protected $signature = 'document:export-seed';

    protected $description = 'Export all ingested documents and chunks to database/seeders/data/*.json for DocumentSeeder';

    public function handle(): int
    {
        $documentsPath = database_path('seeders/data/documents.json');
        $chunksPath = database_path('seeders/data/document_chunks.json');

        $documents = Document::query()->orderBy('id')->get();
        $chunks = DocumentChunk::query()->orderBy('id')->get();

        file_put_contents($documentsPath, $documents->toJson());
        file_put_contents($chunksPath, $chunks->toJson());

        $this->info("Exported {$documents->count()} document(s), {$chunks->count()} chunk(s).");
        $this->line("  {$documentsPath}");
        $this->line("  {$chunksPath}");

        return self::SUCCESS;
    }
}
