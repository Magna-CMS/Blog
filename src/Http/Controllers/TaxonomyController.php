<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MagnaCms\Blog\Http\Controllers\Concerns\CachesDeliveryResponse;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Tag;

/**
 * Read-only taxonomy endpoints for headless frontends: the category tree and the
 * tag list, each carrying a post count scoped to live + public posts only, so
 * drafts, scheduled and private posts never inflate the numbers.
 */
class TaxonomyController
{
    use CachesDeliveryResponse;

    /** Sentinel key for root (parent_id null) categories in the grouping. */
    private const ROOT = 0;

    /**
     * GET /api/v1/blog/categories — the category tree with per-category post
     * counts. Children are nested under their parent; counts are per-category
     * (not roll-ups).
     */
    public function categories(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->withCount(['posts' => fn ($query) => $query->live()->public()])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'parent_id']);

        // Group into an in-memory parent → children map so the recursive build
        // never issues a query per node.
        /** @var array<int, list<Category>> $byParent */
        $byParent = [];
        foreach ($categories as $category) {
            $byParent[$category->parent_id ?? self::ROOT][] = $category;
        }

        return $this->cached($request, response()->json([
            'data' => $this->tree($byParent, self::ROOT, []),
        ]), 300);
    }

    /**
     * GET /api/v1/blog/tags — every tag with a live public post, with its count.
     */
    public function tags(Request $request): JsonResponse
    {
        $tags = Tag::query()
            ->withCount(['posts' => fn ($query) => $query->live()->public()])
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return $this->cached($request, response()->json([
            'data' => $tags
                ->filter(fn (Tag $tag): bool => (int) $tag->getAttribute('posts_count') > 0)
                ->map(fn (Tag $tag): array => [
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'post_count' => (int) $tag->getAttribute('posts_count'),
                ])
                ->values()
                ->all(),
        ]), 300);
    }

    /**
     * Recursively build the nested category tree from a parent-keyed grouping.
     * $seen tracks the ids on the current path so a corrupt cyclic parent chain in
     * the data can never send this into infinite recursion (defence in depth —
     * writes are already cycle-guarded).
     *
     * @param  array<int, list<Category>>  $byParent
     * @param  array<int, true>  $seen
     * @return list<array<string, mixed>>
     */
    private function tree(array $byParent, int $parentId, array $seen): array
    {
        $nodes = [];

        foreach ($byParent[$parentId] ?? [] as $category) {
            $id = (int) $category->id;
            if (isset($seen[$id])) {
                continue;
            }

            $nodes[] = [
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'post_count' => (int) $category->getAttribute('posts_count'),
                'children' => $this->tree($byParent, $id, $seen + [$id => true]),
            ];
        }

        return $nodes;
    }
}
