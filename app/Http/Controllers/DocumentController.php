<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;

class DocumentController extends Controller
{
    /**
     * All ready-to-query documents — the frontend uses this to populate the
     * document selector shown when starting a new chat (chat is documentscoped, see Conversation::document_id). There's no upload flow; documents
     * are ingested via the `document:ingest` console command.
     */
    public function index(): JsonResponse
    {
        $documents = Document::where('status', 'ready')
            ->orderBy('filename')
            ->get(['id', 'filename', 'chunk_count']);

        return response()->json(['documents' => $documents]);
    }

    /**
     * Status of the most recently ingested document, including in-progress
     * or failed ones — kept for a simple "is anything indexed yet" readiness
     * check independent of which document a chat is scoped to.
     */
    public function show(): JsonResponse
    {
        $document = Document::orderByDesc('created_at')->first(['id', 'filename', 'status', 'chunk_count', 'error_message']);

        return response()->json($document);
    }
}
