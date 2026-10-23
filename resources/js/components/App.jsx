import React, { useState, useEffect } from 'react';
import ConversationSidebar from './ConversationSidebar';
import ChatWindow from './ChatWindow';
import { BuildingIcon } from './Icons';

const SESSION_KEY = 'gpo_rag_session_uuid';

export default function App() {
    const [sessionUuid, setSessionUuid] = useState(null);
    const [documents, setDocuments] = useState([]);
    const [selectedDocumentId, setSelectedDocumentId] = useState(null);
    const [conversationListRefreshKey, setConversationListRefreshKey] = useState(0);

    useEffect(() => {
        // Reuse the last conversation across browser restarts, same as
        // ChatGPT reopening where you left off.
        const existing = localStorage.getItem(SESSION_KEY);
        setSessionUuid(existing || null);

        fetchDocuments();
    }, []);

    // Browser tab title reflects whichever document the chat is currently
    // scoped to, instead of a name baked in at build time.
    useEffect(() => {
        const selected = documents.find((doc) => doc.id === selectedDocumentId);
        document.title = selected ? `${selected.filename} — Document Assistant` : 'Document Assistant';
    }, [documents, selectedDocumentId]);

    const fetchDocuments = async () => {
        const res = await fetch('/api/documents');
        const data = await res.json();
        setDocuments(data.documents || []);
    };

    const handleSessionCreated = (uuid) => {
        localStorage.setItem(SESSION_KEY, uuid);
        setSessionUuid(uuid);
    };

    // Chat is document-scoped — switching conversations switches which
    // document the chat is "about" too, so the header/empty-state stay
    // accurate without a separate lookup.
    const handleSelectConversation = (uuid, documentId) => {
        localStorage.setItem(SESSION_KEY, uuid);
        setSessionUuid(uuid);
        setSelectedDocumentId(documentId ?? null);
    };

    const handleNewChat = () => {
        localStorage.removeItem(SESSION_KEY);
        setSessionUuid(null);
        setSelectedDocumentId(null);
    };

    const handleExchangeComplete = () => {
        setConversationListRefreshKey((key) => key + 1);
    };

    // After loading a conversation's history, ChatWindow reports which
    // document it actually belongs to — selectedDocumentId is plain React
    // state, so a page refresh loses it even though session_uuid survives
    // in localStorage; without this, the composer stayed enabled with no
    // document actually selected.
    const handleDocumentResolved = (documentId) => {
        setSelectedDocumentId(documentId ?? null);
    };

    // The session_uuid in localStorage no longer corresponds to a real
    // conversation (e.g. it was deleted) — fall back to a clean slate
    // instead of leaving the UI in a half-resumed state.
    const handleInvalidSession = () => {
        localStorage.removeItem(SESSION_KEY);
        setSessionUuid(null);
        setSelectedDocumentId(null);
    };

    // Picking a document — whether from the empty-state picker or the
    // always-visible switcher mid-conversation — always starts a fresh
    // conversation: chat is document-scoped, so an existing conversation
    // can't be re-pointed at a different document.
    const handleSelectDocument = (documentId) => {
        localStorage.removeItem(SESSION_KEY);
        setSessionUuid(null);
        setSelectedDocumentId(documentId);
    };

    return (
        <div className="flex h-screen bg-gray-50 text-gray-900 dark:bg-neutral-950 dark:text-gray-100 font-sans">
            <aside className="flex w-72 shrink-0 flex-col overflow-y-auto border-r border-gray-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div className="mb-1 flex items-center gap-2.5">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-teal-700 text-white">
                        <BuildingIcon width={17} height={17} />
                    </span>
                    <h1 className="text-[15px] font-semibold leading-tight">Document Assistant</h1>
                </div>

                <p className="mb-4 text-[11.5px] text-gray-400 dark:text-gray-500">
                    {documents.length > 0
                        ? `${documents.length} document${documents.length === 1 ? '' : 's'} indexed`
                        : <>Not yet indexed — run <code>php artisan document:ingest</code></>}
                </p>

                <ConversationSidebar
                    activeSessionUuid={sessionUuid}
                    onSelectConversation={handleSelectConversation}
                    onNewChat={handleNewChat}
                    refreshKey={conversationListRefreshKey}
                />
            </aside>
            <main className="flex min-w-0 flex-1 flex-col">
                <ChatWindow
                    sessionUuid={sessionUuid}
                    onSessionCreated={handleSessionCreated}
                    onExchangeComplete={handleExchangeComplete}
                    documents={documents}
                    selectedDocumentId={selectedDocumentId}
                    onSelectDocument={handleSelectDocument}
                    onDocumentResolved={handleDocumentResolved}
                    onInvalidSession={handleInvalidSession}
                />
            </main>
        </div>
    );
}
