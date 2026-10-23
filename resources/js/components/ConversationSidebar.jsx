import React, { useState, useEffect, useCallback } from 'react';
import { PlusIcon, MessageSquareIcon } from './Icons';

// Groups conversations the same way ChatGPT's history sidebar does, so
// recency is scannable at a glance instead of just a flat list.
function groupByRecency(conversations) {
    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const sevenDaysAgo = new Date(startOfToday);
    sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);

    const groups = { Today: [], 'Previous 7 days': [], Older: [] };

    for (const conversation of conversations) {
        const updatedAt = new Date(conversation.updated_at);

        if (updatedAt >= startOfToday) {
            groups.Today.push(conversation);
        } else if (updatedAt >= sevenDaysAgo) {
            groups['Previous 7 days'].push(conversation);
        } else {
            groups.Older.push(conversation);
        }
    }

    return Object.entries(groups).filter(([, items]) => items.length > 0);
}

export default function ConversationSidebar({ activeSessionUuid, onSelectConversation, onNewChat, refreshKey }) {
    const [conversations, setConversations] = useState([]);

    const fetchConversations = useCallback(async () => {
        try {
            const res = await fetch('/api/conversations');
            const data = await res.json();
            setConversations(data.conversations || []);
        } catch (err) {
            // Non-critical — the chat itself still works without history loaded.
        }
    }, []);

    useEffect(() => {
        fetchConversations();
    }, [fetchConversations, refreshKey]);

    const groups = groupByRecency(conversations);

    return (
        <div className="flex h-full flex-col">
            <button
                type="button"
                onClick={onNewChat}
                className="mb-4 flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-teal-700 px-3 py-2.5 text-[13.5px] font-medium text-teal-700 transition-colors hover:bg-teal-50 active:scale-[0.98] dark:border-teal-500 dark:text-teal-400 dark:hover:bg-teal-500/10"
            >
                <PlusIcon width={15} height={15} />
                New chat
            </button>

            <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">History</p>

            <div className="flex-1 overflow-y-auto">
                {groups.length === 0 && (
                    <p className="px-1 text-[12.5px] text-gray-400 dark:text-gray-500">No conversations yet — ask a question to start one.</p>
                )}

                {groups.map(([label, items]) => (
                    <div key={label} className="mb-3">
                        <p className="mb-1 px-1 text-[10.5px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{label}</p>
                        <ul className="flex flex-col gap-0.5">
                            {items.map((conversation) => {
                                const isActive = conversation.session_uuid === activeSessionUuid;

                                return (
                                    <li key={conversation.session_uuid}>
                                        <button
                                            type="button"
                                            onClick={() => onSelectConversation(conversation.session_uuid, conversation.document_id)}
                                            className={`flex w-full cursor-pointer items-start gap-2 rounded-lg px-2.5 py-2 text-left text-[13px] transition-colors ${
                                                isActive
                                                    ? 'bg-teal-50 text-teal-800 dark:bg-teal-500/15 dark:text-teal-300'
                                                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-neutral-800/60'
                                            }`}
                                        >
                                            <MessageSquareIcon width={14} height={14} className="mt-0.5 shrink-0 opacity-60" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate">{conversation.title}</span>
                                                {conversation.document_filename && (
                                                    <span className="block truncate text-[10.5px] opacity-60">{conversation.document_filename}</span>
                                                )}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </div>
        </div>
    );
}
