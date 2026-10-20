<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Observers;

use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Listeners\PurgePublicBlogCache;
use MagnaCms\Blog\Models\Comment;

/**
 * Purges a post's cached page when a comment visitors can see appears,
 * changes or goes: approved on creation (auto-moderation), approved or
 * un-approved later, edited while approved, or deleted while approved.
 * A pending or spam comment is invisible, so writing one purges nothing —
 * a flood of spam cannot flush the cache.
 */
final class CommentObserver
{
    /** The columns the public thread shows. */
    private const VISIBLE = ['status', 'body', 'author_name', 'author_id', 'parent_id'];

    public function __construct(private readonly PurgePublicBlogCache $purge) {}

    public function created(Comment $comment): void
    {
        if ($comment->status === CommentStatus::Approved) {
            $this->purge->postPage($comment->post_id);
        }
    }

    public function updated(Comment $comment): void
    {
        $wasVisible = $comment->getOriginal('status') === CommentStatus::Approved;
        $isVisible = $comment->status === CommentStatus::Approved;

        if (($wasVisible || $isVisible) && $comment->wasChanged(self::VISIBLE)) {
            $this->purge->postPage($comment->post_id);
        }
    }

    public function deleted(Comment $comment): void
    {
        if ($comment->status === CommentStatus::Approved) {
            $this->purge->postPage($comment->post_id);
        }
    }
}
