import React, { useState, useEffect, useRef } from 'react';
import SourcesPanel from './SourcesPanel';
import { SparkleIcon, SendIcon, FileTextIcon, ChevronDownIcon } from './Icons';

const MAX_TEXTAREA_HEIGHT_PX = 160;

export default function ChatWindow({ sessionUuid, onSessionCreated, onExchangeComplete, documents, selectedDocumentId, onSelectDocument, onDocumentResolved, onInvalidSession }) {
    const [messages, setMessages] = useState([]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const bottomRef = useRef(null);
    const textareaRef = useRef(null);

    useEffect(() => {
        if (sessionUuid) {
            loadHistory(sessionUuid);
        } else {
            setMessages([]); // "New chat" was clicked
        }
    }, [sessionUuid]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    // Auto-grow the composer with content (up to a cap), and shrink it back
    // down once input is cleared (e.g. after sending).
    useEffect(() => {
        const el = textareaRef.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, MAX_TEXTAREA_HEIGHT_PX)}px`;
    }, [input]);

    const loadHistory = async (uuid) => {
        const res = await fetch(`/api/chat/${uuid}/history`);
        const data = await res.json();

        if (!data.found) {
            onInvalidSession();
            return;
        }

        setMessages(data.messages || []);
        onDocumentResolved(data.document_id);
    };

    // Parses one SSE block ("event: x\ndata: y") already split from the stream buffer.
    const parseSseEvent = (rawEvent) => {
        let eventName = 'message';
        let dataLine = '';

        for (const line of rawEvent.split('\n')) {
            if (line.startsWith('event:')) eventName = line.slice(6).trim();
            if (line.startsWith('data:')) dataLine = line.slice(5).trim();
        }

        return dataLine ? { eventName, data: JSON.parse(dataLine) } : null;
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSend(e);
        }
    };

    const handleSend = async (e) => {
        e.preventDefault();
        const trimmed = input.trim();
        if (!trimmed || loading || (!sessionUuid && !selectedDocumentId)) return;

        setMessages((prev) => [
            ...prev,
            { role: 'user', content: trimmed },
            { role: 'assistant', content: '', sources: [] },
        ]);
        setInput('');
        setLoading(true);

        const patchAssistantMessage = (patch) => {
            setMessages((prev) => {
                const next = [...prev];
                next[next.length - 1] = { ...next[next.length - 1], ...patch };
                return next;
            });
        };

        try {
            const res = await fetch('/api/chat/stream', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                },
                body: JSON.stringify({
                    message: trimmed,
                    session_uuid: sessionUuid,
                    // Only matters for a brand-new conversation — the backend
                    // ignores it once a conversation already has a document.
                    document_id: sessionUuid ? undefined : selectedDocumentId,
                }),
            });

            if (!res.ok || !res.body) {
                patchAssistantMessage({ content: 'Something went wrong reaching the server.' });
                return;
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let accumulated = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                let boundary;
                while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                    const parsed = parseSseEvent(buffer.slice(0, boundary));
                    buffer = buffer.slice(boundary + 2);
                    if (!parsed) continue;

                    const { eventName, data } = parsed;

                    if (eventName === 'meta') {
                        if (!sessionUuid && data.session_uuid) {
                            onSessionCreated(data.session_uuid);
                        }
                    } else if (eventName === 'delta') {
                        accumulated += data.text;
                        patchAssistantMessage({ content: accumulated });
                    } else if (eventName === 'done') {
                        patchAssistantMessage({ sources: data.sources });
                        onExchangeComplete?.();
                    } else if (eventName === 'error') {
                        patchAssistantMessage({ content: data.message });
                    }
                }
            }
        } catch (err) {
            patchAssistantMessage({ content: 'Something went wrong reaching the server.' });
        } finally {
            setLoading(false);
        }
    };

    const needsDocumentChoice = !sessionUuid && !selectedDocumentId;
    const selectedDocument = documents.find((d) => d.id === selectedDocumentId);

    return (
        <div className="flex h-screen min-w-0 flex-1 flex-col">
            {documents.length === 0 && (
                <div className="border-b border-gray-200 bg-amber-50 px-5 py-2.5 text-[13px] text-amber-700 dark:border-neutral-800 dark:bg-amber-500/10 dark:text-amber-400">
                    No documents indexed yet — run <code>php artisan document:ingest</code> first.
                </div>
            )}

            {documents.length > 0 && (
                <div className="flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-5 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex min-w-0 items-center gap-1.5 text-[12.5px] text-gray-500 dark:text-gray-400">
                        <FileTextIcon width={14} height={14} className="shrink-0" />
                        {selectedDocument ? (
                            <span className="truncate">
                                Chatting about <span className="font-medium text-gray-800 dark:text-gray-200">{selectedDocument.filename}</span>
                            </span>
                        ) : (
                            <span>No document selected</span>
                        )}
                    </div>

                    <div className="relative shrink-0">
                        <select
                            value={selectedDocumentId ?? ''}
                            onChange={(e) => onSelectDocument(Number(e.target.value))}
                            className="cursor-pointer appearance-none rounded-lg border border-gray-200 bg-gray-50 py-1 pl-2.5 pr-7 text-[12.5px] text-gray-700 outline-none transition-colors hover:border-gray-300 focus:border-teal-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-gray-200"
                        >
                            <option value="" disabled>
                                Switch document…
                            </option>
                            {documents.map((doc) => (
                                <option key={doc.id} value={doc.id}>
                                    {doc.filename}
                                </option>
                            ))}
                        </select>
                        <ChevronDownIcon width={12} height={12} className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-gray-400" />
                    </div>
                </div>
            )}

            <div className="flex flex-1 flex-col overflow-y-auto px-6 py-6">
                {needsDocumentChoice && documents.length > 0 && (
                    <div className="m-auto w-full max-w-sm text-center">
                        <span className="mx-auto mb-3.5 flex h-11 w-11 items-center justify-center rounded-full bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-400">
                            <SparkleIcon width={20} height={20} />
                        </span>
                        <p className="mb-4 text-[13.5px] leading-relaxed text-gray-400 dark:text-gray-500">
                            Which document do you want to ask about?
                        </p>
                        <ul className="flex flex-col gap-1.5 text-left">
                            {documents.map((doc) => (
                                <li key={doc.id}>
                                    <button
                                        type="button"
                                        onClick={() => onSelectDocument(doc.id)}
                                        className="w-full cursor-pointer rounded-xl border border-gray-200 px-3.5 py-2.5 text-[13px] text-gray-700 transition-colors hover:border-teal-600 hover:bg-teal-50 dark:border-neutral-700 dark:text-gray-200 dark:hover:border-teal-500 dark:hover:bg-teal-500/10"
                                    >
                                        {doc.filename}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {messages.length === 0 && !needsDocumentChoice && (
                    <div className="m-auto max-w-xs text-center text-gray-400 dark:text-gray-500">
                        <span className="mx-auto mb-3.5 flex h-11 w-11 items-center justify-center rounded-full bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-400">
                            <SparkleIcon width={20} height={20} />
                        </span>
                        <p className="text-[13.5px] leading-relaxed">
                            Ask a question about {selectedDocument ? `"${selectedDocument.filename}"` : 'this document'} — I&apos;ll answer only from what&apos;s in it.
                        </p>
                    </div>
                )}

                {messages.map((msg, i) => {
                    const isUser = msg.role === 'user';
                    const isStreamingPlaceholder = loading && i === messages.length - 1 && !isUser && !msg.content;

                    return (
                        <div key={i}>
                            <div className={`mb-3.5 flex max-w-[78%] animate-message-in items-end gap-2 ${isUser ? 'ml-auto flex-row-reverse' : ''}`}>
                                {!isUser && (
                                    <span className="flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-full bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-400">
                                        <SparkleIcon width={13} height={13} />
                                    </span>
                                )}
                                <div
                                    className={`min-w-0 rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed ${
                                        isUser
                                            ? 'rounded-br-md bg-teal-700 text-white'
                                            : 'rounded-bl-md border border-gray-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900'
                                    }`}
                                >
                                    {isStreamingPlaceholder ? (
                                        <span className="inline-flex items-center gap-1 py-0.5">
                                            <span className="h-1.5 w-1.5 animate-typing-bounce rounded-full bg-gray-400 dark:bg-gray-500" />
                                            <span className="h-1.5 w-1.5 animate-typing-bounce rounded-full bg-gray-400 [animation-delay:0.15s] dark:bg-gray-500" />
                                            <span className="h-1.5 w-1.5 animate-typing-bounce rounded-full bg-gray-400 [animation-delay:0.3s] dark:bg-gray-500" />
                                        </span>
                                    ) : (
                                        <p className="m-0 whitespace-pre-wrap break-words">{msg.content}</p>
                                    )}
                                </div>
                            </div>

                            {!isUser && msg.sources && msg.sources.length > 0 && (
                                <div className="mb-1 ml-[34px]">
                                    <SourcesPanel sources={msg.sources} />
                                </div>
                            )}
                        </div>
                    );
                })}

                <div ref={bottomRef} />
            </div>

            <form className="flex items-end gap-2.5 border-t border-gray-200 bg-white px-5 py-4 dark:border-neutral-800 dark:bg-neutral-900" onSubmit={handleSend}>
                <textarea
                    ref={textareaRef}
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    onKeyDown={handleKeyDown}
                    placeholder={needsDocumentChoice ? 'Pick a document above to start…' : 'Ask a question… (Shift+Enter for a new line)'}
                    disabled={loading || needsDocumentChoice}
                    rows={1}
                    className="max-h-40 flex-1 resize-none overflow-y-auto rounded-2xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm leading-relaxed text-gray-900 outline-none transition-colors placeholder:text-gray-400 focus:border-teal-600 focus:bg-white dark:border-neutral-700 dark:bg-neutral-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:bg-neutral-900 disabled:cursor-not-allowed disabled:opacity-60"
                />
                <button
                    type="submit"
                    disabled={loading || needsDocumentChoice || !input.trim()}
                    className="flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full bg-teal-700 text-white transition-colors hover:bg-teal-800 active:scale-95 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400 dark:disabled:bg-neutral-700 dark:disabled:text-neutral-500"
                >
                    <SendIcon width={16} height={16} />
                </button>
            </form>
        </div>
    );
}
