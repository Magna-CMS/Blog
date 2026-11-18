<?php

declare(strict_types=1);

namespace MagnaCms\Blog\SiteKit;

use Illuminate\Support\Facades\Storage;
use Magna\Contracts\RemovesSiteKitRows;
use MagnaCms\Blog\Models\Post;

/**
 * Posts that can also leave with a theme's demo content.
 *
 * Magna core hands over only posts the site still has exactly as the theme
 * shipped them, so each one is reproducible: importing the demo again brings
 * it back. That is why they are deleted outright rather than trashed — a
 * trashed post still holds its slug, so a trashed demo post would block both
 * that re-import and the next theme's post of the same name. A featured
 * image goes with its post unless another post still uses the file.
 * Categories and tags stay; the site's own posts may use them.
 *
 * A separate class so BlogPlugin can register it only when the SDK on this
 * site defines RemovesSiteKitRows, and keep running on one that does not.
 */
class RemovablePostsSection extends PostsSection implements RemovesSiteKitRows
{
    public function keep(array $row): ?string
    {
        return null;
    }

    public function remove(array $rows, ?string $actorId = null): int
    {
        $removed = 0;

        foreach ($rows as $row) {
            $slug = $row['slug'] ?? null;
            $post = is_string($slug) && $slug !== '' ? Post::withTrashed()->where('slug', $slug)->first() : null;

            if ($post === null) {
                continue;
            }

            $image = $post->featured_image;
            $post->tags()->detach();
            $post->forceDelete();
            $removed++;

            if (is_string($image) && $image !== '' && ! Post::withTrashed()->where('featured_image', $image)->exists()) {
                Storage::disk('public')->delete($image);
            }
        }

        return $removed;
    }
}
