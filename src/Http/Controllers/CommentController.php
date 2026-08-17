<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\CommentPolicy;
use MagnaCms\Blog\Support\Spam\SpamCheck;
use MagnaCms\Blog\Support\Spam\SpamSubmission;

/**
 * Delivery API for blog comments. Reads only approved comments and accepts new
 * submissions, which are held for moderation (or auto-approved) per settings.
 * "Require login" is a frontend concern (the flag is surfaced in the post
 * payload); this API trusts the frontend for author identity and never accepts
 * a client-supplied author id (impersonation guard).
 */
class CommentController
{
    public function __construct(
        private readonly CommentPolicy $policy,
        private readonly SpamCheck $spam,
    ) {}

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
        $post = $this->findPost($slug);

        if (! $this->policy->open($post)) {
            throw ValidationException::withMessages(['body' => 'Comments are closed for this post.']);
        }

        $honeypotField = (string) config('blog.comments.honeypot_field', 'website');

        /** @var array{body: string, author_name: string, author_email: string, parent_id?: int|null} $data */
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'author_name' => ['required', 'string', 'max:120'],
            'author_email' => ['required', 'email', 'max:190'],
            'parent_id' => ['nullable', 'integer'],
            'form_started_at' => ['nullable', 'integer'],
            $honeypotField => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->looksLikeSpam($request, $data, $honeypotField)) {
            // Reject without persisting, with a generic message that gives a bot
            // no signal about which check tripped.
            throw ValidationException::withMessages(['body' => 'Your comment could not be submitted.']);
        }

        $parentId = $this->resolveParent($post, $data['parent_id'] ?? null);

        $autoApprove = BlogSettings::get()->comment_moderation === 'auto';

        $comment = Comment::create([
            'post_id' => $post->id,
            'parent_id' => $parentId,
            'author_name' => trim(strip_tags($data['author_name'])),
            'author_email' => $data['author_email'],
            'body' => trim(strip_tags($data['body'])),
            'status' => $autoApprove ? CommentStatus::Approved->value : CommentStatus::Pending->value,
        ]);

        return response()->json([
            'data' => [
                'id' => $comment->id,
                'status' => $comment->status->value,
                'awaiting_moderation' => ! $autoApprove,
            ],
        ], 201);
    }

    /**
     * @param  array{body: string, author_name: string, author_email: string, parent_id?: int|null}  $data
     */
    private function looksLikeSpam(Request $request, array $data, string $honeypotField): bool
    {
        $startedAt = $request->integer('form_started_at');

        return $this->spam->isSpam(new SpamSubmission(
            body: $data['body'],
            authorName: $data['author_name'],
            authorEmail: $data['author_email'],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            honeypot: (string) $request->input($honeypotField, ''),
            elapsedSeconds: $startedAt > 0 ? max(0, now()->getTimestamp() - $startedAt) : null,
        ));
    }

    private function findPost(string $slug): Post
    {
        return Post::query()->live()->public()->where('slug', $slug)->firstOrFail();
    }

    /**
     * A reply parent must be an approved comment on the same post; anything else
     * is treated as a top-level comment.
     */
    private function resolveParent(Post $post, ?int $parentId): ?int
    {
        if ($parentId === null) {
            return null;
        }

        $exists = Comment::query()
            ->whereKey($parentId)
            ->where('post_id', $post->id)
            ->where('status', CommentStatus::Approved->value)
            ->exists();

        return $exists ? $parentId : null;
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
