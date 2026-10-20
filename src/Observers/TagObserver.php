<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Observers;

use MagnaCms\Blog\Listeners\PurgePublicBlogCache;
use MagnaCms\Blog\Models\Tag;

/**
 * A renamed or deleted tag changes the tag list on every post page that
 * shows it. Which pages those are is a join away and changes nothing about
 * the answer's shape, so every post page and listing is purged — tag edits
 * are rare, post pages re-render cheaply.
 */
final class TagObserver
{
    public function __construct(private readonly PurgePublicBlogCache $purge) {}

    public function updated(Tag $tag): void
    {
        $this->purge->allPosts();
    }

    public function deleted(Tag $tag): void
    {
        $this->purge->allPosts();
    }
}
