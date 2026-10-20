<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Observers;

use Illuminate\Contracts\Events\Dispatcher;
use Magna\Frontend\Events\PublicUrlsMoved;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Listeners\PurgePublicBlogCache;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostUrlHistory;

/**
 * What a post write means for the PUBLIC site: cached pages to purge, and
 * URLs that moved.
 *
 * Kept apart from PostObserver because that one deliberately ignores
 * autosaves (no revision, no webhook for a background save) — while an
 * autosave of a published post changes the live page as surely as a
 * manual save does, so this one must see every write.
 *
 * "Published" here is the status alone, whatever the date: a post
 * published for tomorrow is not visible yet, but listings cached now
 * computed their expiry without it, so they must be purged to learn the
 * new go-live time.
 */
final class PostPublicationObserver
{
    public function __construct(
        private readonly PurgePublicBlogCache $purge,
        private readonly PostUrlHistory $history,
        private readonly Dispatcher $events,
    ) {}

    public function created(Post $post): void
    {
        if ($post->status === PostStatus::Published) {
            $this->purge->post($post);
        }
    }

    /**
     * Runs before Eloquent syncs the originals, so getRawOriginal() is still
     * the row as it was before this save — what the URL history reads.
     */
    public function updated(Post $post): void
    {
        $moves = $this->history->movesForPost($post);
        if ($moves !== []) {
            $this->events->dispatch(new PublicUrlsMoved($moves, 'magna-cms/blog'));
        }

        if ($post->status === PostStatus::Published || $post->getOriginal('status') === PostStatus::Published) {
            $this->purge->post($post);
        }
    }

    public function deleted(Post $post): void
    {
        if ($post->status === PostStatus::Published) {
            $this->purge->post($post);
        }
    }

    public function restored(Post $post): void
    {
        if ($post->status === PostStatus::Published) {
            $this->purge->post($post);
        }
    }
}
