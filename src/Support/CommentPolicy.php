<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

/**
 * Decides whether comments may be posted on a given post, combining the global
 * Blog settings with the per-post toggle. Backend-only: the actual comment form
 * is a frontend concern, but the delivery API surfaces these flags so a frontend
 * knows what to render.
 */
final class CommentPolicy
{
    public function open(Post $post): bool
    {
        $settings = BlogSettings::get();

        if (! $settings->enable_comments || ! $post->allow_comments) {
            return false;
        }

        $days = $settings->comments_close_after_days;
        if ($days > 0 && $post->published_at !== null && $post->published_at->copy()->addDays($days)->isPast()) {
            return false;
        }

        return true;
    }

    public function requiresLogin(): bool
    {
        return BlogSettings::get()->require_login_to_comment;
    }
}
