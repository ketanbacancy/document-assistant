<?php

namespace App\Http\Controllers;

use App\Exceptions\GeminiQuotaExceededException;
use App\Models\Conversation;
use App\Services\RagChatService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(private RagChatService $ragChatService) {}

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'session_uuid' => 'nullable|string',
            // Required to start a new chat (chat is document-scoped — see
            // Conversation::document_id); ignored once a conversation exists.
            'document_id' => 'required_without:session_uuid|nullable|integer|exists:documents,id',
        ]);

        $sessionUuid = $validated['session_uuid'] ?? (string) Str::uuid();

        $conversation = Conversation::firstOrCreate(
            ['session_uuid' => $sessionUuid],
            ['document_id' => $validated['document_id'] ?? null]
        );
        $conversation->load('messages');

        try {
            $result = $this->ragChatService->ask($conversation, $validated['message']);
        } catch (GeminiQuotaExceededException $e) {
            Log::warning(__CLASS__ . ':' . __FILE__ . ':' . __LINE__ . ':' . __FUNCTION__ . ':' . $e->getMessage());

            return response()->json([
                'session_uuid' => $sessionUuid,
                'answer' => null,
                'sources' => [],
                'error_message' => $e->getMessage(),
            ], 429);
        } catch (Throwable $e) {
            Log::error(__CLASS__ . ':' . __FILE__ . ':' . __LINE__ . ':' . __FUNCTION__ . ':' . $e->getMessage());

            return response()->json([
                'session_uuid' => $sessionUuid,
                'answer' => null,
                'sources' => [],
                'error_message' => 'The assistant is temporarily unavailable. Please try again.',
            ], 502);
        }

        return response()->json([
            'session_uuid' => $sessionUuid,
            'answer' => $result['answer'],
            'sources' => $result['sources'],
        ]);
    }

    /**
     * Server-Sent Events version of ask(): streams the answer as Gemini
     * generates it so the frontend can render it progressively instead of
     * waiting several seconds for the full response.
     */
    public function askStream(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'session_uuid' => 'nullable|string',
            'document_id' => 'required_without:session_uuid|nullable|integer|exists:documents,id',
        ]);

        $sessionUuid = $validated['session_uuid'] ?? (string) Str::uuid();

        $conversation = Conversation::firstOrCreate(
            ['session_uuid' => $sessionUuid],
            ['document_id' => $validated['document_id'] ?? null]
        );
        $conversation->load('messages');

        return response()->stream(function () use ($conversation, $validated, $sessionUuid): void {
            $send = function (string $event, array $data): void {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data) . "\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $send('meta', ['session_uuid' => $sessionUuid]);

            try {
                foreach ($this->ragChatService->askStream($conversation, $validated['message']) as $piece) {
                    if ($piece['type'] === 'delta') {
                        $send('delta', ['text' => $piece['text']]);
                    } else {
                        $send('done', ['sources' => $piece['sources']]);
                    }
                }
            } catch (GeminiQuotaExceededException $e) {
                Log::warning(__CLASS__ . ':' . __FILE__ . ':' . __LINE__ . ':' . __FUNCTION__ . ':' . $e->getMessage());

                $send('error', ['message' => $e->getMessage()]);
            } catch (Throwable $e) {
                Log::error(__CLASS__ . ':' . __FILE__ . ':' . __LINE__ . ':' . __FUNCTION__ . ':' . $e->getMessage());

                $send('error', ['message' => 'The assistant is temporarily unavailable. Please try again.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function history(string $sessionUuid): JsonResponse
    {
        $conversation = Conversation::where('session_uuid', $sessionUuid)->first();

        if (!$conversation) {
            // Lets the frontend tell "this session no longer exists" (e.g. a
            // stale session_uuid left in localStorage after its conversation
            // was deleted) apart from "a real conversation with no document
            // resolved yet" — both would otherwise look like an empty
            // messages array.
            return response()->json(['found' => false, 'messages' => [], 'document_id' => null]);
        }

        return response()->json([
            'found' => true,
            'messages' => $conversation->messages()->get(['role', 'content', 'sources', 'created_at']),
            'document_id' => $conversation->document_id,
        ]);
    }

    /**
     * Lists past conversations, most recently active first, each with a
     * title derived from its first message — the data behind a ChatGPT-style
     * history sidebar (list threads, click to switch, no separate "title"
     * ever stored since it's just derived from the first message).
     */
    public function conversations(): JsonResponse
    {
        $conversations = Conversation::query()
            ->with([
                'messages' => fn ($query) => $query->orderBy('created_at')->limit(1),
                'document:id,filename',
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Conversation $conversation) => [
                'session_uuid' => $conversation->session_uuid,
                'title' => $this->titleFor($conversation->messages->first()?->content),
                'document_id' => $conversation->document_id,
                'document_filename' => $conversation->document?->filename,
                'updated_at' => $conversation->updated_at,
            ]);

        return response()->json(['conversations' => $conversations]);
    }

    private function titleFor(?string $firstMessage): string
    {
        if (! $firstMessage) {
            return 'New chat';
        }

        return Str::limit($firstMessage, 48);
    }
}
