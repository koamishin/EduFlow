<style>
    @layer components {
        .arc-shell[x-cloak],
        .arc-shell [x-cloak] {
            display: none !important;
        }

        /* Cohesive Theme Tokens */
        .arc-shell {
            --arc-bg: #ffffff;
            --arc-sidebar: #f7f7f9;
            --arc-border: #e4e4e7;
            --arc-card: #f9f9fb;
            --arc-text: #18181b;
            --arc-muted: #71717a;
            --arc-user-bg: #18181b;
            --arc-user-text: #ffffff;
            --arc-user-border: #18181b;
            --arc-avatar-bg: #18181b;
            --arc-avatar-text: #ffffff;
            --arc-avatar-border: #27272a;
            --arc-assistant-avatar-bg: #18181b;
            --arc-assistant-avatar-text: #ffffff;
            --arc-input-bg: #ffffff;
            --arc-input-border: #e4e4e7;
            --arc-input-focus: #a1a1aa;
        }

        .dark .arc-shell,
        .arc-shell.dark {
            --arc-bg: #09090b;
            --arc-sidebar: #111113;
            --arc-border: #222226;
            --arc-card: #151518;
            --arc-text: #f4f4f5;
            --arc-muted: #8e8e93;
            --arc-user-bg: #222226;
            --arc-user-text: #f4f4f5;
            --arc-user-border: #2f2f35;
            --arc-avatar-bg: #222226;
            --arc-avatar-text: #f4f4f5;
            --arc-avatar-border: #2f2f35;
            --arc-assistant-avatar-bg: #16161a;
            --arc-assistant-avatar-text: #f4f4f5;
            --arc-input-bg: #131316;
            --arc-input-border: #222226;
            --arc-input-focus: #3f3f46;
        }

        .arc-shell {
            background-color: var(--arc-bg);
            color: var(--arc-text);
        }

        .arc-shell .arc-workspace {
            background-color: var(--arc-bg);
        }

        .arc-shell .arc-sidebar {
            background-color: var(--arc-sidebar);
            border-color: var(--arc-border);
        }

        .arc-shell .arc-header {
            background-color: var(--arc-sidebar);
            border-color: var(--arc-border);
        }

        .arc-shell .arc-user-bubble {
            background-color: var(--arc-user-bg);
            color: var(--arc-user-text);
            border: 1px solid var(--arc-user-border);
        }

        .arc-shell .arc-user-avatar {
            background-color: var(--arc-avatar-bg);
            color: var(--arc-avatar-text);
            border: 1px solid var(--arc-avatar-border);
        }

        .arc-shell .arc-assistant-avatar {
            background-color: var(--arc-assistant-avatar-bg);
            color: var(--arc-assistant-avatar-text);
            border: 1px solid var(--arc-border);
        }

        .arc-shell .arc-composer-card {
            background-color: var(--arc-input-bg);
            border: 1px solid var(--arc-input-border);
        }

        .arc-shell .arc-composer-card:focus-within {
            border-color: var(--arc-input-focus);
        }

        .arc-shell .arc-card {
            background-color: var(--arc-card);
            border: 1px solid var(--arc-border);
        }

        .arc-shell .arc-footer-dock {
            background-color: var(--arc-bg);
            border-color: var(--arc-border);
        }

        /* Subtle entrance transitions */
        .arc-shell .welcome-logo,
        .arc-shell .welcome-greeting,
        .arc-shell .welcome-input,
        .arc-shell .welcome-suggestions {
            animation: welcome-enter 0.2s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        .arc-shell .welcome-greeting {
            animation-delay: 30ms;
        }

        .arc-shell .welcome-input {
            animation-delay: 60ms;
        }

        .arc-shell .welcome-suggestions {
            animation-delay: 90ms;
        }

        @keyframes welcome-enter {
            from {
                opacity: 0;
                transform: translateY(6px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Minimal thinking dots */
        .arc-shell .thinking-dot {
            display: inline-block;
            width: 3.5px;
            height: 3.5px;
            border-radius: 9999px;
            background-color: currentColor;
            animation: thinking-dot-pulse 1.2s infinite ease-in-out both;
        }
        .arc-shell .thinking-dot:nth-child(1) {
            animation-delay: -0.32s;
        }
        .arc-shell .thinking-dot:nth-child(2) {
            animation-delay: -0.16s;
        }
        .arc-shell .thinking-dot:nth-child(3) {
            animation-delay: 0s;
        }

        @keyframes thinking-dot-pulse {
            0%, 80%, 100% {
                transform: scale(0.7);
                opacity: 0.3;
            }
            40% {
                transform: scale(1.15);
                opacity: 0.9;
            }
        }

        /* Editorial prose styling for assistant markdown */
        .arc-shell .prose pre {
            background-color: #161619;
            border: 1px solid #27272a;
            border-radius: 0.75rem;
            padding: 0.875rem 1rem;
            color: #f4f4f5;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.8125rem;
            line-height: 1.5;
            overflow-x: auto;
        }

        .arc-shell .prose code:not(pre code) {
            background-color: rgba(120, 120, 128, 0.14);
            padding: 0.125rem 0.375rem;
            border-radius: 0.375rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.8125rem;
            font-weight: 500;
        }

        .arc-shell .prose table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
            margin-top: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .arc-shell .prose th {
            border-bottom: 1px solid #27272a;
            padding: 0.5rem 0.75rem;
            text-align: left;
            font-weight: 600;
        }

        .arc-shell .prose td {
            border-bottom: 1px solid #222226;
            padding: 0.5rem 0.75rem;
        }

        /* Clean custom scrollbar */
        .arc-shell::-webkit-scrollbar,
        .arc-shell ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        .arc-shell::-webkit-scrollbar-track,
        .arc-shell ::-webkit-scrollbar-track {
            background: transparent;
        }
        .arc-shell::-webkit-scrollbar-thumb,
        .arc-shell ::-webkit-scrollbar-thumb {
            background: rgba(120, 120, 128, 0.2);
            border-radius: 9999px;
        }
        .arc-shell::-webkit-scrollbar-thumb:hover,
        .arc-shell ::-webkit-scrollbar-thumb:hover {
            background: rgba(120, 120, 128, 0.35);
        }

        @media (prefers-reduced-motion: reduce) {
            .arc-shell .welcome-logo,
            .arc-shell .welcome-greeting,
            .arc-shell .welcome-input,
            .arc-shell .welcome-suggestions {
                animation: none;
            }
            .arc-shell .thinking-dot,
            .arc-shell,
            .arc-shell::before,
            .arc-shell::after,
            .arc-shell *,
            .arc-shell *::before,
            .arc-shell *::after {
                animation-duration: 0.01ms;
                animation-iteration-count: 1;
                transition-duration: 0.01ms;
            }
        }
    }
</style>
