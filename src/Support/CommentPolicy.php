<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Carbon\CarbonImmutable;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

/**
 * Decides whether comments may be posted on a given post, combining the global
 * Blog settings with the per-post toggle. Both the delivery API and the post
 * page's own comment form (CommentSubmissionHandler) ask it, and the API
 * surfaces the same flags so a headless frontend knows what to render.
 */
final class CommentPolicy
{
    public function open(Post $post): bool
    {
        $settings = BlogSettings::get();

        if (! $settings->enable_comments || ! $post->allow_comments) {
            return false;
        }

        $closesAt = $this->closesAt($post);

        return $closesAt === null || $closesAt->isFuture();
    }

    /**
     * When the "close after N days" rule shuts this post's comments, or null
     * when it never will. A cached post page expires at this moment, so its
     * form disappears on time.
     */
    public function closesAt(Post $post): ?CarbonImmutable
    {
        $days = BlogSettings::get()->comments_close_after_days;

        return $days > 0 && $post->published_at !== null
            ? CarbonImmutable::instance($post->published_at)->addDays($days)
            : null;
    }

    /**
     * Whether only a signed-in user may comment. On the post page's own form
     * that means a Magna panel user — the site has no separate member
     * system; a headless frontend enforces it with whatever identity it has.
     */
    public function requiresLogin(): bool
    {
        return BlogSettings::get()->require_login_to_comment;
    }
}
