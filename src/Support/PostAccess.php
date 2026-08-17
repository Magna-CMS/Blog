<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use MagnaCms\Blog\Models\Post;

/**
 * Central, ownership-aware authorization for a single post. Every server-side
 * gate (Filament resource abilities, the builder's Livewire actions, the status
 * clamp) resolves through here so the rule lives in exactly one place:
 *
 *   permission  +  (owns the post OR holds blog.posts.edit-others)
 *
 * Super admins bypass all of this via the core Gate::before hook, so they need
 * no special-casing here — every `can()` returns true for them.
 */
final class PostAccess
{
    /** Holders of edit_others may act on any author's post, not only their own. */
    public static function canManageOthers(?Authenticatable $user): bool
    {
        return $user?->can('blog.posts.edit-others') ?? false;
    }

    /** True when $user is the post's primary author (string-safe ULID compare). */
    public static function owns(?Authenticatable $user, Post $post): bool
    {
        return $user !== null
            && $post->author_id !== null
            && (string) $post->author_id === (string) $user->getAuthIdentifier();
    }

    /**
     * The shared ownership rule behind every per-record ability: the user holds
     * the given permission AND either owns the post or may act on others' posts.
     * Every gate below is this same rule with a different permission, so the rule
     * itself lives in exactly one place.
     */
    private static function hasWithOwnership(?Authenticatable $user, Post $post, string $ability): bool
    {
        return ($user?->can($ability) ?? false)
            && (self::owns($user, $post) || self::canManageOthers($user));
    }

    public static function canEdit(?Authenticatable $user, Post $post): bool
    {
        return self::hasWithOwnership($user, $post, 'blog.posts.edit');
    }

    /**
     * Who may view a single post (e.g. the admin rendered preview):
     *   - a post that is already publicly live is visible to anyone who can see
     *     the resource at all (blog.posts.view), matching the public delivery API;
     *   - an unpublished / scheduled / private / non-public post is visible only
     *     to its owner or an edit_others holder (editor / admin).
     * Same ownership rule as canEdit, so an author can never preview another
     * author's draft while editors and admins still can.
     */
    public static function canView(?Authenticatable $user, Post $post): bool
    {
        if (($user?->can('blog.posts.view') ?? false) === false) {
            return false;
        }

        return $post->isPubliclyVisible()
            || self::owns($user, $post)
            || self::canManageOthers($user);
    }

    public static function canDelete(?Authenticatable $user, Post $post): bool
    {
        return self::hasWithOwnership($user, $post, 'blog.posts.delete');
    }

    public static function canRestore(?Authenticatable $user, Post $post): bool
    {
        return self::hasWithOwnership($user, $post, 'blog.posts.restore');
    }

    /**
     * Publishing needs the publish permission AND authority over this post:
     * an author with publish rights may publish their own work but not another
     * author's; edit_others (editor/admin) lifts that ownership restriction.
     */
    public static function canPublish(?Authenticatable $user, Post $post): bool
    {
        return self::hasWithOwnership($user, $post, 'blog.posts.publish');
    }
}
