<?php

/**
 * The in-house classroom stream.
 *
 * The limits here are the ones that keep a school's disk and its page loads
 * under control; they are settings rather than constants because what a school
 * can afford to store is the school's business.
 */
return [
    // Files live on a private disk and are served by a controller that checks
    // who is asking. Never point this at the `public` disk.
    'disk' => env('CLASSROOM_DISK', 'local'),

    'uploads' => [
        // Kilobytes, matching Laravel's own `max:` rule.
        'max_size' => (int) env('CLASSROOM_MAX_UPLOAD_KB', 20480), // 20 MB
        'max_per_post' => (int) env('CLASSROOM_MAX_FILES_PER_POST', 10),

        // Extensions a school actually shares. Anything executable is absent on
        // purpose: an attachment is read, never run.
        'extensions' => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
            'txt', 'csv', 'rtf',
            'png', 'jpg', 'jpeg', 'webp', 'gif',
            'mp3', 'mp4', 'zip',
        ],
    ],

    'stream' => [
        // One screenful. The stream is read by cursor, so this is the cost of a
        // page no matter how many thousands of posts sit behind it.
        'page_size' => (int) env('CLASSROOM_PAGE_SIZE', 20),

        // Shown under each post; the rest are fetched on demand.
        'comment_preview' => 3,

        'max_body_length' => 20000,
        'max_comment_length' => 2000,
    ],
];
