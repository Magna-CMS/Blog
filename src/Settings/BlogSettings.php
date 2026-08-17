<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Settings;

use Magna\Settings\Settings;

/**
 * Backend/content settings for the Blog plugin. Frontend concerns (SEO, themes,
 * routing) are deliberately excluded — the permalink pattern is stored only as a
 * hint for future frontend plugins.
 */
class BlogSettings extends Settings
{
    // General
    public string $default_status = 'draft';

    public ?string $default_author_id = null;

    public int $posts_per_page = 15;

    /** Default language for new posts; pre-selected in the builder's language picker. */
    public string $default_locale = 'en';

    public bool $enable_scheduling = true;

    public bool $enable_revisions = true;

    public int $max_revisions = 20;

    // Slugs
    public bool $auto_generate_slug = true;

    public bool $allow_manual_slug = true;

    // Permalinks — stored for future frontend plugins; no routing here.
    public string $permalink_pattern = '/blog/{slug}';

    // Editor
    public int $autosave_interval = 5;

    /**
     * User-uploaded fonts available in the editor's font pickers. Each entry:
     * ['key' => string, 'label' => string, 'url' => string, 'format' => string].
     *
     * @var array<int, array<string, string>>
     */
    public array $custom_fonts = [];

    /**
     * Meta keys exposed through the delivery API. Custom fields are private by
     * default; only keys listed here (unioned with registry-declared public keys)
     * are ever serialised to the public post payload.
     *
     * @var list<string>
     */
    public array $public_meta = [];

    // Content
    public ?int $default_category_id = null;

    public bool $allow_empty_excerpt = true;

    public bool $generate_reading_time = true;

    // Comments — backend flags only; the comment submission UI is a frontend concern.
    public bool $enable_comments = true;

    public bool $require_login_to_comment = false;

    /** How new comments are handled: 'pending' (hold for moderation) or 'auto' (auto-approve). */
    public string $comment_moderation = 'pending';

    /** Auto-close comments this many days after publish. 0 = never close. */
    public int $comments_close_after_days = 0;
}
