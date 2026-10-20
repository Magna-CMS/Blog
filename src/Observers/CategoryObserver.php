<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Observers;

use Illuminate\Contracts\Events\Dispatcher;
use Magna\Frontend\Events\PublicUrlsMoved;
use Magna\Frontend\UrlMove;
use MagnaCms\Blog\Listeners\PurgePublicBlogCache;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Support\DefaultCategory;
use MagnaCms\Blog\Support\PostUrlHistory;

/**
 * Keeps the "Uncategorised" fallback intact: it can never be deleted, and when
 * any other category is removed its posts are re-filed under the fallback rather
 * than left with a null category (mirroring WordPress).
 *
 * Also the public side of a category write. Its name shows on post pages and
 * its slug may be part of every post URL under it, so any change purges the
 * blog's cached pages, and a slug change — a rename, or the re-file a delete
 * performs with a query-builder update no Post observer sees — announces the
 * URLs it moved.
 */
class CategoryObserver
{
    public function __construct(
        private readonly PurgePublicBlogCache $purge,
        private readonly PostUrlHistory $history,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Server-side safety net against a cyclic hierarchy. The parent picker already
     * hides the category's own subtree, but a forged Livewire request or an import
     * could still set parent_id to self or a descendant. Rather than persist a
     * cycle (which would make tree traversal recurse forever), silently drop back
     * to the previously stored parent. Runs for every write path, not just the form.
     */
    public function saving(Category $category): void
    {
        $parentId = $category->parent_id;

        if ($parentId === null || ! $category->exists) {
            return;
        }

        if ((int) $parentId === (int) $category->getKey()
            || in_array((int) $parentId, $category->descendantIds(), true)) {
            $category->parent_id = $category->getOriginal('parent_id');
        }
    }

    public function updated(Category $category): void
    {
        if ($category->wasChanged('slug')) {
            $this->announce($this->history->movesForCategorySlug(
                (int) $category->getKey(),
                (string) $category->getOriginal('slug'),
                (string) $category->slug,
            ));
        }

        $this->purge->allPosts();
    }

    public function deleting(Category $category): bool
    {
        if (DefaultCategory::isDefault($category)) {
            return false; // The default category is not deletable.
        }

        $fallbackId = DefaultCategory::uncategorisedId();

        // Worked out before the re-file below, which is a query-builder
        // update: it fires no model events, and afterwards nothing remembers
        // which posts were under this category.
        $moves = $this->history->movesForCategorySlug(
            (int) $category->getKey(),
            (string) $category->slug,
            (string) Category::query()->whereKey($fallbackId)->value('slug'),
        );

        // Re-file this category's posts (and any child categories' parent link is
        // handled by the FK) before the row goes away.
        $category->posts()->update(['category_id' => $fallbackId]);

        $this->announce($moves);
        $this->purge->allPosts();

        return true;
    }

    /** @param  list<UrlMove>  $moves */
    private function announce(array $moves): void
    {
        if ($moves !== []) {
            $this->events->dispatch(new PublicUrlsMoved($moves, 'magna-cms/blog'));
        }
    }
}
