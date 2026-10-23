import React, { useState } from 'react';
import { ChevronDownIcon } from './Icons';

export default function SourcesPanel({ sources }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="mt-2 max-w-[78%]">
            <button
                className="inline-flex cursor-pointer items-center gap-1 py-1 text-xs font-medium text-gray-500 hover:text-teal-700 dark:text-gray-400 dark:hover:text-teal-400"
                onClick={() => setOpen(!open)}
            >
                {open ? 'Hide sources' : `Show sources (${sources.length})`}
                <ChevronDownIcon width={13} height={13} className={`transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <ul className="mt-1.5 flex flex-col gap-1.5">
                    {sources.map((source, i) => (
                        <li
                            key={i}
                            className="rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-800/60"
                        >
                            <div className="mb-1 flex items-center gap-1.5 font-semibold text-gray-800 dark:text-gray-200">
                                <span className="min-w-0 truncate">{source.filename}</span>
                                {source.score != null && (
                                    <span className="ml-auto shrink-0 rounded-full bg-teal-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-teal-700 dark:bg-teal-500/10 dark:text-teal-400">
                                        {(source.score * 100).toFixed(0)}% match
                                    </span>
                                )}
                            </div>
                            <p className="m-0 leading-relaxed text-gray-500 dark:text-gray-400">{source.excerpt}…</p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
