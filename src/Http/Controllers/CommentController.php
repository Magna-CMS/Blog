<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\CommentChannel;
use MagnaCms\Blog\Support\CommentSubmissionHandler;

/**
 * Delivery API for blog comments. Reads only approved comments and accepts new
 * submissions, which are held for moderation (or auto-approved) per settings.
 * "Require login" is a frontend concern (the flag is surfaced in the post
 * payload); this API trusts the frontend for author identity and never accepts
 * a client-supplied author id (impersonation guard).
 *
 * Submissions go through CommentSubmissionHandler, the same path the post
 * page's own comment form takes.
 */
class CommentController
{
    public function __construct(private readonly CommentSubmissionHandler $submissions) {}

    /**
     * GET /api/v1/blog/posts/{slug}/comments — approved comments, threaded one level.
     */
    public function index(string $slug): JsonResponse
    {
        $post = $this->findPost($slug);

        $comments = $post->comments()
            ->approved()
            ->whereNull('parent_id')
            ->with(['replies' => fn ($query) => $query->approved()->oldest()])
            ->latest()
            ->get();

        return response()->json([
            'data' => $comments->map(fn (Comment $comment): array => $this->present($comment, true))->all(),
        ]);
    }

    /**
     * POST /api/v1/blog/posts/{slug}/comments — submit a comment for moderation.
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $comment = $this->submissions->submit($this->findPost($slug), $request, CommentChannel::Api);
        $approved = $comment->status === CommentStatus::Approved;

        return response()->json([
            'data' => [
                'id' => $comment->id,
                'status' => $comment->status->value,
                'awaiting_moderation' => ! $approved,
            ],
        ], 201);
    }

    private function findPost(string $slug): Post
    {
        return Post::query()->live()->public()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Comment $comment, bool $withReplies): array
    {
        $data = [
            'id' => $comment->id,
            'author' => data_get($comment, 'author.name') ?? $comment->author_name,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];

        if ($withReplies) {
            $data['replies'] = $comment->replies->map(fn (Comment $reply): array => $this->present($reply, false))->all();
        }

        return $data;
    }
}
