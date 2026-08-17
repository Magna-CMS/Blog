<?php

declare(strict_types=1);

// Transient, user-facing feedback shown by the full-screen post builder
// (notifications and the published toast). The builder's form-field labels are
// rendered by Filament from the shared sidebar schema and follow the base
// locale.
return [
    'notifications' => [
        'title_required' => 'Add a title first.',
        'draft_saved' => 'Draft saved',
        'published' => 'Post published',
        'updated' => 'Post updated',
        'submitted_for_review' => 'Submitted for review',
        'sent_back' => 'Sent back for changes',
        'trashed' => 'Post moved to trash',
    ],
];
