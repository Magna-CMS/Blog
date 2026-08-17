<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostAccess;

/**
 * Laravel-native authorization surface for a Post. Every ability delegates to
 * PostAccess, so there is exactly ONE source of truth for post authorization:
 * PostAccess holds the rule (permission + ownership), and this policy is the
 * idiomatic Gate / authorize() / can() entry point onto it. Registered on the
 * Gate in BlogPlugin::boot().
 *
 * Only per-record abilities live here; collection-level checks (viewAny/create)
 * are permission-only and stay on the Filament resource so no rule is duplicated.
 */
class PostPolicy
{
    public function view(?Authenticatable $user, Post $post): bool
    {
        return PostAccess::canView($user, $post);
    }

    public function update(?Authenticatable $user, Post $post): bool
    {
        return PostAccess::canEdit($user, $post);
    }

    public function delete(?Authenticatable $user, Post $post): bool
    {
        return PostAccess::canDelete($user, $post);
    }

    public function restore(?Authenticatable $user, Post $post): bool
    {
        return PostAccess::canRestore($user, $post);
    }

    public function publish(?Authenticatable $user, Post $post): bool
    {
        return PostAccess::canPublish($user, $post);
    }
}
