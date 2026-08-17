<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\ReactionService;

/**
 * Delivery endpoint for anonymous post reactions. Toggling is idempotent per
 * visitor (a hashed IP + user agent), so a second call with the same type
 * removes the reaction. Reactions are only served for live, public posts.
 */
class ReactionController
{
    public function __construct(private readonly ReactionService $reactions) {}

    /**
     * POST /api/v1/blog/posts/{slug}/reactions — toggle a reaction of the given type.
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        abort_unless($this->reactions->isEnabled(), 404);

        $post = Post::query()->live()->public()->where('slug', $slug)->firstOrFail();

        $type = (string) $request->input('type', '');
        abort_unless($this->reactions->allows($type), 422);

        $fingerprint = $this->reactions->fingerprint($request->ip(), $request->userAgent());

        return response()->json(['data' => $this->reactions->toggle($post, $type, $fingerprint)]);
    }
}
