<?php

declare(strict_types=1);

return [
    /*
     * Comment spam defences for the public submission endpoint. The honeypot is
     * always on (a hidden field a real user never fills); an optional Akismet
     * driver adds content scoring when a key is configured.
     */
    'comments' => [
        // Request field a bot tends to fill but a human never sees; any value = spam.
        'honeypot_field' => env('BLOG_COMMENT_HONEYPOT', 'website'),

        // Minimum seconds between a form being shown and submitted. When the client
        // sends `form_started_at` (unix seconds), a faster submit is treated as a
        // bot. Zero disables the timing check.
        'min_submit_seconds' => (int) env('BLOG_COMMENT_MIN_SECONDS', 2),

        // Spam driver: 'honeypot' (default) or 'akismet'. Akismet also keeps the
        // honeypot; it only adds remote content scoring.
        'spam_driver' => env('BLOG_COMMENT_SPAM_DRIVER', 'honeypot'),

        'akismet' => [
            'key' => env('AKISMET_KEY'),
            'site' => env('AKISMET_SITE', env('APP_URL')),
        ],
    ],

    /*
     * Anonymous post reactions. Empty `types` disables the feature (the endpoint
     * 404s and no counts are exposed). Keep the list short and stable — each type
     * is a column-less tally keyed by name.
     */
    'reactions' => [
        'types' => ['like', 'love', 'clap', 'insightful'],
    ],

    /*
     * Localisation. `default` is the fallback locale for new posts and the
     * `locale` column default; the admin's Blog settings "Default language"
     * overrides it at runtime. The full language list lives in Support\Locales.
     */
    'locales' => [
        'default' => env('BLOG_DEFAULT_LOCALE', 'en'),
    ],

    'editor' => [
        /*
         * Block/inline tools enabled in the Editor.js instance, in toolbar order.
         * Names map to the JavaScript tool registry (window.magnaBlog.tools).
         * Blog settings can narrow this list at runtime; other plugins can add
         * new tool classes via window.magnaBlog.registerTool() without editing
         * this plugin.
         */
        'tools' => [
            'paragraph',
            'header',
            'list',
            'checklist',
            'quote',
            'table',
            'code',
            'delimiter',
            'image',
            'embed',
            'link',
            'attaches',
            'warning',
            'raw',
            'inlineCode',
            'marker',
            'underline',
            'strikethrough',
            'subscript',
            'superscript',
            'textColor',
            'preformatted',
            'verse',
            'spacer',
            'pullquote',
            'buttons',
            'more',
            'pageBreak',
            'callout',
            'cta',
            'faq',
            'details',
            'socialIcons',
            'mediaText',
            'footnotes',
            'rss',
            'gallery',
            'cover',
            'video',
            'audio',
            'toc',
            'relatedPosts',
            'postExcerpt',
            'featuredImage',
            'map',
            'columns',
            'group',
        ],
    ],
];
