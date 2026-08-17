<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Observers;

use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Support\DefaultCategory;

/**
 * Keeps the "Uncategorised" fallback intact: it can never be deleted, and when
 * any other category is removed its posts are re-filed under the fallback rather
 * than left with a null category (mirroring WordPress).
 */
class CategoryObserver
{
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

    public function deleting(Category $category): bool
    {
        if (DefaultCategory::isDefault($category)) {
            return false; // The default category is not deletable.
        }

        $fallbackId = DefaultCategory::uncategorisedId();

        // Re-file this category's posts (and any child categories' parent link is
        // handled by the FK) before the row goes away.
        $category->posts()->update(['category_id' => $fallbackId]);

        return true;
    }
}
