<?php

declare(strict_types=1);

// Human-readable labels for the plugin's backed enums. Keys mirror each enum's
// stored value so a case resolves its label directly from its value.
return [
    'post_status' => [
        'draft' => 'Draft',
        'pending_review' => 'Pending review',
        'published' => 'Published',
        'scheduled' => 'Scheduled',
        'archived' => 'Archived',
    ],

    'post_visibility' => [
        'public' => 'Public',
        'private' => 'Private',
        'password' => 'Password protected',
    ],

    'comment_status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'spam' => 'Spam',
    ],

    'meta_type' => [
        'string' => 'Text',
        'integer' => 'Number',
        'boolean' => 'True / false',
        'json' => 'JSON',
        'date' => 'Date',
    ],
];
