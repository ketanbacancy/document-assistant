<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Generator;

/**
 * The heart of the system — direct port of workshop Section 8 (rag_chat()),
 * but with conversation history persisted to the DB instead of an in-memory
 * Python list, so it survives page refreshes.
 */
class RagChatService
{
    private const SYSTEM_TEMPLATE = <<<'TEXT'
You are a helpful assistant answering questions about "%s".
Use the CONTEXT below if it's relevant to the question. The context may
include chart/figure data transcribed from the document's images, labeled
with its source page.
If the answer isn't in the context AND isn't something already established
earlier in this conversation, respond exactly with:
"I don't have that information in %s."
Do not make up facts and do not use outside knowledge.
When the context contains directly related figures next to the ones asked
for (e.g. a baseline, a reduction, and the resulting total), state the
connection explicitly rather than only answering the literal question.
Keep answers brief — 2 to 4 sentences — unless the user explicitly asks for more detail.

CONTEXT:
%s
TEXT;

    public function __construct(
        private EmbeddingService $embedder,
        private VectorSearchService $vectorSearch,
        private GeminiChatService $chatService,
        private int $topK = 4,
    ) {}

    /**
     * @return array{answer: string, sources: array}
     */
    public function ask(Conversation $conversation, string $userMessage): array
    {
        [$systemInstruction, $history, $sources] = $this->retrieve($conversation, $userMessage);

        $answer = $this->chatService->generate($systemInstruction, $history, $userMessage);

        $this->persistTurn($conversation, $userMessage, $answer, $sources);

        return [
            'answer' => $answer,
            'sources' => $sources,
        ];
    }

    /**
     * Streaming counterpart of ask() — yields ['type' => 'delta', 'text' => ...]
     * as each piece of the answer arrives, then a final
     * ['type' => 'done', 'sources' => ...] once the full answer is persisted.
     *
     * @return Generator<int, array{type: string, text?: string, sources?: array}>
     */
    public function askStream(Conversation $conversation, string $userMessage): Generator
    {
        [$systemInstruction, $history, $sources] = $this->retrieve($conversation, $userMessage);

        $fullAnswer = '';

        foreach ($this->chatService->generateStream($systemInstruction, $history, $userMessage) as $delta) {
            $fullAnswer .= $delta;
            yield ['type' => 'delta', 'text' => $delta];
        }

        $this->persistTurn($conversation, $userMessage, $fullAnswer, $sources);

        yield ['type' => 'done', 'sources' => $sources];
    }

    /**
     * Retrieves the relevant document context for this message and builds
     * the system prompt + Gemini-shaped conversation history shared by both
     * ask() and askStream().
     *
     * @return array{0: string, 1: array, 2: array} [systemInstruction, history, sources]
     */
    private function retrieve(Conversation $conversation, string $userMessage): array
    {
        $queryVector = $this->embedder->embed($userMessage);
        $matches = $this->vectorSearch->search($queryVector, $this->topK, $conversation->document_id);

        $sources = [];
        $contextPieces = [];

        foreach ($matches as $chunk) {
            $contextPieces[] = $chunk->content;
            $sources[] = [
                'document_id' => $chunk->document_id,
                'filename' => $chunk->document->filename,
                'chunk_index' => $chunk->chunk_index,
                'score' => $chunk->score,
                'excerpt' => mb_substr($chunk->content, 0, 200),
            ];
        }

        $documentName = $conversation->document?->filename ?? 'the selected document';
        $context = implode("\n\n---\n\n", $contextPieces);
        $systemInstruction = sprintf(self::SYSTEM_TEMPLATE, $documentName, $documentName, $context ?: '(no relevant context found)');

        $history = $conversation->messages->map(fn (Message $m) => [
            'role' => $m->role === 'assistant' ? 'model' : 'user',
            'text' => $m->content,
        ])->toArray();

        return [$systemInstruction, $history, $sources];
    }

    private function persistTurn(Conversation $conversation, string $userMessage, string $answer, array $sources): void
    {
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $answer,
            'sources' => $sources,
        ]);
    }
}
