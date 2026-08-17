<?php

declare(strict_types=1);

// User-facing labels for the plugin's Filament admin resources: navigation,
// model names, table columns, filters and bespoke actions. Generic Filament
// chrome (Edit / Delete / Create / trashed filter, …) is translated by Filament
// core and is intentionally not duplicated here.
return [
    'group' => 'Magna Blog',

    'post' => [
        'label' => 'post',
        'plural' => 'Posts',
        'navigation' => 'Posts',
        'nav' => [
            'all' => 'All Posts',
            'create' => 'Create post',
            'drafts' => 'Drafts',
        ],
        'columns' => [
            'category' => 'Category',
            'author' => 'Author',
            'featured' => 'Featured',
            'views' => 'Views',
        ],
        'actions' => [
            'export_csv' => 'Export to CSV',
        ],
    ],

    'category' => [
        'label' => 'category',
        'plural' => 'Categories',
        'navigation' => 'Categories',
        'nav' => [
            'all' => 'All Categories',
            'new' => 'New Category',
        ],
        'columns' => [
            'parent' => 'Parent',
            'posts' => 'Posts',
        ],
    ],

    'tag' => [
        'label' => 'tag',
        'plural' => 'Tags',
        'navigation' => 'Tags',
        'nav' => [
            'all' => 'All Tags',
            'new' => 'New Tag',
        ],
        'columns' => [
            'posts' => 'Posts',
        ],
    ],

    'series' => [
        'label' => 'series',
        'plural' => 'Series',
        'navigation' => 'Series',
        'nav' => [
            'all' => 'All Series',
            'new' => 'New Series',
        ],
        'columns' => [
            'parts' => 'Parts',
        ],
    ],

    'comment' => [
        'label' => 'comment',
        'plural' => 'Comments',
        'navigation' => 'Comments',
        'nav' => [
            'all' => 'All Comments',
            'pending' => 'Pending',
            'spam' => 'Spam',
        ],
        'columns' => [
            'post' => 'Post',
            'author' => 'Author',
        ],
        'actions' => [
            'approve' => 'Approve',
            'spam' => 'Mark as spam',
        ],
    ],
];
