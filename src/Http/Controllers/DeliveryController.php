<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MagnaCms\Blog\Http\Controllers\Concerns\CachesDeliveryResponse;
use MagnaCms\Blog\Http\Requests\PostIndexRequest;
use MagnaCms\Blog\Http\Resources\PostTransformer;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\ViewCounter;

/**
 * Read-only delivery API for headless frontends. Only public, published posts
 * are ever returned; drafts, scheduled, private and password-protected posts
 * are never exposed here.
 */
class DeliveryController
{
    use CachesDeliveryResponse;

    public function __construct(
        private readonly PostTransformer $transformer,
        private readonly ViewCounter $views,
    ) {}

    /**
     * GET /api/v1/blog/posts — paginated, filterable, searchable list of
     * published public posts.
     */
    public function index(PostIndexRequest $request): JsonResponse
    {
        $perPage = $request->integer('per_page') ?: max(1, min(100, BlogSettings::get()->posts_per_page));
        [$sortColumn, $sortDirection] = $request->sort();

        $query = Post::query()
            ->live()
            ->public()
            ->with(['author', 'category'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->string('search')->toString()))
            ->when($request->filled('category'), fn ($q) => $q->inCategory($request->string('category')->toString()))
            ->when($request->filled('tag'), fn ($q) => $q->withTag($request->string('tag')->toString()))
            ->when($request->filled('series'), fn ($q) => $q->inSeries($request->string('series')->toString()))
            ->when($request->filled('locale'), fn ($q) => $q->locale($request->string('locale')->toString()))
            ->when($request->filled('author'), fn ($q) => $q->byAuthor($request->string('author')->toString()))
            ->when($request->boolean('featured'), fn ($q) => $q->featured())
            ->when($request->filled('year'), fn ($q) => $q->publishedIn(
                $request->integer('year'),
                $request->filled('month') ? $request->integer('month') : null,
            ));

        $posts = $query
            ->orderBy($sortColumn, $sortDirection)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return $this->cached($request, response()->json([
            'data' => array_map(
                fn (Post $post): array => $this->transformer->summary($post),
                $posts->items(),
            ),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]));
    }

    /**
     * GET /api/v1/blog/posts/{slug} — a single published public post.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $post = Post::query()
            ->live()
            ->public()
            ->with(['author', 'category', 'tags', 'meta', 'reactions', 'series', 'coAuthors'])
            ->where('slug', $slug)
            ->firstOrFail();

        // Buffered in the cache (deduped per IP/day) and flushed on a schedule, so
        // a read never writes to the posts row.
        $this->views->record($post, $request->ip());

        return $this->cached($request, response()->json(['data' => $this->transformer->full($post)]));
    }
}
