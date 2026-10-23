import React from 'react';

// Small shared set of inline SVG icons (24x24 viewBox, stroke-based) used
// across the sidebar and chat UI — avoids pulling in an icon library for a
// handful of glyphs.

const base = {
    width: 16,
    height: 16,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 2,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
};

export function FileTextIcon(props) {
    return (
        <svg {...base} {...props}>
            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
            <path d="M14 2v6h6" />
            <path d="M9 13h6" />
            <path d="M9 17h6" />
        </svg>
    );
}

export function CheckCircleIcon(props) {
    return (
        <svg {...base} {...props}>
            <circle cx="12" cy="12" r="10" />
            <path d="m8.5 12.5 2.5 2.5 5-5" />
        </svg>
    );
}

export function XCircleIcon(props) {
    return (
        <svg {...base} {...props}>
            <circle cx="12" cy="12" r="10" />
            <path d="m9.5 9.5 5 5" />
            <path d="m14.5 9.5-5 5" />
        </svg>
    );
}

export function LoaderIcon(props) {
    return (
        <svg {...base} className={`icon-spin ${props.className || ''}`} {...props}>
            <path d="M12 2a10 10 0 1 0 10 10" />
        </svg>
    );
}

export function SendIcon(props) {
    return (
        <svg {...base} {...props}>
            <path d="m3 11 18-8-8 18-2-8-8-2Z" />
        </svg>
    );
}

export function ChevronDownIcon(props) {
    return (
        <svg {...base} {...props}>
            <path d="m6 9 6 6 6-6" />
        </svg>
    );
}

export function SparkleIcon(props) {
    return (
        <svg {...base} {...props}>
            <path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8" />
        </svg>
    );
}

export function PlusIcon(props) {
    return (
        <svg {...base} {...props}>
            <path d="M12 5v14M5 12h14" />
        </svg>
    );
}

export function MessageSquareIcon(props) {
    return (
        <svg {...base} {...props}>
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z" />
        </svg>
    );
}

export function BuildingIcon(props) {
    return (
        <svg {...base} {...props}>
            <path d="M4 22V8l8-5 8 5v14" />
            <path d="M2 22h20" />
            <path d="M9 22v-5a3 3 0 0 1 6 0v5" />
            <path d="M8 11h.01M16 11h.01M8 15h.01M16 15h.01" />
        </svg>
    );
}
