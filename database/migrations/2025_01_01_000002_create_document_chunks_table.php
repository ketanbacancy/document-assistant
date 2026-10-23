<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No external vector database — the embedding vector is stored right
     * alongside its chunk text. At query time we load every chunk's vector
     * and rank by cosine similarity in PHP (see VectorSearchService); for a
     * single bounded document (a few hundred chunks) that's a sub-millisecond
     * linear scan, so a dedicated index isn't needed.
     */
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->json('embedding');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
