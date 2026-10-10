<?php

return [
    // Required outside local/testing; never choose an institution by row order.
    'institution_id' => env('EDUFLOW_INSTITUTION_ID'),

    'background_finance' => [
        'enabled' => env('EDUFLOW_BACKGROUND_FINANCE', false),
        'queue_connection' => env('EDUFLOW_FINANCE_QUEUE_CONNECTION', 'database'),
        'queue' => 'finance-planning',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Submission Runtime
    |--------------------------------------------------------------------------
    |
    | Durable submission work is recorded the moment a payment is authorized,
    | but nothing is ever handed to a worker unless an operator turns this on.
    | Enabling it is a separate, reviewed decision because it is the first step
    | toward an executor that talks to a rail, and the release it points at.
    |
    | The isolated executor ships in this build but is gated behind this flag,
    | and behind an explicit Arc *testnet* rail check with no fallback. A
    | dispatched entry with the runtime off is inspected and concluded as
    | blocked with a specific reason; it is never submitted.
    |
    | `stop_switch` holds new submissions without erasing evidence or
    | releasing a hold.
    |
    */

    'submission' => [
        'enabled' => env('EDUFLOW_SUBMISSION_ENABLED', false),
        'queue_connection' => env('EDUFLOW_SUBMISSION_QUEUE_CONNECTION', 'database'),
        'queue' => 'finance-submission',
        'stop_switch' => env('EDUFLOW_SUBMISSION_STOP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dual-Currency Display
    |--------------------------------------------------------------------------
    |
    | Settlement always happens in USDC base units. Staff-facing screens also
    | show a local-currency equivalent so a reviewer can see the real value
    | of what they are authorising. The rate used is the locked quote stored
    | on the agent decision, never a live rate.
    |
    */
    'display_currency' => env('EDUFLOW_DISPLAY_CURRENCY', 'PHP'),
    'max_rate_age_seconds' => env('EDUFLOW_MAX_RATE_AGE_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Campus Quick Resources
    |--------------------------------------------------------------------------
    |
    | Rendered as cards beneath "My Assistance Requests" on the student
    | dashboard. A resource only becomes clickable once its "url" is set;
    | entries without one render as a muted, non-interactive placeholder
    | rather than a link that leads nowhere. This keeps the layout stable
    | without shipping dead links or invented destinations.
    |
    | "icon" must be a kebab-case Lucide icon name. Unknown names fall
    | back to a generic icon on the client, so adding a new resource
    | never breaks the build.
    |
    */

    'resources' => [
        [
            'title' => 'Academic Library',
            'description' => 'Access research journals, past exams, and e-books.',
            'url' => null,
            'icon' => 'book-open',
        ],
        [
            'title' => 'Academic Calendar',
            'description' => 'Check term milestones, holidays, and exam schedules.',
            'url' => null,
            'icon' => 'calendar',
        ],
        [
            'title' => 'IT & LMS Guides',
            'description' => 'Self-help setup guides for Wi-Fi and student portal.',
            'url' => null,
            'icon' => 'help-circle',
        ],
        [
            'title' => 'Office Hours',
            'description' => 'Book consultation slots with advisors and faculty.',
            'url' => null,
            'icon' => 'message-square',
        ],
    ],
];
